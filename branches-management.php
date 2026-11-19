<?php
require_once 'includes/auth_helpers.php';
require_once 'includes/db.php';
require_once 'includes/make_mailer.php';
require_once 'includes/branch_helpers.php';
require_once 'includes/toasts.php';
require_admin($pdo);

$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $messages[] = ['kind'=>'danger','text'=>'Session expired. Refresh and try again.'];
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = filter_var($_POST['branch_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        if ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $province = trim((string)($_POST['province'] ?? ''));
            $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $phone = trim((string)($_POST['phone'] ?? ''));
            $schedule = trim((string)($_POST['schedule'] ?? ''));
            $archivedEdit=false;
            if($id){$check=$pdo->prepare("SELECT status FROM branches WHERE branch_id=?");$check->execute([$id]);$archivedEdit=$check->fetchColumn()==='archived';}
            if ($archivedEdit) {
                $messages[]=['kind'=>'danger','text'=>'Restore this archived branch before editing its details.'];
            } elseif ($name === '' || mb_strlen($name) > 160 || $address === '' || mb_strlen($address) > 255 || $city === '' || mb_strlen($city) > 120 || $province === '' || mb_strlen($province) > 120 || $lat === false || $lng === false || !branch_is_within_philippines((float)$lat, (float)$lng)) {
                $messages[] = ['kind'=>'danger','text'=>'Enter the branch details and a map pin inside the Philippines.'];
            } else {
                try {
                    if ($id) {
                        $stmt = $pdo->prepare('UPDATE branches SET name=?,address=?,city=?,province=?,latitude=?,longitude=?,phone=?,schedule=?,takeout=?,pickup=? WHERE branch_id=?');
                        $stmt->execute([$name,$address,$city,$province,$lat,$lng,$phone ?: null,$schedule ?: null,isset($_POST['takeout'])?1:0,isset($_POST['pickup'])?1:0,$id]);
                        $messages[] = ['kind'=>'success','text'=>'Branch details updated.'];
                    } else {
                        $pdo->beginTransaction();
                        $pdo->prepare("INSERT INTO branches(name,address,city,province,latitude,longitude,phone,schedule,takeout,pickup,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$name,$address,$city,$province,$lat,$lng,$phone ?: null,$schedule ?: null,isset($_POST['takeout'])?1:0,isset($_POST['pickup'])?1:0,(int)$_SESSION['user_id']]);
                        $newId = (int)$pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO team_inbox_events(event_type,title,message,audience,entity_type,entity_id,created_by) VALUES('branch_added',?,?, 'all','branch',?,?)")
                            ->execute(['New branch: '.$name,$name.' is now open in '.$city.', '.$province.'.',$newId,(int)$_SESSION['user_id']]);
                        $pdo->commit();
                        try { notify_nomcha_users('branch_notification','A new Nomcha branch is open',$name.' is now open at '.$address.', '.$city.', '.$province.'. Find details and directions on our branch page.','branches.php'); }
                        catch (Throwable $mailError) { error_log('Branch notification failed: '.$mailError->getMessage()); }
                        $messages[] = ['kind'=>'success','text'=>'Branch added. A dashboard news item was created and subscriber notices were sent where delivery was available.'];
                    }
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $messages[] = ['kind'=>'danger','text'=>'Could not save the branch. Apply the branch management migration and try again.'];
                }
            }
        } elseif ($action === 'status' && $id) {
            $status = (string)($_POST['status'] ?? '');
            $current=$pdo->prepare('SELECT status FROM branches WHERE branch_id=?'); $current->execute([$id]); $currentStatus=$current->fetchColumn();
            if (!$currentStatus || !in_array($status,['open','closed','archived'],true) || ($currentStatus==='archived' && $status!=='open')) $messages[]=['kind'=>'danger','text'=>'Choose a valid branch status.'];
            else {
                $pdo->prepare('UPDATE branches SET status=? WHERE branch_id=?')->execute([$status,$id]);
                $messages[]=['kind'=>'success','text'=>$status==='archived'?'Branch archived and removed from public maps.':($status==='closed'?'Branch marked closed.':'Branch reopened.')];
            }
        } elseif ($action === 'assign' && $id) {
            $check=$pdo->prepare("SELECT status FROM branches WHERE branch_id=?"); $check->execute([$id]);
            if($check->fetchColumn()==='archived'){$messages[]=['kind'=>'danger','text'=>'Restore this archived branch before changing staff assignments.'];}
            else {
            $staffIds = array_values(array_unique(array_filter(array_map('intval',(array)($_POST['staff_ids'] ?? [])),static fn($value)=>$value>0)));
            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM branch_staff WHERE branch_id=?')->execute([$id]);
                $isStaff=$pdo->prepare("SELECT user_id FROM user_credentials WHERE user_id=? AND role='staff' AND is_active=1");
                $assign=$pdo->prepare('INSERT INTO branch_staff(branch_id,user_id) VALUES(?,?)');
                foreach ($staffIds as $staffId) { $isStaff->execute([$staffId]); if ($isStaff->fetchColumn()) $assign->execute([$id,$staffId]); }
                $pdo->commit(); $messages[]=['kind'=>'success','text'=>'Branch staff assignments updated.'];
            } catch (Throwable $error) { if($pdo->inTransaction())$pdo->rollBack(); $messages[]=['kind'=>'danger','text'=>'Could not update staff assignments. Apply the branch management migration and try again.']; }
            }
        }
    }
}
$branches = $pdo->query("SELECT * FROM branches WHERE status<>'archived' ORDER BY FIELD(status,'open','closed'),name")->fetchAll(PDO::FETCH_ASSOC);
$archivedBranches = $pdo->query("SELECT * FROM branches WHERE status='archived' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$staff = $pdo->query("SELECT c.user_id,i.first_name,i.last_name FROM user_credentials c JOIN user_info i ON i.user_id=c.user_id WHERE c.role='staff' AND c.is_active=1 ORDER BY i.last_name,i.first_name")->fetchAll(PDO::FETCH_ASSOC);
$assigned = [];
foreach ($pdo->query('SELECT branch_id,user_id FROM branch_staff')->fetchAll(PDO::FETCH_ASSOC) as $row) $assigned[(int)$row['branch_id']][]=(int)$row['user_id'];
$pageTitle='Branch management';
$pageStyles='<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css"><link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">';
$pageScripts='<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>';
require 'includes/header.php'; render_toasts($messages);
?>
<main class="container py-5">
<div class="mb-4"><h1 class="mb-1">Branch management</h1><p class="text-secondary mb-0">Manage map listings, closures, and staff assignments.</p></div>
<section class="card card-body mb-4"><h2 class="h4">Branch map</h2><div id="management-branch-map" class="branch-map" role="application" aria-label="Management map of all branches"></div><p class="branch-map-credit">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a></p></section>
<section class="card card-body mb-4"><h2 class="h4">Add a branch</h2><form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><div class="col-md-6"><label class="form-label">Branch name</label><input class="form-control" name="name" maxlength="160" required></div><div class="col-md-6"><label class="form-label">Street address</label><input class="form-control" name="address" maxlength="255" required></div><div class="col-md-4"><label class="form-label">City</label><input class="form-control" name="city" maxlength="120" required></div><div class="col-md-4"><label class="form-label">Province</label><input class="form-control" name="province" maxlength="120" required></div><div class="col-md-2"><label class="form-label">Latitude</label><input class="form-control" type="number" name="latitude" step="any" min="4.5" max="21.2" required></div><div class="col-md-2"><label class="form-label">Longitude</label><input class="form-control" type="number" name="longitude" step="any" min="116.9" max="126.7" required></div><div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="40"></div><div class="col-md-5"><label class="form-label">Hours</label><input class="form-control" name="schedule" maxlength="180" placeholder="Daily, 10:00 AM–10:00 PM"></div><div class="col-md-3 d-flex align-items-end gap-3"><label><input type="checkbox" name="takeout" checked> Takeout</label><label><input type="checkbox" name="pickup" checked> Pickup</label></div><div class="col-12"><button class="btn btn-auth">Add branch and notify subscribers</button></div></form></section>
<h2 class="h3 mb-3">Branches</h2>
<?php if (!$branches): ?><p class="text-secondary">No branches yet. Add one above to publish it to the branch map and order picker.</p><?php endif; ?>
<?php $allBranches=array_merge($branches,$archivedBranches); $archiveHeadingShown=false; foreach ($allBranches as $branch): $id=(int)$branch['branch_id']; if($branch['status']==='archived'&&!$archiveHeadingShown): $archiveHeadingShown=true; ?><h2 class="h3 mt-5 mb-3">Archived branches</h2><p class="text-secondary">Archived records are read-only until explicitly restored.</p><?php endif; ?>
<article class="card card-body mb-3"><div class="d-flex justify-content-between align-items-start gap-3"><div><button type="button" class="btn btn-link p-0 text-start h5 mb-1 management-branch-focus" data-branch-focus="<?=$id?>"><?=e($branch['name'])?></button><p class="small text-secondary mb-3"><?=e($branch['address'])?> · <?=e($branch['city'])?>, <?=e($branch['province'])?> · <?=e(ucfirst($branch['status']))?></p></div><span class="badge <?=$branch['status']==='open'?'text-bg-success':($branch['status']==='closed'?'text-bg-warning':'text-bg-secondary')?>"><?=e($branch['status'])?></span></div>
<?php if($branch['status']!=='archived'): ?><details><summary class="mb-3">Edit branch details</summary><form method="post" class="row g-2 mb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="branch_id" value="<?=$id?>"><div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" value="<?=e($branch['name'])?>" required></div><div class="col-md-6"><label class="form-label">Address</label><input class="form-control" name="address" value="<?=e($branch['address'])?>" required></div><div class="col-md-3"><label class="form-label">City</label><input class="form-control" name="city" value="<?=e($branch['city'])?>" required></div><div class="col-md-3"><label class="form-label">Province</label><input class="form-control" name="province" value="<?=e($branch['province'])?>" required></div><div class="col-md-2"><label class="form-label">Latitude</label><input class="form-control" type="number" step="any" min="4.5" max="21.2" name="latitude" value="<?=e($branch['latitude'])?>" required></div><div class="col-md-2"><label class="form-label">Longitude</label><input class="form-control" type="number" step="any" min="116.9" max="126.7" name="longitude" value="<?=e($branch['longitude'])?>" required></div><div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?=e($branch['phone']??'')?>"></div><div class="col-md-5"><label class="form-label">Hours</label><input class="form-control" name="schedule" value="<?=e($branch['schedule']??'')?>"></div><div class="col-md-3 d-flex align-items-end gap-3"><label><input type="checkbox" name="takeout" <?=$branch['takeout']?'checked':''?>> Takeout</label><label><input type="checkbox" name="pickup" <?=$branch['pickup']?'checked':''?>> Pickup</label></div><div class="col-12"><button class="btn btn-sm btn-outline-primary">Save details</button></div></form></details>
<form method="post" class="row g-2 align-items-end mb-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="branch_id" value="<?=$id?>"><div class="col-md-9"><label class="form-label">Assigned staff</label><select class="form-select" name="staff_ids[]" multiple size="<?=max(2,min(5,count($staff)))?>"><?php foreach($staff as $person): ?><option value="<?=(int)$person['user_id']?>" <?=in_array((int)$person['user_id'],$assigned[$id]??[],true)?'selected':''?>><?=e($person['first_name'].' '.$person['last_name'])?></option><?php endforeach; ?></select></div><div class="col-md-3"><button class="btn btn-sm btn-outline-primary">Save assignments</button></div></form>
<form method="post" class="d-flex flex-wrap align-items-center gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="branch_id" value="<?=$id?>"><label class="form-label mb-0" for="branch-status-<?=$id?>">Status</label><select class="form-select form-select-sm w-auto" id="branch-status-<?=$id?>" name="status"><option value="open" <?=$branch['status']==='open'?'selected':''?>>Open</option><option value="closed" <?=$branch['status']==='closed'?'selected':''?>>Closed</option><option value="archived">Archive permanently</option></select><button class="btn btn-sm btn-outline-secondary">Update status</button></form>
<?php else: ?><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="branch_id" value="<?=$id?>"><input type="hidden" name="status" value="open"><button class="btn btn-sm btn-outline-primary">Restore branch to Open</button></form><?php endif; ?></article><?php endforeach; ?></main>
<script id="management-branch-data" type="application/json"><?=json_encode(array_map(static fn($b)=>['id'=>(int)$b['branch_id'],'name'=>$b['name'],'address'=>$b['address'],'lat'=>(float)$b['latitude'],'lng'=>(float)$b['longitude'],'status'=>$b['status']],$allBranches),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{const branches=JSON.parse(document.getElementById('management-branch-data').textContent||'[]');const esc=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));const map=L.map('management-branch-map',{maxBounds:L.latLngBounds([[4.5,116.9],[21.2,126.7]]),maxBoundsViscosity:1,minZoom:5}).setView([14.6,121.0],6);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);const layer=typeof L.markerClusterGroup==='function'?L.markerClusterGroup({disableClusteringAtZoom:14}).addTo(map):L.layerGroup().addTo(map);const markers=new Map();branches.forEach(branch=>{const marker=L.marker([branch.lat,branch.lng]).bindPopup(`<strong>${esc(branch.name)}</strong><br>${esc(branch.address)}<br><small>${esc(branch.status)}</small>`).addTo(layer);markers.set(String(branch.id),marker);});if(branches.length)map.fitBounds(L.latLngBounds(branches.map(b=>[b.lat,b.lng])),{padding:[24,24],maxZoom:8});document.querySelectorAll('[data-branch-focus]').forEach(button=>button.addEventListener('click',()=>{const marker=markers.get(button.dataset.branchFocus);if(!marker)return;map.setView(marker.getLatLng(),Math.max(map.getZoom(),14),{animate:false});marker.openPopup();}));window.addEventListener('resize',()=>map.invalidateSize());});
</script>
<?php require 'includes/footer.php'; ?>
