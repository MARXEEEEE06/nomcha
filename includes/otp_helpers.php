<?php
require_once __DIR__ . '/make_mailer.php';

const OTP_TTL_MINUTES = 5;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_WAIT_SECONDS = 60;
const OTP_MAX_SENDS_PER_WINDOW = 3;
const OTP_SEND_WINDOW_MINUTES = 15;

function otp_pepper(): string {
    $pepper = (string)(nomcha_config()['otp_pepper'] ?? '');
    if (strlen($pepper) < 32 || str_starts_with($pepper, 'REPLACE_')) {
        throw new RuntimeException('Set a private OTP pepper in includes/local_config.php.');
    }
    return $pepper;
}

function auth_security_log(PDO $pdo, string $event, ?string $email, ?string $purpose, string $outcome, array $details = []): void {
    $pdo->prepare('INSERT INTO auth_security_logs (event_name, email_addr, purpose, outcome, details) VALUES (?, ?, ?, ?, ?)')
        ->execute([$event, $email, $purpose, $outcome, $details ? json_encode($details) : null]);
}

function create_otp_challenge(PDO $pdo, string $email, string $purpose, ?int $userId, ?array $registrationData = null): int {
    $pepper = otp_pepper();
    $pdo->exec('DELETE FROM auth_otp_challenges WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $recent = $pdo->prepare('SELECT COUNT(*) FROM auth_otp_challenges WHERE email_addr = ? AND purpose = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ' . OTP_SEND_WINDOW_MINUTES . ' MINUTE)');
    $recent->execute([$email, $purpose]);
    if ((int)$recent->fetchColumn() >= OTP_MAX_SENDS_PER_WINDOW) {
        auth_security_log($pdo, 'otp_request', $email, $purpose, 'rate_limited');
        throw new DomainException('Too many code requests. Please try again later.');
    }
    $latest = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM auth_otp_challenges WHERE email_addr = ? AND purpose = ? ORDER BY challenge_id DESC LIMIT 1');
    $latest->execute([$email, $purpose]);
    $lastAgeSeconds = $latest->fetchColumn();
    if ($lastAgeSeconds !== false && (int)$lastAgeSeconds < OTP_RESEND_WAIT_SECONDS) {
        auth_security_log($pdo, 'otp_request', $email, $purpose, 'cooldown');
        throw new DomainException('Please wait a minute before requesting another code.');
    }

    $pdo->prepare('UPDATE auth_otp_challenges SET consumed_at = NOW() WHERE email_addr = ? AND purpose = ? AND consumed_at IS NULL')
        ->execute([$email, $purpose]);
    $otp = (string)random_int(100000, 999999);
    $hash = hash_hmac('sha256', $otp, $pepper);
    $data = $registrationData === null ? null : json_encode($registrationData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO auth_otp_challenges (email_addr, purpose, user_id, otp_hash, registration_data, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . OTP_TTL_MINUTES . ' MINUTE))')
        ->execute([$email, $purpose, $userId, $hash, $data]);
    $id = (int)$pdo->lastInsertId();
    auth_security_log($pdo, 'otp_request', $email, $purpose, 'created', ['challenge_id' => $id]);

    try {
        send_otp_email($email, $purpose, $otp);
        auth_security_log($pdo, 'otp_delivery', $email, $purpose, 'accepted_by_make', ['challenge_id' => $id]);
    } catch (Throwable $e) {
        auth_security_log($pdo, 'otp_delivery', $email, $purpose, 'failed', ['challenge_id' => $id, 'reason' => substr($e->getMessage(), 0, 180)]);
        throw $e;
    }
    return $id;
}

function verify_otp_challenge(PDO $pdo, int $id, string $email, string $purpose, string $otp): ?array {
    $stmt = $pdo->prepare('SELECT * FROM auth_otp_challenges WHERE challenge_id = ? AND email_addr = ? AND purpose = ? AND consumed_at IS NULL LIMIT 1');
    $stmt->execute([$id, $email, $purpose]);
    $challenge = $stmt->fetch();
    if (!$challenge) return null;
    $expiry = $pdo->prepare('SELECT expires_at <= NOW() FROM auth_otp_challenges WHERE challenge_id = ?');
    $expiry->execute([$id]);
    if ((bool)$expiry->fetchColumn()) {
        auth_security_log($pdo, 'otp_verify', $email, $purpose, 'expired', ['challenge_id' => $id]);
        return null;
    }
    if ((int)$challenge['attempts'] >= OTP_MAX_ATTEMPTS) {
        auth_security_log($pdo, 'otp_verify', $email, $purpose, 'locked', ['challenge_id' => $id]);
        return null;
    }
    $expected = hash_hmac('sha256', $otp, otp_pepper());
    if (!hash_equals($challenge['otp_hash'], $expected)) {
        $pdo->prepare('UPDATE auth_otp_challenges SET attempts = attempts + 1 WHERE challenge_id = ?')->execute([$id]);
        auth_security_log($pdo, 'otp_verify', $email, $purpose, 'invalid', ['challenge_id' => $id]);
        return null;
    }
    $pdo->prepare('UPDATE auth_otp_challenges SET consumed_at = NOW() WHERE challenge_id = ? AND consumed_at IS NULL')->execute([$id]);
    auth_security_log($pdo, 'otp_verify', $email, $purpose, 'success', ['challenge_id' => $id]);
    return $challenge;
}
