<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require 'includes/toasts.php';
require_once 'includes/make_mailer.php';

$role = current_role($pdo);
$isTeam = $role === 'admin';
$errors = [];
$editPromo = null;
if ($isTeam && (isset($_GET['edit']) || (isset($_POST['save_promo']) && (int)($_POST['promo_id'] ?? 0) > 0))) {
    $editQuery = $pdo->prepare('SELECT * FROM promotions WHERE promo_id=?');
    $editQuery->execute([(int)($_GET['edit'] ?? $_POST['promo_id'])]);
    $editPromo = $editQuery->fetch() ?: null;
    if ($editPromo) {
        $activityQuery = $pdo->prepare('SELECT (SELECT COUNT(*) FROM promotion_redemptions WHERE promo_id=?) + (SELECT COUNT(*) FROM promotion_claims WHERE promo_id=?)');
        $activityQuery->execute([(int)$editPromo['promo_id'],(int)$editPromo['promo_id']]);
        $editPromo['has_activity'] = (int)$activityQuery->fetchColumn() > 0;
        $selectedQuery = $pdo->prepare('SELECT item_id FROM promotion_eligible_items WHERE promo_id=?');
        $selectedQuery->execute([(int)$editPromo['promo_id']]);
        $editPromo['selected_items'] = array_map('intval',$selectedQuery->fetchAll(PDO::FETCH_COLUMN));
        $faqQuery=$pdo->prepare('SELECT question,answer FROM promotion_faqs WHERE promo_id=? ORDER BY sort_order,faq_id');
        $faqQuery->execute([(int)$editPromo['promo_id']]);
        $editPromo['faqs']=$faqQuery->fetchAll(PDO::FETCH_ASSOC);
    }
}

