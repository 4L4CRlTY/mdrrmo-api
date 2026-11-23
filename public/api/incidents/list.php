<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/http.php';
require_once __DIR__ . '/../../../src/database.php';

requireMethod('GET');
requireReadToken();
$limit = queryInteger('limit', 25, 1, 100);
$offset = queryInteger('offset', 0, 0, 10000);

try {
    $rows = listIncidents(databaseConnection(), $limit, $offset);
} catch (Throwable $error) {
    error_log('MDRRMO list failed (' . $error->getCode() . ')');
    fail(503, 'database_unavailable');
}
respond(200, ['incidents' => $rows, 'limit' => $limit, 'offset' => $offset]);
