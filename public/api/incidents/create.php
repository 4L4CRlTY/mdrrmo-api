<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/http.php';
require_once __DIR__ . '/../../../src/database.php';

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
