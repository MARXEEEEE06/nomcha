<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require 'includes/otp_helpers.php';

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$errors = [];
$notice = $_SESSION['registration_notice'] ?? null;
unset($_SESSION['registration_notice']);
$old = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'home_addr' => '', 'email_addr' => '', 'mobileno' => ''];
$password = $confirm = '';
$verifying = !empty($_SESSION['registration_challenge_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Session expired. Please try again.';
    } elseif (($_POST['step'] ?? '') === 'restart') {
        $oldId = (int)($_SESSION['registration_challenge_id'] ?? 0);
        if ($oldId > 0) {
            $pdo->prepare('UPDATE auth_otp_challenges SET consumed_at = NOW() WHERE challenge_id = ? AND purpose = ? AND consumed_at IS NULL')
                ->execute([$oldId, 'registration']);
        }
        unset($_SESSION['registration_challenge_id'], $_SESSION['registration_email'], $_SESSION['registration_notice']);
        header('Location: register.php');
        exit;
    } elseif (($_POST['step'] ?? '') === 'resend') {
        $oldId = (int)($_SESSION['registration_challenge_id'] ?? 0);
        $email = (string)($_SESSION['registration_email'] ?? '');
        try {
            $stmt = $pdo->prepare('SELECT registration_data FROM auth_otp_challenges WHERE challenge_id = ? AND email_addr = ? AND purpose = ?');
            $stmt->execute([$oldId, $email, 'registration']);
            $data = json_decode((string)$stmt->fetchColumn(), true);
            if (!is_array($data)) throw new RuntimeException('Registration has expired. Please start again.');
            $_SESSION['registration_challenge_id'] = create_otp_challenge($pdo, $email, 'registration', null, $data);
            $_SESSION['registration_notice'] = 'A new verification code was requested. Check your email.';
            header('Location: register.php');
            exit;
        } catch (DomainException $ex) {
            $errors['form'] = $ex->getMessage();
        } catch (Throwable $ex) {
            $errors['form'] = 'Could not send a new code. Please try again.';
        }
        $verifying = true;
    } elseif (($_POST['step'] ?? '') === 'verify') {
        $email = (string)($_SESSION['registration_email'] ?? '');
        $challengeId = (int)($_SESSION['registration_challenge_id'] ?? 0);
        $code = trim((string)($_POST['otp'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $errors['otp'] = 'Enter the six-digit code from your email.';
        } else {
            try {
                $challenge = verify_otp_challenge($pdo, $challengeId, $email, 'registration', $code);
                if (!$challenge) {
                    $errors['form'] = 'That code is incorrect or expired. Request a new code and try again.';
                } else {
                    $data = json_decode((string)$challenge['registration_data'], true);
                    if (!is_array($data)) throw new RuntimeException('Registration data is unavailable.');
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO user_info (first_name, middle_name, last_name, home_addr, mobileno) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$data['first_name'], $data['middle_name'] ?: null, $data['last_name'], $data['home_addr'], $data['mobileno']]);
                    $userId = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO user_credentials (user_id, email_addr, password) VALUES (?, ?, ?)')
                        ->execute([$userId, $email, $data['password_hash']]);
                    $pdo->commit();
                    $pdo->prepare('UPDATE auth_otp_challenges SET registration_data = NULL WHERE challenge_id = ?')->execute([$challengeId]);
                    auth_security_log($pdo, 'registration', $email, 'registration', 'completed');
                    unset($_SESSION['registration_challenge_id'], $_SESSION['registration_email']);
                    $_SESSION['flash_success'] = 'Email verified and account created. Please log in.';
                    header('Location: login.php');
                    exit;
                }
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors['form'] = $ex->getCode() === '23000' ? 'That email or mobile number is already registered. Please restart registration.' : 'Account creation failed. Please try again.';
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors['form'] = 'Verification could not be completed. Please try again.';
            }
        }
        $verifying = true;
    } else {
        foreach ($old as $k => $_) $old[$k] = trim($_POST[$k] ?? '');
        $old['email_addr'] = strtolower($old['email_addr']);
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $errors = validate_registration($old, $password, $confirm);
        if (!$errors) {
            try {
                $s = $pdo->prepare('SELECT 1 FROM user_credentials WHERE email_addr = ?');
                $s->execute([$old['email_addr']]);
                if ($s->fetchColumn()) $errors['email_addr'] = 'This email is already registered.';
                $mobile = normalize_mobile($old['mobileno']);
                $s = $pdo->prepare('SELECT 1 FROM user_info WHERE mobileno = ?');
                $s->execute([$mobile]);
                if ($s->fetchColumn()) $errors['mobileno'] = 'This mobile number is already registered.';

                if (!$errors) {
                    $data = [
                        'first_name' => $old['first_name'],
                        'middle_name' => $old['middle_name'],
                        'last_name' => $old['last_name'],
                        'home_addr' => $old['home_addr'],
                        'mobileno' => $mobile,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ];
                    $challengeId = create_otp_challenge($pdo, $old['email_addr'], 'registration', null, $data);
                    $_SESSION['registration_challenge_id'] = $challengeId;
                    $_SESSION['registration_email'] = $old['email_addr'];
                    $verifying = true;
                    $_SESSION['registration_notice'] = 'We sent a six-digit verification code to your email. It expires in five minutes.';
                    auth_security_log($pdo, 'registration', $old['email_addr'], 'registration', 'verification_started');
                    header('Location: register.php');
                    exit;
                }
            } catch (DomainException $ex) {
                $errors['form'] = $ex->getMessage();
            } catch (Throwable $ex) {
                $errors['form'] = 'Could not send the verification code. Check the Make setup and try again.';
            }
        }
    }
}

