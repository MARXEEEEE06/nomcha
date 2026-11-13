<?php
require_once __DIR__ . '/make_mailer.php';

$databaseUrl = trim(nomcha_env('DATABASE_URL', ''));
$urlParts = $databaseUrl !== '' ? parse_url($databaseUrl) : false;
$urlScheme = is_array($urlParts) ? strtolower((string)($urlParts['scheme'] ?? '')) : '';
if ($databaseUrl !== '' && (!is_array($urlParts) || !in_array($urlScheme, ['mysql', 'mysqls'], true))) {
    http_response_code(500);
    error_log('Nomcha DB connection failed: DATABASE_URL must be a valid mysql:// URL.');
    exit('Database connection failed.');
}

// Explicit DB_* variables take precedence; otherwise use Aiven Runtime's
// integrated DATABASE_URL, then local development defaults.
$host = trim(nomcha_env('DB_HOST', (string)($urlParts['host'] ?? '127.0.0.1')));
$port = (int)nomcha_env('DB_PORT', (string)($urlParts['port'] ?? 3306));
$urlDatabase = isset($urlParts['path']) ? rawurldecode(trim($urlParts['path'], '/')) : '';
$db   = nomcha_env('DB_NAME', $urlDatabase !== '' ? $urlDatabase : 'nomcha_db');
$user = nomcha_env('DB_USER', isset($urlParts['user']) ? rawurldecode($urlParts['user']) : 'root');
$pass = nomcha_env('DB_PASSWORD', isset($urlParts['pass']) ? rawurldecode($urlParts['pass']) : '');
$sslCa = trim(nomcha_env('DB_SSL_CA', ''));

// PDO expects a certificate file path. Allow deployment platforms to provide
// the Aiven CA certificate itself as an environment variable instead.
if (str_contains($sslCa, '-----BEGIN CERTIFICATE-----')) {
    $sslCa = str_replace('\\n', "\n", $sslCa);
    $caPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'nomcha-aiven-ca-' . hash('sha256', $sslCa) . '.pem';
    if (!is_file($caPath)) {
        $tempPath = tempnam(sys_get_temp_dir(), 'nomcha-ca-');
        if ($tempPath === false || file_put_contents($tempPath, $sslCa, LOCK_EX) === false) {
            http_response_code(500);
            error_log('Nomcha DB connection failed: unable to prepare the configured CA certificate.');
            exit('Database connection failed.');
        }
        @chmod($tempPath, 0600);
        if (!@rename($tempPath, $caPath)) {
            @unlink($tempPath);
            if (!is_file($caPath)) {
                http_response_code(500);
                error_log('Nomcha DB connection failed: unable to install the configured CA certificate.');
                exit('Database connection failed.');
            }
        }
    }
    $sslCa = $caPath;
} elseif ($sslCa !== '' && !is_file($sslCa)) {
    // Resolve relative certificate paths from the project root, not from the
    // process working directory (which differs between Apache and CLI PHP).
    $projectRelativeCaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($sslCa, '/\\');
    if (is_file($projectRelativeCaPath)) {
        $sslCa = $projectRelativeCaPath;
    }
}

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
    error_log('Nomcha DB connection failed: ' . $e->getMessage());
    exit('Database connection failed.');
}
