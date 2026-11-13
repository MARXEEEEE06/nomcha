<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require 'includes/toasts.php';
require_once 'includes/make_mailer.php';

$role = current_role($pdo);
$isTeam = $role === 'admin';
$errors = [];

function cleanPromoFaq(string $html): string {
    return strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $errors[] = 'Your session expired. Please try again.';
    if (isset($_POST['claim_voucher']) && !$errors) {
        require_customer($pdo);
        $id = (int)$_POST['claim_voucher'];
        $check = $pdo->prepare("SELECT promo_id FROM promotions WHERE promo_id=? AND is_platform_voucher=1 AND status='active' AND NOW() BETWEEN starts_at AND ends_at AND (usage_limit IS NULL OR usage_count < usage_limit)");
        $check->execute([$id]);
        if (!$check->fetchColumn()) $errors[] = 'This voucher is no longer available.';
        else {
            $pdo->prepare('INSERT IGNORE INTO promotion_claims(promo_id,user_id) VALUES(?,?)')->execute([$id,(int)$_SESSION['user_id']]);
            $_SESSION['flash_toasts'][] = ['kind'=>'success','title'=>'Voucher claimed','text'=>'You can select it when placing an order.'];
            header('Location: promos.php?tab=vouchers'); exit;
        }
    }
    if (isset($_POST['save_promo']) && $isTeam && !$errors) {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $mechanics = trim((string)($_POST['mechanics'] ?? ''));
        $isVoucher = isset($_POST['is_platform_voucher']) ? 1 : 0;
        $scope = (string)($_POST['eligibility_scope'] ?? 'all');
        $type = (string)($_POST['discount_type'] ?? 'percent');
        $value = max(0, (float)($_POST['discount_value'] ?? 0));
        $minimum = max(0, (float)($_POST['minimum_spend'] ?? 0));
        $maximum = ($_POST['max_discount'] ?? '') !== '' ? max(0, (float)$_POST['max_discount']) : null;
        $channel = (string)($_POST['channel'] ?? 'all');
        $start = str_replace('T', ' ', (string)($_POST['starts_at'] ?? ''));
        $end = str_replace('T', ' ', (string)($_POST['ends_at'] ?? ''));
        $eligibleItems = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['eligible_items'] ?? [])), static fn($id) => $id > 0)));

        if ($name === '' || mb_strlen($name) > 180 || $description === '' || !in_array($type, ['percent','fixed','free_item'], true)
            || !in_array($scope, ['all','exclusive'], true) || !in_array($channel, ['all','cash','gcash','maya','card_pickup'], true)
            || !$start || !$end || strtotime($end) <= strtotime($start) || ($scope === 'exclusive' && !$eligibleItems)) {
            $errors[] = 'Complete the required fields and select products for a selected-products offer.';
        }

        $faqs = [];
        foreach ((array)($_POST['faq_question'] ?? []) as $index => $question) {
            $question = trim((string)$question);
            $answer = cleanPromoFaq((string)(($_POST['faq_answer'] ?? [])[$index] ?? ''));
            if ($question !== '' && trim(strip_tags($answer)) !== '') $faqs[] = ['question' => mb_substr($question, 0, 500), 'answer' => $answer];
        }

        $imagePath = null;
        $uploadedImage = $_FILES['promo_image'] ?? null;
        if ($uploadedImage && (int)($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$uploadedImage['error'] !== UPLOAD_ERR_OK || (int)$uploadedImage['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Promo image must be under 5 MB.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($uploadedImage['tmp_name']);
                $extension = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
                if (!$extension || @getimagesize($uploadedImage['tmp_name']) === false) $errors[] = 'Choose a valid JPG, PNG, or WebP promo image.';
                else {
                    $directory = __DIR__ . '/uploads/promos';
                    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) $errors[] = 'Could not prepare promo image storage.';
                    else {
                        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                        if (move_uploaded_file($uploadedImage['tmp_name'], $directory . '/' . $filename)) $imagePath = 'uploads/promos/' . $filename;
                        else $errors[] = 'Could not save the promo image.';
                    }
                }
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $firstFaq = $faqs[0] ?? null;
                $insert = $pdo->prepare("INSERT INTO promotions(name,description,mechanics,faq_question,faq_answer,faq_json,promo_image,promo_code,is_platform_voucher,eligibility_scope,discount_type,discount_value,max_discount,minimum_spend,starts_at,ends_at,channel,status,created_by) VALUES(?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,'scheduled',?)");
                $insert->execute([$name,$description,$mechanics ?: null,$firstFaq['question'] ?? null,$firstFaq['answer'] ?? null,json_encode($faqs, JSON_UNESCAPED_UNICODE),$imagePath,$isVoucher,$scope,$type,$value,$type === 'percent' ? $maximum : null,$minimum,$start,$end,$channel,(int)$_SESSION['user_id']]);
                $promoId = (int)$pdo->lastInsertId();
                if ($scope === 'exclusive') {
                    $validIds = $pdo->prepare("SELECT item_id FROM menu_items WHERE item_kind='item' AND is_available=1 AND item_id=?");
                    $saveItem = $pdo->prepare('INSERT INTO promotion_eligible_items(promo_id,item_id) VALUES(?,?)');
                    foreach ($eligibleItems as $itemId) { $validIds->execute([$itemId]); if ($validIds->fetchColumn()) $saveItem->execute([$promoId,$itemId]); }
                }
                $pdo->commit();
                $pdo->prepare("INSERT INTO team_inbox_events(event_type,title,message,audience,entity_type,entity_id) VALUES('promo_scheduled',?,?, 'all','promotion',?)")
                    ->execute(['Promo scheduled: '.$name,$name.' is scheduled to run from '.$start.' to '.$end.'.',$promoId]);
                header('Location: promos.php?saved=1'); exit;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Could not save this promotion. Please check the database migration and try again.';
            }
        }
    }
    if (isset($_POST['promo_status']) && $isTeam && !$errors) {
        $status = (string)$_POST['promo_status'];
        $promoId = (int)($_POST['promo_id'] ?? 0);
        if (in_array($status, ['draft','scheduled','active','paused','ended'], true)) {
            $beforeQuery = $pdo->prepare('SELECT name,description,is_platform_voucher,status,starts_at,ends_at FROM promotions WHERE promo_id=?');
            $beforeQuery->execute([$promoId]);
            $before = $beforeQuery->fetch();
            $pdo->prepare('UPDATE promotions SET status=? WHERE promo_id=?')->execute([$status,$promoId]);
            if ($before && $before['status'] !== 'active' && $status === 'active' && strtotime((string)$before['starts_at']) <= time() && strtotime((string)$before['ends_at']) >= time()) {
                try {
                    $kind = (bool)$before['is_platform_voucher'] ? 'voucher' : 'promotion';
                    notify_nomcha_users('promo_notification','A new Nomcha ' . $kind . ' is available',(string)$before['name'] . ' — ' . (string)$before['description'], 'promos.php?tab=' . ((bool)$before['is_platform_voucher'] ? 'vouchers' : 'promos'));
                } catch (Throwable $mailError) { error_log('Promo notification failed: ' . $mailError->getMessage()); }
            }
        }
        header('Location: promos.php'); exit;
    }
}

