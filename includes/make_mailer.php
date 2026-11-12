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

    $subject = match ($purpose) {
        'registration' => 'Verify your Nomcha account',
        'account_deletion' => 'Confirm your Nomcha account deletion',
        'email_change' => 'Verify your new Nomcha email',
        default => 'Your Nomcha password reset code',
    };
    $action = match ($purpose) {
        'registration' => 'verify your email address',
        'account_deletion' => 'confirm your account deletion',
        'email_change' => 'verify your new email address',
        default => 'reset your password',
    };
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

function send_employee_welcome_email(string $to, string $employeeName, string $temporaryPassword): bool {
    $config = nomcha_config();
    $webhook = trim((string)($config['make_webhook_url'] ?? ''));
    if ($webhook === '' || !filter_var($webhook, FILTER_VALIDATE_URL)) throw new RuntimeException('Add the Make webhook URL in includes/local_config.php.');
    $fromEmail = (string)($config['mail_from_email'] ?? 'no-reply@example.com');
    $fromName = (string)($config['mail_from_name'] ?? 'Nomcha');
    $safeName = htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($temporaryPassword, ENT_QUOTES, 'UTF-8');
    $subject = 'Your Nomcha employee temporary password';
    $text = "Hello {$employeeName},\n\nA temporary password has been set for your Nomcha employee account.\nTemporary password: {$temporaryPassword}\n\nLog in using this password, then use Forgot password to choose a new one. Keep this email private.";
    $html = '<p>Hello ' . $safeName . ',</p><p>A temporary password has been set for your Nomcha employee account.</p><p>Temporary password: <strong>' . $safePassword . '</strong></p><p>Log in using this password, then use Forgot password to choose a new one. Keep this email private.</p>';
    $payload = json_encode(['to'=>$to,'from_email'=>$fromEmail,'from_name'=>$fromName,'subject'=>$subject,'text'=>$text,'html'=>$html,'purpose'=>'employee_welcome'], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Could not prepare the email payload.');
    $headers = "Content-Type: application/json\r\n";
    $token = trim((string)($config['make_webhook_token'] ?? ''));
    if ($token !== '') $headers .= 'x-make-apikey: ' . $token . "\r\n";
    $context = stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>$payload,'timeout'=>10,'ignore_errors'=>true]]);
    $response = @file_get_contents($webhook, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) throw new RuntimeException('The employee welcome email could not be delivered through Make.');
    return true;
}
