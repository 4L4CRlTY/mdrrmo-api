<?php

declare(strict_types=1);

require_once __DIR__ . '/db_connection.php';

const INCIDENT_FIELDS = "id, category, location_name AS location, details, persons_affected,
    ST_Y(position) AS latitude, ST_X(position) AS longitude, ST_SRID(position) AS srid,
    gps_accuracy_meters, photo_mime, created_at";

function initializeDatabase(PDO $db): void
{
    $db->beginTransaction();
    try {
        $db->query("SELECT pg_advisory_xact_lock(681922317)");
        $db->exec('CREATE EXTENSION IF NOT EXISTS postgis');
        $db->exec("CREATE TABLE IF NOT EXISTS incidents (
            id UUID PRIMARY KEY,
            category TEXT NOT NULL CHECK (category IN ('flood', 'fire', 'landslide', 'earthquake', 'medical', 'other')),
            location_name TEXT NOT NULL,
            details TEXT NOT NULL,
            persons_affected INTEGER NOT NULL CHECK (persons_affected >= 0),
            position GEOMETRY(Point, 4326) NOT NULL CHECK (
                ST_X(position) BETWEEN -180 AND 180 AND ST_Y(position) BETWEEN -90 AND 90
            ),
            gps_accuracy_meters DOUBLE PRECISION CHECK (gps_accuracy_meters >= 0 AND gps_accuracy_meters < 'Infinity'),
            photo_mime TEXT,
            photo BYTEA,
            payload_hash TEXT NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            CHECK ((photo_mime IS NULL) = (photo IS NULL)),
            CHECK (octet_length(photo) <= 2097152)
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS incidents_position_gix ON incidents USING GIST (position)');
        $db->exec('CREATE INDEX IF NOT EXISTS incidents_created_idx ON incidents (created_at DESC, id DESC)');
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function databaseStatus(PDO $db): array
{
    $version = $db->query("SELECT current_setting('server_version') AS postgres, postgis_lib_version() AS postgis,
        to_regclass('public.incidents') IS NOT NULL AS incidents_ready")->fetch();
    if (!($version['incidents_ready'] ?? false)) {
        throw new RuntimeException('Incident schema is missing.');
    }
    return ['postgresql' => $version['postgres'], 'postgis' => $version['postgis']];
}

function saveIncident(PDO $db, array $report): array
{
    $payloadHash = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));
    $insert = $db->prepare("INSERT INTO incidents (
        id, category, location_name, details, persons_affected, position, gps_accuracy_meters, photo_mime, photo, payload_hash
    ) VALUES (
        :id, :category, :location, :details, :persons,
        ST_SetSRID(ST_MakePoint(CAST(:longitude AS double precision), CAST(:latitude AS double precision)), 4326),
        :accuracy, :mime, decode(:photo, 'base64'), :hash
    ) ON CONFLICT (id) DO NOTHING
    RETURNING id, created_at, ST_SRID(position) AS srid");
    $insert->execute([
        'id' => $report['id'], 'category' => $report['category'], 'location' => $report['location'],
        'details' => $report['details'], 'persons' => $report['personsAffected'], 'longitude' => $report['longitude'],
        'latitude' => $report['latitude'], 'accuracy' => $report['gpsAccuracyMeters'],
        'mime' => $report['photoMime'], 'photo' => $report['photoBase64'], 'hash' => $payloadHash,
    ]);

    $row = $insert->fetch();
    if ($row !== false) {
        return ['row' => $row, 'created' => true];
    }

    $existing = $db->prepare('SELECT id, created_at, ST_SRID(position) AS srid, payload_hash FROM incidents WHERE id = :id');
    $existing->execute(['id' => $report['id']]);
    $row = $existing->fetch();
    if ($row === false || !hash_equals($row['payload_hash'], $payloadHash)) {
        return ['row' => null, 'created' => false];
    }
    return ['row' => $row, 'created' => false];
}

function listIncidents(PDO $db, int $limit, int $offset): array
{
    $sql = "SELECT " . INCIDENT_FIELDS . " FROM incidents ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset";
    $query = $db->prepare($sql);
    $query->bindValue(':limit', $limit, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll();
}

function findIncident(PDO $db, string $id): array|false
{
    $query = $db->prepare("SELECT " . INCIDENT_FIELDS . ", ST_AsGeoJSON(position)::json AS geometry,
        replace(encode(photo, 'base64'), chr(10), '') AS photo_base64
        FROM incidents WHERE id = :id");
    $query->execute(['id' => $id]);
    $report = $query->fetch();
    if ($report !== false) {
        $report['geometry'] = json_decode($report['geometry'], true, flags: JSON_THROW_ON_ERROR);
    }
    return $report;
}

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

function validIncidentId(mixed $value): bool
{
    return is_string($value) &&
        preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value) === 1;
}