$pageTitle = 'Register';
$pageScripts = '<script src="assets/js/auth.js"></script>';
require 'includes/header.php';
?>

<main class="container auth-wrap py-5">
  <div class="card auth-card col-md-10 col-lg-8 mx-auto">
    <div class="card-body p-4 p-md-5">
      <h1 class="h2 mb-1"><?= $verifying ? 'Verify your email' : 'Create your account' ?></h1>
      <p class="text-secondary mb-4"><?= $verifying ? 'Enter the code sent to ' . e($_SESSION['registration_email'] ?? '') . '.' : 'Join Nomcha in a minute.' ?></p>

      <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
      <?php if (!empty($errors['form'])): ?><div class="alert alert-danger"><?= e($errors['form']) ?></div><?php endif; ?>

      <?php if ($verifying): ?>
        <form method="post" action="register.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="verify">
          <?php form_field('otp', 'Six-digit code', 'text', '', $errors['otp'] ?? null, 'required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"'); ?>
          <button type="submit" class="btn btn-auth w-100 py-2">Verify email and create account</button>
        </form>
        <form method="post" action="register.php" class="mt-3">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="resend">
          <button type="submit" class="btn btn-link auth-link w-100">Request another code</button>
        </form>
        <form method="post" action="register.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="restart">
          <button type="submit" class="btn btn-link text-secondary w-100">Start registration over</button>
        </form>
      <?php else: ?>
        <form method="post" action="register.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="row">
            <div class="col-md-4"><?php form_field('first_name', 'First name', 'text', $old['first_name'], $errors['first_name'] ?? null, 'required maxlength="100" autocomplete="given-name"'); ?></div>
            <div class="col-md-4"><?php form_field('middle_name', 'Middle name (optional)', 'text', $old['middle_name'], $errors['middle_name'] ?? null, 'maxlength="100" autocomplete="additional-name"'); ?></div>
            <div class="col-md-4"><?php form_field('last_name', 'Last name', 'text', $old['last_name'], $errors['last_name'] ?? null, 'required maxlength="100" autocomplete="family-name"'); ?></div>
          </div>
          <?php form_field('home_addr', 'Home address', 'text', $old['home_addr'], $errors['home_addr'] ?? null, 'required maxlength="255" autocomplete="street-address"'); ?>
          <div class="row register-contact-row">
            <div class="col-md-6"><?php form_field('email_addr', 'Email address', 'email', $old['email_addr'], $errors['email_addr'] ?? null, 'required maxlength="255" autocomplete="email"'); ?></div>
            <div class="col-md-6"><?php form_field('mobileno', 'Mobile number', 'tel', $old['mobileno'], $errors['mobileno'] ?? null, 'required maxlength="13" inputmode="tel" placeholder="09XXXXXXXXX" pattern="(09\d{9}|\+639\d{9})" autocomplete="tel"'); ?></div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <?php password_field('password', 'Password', $password, $errors['password'] ?? null, 'required minlength="8" maxlength="72" autocomplete="new-password"'); ?>
              <ul id="pw-rules" class="pw-rules mb-3">
                <li data-rule="len"><i class="bi bi-circle"></i> At least 8 characters</li>
                <li data-rule="lower"><i class="bi bi-circle"></i> Lowercase letter</li>
                <li data-rule="upper"><i class="bi bi-circle"></i> Uppercase letter</li>
                <li data-rule="number"><i class="bi bi-circle"></i> Number</li>
                <li data-rule="special"><i class="bi bi-circle"></i> Special character</li>
              </ul>
            </div>
            <div class="col-md-6"><?php password_field('confirm_password', 'Confirm password', $confirm, $errors['confirm_password'] ?? null, 'required maxlength="72" autocomplete="new-password"'); ?></div>
          </div>
          <button type="submit" class="btn btn-auth w-100 py-2 mt-2">Send verification code</button>
        </form>
      <?php endif; ?>
      <p class="text-center mt-4 mb-0">Already have an account? <a href="login.php" class="auth-link">Log in</a></p>
    </div>
  </div>
</main>

<?php require 'includes/footer.php'; ?>
