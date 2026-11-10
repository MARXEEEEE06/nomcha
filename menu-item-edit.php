<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require_staff_or_admin($pdo);
$id=filter_var($_GET['id']??$_POST['item_id']??null,FILTER_VALIDATE_INT)?:0;
$item=['category_id'=>'','name'=>'','description'=>'','price'=>'','image_url'=>'','publish_at'=>'','customizations_json'=>'']; $error=null;
$categories=$pdo->query('SELECT category_id, name FROM menu_categories ORDER BY sort_order, name')->fetchAll();
if ($id) { $get=$pdo->prepare('SELECT item_id, category_id, name, description, price, image_url, publish_at, customizations_json FROM menu_items WHERE item_id=?'); $get->execute([$id]); $item=$get->fetch()?:null; if (!$item) { http_response_code(404); exit('Menu item not found.'); } if ($item['publish_at']) $item['publish_at']=(new DateTimeImmutable((string)$item['publish_at'],new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach (['category_id','name','description','price','image_url','publish_at','customizations_json'] as $key) $item[$key]=trim((string)($_POST[$key]??''));
    if (!csrf_check($_POST['csrf']??null)) $error='Session expired. Refresh and try again.';
    elseif (!filter_var($item['category_id'],FILTER_VALIDATE_INT) || !mb_strlen($item['name']) || mb_strlen($item['name'])>255) $error='Choose a category and enter an item name.';
    elseif ($item['price']!=='' && (!is_numeric($item['price']) || (float)$item['price']<0 || (float)$item['price']>99999999)) $error='Enter a valid non-negative price.';
    elseif ($item['image_url']!=='' && !(filter_var($item['image_url'],FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($item['image_url'],PHP_URL_SCHEME)),['http','https'],true))) $error='Photo must use an http or https URL.';
    elseif ($item['publish_at']!=='' && !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/',$item['publish_at'])) $error='Choose a valid release date and time.';
    $releaseDate=$item['publish_at']===''?null:DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$item['publish_at'],new DateTimeZone('Asia/Manila')); $dateErrors=DateTimeImmutable::getLastErrors();
    if (!$error && $item['publish_at']!=='' && (!$releaseDate || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])))) $error='Choose a valid release date and time.';
    $customs=null;
    if (!$error && $item['customizations_json']!=='') {
        $customs=json_decode($item['customizations_json'],true);
        if (!is_array($customs) || json_last_error()!==JSON_ERROR_NONE || !array_is_list($customs)) $error='Customizations must be a JSON array of option groups.';
        else foreach ($customs as $group) {
            if (!is_array($group) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/',(string)($group['key']??'')) || trim((string)($group['label']??''))==='' || !is_array($group['choices']??null) || !$group['choices']) { $error='Each customization group needs a valid key, label, and choices.'; break; }
            if (!empty($group['multiple']) && (!isset($group['maxCount']) || filter_var($group['maxCount'],FILTER_VALIDATE_INT)===false || (int)$group['maxCount']<1 || (int)$group['maxCount']>99)) { $error='Multiple choice groups need a maxCount between 1 and 99.'; break; }
            foreach ($group['choices'] as $choice) if (!is_array($choice) || trim((string)($choice['name']??''))==='' || mb_strlen((string)$choice['name'])>120 || filter_var($choice['priceCents']??0,FILTER_VALIDATE_INT)===false || (int)($choice['priceCents']??0)<0) { $error='Each choice needs a name and a non-negative integer priceCents.'; break 2; }
        }
    }
    if (!$error) {
        $release=$releaseDate?$releaseDate->format('Y-m-d H:i:s'):null;
        if ($id) $sql='UPDATE menu_items SET category_id=?,name=?,description=?,price=?,image_url=?,publish_at=?,customizations_json=? WHERE item_id=?'; else $sql='INSERT INTO menu_items (category_id,name,description,price,image_url,publish_at,customizations_json,is_available) VALUES (?,?,?,?,?,?,?,0)';
        $params=[(int)$item['category_id'],$item['name'],$item['description']?:null,$item['price']===''?null:$item['price'],$item['image_url']?:null,$release,$customs?json_encode($customs,JSON_UNESCAPED_UNICODE):null]; if ($id) $params[]=$id;
        $pdo->prepare($sql)->execute($params); $_SESSION['flash_success']=$id?'Menu item updated.':'Menu item added.'; header('Location: menu-management.php'); exit;
    }
}
$pageTitle=$id?'Edit menu item':'Add menu item'; require 'includes/header.php';
?>
<main class="container py-5" style="max-width:900px"><a href="menu-management.php">← Menu management</a><h1 class="my-3"><?=$id?'Edit':'Add'?> menu item</h1>
<?php if ($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<form method="post" class="card card-body"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$id?>">
<label class="form-label" for="category_id">Category</label><select class="form-select mb-3" id="category_id" name="category_id" required><option value="">Choose category</option><?php foreach($categories as $cat): ?><option value="<?=(int)$cat['category_id']?>" <?=((string)$item['category_id']===(string)$cat['category_id'])?'selected':''?>><?=e($cat['name'])?></option><?php endforeach; ?></select>
<label class="form-label" for="name">Item name</label><input class="form-control mb-3" id="name" name="name" required maxlength="255" value="<?=e($item['name'])?>">
<label class="form-label" for="description">Description</label><textarea class="form-control mb-3" id="description" name="description" maxlength="500" rows="3"><?=e($item['description']??'')?></textarea>
<label class="form-label" for="price">Price (₱)</label><input class="form-control mb-3" id="price" name="price" type="number" min="0" step="0.01" value="<?=e((string)$item['price'])?>">
<label class="form-label" for="image_url">Photo URL</label><input class="form-control mb-3" id="image_url" name="image_url" type="url" value="<?=e($item['image_url']??'')?>">
<label class="form-label" for="publish_at">Release time (Asia/Manila)</label><input class="form-control mb-3" id="publish_at" name="publish_at" type="datetime-local" value="<?=e((string)$item['publish_at'])?>"><div class="form-text mb-3">Leave blank to publish immediately. A future time keeps this item hidden from the public menu and checkout until then.</div>
<label class="form-label" for="customizations_json">Item customizations (JSON)</label><textarea class="form-control font-monospace mb-3" id="customizations_json" name="customizations_json" rows="8" placeholder='[{"key":"flavor","label":"Choose a flavor","required":true,"choices":[{"name":"Soy Garlic","priceCents":0}]}]'><?=e(is_string($item['customizations_json'])?(string)$item['customizations_json']:json_encode($item['customizations_json'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></textarea><div class="form-text mb-3">Use an array of groups, each with key, label, required, and choices. Choice prices are in cents. You can also use multiple=true and maxCount for counted options.</div>
<button class="btn btn-auth">Save item</button></form></main>
<?php require 'includes/footer.php'; ?>
