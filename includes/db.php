<?php
require_once __DIR__ . '/make_mailer.php';

$host = trim((string)(nomcha_env('DB_HOST', '127.0.0.1')));
$port = (int)nomcha_env('DB_PORT', '3306');
$db   = nomcha_env('DB_NAME', 'nomcha_db');
$user = nomcha_env('DB_USER', 'root');
$pass = nomcha_env('DB_PASSWORD', '');
$sslCa = trim(nomcha_env('DB_SSL_CA', ''));

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

if ($sslCa !== '') {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = 1;
    }
}

try {
    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        $options
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed.');
}
