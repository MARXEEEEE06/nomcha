<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';

if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }

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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors['email_addr'] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT c.user_id, c.password, i.first_name
                     FROM user_credentials c
                     JOIN user_info i ON i.user_id = c.user_id
                     WHERE c.email_addr = ? LIMIT 1'
                );
                $stmt->execute([$email]);
                $row  = $stmt->fetch();
                $hash = $row['password'] ?? password_hash('dummy-value', PASSWORD_DEFAULT);

                if ($row && password_verify($password, $hash)) {
                    session_regenerate_id(true);
                    unset($_SESSION['csrf']);
                    $_SESSION['user_id']    = (int)$row['user_id'];
                    $_SESSION['first_name'] = $row['first_name'];
                    header('Location: index.php');
                    exit;
                }
                $errors['form'] = 'Invalid email or password.';
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

        <?php form_field('email_addr', 'Email address', 'email', $email, $errors['email_addr'] ?? null, 'required maxlength="255" autocomplete="email"'); ?>
        <?php password_field('password', 'Password', $password, $errors['password'] ?? null, 'required maxlength="72" autocomplete="current-password"'); ?>

        <div class="text-end mb-3"><a href="forgot-password.php" class="auth-link small">Forgot password?</a></div>

        <button type="submit" class="btn btn-auth w-100 py-2">Log in</button>
      </form>

      <p class="text-center mt-4 mb-0">No account yet? <a href="register.php" class="auth-link">Register</a></p>
    </div>
  </div>
</main>

<?php require 'includes/footer.php'; ?>