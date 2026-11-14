<?php
function nomcha_env(string $name, string $default = ''): string {
    static $fileValues;
    if ($fileValues === null) {
        $fileValues = [];
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile) && is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                if ($key !== '') $fileValues[$key] = $value;
            }
        }
    }
    $value = getenv($name);
    if ($value !== false) return (string)$value;
    return (string)($fileValues[$name] ?? $default);
}

function nomcha_config(): array {
    static $config;
    if ($config !== null) return $config;   

    $configFile = __DIR__ . '/local_config.php';
    $config = is_file($configFile) ? require $configFile : [];
    if (!is_array($config)) $config = [];
    $envMap = [
        'ALLOW_TEST_ADMIN_LOGIN' => 'allow_test_admin_login',
        'MAKE_WEBHOOK_URL' => 'make_webhook_url', 'MAKE_WEBHOOK_TOKEN' => 'make_webhook_token',
        'MAIL_FROM_EMAIL' => 'mail_from_email', 'MAIL_FROM_NAME' => 'mail_from_name',
        'SITE_BASE_URL' => 'site_base_url', 'OTP_PEPPER' => 'otp_pepper',
    ];
    foreach ($envMap as $envName => $configName) {
        $value = nomcha_env($envName);
        if ($value !== '') {
            $config[$configName] = $envName === 'ALLOW_TEST_ADMIN_LOGIN'
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : $value;
        }
    }
    return $config;
}

