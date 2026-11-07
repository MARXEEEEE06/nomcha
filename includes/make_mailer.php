<?php
function nomcha_config(): array {
    static $config;
    if ($config !== null) return $config;

    $configFile = __DIR__ . '/local_config.php';
    $config = is_file($configFile) ? require $configFile : [];
    if (!is_array($config)) $config = [];
    return $config;
}

function send_otp_email(string $to, string $purpose, string $otp): bool {
    $config = nomcha_config();
    $webhook = trim((string)($config['make_webhook_url'] ?? ''));
    if ($webhook === '' || !filter_var($webhook, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Add the Make webhook URL in includes/local_config.php.');
    }

    $subject = $purpose === 'registration' ? 'Verify your Nomcha account' : 'Your Nomcha password reset code';
    $action = $purpose === 'registration' ? 'verify your email address' : 'reset your password';
    $fromEmail = (string)($config['mail_from_email'] ?? 'no-reply@example.com');
    $fromName = (string)($config['mail_from_name'] ?? 'Nomcha');
    $text = "Your Nomcha verification code is {$otp}.\n\nUse this code to {$action}. It expires in 5 minutes. If you did not request it, you can ignore this email.";
    $html = '<p>Your Nomcha verification code is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">'
        . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8')
        . '</p><p>Use this code to ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')
        . '. It expires in 5 minutes. If you did not request it, you can ignore this email.</p>';

    $payload = json_encode([
        'to' => $to,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'subject' => $subject,
        'text' => $text,
        'html' => $html,
        'purpose' => $purpose,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Could not prepare the email payload.');

    $headers = "Content-Type: application/json\r\n";
    $token = trim((string)($config['make_webhook_token'] ?? ''));
    if ($token !== '') $headers .= 'x-make-apikey: ' . $token . "\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headers,
        'content' => $payload,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($webhook, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false) {
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new RuntimeException('Make returned HTTP ' . $matches[1] . '. Check the webhook API key and URL.');
        }
        throw new RuntimeException('No HTTP response from Make. Check outbound HTTPS access and the webhook URL.');
    }
    if (!preg_match('/\s2\d\d\s/', $statusLine)) {
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        throw new RuntimeException('Make returned HTTP ' . ($matches[1] ?? 'error') . '. Check the webhook API key and URL.');
    }
    return true;
}
