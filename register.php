<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$errors = [];
$old = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'home_addr' => '', 'email_addr' => '', 'mobileno' => ''];
$password = $confirm = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) $old[$k] = trim($_POST[$k] ?? '');
    $old['email_addr'] = strtolower($old['email_addr']);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Session expired. Please try again.';
    } else {
        $errors = validate_registration($old, $password, $confirm);

        if (!$errors) {
            $mobile = normalize_mobile($old['mobileno']);
            try {
                $s = $pdo->prepare('SELECT 1 FROM user_credentials WHERE email_addr = ?');
                $s->execute([$old['email_addr']]);
                if ($s->fetchColumn()) $errors['email_addr'] = 'This email is already registered.';

                $s = $pdo->prepare('SELECT 1 FROM user_info WHERE mobileno = ?');
                $s->execute([$mobile]);
                if ($s->fetchColumn()) $errors['mobileno'] = 'This mobile number is already registered.';

                if (!$errors) {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'INSERT INTO user_info (first_name, middle_name, last_name, home_addr, mobileno)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([
                        $old['first_name'],
                        $old['middle_name'] !== '' ? $old['middle_name'] : null,
                        $old['last_name'],
                        $old['home_addr'],
                        $mobile,
                    ]);
                    $uid = (int)$pdo->lastInsertId();

                    $pdo->prepare(
                        'INSERT INTO user_credentials (user_id, email_addr, password) VALUES (?, ?, ?)'
                    )->execute([$uid, $old['email_addr'], password_hash($password, PASSWORD_DEFAULT)]);
                    $pdo->commit();

                    $_SESSION['flash_success'] = 'Account created. Please log in.';
                    header('Location: login.php');
                    exit;
                }
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors['form'] = $ex->getCode() === '23000'
                    ? 'That email or mobile number is already registered.'
                    : 'Registration failed. Please try again.';
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
      <h1 class="h2 mb-1">Create your account</h1>
      <p class="text-secondary mb-4">Join Nomcha in a minute.</p>

      <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-danger"><?= e($errors['form']) ?></div>
      <?php endif; ?>

      <form method="post" action="register.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="row">
          <div class="col-md-4"><?php form_field('first_name', 'First name', 'text', $old['first_name'], $errors['first_name'] ?? null, 'required maxlength="100" autocomplete="given-name"'); ?></div>
          <div class="col-md-4"><?php form_field('middle_name', 'Middle name (optional)', 'text', $old['middle_name'], $errors['middle_name'] ?? null, 'maxlength="100" autocomplete="additional-name"'); ?></div>
          <div class="col-md-4"><?php form_field('last_name', 'Last name', 'text', $old['last_name'], $errors['last_name'] ?? null, 'required maxlength="100" autocomplete="family-name"'); ?></div>
        </div>

        <?php form_field('home_addr', 'Home address', 'text', $old['home_addr'], $errors['home_addr'] ?? null, 'required maxlength="255" autocomplete="street-address"'); ?>

        <div class="row register-contact-row">
          <div class="col-md-6"><?php form_field('email_addr', 'Email address', 'email', $old['email_addr'], $errors['email_addr'] ?? null, 'required maxlength="255" inputmode="email" autocapitalize="none" spellcheck="false" autocomplete="email"'); ?></div>
          <div class="col-md-6"><?php form_field('mobileno', 'Mobile number', 'tel', $old['mobileno'], $errors['mobileno'] ?? null, 'required maxlength="13" inputmode="tel" placeholder="09XXXXXXXXX" pattern="(09\d{9}|\+639\d{9})" title="09XXXXXXXXX or +639XXXXXXXXX" autocomplete="tel"'); ?></div>
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

        <button type="submit" class="btn btn-auth w-100 py-2 mt-2">Register</button>
      </form>

      <p class="text-center mt-4 mb-0">Already have an account? <a href="login.php" class="auth-link">Log in</a></p>
    </div>
  </div>
</main>

<?php require 'includes/footer.php'; ?>