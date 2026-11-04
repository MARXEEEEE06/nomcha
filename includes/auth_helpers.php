<?php
if (session_status() === PHP_SESSION_NONE) session_start();

const NAME_RE   = '/^[\p{L}][\p{L} .\'-]{0,99}$/u';
const MOBILE_RE = '/^(09\d{9}|\+639\d{9})$/';
const PASS_RE   = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/';

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

function validate_registration(array $d, string $password, string $confirm): array {
    $err = [];
    if (!preg_match(NAME_RE, $d['first_name'])) $err['first_name'] = 'Enter a valid first name.';
    if ($d['middle_name'] !== '' && !preg_match(NAME_RE, $d['middle_name'])) $err['middle_name'] = 'Enter a valid middle name or leave it blank.';
    if (!preg_match(NAME_RE, $d['last_name'])) $err['last_name'] = 'Enter a valid last name.';

    $len = mb_strlen($d['home_addr']);
    if ($len < 5 || $len > 255) $err['home_addr'] = 'Enter your full home address (5 to 255 characters).';

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