function cleanPromoFaq(string $html): string {
    return strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $errors[] = 'Your session expired. Please try again.';
    if (isset($_POST['claim_voucher']) && !$errors) {
        require_customer($pdo);
        $id = (int)$_POST['claim_voucher'];
        $check = $pdo->prepare("SELECT p.promo_id FROM promotions p WHERE p.promo_id=? AND p.is_platform_voucher=1 AND p.status='active' AND NOW() BETWEEN p.starts_at AND p.ends_at AND (p.usage_limit IS NULL OR (SELECT COUNT(*) FROM promotion_redemptions r WHERE r.promo_id=p.promo_id)<p.usage_limit)");
        $check->execute([$id]);
        if (!$check->fetchColumn()) $errors[] = 'This voucher is no longer available.';
        else {
            $pdo->prepare('INSERT IGNORE INTO promotion_claims(promo_id,user_id) VALUES(?,?)')->execute([$id,(int)$_SESSION['user_id']]);
            $_SESSION['flash_toasts'][] = ['kind'=>'success','title'=>'Voucher claimed','text'=>'You can select it when placing an order.'];
            header('Location: promos.php?tab=vouchers'); exit;
        }
    }
    if (isset($_POST['save_promo']) && $isTeam && !$errors) {
        $editId = (int)($_POST['promo_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $mechanics = trim((string)($_POST['mechanics'] ?? ''));
        $promoCode = strtoupper(trim((string)($_POST['promo_code'] ?? '')));
        if ($promoCode !== '' && !preg_match('/^[A-Z0-9_-]{3,60}$/', $promoCode)) $errors[] = 'Promo codes must be 3–60 characters using letters, numbers, hyphens, or underscores.';
        $isVoucher = (string)($_POST['is_platform_voucher'] ?? '') === '1' ? 1 : 0;
        if ($isVoucher) $promoCode = '';
        $scope = (string)($_POST['eligibility_scope'] ?? 'all');
        $type = (string)($_POST['discount_type'] ?? 'percent');
        $value = max(0, (float)($_POST['discount_value'] ?? 0));
        $minimum = max(0, (float)($_POST['minimum_spend'] ?? 0));
        $maximum = ($_POST['max_discount'] ?? '') !== '' ? max(0, (float)$_POST['max_discount']) : null;
        $channel = (string)($_POST['channel'] ?? 'all');
        $startInput = (string)($_POST['starts_at'] ?? '');
        $endInput = (string)($_POST['ends_at'] ?? '');
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $startInput);
        $startDateErrors = DateTimeImmutable::getLastErrors();
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $endInput);
        $endDateErrors = DateTimeImmutable::getLastErrors();
        $validDateParts = ($startDateErrors === false || ($startDateErrors['warning_count'] === 0 && $startDateErrors['error_count'] === 0))
            && ($endDateErrors === false || ($endDateErrors['warning_count'] === 0 && $endDateErrors['error_count'] === 0));
        $validSchedule = $startDate && $endDate && $validDateParts && $endDate > $startDate;
        $start = $startDate ? $startDate->format('Y-m-d H:i:s') : '';
        $end = $endDate ? $endDate->format('Y-m-d H:i:s') : '';
        $eligibleItems = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['eligible_items'] ?? [])), static fn($id) => $id > 0)));

        if (!$validSchedule) $errors[] = 'The end date and time must be later than the start date and time.';
        if ($type === 'percent' && $value > 100) $errors[] = 'Percentage discounts cannot exceed 100%.';
        if ($name === '' || mb_strlen($name) > 180 || $description === '' || !in_array($type, ['percent','fixed','free_item'], true)
            || !in_array($scope, ['all','exclusive'], true) || !in_array($channel, ['all','cash','gcash','maya','card_pickup'], true)
            || ($scope === 'exclusive' && !$eligibleItems)) {
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
        $removeImage = isset($_POST['remove_promo_image']) && $imagePath === null;

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($editId > 0) {
                    $lock = $pdo->prepare('SELECT * FROM promotions WHERE promo_id=? FOR UPDATE');
                    $lock->execute([$editId]);
                    $existing = $lock->fetch();
                    if (!$existing) throw new RuntimeException('Offer not found.');
                    $usage = $pdo->prepare('SELECT (SELECT COUNT(*) FROM promotion_redemptions WHERE promo_id=?) + (SELECT COUNT(*) FROM promotion_claims WHERE promo_id=?)');
                    $usage->execute([$editId,$editId]);
                    $hasActivity = (int)$usage->fetchColumn() > 0;
                    if ($hasActivity) {
                        $pdo->prepare('UPDATE promotions SET name=?,description=?,mechanics=?,promo_code=?,promo_image=IF(?,NULL,COALESCE(?,promo_image)) WHERE promo_id=?')
                            ->execute([$name,$description,$mechanics ?: null,$promoCode ?: null,$removeImage?1:0,$imagePath,$editId]);
                    } else {
                        if ($isVoucher !== (int)$existing['is_platform_voucher']) throw new RuntimeException('Offer type cannot be changed after creation.');
                        $pdo->prepare('UPDATE promotions SET name=?,description=?,mechanics=?,promo_code=?,promo_image=IF(?,NULL,COALESCE(?,promo_image)),eligibility_scope=?,discount_type=?,discount_value=?,max_discount=?,minimum_spend=?,starts_at=?,ends_at=?,channel=? WHERE promo_id=?')
                            ->execute([$name,$description,$mechanics ?: null,$promoCode ?: null,$removeImage?1:0,$imagePath,$scope,$type,$value,$type === 'percent' ? $maximum : null,$minimum,$start,$end,$channel,$editId]);
                        $pdo->prepare('DELETE FROM promotion_eligible_items WHERE promo_id=?')->execute([$editId]);
                        if ($scope === 'exclusive') {
                            $validIds = $pdo->prepare('SELECT item_id FROM menu_items WHERE is_available=1 AND item_id=?');
                            $saveItem = $pdo->prepare('INSERT INTO promotion_eligible_items(promo_id,item_id) VALUES(?,?)');
                            foreach ($eligibleItems as $itemId) { $validIds->execute([$itemId]); if ($validIds->fetchColumn()) $saveItem->execute([$editId,$itemId]); }
                        }
                    }
                    $promoId = $editId;
                } else {
                    $insert = $pdo->prepare("INSERT INTO promotions(name,description,mechanics,promo_image,promo_code,is_platform_voucher,eligibility_scope,discount_type,discount_value,max_discount,minimum_spend,starts_at,ends_at,channel,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'scheduled',?)");
                    $insert->execute([$name,$description,$mechanics ?: null,$imagePath,$promoCode ?: null,$isVoucher,$scope,$type,$value,$type === 'percent' ? $maximum : null,$minimum,$start,$end,$channel,(int)$_SESSION['user_id']]);
                    $promoId = (int)$pdo->lastInsertId();
                }
                if ($editId === 0 && $scope === 'exclusive') {
                    $validIds = $pdo->prepare('SELECT item_id FROM menu_items WHERE is_available=1 AND item_id=?');
                    $saveItem = $pdo->prepare('INSERT INTO promotion_eligible_items(promo_id,item_id) VALUES(?,?)');
                    foreach ($eligibleItems as $itemId) { $validIds->execute([$itemId]); if ($validIds->fetchColumn()) $saveItem->execute([$promoId,$itemId]); }
                }
                if ($editId > 0) $pdo->prepare('DELETE FROM promotion_faqs WHERE promo_id=?')->execute([$promoId]);
                if ($faqs) {
                    $faqInsert=$pdo->prepare('INSERT INTO promotion_faqs(promo_id,question,answer,sort_order) VALUES(?,?,?,?)');
                    foreach ($faqs as $sortOrder=>$faq) $faqInsert->execute([$promoId,$faq['question'],$faq['answer'],$sortOrder]);
                }
                $pdo->commit();
                $pdo->prepare("INSERT INTO team_inbox_events(event_type,title,message,audience,entity_type,entity_id,created_by) VALUES(?,?,?, 'all','promotion',?,?)")
                    ->execute([$editId > 0 ? 'promo_updated' : 'promo_created',($editId > 0 ? 'Promo updated: ' : 'Promo created: ').$name,$name.' offer details were '.($editId > 0 ? 'updated.' : 'created and scheduled.'),$promoId,(int)$_SESSION['user_id']]);
                $pdo->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,after_data) VALUES(?,?,?,?,?)')->execute([(int)$_SESSION['user_id'],$editId>0?'promo.updated':'promo.created','promotion',$promoId,json_encode(['name'=>$name,'promo_code'=>$promoCode,'is_voucher'=>(bool)$isVoucher],JSON_UNESCAPED_UNICODE)]);
                header('Location: promos.php?tab='.($isVoucher?'vouchers':'promos').'&saved='.($editId>0?'updated':'created')); exit;
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
            $pdo->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,after_data) VALUES(?,?,?,?,?)')->execute([(int)$_SESSION['user_id'],'promo.status_changed','promotion',$promoId,json_encode(['from'=>$before['status']??null,'to'=>$status,'name'=>$before['name']??null],JSON_UNESCAPED_UNICODE)]);
            $_SESSION['flash_success']='Offer status updated.';
            if ($before && $before['status'] !== 'active' && $status === 'active' && strtotime((string)$before['starts_at']) <= time() && strtotime((string)$before['ends_at']) >= time()) {
                try {
                    $kind = (bool)$before['is_platform_voucher'] ? 'voucher' : 'promotion';
                    notify_nomcha_users('promo_notification','A new Nomcha ' . $kind . ' is available',(string)$before['name'] . ' — ' . (string)$before['description'], 'promos.php?tab=' . ((bool)$before['is_platform_voucher'] ? 'vouchers' : 'promos'));
                } catch (Throwable $mailError) { error_log('Promo notification failed: ' . $mailError->getMessage()); }
            }
        }
        header('Location: promos.php?tab='.($tab ?? (($_GET['tab']??'promos')==='vouchers'?'vouchers':'promos'))); exit;
    }
}

