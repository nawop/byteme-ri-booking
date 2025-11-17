<?php
// config/config.php

function parse_database_url(?string $url): array {
    if (!$url) return [];
    $parts = parse_url($url);
    if ($parts === false) return [];

    $scheme = $parts['scheme'] ?? 'postgres';
    if ($scheme === 'postgresql') $scheme = 'postgres';

    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? 5432;
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';
    $db   = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

    return [
        'dsn'  => $dsn,
        'user' => $user,
        'pass' => $pass,
    ];
}

$pg = parse_database_url(getenv('DATABASE_URL') ?: null);

return [
    'trimesters' => [
        'T1' => ['start'=>[9,1],  'end'=>[12,20]],
        'T2' => ['start'=>[1,7],  'end'=>[3,31]],
        'T3' => ['start'=>[4,15], 'end'=>[7,15]],
    ],

    // used everywhere in admin/API
    'admin_secret' => getenv('ADMIN_SECRET') ?: 'changeme',

    'callmebot' => [
        'phone'  => getenv('CALLMEBOT_PHONE') ?: '',
        'apikey' => getenv('CALLMEBOT_KEY')   ?: '',
    ],

    // local sqlite fallback
    'db_path' => __DIR__ . '/../db.sqlite',

    // pg info (empty array if DATABASE_URL not set)
    'pg' => $pg,
];
