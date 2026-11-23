<?php

declare(strict_types=1);

require_once __DIR__ . '/src/database.php';

if (PHP_SAPI !== 'cli') {
    requireMethod('GET');
    try {
        $versions = databaseStatus(databaseConnection());
    } catch (Throwable) {
        fail(503, 'database_unavailable');
    }
    respond(200, ['status' => 'ok', 'service' => 'mdrrmo-api', 'storage' => 'postgresql-postgis', ...$versions]);
}

try {
    $db = databaseConnection();
    if (in_array('--initialize', $argv, true)) {
        initializeDatabase($db);
    }
    echo json_encode(['status' => 'ok', ...databaseStatus($db)], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'PostgreSQL/PostGIS check failed (' . $error->getCode() . ').' . PHP_EOL);
    exit(1);
}