$tab = ($_GET['tab'] ?? 'promos') === 'vouchers' ? 'vouchers' : 'promos';
$promos = $isTeam ? $pdo->query('SELECT * FROM promotions ORDER BY starts_at DESC')->fetchAll() : $pdo->query("SELECT * FROM promotions WHERE status='active' AND NOW() BETWEEN starts_at AND ends_at ORDER BY starts_at")->fetchAll();
$eligibleItems = $pdo->query("SELECT i.item_id,i.name,i.category_id,COALESCE(c.name,'Other') AS category_name FROM menu_items i LEFT JOIN menu_categories c ON c.category_id=i.category_id WHERE i.item_kind='item' AND i.is_available=1 ORDER BY category_name,i.name")->fetchAll();
$categories = [];
foreach ($eligibleItems as $item) $categories[$item['category_name']][] = $item;
$claims = [];
if ($role === 'customer') { $query=$pdo->prepare('SELECT promo_id FROM promotion_claims WHERE user_id=?'); $query->execute([(int)$_SESSION['user_id']]); $claims=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN)); }
$myAvailableVouchers = [];
if ($role === 'customer') {
    $query = $pdo->prepare("SELECT c.claim_id,c.claimed_at,p.name,p.description,p.discount_type,p.discount_value,p.minimum_spend,p.ends_at FROM promotion_claims c JOIN promotions p ON p.promo_id=c.promo_id WHERE c.user_id=? AND c.redeemed_at IS NULL AND p.is_platform_voucher=1 AND p.status='active' AND NOW() BETWEEN p.starts_at AND p.ends_at AND (p.usage_limit IS NULL OR p.usage_limit=0 OR p.usage_count<p.usage_limit) ORDER BY c.claimed_at DESC");
    $query->execute([(int)$_SESSION['user_id']]);
    $myAvailableVouchers = $query->fetchAll();
}
$metrics = $isTeam ? $pdo->query('SELECT p.promo_id,COUNT(r.redemption_id) redemptions,COALESCE(SUM(r.discount_amount),0) discount_total FROM promotions p LEFT JOIN promotion_redemptions r ON r.promo_id=p.promo_id GROUP BY p.promo_id')->fetchAll(PDO::FETCH_UNIQUE) : [];
$pageTitle = 'Vouchers & Promos';
require 'includes/header.php';
?>
<main class="container py-5">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><p class="text-uppercase small fw-semibold text-secondary mb-1">Nomcha offers</p><h1><?= $isTeam ? 'Promotions' : 'Vouchers & Promos' ?></h1><p class="text-secondary mb-0"><?= $isTeam ? 'Promos invite customers to participate. Vouchers apply discounts at checkout.' : 'Join promotions or claim vouchers for your next order.' ?></p></div><?php if ($isTeam): ?><button class="btn btn-order" data-bs-toggle="modal" data-bs-target="#promoModal"><i class="bi bi-plus-lg me-1"></i>Create offer</button><?php endif; ?></div>
 <?php $toastMessages=[]; if (isset($_GET['saved'])) $toastMessages[]=['kind'=>'success','text'=>'Offer saved and added to the team inbox.']; foreach ($errors as $error) $toastMessages[]=['kind'=>'danger','text'=>$error]; render_toasts($toastMessages); ?>
 <ul class="nav nav-pills mb-4"><li class="nav-item"><a class="nav-link <?=$tab==='promos'?'active':''?>" href="?tab=promos">Promos</a></li><li class="nav-item"><a class="nav-link <?=$tab==='vouchers'?'active':''?>" href="?tab=vouchers">Vouchers</a></li></ul>
 <?php if ($tab === 'vouchers' && $role === 'customer'): ?><section class="form-panel mb-4"><h2 class="h4">My available vouchers</h2><?php if ($myAvailableVouchers): ?><div class="row g-3"><?php foreach ($myAvailableVouchers as $voucher): ?><div class="col-md-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between gap-2"><strong><?=e($voucher['name'])?></strong><span class="badge text-bg-success">Unused</span></div><p class="small text-secondary mt-2 mb-2"><?=e($voucher['description'])?></p><p class="small mb-2"><?=e($voucher['discount_type']==='percent'?$voucher['discount_value'].'% off':($voucher['discount_type']==='fixed'?'₱'.number_format((float)$voucher['discount_value'],2).' off':'Free item'))?><?php if ((float)$voucher['minimum_spend']>0): ?> · ₱<?=number_format((float)$voucher['minimum_spend'],2)?> minimum<?php endif; ?></p><p class="small text-secondary mb-0">Expires <?=date('M j, Y',strtotime($voucher['ends_at']))?> · Choose it at checkout.</p></div></div><?php endforeach; ?></div><?php else: ?><p class="text-secondary mb-0">You have no available unused vouchers. Claim one below to add it here.</p><?php endif; ?></section><?php endif; ?>
 <div class="row g-4">
 <?php $shown=0; foreach ($promos as $promo): if (($tab==='vouchers') !== (bool)$promo['is_platform_voucher']) continue; $shown++; $faqs=json_decode((string)($promo['faq_json']??''),true); if (!is_array($faqs)||!$faqs) $faqs=$promo['faq_question']&&$promo['faq_answer']?[['question'=>$promo['faq_question'],'answer'=>$promo['faq_answer']]]:[]; ?>
  <div class="col-md-6"><article class="form-panel h-100 promo-card">
   <?php if (!empty($promo['promo_image'])): ?><img class="promo-card-image" src="<?=e($promo['promo_image'])?>" alt="<?=e($promo['name'])?>"><?php endif; ?>
   <div class="d-flex justify-content-between gap-2"><h2 class="h4"><?=e($promo['name'])?></h2><?php if($isTeam):?><span class="badge text-bg-light align-self-start"><?=e($promo['status'])?></span><?php endif;?></div>
   <p><?=nl2br(e($promo['description']))?></p>
   <?php if ($tab==='promos'): ?><p class="badge text-bg-info">Participation promo</p><?php else: ?><p class="badge text-bg-success">Checkout voucher</p><ul class="small text-secondary"><li><?=e($promo['discount_type']==='percent'?$promo['discount_value'].'% off':($promo['discount_type']==='fixed'?'₱'.number_format((float)$promo['discount_value'],2).' off':'Free item'))?></li><?php if((float)$promo['minimum_spend']>0):?><li>Minimum spend: ₱<?=number_format((float)$promo['minimum_spend'],2)?></li><?php endif;?><?php if($promo['max_discount']):?><li>Maximum discount: ₱<?=number_format((float)$promo['max_discount'],2)?></li><?php endif;?></ul><?php endif; ?>
   <?php if ($promo['mechanics']): ?><div class="small mb-3"><strong>Mechanics</strong><p class="mb-0"><?=nl2br(e($promo['mechanics']))?></p></div><?php endif; ?>
   <p class="small text-secondary">Valid <?=date('M j, Y',strtotime($promo['starts_at']))?> – <?=date('M j, Y',strtotime($promo['ends_at']))?></p>
   <?php foreach($faqs as $faq): ?><details class="mb-2"><summary><?=e((string)$faq['question'])?></summary><div class="mt-2"><?=$faq['answer']?></div></details><?php endforeach; ?>
   <?php if(!$isTeam && $tab==='vouchers'): if(in_array((int)$promo['promo_id'],$claims,true)): ?><span class="badge text-bg-success">Claimed</span><?php elseif($role==='customer'): ?><form method="post" class="mt-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="claim_voucher" value="<?=(int)$promo['promo_id']?>"><button class="btn btn-order">Claim voucher</button></form><?php else: ?><a class="btn btn-order mt-3" href="login.php?redirect=<?=rawurlencode('promos.php?tab=vouchers')?>">Log in to claim</a><?php endif; endif; ?>
   <?php if ($isTeam): ?><div class="d-flex justify-content-between small text-secondary"><span><?=number_format((int)($metrics[$promo['promo_id']]['redemptions']??0))?> redemptions</span><span>₱<?=number_format((float)($metrics[$promo['promo_id']]['discount_total']??0),2)?> discount</span></div><form method="post" class="mt-3 d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="promo_id" value="<?=(int)$promo['promo_id']?>"><select class="form-select form-select-sm" name="promo_status"><?php foreach(['draft','scheduled','active','paused','ended'] as $status):?><option <?=$promo['status']===$status?'selected':''?>><?=$status?></option><?php endforeach;?></select><button class="btn btn-sm btn-outline-secondary">Update</button></form><?php endif; ?>
  </article></div>
 <?php endforeach; if(!$shown): ?><div class="col-12"><div class="form-panel text-center py-5"><i class="bi bi-tag fs-1 text-secondary"></i><h2 class="h4 mt-3">No <?=e($tab)?> available</h2><p class="text-secondary mb-0">Check back soon for new offers.</p></div></div><?php endif; ?>
 </div>
