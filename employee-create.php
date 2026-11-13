<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require 'includes/media_helpers.php'; require 'includes/make_mailer.php'; require 'includes/toasts.php'; require 'includes/psgc_helpers.php'; require_admin($pdo);
$errors=[];
$form=['first_name'=>'','middle_name'=>'','last_name'=>'','email_addr'=>'','mobileno'=>'','street_address'=>'','region_code'=>'','municipality_code'=>'','barangay_code'=>'','zip_code'=>'','role'=>'staff'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach ($form as $key=>$_) $form[$key]=trim((string)($_POST[$key]??''));
    if (!csrf_check($_POST['csrf']??null)) $errors[]='Session expired. Refresh and try again.';
    if (!verify_account_password($pdo,(int)$_SESSION['user_id'],(string)($_POST['auth_password']??''))) $errors[]='Enter your current admin password to confirm employee creation.';
    if (!preg_match(NAME_RE,$form['first_name'])||!preg_match(NAME_RE,$form['last_name'])||($form['middle_name']!==''&&!preg_match(NAME_RE,$form['middle_name']))) $errors[]='Enter valid employee names.';
    if (!filter_var($form['email_addr'],FILTER_VALIDATE_EMAIL)||strlen($form['email_addr'])>255) $errors[]='Enter a valid employee email address.';
    $form['mobileno']=normalize_mobile($form['mobileno']); if (!preg_match(MOBILE_RE,$form['mobileno'])) $errors[]='Enter a valid Philippine mobile number.';
    if (!in_array($form['role'],['staff','admin'],true)) $errors[]='Choose a valid employee role.';
    foreach (['street_address'=>255,'zip_code'=>10] as $key=>$max) if ($form[$key]===''||mb_strlen($form[$key])>$max) $errors[]='Enter a valid complete employee address.';
    if ($form['zip_code']!==''&&!preg_match('/^[A-Za-z0-9 -]{3,10}$/',$form['zip_code'])) $errors[]='Enter a valid ZIP or postal code.';
    $place=psgc_resolve($pdo,$form['region_code'],$form['municipality_code'],$form['barangay_code']); if (!$place) $errors[]='Select a valid region, city / municipality and barangay.';
    if (!$errors) { $dup=$pdo->prepare('SELECT 1 FROM user_credentials WHERE email_addr=? UNION SELECT 1 FROM user_info WHERE mobileno=?'); $dup->execute([$form['email_addr'],$form['mobileno']]); if ($dup->fetchColumn()) $errors[]='That email or contact number is already in use.'; }
    try { if (!$errors) $photo=save_square_employee_photo($_FILES['photo']??[]); } catch (Throwable $e) { $errors[]=$e->getMessage(); }
    if (!$errors) {
        $temporaryPassword='$Nomcha'.random_int(100000,999999);
        $fullName=trim(implode(' ',array_filter([$form['first_name'],$form['middle_name'],$form['last_name']])));
        $homeAddress=mb_substr(implode(', ',array_filter([$form['street_address'],$place['barangay'],$place['municipality'],$place['province'],$form['zip_code']],'strlen')),0,255);
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO user_info (first_name,middle_name,last_name,home_addr,mobileno,photo_path) VALUES (?,?,?,?,?,?)')->execute([$form['first_name'],$form['middle_name']?:null,$form['last_name'],$homeAddress,$form['mobileno'],$photo]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_credentials (user_id,email_addr,password,role,is_active) VALUES (?,?,?,?,1)')->execute([$id,$form['email_addr'],password_hash($temporaryPassword,PASSWORD_DEFAULT),$form['role']]);
            $pdo->prepare('INSERT INTO user_addresses (user_id,recipient_name,mobile_no,street_address,region_code,province,province_code,municipality,municipality_code,barangay,barangay_code,zip_code,latitude,longitude,is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,1)')->execute([$id,$fullName,$form['mobileno'],$form['street_address'],$place['region_code'],$place['province'],$place['province_code'],$place['municipality'],$place['municipality_code'],$place['barangay'],$place['barangay_code'],$form['zip_code']]);
            send_employee_welcome_email($form['email_addr'],$fullName,$temporaryPassword);
            $pdo->commit(); $_SESSION['flash_success']='Employee account created. A temporary password was sent through email.'; header('Location: employees.php'); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors[]='Employee could not be created or the welcome email could not be sent. Check the email and Make setup, then try again.'; }
    }
}
$pageTitle='Add employee'; require 'includes/header.php'; render_toasts(array_map(static fn($message)=>['kind'=>'danger','text'=>$message],$errors));
$psgc=psgc_form_state($pdo,$form);
?>
<main class="container py-5" style="max-width:850px"><a href="employees.php" class="btn btn-link auth-link px-0">← Employees</a><h1 class="mb-4">Add employee</h1>
<form id="employeeCreateForm" method="post" enctype="multipart/form-data" class="card card-body"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="row g-3">
<div class="col-md-4"><label class="form-label">First name</label><input class="form-control" name="first_name" required maxlength="100" value="<?=e($form['first_name'])?>"></div><div class="col-md-4"><label class="form-label">Middle name</label><input class="form-control" name="middle_name" maxlength="100" value="<?=e($form['middle_name'])?>"></div><div class="col-md-4"><label class="form-label">Last name</label><input class="form-control" name="last_name" required maxlength="100" value="<?=e($form['last_name'])?>"></div>
<div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email_addr" required maxlength="255" value="<?=e($form['email_addr'])?>"></div><div class="col-md-6"><label class="form-label">Contact number</label><input class="form-control" name="mobileno" required value="<?=e($form['mobileno'])?>"></div>
<div class="col-12"><h2 class="h5 mt-2 mb-0">Employee address</h2></div><div class="col-12"><label class="form-label">Street address</label><input class="form-control" name="street_address" required maxlength="255" value="<?=e($form['street_address'])?>"></div><?php psgc_select_field('region',$psgc['regions'],$form['region_code'],null,'col-md-6'); psgc_select_field('municipality',$psgc['localities'],$form['municipality_code'],null,'col-md-6'); psgc_select_field('barangay',$psgc['barangays'],$form['barangay_code'],null,'col-md-6'); ?><div class="col-md-6"><label class="form-label">ZIP / postal code</label><input class="form-control" name="zip_code" required maxlength="10" value="<?=e($form['zip_code'])?>"></div>
<div class="col-md-6"><label class="form-label">Role</label><select class="form-select" name="role"><option value="staff" <?=$form['role']==='staff'?'selected':''?>>Staff</option><option value="admin" <?=$form['role']==='admin'?'selected':''?>>Admin</option></select></div><div class="col-md-6"><label class="form-label">Square employee photo (1:1)</label><input class="form-control" type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-image-input="#new-employee-photo-preview" required><img id="new-employee-photo-preview" class="staff-photo mt-2" style="width:92px;height:92px" alt="Photo preview" hidden></div></div><p class="form-text mt-3">A temporary password will be generated and emailed to the employee. They can use “Forgot password” to choose their own password.</p><button type="button" class="btn btn-auth mt-2" data-bs-toggle="modal" data-bs-target="#employeeCreateConfirm">Create employee</button></form></main>
<div class="modal fade" id="employeeCreateConfirm" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Confirm employee creation</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p>A temporary password will be sent to this employee’s email.</p><label class="form-label" for="create-auth-password">Your current admin password</label><input class="form-control" id="create-auth-password" type="password" name="auth_password" form="employeeCreateForm" required autocomplete="current-password"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-auth" type="submit" form="employeeCreateForm">Confirm and create</button></div></div></div></div>
<script src="assets/js/psgc-select.js"></script><script src="assets/js/image-preview.js"></script><?php require 'includes/footer.php'; ?>
