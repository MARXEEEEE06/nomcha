<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require_staff_or_admin($pdo);
$id=(int)($_GET['id']??0);$stmt=$pdo->prepare('SELECT resume_path FROM careers_applications WHERE application_id=?');$stmt->execute([$id]);$name=$stmt->fetchColumn();
if(!$name){http_response_code(404);exit('Resume not found.');}
$path=__DIR__.'/uploads/resumes/'.basename((string)$name);if(!is_file($path)){http_response_code(404);exit('Resume not found.');}
$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$mime=['pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][$ext]??'application/octet-stream';
header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="resume-'.$id.'.'.$ext.'"');header('X-Content-Type-Options: nosniff');readfile($path);