</main>
<?php if ($isTeam): ?>
<div class="modal fade" id="promoModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content order-options-modal"><form method="post" enctype="multipart/form-data"><div class="modal-header"><h2 class="modal-title h5">Create an offer</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
 <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="save_promo" value="1">
 <div class="mb-3"><label class="form-label" for="offer-kind">Offer type</label><select class="form-select" name="offer_kind" id="offer-kind"><option value="promo">Participation promo</option><option value="voucher">Checkout voucher</option></select><div class="form-text">Promos describe an activity customers join. Vouchers provide a checkout discount.</div><input type="hidden" name="is_platform_voucher" id="is-platform-voucher" value=""></div>
 <div class="mb-3"><label class="form-label" for="promo-image">Promo image</label><input class="form-control" id="promo-image" type="file" name="promo_image" accept="image/jpeg,image/png,image/webp"><div class="form-text">Square image recommended. JPG, PNG, or WebP up to 5 MB.</div></div>
 <div class="mb-3"><label class="form-label" for="promo-name">Offer name</label><input class="form-control" id="promo-name" name="name" maxlength="180" required></div>
 <div class="mb-3"><label class="form-label" for="promo-description">Customer description</label><textarea class="form-control" id="promo-description" name="description" rows="3" required placeholder="For a promo, explain how to join. For a voucher, describe the discount."></textarea></div>
 <div class="mb-3"><label class="form-label" for="promo-mechanics">Mechanics and terms</label><textarea class="form-control" id="promo-mechanics" name="mechanics" rows="3" placeholder="Eligibility, steps, exclusions, and terms"></textarea></div>
 <div class="row"><div class="col-md-6 mb-3"><label class="form-label" for="eligibility-scope">Eligible products</label><select class="form-select" name="eligibility_scope" id="eligibility-scope"><option value="all">All products</option><option value="exclusive">Selected products only</option></select></div><div class="col-md-6 mb-3" id="discount-fields"><label class="form-label" for="discount-type">Discount type</label><select class="form-select" name="discount_type" id="discount-type"><option value="percent">Percentage</option><option value="fixed">Fixed amount</option><option value="free_item">Free item</option></select></div>
 <div class="col-md-6 mb-3 discount-field"><label class="form-label">Discount value</label><input class="form-control" type="number" min="0" step="0.01" name="discount_value" value="0"></div><div class="col-md-6 mb-3 discount-field"><label class="form-label">Maximum discount (₱)</label><input class="form-control" type="number" min="0" step="0.01" name="max_discount"></div><div class="col-md-6 mb-3 discount-field"><label class="form-label">Minimum spend (₱)</label><input class="form-control" type="number" min="0" step="0.01" name="minimum_spend" value="0"></div>
 <div class="col-md-6 mb-3"><label class="form-label" for="promo-start">Starts</label><input class="form-control" id="promo-start" type="datetime-local" name="starts_at" required></div><div class="col-md-6 mb-3"><label class="form-label" for="promo-end">Ends</label><input class="form-control" id="promo-end" type="datetime-local" name="ends_at" required></div><div class="col-md-6 mb-3"><label class="form-label">Payment channel</label><select class="form-select" name="channel"><option value="all">All payment methods</option><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="card_pickup">Card at pickup</option></select></div></div>
 <div class="mb-3" id="eligible-products-wrap" hidden><label class="form-label">Select eligible products</label><input type="search" id="product-search" class="form-control mb-3" placeholder="Filter products"><div class="d-flex flex-wrap gap-2 mb-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-product-select="all">Select all shown</button><button class="btn btn-sm btn-outline-secondary" type="button" data-product-select="none">Clear shown</button></div>
 <?php foreach($categories as $categoryName=>$items): ?><fieldset class="border rounded p-3 mb-2 product-category" data-category="<?=e(mb_strtolower($categoryName))?>"><legend class="float-none w-auto px-2 fs-6"><label><input type="checkbox" data-category-check> <?=e($categoryName)?></label></legend><?php foreach($items as $item): ?><label class="d-flex align-items-center gap-2 mb-1 product-choice" data-product="<?=e(mb_strtolower($item['name']))?>"><input type="checkbox" name="eligible_items[]" value="<?=(int)$item['item_id']?>"> <?=e($item['name'])?></label><?php endforeach; ?></fieldset><?php endforeach; ?></div>
 <section class="border-top pt-3"><div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h6 mb-0">Frequently asked questions</h3><button class="btn btn-sm btn-outline-secondary" type="button" id="add-faq">Add question</button></div><div id="faq-list"></div></section>
 </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-order">Save offer</button></div></form></div></div></div>
