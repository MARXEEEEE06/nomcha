<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require 'includes/otp_helpers.php';

$errors = [];
$email = '';
$stage = 'request';
$notice = $_SESSION['reset_notice'] ?? null;
unset($_SESSION['reset_notice']);
$challengeId = (int)($_SESSION['reset_challenge_id'] ?? 0);
$verifiedUserId = (int)($_SESSION['reset_verified_user_id'] ?? 0);
if ($challengeId > 0) $stage = 'verify';
if (!empty($_SESSION['reset_verified_at']) && (time() - (int)$_SESSION['reset_verified_at']) <= 900) $stage = 'choice';
if ($stage === 'choice' && isset($_SESSION['reset_change_password'])) $stage = 'new_password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Session expired. Please try again.';
    } else {
        $step = (string)($_POST['step'] ?? 'request');
        if ($step === 'request') {
            $email = strtolower(trim($_POST['email_addr'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                $errors['email_addr'] = 'Enter a valid email address.';
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT user_id FROM user_credentials WHERE email_addr = ? LIMIT 1');
                    $stmt->execute([$email]);
                    $foundUser = $stmt->fetchColumn();
                    // Send the same code flow regardless of account existence; keep the result generic.
                    $challengeId = create_otp_challenge($pdo, $email, 'password_reset', $foundUser ? (int)$foundUser : null);
                    $_SESSION['reset_challenge_id'] = $challengeId;
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_sent_at'] = time();
                    unset($_SESSION['reset_verified_at'], $_SESSION['reset_verified_user_id'], $_SESSION['reset_change_password']);
                    $stage = 'verify';
                    $_SESSION['reset_notice'] = 'If this address can receive mail, a six-digit code has been sent. It expires in five minutes.';
                    header('Location: forgot-password.php');
                    exit;
                } catch (DomainException $ex) {
                    $errors['form'] = $ex->getMessage();
                } catch (Throwable $ex) {
                    $errors['form'] = 'The code could not be sent. Please check the Make setup and try again.';
                }
            }
        } elseif ($step === 'resend') {
            $email = (string)($_SESSION['reset_email'] ?? '');
            $oldId = (int)($_SESSION['reset_challenge_id'] ?? 0);
            try {
                $stmt = $pdo->prepare('SELECT user_id FROM auth_otp_challenges WHERE challenge_id = ? AND email_addr = ? AND purpose = ?');
                $stmt->execute([$oldId, $email, 'password_reset']);
                $foundUser = $stmt->fetchColumn();
                $_SESSION['reset_challenge_id'] = create_otp_challenge($pdo, $email, 'password_reset', $foundUser ? (int)$foundUser : null);
                $_SESSION['reset_sent_at'] = time();
                unset($_SESSION['reset_verified_at'], $_SESSION['reset_verified_user_id']);
                $stage = 'verify';
                $_SESSION['reset_notice'] = 'If this address can receive mail, a new six-digit code has been sent.';
                header('Location: forgot-password.php');
                exit;
            } catch (DomainException $ex) {
                $errors['form'] = $ex->getMessage();
                $stage = 'verify';
            } catch (Throwable $ex) {
                $errors['form'] = 'The code could not be sent. Please check the Make setup and try again.';
                $stage = 'verify';
            }
        } elseif ($step === 'verify') {
            $code = trim((string)($_POST['otp'] ?? ''));
            $email = (string)($_SESSION['reset_email'] ?? '');
            if (!preg_match('/^\d{6}$/', $code)) {
                $errors['otp'] = 'Enter the six-digit code from your email.';
                $stage = 'verify';
            } else {
                try {
                    $challenge = verify_otp_challenge($pdo, (int)($_SESSION['reset_challenge_id'] ?? 0), $email, 'password_reset', $code);
                    if (!$challenge) {
                        $errors['form'] = 'That code is incorrect or expired. Request a new code and try again.';
                        $stage = 'verify';
                    } else {
                        $_SESSION['reset_verified_at'] = time();
                        $_SESSION['reset_verified_user_id'] = (int)($challenge['user_id'] ?? 0);
                        $stage = 'choice';
                        $_SESSION['reset_notice'] = 'Code verified. Choose how you want to continue.';
                        header('Location: forgot-password.php');
                        exit;
                    }
                } catch (Throwable $ex) {
                    $errors['form'] = 'Could not verify the code. Please try again.';
                    $stage = 'verify';
                }
            }
        } elseif ($step === 'new_password' && in_array($stage, ['choice', 'new_password'], true)) {
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if (!preg_match(PASS_RE, $password) || strlen($password) > 72) {
                $errors['password'] = 'Use 8 to 72 characters with a lowercase letter, an uppercase letter, a number, and a special character.';
            } elseif ($password !== $confirm) {
                $errors['confirm_password'] = 'Passwords do not match.';
            } elseif ((time() - (int)($_SESSION['reset_verified_at'] ?? 0)) > 900) {
                $errors['form'] = 'Your verification expired. Request a new code.';
                $stage = 'request';
            } elseif ((int)($_SESSION['reset_verified_user_id'] ?? 0) < 1) {
                $errors['form'] = 'If an account exists for this address, you can sign in using its current password.';
                $stage = 'choice';
            } else {
                $pdo->prepare('UPDATE user_credentials SET password = ? WHERE user_id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$_SESSION['reset_verified_user_id']]);
                auth_security_log($pdo, 'password_reset', (int)$_SESSION['reset_verified_user_id'], 'password_reset', 'password_changed');
                unset($_SESSION['reset_challenge_id'], $_SESSION['reset_email'], $_SESSION['reset_sent_at'], $_SESSION['reset_verified_at'], $_SESSION['reset_verified_user_id'], $_SESSION['reset_change_password']);
                $_SESSION['flash_success'] = 'Password updated. You can now log in.';
                header('Location: login.php');
                exit;
            }
        } elseif ($step === 'keep_password' && $stage === 'choice') {
            auth_security_log($pdo, 'password_reset', (int)($_SESSION['reset_verified_user_id'] ?? 0) ?: null, 'password_reset', 'kept_existing_password');
            unset($_SESSION['reset_challenge_id'], $_SESSION['reset_email'], $_SESSION['reset_sent_at'], $_SESSION['reset_verified_at'], $_SESSION['reset_verified_user_id'], $_SESSION['reset_change_password']);
            $_SESSION['flash_success'] = 'Your password was not changed. Log in with your existing password.';
            header('Location: login.php');
            exit;
        } elseif ($step === 'choose_new_password' && $stage === 'choice') {
            $_SESSION['reset_change_password'] = true;
            header('Location: forgot-password.php');
            exit;
        }
    }
}

$pageTitle = 'Forgot Password';
$pageScripts = '<script src="assets/js/auth.js"></script><script src="assets/js/otp-countdown.js"></script>';
require 'includes/header.php';
?>

<main class="container auth-wrap py-5">
  <div class="card auth-card col-md-8 col-lg-5 mx-auto">
    <div class="card-body p-4 p-md-5">
      <h1 class="h2 mb-1">Forgot password</h1>
      <p class="text-secondary mb-4">
        <?php if ($stage === 'request'): ?>Enter your email and we'll send a verification code.
        <?php elseif ($stage === 'verify'): ?>Enter the six-digit code sent to <?= e($_SESSION['reset_email'] ?? '') ?>.
        <?php elseif ($stage === 'choice'): ?>Choose whether to keep your current password or create a new one.
        <?php else: ?>Choose a new password for your account.<?php endif; ?>
      </p>

      <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
      <?php if (!empty($errors['form'])): ?><div class="alert alert-danger"><?= e($errors['form']) ?></div><?php endif; ?>

      <?php if ($stage === 'request'): ?>
        <form method="post" action="forgot-password.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="request">
          <?php form_field('email_addr', 'Email address', 'email', $email, $errors['email_addr'] ?? null, 'required maxlength="255" autocomplete="email"'); ?>
          <button type="submit" class="btn btn-auth w-100 py-2">Send verification code</button>
        </form>
      <?php elseif ($stage === 'verify'): ?>
        <form method="post" action="forgot-password.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <?php form_field('otp', 'Six-digit code', 'text', '', $errors['otp'] ?? null, 'required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"'); ?>
          <p class="small text-secondary mb-2" data-otp-countdown data-remaining="<?= max(0, 300 - (time() - (int)($_SESSION['reset_sent_at'] ?? time()))) ?>" data-resend-target="#resend-password-code" aria-live="polite"></p>
          <button id="resend-password-code" type="submit" name="step" value="resend" class="btn btn-link auth-link px-0" disabled>Resend code</button>
          <button type="submit" name="step" value="verify" class="btn btn-auth w-100 py-2">Verify code</button>
        </form>
        </form>
      <?php elseif ($stage === 'choice'): ?>
        <form method="post" action="forgot-password.php" class="mb-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="keep_password">
          <button type="submit" class="btn btn-outline-secondary w-100 py-2">Continue with my existing password</button>
        </form>
        <form method="post" action="forgot-password.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="choose_new_password">
          <button type="submit" class="btn btn-auth w-100 py-2">Set a new password</button>
        </form>
      <?php else: ?>
        <form method="post" action="forgot-password.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="new_password">
          <?php password_field('password', 'New password', '', $errors['password'] ?? null, 'required minlength="8" maxlength="72" autocomplete="new-password"'); ?>
          <?php password_field('confirm_password', 'Confirm new password', '', $errors['confirm_password'] ?? null, 'required maxlength="72" autocomplete="new-password"'); ?>
          <button type="submit" class="btn btn-auth w-100 py-2">Save new password</button>
        </form>
      <?php endif; ?>

      <p class="text-center mt-4 mb-0"><a href="login.php" class="auth-link">Back to login</a></p>
    </div>
  </div>
</main>

<?php require 'includes/footer.php'; ?>