$tab = ($_GET['tab'] ?? 'promos') === 'vouchers' ? 'vouchers' : 'promos';
$promos = $isTeam
    ? $pdo->query('SELECT * FROM promotions ORDER BY starts_at DESC')->fetchAll()
    : (function () use ($pdo, $tab): array {
        $stmt = $pdo->prepare("SELECT * FROM promotions WHERE is_platform_voucher=? AND status='active' AND NOW() BETWEEN starts_at AND ends_at ORDER BY starts_at");
        $stmt->execute([$tab === 'vouchers' ? 1 : 0]);
        return $stmt->fetchAll();
    })();
$promoIds=array_map('intval',array_column($promos,'promo_id'));
$faqsByPromo=[];
if ($promoIds) {
    $faqMarks=implode(',',array_fill(0,count($promoIds),'?'));
    $faqRows=$pdo->prepare("SELECT promo_id,question,answer FROM promotion_faqs WHERE promo_id IN ($faqMarks) ORDER BY promo_id,sort_order,faq_id");
    $faqRows->execute($promoIds);
    foreach ($faqRows->fetchAll(PDO::FETCH_ASSOC) as $faq) $faqsByPromo[(int)$faq['promo_id']][]=$faq;
}
$eligibleItems = $pdo->query("SELECT i.item_id,i.name,i.category_id,COALESCE(c.name,'Other') AS category_name FROM menu_items i LEFT JOIN menu_categories c ON c.category_id=i.category_id WHERE i.is_available=1 ORDER BY category_name,i.name")->fetchAll();
$categories = [];
foreach ($eligibleItems as $item) $categories[$item['category_name']][] = $item;
$claims = [];
if ($role === 'customer') { $query=$pdo->prepare('SELECT promo_id FROM promotion_claims WHERE user_id=?'); $query->execute([(int)$_SESSION['user_id']]); $claims=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN)); }
$myAvailableVouchers = [];
if ($role === 'customer') {
    $query = $pdo->prepare("SELECT c.claim_id,c.claimed_at,p.name,p.description,p.discount_type,p.discount_value,p.minimum_spend,p.ends_at FROM promotion_claims c JOIN promotions p ON p.promo_id=c.promo_id WHERE c.user_id=? AND NOT EXISTS (SELECT 1 FROM promotion_redemptions r WHERE r.claim_id=c.claim_id) AND p.is_platform_voucher=1 AND p.status='active' AND NOW() BETWEEN p.starts_at AND p.ends_at AND (p.usage_limit IS NULL OR (SELECT COUNT(*) FROM promotion_redemptions r WHERE r.promo_id=p.promo_id)<p.usage_limit) ORDER BY c.claimed_at DESC");
    $query->execute([(int)$_SESSION['user_id']]);
    $myAvailableVouchers = $query->fetchAll();
}
$metrics = $isTeam ? $pdo->query('SELECT p.promo_id,COUNT(r.redemption_id) redemptions,COALESCE(SUM(r.discount_amount),0) discount_total FROM promotions p LEFT JOIN promotion_redemptions r ON r.promo_id=p.promo_id GROUP BY p.promo_id')->fetchAll(PDO::FETCH_UNIQUE) : [];
$submittedPromo = isset($_POST['save_promo']) ? $_POST : [];
$promoField = static fn(string $key, mixed $fallback = ''): string => e((string)($submittedPromo[$key] ?? $fallback));
$pageTitle = 'Vouchers & Promos';
require 'includes/header.php';
?>
<main class="container py-5">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><p class="text-uppercase small fw-semibold text-secondary mb-1">Nomcha offers</p><h1><?= $isTeam ? 'Promotions' : 'Vouchers & Promos' ?></h1><p class="text-secondary mb-0"><?= $isTeam ? 'Promos invite customers to participate. Vouchers apply discounts at checkout.' : 'Join promotions or claim vouchers for your next order.' ?></p></div><?php if ($isTeam): ?><button class="btn btn-order" data-bs-toggle="modal" data-bs-target="#promoModal" data-offer-kind="<?=$tab==='vouchers'?'voucher':'promo'?>"><i class="bi bi-plus-lg me-1"></i>Create <?=$tab==='vouchers'?'voucher':'promo'?></button><?php endif; ?></div>
 <?php $toastMessages=[]; if (isset($_GET['saved'])) $toastMessages[]=['kind'=>'success','text'=>$_GET['saved']==='updated'?'Offer updated successfully.':'Offer created and added to the team inbox.']; if(isset($_SESSION['flash_success'])){$toastMessages[]=['kind'=>'success','text'=>$_SESSION['flash_success']];unset($_SESSION['flash_success']);} foreach ($errors as $error) $toastMessages[]=['kind'=>'danger','text'=>$error]; render_toasts($toastMessages); ?>
 <ul class="nav nav-pills mb-4"><li class="nav-item"><a class="nav-link <?=$tab==='promos'?'active':''?>" href="?tab=promos">Promos</a></li><li class="nav-item"><a class="nav-link <?=$tab==='vouchers'?'active':''?>" href="?tab=vouchers">Vouchers</a></li></ul>
 <?php if ($tab === 'vouchers' && $role === 'customer'): ?><section class="form-panel mb-4"><h2 class="h4">My available vouchers</h2><?php if ($myAvailableVouchers): ?><div class="row g-3"><?php foreach ($myAvailableVouchers as $voucher): ?><div class="col-md-6"><div class="border rounded p-3 h-100"><div class="d-flex justify-content-between gap-2"><strong><?=e($voucher['name'])?></strong><span class="badge text-bg-success">Unused</span></div><p class="small text-secondary mt-2 mb-2"><?=e($voucher['description'])?></p><p class="small mb-2"><?=e($voucher['discount_type']==='percent'?$voucher['discount_value'].'% off':($voucher['discount_type']==='fixed'?'₱'.number_format((float)$voucher['discount_value'],2).' off':'Free item'))?><?php if ((float)$voucher['minimum_spend']>0): ?> · ₱<?=number_format((float)$voucher['minimum_spend'],2)?> minimum<?php endif; ?></p><p class="small text-secondary mb-0">Expires <?=date('M j, Y',strtotime($voucher['ends_at']))?> · Choose it at checkout.</p></div></div><?php endforeach; ?></div><?php else: ?><p class="text-secondary mb-0">You have no available unused vouchers. Claim one below to add it here.</p><?php endif; ?></section><?php endif; ?>
 <?php $matchingPromos=array_values(array_filter($promos,static fn($promo)=>(($tab==='vouchers')===(bool)$promo['is_platform_voucher']))); ?>
 <?php if ($matchingPromos): ?><section class="form-panel py-3 mb-4"><div class="row g-3 align-items-end"><div class="col-lg-6"><label class="form-label" for="promo-search">Search offers</label><input class="form-control" id="promo-search" type="search" placeholder="Name, description, terms, or code" autocomplete="off"></div><?php if($isTeam): ?><div class="col-sm-6 col-lg-3"><label class="form-label" for="promo-status-filter">Status</label><select class="form-select" id="promo-status-filter"><option value="">All statuses</option><?php foreach(['draft','scheduled','active','paused','ended'] as $status): ?><option value="<?=$status?>"><?=ucfirst($status)?></option><?php endforeach; ?></select></div><?php endif; ?><div class="col-sm-6 col-lg-3"><label class="form-label" for="promo-discount-filter">Discount type</label><select class="form-select" id="promo-discount-filter"><option value="">All types</option><option value="percent">Percentage</option><option value="fixed">Fixed amount</option><option value="free_item">Free item</option></select></div><div class="col-12 d-flex justify-content-between"><span class="small text-secondary" id="promo-result-count" aria-live="polite"></span><button class="btn btn-sm btn-outline-secondary" id="promo-filter-reset" type="button">Clear filters</button></div></div></section><?php endif; ?>
 <div class="row g-4" id="promo-results">
