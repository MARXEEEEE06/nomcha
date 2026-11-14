<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require_role($pdo, ['staff']);
if ($_SERVER['REQUEST_METHOD']!=='POST'||!csrf_check($_POST['csrf']??null)) { http_response_code(400); exit('Invalid request.'); }
$orderId=filter_var($_POST['order_id']??null,FILTER_VALIDATE_INT); $action=(string)($_POST['action']??''); $actor=(int)$_SESSION['user_id'];
if (!$orderId||!in_array($action,['status','cancel'],true)) { http_response_code(400); exit('Invalid order action.'); }
$reasonLabels=['customer_request','item_unavailable','payment_issue','duplicate_order','delivery_issue','other'];
try {
    $pdo->beginTransaction();
    $get=$pdo->prepare('SELECT order_id,status,fulfillment FROM customer_orders WHERE order_id=? FOR UPDATE'); $get->execute([(int)$orderId]); $order=$get->fetch();
    if (!$order) throw new DomainException('Order not found.');
    $current=strtolower((string)$order['status']); $terminal=['cancelled','completed','delivered','rejected','failed'];
    if ($action==='cancel') {
        $reason=(string)($_POST['reason']??'');
        if (in_array($current,$terminal,true)) throw new DomainException('This order is already closed and cannot be cancelled.');
        if (!in_array($reason,$reasonLabels,true)) throw new DomainException('Choose a valid cancellation reason.');
        $pdo->prepare("UPDATE customer_orders SET status='cancelled' WHERE order_id=?")->execute([(int)$orderId]);
        $pdo->prepare("INSERT INTO staff_order_actions (order_id,user_id,event_type,from_status,to_status,cancellation_reason) VALUES (?,?,'cancelled',?,'cancelled',?)")
            ->execute([(int)$orderId,$actor,$current,$reason]);
        $message='Order cancelled and reason recorded.';
    } else {
        $next=(string)($_POST['new_status']??'');
        $allowed=match($current) {
            'pending'=>['confirmed'],
            'confirmed'=>['preparing'],
            'preparing'=>['ready'],
            'ready'=>[(string)$order['fulfillment']==='delivery'?'out_for_delivery':'completed'],
            'out_for_delivery'=>['completed'],
            default=>[],
        };
        if (!in_array($next,$allowed,true)) throw new DomainException('That status change is not available for this order. Refresh the list and try again.');
        $pdo->prepare('UPDATE customer_orders SET status=? WHERE order_id=?')->execute([$next,(int)$orderId]);
        $pdo->prepare("INSERT INTO staff_order_actions (order_id,user_id,event_type,from_status,to_status,cancellation_reason) VALUES (?,?,'status_changed',?,?,NULL)")
            ->execute([(int)$orderId,$actor,$current,$next]);
        $message='Order status updated.';
    }
    $pdo->commit(); $_SESSION['flash_success']=$message;
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); $_SESSION['flash_error']=$e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); $_SESSION['flash_error']='The order could not be updated. Refresh and try again.';
}
header('Location: staff-orders.php'); exit;
