<?php
if (session_status() === PHP_SESSION_NONE) session_start();

const NAME_RE   = '/^[\p{L}][\p{L} .\'-]{0,99}$/u';
const MOBILE_RE = '/^(09\d{9}|\+639\d{9})$/';
const PASS_RE   = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/';
const LOGIN_MAX_ATTEMPTS = 6;
const LOGIN_LOCK_MINUTES = 5;

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check($t): bool {
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

/** Resolve permissions from the database on every protected request. */
function current_role(PDO $pdo): ?string {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) return null;
    $stmt = $pdo->prepare('SELECT role, is_active FROM user_credentials WHERE user_id = ?');
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    $role = $account['role'] ?? null;
    if (!is_string($role) || !preg_match('/^(customer|[a-z][a-z0-9_]{1,31})$/', $role)) {
        $_SESSION = [];
        return null;
    }
    if (!(bool)$account['is_active']) {
        $_SESSION = [];
        header('Location: login.php?disabled=1');
        exit;
    }
    $_SESSION['role'] = $role;
    return $role;
}

function require_role(PDO $pdo, array $roles): void {
    $role = current_role($pdo);
    if ($role === null) {
        $_SESSION['flash_toasts'][] = ['kind' => 'warning', 'text' => 'Please log in first to access that page.'];
        header('Location: login.php');
        exit;
    }
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        $pageTitle = 'Access denied';
        require __DIR__ . '/header.php';
        echo '<main class="container py-5" style="min-height:45vh"><section class="card access-denied-card mx-auto p-4 p-md-5" style="max-width:680px"><span class="access-denied-icon"><i class="bi bi-shield-lock"></i></span><h1 class="h2 mt-3">Access denied</h1><p class="mb-0">Your account does not have permission to view this page.</p></section></main>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

function require_customer(PDO $pdo): void { require_role($pdo, ['customer']); }
function require_staff_or_admin(PDO $pdo): void { require_role($pdo, ['staff', 'admin']); }
function require_admin(PDO $pdo): void { require_role($pdo, ['admin']); }

function account_password_matches(string $email, string $passwordHash, string $password): bool {
    if ($password === '') return false;
    if (password_verify($password, $passwordHash)) return true;
    require_once __DIR__ . '/make_mailer.php';
    $config = nomcha_config();
    return ($config['allow_test_admin_login'] ?? false) === true
        && in_array(strtolower($email), ['admin@admin', 'nomchaadmin@admin'], true)
        && hash_equals('@NomchaAdmin2026', $password);
}

function verify_account_password(PDO $pdo, int $userId, string $password): bool {
    $stmt = $pdo->prepare('SELECT email_addr, password FROM user_credentials WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    return is_array($account) && account_password_matches((string)$account['email_addr'], (string)$account['password'], $password);
}

function normalize_mobile(string $m): string {
    return str_starts_with($m, '+63') ? '0' . substr($m, 3) : $m;
}

function valid_login_email(string $email): bool {
    return in_array($email, ['admin@admin', 'nomchaadmin@admin'], true) || (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function login_is_locked(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare('SELECT locked_until IS NOT NULL AND locked_until > NOW() FROM login_attempts WHERE email_addr = ?');
    $stmt->execute([$email]);
    return (bool)$stmt->fetchColumn();
}

function record_login_failure(PDO $pdo, string $email): bool {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO login_attempts (email_addr, attempt_count) VALUES (?, 0)')->execute([$email]);
        $stmt = $pdo->prepare(
            'SELECT attempt_count, locked_until, locked_until IS NOT NULL AND locked_until > NOW() AS is_locked
             FROM login_attempts WHERE email_addr = ? FOR UPDATE'
        );
        $stmt->execute([$email]);
        $attempt = $stmt->fetch();

        if (!$attempt) {
            throw new RuntimeException('Login attempt row could not be locked.');
        }
        if ((bool)$attempt['is_locked']) {
            $pdo->commit();
            return true;
        }

        $count = $attempt['locked_until'] !== null ? 1 : (int)$attempt['attempt_count'] + 1;
        $locked = $count >= LOGIN_MAX_ATTEMPTS;
        $update = $pdo->prepare(
            'UPDATE login_attempts
               SET attempt_count = ?, locked_until = CASE WHEN ? = 1 THEN DATE_ADD(NOW(), INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE) ELSE NULL END
             WHERE email_addr = ?'
        );
        $update->execute([$count, $locked ? 1 : 0, $email]);
        $pdo->commit();
        return $locked;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function clear_login_failures(PDO $pdo, string $email): void {
    $pdo->prepare('DELETE FROM login_attempts WHERE email_addr = ?')->execute([$email]);
}

function validate_registration(array $d, string $password, string $confirm): array {
    $err = [];
    if (!preg_match(NAME_RE, $d['first_name'])) $err['first_name'] = 'Enter a valid first name.';
    if ($d['middle_name'] !== '' && !preg_match(NAME_RE, $d['middle_name'])) $err['middle_name'] = 'Enter a valid middle name or leave it blank.';
    if (!preg_match(NAME_RE, $d['last_name'])) $err['last_name'] = 'Enter a valid last name.';

    foreach (['recipient_name', 'street_address'] as $field) {
        $max = $field === 'street_address' ? 255 : 120;
        if (mb_strlen($d[$field] ?? '') < 2 || mb_strlen($d[$field] ?? '') > $max) $err[$field] = 'Please enter a valid value.';
    }
    if (!preg_match('/^\d{4}$/', $d['zip_code'] ?? '')) $err['zip_code'] = 'Enter a 4-digit ZIP code.';
    foreach (['region_code' => 'a region', 'municipality_code' => 'a city / municipality', 'barangay_code' => 'a barangay'] as $field => $what) {
        if (!preg_match('/^\d{10}$/', $d[$field] ?? '')) $err[$field] = "Select {$what}.";
    }
    $lat = filter_var($d['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($d['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lat < -90 || $lat > 90) $err['latitude'] = 'Drop a pin on the map.';
    if ($lng === false || $lng < -180 || $lng > 180) $err['longitude'] = 'Drop a pin on the map.';

    if (!filter_var($d['email_addr'], FILTER_VALIDATE_EMAIL) || strlen($d['email_addr']) > 255) {
        $err['email_addr'] = 'Enter a valid email address.';
    }
    if (!preg_match(MOBILE_RE, $d['mobileno'])) {
        $err['mobileno'] = 'Use a PH mobile number: 09XXXXXXXXX or +639XXXXXXXXX.';
    }
    if (!preg_match(PASS_RE, $password) || strlen($password) > 72) {
        $err['password'] = 'Use 8 to 72 characters with a lowercase letter, an uppercase letter, a number, and a special character.';
    } elseif ($password !== $confirm) {
        $err['confirm_password'] = 'Passwords do not match.';
    }
    return $err;
}

// $attrs must be trusted literals only (never user input)
function form_field(string $name, string $label, string $type, string $value, ?string $error, string $attrs = ''): void {
    $cls = 'form-control' . ($error ? ' is-invalid' : '');
    echo '<div class="mb-3">';
    echo '<label for="' . e($name) . '" class="form-label">' . e($label) . '</label>';
    echo '<input type="' . e($type) . '" id="' . e($name) . '" name="' . e($name) . '" class="' . $cls . '" value="' . e($value) . '" ' . $attrs . '>';
    if ($error) echo '<div class="invalid-feedback">' . e($error) . '</div>';
    echo '</div>';
}

function password_field(string $name, string $label, string $value, ?string $error, string $attrs = ''): void {
    $cls = 'form-control' . ($error ? ' is-invalid' : '');
    echo '<div class="mb-3">';
    echo '<label for="' . e($name) . '" class="form-label">' . e($label) . '</label>';
    echo '<div class="input-group has-validation">';
    echo '<input type="password" id="' . e($name) . '" name="' . e($name) . '" class="' . $cls . '" value="' . e($value) . '" ' . $attrs . '>';
    echo '<button type="button" class="btn pw-toggle" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye"></i></button>';
    if ($error) echo '<div class="invalid-feedback">' . e($error) . '</div>';
    echo '</div></div>';
}
