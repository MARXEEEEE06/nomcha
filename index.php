<?php
$pageTitle = 'Home';
require 'includes/db.php';
require 'includes/header.php';

$items = [];
$dbError = null;
try {
    $items = $pdo->query(
        'SELECT item_id, name, description, price, image_url
         FROM menu_items WHERE is_available = 1
         ORDER BY item_id LIMIT 4'
    )->fetchAll();
} catch (PDOException $e) {
    $dbError = 'Could not load menu items. Check that the menu_items table exists.';
}
?>

<!-- Hero -->
<section class="hero" style="background-image:url('https://www.bonchon.com.ph/storage/cms_images/ira2JcqW9ygL2LFWED0yJnfB7xbypOVp1iWBPLfq.webp');">
  <div class="container py-5">
    <div class="col-lg-6">
      <p class="text-uppercase fw-semibold mb-2" style="color:var(--nc-tan);letter-spacing:2px;">Welcome to Nomcha</p>
      <h1 class="mb-3">Crispy. Saucy. Unforgettable.</h1>
      <p class="lead mb-4">Double-fried to perfection and glazed with our signature sauces.</p>
      <div class="d-flex flex-wrap gap-2">
        <a href="menu.php" class="btn btn-login btn-lg">View Menu</a>
        <a href="order.php" class="btn btn-order btn-lg">Order Now</a>
      </div>
    </div>
  </div>
</section>

<!-- Signature items -->
<section class="container py-5">
  <div class="text-center mb-5">
    <h2 class="section-title">Our Signature Favorites</h2>
    <p class="text-secondary mb-0">Customer picks you can't go wrong with.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($dbError) ?></div>
  <?php elseif (!$items): ?>
    <div class="alert alert-info">No menu items yet.</div>
  <?php else: ?>
    <div class="row g-4">
      <?php foreach ($items as $item): ?>
        <div class="col-12 col-sm-6 col-lg-3">
          <div class="card item-card h-100">
            <?php if (!empty($item['image_url'])): ?>
              <img src="<?= htmlspecialchars($item['image_url']) ?>" class="item-img" alt="<?= htmlspecialchars($item['name']) ?>">
            <?php else: ?>
              <div class="item-placeholder"><i class="bi bi-egg-fried"></i></div>
            <?php endif; ?>
            <div class="card-body">
              <h5 class="card-title"><?= htmlspecialchars($item['name']) ?></h5>
              <p class="card-text small text-secondary"><?= htmlspecialchars($item['description'] ?? '') ?></p>
              <?php if ($item['price'] > 0): ?>
                <span class="item-price">&#8369;<?= number_format($item['price'], 2) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-5">
      <a href="menu.php" class="btn btn-order px-4">See Full Menu</a>
    </div>
  <?php endif; ?>
</section>

<!-- Our Story -->
<section class="band-dark py-5">
  <div class="container text-center col-lg-8">
    <h2 class="font-display mb-3">Our Story</h2>
    <p class="mb-4 opacity-75">Placeholder copy. Tell the Nomcha story here: where it started, what makes the chicken different, and why people keep coming back.</p>
    <a href="#" class="btn btn-login">Read More</a>
  </div>
</section>

<!-- Branches CTA -->
<section class="container py-5 text-center">
  <h2 class="section-title mb-3">Find a Branch Near You</h2>
  <p class="text-secondary mb-4">Dine in, take out, or order for delivery.</p>
  <a href="#" class="btn btn-order px-4">View Branches</a>
</section>

<?php require 'includes/footer.php'; ?>