<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require_once 'includes/psgc_import.php';
require_once 'includes/psgc_helpers.php';
require_once 'includes/branch_helpers.php';
require_once 'includes/otp_helpers.php';

$errors=[];
$success='';
$adminCount=null;
$form=['first_name'=>'','middle_name'=>'','last_name'=>'','phone'=>'','email'=>'','recipient_name'=>'','street_address'=>'','region_code'=>'','municipality_code'=>'','barangay_code'=>'','zip_code'=>'','latitude'=>'','longitude'=>''];
$setupChallengeId=(int)($_SESSION['setup_challenge_id']??0);
$setupEmail=(string)($_SESSION['setup_email']??'');
$verifying=$setupChallengeId>0&&$setupEmail!=='';
$referenceCounts=['regions'=>0,'provinces'=>0,'localities'=>0,'barangays'=>0];
try {
    $adminCount=(int)$pdo->query("SELECT COUNT(*) FROM user_credentials WHERE role='admin'")->fetchColumn();
    foreach ($referenceCounts as $table=>$_) $referenceCounts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM psgc_'.$table)->fetchColumn();
} catch (PDOException $e) {
    $errors[]='Import database/schema.sql into nomcha_db first, then reload this setup page.';
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if ($adminCount===null) $errors[]='Setup is unavailable until the schema is imported.';
    elseif ($adminCount>0) $errors[]='Setup is already complete because an admin account exists.';
    elseif (!csrf_check($_POST['csrf']??null)) $errors[]='Your session expired. Refresh this page and try again.';
    elseif (($_POST['action']??'')==='verify_admin') {
        $code=trim((string)($_POST['otp']??''));
        if (!preg_match('/^\d{6}$/',$code)) $errors[]='Enter the six-digit code from your email.';
        else {
            $lockAcquired=false;
            try {
                $lock=$pdo->query("SELECT GET_LOCK('nomcha_first_admin_setup',5)");
                $lockAcquired=(int)$lock->fetchColumn()===1;
                if (!$lockAcquired) throw new RuntimeException('Another setup request is running. Wait a moment and retry.');
                $pdo->beginTransaction();
                if ((int)$pdo->query("SELECT COUNT(*) FROM user_credentials WHERE role='admin'")->fetchColumn()>0) {
                    $pdo->rollBack(); $errors[]='Setup is already complete because an admin account exists.';
                } else {
                    $challenge=verify_otp_challenge($pdo,$setupChallengeId,$setupEmail,'registration',$code);
                    if (!$challenge) { $pdo->commit(); $errors[]='That code is incorrect or expired. Request a new code and try again.'; }
                    else {
                        $data=json_decode((string)$challenge['registration_data'],true,512,JSON_THROW_ON_ERROR);
                        if (!is_array($data)) throw new RuntimeException('Setup data is unavailable.');
                        $pdo->prepare('INSERT INTO user_info(first_name,middle_name,last_name,phone) VALUES(?,?,?,?)')->execute([$data['first_name'],$data['middle_name']?:null,$data['last_name'],$data['phone']]);
                        $userId=(int)$pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO user_credentials(user_id,email,password_hash,role,is_active,first_login_activation) VALUES(?,?,?,'admin',1,0)")->execute([$userId,$setupEmail,$data['password_hash']]);
                        $pdo->prepare('INSERT INTO user_addresses(user_id,recipient_name,recipient_phone,street_address,barangay_code,zip_code,latitude,longitude,is_default) VALUES(?,?,?,?,?,?,?,?,1)')->execute([$userId,$data['recipient_name'],$data['phone'],$data['street_address'],$data['barangay_code'],$data['zip_code'],$data['latitude'],$data['longitude']]);
                        $pdo->prepare('UPDATE auth_otp_challenges SET registration_data=NULL WHERE challenge_id=?')->execute([$setupChallengeId]);
                        $pdo->commit(); $adminCount=1; $verifying=false;
                        unset($_SESSION['setup_challenge_id'],$_SESSION['setup_email'],$_SESSION['setup_sent_at']);
                        $success='Initial admin created. Sign in with that account; this setup page is now disabled.';
                    }
                }
            } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); error_log('Nomcha initial admin OTP setup failed: '.$e->getMessage()); $errors[]='The code could not be verified. Check email delivery and try again.'; }
            finally { if($lockAcquired){try{$pdo->query("SELECT RELEASE_LOCK('nomcha_first_admin_setup')");}catch(Throwable $e){}} }
        }
    }
    elseif (($_POST['action']??'')==='import_psgc') {
        try {
            $count=import_psgc_reference_data($pdo,__DIR__.'/database/psgc_reference.json');
            $success='Imported or refreshed '.$count.' PSGC reference rows using prepared statements.';
            foreach ($referenceCounts as $table=>$_) $referenceCounts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM psgc_'.$table)->fetchColumn();
        } catch (Throwable $e) { error_log('Nomcha PSGC import failed: '.$e->getMessage()); $errors[]='PSGC reference data could not be imported. Check the packaged JSON and database schema.'; }
    } elseif (($_POST['action']??'')==='create_admin' && min($referenceCounts)===0) $errors[]='Import PSGC reference data before creating the first admin.';
    elseif (($_POST['action']??'')==='create_admin') {
        foreach ($form as $key=>$_) $form[$key]=trim((string)($_POST[$key]??''));
        $form['email']=strtolower($form['email']);
        $form['phone']=normalize_mobile($form['phone']);
        $first=$form['first_name'];
        $middle=$form['middle_name'];
        $last=$form['last_name'];
        $email=$form['email'];
        $password=(string)($_POST['password']??'');
        $confirm=(string)($_POST['confirm_password']??'');
        if (!preg_match(NAME_RE,$first)||!preg_match(NAME_RE,$last)||($middle!==''&&!preg_match(NAME_RE,$middle))) $errors[]='Enter valid first, middle, and last names. Leave middle name blank if it does not apply.';
        if (!preg_match(MOBILE_RE,$form['phone'])) $errors[]='Enter a valid Philippine mobile number.';
        if (mb_strlen($form['recipient_name'])<2||mb_strlen($form['recipient_name'])>120) $errors[]='Enter a valid address recipient name.';
        if (mb_strlen($form['street_address'])<2||mb_strlen($form['street_address'])>255) $errors[]='Enter a valid street address.';
        if (!preg_match('/^\d{4}$/',$form['zip_code'])) $errors[]='Enter a 4-digit ZIP code.';
        $place=psgc_resolve($pdo,$form['region_code'],$form['municipality_code'],$form['barangay_code']);
        if (!$place) $errors[]='Select a valid region, city / municipality, and barangay.';
        $latitude=filter_var($form['latitude'],FILTER_VALIDATE_FLOAT);
        $longitude=filter_var($form['longitude'],FILTER_VALIDATE_FLOAT);
        if ($latitude===false||$longitude===false||!branch_is_within_philippines((float)$latitude,(float)$longitude)) $errors[]='Place a map pin inside the Philippines.';
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>255) $errors[]='Enter a valid email address.';
        if (!preg_match(PASS_RE,$password)) $errors[]='Use at least eight characters with uppercase, lowercase, number, and symbol.';
        if ($password!==$confirm) $errors[]='The password confirmation does not match.';
        if (!$errors) {
            try {
                $data=['first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last,'phone'=>$form['phone'],'recipient_name'=>$form['recipient_name'],'street_address'=>$form['street_address'],'barangay_code'=>$place['barangay_code'],'zip_code'=>$form['zip_code'],'latitude'=>$latitude,'longitude'=>$longitude,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)];
                $id=create_otp_challenge($pdo,$email,'registration',null,$data);
                $_SESSION['setup_challenge_id']=$id; $_SESSION['setup_email']=$email; $_SESSION['setup_sent_at']=time();
                $setupChallengeId=$id; $setupEmail=$email; $verifying=true;
                $success='A verification code was sent to the admin email. Enter it below to finish setup.';
            } catch (Throwable $e) {
                error_log('Nomcha initial admin setup failed: '.$e->getMessage());
                $errors[]='Could not send the verification code. Check Make email settings and try again.';
            }
        }
    } else $errors[]='Choose a valid setup action.';
}
$referencesReady=min($referenceCounts)>0;
$psgc=$referencesReady?psgc_form_state($pdo,$form):['regions'=>[],'localities'=>[],'barangays'=>[]];
$pageTitle='System setup';
$pageStyles='<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
$pageScripts='<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/js/psgc-select.js?v='.filemtime(__DIR__.'/assets/js/psgc-select.js').'"></script><script src="assets/js/address-map.js?v='.filemtime(__DIR__.'/assets/js/address-map.js').'"></script><script src="assets/js/auth.js?v='.filemtime(__DIR__.'/assets/js/auth.js').'"></script>';
require 'includes/header.php';
?>
<main class="container py-5" style="max-width:880px">
  <h1 class="mb-2">Nomcha system setup</h1>
  <p class="text-secondary">Import the PSGC address reference and create the first administrator after importing the schema.</p>
  <?php foreach ($errors as $message): ?><div class="text-danger mb-3" role="alert"><?=e($message)?></div><?php endforeach; ?>
  <?php if ($success!==''): ?><div class="text-success mb-3" role="status"><?=e($success)?></div><?php endif; ?>
  <?php if ($adminCount===null): ?>
    <section class="card card-body"><h2 class="h5">Schema required</h2><p class="mb-0">Import <code>database/schema.sql</code> into <code>nomcha_db</code>, then reload this page.</p></section>
  <?php elseif ($adminCount>0): ?>
    <section class="card card-body"><h2 class="h5">Setup complete</h2><p class="mb-0">An administrator already exists. Initial setup is disabled.</p></section>
  <?php else: ?>
    <section class="card card-body mb-4">
      <h2 class="h4">PSGC reference data</h2>
      <p>Rows currently in the database: <?=e((string)$referenceCounts['regions'])?> regions, <?=e((string)$referenceCounts['provinces'])?> provinces, <?=e((string)$referenceCounts['localities'])?> cities / municipalities, and <?=e((string)$referenceCounts['barangays'])?> barangays.</p>
      <p class="small text-secondary">This imports reference names and codes from the packaged JSON with prepared statements. It creates no accounts, menu rows, promotions, or orders.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="import_psgc"><button class="btn btn-outline-primary">Import / refresh PSGC reference</button></form>
    </section>
    <?php if (!$referencesReady): ?>
      <section class="card card-body"><h2 class="h4">Create initial administrator</h2><p class="mb-0">Import PSGC reference data first. The first admin form appears after that import completes.</p></section>
    <?php else: ?><section class="card card-body">
      <h2 class="h4">Create initial administrator</h2>
      <p class="small text-secondary">Available only while no admin account exists.</p>
      <?php if ($verifying): ?>
      <p class="small text-secondary">Enter the six-digit code sent to <?=e($setupEmail)?>.</p>
      <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="verify_admin"><div class="col-12"><label class="form-label" for="otp">Email verification code</label><input class="form-control" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div><div class="col-12"><button class="btn btn-auth">Verify email and create admin</button></div></form>
      <?php else: ?>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_admin">
        <div class="col-md-4"><label class="form-label" for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" maxlength="100" required value="<?=e($form['first_name'])?>"></div>
        <div class="col-md-4"><label class="form-label" for="middle_name">Middle name (optional)</label><input class="form-control" id="middle_name" name="middle_name" maxlength="100" value="<?=e($form['middle_name'])?>"></div>
        <div class="col-md-4"><label class="form-label" for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" maxlength="100" required value="<?=e($form['last_name'])?>"></div>
        <div class="col-md-6"><label class="form-label" for="email">Admin email</label><input class="form-control" id="email" name="email" type="email" maxlength="255" autocomplete="email" required value="<?=e($form['email'])?>"></div>
        <div class="col-md-6"><label class="form-label" for="phone">Mobile number</label><input class="form-control" id="phone" name="phone" type="text" inputmode="numeric" data-digits-only data-ph-mobile maxlength="11" pattern="09[0-9]{9}" autocomplete="tel" required value="<?=e($form['phone'])?>"></div>
        <div class="col-12"><h3 class="h5 mb-0">Default address</h3></div>
        <div class="col-md-6"><label class="form-label" for="recipient_name">Recipient name</label><input class="form-control" id="recipient_name" name="recipient_name" maxlength="120" autocomplete="name" required value="<?=e($form['recipient_name'])?>"></div>
        <div class="col-md-6"><label class="form-label" for="street_address">Unit / house number and street</label><input class="form-control" id="street_address" name="street_address" minlength="2" maxlength="255" autocomplete="address-line1" required value="<?=e($form['street_address'])?>"></div>
        <div class="col-md-4"><?php psgc_select_field('region',$psgc['regions'],$form['region_code']); ?></div>
        <div class="col-md-4"><?php psgc_select_field('municipality',$psgc['localities'],$form['municipality_code']); ?></div>
        <div class="col-md-4"><?php psgc_select_field('barangay',$psgc['barangays'],$form['barangay_code']); ?></div>
        <div class="col-md-4"><label class="form-label" for="zip_code">ZIP code</label><input class="form-control" id="zip_code" name="zip_code" maxlength="4" inputmode="numeric" pattern="[0-9]{4}" autocomplete="postal-code" required value="<?=e($form['zip_code'])?>"></div>
        <div class="col-12"><label class="form-label" for="address-map">Map pin location</label><div id="address-map" class="branch-map" style="height:320px;min-height:280px;position:relative"></div><input type="hidden" id="latitude" name="latitude" value="<?=e($form['latitude'])?>"><input type="hidden" id="longitude" name="longitude" value="<?=e($form['longitude'])?>"><div class="form-text">Click the map to set the address location.</div></div>
        <div class="col-md-6"><?php password_field('password','Password','',null,'required minlength="8" maxlength="72" autocomplete="new-password"'); ?><ul id="pw-rules" class="pw-rules mb-3"><li data-rule="len"><i class="bi bi-circle"></i> At least 8 characters</li><li data-rule="lower"><i class="bi bi-circle"></i> Lowercase letter</li><li data-rule="upper"><i class="bi bi-circle"></i> Uppercase letter</li><li data-rule="number"><i class="bi bi-circle"></i> Number</li><li data-rule="special"><i class="bi bi-circle"></i> Special character</li></ul></div>
        <div class="col-md-6"><?php password_field('confirm_password','Confirm password','',null,'required maxlength="72" autocomplete="new-password"'); ?></div>
        <div class="col-12"><button class="btn btn-auth">Create first admin</button></div>
      </form>
      <?php endif; ?>
    </section><?php endif; ?>
  <?php endif; ?>
</main>
<?php require 'includes/footer.php'; ?>
