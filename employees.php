<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require_admin($pdo);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $error = 'Session expired. Refresh and try again.';
    elseif (($_POST['action'] ?? '') === 'deactivate' && strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) === 'DEACTIVATE') {
        $employeeId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$employeeId || $employeeId === (int)$_SESSION['user_id']) $error = 'You cannot deactivate your own account.';
        else {
            $target=$pdo->prepare('SELECT role FROM user_credentials WHERE user_id = ? AND is_active = 1'); $target->execute([(int)$employeeId]); $targetRole=$target->fetchColumn();
            if (!$targetRole) $error='Employee is already inactive or no longer exists.';
            if (!$error && $targetRole==='admin' && (int)$pdo->query("SELECT COUNT(*) FROM user_credentials WHERE role='admin' AND is_active=1")->fetchColumn()<=1) $error='The last active admin account cannot be deactivated.';
        }
        if (!$error) {
            $stmt = $pdo->prepare("UPDATE user_credentials SET is_active = 0 WHERE user_id = ? AND role IN ('staff','admin')");
            $stmt->execute([(int)$employeeId]);
            $_SESSION['flash_success'] = $stmt->rowCount() ? 'Employee deactivated.' : 'Employee was not found or is already inactive.';
            header('Location: employees.php'); exit;
        }
    } elseif (($_POST['action'] ?? '') === 'deactivate') $error = 'Type DEACTIVATE to confirm this action.';
}
$employees = $pdo->query("SELECT c.user_id, c.role, c.email_addr, c.is_active, i.first_name, i.middle_name, i.last_name, i.mobileno, i.home_addr FROM user_credentials c JOIN user_info i ON i.user_id = c.user_id WHERE c.role IN ('staff','admin') ORDER BY i.last_name, i.first_name")->fetchAll();
$success = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
$pageTitle = 'Employee management';
require 'includes/header.php';
?>
<main class="container py-5"><div class="d-flex justify-content-between align-items-center mb-4"><h1>Employee management</h1><span class="badge text-bg-dark">Admin</span></div>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<div class="row g-2 mb-3"><div class="col-md-7"><label class="form-label" for="employee-search">Search names</label><input id="employee-search" class="form-control" type="search" placeholder="Search employees"></div><div class="col-md-3"><label class="form-label" for="sort-key">Sort by</label><select id="sort-key" class="form-select"><option value="name">Name</option><option value="role">Role</option></select></div><div class="col-md-2"><label class="form-label" for="sort-dir">Order</label><select id="sort-dir" class="form-select"><option value="asc">Ascending</option><option value="desc">Descending</option></select></div></div>
<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Contact Number</th><th>Status</th></tr></thead><tbody id="employee-rows">
<?php foreach ($employees as $person): $fullName = trim(implode(' ', array_filter([$person['first_name'], $person['middle_name'], $person['last_name']]))); ?>
<tr tabindex="0" role="button" class="employee-row" data-name="<?= e(mb_strtolower($fullName)) ?>" data-role="<?= e($person['role']) ?>" data-person='<?= e(json_encode($person, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>'><td><?= e($fullName) ?></td><td><?= e(ucfirst($person['role'])) ?></td><td><?= e($person['email_addr']) ?></td><td><?= e($person['mobileno']) ?></td><td><?= (int)$person['is_active'] ? 'Active' : 'Deactivated' ?></td></tr>
<?php endforeach; ?></tbody></table></div><p id="employee-empty" class="text-secondary" hidden>No employees match your search.</p></main>
<div class="modal fade" id="employeeModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Employee details</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><dl class="row mb-0"><dt class="col-4">Name</dt><dd class="col-8" data-field="name"></dd><dt class="col-4">Role</dt><dd class="col-8" data-field="role"></dd><dt class="col-4">Email</dt><dd class="col-8" data-field="email_addr"></dd><dt class="col-4">Contact</dt><dd class="col-8" data-field="mobileno"></dd><dt class="col-4">Address</dt><dd class="col-8" data-field="home_addr"></dd><dt class="col-4">Status</dt><dd class="col-8" data-field="status"></dd></dl></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="showDeactivate">Deactivate employee</button></div></div></div></div>
<div class="modal fade" id="deactivateModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h2 class="modal-title fs-5 text-danger">Confirm deactivation</h2><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p>This immediately prevents the employee from signing in. To confirm, type <strong>DEACTIVATE</strong>.</p><input class="form-control" name="confirm_text" required pattern="DEACTIVATE" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="user_id" id="deactivate-user-id"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Deactivate employee</button></div></form></div></div></div>
<script>
document.addEventListener('DOMContentLoaded',()=>{ const rows=[...document.querySelectorAll('.employee-row')], search=document.getElementById('employee-search'), key=document.getElementById('sort-key'), dir=document.getElementById('sort-dir'), empty=document.getElementById('employee-empty'); let selected=null;
function refresh(){const q=search.value.trim().toLowerCase();rows.forEach(r=>r.hidden=!r.dataset.name.includes(q));const visible=rows.filter(r=>!r.hidden);visible.sort((a,b)=>a.dataset[key.value].localeCompare(b.dataset[key.value])*(dir.value==='asc'?1:-1));visible.forEach(r=>r.parentNode.appendChild(r));empty.hidden=visible.length>0;} [search,key,dir].forEach(x=>x.addEventListener('input',refresh));
const details=new bootstrap.Modal('#employeeModal'), confirm=new bootstrap.Modal('#deactivateModal'); rows.forEach(row=>{const open=()=>{selected=JSON.parse(row.dataset.person);const full=[selected.first_name,selected.middle_name,selected.last_name].filter(Boolean).join(' ');document.querySelector('[data-field="name"]').textContent=full;['role','email_addr','mobileno','home_addr'].forEach(k=>document.querySelector('[data-field="'+k+'"]').textContent=selected[k]||'—');document.querySelector('[data-field="status"]').textContent=Number(selected.is_active)?'Active':'Deactivated';document.getElementById('showDeactivate').hidden=!Number(selected.is_active)||Number(selected.user_id)===<?= (int)$_SESSION['user_id'] ?>;details.show();};row.addEventListener('click',open);row.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();open();}});}); document.getElementById('showDeactivate').addEventListener('click',()=>{document.getElementById('deactivate-user-id').value=selected.user_id;details.hide();confirm.show();});refresh(); });
</script>
<?php require 'includes/footer.php'; ?>