<?php $shown=0; foreach ($matchingPromos as $promo): $faqs=$faqsByPromo[(int)$promo['promo_id']]??[]; $searchText=mb_strtolower(implode(' ',[(string)$promo['name'],(string)$promo['description'],(string)($promo['mechanics']??''),(string)($promo['promo_code']??'')])); ?>
  <?php $shown++; ?><div class="col-md-6 promo-result" data-search="<?=e($searchText)?>" data-status="<?=e((string)$promo['status'])?>" data-discount="<?=e((string)$promo['discount_type'])?>"><article class="form-panel h-100 promo-card">
   <?php if (!empty($promo['promo_image'])): ?><img class="promo-card-image" src="<?=e($promo['promo_image'])?>" alt="<?=e($promo['name'])?>"><?php endif; ?>
   <div class="d-flex justify-content-between gap-2"><h2 class="h4"><?=e($promo['name'])?></h2><?php if($isTeam): $statusClass=['draft'=>'text-bg-secondary','scheduled'=>'text-bg-info','active'=>'text-bg-success','paused'=>'text-bg-warning','ended'=>'text-bg-dark'][$promo['status']]??'text-bg-secondary'; ?><span class="badge <?=$statusClass?> align-self-start"><?=e(ucfirst((string)$promo['status']))?></span><?php endif;?></div>
   <p><?=nl2br(e($promo['description']))?></p>
   <?php if ($tab==='promos'): ?><p class="badge text-bg-info">Participation promo</p><?php else: ?><p class="badge text-bg-success">Checkout voucher</p><ul class="small text-secondary"><li><?=e($promo['discount_type']==='percent'?$promo['discount_value'].'% off':($promo['discount_type']==='fixed'?'₱'.number_format((float)$promo['discount_value'],2).' off':'Free item'))?></li><?php if((float)$promo['minimum_spend']>0):?><li>Minimum spend: ₱<?=number_format((float)$promo['minimum_spend'],2)?></li><?php endif;?><?php if($promo['max_discount']):?><li>Maximum discount: ₱<?=number_format((float)$promo['max_discount'],2)?></li><?php endif;?></ul><?php if(!empty($promo['promo_code'])):?><div class="promo-code mb-3">Code: <strong><?=e($promo['promo_code'])?></strong></div><?php endif; ?><a class="btn btn-sm btn-order mb-3" href="order.php">Order and use this offer</a><?php endif; ?>
   <?php if ($promo['mechanics']): ?><div class="small mb-3"><strong>Mechanics</strong><p class="mb-0"><?=nl2br(e($promo['mechanics']))?></p></div><?php endif; ?>
   <p class="small text-secondary">Valid <?=date('M j, Y',strtotime($promo['starts_at']))?> – <?=date('M j, Y',strtotime($promo['ends_at']))?></p>
   <?php foreach($faqs as $faq): ?><details class="mb-2"><summary><?=e((string)$faq['question'])?></summary><div class="mt-2"><?=$faq['answer']?></div></details><?php endforeach; ?>
   <?php if(!$isTeam && $tab==='vouchers'): if(in_array((int)$promo['promo_id'],$claims,true)): ?><span class="badge text-bg-success">Claimed</span><?php elseif($role==='customer'): ?><form method="post" class="mt-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="claim_voucher" value="<?=(int)$promo['promo_id']?>"><button class="btn btn-order">Claim voucher</button></form><?php else: ?><a class="btn btn-order mt-3" href="login.php?redirect=<?=rawurlencode('promos.php?tab=vouchers')?>">Log in to claim</a><?php endif; endif; ?>
  <?php if ($isTeam): ?><div class="d-flex justify-content-between small text-secondary"><span><?=number_format((int)($metrics[$promo['promo_id']]['redemptions']??0))?> redemptions</span><span>₱<?=number_format((float)($metrics[$promo['promo_id']]['discount_total']??0),2)?> discount</span></div><div class="d-flex gap-2 mt-3"><a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center justify-content-center text-center" href="promos.php?tab=<?=$tab?>&amp;edit=<?=(int)$promo['promo_id']?>">Edit offer</a><form method="post" class="d-flex gap-2 flex-grow-1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="promo_id" value="<?=(int)$promo['promo_id']?>"><select class="form-select form-select-sm" name="promo_status"><?php foreach(['draft','scheduled','active','paused','ended'] as $status):?><option <?=$promo['status']===$status?'selected':''?>><?=$status?></option><?php endforeach;?></select><button class="btn btn-sm btn-outline-secondary">Update</button></form></div><?php endif; ?>
  </article></div>
 <?php endforeach; if(!$shown): ?><div class="col-12"><div class="form-panel text-center py-5"><i class="bi bi-tag fs-1 text-secondary"></i><h2 class="h4 mt-3">No <?=e($tab)?> available</h2><p class="text-secondary mb-0">Check back soon for new offers.</p></div></div><?php else: ?><div class="col-12 text-center py-5" id="promo-no-results" hidden><div class="form-panel"><h2 class="h5">No matching offers</h2><p class="text-secondary mb-0">Try a different search or clear a filter.</p></div></div><?php endif; ?>
 </div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const cards=[...document.querySelectorAll('.promo-result')];
 const search=document.getElementById('promo-search');
 const status=document.getElementById('promo-status-filter');
 const discount=document.getElementById('promo-discount-filter');
 const empty=document.getElementById('promo-no-results');
 const count=document.getElementById('promo-result-count');
 const applyFilters=()=>{const query=(search?.value||'').trim().toLowerCase();let visible=0;cards.forEach(card=>{const matches=(!query||(card.dataset.search||'').toLowerCase().includes(query))&&(!status?.value||card.dataset.status===status.value)&&(!discount?.value||card.dataset.discount===discount.value);card.classList.toggle('d-none',!matches);if(matches)visible++;});if(empty)empty.hidden=visible!==0;if(count)count.textContent=`Showing ${visible} of ${cards.length} offers`;};
 search?.addEventListener('input',applyFilters);status?.addEventListener('change',applyFilters);discount?.addEventListener('change',applyFilters);document.getElementById('promo-filter-reset')?.addEventListener('click',()=>{if(search)search.value='';if(status)status.value='';if(discount)discount.value='';applyFilters();});applyFilters();
});
</script>
<?php if ($isTeam): ?>
<div class="modal fade" id="promoModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content order-options-modal"><form method="post" enctype="multipart/form-data"><div class="modal-header"><h2 class="modal-title h5"><?=$editPromo?'Edit offer':'Create an offer'?></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
 <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="save_promo" value="1"><input type="hidden" name="promo_id" value="<?=(int)($editPromo['promo_id']??0)?>">
 <input type="hidden" name="is_platform_voucher" id="is-platform-voucher" value="<?=$editPromo&&(int)$editPromo['is_platform_voucher']?'1':''?>">
 <?php if($editPromo&&!empty($editPromo['promo_image'])): ?><div class="current-promo-image mb-3"><img src="<?=e($editPromo['promo_image'])?>" alt="Current offer image"><label class="form-check-label"><input class="form-check-input me-2" type="checkbox" name="remove_promo_image" value="1">Remove current image</label></div><?php endif; ?><div class="mb-3"><label class="form-label" for="promo-image">Promo image</label><input class="form-control" id="promo-image" type="file" name="promo_image" accept="image/jpeg,image/png,image/webp"><div class="form-text">Square image recommended. JPG, PNG, or WebP up to 5 MB. Leave blank to keep current image.</div></div>
 <div class="mb-3"><label class="form-label" for="promo-name">Offer name</label><input class="form-control" id="promo-name" name="name" maxlength="180" required value="<?=$promoField('name',$editPromo['name']??'')?>"></div>
 <div class="mb-3"><label class="form-label" for="promo-description">Customer description</label><textarea class="form-control" id="promo-description" name="description" rows="3" required placeholder="For a promo, explain how to join. For a voucher, describe the discount."><?=$promoField('description',$editPromo['description']??'')?></textarea></div>
 <div class="mb-3 promo-code-field"><label class="form-label" for="promo-code-field">Promo code <span class="text-secondary">(optional)</span></label><input class="form-control text-uppercase" id="promo-code-field" name="promo_code" maxlength="60" value="<?=e((string)($editPromo['promo_code']??''))?>" placeholder="NOMCHARAP"><div class="form-text">Customers can enter this code at checkout. Leave blank for offers without a code.</div></div>
 <div class="mb-3"><label class="form-label" for="promo-mechanics">Mechanics and terms</label><textarea class="form-control" id="promo-mechanics" name="mechanics" rows="4" placeholder="P75 off for minimum spend of P550 when you use the code…&#10;Valid for hotline, e-commerce and Grab transactions&#10;Promo duration and exclusions"><?=e((string)($editPromo['mechanics']??''))?></textarea></div>
 <div class="row"><div class="col-md-6 mb-3"><label class="form-label" for="eligibility-scope">Eligible products</label><select class="form-select" name="eligibility_scope" id="eligibility-scope" <?=$editPromo&&$editPromo['has_activity']?'disabled':''?>><option value="all" <?=(!$editPromo||$editPromo['eligibility_scope']==='all')?'selected':''?>>All products</option><option value="exclusive" <?=($editPromo&&$editPromo['eligibility_scope']==='exclusive')?'selected':''?>>Selected products only</option></select></div><div class="col-md-6 mb-3" id="discount-fields"><label class="form-label" for="discount-type">Discount type</label><select class="form-select" name="discount_type" id="discount-type" <?=$editPromo&&$editPromo['has_activity']?'disabled':''?>><option value="percent" <?=(!$editPromo||$editPromo['discount_type']==='percent')?'selected':''?>>Percentage</option><option value="fixed" <?=($editPromo&&$editPromo['discount_type']==='fixed')?'selected':''?>>Fixed amount</option><option value="free_item" <?=($editPromo&&$editPromo['discount_type']==='free_item')?'selected':''?>>Free item</option></select></div>
 <div class="col-md-6 mb-3 discount-field discount-value-field"><label class="form-label" id="discount-value-label">Discount value</label><input class="form-control" id="discount-value" type="number" min="0" step="0.01" name="discount_value" value="<?=e((string)($editPromo['discount_value']??'0'))?>" <?=$editPromo&&$editPromo['has_activity']?'readonly':''?>><div class="form-text">Percentage offers use a percent value. Fixed offers use pesos.</div></div><div class="col-md-6 mb-3 discount-field maximum-discount-field"><label class="form-label">Maximum discount cap (₱)</label><input class="form-control" type="number" min="0" step="0.01" name="max_discount" value="<?=e((string)($editPromo['max_discount']??'0'))?>" <?=$editPromo&&$editPromo['has_activity']?'readonly':''?>><div class="form-text">Applies only to percentage discounts.</div></div><div class="col-md-6 mb-3 discount-field minimum-spend-field"><label class="form-label">Minimum order spend (₱)</label><input class="form-control" type="number" min="0" step="0.01" name="minimum_spend" value="<?=e((string)($editPromo['minimum_spend']??'0'))?>" <?=$editPromo&&$editPromo['has_activity']?'readonly':''?>><div class="form-text">The customer’s order subtotal must meet this amount.</div></div>
 <div class="col-md-6 mb-3"><label class="form-label" for="promo-start">Starts</label><input class="form-control" id="promo-start" type="datetime-local" name="starts_at" required value="<?=$editPromo?e(date('Y-m-d\TH:i',strtotime((string)$editPromo['starts_at']))):''?>" <?=$editPromo&&$editPromo['has_activity']?'readonly':''?>></div><div class="col-md-6 mb-3"><label class="form-label" for="promo-end">Ends</label><input class="form-control" id="promo-end" type="datetime-local" name="ends_at" required value="<?=$editPromo?e(date('Y-m-d\TH:i',strtotime((string)$editPromo['ends_at']))):''?>" <?=$editPromo&&$editPromo['has_activity']?'readonly':''?>></div><div class="col-md-6 mb-3"><label class="form-label">Payment channel</label><select class="form-select" name="channel" <?=$editPromo&&$editPromo['has_activity']?'disabled':''?>><option value="all" <?=(!$editPromo||$editPromo['channel']==='all')?'selected':''?>>All payment methods</option><option value="cash" <?=($editPromo&&$editPromo['channel']==='cash')?'selected':''?>>Cash</option><option value="gcash" <?=($editPromo&&$editPromo['channel']==='gcash')?'selected':''?>>GCash</option><option value="maya" <?=($editPromo&&$editPromo['channel']==='maya')?'selected':''?>>Maya</option><option value="card_pickup" <?=($editPromo&&$editPromo['channel']==='card_pickup')?'selected':''?>>Card at pickup</option></select></div></div>
 <?php if($editPromo&&$editPromo['has_activity']): ?><div class="alert alert-info">This offer has claims or redemptions. Discount terms, eligible products, dates, and payment channel are locked to preserve customer history.</div><?php endif; ?>
 <div class="mb-3" id="eligible-products-wrap" <?=$editPromo&&$editPromo['eligibility_scope']==='exclusive'?'':'hidden'?>><label class="form-label">Select eligible products</label><input type="search" id="product-search" class="form-control mb-3" placeholder="Filter products"><div class="d-flex flex-wrap gap-2 mb-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-product-select="all">Select all shown</button><button class="btn btn-sm btn-outline-secondary" type="button" data-product-select="none">Clear shown</button></div>
 <?php foreach($categories as $categoryName=>$items): ?><fieldset class="border rounded p-3 mb-2 product-category" data-category="<?=e(mb_strtolower($categoryName))?>"><legend class="float-none w-auto px-2 fs-6"><label><input type="checkbox" data-category-check> <?=e($categoryName)?></label></legend><?php foreach($items as $item): ?><label class="d-flex align-items-center gap-2 mb-1 product-choice" data-product="<?=e(mb_strtolower($item['name']))?>"><input type="checkbox" name="eligible_items[]" value="<?=(int)$item['item_id']?>" <?=($editPromo&&in_array((int)$item['item_id'],$editPromo['selected_items'],true))?'checked':''?> <?=$editPromo&&$editPromo['has_activity']?'disabled':''?>> <?=e($item['name'])?></label><?php endforeach; ?></fieldset><?php endforeach; ?></div>
 <section class="border-top pt-3"><div class="d-flex justify-content-between align-items-center mb-2"><h3 class="h6 mb-0">Frequently asked questions</h3><button class="btn btn-sm btn-outline-secondary" type="button" id="add-faq">Add question</button></div><div id="faq-list"><?php foreach(($editPromo['faqs']??[]) as $faq): ?><div class="faq-entry border rounded p-3 mb-3"><div class="d-flex justify-content-between align-items-center"><label class="form-label">Question</label><button class="btn btn-sm btn-outline-danger" type="button" data-remove-faq>Remove</button></div><input class="form-control mb-2" name="faq_question[]" maxlength="500" value="<?=e((string)($faq['question']??''))?>"><label class="form-label">Answer</label><div class="btn-toolbar mb-2" role="toolbar" aria-label="FAQ formatting"><div class="btn-group btn-group-sm" role="group"><button class="btn btn-outline-secondary" type="button" data-faq-command="bold"><b>B</b></button><button class="btn btn-outline-secondary" type="button" data-faq-command="italic"><i>I</i></button><button class="btn btn-outline-secondary" type="button" data-faq-command="underline"><u>U</u></button><button class="btn btn-outline-secondary" type="button" data-faq-command="insertUnorderedList">• List</button></div></div><div class="form-control faq-editor" contenteditable="true" style="min-height:90px" role="textbox" aria-multiline="true"><?=cleanPromoFaq((string)($faq['answer']??''))?></div><input type="hidden" name="faq_answer[]" class="faq-answer" value=""></div><?php endforeach; ?></div></section>
 </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-order">Save offer</button></div></form></div></div></div>
