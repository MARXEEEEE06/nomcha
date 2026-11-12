<?php
require 'includes/auth_helpers.php'; require 'includes/db.php'; require_staff_or_admin($pdo);
$pageTitle='Management'; require 'includes/header.php';
?>
<main class="container py-5"><div class="mb-4"><p class="text-uppercase small fw-semibold text-secondary mb-1"><?=e(ucfirst((string)$_SESSION['role']))?> workspace</p><h1>Management</h1><p class="text-secondary">Choose a tool available to your account.</p></div><div class="row g-4">
<div class="col-md-6"><a class="management-card" href="staff-orders.php"><span class="management-card-icon"><i class="bi bi-receipt"></i></span><h2>Incoming orders</h2><p>Review customer orders and update their status.</p></a></div>
<div class="col-md-6"><a class="management-card" href="menu-management.php"><span class="management-card-icon"><i class="bi bi-card-list"></i></span><h2>Menu management</h2><p>View, add, edit, and schedule menu items.</p></a></div>
<div class="col-md-6"><a class="management-card" href="team-inbox.php"><span class="management-card-icon"><i class="bi bi-inbox"></i></span><h2>Team inbox</h2><p>Review applications, customer feedback, and site activity.</p></a></div>
<?php if (($_SESSION['role']??'')==='admin'): ?><div class="col-md-6"><a class="management-card" href="promos.php"><span class="management-card-icon"><i class="bi bi-tags"></i></span><h2>Promos</h2><p>Publish offers and review redemption analytics.</p></a></div><?php endif; ?>
<?php if (($_SESSION['role']??'')==='admin'): ?><div class="col-md-6"><a class="management-card" href="employees.php"><span class="management-card-icon"><i class="bi bi-people"></i></span><h2>Employee management</h2><p>Add employees, manage details, and update account status.</p></a></div><?php endif; ?>
</div></main><?php require 'includes/footer.php'; ?>
