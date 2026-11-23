<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/database.php';

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
