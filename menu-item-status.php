<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require 'includes/make_mailer.php'; require_staff_or_admin($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) { http_response_code(400); exit('Invalid request.'); }
$id=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT); $status=(string)($_POST['new_status']??''); $word=$status==='activate'?'ACTIVATE':'DEACTIVATE';
if (!$id || !in_array($status,['activate','deactivate'],true) || strtoupper(trim((string)($_POST['confirm_text']??'')))!==$word) { http_response_code(400); exit('Confirmation did not match.'); }
$itemQuery=$pdo->prepare('SELECT name,description,item_kind,is_available,has_been_available FROM menu_items WHERE item_id=?');$itemQuery->execute([(int)$id]);$item=$itemQuery->fetch();
if(!$item){http_response_code(404);exit('Menu item not found.');}
$wasAvailable=(bool)$item['is_available'];
$isReturning=$status==='activate'&&!$wasAvailable&&(bool)$item['has_been_available'];
$stmt=$pdo->prepare('UPDATE menu_items SET is_available = ?, has_been_available = CASE WHEN ? = 1 THEN 1 ELSE has_been_available END WHERE item_id = ?'); $stmt->execute([$status==='activate'?1:0,$status==='activate'?1:0,(int)$id]);
if($status==='activate'&&!$wasAvailable&&$item['item_kind']==='item'){if($isReturning)notify_menu_item_reactivated((string)$item['name'],(string)($item['description']??''));else notify_nomcha_users('menu_event_notification','New Nomcha menu item available',(string)$item['name'].(!empty($item['description'])?' — '.$item['description']:''),'order.php');}
$_SESSION['flash_success']='Menu item status updated.'; $return='menu-management.php'; if (preg_match('/^menu-item-edit\.php\?id=(\d+)$/',(string)($_POST['return_to']??''),$match)&&(int)$match[1]===(int)$id) $return='menu-item-edit.php?id='.(int)$id; header('Location: '.$return); exit;