function send_otp_email(string $to, string $purpose, string $otp): bool {
    $config = nomcha_config();
    $webhook = trim((string)($config['make_webhook_url'] ?? ''));
    if ($webhook === '' || !filter_var($webhook, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Add the Make webhook URL in includes/local_config.php.');
    }

    $subject = match ($purpose) {
        'login' => 'Your Nomcha login code',
        'registration' => 'Verify your Nomcha account',
        'account_deletion' => 'Confirm your Nomcha account deletion',
        'email_change' => 'Verify your new Nomcha email',
        default => 'Your Nomcha password reset code',
    };
    $action = match ($purpose) {
        'login' => 'sign in to your Nomcha account',
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

    $payloadData = [
        'to' => $to,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'subject' => $subject,
        'text' => $text,
        'html' => $html,
        'purpose' => 'otp',
        'route' => 'auth',
    ];
    $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
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

function send_sample_email(string $to): bool {
    $config = nomcha_config();
    $webhook = trim((string)($config['make_webhook_url'] ?? ''));
    if ($webhook === '' || !filter_var($webhook, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Add the Make webhook URL in includes/local_config.php.');
    }
    $fromEmail = (string)($config['mail_from_email'] ?? 'no-reply@example.com');
    $fromName = (string)($config['mail_from_name'] ?? 'Nomcha');
    $text = "This is a sample email from Nomcha. Your Make email scenario is connected.";
    $html = '<p>This is a sample email from Nomcha.</p><p>Your Make email scenario is connected.</p>';
    $payload = json_encode([
        'to' => $to,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'subject' => 'Nomcha sample email',
        'text' => $text,
        'html' => $html,
        'purpose' => 'otp',
        'route' => 'auth',
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Could not prepare the sample email payload.');
    $headers = "Content-Type: application/json\r\n";
    $token = trim((string)($config['make_webhook_token'] ?? ''));
    if ($token !== '') $headers .= 'x-make-apikey: ' . $token . "\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers, 'content' => $payload,
        'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($webhook, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        throw new RuntimeException($response === false && !$statusLine
            ? 'No HTTP response from Make. Check outbound HTTPS access and the webhook URL.'
            : 'Make returned HTTP ' . ($matches[1] ?? 'error') . '. Check the webhook setup.');
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
    $subject = 'Your Nomcha employee account';
    $text = "Hello {$employeeName},\n\nYour Nomcha employee account is ready.\nTemporary password: {$temporaryPassword}\n\nLog in using this password, then use Forgot password to choose a new one. Keep this email private.";
    $html = '<p>Hello ' . $safeName . ',</p><p>Your Nomcha employee account is ready.</p><p>Temporary password: <strong>' . $safePassword . '</strong></p><p>Log in using this password, then use Forgot password to choose a new one. Keep this email private.</p>';
    $payload = json_encode(['to'=>$to,'from_email'=>$fromEmail,'from_name'=>$fromName,'subject'=>$subject,'text'=>$text,'html'=>$html,'purpose'=>'notify','route'=>'notification'], JSON_UNESCAPED_SLASHES);
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

function send_customer_welcome_email(string $to, string $customerName): bool {
    $config = nomcha_config();
    $webhook = trim((string)($config['make_webhook_url'] ?? ''));
    if ($webhook === '' || !filter_var($webhook, FILTER_VALIDATE_URL)) throw new RuntimeException('Make webhook is not configured.');
    $fromEmail = (string)($config['mail_from_email'] ?? 'no-reply@example.com');
    $fromName = (string)($config['mail_from_name'] ?? 'Nomcha');
    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $subject = 'Welcome to Nomcha';
    $text = "Hello {$customerName},\n\nWelcome to Nomcha! Your account is ready. Explore the menu, claim platform vouchers, and order your favorites online.\n\nWe’re glad you’re here.\nNomcha";
    $html = '<p>Hello ' . $safeName . ',</p><p>Welcome to Nomcha! Your account is ready.</p><p>Explore the menu, claim platform vouchers, and order your favorites online.</p><p>We’re glad you’re here.<br>Nomcha</p>';
    $payload = json_encode(['to'=>$to,'from_email'=>$fromEmail,'from_name'=>$fromName,'subject'=>$subject,'text'=>$text,'html'=>$html,'purpose'=>'notify','route'=>'notification'], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Could not prepare the welcome email.');
    $headers = "Content-Type: application/json\r\n";
    $token = trim((string)($config['make_webhook_token'] ?? ''));
    if ($token !== '') $headers .= 'x-make-apikey: ' . $token . "\r\n";
    $context = stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>$payload,'timeout'=>10,'ignore_errors'=>true]]);
    $response = @file_get_contents($webhook, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) throw new RuntimeException('Welcome email could not be delivered through Make.');
    return true;
}

function nomcha_absolute_url(string $path): string {
    $configured = rtrim(trim((string)(nomcha_config()['site_base_url'] ?? '')), '/');
    if ($configured !== '') return $configured . '/' . ltrim($path, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . ($basePath ? $basePath . '/' : '/') . ltrim($path, '/');
}

function send_subscription_welcome_email(string $to, string $unsubscribeUrl): bool {
    $config = nomcha_config();
    $webhook = trim((string)($config['make_webhook_url'] ?? ''));
    if ($webhook === '' || !filter_var($webhook, FILTER_VALIDATE_URL)) throw new RuntimeException('Make webhook is not configured.');
    $fromEmail = (string)($config['mail_from_email'] ?? 'no-reply@example.com');
    $fromName = (string)($config['mail_from_name'] ?? 'Nomcha');
    $subject = 'You are subscribed to Nomcha';
    $text = "Thanks for subscribing to Nomcha email updates about promotions, vouchers, and menu news.\n\nIf this was not you, unsubscribe here: {$unsubscribeUrl}\n\nNomcha";
    $html = '<p>Thanks for subscribing to Nomcha email updates about promotions, vouchers, and menu news.</p><p>If this was not you, <a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8') . '">unsubscribe here</a>.</p><p>Nomcha</p>';
    $payload = json_encode(['to'=>$to,'from_email'=>$fromEmail,'from_name'=>$fromName,'subject'=>$subject,'text'=>$text,'html'=>$html,'purpose'=>'notify','route'=>'notification'], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Could not prepare the subscription email.');
    $headers = "Content-Type: application/json\r\n";
    $token = trim((string)($config['make_webhook_token'] ?? ''));
    if ($token !== '') $headers .= 'x-make-apikey: ' . $token . "\r\n";
    $context = stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>$payload,'timeout'=>10,'ignore_errors'=>true]]);
    $response = @file_get_contents($webhook, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) throw new RuntimeException('Subscription email could not be delivered through Make.');
    return true;
}

function send_notification_email(string $to,string $subject,string $text,string $purpose,?string $formattedHtml=null): bool {
    global $pdo;
    $config=nomcha_config();$webhook=trim((string)($config['make_webhook_url']??''));
    if($webhook===''||!filter_var($webhook,FILTER_VALIDATE_URL))throw new RuntimeException('Make webhook is not configured.');
    $fromEmail=(string)($config['mail_from_email']??'no-reply@example.com');$fromName=(string)($config['mail_from_name']??'Nomcha');
    $unsubscribe=$pdo->prepare('SELECT unsubscribe_token_hash FROM email_subscriptions WHERE email_addr=? AND unsubscribed_at IS NULL');
    $unsubscribe->execute([strtolower($to)]);
    $hash=(string)$unsubscribe->fetchColumn();
    if ($hash === '') return false;
    $unsubscribeUrl=nomcha_absolute_url('unsubscribe.php?token=' . rawurlencode($hash));
    $text .= "\n\nTo stop receiving Nomcha email updates, unsubscribe here: {$unsubscribeUrl}";
    $html=($formattedHtml!==null?$formattedHtml:nl2br(htmlspecialchars($text,ENT_QUOTES,'UTF-8')));
    $html .= '<p><a href="' . htmlspecialchars($unsubscribeUrl,ENT_QUOTES,'UTF-8') . '">Unsubscribe from Nomcha email updates</a></p>';
    $payload=json_encode(['to'=>$to,'from_email'=>$fromEmail,'from_name'=>$fromName,'subject'=>$subject,'text'=>$text,'html'=>$html,'purpose'=>'notify','route'=>'notification'],JSON_UNESCAPED_SLASHES);
    if($payload===false)throw new RuntimeException('Could not prepare the notification email.');
    $headers="Content-Type: application/json\r\n";$token=trim((string)($config['make_webhook_token']??''));if($token!=='')$headers.='x-make-apikey: '.$token."\r\n";
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>$payload,'timeout'=>10,'ignore_errors'=>true]]);$response=@file_get_contents($webhook,false,$ctx);$status=$http_response_header[0]??'';
    if($response===false||!preg_match('/\s2\d\d\s/',$status))throw new RuntimeException('Notification could not be delivered through Make.');return true;
}

function notify_nomcha_users(string $purpose,string $subject,string $message,string $path): void {
    global $pdo;
    $users=$pdo->query("SELECT s.email_addr, i.first_name FROM email_subscriptions s LEFT JOIN user_credentials c ON LOWER(c.email_addr)=s.email_addr AND c.is_active=1 AND c.role='customer' LEFT JOIN user_info i ON i.user_id=c.user_id WHERE s.unsubscribed_at IS NULL")->fetchAll();
    foreach($users as $user){try{$name=trim((string)($user['first_name']??''));send_notification_email((string)$user['email_addr'],$subject,'Hello'.($name!==''?' '.$name:'').",\n\n".$message."\n\nView details: ".nomcha_absolute_url($path),$purpose);}catch(Throwable $e){error_log('Nomcha notification email failed: '.$e->getMessage());}}
}

function notify_menu_item_reactivated(string $itemName, string $description = ''): void {
    $details = trim($description) !== '' ? "\n\n" . trim($description) : '';
    notify_nomcha_users(
        'menu_event_notification',
        "Everyone's favorite is back!",
        $itemName . ' is back on the Nomcha menu.' . $details,
        'order.php'
    );
}