<template id="faq-template"><div class="faq-entry border rounded p-3 mb-3"><div class="d-flex justify-content-between align-items-center"><label class="form-label">Question</label><button class="btn btn-sm btn-outline-danger" type="button" data-remove-faq>Remove</button></div><input class="form-control mb-2" name="faq_question[]" maxlength="500" placeholder="What should customers know?"><label class="form-label">Answer</label><div class="btn-toolbar mb-2" role="toolbar" aria-label="FAQ formatting"><div class="btn-group btn-group-sm" role="group"><button class="btn btn-outline-secondary" type="button" data-faq-command="bold"><b>B</b></button><button class="btn btn-outline-secondary" type="button" data-faq-command="italic"><i>I</i></button><button class="btn btn-outline-secondary" type="button" data-faq-command="underline"><u>U</u></button><button class="btn btn-outline-secondary" type="button" data-faq-command="insertUnorderedList">• List</button></div></div><div class="form-control faq-editor" contenteditable="true" style="min-height:90px" role="textbox" aria-multiline="true"></div><input type="hidden" name="faq_answer[]" class="faq-answer"></div></template>
<div class="modal fade" id="promoConfirmModal" tabindex="-1" aria-labelledby="promoConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="promoConfirmTitle">Confirm update</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button></div><div class="modal-body" id="promoConfirmMessage"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-auth" id="promoConfirmSubmit">Confirm</button></div></div></div></div>
<style>.promo-card{min-width:0;overflow:hidden}.promo-card-image{float:left;width:min(45%,300px);max-width:100%;aspect-ratio:1;object-fit:cover;border-radius:14px;margin:0 1rem .75rem 0}.promo-card::after{content:"";display:block;clear:both}.current-promo-image{display:flex;flex-direction:column;align-items:flex-start;gap:.65rem;max-width:100%;padding:.75rem;border:1px solid #dee2e6;border-radius:.5rem}.current-promo-image img{display:block;width:min(100%,320px);max-height:320px;object-fit:contain;border-radius:.4rem}.current-promo-image label{margin-left:auto}.faq-editor:focus{border-color:#86b7fe;outline:0;box-shadow:0 0 0 .25rem #0d6efd40}.product-category legend{font-weight:600}@media(max-width:575.98px){.promo-card-image{float:none;display:block;width:min(100%,340px);margin:0 auto 1rem}}</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.querySelector('#promoModal form'),voucher=document.getElementById('is-platform-voucher'),scope=document.getElementById('eligibility-scope'),productWrap=document.getElementById('eligible-products-wrap'),discountType=document.getElementById('discount-type'),discountValue=document.getElementById('discount-value');
 const codeWrap=document.querySelector('.promo-code-field');
 const syncKind=isVoucher=>{voucher.value=isVoucher?'1':'';if(codeWrap)codeWrap.hidden=isVoucher;};
 const syncDiscount=()=>{const type=discountType.value;document.querySelector('.discount-value-field').hidden=type==='free_item';document.querySelector('.maximum-discount-field').hidden=type!=='percent';document.getElementById('discount-value-label').textContent=type==='percent'?'Discount value (%)':type==='fixed'?'Discount value (₱)':'Discount value';discountValue.max=type==='percent'?'100':'';};
 const confirmModal=new bootstrap.Modal(document.getElementById('promoConfirmModal'));let pendingForm=null;
 const validatePromo=()=>{
  form.querySelectorAll('.is-invalid').forEach(field=>field.classList.remove('is-invalid'));
  let firstInvalid=null;
  form.querySelectorAll('[required]').forEach(field=>{if(!field.disabled&&!field.value.trim()){field.classList.add('is-invalid');firstInvalid??=field;}});
  const start=document.getElementById('promo-start'),end=document.getElementById('promo-end');
  const scheduleValid=start.value&&end.value&&Number.isFinite(new Date(start.value).getTime())&&Number.isFinite(new Date(end.value).getTime())&&new Date(end.value)>new Date(start.value);
  end.setCustomValidity(scheduleValid?'':'The end date and time must be later than the start date and time.');
  if(!scheduleValid){end.classList.add('is-invalid');firstInvalid??=end;}
  if(scope.value==='exclusive'&&!form.querySelector('[name="eligible_items[]"]:checked')){productWrap.classList.add('border','border-danger','rounded');firstInvalid??=productWrap;}else productWrap.classList.remove('border','border-danger','rounded');
  if(firstInvalid){firstInvalid.scrollIntoView({behavior:'smooth',block:'center'});firstInvalid.focus?.();return false;}return true;
 };
 form.querySelectorAll('input,textarea,select').forEach(field=>field.addEventListener('input',()=>field.classList.remove('is-invalid')));
 const askBeforeSubmit=(targetForm,messageForForm)=>targetForm?.addEventListener('submit',event=>{if(targetForm.dataset.confirmed==='1'){targetForm.dataset.confirmed='';return;}event.preventDefault();if(targetForm===form&&!validatePromo())return;pendingForm=targetForm;document.getElementById('promoConfirmMessage').textContent=messageForForm();confirmModal.show();});
 askBeforeSubmit(form,()=> 'Save these changes to this offer?');
 document.querySelectorAll('form select[name="promo_status"]').forEach(select=>askBeforeSubmit(select.closest('form'),()=>`Change this offer status to ${select.options[select.selectedIndex].text}?`));
 document.getElementById('promoConfirmSubmit').addEventListener('click',()=>{if(!pendingForm)return;const formToSubmit=pendingForm;pendingForm=null;formToSubmit.dataset.confirmed='1';confirmModal.hide();formToSubmit.requestSubmit();});
 document.querySelector('[data-bs-target="#promoModal"][data-offer-kind]')?.addEventListener('click',event=>{
  form.reset();
  form.querySelector('[name="promo_id"]').value='0';
  document.querySelector('#promoModal .modal-title').textContent='Create '+(event.currentTarget.dataset.offerKind==='voucher'?'voucher':'promo');
  faqList.replaceChildren();
  faqList.append(template.content.cloneNode(true));
  document.querySelectorAll('.product-choice input').forEach(box=>box.checked=false);
  document.querySelectorAll('[data-category-check]').forEach(box=>box.checked=false);
  scope.value='all'; productWrap.hidden=true;
  document.getElementById('product-search').value='';
  document.querySelectorAll('.product-choice,.product-category').forEach(el=>el.hidden=false);
  syncKind(event.currentTarget.dataset.offerKind==='voucher');
  syncDiscount();
 });
 syncKind(voucher.value==='1');
 syncDiscount();
 discountType.addEventListener('change',syncDiscount);
 scope.addEventListener('change',()=>productWrap.hidden=scope.value!=='exclusive');
 const faqList=document.getElementById('faq-list'),template=document.getElementById('faq-template');
 function addFaq(){faqList.append(template.content.cloneNode(true));} if(!faqList.children.length)addFaq();
 document.getElementById('add-faq').addEventListener('click',addFaq);
 form.addEventListener('mousedown',event=>{if(event.target.closest('[data-faq-command]'))event.preventDefault();});
 form.addEventListener('click',event=>{const remove=event.target.closest('[data-remove-faq]');if(remove){remove.closest('.faq-entry').remove();return;}const command=event.target.closest('[data-faq-command]');if(command){const entry=command.closest('.faq-entry'),editor=entry.querySelector('.faq-editor');editor.focus();document.execCommand(command.dataset.faqCommand,false,null);}});
 form.addEventListener('submit',()=>form.querySelectorAll('.faq-entry').forEach(entry=>entry.querySelector('.faq-answer').value=entry.querySelector('.faq-editor').innerHTML));
 const search=document.getElementById('product-search'); search.addEventListener('input',()=>{const term=search.value.trim().toLowerCase();document.querySelectorAll('.product-choice').forEach(row=>row.hidden=!row.dataset.product.includes(term));document.querySelectorAll('.product-category').forEach(group=>group.hidden=!group.querySelector('.product-choice:not([hidden])'));});
 document.querySelectorAll('[data-category-check]').forEach(master=>master.addEventListener('change',()=>master.closest('fieldset').querySelectorAll('.product-choice:not([hidden]) input').forEach(box=>box.checked=master.checked)));
 document.querySelectorAll('[data-product-select]').forEach(button=>button.addEventListener('click',()=>document.querySelectorAll('.product-choice:not([hidden]) input').forEach(box=>box.checked=button.dataset.productSelect==='all')));
 <?php if($editPromo): ?>
 bootstrap.Modal.getOrCreateInstance(document.getElementById('promoModal')).show();
 if(window.history.replaceState){const cleanUrl=new URL(window.location.href);cleanUrl.searchParams.delete('edit');window.history.replaceState({},'',cleanUrl);}
 <?php endif; ?>
 <?php if($errors && isset($_POST['save_promo'])): ?>
 const submittedPromo = <?=json_encode($submittedPromo, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 Object.entries(submittedPromo).forEach(([name,value])=>{const fields=[...form.elements].filter(field=>field.name===name);fields.forEach(field=>{if(field.type==='file')return;if(field.type==='checkbox')field.checked=Array.isArray(value)?value.map(String).includes(field.value):Boolean(value);else if(!field.disabled)field.value=Array.isArray(value)?value[0]??'':value;});});
 scope.dispatchEvent(new Event('change'));
 syncKind(voucher.value==='1');
 bootstrap.Modal.getOrCreateInstance(document.getElementById('promoModal')).show();
 validatePromo();
 <?php endif; ?>
});
</script>
<?php endif; require 'includes/footer.php'; ?>
