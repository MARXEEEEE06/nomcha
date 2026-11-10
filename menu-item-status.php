<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require_admin($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) { http_response_code(400); exit('Invalid request.'); }
$id=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT); $status=(string)($_POST['new_status']??''); $word=$status==='activate'?'ACTIVATE':'DEACTIVATE';
if (!$id || !in_array($status,['activate','deactivate'],true) || strtoupper(trim((string)($_POST['confirm_text']??'')))!==$word) { http_response_code(400); exit('Confirmation did not match.'); }
$stmt=$pdo->prepare('UPDATE menu_items SET is_available = ? WHERE item_id = ?'); $stmt->execute([$status==='activate'?1:0,(int)$id]);
$_SESSION['flash_success']='Menu item status updated.'; header('Location: menu-management.php'); exit;
