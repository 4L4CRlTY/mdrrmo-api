<?php

declare(strict_types=1);

require_once __DIR__ . '/src/database.php';

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
