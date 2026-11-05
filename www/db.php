<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = getenv('DB_HOST');
$db = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASSWORD');

if (
    $host === false || $host === '' ||
    $db === false || $db === '' ||
    $user === false || $user === '' ||
    $pass === false || $pass === ''
) {
    error_log('Database configuration is missing.');
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}

$dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (\PDOException $e) {
    error_log('Database connection failed. SQLSTATE: ' . $e->getCode());
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}