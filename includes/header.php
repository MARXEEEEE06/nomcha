<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle   = $pageTitle ?? 'Nomcha';
$current     = basename($_SERVER['PHP_SELF']);
$isLoggedIn  = !empty($_SESSION['user_id']);
$displayName = $_SESSION['first_name'] ?? '';
$isManagementRole = in_array(($_SESSION['role'] ?? ''), ['staff', 'admin'], true);

function navActive(string $file, string $current): string {
    return $file === $current ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> | Nomcha</title>
  <link rel="icon" type="image/png" sizes="256x256" href="assets/namcha_favicon_256.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
  <?= $pageStyles ?? '' ?>
</head>
<body>

<nav class="navbar navbar-expand-xl navbar-nomcha sticky-top" data-bs-theme="dark">
  <div class="container">
    <a class="navbar-brand" href="index.php"><img class="navbar-logo" src="assets\namcha_korean_favicon.png">Nomcha</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav me-auto mb-2 mb-xl-0">
            <?php if ($isManagementRole): ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <li class="nav-item"><a class="nav-link<?= navActive('admin.php', $current) ?>" href="admin.php">Management</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('staff-orders.php', $current) ?>" href="staff-orders.php">Incoming orders</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('menu-management.php', $current) ?>" href="menu-management.php">Menu management</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('employees.php', $current) ?>" href="employees.php">Employees</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('team-inbox.php', $current) ?>" href="team-inbox.php">Team inbox</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('promos.php', $current) ?>" href="promos.php">Promos</a></li>
            <?php else: ?>
            <li class="nav-item"><a class="nav-link<?= navActive('admin.php', $current) ?>" href="admin.php">Management</a></li>
            <?php endif; ?>
            <?php else: ?>
            <li class="nav-item"><a class="nav-link<?= navActive('menu.php', $current) ?>" href="menu.php">Menu</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('our-story.php', $current) ?>" href="our-story.php">Our Story</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('branches.php', $current) ?>" href="branches.php">Branches</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('promos.php', $current) ?>" href="promos.php">Promos</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Franchise</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('careers.php', $current) ?>" href="careers.php">Careers</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('contact.php', $current) ?>" href="contact.php">Contact Us</a></li>
            <?php endif; ?>
        </ul>

      <div class="d-flex align-items-center gap-2">
        <?php if (!$isManagementRole): ?><a href="order.php" class="btn btn-order">Order</a><?php endif; ?>

        <?php if ($isLoggedIn): ?>
          <div class="dropdown">
            <button class="btn btn-login dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($displayName) ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-nomcha">
              <?php if (($_SESSION['role'] ?? 'customer') === 'customer'): ?>
              <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My account</a></li>
              <li><a class="dropdown-item" href="order.php?view=orders"><i class="bi bi-receipt me-2"></i>My orders</a></li>
              <li><a class="dropdown-item" href="billing-address.php"><i class="bi bi-geo-alt me-2"></i>Billing Address</a></li>
              <?php elseif (($_SESSION['role'] ?? '') === 'staff'): ?>
              <li><a class="dropdown-item" href="admin.php"><i class="bi bi-speedometer2 me-2"></i>Management</a></li>
              <li><a class="dropdown-item" href="staff-orders.php"><i class="bi bi-receipt me-2"></i>Incoming orders</a></li>
              <li><a class="dropdown-item" href="menu-management.php"><i class="bi bi-card-list me-2"></i>Menu management</a></li>
              <li><a class="dropdown-item" href="team-inbox.php"><i class="bi bi-inbox me-2"></i>Team inbox</a></li>
              <?php endif; ?>
              <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
          </div>
        <?php else: ?>
          <a href="login.php" class="btn btn-login">Login</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
