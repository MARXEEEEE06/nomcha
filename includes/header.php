<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle   = $pageTitle ?? 'Nomcha';
$current     = basename($_SERVER['PHP_SELF']);
$isLoggedIn  = !empty($_SESSION['user_id']);
$displayName = $_SESSION['first_name'] ?? '';

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
            <li class="nav-item"><a class="nav-link<?= navActive('menu.php', $current) ?>" href="menu.php">Menu</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('our-story.php', $current) ?>" href="our-story.php">Our Story</a></li>
            <li class="nav-item"><a class="nav-link<?= navActive('branches.php', $current) ?>" href="branches.php">Branches</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Promos</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Franchise</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Careers</a></li>
            <li class="nav-item"><a class="nav-link" href="#">Contact Us</a></li>
        </ul>

      <div class="d-flex align-items-center gap-2">
        <a href="order.php" class="btn btn-order">Order</a>

        <?php if ($isLoggedIn): ?>
          <div class="dropdown">
            <button class="btn btn-login dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($displayName) ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-nomcha">
              <li><a class="dropdown-item" href="billing-address.php"><i class="bi bi-geo-alt me-2"></i>Billing Address</a></li>
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
