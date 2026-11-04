<?php
require 'includes/auth_helpers.php';

$errors = [];
$email  = '';
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email_addr'] ?? ''));
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors['email_addr'] = 'Enter a valid email address.';
    } else {
        // TODO: create a reset token and send the email. Same message either way
        // so the page can't be used to check which emails are registered.
        $sent = true;
    }
}

$pageTitle = 'Forgot Password';
require 'includes/header.php';
?>

<main class="container auth-wrap py-5">
  <div class="card auth-card col-md-8 col-lg-5 mx-auto">
    <div class="card-body p-4 p-md-5">
      <h1 class="h2 mb-1">Forgot password</h1>
      <p class="text-secondary mb-4">Enter your email and we'll send a reset link.</p>

      <?php if ($sent): ?>
        <div class="alert alert-success">If an account exists for that email, a reset link will be sent.</div>
      <?php endif; ?>
      <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-danger"><?= e($errors['form']) ?></div>
      <?php endif; ?>

      <form method="post" action="forgot-password.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php form_field('email_addr', 'Email address', 'email', $email, $errors['email_addr'] ?? null, 'required maxlength="255" autocomplete="email"'); ?>
        <button type="submit" class="btn btn-auth w-100 py-2">Send reset link</button>
      </form>

      <p class="text-center mt-4 mb-0"><a href="login.php" class="auth-link">Back to login</a></p>
    </div>
  </div>
</main>

<?php require 'includes/footer.php'; ?>