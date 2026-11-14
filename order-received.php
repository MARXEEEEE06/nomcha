<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require_customer($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) { http_response_code(400); exit('Invalid request.'); }
$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId) { http_response_code(400); exit('Invalid order.'); }
$lookup = $pdo->prepare("SELECT o.delivery_distance_km, TIMESTAMPDIFF(SECOND, COALESCE((SELECT MAX(a.created_at) FROM staff_order_actions a WHERE a.order_id=o.order_id AND a.to_status='out_for_delivery'), o.created_at), NOW()) AS elapsed_seconds FROM customer_orders o WHERE o.order_id=? AND o.user_id=? AND o.fulfillment='delivery' AND o.status='out_for_delivery'");
$lookup->execute([(int)$orderId, (int)$_SESSION['user_id']]);
$delivery = $lookup->fetch();
$updated = false;
$message = 'This order is no longer awaiting delivery confirmation.';
if ($delivery) {
    $distanceKm = (float)($delivery['delivery_distance_km'] ?? 5);
    $etaSeconds = max(60, (int)ceil((($distanceKm > 0 ? $distanceKm : 5) / 25 * 3600) / 60) * 60);
    if ((int)$delivery['elapsed_seconds'] >= $etaSeconds) {
        $stmt = $pdo->prepare("UPDATE customer_orders SET status='completed' WHERE order_id=? AND user_id=? AND fulfillment='delivery' AND status='out_for_delivery'");
        $stmt->execute([(int)$orderId, (int)$_SESSION['user_id']]);
        $updated = $stmt->rowCount() > 0;
    } else $message = 'Delivery is still in progress. You can confirm receipt when the progress is complete.';
}
$_SESSION[$updated ? 'flash_success' : 'flash_error'] = $updated ? 'Order marked as received.' : $message;
header('Location: order.php?view=orders#my-orders');
exit;
