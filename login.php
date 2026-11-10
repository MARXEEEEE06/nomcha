<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';

$localConfigPath = __DIR__ . '/includes/local_config.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
$allowTestAdminLogin = is_array($localConfig) && ($localConfig['allow_test_admin_login'] ?? false) === true;

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email_addr'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Session expired. Please try again.';
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

                    $testAdminPassword = $allowTestAdminLogin
                        && $email === 'admin@admin'
                        && hash_equals('admin', $password);

                    if ($row && (int)$row['is_active'] === 1 && (password_verify($password, $hash) || $testAdminPassword)) {
                        clear_login_failures($pdo, $email);
                        session_regenerate_id(true);
                        unset($_SESSION['csrf']);
                        $_SESSION['user_id']    = (int)$row['user_id'];
                        $_SESSION['first_name'] = $row['first_name'];
                        $_SESSION['role']       = $row['role'];
                        header('Location: ' . $redirectTo);
                        exit;
                    }

                    $locked = record_login_failure($pdo, $email);
                    $errors['form'] = $locked
                        ? 'Too many login attempts, please try again later.'
                        : 'Invalid email or password.';
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
      <p class="text-secondary mb-4">Log in to your Nomcha account.</p>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
      <?php endif; ?>
      <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-danger"><?= e($errors['form']) ?></div>
      <?php endif; ?>

      <form method="post" action="login.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">

        <?php form_field('email_addr', 'Email address', 'text', $email, $errors['email_addr'] ?? null, 'required maxlength="255" inputmode="email" autocapitalize="none" spellcheck="false" autocomplete="username"'); ?>
        <?php password_field('password', 'Password', $password, $errors['password'] ?? null, 'required maxlength="72" autocomplete="current-password"'); ?>

        <div class="text-end mb-3"><a href="forgot-password.php" class="auth-link small">Forgot password?</a></div>

        <button type="submit" class="btn btn-auth w-100 py-2">Log in</button>
      </form>

      <p class="text-center mt-4 mb-0">No account yet? <a href="register.php" class="auth-link">Register</a></p>
    </div>
  </div>
</main>

<?php require 'includes/footer.php'; ?>
