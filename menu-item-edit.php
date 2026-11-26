<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require 'includes/media_helpers.php'; require 'includes/toasts.php'; require_staff_or_admin($pdo);
$id=filter_var($_GET['id']??$_POST['item_id']??null,FILTER_VALIDATE_INT)?:0;
$entry=['category_id'=>'','name'=>'','description'=>'','price'=>'','unit_cost'=>'','image_url'=>'','publish_at'=>''];
$errors=[]; $storedBlob=false; $isAvailable=false; $storedImageUrl=null;
if ($id) {
    $get=$pdo->prepare('SELECT item_id,category_id,name,description,price,unit_cost,image_url,publish_at,is_available,(image_data IS NOT NULL) AS has_blob FROM menu_items WHERE item_id=?');
    $get->execute([$id]); $found=$get->fetch();
    if (!$found) { http_response_code(404); exit('Menu item not found.'); }
    $entry=array_intersect_key($found,$entry); $entry['price']=(string)$found['price']; $entry['unit_cost']=$found['unit_cost']===null?'':(string)$found['unit_cost'];
    $entry['publish_at']=$found['publish_at']?(new DateTimeImmutable((string)$found['publish_at'],new DateTimeZone('Asia/Manila')))->format('Y-m-d\\TH:i'):'';
    $storedBlob=(bool)$found['has_blob']; $storedImageUrl=$found['image_url']; $isAvailable=(bool)$found['is_available'];
}
$categories=$pdo->query('SELECT category_id,name FROM menu_categories ORDER BY sort_order,name')->fetchAll(PDO::FETCH_ASSOC);
$optionGroups=$pdo->query('SELECT option_group_id,name FROM menu_option_groups ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$optionData=$pdo->query("SELECT o.option_id,g.name AS type,COALESCE(o.name,source_item.name) AS name FROM menu_options o JOIN menu_option_groups g ON g.option_group_id=o.option_group_id LEFT JOIN menu_items source_item ON source_item.item_id=o.source_item_id WHERE o.is_available=1 AND (o.source_item_id IS NULL OR (source_item.is_available=1 AND (source_item.publish_at IS NULL OR source_item.publish_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)))) ORDER BY g.name,name")->fetchAll(PDO::FETCH_ASSOC);
$initialGroups=[];
if ($id) {
    $assignments=$pdo->prepare('SELECT ig.item_option_group_id,ig.option_group_id,ig.label,g.name AS type,ig.min_selections,ig.max_selections FROM menu_item_option_groups ig JOIN menu_option_groups g ON g.option_group_id=ig.option_group_id WHERE ig.item_id=? ORDER BY ig.sort_order,ig.item_option_group_id');
    $assignments->execute([$id]); $choiceQuery=$pdo->prepare('SELECT c.option_id,COALESCE(o.name,source_item.name) AS name,c.price_adjustment FROM menu_item_option_choices c JOIN menu_options o ON o.option_id=c.option_id LEFT JOIN menu_items source_item ON source_item.item_id=o.source_item_id WHERE c.item_option_group_id=? AND o.option_group_id=? ORDER BY c.sort_order,c.option_id');
    foreach ($assignments->fetchAll(PDO::FETCH_ASSOC) as $group) {
        $choiceQuery->execute([(int)$group['item_option_group_id'],(int)$group['option_group_id']]);
        $initialGroups[]=['label'=>$group['label'],'type'=>$group['type'],'required'=>(int)$group['min_selections']>0,'multiple'=>$group['max_selections']===null||(int)$group['max_selections']>1,'maxCount'=>$group['max_selections']===null?99:(int)$group['max_selections'],'choices'=>$choiceQuery->fetchAll(PDO::FETCH_ASSOC)];
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach ($entry as $key=>$_) $entry[$key]=trim((string)($_POST[$key]??''));
    if (!csrf_check($_POST['csrf']??null)) $errors[]='Session expired. Refresh and try again.';
    if (!filter_var($entry['category_id'],FILTER_VALIDATE_INT)||!mb_strlen($entry['name'])||mb_strlen($entry['name'])>255) $errors[]='Choose a category and enter a menu name.';
    if ($entry['price']===''||!is_numeric($entry['price'])||(float)$entry['price']<0||(float)$entry['price']>99999999) $errors[]='Enter a valid non-negative price.';
    if ($entry['unit_cost']!==''&&(!is_numeric($entry['unit_cost'])||(float)$entry['unit_cost']<0||(float)$entry['unit_cost']>99999999)) $errors[]='Enter a valid non-negative unit cost.';
    if ($entry['image_url']!==''&&!(filter_var($entry['image_url'],FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($entry['image_url'],PHP_URL_SCHEME)),['http','https'],true))) $errors[]='Photo links must use http or https.';
    if ($entry['publish_at']!==''&&!preg_match('/^\\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d$/',$entry['publish_at'])) $errors[]='Choose a valid release date and time.';
    $releaseDate=$entry['publish_at']===''?null:DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$entry['publish_at'],new DateTimeZone('Asia/Manila')); $dateErrors=DateTimeImmutable::getLastErrors();
    if ($entry['publish_at']!==''&&(!$releaseDate||($dateErrors&&($dateErrors['warning_count']||$dateErrors['error_count'])))) $errors[]='Choose a valid release date and time.';
    $rawGroups=trim((string)($_POST['option_groups']??'')); $groups=$rawGroups===''?[]:json_decode($rawGroups,true);
    if ($rawGroups!==''&&(!is_array($groups)||json_last_error()!==JSON_ERROR_NONE||!array_is_list($groups))) $errors[]='Customization groups are not in a valid format.';
    $checkedGroups=[]; $groupLookup=[]; $optionLookup=[];
    if (is_array($groups)) {
        $groupByName=$pdo->prepare('SELECT option_group_id FROM menu_option_groups WHERE name=?');
        foreach ($groups as $index=>$group) {
            if (!is_array($group)) { $errors[]='Review the customization groups.'; break; }
            $label=trim((string)($group['label']??'')); $type=trim((string)($group['type']??''));
            $rawChoices=is_array($group['choices']??null)?$group['choices']:[]; $choicePrices=[]; $ids=[];
            foreach ($rawChoices as $choice) {
                $choiceId=filter_var(is_array($choice)?($choice['option_id']??null):$choice,FILTER_VALIDATE_INT);
                $priceAdjustment=is_array($choice)?($choice['price_adjustment']??0):0;
                if (!$choiceId||!is_numeric($priceAdjustment)||(float)$priceAdjustment<0||(float)$priceAdjustment>99999999) { $errors[]='Enter a valid non-negative price adjustment for each choice.'; break; }
                $choiceId=(int)$choiceId; if (isset($choicePrices[$choiceId])) { $errors[]='A choice cannot be listed twice in one group.'; break; }
                $ids[]=$choiceId; $choicePrices[$choiceId]=(float)$priceAdjustment;
            }
            if ($errors) break;
            if ($label===''||mb_strlen($label)>100||$type===''||!$ids||count($ids)>60) { $errors[]='Each group needs a name and at least one available choice.'; break; }
            $groupByName->execute([$type]); $groupId=(int)$groupByName->fetchColumn();
            if (!$groupId) { $errors[]='Choose a valid option group.'; break; }
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $choiceQuery=$pdo->prepare("SELECT o.option_id,o.option_group_id,o.is_available,o.source_item_id,source_item.is_available AS source_available,source_item.publish_at FROM menu_options o LEFT JOIN menu_items source_item ON source_item.item_id=o.source_item_id WHERE o.option_id IN ($marks)");
            $choiceQuery->execute($ids); $valid=[];
            foreach ($choiceQuery->fetchAll(PDO::FETCH_ASSOC) as $choice) if ((int)$choice['option_group_id']===$groupId&&(int)$choice['is_available']===1&&($choice['source_item_id']===null||((int)$choice['source_available']===1&&(!$choice['publish_at']||strtotime((string)$choice['publish_at'])<=time())))) $valid[]=(int)$choice['option_id'];
            if (count($valid)!==count($ids)) { $errors[]='One selected choice is no longer available. Refresh and try again.'; break; }
            $required=($group['required']??'')==='1'; $multiple=($group['multiple']??'')==='1'; $maxCount=$multiple?filter_var($group['maxCount']??null,FILTER_VALIDATE_INT):1;
            if ($multiple&&($maxCount===false||$maxCount<1||$maxCount>99)) { $errors[]='Set a valid maximum number of selections.'; break; }
            if (!$multiple&&count($valid)<1) { $errors[]='Add at least one choice to each group.'; break; }
            $checkedGroups[]=['group_id'=>$groupId,'label'=>$label,'ids'=>$valid,'prices'=>$choicePrices,'min'=>$required?1:0,'max'=>$multiple?(int)$maxCount:1,'sort_order'=>$index];
        }
    }
    if (!$errors&&$checkedGroups) {
        $sum=0;
        foreach ($checkedGroups as $group) if ($group['max']!==null) $sum+=$group['max'];
        if ($sum>500) $errors[]='The total number of selections across groups is too high.';
    }
    try { $photo=uploaded_menu_image($_FILES['image_file']??[]); } catch (Throwable $e) { $photo=null; $errors[]=$e->getMessage(); }
    if (!$errors) {
        $release=$releaseDate?$releaseDate->format('Y-m-d H:i:s'):null; $remove=($_POST['remove_image']??'')==='1';
        $url=$remove||$photo?null:($entry['image_url']!==''?$entry['image_url']:$storedImageUrl);
        $blobSql=$photo?'image_data=?,image_mime=?':($remove?'image_data=NULL,image_mime=NULL':'image_data=image_data,image_mime=image_mime');
        $pdo->beginTransaction();
        try {
            if ($id) {
                $sql="UPDATE menu_items SET category_id=?,name=?,description=?,price=?,unit_cost=?,image_url=?,publish_at=?,{$blobSql} WHERE item_id=?";
                $params=[(int)$entry['category_id'],$entry['name'],$entry['description']?:null,$entry['price'],$entry['unit_cost']===''?null:$entry['unit_cost'],$url,$release];
                if ($photo) array_push($params,$photo['data'],$photo['mime']); $params[]=$id; $pdo->prepare($sql)->execute($params); $itemId=$id;
            } else {
                $sql='INSERT INTO menu_items(category_id,name,description,price,unit_cost,image_url,image_data,image_mime,publish_at,is_available) VALUES(?,?,?,?,?,?,?,?,?,0)';
                $pdo->prepare($sql)->execute([(int)$entry['category_id'],$entry['name'],$entry['description']?:null,$entry['price'],$entry['unit_cost']===''?null:$entry['unit_cost'],$photo?null:$url,$photo['data']??null,$photo['mime']??null,$release]); $itemId=(int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM menu_item_option_groups WHERE item_id=?')->execute([$itemId]);
            $insertGroup=$pdo->prepare('INSERT INTO menu_item_option_groups(item_id,option_group_id,label,min_selections,max_selections,sort_order) VALUES(?,?,?,?,?,?)');
            $insertChoice=$pdo->prepare('INSERT INTO menu_item_option_choices(item_option_group_id,option_id,price_adjustment,sort_order) VALUES(?,?,?,?)');
            foreach ($checkedGroups as $group) {
                $insertGroup->execute([$itemId,$group['group_id'],$group['label'],$group['min'],$group['max'],$group['sort_order']]); $assignmentId=(int)$pdo->lastInsertId();
                foreach ($group['ids'] as $sort=>$optionId) $insertChoice->execute([$assignmentId,$optionId,$group['prices'][$optionId],$sort]);
            }
            $pdo->commit(); $_SESSION['flash_success']=$id?'Menu item updated.':'Menu item added inactive. An admin must activate it.'; header('Location: menu-management.php'); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors[]='Could not save the menu item. Check its category and customization options.'; }
    }
}
$groupNames=array_column($optionGroups,'name');
$imageQuery=$id?'menu-image.php?id='.$id:($entry['image_url']?:'assets/menu-placeholder.svg');
$pageTitle=$id?'Edit menu item':'Add menu item'; $pageScripts='<script src="assets/js/menu-entry-editor.js?v=' . filemtime(__DIR__ . '/assets/js/menu-entry-editor.js') . '"></script><script src="assets/js/image-preview.js"></script>'; require 'includes/header.php'; render_toasts(array_map(static fn($message)=>['kind'=>'danger','text'=>$message],$errors));
?>
<main class="container py-5" style="max-width:980px"><a class="btn btn-link auth-link px-0" href="menu-management.php"><i class="bi bi-arrow-left me-1"></i>Menu management</a><h1 class="mb-4"><?=$id?'Edit':'Add'?> menu item</h1>
<?php if($id): ?><section class="card card-body mb-3"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3"><div><h2 class="h5 mb-1">Sale status</h2><p class="mb-0">Currently <strong class="<?=$isAvailable?'text-success':'text-danger'?>"><?=$isAvailable?'Active':'Inactive'?></strong>. Status changes take effect after confirmation.</p></div><button type="button" class="btn <?=$isAvailable?'btn-outline-danger':'btn-outline-success'?>" id="open-status-confirm" data-next-status="<?=$isAvailable?'deactivate':'activate'?>"><?=$isAvailable?'Deactivate':'Activate'?> item</button></div></section><?php endif; ?>
<?php if(!$categories): ?><div class="alert alert-info">Add a menu category before creating menu items.</div><?php endif; ?>
<form id="menuEntryForm" method="post" enctype="multipart/form-data" class="card card-body gap-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$id?>"><input type="hidden" name="option_groups" id="option-groups-data">
<div class="row g-3"><div class="col-md-6"><label class="form-label" for="category_id">Category</label><select class="form-select" name="category_id" id="category_id" required><option value="">Choose a category</option><?php foreach($categories as $category): ?><option value="<?=(int)$category['category_id']?>" <?=(string)$entry['category_id']===(string)$category['category_id']?'selected':''?>><?=e($category['name'])?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label" for="name">Name</label><input class="form-control" name="name" id="name" maxlength="255" required value="<?=e($entry['name'])?>"></div><div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control" name="description" id="description" maxlength="500" rows="3"><?=e((string)$entry['description'])?></textarea></div><div class="col-md-6"><label class="form-label" for="price">Price (₱)</label><input class="form-control" name="price" id="price" type="number" min="0" step="0.01" required value="<?=e((string)$entry['price'])?>"></div><div class="col-md-6"><label class="form-label" for="unit_cost">Unit cost (₱, optional)</label><input class="form-control" name="unit_cost" id="unit_cost" type="number" min="0" step="0.01" value="<?=e((string)$entry['unit_cost'])?>"><div class="form-text">Used for gross margin reporting. Leave blank if it is not tracked.</div></div><div class="col-md-6"><label class="form-label" for="publish_at">Release time (Asia/Manila)</label><input class="form-control" name="publish_at" id="publish_at" type="datetime-local" value="<?=e($entry['publish_at'])?>"><div class="form-text">Leave blank for immediate release after activation.</div></div></div>
<section id="customization-section" class="border rounded p-3"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div><h2 class="h5 mb-1">Item customizations</h2><p class="small text-secondary mb-3">Assign option groups and choose the allowed options for this item.</p></div><button type="button" class="btn btn-sm btn-outline-primary" id="add-customization-group" <?=$optionData?'':'disabled'?>>Add option group</button></div><div id="customization-groups"></div><?php if(!$optionData): ?><p class="small text-secondary mb-0">Add option groups and choices in <a href="menu-option-management.php">Option management</a> first.</p><?php else: ?><a class="small" href="menu-option-management.php">Manage option groups and choices</a><?php endif; ?></section>
<section class="border rounded p-3"><h2 class="h5">Photo</h2><div class="row g-3 align-items-start"><div class="col-md-5"><img id="menu-photo-preview" class="w-100 rounded" style="max-height:240px;object-fit:cover" src="<?=e($imageQuery?:'assets/menu-placeholder.svg')?>" alt="Menu photo preview"><label class="image-drop-zone mt-2" for="image_file" id="image-drop-zone"><span><i class="bi bi-cloud-arrow-up fs-3 d-block"></i>Drop an image here or choose a file</span><input class="visually-hidden" type="file" id="image_file" name="image_file" accept="image/jpeg,image/png,image/webp" data-image-input="#menu-photo-preview"></label><div class="form-text">JPG, PNG, or WebP up to 4 MB.</div></div><div class="col-md-7"><label class="form-label" for="image_url">Or use a photo link</label><input class="form-control" type="url" name="image_url" id="image_url" maxlength="500" value="<?=e((string)$entry['image_url'])?>" placeholder="https://…"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_image"><label class="form-check-label" for="remove_image">Remove the current image</label></div></div></div></section>
<button type="button" class="btn btn-auth" id="open-menu-save-confirm" <?=$categories?'':'disabled'?>>Save menu item</button></form></main>
<script type="application/json" id="menu-option-data"><?=json_encode(array_map(static fn($option)=>['id'=>(int)$option['option_id'],'name'=>$option['name'],'type'=>$option['type']],$optionData),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
<script type="application/json" id="menu-option-group-types"><?=json_encode($groupNames,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
<script type="application/json" id="menu-customization-initial"><?=json_encode($initialGroups,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
<div class="modal fade" id="menuSaveConfirm" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Confirm menu changes</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">Save these menu item details? Sale status is managed separately.</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Review changes</button><button class="btn btn-auth" type="submit" form="menuEntryForm">Confirm and save</button></div></div></div></div>
<?php if($id): ?><div class="modal fade" id="menuStatusConfirm" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="menu-item-status.php"><div class="modal-header"><h2 class="modal-title fs-5">Confirm sale status change</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><label class="form-label" for="menu-status-confirm-word">Type <strong id="menu-status-confirm-label"></strong> to confirm.</label><input class="form-control" id="menu-status-confirm-word" name="confirm_text" required autocomplete="off"></div><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$id?>"><input type="hidden" name="new_status" id="menu-status-next"><input type="hidden" name="return_to" value="menu-item-edit.php?id=<?=$id?>"><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-auth">Confirm status change</button></div></form></div></div></div><script>document.getElementById('open-status-confirm').addEventListener('click',()=>{const next=document.getElementById('open-status-confirm').dataset.nextStatus;document.getElementById('menu-status-next').value=next;document.getElementById('menu-status-confirm-label').textContent=next==='activate'?'ACTIVATE':'DEACTIVATE';new bootstrap.Modal('#menuStatusConfirm').show();});</script><?php endif; ?>
<?php require 'includes/footer.php'; ?>
