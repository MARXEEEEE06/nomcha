<?php
function save_square_employee_photo(array $upload): string {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Choose a square employee photo.');
    if (($upload['size'] ?? PHP_INT_MAX) > 4 * 1024 * 1024) throw new RuntimeException('Employee photos must be 4 MB or smaller.');
    $info=@getimagesize((string)$upload['tmp_name']);
    if (!$info || (int)$info[0] !== (int)$info[1]) throw new RuntimeException('Employee photos must be square (1:1).');
    $types=[IMAGETYPE_JPEG=>['image/jpeg','jpg'],IMAGETYPE_PNG=>['image/png','png'],IMAGETYPE_WEBP=>['image/webp','webp']];
    if (!isset($types[$info[2]])) throw new RuntimeException('Use a JPG, PNG, or WebP employee photo.');
    $dir=__DIR__.'/../assets/uploads/employees';
    if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('The employee photo folder is unavailable.');
    $name='employee_'.bin2hex(random_bytes(16)).'.'.$types[$info[2]][1];
    if (!move_uploaded_file((string)$upload['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('Could not save the employee photo.');
    return 'assets/uploads/employees/'.$name;
}

function uploaded_menu_image(array $upload): ?array {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? PHP_INT_MAX) > 4 * 1024 * 1024) throw new RuntimeException('Menu photos must be 4 MB or smaller.');
    $info=@getimagesize((string)$upload['tmp_name']);
    $types=[IMAGETYPE_JPEG=>'image/jpeg',IMAGETYPE_PNG=>'image/png',IMAGETYPE_WEBP=>'image/webp'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('Use a JPG, PNG, or WebP photo.');
    $bytes=file_get_contents((string)$upload['tmp_name']);
    if ($bytes===false) throw new RuntimeException('Could not read the uploaded photo.');
    return ['mime'=>$types[$info[2]],'data'=>$bytes];
}
