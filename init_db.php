<?php
// init_db.php - ONE-TIME bootstrap for Postgres on Render
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$dsnUrl = getenv('DATABASE_URL');
if (!$dsnUrl) {
    http_response_code(500);
    echo "No DATABASE_URL env var.\n";
    exit;
}

// Parse DATABASE_URL = postgres://user:pass@host:port/db
$parts = parse_url($dsnUrl);
if ($parts === false) {
    http_response_code(500);
    echo "Invalid DATABASE_URL.\n";
    exit;
}

$host = $parts['host'] ?? 'localhost';
$port = $parts['port'] ?? 5432;
$user = $parts['user'] ?? '';
$pass = $parts['pass'] ?? '';
$db   = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $db);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB connect error: ".$e->getMessage()."\n";
    exit;
}

// Load SQL
$sqlFile = __DIR__.'/init_pg.sql';
if (!file_exists($sqlFile)) {
    http_response_code(500);
    echo "init_pg.sql not found.\n";
    exit;
}

$sql = file_get_contents($sqlFile);

try {
    $pdo->exec($sql);
    echo "OK: schema + seed installed.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Exec error: ".$e->getMessage()."\n";
}
