<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
$token = trim((string)($_REQUEST['token'] ?? ''));
$validToken = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$subscription = null;
if ($validToken) {
    $stmt = $pdo->prepare('SELECT subscription_id,email_addr,unsubscribed_at FROM email_subscriptions WHERE unsubscribe_token_hash IN (?,?)');
    $stmt->execute([hash('sha256', $token), $token]);
    $subscription = $stmt->fetch() ?: null;
}
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null) || !$subscription) {
        http_response_code(400);
        $message = 'This unsubscribe link is invalid or has expired.';
    } elseif ($subscription['unsubscribed_at'] !== null) {
        $message = e($subscription['email_addr']) . ' is already unsubscribed from Nomcha email updates.';
    } else {
        $pdo->prepare('UPDATE email_subscriptions SET unsubscribed_at=NOW() WHERE subscription_id=? AND unsubscribed_at IS NULL')->execute([(int)$subscription['subscription_id']]);
        $message = e($subscription['email_addr']) . ' has been unsubscribed from Nomcha email updates.';
    }
} elseif (!$subscription) {
    http_response_code(404);
    $message = 'This unsubscribe link is invalid or has expired.';
} elseif ($subscription['unsubscribed_at'] !== null) {
    $message = e($subscription['email_addr']) . ' is already unsubscribed from Nomcha email updates.';
} else {
    $message = 'Unsubscribe ' . e($subscription['email_addr']) . ' from Nomcha email updates?';
}
$pageTitle = 'Unsubscribe';
require 'includes/header.php';
?>
<main class="container py-5" style="min-height:45vh"><section class="form-panel mx-auto" style="max-width:680px"><h1 class="h2">Nomcha email preferences</h1><p><?= $message ?></p><?php if ($subscription && $subscription['unsubscribed_at'] === null && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn btn-order" type="submit">Unsubscribe</button></form><?php else: ?><a class="btn btn-order" href="index.php">Back to Nomcha</a><?php endif; ?></section></main>
<?php require 'includes/footer.php'; ?>
