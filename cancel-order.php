<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require_customer($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) {
    http_response_code(400);
    exit('Invalid request.');
}
$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId) { http_response_code(400); exit('Invalid order.'); }

// Customer cancellations are allowed only while an order is still pending.
$stmt = $pdo->prepare("UPDATE customer_orders SET status = 'cancelled' WHERE order_id = ? AND user_id = ? AND LOWER(status) = 'pending'");
$stmt->execute([(int)$orderId, (int)$_SESSION['user_id']]);
$_SESSION['flash_success'] = $stmt->rowCount() ? 'Your pending order was cancelled.' : 'That order cannot be cancelled. It may no longer be pending.';
header('Location: order.php?view=orders');
exit;
