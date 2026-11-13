<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require_once 'includes/make_mailer.php';
require 'includes/otp_helpers.php';

// Returning to login ends any in-progress password-reset flow for this session.
$resetChallengeId = (int)($_SESSION['reset_challenge_id'] ?? 0);
if ($resetChallengeId > 0) {
    $pdo->prepare('UPDATE auth_otp_challenges SET consumed_at = NOW() WHERE challenge_id = ? AND purpose = ? AND consumed_at IS NULL')
        ->execute([$resetChallengeId, 'password_reset']);
}
unset(
    $_SESSION['reset_challenge_id'],
    $_SESSION['reset_email'],
    $_SESSION['reset_sent_at'],
    $_SESSION['reset_verified_at'],
    $_SESSION['reset_verified_user_id'],
    $_SESSION['reset_change_password'],
    $_SESSION['reset_notice']
);

$loginWelcome = !empty($_SESSION['user_id']) && ($_GET['auth_success'] ?? '') === '1';
if (!empty($_SESSION['user_id']) && !$loginWelcome) { header('Location: ' . (current_role($pdo) === 'admin' ? 'admin.php' : 'index.php')); exit; }

$redirectCandidate = (string)($_POST['redirect'] ?? $_GET['redirect'] ?? '');
$redirectTo = $redirectCandidate === 'order.php' ? 'order.php' : 'index.php';
if ($redirectTo === 'index.php') {
    $redirectPath = parse_url($redirectCandidate, PHP_URL_PATH);
    $redirectQuery = [];
    parse_str((string)parse_url($redirectCandidate, PHP_URL_QUERY), $redirectQuery);
    $pickupLocation = trim((string)($redirectQuery['pickup_location'] ?? ''));
    if ($redirectPath === 'order.php'
        && ($redirectQuery['fulfillment'] ?? '') === 'pickup'
        && $pickupLocation !== ''
        && mb_strlen($pickupLocation) <= 255) {
        $redirectTo = 'order.php?fulfillment=pickup&pickup_location=' . rawurlencode($pickupLocation);
    }
}
$errors  = [];
$email   = '';
$password = '';
$success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$loginDestination = $loginWelcome ? (string)($_SESSION['login_destination'] ?? 'index.php') : '';
if ($loginWelcome && current_role($pdo) === 'admin') $loginDestination = 'admin.php';
if ($loginWelcome) unset($_SESSION['login_destination']);
$otpPending = !empty($_SESSION['login_otp_challenge_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email_addr'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'send_sample') {
        if (!valid_login_email($email) || strlen($email) > 255) {
            $errors['email_addr'] = 'Enter a valid email address to receive the sample.';
        } else {
            try {
                send_sample_email($email);
                $success = 'Sample email sent. Check your inbox.';
            } catch (Throwable $ex) {
                $errors['form'] = 'Sample email could not be sent. Check the Make setup and try again.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'verify_login_otp') {
        $code = trim((string)($_POST['otp'] ?? ''));
        try {
            $challenge = preg_match('/^\d{6}$/', $code)
                ? verify_otp_challenge($pdo, (int)($_SESSION['login_otp_challenge_id'] ?? 0), (string)($_SESSION['login_otp_email'] ?? ''), 'login', $code)
                : null;
            if (!$challenge || (int)$challenge['user_id'] !== (int)($_SESSION['login_otp_user_id'] ?? 0)) {
                $errors['form'] = 'That code is incorrect or expired. Request a new code and try again.';
            } else {
                $user = $pdo->prepare('SELECT c.user_id,c.role,i.first_name FROM user_credentials c JOIN user_info i ON i.user_id=c.user_id WHERE c.user_id=? AND c.email_addr=? AND c.is_active=1');
                $user->execute([(int)$challenge['user_id'], (string)($_SESSION['login_otp_email'] ?? '')]);
                $row = $user->fetch();
                if (!$row) throw new RuntimeException('Account is unavailable.');
                session_regenerate_id(true);
                unset($_SESSION['csrf']);
                $_SESSION['user_id'] = (int)$row['user_id'];
                $_SESSION['first_name'] = $row['first_name'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['login_destination'] = $redirectTo;
                unset($_SESSION['login_otp_challenge_id'], $_SESSION['login_otp_user_id'], $_SESSION['login_otp_email'], $_SESSION['login_otp_sent_at']);
                header('Location: login.php?auth_success=1'); exit;
            }
        } catch (Throwable $ex) { $errors['form'] = 'Could not verify the code. Please try again.'; }
        $otpPending = true;
    } elseif (($_POST['action'] ?? '') === 'cancel_login_otp') {
        $challengeId = (int)($_SESSION['login_otp_challenge_id'] ?? 0);
        if ($challengeId) $pdo->prepare('UPDATE auth_otp_challenges SET consumed_at=NOW() WHERE challenge_id=? AND purpose=\'login\' AND consumed_at IS NULL')->execute([$challengeId]);
        unset($_SESSION['login_otp_challenge_id'], $_SESSION['login_otp_user_id'], $_SESSION['login_otp_email'], $_SESSION['login_otp_sent_at']);
        $otpPending = false;
    } elseif (($_POST['action'] ?? '') === 'send_login_otp') {
        if (!valid_login_email($email) || strlen($email) > 255) $errors['email_addr'] = 'Enter a valid email address.';
        else {
            try {
                $stmt = $pdo->prepare('SELECT user_id FROM user_credentials WHERE email_addr=? AND is_active=1');
                $stmt->execute([$email]);
                $userId = (int)$stmt->fetchColumn();
                if ($userId) {
                    $challengeId = create_otp_challenge($pdo, $email, 'login', $userId);
                    $_SESSION['login_otp_challenge_id'] = $challengeId;
                    $_SESSION['login_otp_user_id'] = $userId;
                    $_SESSION['login_otp_email'] = $email;
                    $_SESSION['login_otp_sent_at'] = time();
                }
                $success = 'If that email belongs to an active account, a sign-in code has been sent.';
            } catch (Throwable $ex) { $errors['form'] = $ex instanceof DomainException ? $ex->getMessage() : 'Could not send a sign-in code. Please try again.'; }
        }
        $otpPending = !empty($_SESSION['login_otp_challenge_id']);
    } else {
        if (!valid_login_email($email) || strlen($email) > 255) {
            $errors['email_addr'] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }

        if (!$errors) {
            try {
                if (login_is_locked($pdo, $email)) {
                    $errors['form'] = 'Too many login attempts, please try again later.';
                } else {
                    $stmt = $pdo->prepare(
                        'SELECT c.user_id, c.password, c.role, c.is_active, i.first_name
                         FROM user_credentials c
                         JOIN user_info i ON i.user_id = c.user_id
                         WHERE c.email_addr = ? LIMIT 1'
                    );
                    $stmt->execute([$email]);
                    $row  = $stmt->fetch();
                    $hash = $row['password'] ?? password_hash('dummy-value', PASSWORD_DEFAULT);

                    if ($row && (int)$row['is_active'] === 1 && account_password_matches($email, $hash, $password)) {
                        clear_login_failures($pdo, $email);
                        try {
                            $challengeId = create_otp_challenge($pdo, $email, 'login', (int)$row['user_id']);
                            $_SESSION['login_otp_challenge_id'] = $challengeId;
                            $_SESSION['login_otp_user_id'] = (int)$row['user_id'];
                            $_SESSION['login_otp_email'] = $email;
                            $_SESSION['login_otp_sent_at'] = time();
                            $otpPending = true;
                            $success = 'We sent a six-digit sign-in code to your email.';
                        } catch (Throwable $ex) {
                            $errors['form'] = $ex instanceof DomainException ? $ex->getMessage() : 'Could not send a sign-in code. Please try again.';
                        }
                    } else {
                        $locked = record_login_failure($pdo, $email);
                        $errors['form'] = $locked
                            ? 'Too many login attempts, please try again later.'
                            : 'Invalid email or password.';
                    }
                }
            } catch (PDOException $ex) {
                $errors['form'] = 'Login failed. Please try again.';
            }
        }
    }
}

$pageTitle = 'Login';
$pageScripts = '<script src="assets/js/auth.js"></script>';
require 'includes/header.php';
?>

<main class="container auth-wrap py-5">
  <div class="card auth-card col-md-8 col-lg-5 mx-auto">
    <div class="card-body p-4 p-md-5">
      <h1 class="h2 mb-1">Welcome back</h1>
      <p class="text-secondary mb-4"><?= $otpPending ? 'Enter the code sent to ' . e($_SESSION['login_otp_email'] ?? '') . '.' : 'Log in to your Nomcha account.' ?></p>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
      <?php endif; ?>
      <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-danger"><?= e($errors['form']) ?></div>
      <?php endif; ?>

      <?php if ($otpPending): ?><form method="post" action="login.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
        <?php form_field('otp', 'Six-digit sign-in code', 'text', '', $errors['otp'] ?? null, 'required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"'); ?>
        <button type="submit" name="action" value="verify_login_otp" class="btn btn-auth w-100 py-2">Verify and log in</button>
      </form><form method="post" action="login.php" class="mt-2 d-flex justify-content-between"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="email_addr" value="<?=e((string)($_SESSION['login_otp_email']??''))?>"><input type="hidden" name="redirect" value="<?=e($redirectTo)?>"><button class="btn btn-link auth-link px-0" name="action" value="send_login_otp">Send another code</button><button class="btn btn-link text-secondary px-0" name="action" value="cancel_login_otp">Back to password login</button></form>
      <?php else: ?><form method="post" action="login.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">

        <?php form_field('email_addr', 'Email address', 'text', $email, $errors['email_addr'] ?? null, 'required maxlength="255" inputmode="email" autocapitalize="none" spellcheck="false" autocomplete="username"'); ?>
        <?php password_field('password', 'Password', $password, $errors['password'] ?? null, 'required maxlength="72" autocomplete="current-password"'); ?>

        <div class="text-end mb-3"><a href="forgot-password.php" class="auth-link small">Forgot password?</a></div>

        <button type="submit" class="btn btn-auth w-100 py-2">Log in</button>
        <button type="submit" name="action" value="send_login_otp" formnovalidate class="btn btn-outline-secondary w-100 py-2 mt-2">Log in with a code</button>
        <button type="submit" name="action" value="send_sample" hidden formnovalidate class="btn btn-outline-secondary w-100 py-2 mt-2">Send sample email</button>
      </form>
      <?php endif; ?>

      <p class="text-center mt-4 mb-0">No account yet? <a href="register.php" class="auth-link">Register</a></p>
    </div>
  </div>
</main>

<?php if ($loginWelcome): ?>
<div class="modal fade" id="loginSuccessModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Login successful</h2></div><div class="modal-body">Welcome back, <?=e((string)($_SESSION['first_name']??'customer'))?>.</div><div class="modal-footer"><a class="btn btn-auth" href="<?=e($loginDestination ?: 'index.php')?>">Continue</a></div></div></div></div>
<script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal('#loginSuccessModal',{backdrop:'static',keyboard:false}).show());</script>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
