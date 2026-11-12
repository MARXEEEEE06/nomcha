<?php
require 'includes/db.php';
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit; }
$stmt=$pdo->prepare('SELECT image_data,image_mime,image_url FROM menu_items WHERE item_id=?'); $stmt->execute([(int)$id]); $image=$stmt->fetch();
if (!$image) { http_response_code(404); exit; }
if (!empty($image['image_data'])) { header('Content-Type: '.$image['image_mime']); header('Cache-Control: public, max-age=3600'); echo $image['image_data']; exit; }
if (!empty($image['image_url']) && filter_var($image['image_url'],FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($image['image_url'],PHP_URL_SCHEME)),['http','https'],true)) { header('Location: '.$image['image_url'],true,302); exit; }
http_response_code(404);
