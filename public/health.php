<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/http.php';
require_once __DIR__ . '/../src/database.php';

requireMethod('GET');
try {
    $versions = databaseStatus(databaseConnection());
} catch (Throwable) {
    fail(503, 'database_unavailable');
}
respond(200, ['status' => 'ok', 'service' => 'mdrrmo-api', 'storage' => 'postgresql-postgis', ...$versions]);