<template id="faq-template"><div class="faq-entry border rounded p-3 mb-3"><div class="d-flex justify-content-between align-items-center"><label class="form-label">Question</label><button class="btn btn-sm btn-outline-danger" type="button" data-remove-faq>Remove</button></div><input class="form-control mb-2" name="faq_question[]" maxlength="500" placeholder="What should customers know?"><label class="form-label">Answer</label><div class="btn-toolbar mb-2" role="toolbar" aria-label="FAQ formatting"><div class="btn-group btn-group-sm" role="group"><button class="btn btn-outline-secondary" type="button" data-faq-command="bold"><b>B</b></button><button class="btn btn-outline-secondary" type="button" data-faq-command="italic"><i>I</i></button><button class="btn btn-outline-secondary" type="button" data-faq-command="underline"><u>U</u></button><button class="btn btn-outline-secondary" type="button" data-faq-command="insertUnorderedList">• List</button></div></div><div class="form-control faq-editor" contenteditable="true" style="min-height:90px" role="textbox" aria-multiline="true"></div><input type="hidden" name="faq_answer[]" class="faq-answer"></div></template>
<style>.promo-card-image{float:left;width:min(45%,300px);aspect-ratio:1;object-fit:cover;border-radius:14px;margin:0 1rem .75rem 0}.promo-card::after{content:"";display:block;clear:both}.faq-editor:focus{border-color:#86b7fe;outline:0;box-shadow:0 0 0 .25rem #0d6efd40}.product-category legend{font-weight:600}@media(max-width:575.98px){.promo-card-image{float:none;display:block;width:min(100%,340px);margin:0 auto 1rem}}</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.querySelector('#promoModal form'),kind=document.getElementById('offer-kind'),voucher=document.getElementById('is-platform-voucher'),discountFields=[...document.querySelectorAll('.discount-field,#discount-fields')],scope=document.getElementById('eligibility-scope'),productWrap=document.getElementById('eligible-products-wrap');
 const syncKind=()=>{const isVoucher=kind.value==='voucher';voucher.disabled=!isVoucher;voucher.value=isVoucher?'1':'';discountFields.forEach(el=>el.hidden=!isVoucher);};kind.addEventListener('change',syncKind);syncKind();
 scope.addEventListener('change',()=>productWrap.hidden=scope.value!=='exclusive');
 const faqList=document.getElementById('faq-list'),template=document.getElementById('faq-template');
 function addFaq(){faqList.append(template.content.cloneNode(true));} addFaq();
 document.getElementById('add-faq').addEventListener('click',addFaq);
 form.addEventListener('mousedown',event=>{if(event.target.closest('[data-faq-command]'))event.preventDefault();});
 form.addEventListener('click',event=>{const remove=event.target.closest('[data-remove-faq]');if(remove){remove.closest('.faq-entry').remove();return;}const command=event.target.closest('[data-faq-command]');if(command){const entry=command.closest('.faq-entry'),editor=entry.querySelector('.faq-editor');editor.focus();document.execCommand(command.dataset.faqCommand,false,null);}});
 form.addEventListener('submit',()=>form.querySelectorAll('.faq-entry').forEach(entry=>entry.querySelector('.faq-answer').value=entry.querySelector('.faq-editor').innerHTML));
 const search=document.getElementById('product-search'); search.addEventListener('input',()=>{const term=search.value.trim().toLowerCase();document.querySelectorAll('.product-choice').forEach(row=>row.hidden=!row.dataset.product.includes(term));document.querySelectorAll('.product-category').forEach(group=>group.hidden=!group.querySelector('.product-choice:not([hidden])'));});
 document.querySelectorAll('[data-category-check]').forEach(master=>master.addEventListener('change',()=>master.closest('fieldset').querySelectorAll('.product-choice:not([hidden]) input').forEach(box=>box.checked=master.checked)));
 document.querySelectorAll('[data-product-select]').forEach(button=>button.addEventListener('click',()=>document.querySelectorAll('.product-choice:not([hidden]) input').forEach(box=>box.checked=button.dataset.productSelect==='all')));
});
</script>
<?php endif; require 'includes/footer.php'; ?>
