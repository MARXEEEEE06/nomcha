<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require_once 'includes/make_mailer.php';

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $stmt = $pdo->prepare('SELECT subscription_id, unsubscribed_at FROM email_subscriptions WHERE email_addr=?');
            $stmt->execute([$email]);
            $existing = $stmt->fetch();
            if ($existing && $existing['unsubscribed_at'] === null) {
                $message = 'This email is already subscribed to Nomcha updates.';
            } else {
                if ($existing) {
                    $pdo->prepare('UPDATE email_subscriptions SET consented_at=NOW(), unsubscribed_at=NULL, unsubscribe_token_hash=? WHERE subscription_id=?')->execute([$hash, (int)$existing['subscription_id']]);
                } else {
                    $pdo->prepare('INSERT INTO email_subscriptions(email_addr, unsubscribe_token_hash) VALUES(?,?)')->execute([$email, $hash]);
                }
                try {
                    $url = nomcha_absolute_url('unsubscribe.php?token=' . rawurlencode($token));
                    send_subscription_welcome_email($email, $url);
                    $message = 'You are subscribed to Nomcha updates. Check your inbox for a welcome email.';
                } catch (Throwable $mailError) {
                    error_log('Nomcha subscription welcome email failed: ' . $mailError->getMessage());
                    $message = 'Your subscription is saved, but we could not send the welcome email. Please try again later or contact Nomcha.';
                }
            }
        }
    }
}
$pageTitle = 'Email updates';
require 'includes/header.php';
?>
<main class="container py-5" style="min-height:45vh"><section class="form-panel mx-auto" style="max-width:680px"><h1 class="h2">Nomcha email updates</h1><?php if ($error): ?><p class="alert alert-danger mb-0"><?= e($error) ?></p><?php else: ?><p class="mb-0"><?= e($message ?: 'Use the form in the site footer to subscribe to Nomcha promotions, vouchers, and menu news.') ?></p><?php endif; ?><a class="btn btn-order mt-4" href="index.php">Back to Nomcha</a></section></main>
<?php require 'includes/footer.php'; ?>
