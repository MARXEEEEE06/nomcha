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

function normalize_mobile(string $m): string {
    return str_starts_with($m, '+63') ? '0' . substr($m, 3) : $m;
}

function valid_login_email(string $email): bool {
    return $email === 'admin@admin' || (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
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

    foreach (['recipient_name', 'street_address', 'province', 'municipality', 'barangay'] as $field) {
        $max = $field === 'street_address' ? 255 : 120;
        if (mb_strlen($d[$field] ?? '') < 2 || mb_strlen($d[$field] ?? '') > $max) $err[$field] = 'Please enter a valid value.';
    }
    if (!preg_match('/^\d{4}$/', $d['zip_code'] ?? '')) $err['zip_code'] = 'Enter a 4-digit ZIP code.';
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
