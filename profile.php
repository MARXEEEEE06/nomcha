<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require 'includes/otp_helpers.php';
require_customer($pdo); $userId=(int)$_SESSION['user_id']; $errors=[]; $success=$_SESSION['flash_success']??null; unset($_SESSION['flash_success']);

function anonymize_customer(PDO $pdo,int $userId,string $email): void {
    $pdo->beginTransaction();
    try {
        $lockedOrders=$pdo->prepare('SELECT order_id,status FROM customer_orders WHERE user_id = ? FOR UPDATE'); $lockedOrders->execute([$userId]);
        foreach ($lockedOrders->fetchAll() as $order) if (!in_array(strtolower((string)$order['status']),['cancelled','completed','delivered','rejected','failed'],true)) throw new DomainException('Your account cannot be deleted while an order is active.');
        $orders=$pdo->prepare('SELECT order_id FROM customer_orders WHERE user_id = ?'); $orders->execute([$userId]);
        $orderIds=array_map('intval',$orders->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare("UPDATE customer_orders SET user_id = NULL, customer_name = 'Deleted customer', phone = '', delivery_address = NULL, delivery_instructions = NULL WHERE user_id = ?")->execute([$userId]);
        if ($orderIds) { $marks=implode(',',array_fill(0,count($orderIds),'?')); $pdo->prepare("UPDATE customer_order_items SET options_json = JSON_REMOVE(options_json, '$.specialRequest') WHERE order_id IN ($marks) AND options_json IS NOT NULL")->execute($orderIds); }
        $pdo->prepare('DELETE FROM user_addresses WHERE user_id = ?')->execute([$userId]);
        $anonymousEmail='deleted-'.$userId.'@invalid.local';
        $pdo->prepare('UPDATE auth_security_logs SET email_addr = ? WHERE email_addr = ?')->execute([$anonymousEmail,$email]);
        $pdo->prepare('DELETE FROM auth_otp_challenges WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE user_credentials SET email_addr = ?, password = ?, is_active = 0 WHERE user_id = ?')->execute([$anonymousEmail,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$userId]);
        $pdo->prepare("UPDATE user_info SET first_name = 'Deleted', middle_name = NULL, last_name = 'Customer', home_addr = '', mobileno = CONCAT('deleted-', user_id) WHERE user_id = ?")->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_check($_POST['csrf']??null)) $errors[]='Session expired. Please try again.';
    elseif (($_POST['action']??'')==='save') {
        $first=trim((string)($_POST['first_name']??'')); $middle=trim((string)($_POST['middle_name']??'')); $last=trim((string)($_POST['last_name']??'')); $mobile=normalize_mobile(trim((string)($_POST['mobileno']??'')));
        if (!preg_match(NAME_RE,$first)||!preg_match(NAME_RE,$last)||($middle!==''&&!preg_match(NAME_RE,$middle))) $errors[]='Enter valid personal names.';
        if (!preg_match(MOBILE_RE,$mobile)) $errors[]='Enter a valid Philippine mobile number.';
        if (!$errors) { $dupe=$pdo->prepare('SELECT 1 FROM user_info WHERE mobileno = ? AND user_id <> ?'); $dupe->execute([$mobile,$userId]); if ($dupe->fetchColumn()) $errors[]='That mobile number is already in use.'; }
        if (!$errors) { $pdo->prepare('UPDATE user_info SET first_name=?,middle_name=?,last_name=?,mobileno=? WHERE user_id=?')->execute([$first,$middle?:null,$last,$mobile,$userId]); $_SESSION['first_name']=$first; $_SESSION['flash_success']='Your personal details were updated.'; header('Location: profile.php'); exit; }
    } elseif (($_POST['action']??'')==='begin_deletion') {
        $typed=strtolower(trim((string)($_POST['delete_email']??''))); $stmt=$pdo->prepare('SELECT email_addr FROM user_credentials WHERE user_id=?'); $stmt->execute([$userId]); $email=(string)$stmt->fetchColumn();
        if (!filter_var($typed,FILTER_VALIDATE_EMAIL)||!hash_equals(strtolower($email),$typed)) $errors[]='Enter the email address on your account.';
        else { try { $cid=create_otp_challenge($pdo,$email,'account_deletion',$userId); $_SESSION['account_deletion_challenge_id']=$cid; $_SESSION['account_deletion_email']=$email; $_SESSION['flash_success']='A six-digit verification code was sent to your email.'; header('Location: profile.php?verify_deletion=1'); exit; } catch (DomainException $e) { $errors[]=$e->getMessage(); } catch (Throwable $e) { $errors[]='We could not send the verification code. Please try again.'; } }
    } elseif (($_POST['action']??'')==='resend_deletion') {
        $email=(string)($_SESSION['account_deletion_email']??'');
        try { if ($email==='') throw new RuntimeException('Restart account deletion verification.'); $cid=create_otp_challenge($pdo,$email,'account_deletion',$userId); $_SESSION['account_deletion_challenge_id']=$cid; $_SESSION['flash_success']='A new six-digit verification code was sent.'; header('Location: profile.php?verify_deletion=1'); exit; }
        catch (DomainException $e) { $errors[]=$e->getMessage(); }
        catch (Throwable $e) { $errors[]='We could not send another code. Please try again.'; }
    } elseif (($_POST['action']??'')==='verify_deletion') {
        $email=(string)($_SESSION['account_deletion_email']??''); $cid=(int)($_SESSION['account_deletion_challenge_id']??0); $otp=trim((string)($_POST['otp']??'')); $challenge=null;
        try { if (preg_match('/^\d{6}$/',$otp)&&$cid&&$email!=='') $challenge=verify_otp_challenge($pdo,$cid,$email,'account_deletion',$otp); }
        catch (Throwable $e) { $errors[]='The verification code could not be checked. Please try again.'; }
        if (!$errors && (!$challenge||(int)$challenge['user_id']!==$userId)) $errors[]='That code is incorrect or expired. Request a new code and try again.';
        elseif ($challenge && (int)$challenge['user_id']===$userId) { $active=$pdo->prepare("SELECT COUNT(*) FROM customer_orders WHERE user_id=? AND LOWER(status) NOT IN ('cancelled','completed','delivered','rejected','failed')"); $active->execute([$userId]);
            if ((int)$active->fetchColumn()>0) { $errors[]='Your account cannot be deleted while an order is active.'; unset($_SESSION['account_deletion_challenge_id'],$_SESSION['account_deletion_email']); }
            else { try { anonymize_customer($pdo,$userId,$email); $_SESSION=[]; session_destroy(); header('Location: index.php'); exit; } catch (DomainException $e) { $errors[]=$e->getMessage(); unset($_SESSION['account_deletion_challenge_id'],$_SESSION['account_deletion_email']); } catch (Throwable $e) { $errors[]='Account deletion failed. Please try again.'; unset($_SESSION['account_deletion_challenge_id'],$_SESSION['account_deletion_email']); } }
        }
    }
}
$stmt=$pdo->prepare('SELECT first_name,middle_name,last_name,mobileno,(SELECT email_addr FROM user_credentials WHERE user_id=user_info.user_id) AS email_addr FROM user_info WHERE user_id=?'); $stmt->execute([$userId]); $profile=$stmt->fetch(); if (!$profile) { http_response_code(404); exit('Account not found.'); }
$pageTitle='My account'; require 'includes/header.php';
?>
<main class="container py-5" style="max-width:760px"><h1 class="mb-4">My account</h1><?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif;?><?php foreach($errors as $error):?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach;?>
<form method="post" class="card card-body mb-4"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><label class="form-label">Email</label><input class="form-control mb-3" value="<?=e($profile['email_addr'])?>" disabled><div class="row"><div class="col-md-4"><label class="form-label">First name</label><input class="form-control mb-3" name="first_name" required maxlength="100" value="<?=e($profile['first_name'])?>"></div><div class="col-md-4"><label class="form-label">Middle name</label><input class="form-control mb-3" name="middle_name" maxlength="100" value="<?=e($profile['middle_name']??'')?>"></div><div class="col-md-4"><label class="form-label">Last name</label><input class="form-control mb-3" name="last_name" required maxlength="100" value="<?=e($profile['last_name'])?>"></div></div><label class="form-label">Mobile number</label><input class="form-control mb-3" name="mobileno" required value="<?=e($profile['mobileno'])?>"><button class="btn btn-auth">Save details</button></form>
<section class="card card-body border-danger"><h2 class="h5 text-danger">Delete account</h2><p>Order history is retained with personal details anonymized. Deletion is blocked while an order is active.</p><button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">Request account deletion</button></section></main>
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h2 class="modal-title fs-5 text-danger">Verify account deletion</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p>Enter your account email. We’ll send a six-digit code before anything changes.</p><input class="form-control" type="email" name="delete_email" required autocomplete="email" placeholder="Email address"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="begin_deletion"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Send verification code</button></div></form></div></div></div>
<div class="modal fade" id="verifyDeletionModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h2 class="modal-title fs-5 text-danger">Confirm account deletion</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p>Enter the six-digit code sent to <?=e((string)($_SESSION['account_deletion_email']??''))?>. It expires in five minutes.</p><input class="form-control" name="otp" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="verify_deletion"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Verify and delete account</button></div></form><form method="post" class="px-3 pb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="resend_deletion"><button class="btn btn-link">Send a new code</button></form></div></div></div>
<?php if(!empty($_SESSION['account_deletion_challenge_id'])): ?><script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal('#verifyDeletionModal').show());</script><?php endif; ?>
<?php require 'includes/footer.php'; ?>
