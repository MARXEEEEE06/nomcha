<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php?redirect=' . rawurlencode('billing-address.php')); exit; }
$userId = (int)$_SESSION['user_id'];
$errors = [];
$old = ['address_id'=>'','recipient_name'=>'','mobile_no'=>'','street_address'=>'','province'=>'','municipality'=>'','barangay'=>'','zip_code'=>'','latitude'=>'','longitude'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $errors[] = 'Session expired. Please try again.';
    elseif (($_POST['action'] ?? '') === 'delete') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM user_addresses WHERE user_id = ? AND address_id = ? AND is_default = 1');
        $stmt->execute([$userId, (int)($_POST['address_id'] ?? 0)]);
        if ($stmt->rowCount()) {
            $next = $pdo->prepare('SELECT address_id FROM user_addresses WHERE user_id = ? ORDER BY address_id DESC LIMIT 1');
            $next->execute([$userId]);
            if ($nextId = $next->fetchColumn()) $pdo->prepare('UPDATE user_addresses SET is_default = 1 WHERE user_id = ? AND address_id = ?')->execute([$userId, $nextId]);
        } else {
            $pdo->prepare('DELETE FROM user_addresses WHERE user_id = ? AND address_id = ?')->execute([$userId, (int)($_POST['address_id'] ?? 0)]);
        }
        $pdo->commit();
        $_SESSION['flash_success'] = 'Address deleted.';
        header('Location: billing-address.php'); exit;
    } elseif (($_POST['action'] ?? '') === 'default') {
        $id = (int)($_POST['address_id'] ?? 0);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE user_addresses SET is_default = 0 WHERE user_id = ?')->execute([$userId]);
        $stmt = $pdo->prepare('UPDATE user_addresses SET is_default = 1 WHERE user_id = ? AND address_id = ?'); $stmt->execute([$userId, $id]);
        if ($stmt->rowCount()) { $pdo->commit(); $_SESSION['flash_success']='Default billing address updated.'; header('Location: billing-address.php'); exit; }
        $pdo->rollBack(); $errors[]='Address not found.';
    } else {
        foreach ($old as $key => $_) $old[$key] = trim((string)($_POST[$key] ?? ''));
        foreach (['recipient_name'=>'Name','street_address'=>'Address','province'=>'City / Province','municipality'=>'Municipality','barangay'=>'Barangay'] as $key=>$label) if (mb_strlen($old[$key]) < 2 || mb_strlen($old[$key]) > ($key === 'street_address' ? 255 : 120)) $errors[]="Enter a valid {$label}.";
        if (!preg_match('/^\d{4}$/', $old['zip_code'])) $errors[]='Enter a 4-digit ZIP code.';
        if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $old['mobile_no'])) $errors[]='Enter a Philippine mobile number.';
        $lat=filter_var($old['latitude'],FILTER_VALIDATE_FLOAT); $lng=filter_var($old['longitude'],FILTER_VALIDATE_FLOAT);
        if ($lat===false || $lat < -90 || $lat > 90 || $lng===false || $lng < -180 || $lng > 180) $errors[]='Choose a map pin location.';
        if (!$errors) {
            $pdo->beginTransaction();
            if ($old['address_id'] !== '') {
                $stmt = $pdo->prepare('SELECT is_default FROM user_addresses WHERE user_id = ? AND address_id = ? FOR UPDATE');
                $stmt->execute([$userId, (int)$old['address_id']]);
                $existing = $stmt->fetch();
                if (!$existing) { $pdo->rollBack(); $errors[] = 'Address not found.'; }
                else {
                    $pdo->prepare('UPDATE user_addresses SET recipient_name = ?, mobile_no = ?, street_address = ?, province = ?, municipality = ?, barangay = ?, zip_code = ?, latitude = ?, longitude = ? WHERE user_id = ? AND address_id = ?')
                        ->execute([$old['recipient_name'],$old['mobile_no'],$old['street_address'],$old['province'],$old['municipality'],$old['barangay'],$old['zip_code'],$lat,$lng,$userId,(int)$old['address_id']]);
                    $pdo->commit(); $_SESSION['flash_success']='Address updated.'; header('Location: billing-address.php'); exit;
                }
            } else {
                $hasDefault = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = ? AND is_default = 1');
                $hasDefault->execute([$userId]);
                $makeDefault = (int)$hasDefault->fetchColumn() === 0 ? 1 : 0;
                $pdo->prepare('INSERT INTO user_addresses (user_id, recipient_name, mobile_no, street_address, province, municipality, barangay, zip_code, latitude, longitude, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$userId,$old['recipient_name'],$old['mobile_no'],$old['street_address'],$old['province'],$old['municipality'],$old['barangay'],$old['zip_code'],$lat,$lng,$makeDefault]);
                $pdo->commit(); $_SESSION['flash_success']=$makeDefault ? 'Address saved as your default.' : 'Address saved.'; header('Location: billing-address.php'); exit;
            }
        }
    }
}
$stmt=$pdo->prepare('SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC, address_id DESC'); $stmt->execute([$userId]); $addresses=$stmt->fetchAll();
// Repair any older duplicate-default state, keeping the most recently created address selected.
$defaults = array_values(array_filter($addresses, static fn(array $row): bool => (int)$row['is_default'] === 1));
if (count($defaults) > 1) {
    $keepId = (int)$defaults[0]['address_id'];
    $pdo->prepare('UPDATE user_addresses SET is_default = CASE WHEN address_id = ? THEN 1 ELSE 0 END WHERE user_id = ?')->execute([$keepId, $userId]);
    foreach ($addresses as &$addressRow) $addressRow['is_default'] = (int)$addressRow['address_id'] === $keepId ? 1 : 0;
    unset($addressRow);
}
$editAddress = null;
if (isset($_GET['edit'])) foreach ($addresses as $candidate) if ((int)$candidate['address_id'] === (int)$_GET['edit']) { $editAddress=$candidate; $old=array_merge($old,$candidate); $old['address_id']=(string)$candidate['address_id']; break; }
$flash=$_SESSION['flash_success']??null; unset($_SESSION['flash_success']);
$pageTitle='Billing addresses'; $pageStyles='<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'; $pageScripts='<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/js/address-map.js"></script><script src="assets/js/billing-address.js"></script>';
require 'includes/header.php';
?>
<main class="container auth-wrap py-5"><div class="card auth-card col-lg-9 mx-auto"><div class="card-body p-4 p-md-5">
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h2 mb-1">Billing addresses</h1><p class="text-secondary mb-0">Choose the address to use by default.</p></div><button class="btn btn-auth" id="new-address-button" type="button" aria-expanded="<?= $editAddress ? 'true' : 'false' ?>">New address</button></div>
<?php if($flash): ?><div class="alert alert-success"><?=e($flash)?></div><?php endif; ?><?php foreach($errors as $error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endforeach; ?>
<?php if(!$addresses): ?><p class="text-secondary">You haven’t saved an address yet.</p><?php endif; ?>
<?php foreach($addresses as $address): ?><article class="saved-address-card <?= $address['is_default'] ? 'is-default':'' ?> border rounded p-3 mb-3"><div class="d-flex flex-wrap align-items-start justify-content-between gap-3"><div><strong>Name:</strong> <?=e($address['recipient_name'])?><?php if($address['is_default']): ?> <span class="badge text-bg-success">Default</span><?php endif; ?><p class="mb-1 mt-2"><strong>Address:</strong> <?=e(implode(', ',array_filter([$address['street_address'],$address['barangay'],$address['municipality'],$address['province'],$address['zip_code']])))?></p><p class="mb-0 small"><strong>Mobile number:</strong> <?=e($address['mobile_no'])?></p><?php if(trim((string)$address['street_address'])==='' || !preg_match('/^\d{4}$/',(string)$address['zip_code'])): ?><small class="text-danger">Add the street address and ZIP code to enable delivery.</small><?php endif; ?></div><div class="d-flex flex-wrap align-items-start gap-2"><a class="btn btn-outline-secondary" href="billing-address.php?edit=<?=(int)$address['address_id']?>#new-address">Edit</a><?php if(!$address['is_default']): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="default"><input type="hidden" name="address_id" value="<?=(int)$address['address_id']?>"><button class="btn btn-outline-secondary">Set as default</button></form><?php endif; ?><form method="post" class="address-delete-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="address_id" value="<?=(int)$address['address_id']?>"><button type="button" class="btn btn-outline-danger" data-delete-address="<?=e($address['recipient_name'])?>">Delete</button></form></div></div></article><?php endforeach; ?>
<div class="collapse <?= ($errors || $editAddress) ? 'show':'' ?> mt-4" id="new-address"><div class="border rounded p-3"><h2 class="h5"><?= $editAddress ? 'Edit billing address':'Add a billing address' ?></h2><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="<?= $editAddress ? 'edit':'add' ?>"><input type="hidden" name="address_id" value="<?=e($old['address_id'])?>">
<?php
$addressFields = [
    'recipient_name' => ['Name', 'Juan Dela Cruz', 120, 'text', 'autocomplete="name"'],
    'mobile_no' => ['Mobile number', '09XXXXXXXXX', 13, 'tel', 'inputmode="tel" pattern="(09[0-9]{9}|\+639[0-9]{9})"'],
    'street_address' => ['Address', '123 Street, Camella Subdiv., 2nd Floor ABC Bldg.', 255, 'text', 'autocomplete="address-line1"'],
    'province' => ['City / Province', 'Metro Manila', 120, 'text', ''],
    'municipality' => ['Municipality', 'Quezon City', 120, 'text', ''],
    'barangay' => ['Barangay', 'Bago Bantay', 120, 'text', ''],
    'zip_code' => ['ZIP code', '1105', 4, 'text', 'inputmode="numeric" pattern="[0-9]{4}" autocomplete="postal-code"'],
];
foreach ($addressFields as $key => [$label, $example, $maxLength, $type, $extra]):
?><label class="form-label" for="<?=$key?>"><?=$label?></label><input class="form-control mb-3" id="<?=$key?>" name="<?=$key?>" type="<?=$type?>" maxlength="<?=$maxLength?>" placeholder="e.g. <?=e($example)?>" <?=$extra?> required value="<?=e($old[$key])?>"><?php endforeach; ?>
<label class="form-label" for="address-map">Map pin location (click the map)</label><div id="address-map" class="branch-map" style="height:320px;min-height:280px;position:relative"></div><div class="row mt-2"><div class="col-sm-6"><label class="form-label" for="latitude">Latitude</label><input class="form-control" type="number" step="any" min="-90" max="90" id="latitude" name="latitude" required value="<?=e($old['latitude'])?>"></div><div class="col-sm-6"><label class="form-label" for="longitude">Longitude</label><input class="form-control" type="number" step="any" min="-180" max="180" id="longitude" name="longitude" required value="<?=e($old['longitude'])?>"></div></div>
<button class="btn btn-auth mt-3">Save address</button></form></div></div>
</div></div></main>
<div class="modal fade" id="delete-address-modal" tabindex="-1" aria-labelledby="delete-address-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="delete-address-title">Delete saved address?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">Delete the address for <strong id="delete-address-recipient"></strong>?</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep address</button><button type="button" class="btn btn-danger" id="confirm-delete-address">Delete address</button></div></div></div></div>
<?php require 'includes/footer.php'; ?>
