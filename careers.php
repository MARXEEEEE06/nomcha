<?php
require 'includes/auth_helpers.php'; require 'includes/db.php';
$errors=[]; $saved=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf']??null)) $errors[]='Your session expired. Please try again.';
  $d=[]; foreach (['purpose','first_name','middle_name','last_name','position_applied','mobile_number','address','email'] as $k) $d[$k]=trim((string)($_POST[$k]??''));
  if (!$d['purpose'] || !$d['first_name'] || !$d['last_name'] || !$d['position_applied'] || !$d['mobile_number'] || !$d['address'] || !filter_var($d['email'],FILTER_VALIDATE_EMAIL)) $errors[]='Please complete all required fields with a valid email.';
  if (!$errors && email_owned_by_another_account($pdo,$d['email'])) $errors[]='That email address belongs to another account. Use your own email address.';
  $resume=null;
  if (!empty($_FILES['resume']['name'])) {
    $f=$_FILES['resume']; $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    if ($f['error']!==UPLOAD_ERR_OK || !in_array($ext,['pdf','doc','docx'],true) || $f['size']>5*1024*1024) $errors[]='Resume must be a PDF or Word document up to 5 MB.';
    else { $dir=__DIR__.'/uploads/resumes'; if (!is_dir($dir)) mkdir($dir,0750,true); $resume=bin2hex(random_bytes(16)).'.'.$ext; if (!move_uploaded_file($f['tmp_name'],$dir.'/'.$resume)) { $errors[]='Resume upload failed.'; $resume=null; } }
  }
  if (!$errors) { $pdo->prepare('INSERT INTO careers_applications(purpose,first_name,middle_name,last_name,position_applied,mobile_number,address,email,resume_path) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$d['purpose'],$d['first_name'],$d['middle_name']?:null,$d['last_name'],$d['position_applied'],$d['mobile_number'],$d['address'],$d['email'],$resume]); $id=(int)$pdo->lastInsertId(); $pdo->prepare("INSERT INTO team_inbox_events(event_type,title,message,audience,entity_type,entity_id) VALUES('career_application','New career application',?, 'staff','career_application',?)")->execute([$d['first_name'].' '.$d['last_name'].' applied for '.$d['position_applied'].'.',$id]); $saved=true; }
}
$pageTitle='Careers'; require 'includes/header.php';
?>
<main class="container py-5"><div class="form-panel mx-auto" style="max-width:760px"><div class="text-center mb-4"><span class="management-card-icon mx-auto"><i class="bi bi-people"></i></span><h1 class="mt-3">Join the Nomcha team</h1><p class="text-secondary">Tell us a little about yourself and the role you’re interested in.</p></div>
<?php if($saved): ?><div class="alert alert-success">Thank you. Your application has been received by our team.</div><?php endif; ?><?php foreach($errors as $er): ?><div class="alert alert-danger"><?=e($er)?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><div class="mb-3"><label class="form-label">Purpose *</label><select name="purpose" class="form-select" required><option value="">Select purpose</option><option>Employment</option><option>Internship</option><option>Part-time</option></select></div>
<div class="row"><div class="col-md-4 mb-3"><label class="form-label">First name *</label><input class="form-control" name="first_name" required></div><div class="col-md-4 mb-3"><label class="form-label">Middle name</label><input class="form-control" name="middle_name"></div><div class="col-md-4 mb-3"><label class="form-label">Last name *</label><input class="form-control" name="last_name" required></div></div>
<div class="mb-3"><label class="form-label">Position applied for *</label><input class="form-control" name="position_applied" placeholder="e.g. Service Crew" required></div><div class="mb-3"><label class="form-label">Mobile number *</label><input class="form-control" name="mobile_number" placeholder="09XX XXX XXXX" required></div><div class="mb-3"><label class="form-label">Complete address *</label><textarea class="form-control" name="address" rows="2" required></textarea></div><div class="mb-3"><label class="form-label">Email address *</label><input type="email" class="form-control" name="email" required></div><div class="mb-4"><label class="form-label">Resume/CV (PDF or Word, up to 5 MB)</label><input type="file" class="form-control" name="resume" accept=".pdf,.doc,.docx"></div><button class="btn btn-order w-100" type="submit">Submit application</button></form></div></main>
<?php require 'includes/footer.php'; ?>
