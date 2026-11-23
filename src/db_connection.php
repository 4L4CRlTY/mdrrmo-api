<?php

declare(strict_types=1);

function databaseConnection(): PDO
{
    $url = getenv('DATABASE_URL');
    $parts = is_string($url) && $url !== '' ? parse_url($url) : false;
    if (!$parts || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)
        || !isset($parts['host'], $parts['path'], $parts['user'], $parts['pass'])) {
        throw new RuntimeException('DATABASE_URL must be a PostgreSQL connection URL.');
    }

    $host = $parts['host'];
    $database = rawurldecode(ltrim($parts['path'], '/'));
    $port = $parts['port'] ?? 5432;
    parse_str($parts['query'] ?? '', $options);
    $sslMode = $options['sslmode'] ?? 'prefer';
    if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)
        || !preg_match('/^[A-Za-z0-9_-]+$/D', $database)
        || !in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
        throw new RuntimeException('Invalid PostgreSQL connection settings.');
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode};connect_timeout=5";
    return new PDO($dsn, rawurldecode($parts['user']), rawurldecode($parts['pass']), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
