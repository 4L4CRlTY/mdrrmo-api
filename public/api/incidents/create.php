<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/database.php';

const MAX_PHOTO_BYTES = 2 * 1024 * 1024;
const MAX_REQUEST_BYTES = 2_820_000;
const CATEGORIES = ['flood', 'fire', 'landslide', 'earthquake', 'medical', 'other'];

function applyCreateCors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = getenv('HATCHABLE_ORIGIN');
    header('Vary: Origin');
    if (is_string($allowed) && $allowed !== '' && $origin === $allowed) {
        header("Access-Control-Allow-Origin: {$allowed}");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        return;
    }
    if ($origin !== '') {
        fail(403, 'origin_not_allowed');
    }
}

function readJsonRequest(): object
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        fail(415, 'expected_json');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_REQUEST_BYTES) {
        fail(413, 'request_too_large');
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_REQUEST_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_REQUEST_BYTES) {
        fail(413, 'request_too_large');
    }
    try {
        $value = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail(400, 'invalid_json');
    }
    if (!is_object($value)) {
        fail(400, 'invalid_report');
    }
    return $value;
}

function validText(mixed $value, int $min, int $max): bool
{
    if (!is_string($value) || str_contains($value, "\0")) {
        return false;
    }
    $length = preg_match_all('/./us', trim($value));
    return $length !== false && $length >= $min && $length <= $max;
}

function finiteNumber(mixed $value): bool
{
    return (is_int($value) || is_float($value)) && is_finite((float) $value);
}

function incidentPhoto(mixed $photo): array
{
    if ($photo === null) {
        return [null, null];
    }
    if (!is_string($photo)) {
        fail(400, 'invalid_photo');
    }
    $parts = explode(',', $photo, 2);
    $formats = [
        'data:image/jpeg;base64' => 'image/jpeg',
        'data:image/png;base64' => 'image/png',
        'data:image/webp;base64' => 'image/webp',
    ];
    if (count($parts) !== 2 || !isset($formats[$parts[0]]) || strlen($parts[1]) % 4 !== 0) {
        fail(400, 'invalid_photo');
    }
    $bytes = base64_decode($parts[1], true);
    if ($bytes === false || strlen($bytes) > MAX_PHOTO_BYTES || base64_encode($bytes) !== $parts[1]) {
        fail(400, 'invalid_photo');
    }
    $image = @getimagesizefromstring($bytes);
    if (($image['mime'] ?? null) !== $formats[$parts[0]]) {
        fail(400, 'invalid_photo');
    }
    return [$formats[$parts[0]], $parts[1]];
}

function incidentFromRequest(object $input): array
{
    $id = is_string($input->id ?? null) ? strtolower(trim($input->id)) : '';
    $category = is_string($input->category ?? null) ? trim($input->category) : '';
    if (!validIncidentId($id) || !in_array($category, CATEGORIES, true)
        || !validText($input->location ?? null, 3, 140) || !validText($input->details ?? null, 10, 1500)
        || ($input->practice ?? false) !== true) {
        fail(400, 'invalid_report');
    }

    $persons = $input->personsAffected ?? null;
    if (!is_int($persons) || $persons < 0 || $persons > 2147483647) {
        fail(400, 'invalid_people');
    }
    $latitude = $input->latitude ?? null;
    $longitude = $input->longitude ?? null;
    $accuracy = $input->gpsAccuracyMeters ?? null;
    if (!finiteNumber($latitude) || abs($latitude) > 90 || !finiteNumber($longitude) || abs($longitude) > 180
        || ($accuracy !== null && (!finiteNumber($accuracy) || $accuracy < 0))) {
        fail(400, 'invalid_location');
    }
    [$mime, $base64] = incidentPhoto($input->photo ?? null);
    return [
        'id' => $id, 'category' => $category, 'location' => trim($input->location), 'details' => trim($input->details),
        'personsAffected' => $persons, 'latitude' => (float) $latitude, 'longitude' => (float) $longitude,
        'gpsAccuracyMeters' => $accuracy === null ? null : (float) $accuracy,
        'photoMime' => $mime, 'photoBase64' => $base64,
    ];
}

applyCreateCors();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
requireMethod('POST');

$report = incidentFromRequest(readJsonRequest());
try {
    $saved = saveIncident(databaseConnection(), $report);
} catch (Throwable $error) {
    error_log('MDRRMO create failed (' . $error->getCode() . ')');
    fail(503, 'database_unavailable');
}

if ($saved['row'] === null) {
    fail(409, 'incident_id_conflict');
}
$row = $saved['row'];
respond($saved['created'] ? 201 : 200, [
    'accepted' => true,
    'id' => $row['id'],
    'reference' => 'MDR-' . strtoupper($row['id']),
    'createdAt' => $row['created_at'],
    'srid' => (int) $row['srid'],
]);
