<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/http.php';
require_once __DIR__ . '/../../../src/database.php';

requireMethod('GET');
requireReadToken();
$id = $_GET['id'] ?? '';
if (!validIncidentId($id)) {
    fail(400, 'invalid_incident_id');
}
try {
    $incident = findIncident(databaseConnection(), strtolower($id));
} catch (Throwable $error) {
    error_log('MDRRMO view failed (' . $error->getCode() . ')');
    fail(503, 'database_unavailable');
}
if ($incident === false) {
    fail(404, 'incident_not_found');
}
respond(200, ['incident' => $incident]);
