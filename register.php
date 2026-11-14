<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require 'includes/otp_helpers.php';
require_once 'includes/make_mailer.php';
require 'includes/psgc_helpers.php';

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$registrationSuccess = !empty($_SESSION['registration_success_pending']) && ($_GET['account_created'] ?? '') === '1';
if ($registrationSuccess) unset($_SESSION['registration_success_pending']);

$errors = [];
$notice = $_SESSION['registration_notice'] ?? null;
unset($_SESSION['registration_notice']);
$old = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'recipient_name' => '', 'street_address' => '', 'region_code' => '', 'province_code' => '', 'municipality_code' => '', 'barangay_code' => '', 'zip_code' => '', 'latitude' => '', 'longitude' => '', 'email_addr' => '', 'mobileno' => ''];
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
        unset($_SESSION['registration_challenge_id'], $_SESSION['registration_email'], $_SESSION['registration_notice'], $_SESSION['registration_sent_at']);
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
            $_SESSION['registration_sent_at'] = time();
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
                        ->execute([$data['first_name'], $data['middle_name'] ?: null, $data['last_name'], $data['street_address'], $data['mobileno']]);
                    $userId = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO user_credentials (user_id, email_addr, password) VALUES (?, ?, ?)')
                        ->execute([$userId, $email, $data['password_hash']]);
                    $pdo->prepare('INSERT INTO user_addresses (user_id, recipient_name, mobile_no, street_address, region_code, province, province_code, municipality, municipality_code, barangay, barangay_code, zip_code, latitude, longitude, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
                        ->execute([$userId, $data['recipient_name'], $data['mobileno'], $data['street_address'], $data['region_code'] ?? null, $data['province'], $data['province_code'] ?? null, $data['municipality'], $data['municipality_code'] ?? null, $data['barangay'], $data['barangay_code'] ?? null, $data['zip_code'], $data['latitude'], $data['longitude']]);
                    $pdo->commit();
                    try {
                        send_customer_welcome_email($email, trim($data['first_name'] . ' ' . $data['last_name']));
                    } catch (Throwable $mailError) {
                        error_log('Nomcha customer welcome email failed: ' . $mailError->getMessage());
                    }
                    $pdo->prepare('UPDATE auth_otp_challenges SET registration_data = NULL WHERE challenge_id = ?')->execute([$challengeId]);
                    auth_security_log($pdo, 'registration', $userId, 'registration', 'completed');
                    unset($_SESSION['registration_challenge_id'], $_SESSION['registration_email'], $_SESSION['registration_sent_at']);
                    $_SESSION['registration_success_pending'] = true;
                    header('Location: register.php?account_created=1');
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
                $place = psgc_resolve($pdo, $old['region_code'], $old['province_code'], $old['municipality_code'], $old['barangay_code']);
                if (!$place) $errors['province_code'] = 'Choose the matching province and city / municipality for this region.';
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
                        'recipient_name' => $old['recipient_name'],
                        'street_address' => $old['street_address'],
                        'region_code' => $place['region_code'],
                        'province' => $place['province'],
                        'province_code' => $place['province_code'],
                        'municipality' => $place['municipality'],
                        'municipality_code' => $place['municipality_code'],
                        'barangay' => $place['barangay'],
                        'barangay_code' => $place['barangay_code'],
                        'zip_code' => $old['zip_code'],
                        'latitude' => $old['latitude'],
                        'longitude' => $old['longitude'],
                        'mobileno' => $mobile,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ];
                    $challengeId = create_otp_challenge($pdo, $old['email_addr'], 'registration', null, $data);
                    $_SESSION['registration_challenge_id'] = $challengeId;
                    $_SESSION['registration_email'] = $old['email_addr'];
                    $_SESSION['registration_sent_at'] = time();
                    $verifying = true;
                    $_SESSION['registration_notice'] = 'We sent a six-digit verification code to your email. It expires in five minutes.';
                    auth_security_log($pdo, 'registration', null, 'registration', 'verification_started');
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
$pageStyles = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
$pageScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/js/auth.js"></script><script src="assets/js/psgc-select.js"></script><script src="assets/js/address-map.js"></script><script src="assets/js/otp-countdown.js"></script>';
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
          <?php form_field('otp', 'Six-digit code', 'text', '', $errors['otp'] ?? null, 'required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"'); ?>
          <p class="small text-secondary mb-2" data-otp-countdown data-remaining="<?= max(0, 300 - (time() - (int)($_SESSION['registration_sent_at'] ?? time()))) ?>" data-resend-target="#resend-registration-code" aria-live="polite"></p>
          <button id="resend-registration-code" type="submit" name="step" value="resend" class="btn btn-link auth-link px-0" disabled>Resend code</button>
          <button type="submit" name="step" value="verify" class="btn btn-auth w-100 py-2">Verify email and create account</button>
        </form>
        <form method="post" action="register.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="step" value="restart">
          <button type="submit" class="btn btn-link text-secondary w-100">Start registration over</button>
        </form>
      <?php else: ?>
        <form method="post" action="register.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <fieldset class="mb-4"><legend class="h5">Personal info</legend><div class="row">
            <div class="col-md-4"><?php form_field('first_name', 'First name', 'text', $old['first_name'], $errors['first_name'] ?? null, 'required minlength="2" maxlength="100" autocomplete="given-name"'); ?></div>
            <div class="col-md-4"><?php form_field('middle_name', 'Middle name (optional)', 'text', $old['middle_name'], $errors['middle_name'] ?? null, 'minlength="2" maxlength="100" autocomplete="additional-name"'); ?></div>
            <div class="col-md-4"><?php form_field('last_name', 'Last name', 'text', $old['last_name'], $errors['last_name'] ?? null, 'required minlength="2" maxlength="100" autocomplete="family-name"'); ?></div>
          </div></fieldset>
          <fieldset class="mb-4"><legend class="h5">Password</legend><div class="row">
            <div class="col-md-6">
              <?php password_field('password', 'Password', $password, $errors['password'] ?? null, 'required minlength="8" maxlength="72" autocomplete="new-password"'); ?>
              <ul id="pw-rules" class="pw-rules mb-3">
                <li data-rule="len"><i class="bi bi-circle"></i> At least 8 characters</li><li data-rule="lower"><i class="bi bi-circle"></i> Lowercase letter</li><li data-rule="upper"><i class="bi bi-circle"></i> Uppercase letter</li><li data-rule="number"><i class="bi bi-circle"></i> Number</li><li data-rule="special"><i class="bi bi-circle"></i> Special character</li>
              </ul>
            </div>
            <div class="col-md-6"><?php password_field('confirm_password', 'Confirm password', $confirm, $errors['confirm_password'] ?? null, 'required maxlength="72" autocomplete="new-password"'); ?></div>
          </div></fieldset>
          <fieldset class="mb-4"><legend class="h5">Contact info</legend><div class="row register-contact-row">
            <div class="col-md-6"><?php form_field('email_addr', 'Email address', 'email', $old['email_addr'], $errors['email_addr'] ?? null, 'required maxlength="255" autocomplete="email"'); ?></div>
            <div class="col-md-6"><?php form_field('mobileno', 'Mobile number', 'tel', $old['mobileno'], $errors['mobileno'] ?? null, 'required maxlength="13" inputmode="tel" placeholder="09XXXXXXXXX" pattern="(09\d{9}|\+639\d{9})" autocomplete="tel"'); ?></div>
          </div></fieldset>
          <fieldset class="mb-4"><legend class="h5">Billing address <small class="text-secondary">(default)</small></legend>
            <?php form_field('recipient_name', 'Recipient name', 'text', $old['recipient_name'], $errors['recipient_name'] ?? null, 'required maxlength="120" autocomplete="name" placeholder="e.g. Juan Dela Cruz"'); ?>
            <?php form_field('street_address', 'Unit/House Number and Street Name', 'text', $old['street_address'], $errors['street_address'] ?? null, 'required minlength="2" maxlength="255" autocomplete="address-line1" placeholder="Unit/House Number and Street Name"'); ?>
            <?php $psgc = psgc_form_state($pdo, $old); ?>
            <div class="row"><div class="col-md-3"><?php psgc_select_field('region', $psgc['regions'], $old['region_code'], $errors['region_code'] ?? null); ?></div><div class="col-md-3"><?php psgc_select_field('province', $psgc['provinces'], $old['province_code'], $errors['province_code'] ?? null); ?></div><div class="col-md-3"><?php psgc_select_field('municipality', $psgc['localities'], $old['municipality_code'], $errors['municipality_code'] ?? null); ?></div><div class="col-md-3"><?php psgc_select_field('barangay', $psgc['barangays'], $old['barangay_code'], $errors['barangay_code'] ?? null); ?></div></div><div class="row"><div class="col-md-3"><?php form_field('zip_code', 'ZIP code', 'text', $old['zip_code'], $errors['zip_code'] ?? null, 'required maxlength="4" inputmode="numeric" pattern="[0-9]{4}" autocomplete="postal-code" placeholder="e.g. 1105"'); ?></div></div>
            <label class="form-label" for="address-map">Map pin location</label><div id="address-map" class="branch-map" style="height:320px;min-height:280px;position:relative"></div>
            <div class="row mt-2"><div class="col-sm-6"><?php form_field('latitude', 'Latitude', 'number', $old['latitude'], $errors['latitude'] ?? null, 'required step="any" min="-90" max="90"'); ?></div><div class="col-sm-6"><?php form_field('longitude', 'Longitude', 'number', $old['longitude'], $errors['longitude'] ?? null, 'required step="any" min="-180" max="180"'); ?></div></div>
          </fieldset>
          <button type="submit" class="btn btn-auth w-100 py-2 mt-2">Send verification code</button>
        </form>
      <?php endif; ?>
      <p class="text-center mt-4 mb-0">Already have an account? <a href="login.php" class="auth-link">Log in</a></p>
    </div>
  </div>
</main>

<?php if ($registrationSuccess): ?>
<div class="modal fade" id="registrationSuccessModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Account created</h2></div><div class="modal-body">Your email is verified and your account is ready. Continue to log in.</div><div class="modal-footer"><a class="btn btn-auth" href="login.php">Continue to login</a></div></div></div></div>
<script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal('#registrationSuccessModal',{backdrop:'static',keyboard:false}).show());</script>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
