<?php

declare(strict_types=1);

const MAX_PHOTO_BYTES = 2 * 1024 * 1024;
const MAX_REQUEST_BYTES = 2_820_000;
const CATEGORIES = ['flood', 'fire', 'landslide', 'earthquake', 'medical', 'other'];

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

function fail(int $status, string $code): never
{
    respond($status, ['error' => $code]);
}

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

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header("Allow: {$method}");
        fail(405, 'method_not_allowed');
    }
}

function requireReadToken(): void
{
    $token = getenv('INCIDENT_READ_TOKEN');
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$token || !preg_match('/^Bearer (\S+)$/iD', $header, $match) || !hash_equals($token, $match[1])) {
        header('WWW-Authenticate: Bearer');
        fail(401, 'unauthorized');
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

function validIncidentId(mixed $value): bool
{
    return is_string($value) &&
        preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value) === 1;
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

function queryInteger(string $name, int $default, int $min, int $max): int
{
    $value = $_GET[$name] ?? null;
    if ($value === null) {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($parsed === false) {
        fail(400, 'invalid_pagination');
    }
    return $parsed;
}
