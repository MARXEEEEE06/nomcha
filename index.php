<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
$homeRole = current_role($pdo);
if (in_array($homeRole, ['staff', 'admin'], true)) {
    $pageTitle = 'Management';
    require 'includes/header.php';
    ?>
    <main class="container py-5 management-home"><div class="mb-4"><p class="text-uppercase small fw-semibold text-secondary mb-1"><?=e(ucfirst($homeRole))?> workspace</p><h1>Management</h1><p class="text-secondary">Choose a tool available to your account.</p></div><div class="row g-4"><div class="col-md-6"><a class="management-card" href="staff-orders.php"><span class="management-card-icon"><i class="bi bi-receipt"></i></span><h2>Incoming orders</h2><p>Review customer orders and update their status.</p></a></div><div class="col-md-6"><a class="management-card" href="menu-management.php"><span class="management-card-icon"><i class="bi bi-card-list"></i></span><h2>Menu management</h2><p>View, add, edit, and schedule menu items.</p></a></div><?php if ($homeRole === 'admin'): ?><div class="col-md-6"><a class="management-card" href="employees.php"><span class="management-card-icon"><i class="bi bi-people"></i></span><h2>Employee management</h2><p>Manage employee accounts and access.</p></a></div><?php endif; ?></div></main>
    <?php require 'includes/footer.php'; exit;
}
$pageTitle = 'Home';
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

<!-- Bonchon-inspired banner slideshow -->
<section class="home-slideshow" aria-label="Featured banners">
  <div class="home-slideshow-track" id="home-slideshow-track">
    <img class="home-slide is-active" src="assets\slideshow\slide1.webp" alt="Featured Nomcha banner">
    <img class="home-slide" src="assets\slideshow\slide2.jpg" alt="Featured Nomcha banner">
    <img class="home-slide" src="assets\slideshow\slide3.jpg" alt="Featured Nomcha banner">
  </div>
  <button class="slide-arrow slide-prev" type="button" aria-label="Previous image"><i class="bi bi-chevron-left"></i></button>
  <button class="slide-arrow slide-next" type="button" aria-label="Next image"><i class="bi bi-chevron-right"></i></button>
  <div class="slide-dots" role="tablist" aria-label="Choose banner image">
    <button class="slide-dot is-active" type="button" aria-label="Show image 1" aria-current="true"></button>
    <button class="slide-dot" type="button" aria-label="Show image 2"></button>
    <button class="slide-dot" type="button" aria-label="Show image 3"></button>
  </div>
  <div class="slide-count" aria-live="polite"><span id="slide-current">1</span> / 3</div>
  <div class="slide-progress" aria-hidden="true"><span id="slide-progress-fill"></span></div>
</section>
<?php
$pageScripts = <<<'HTML'
<script>
(() => {
  const slides = [...document.querySelectorAll('.home-slide')];
  const dots = [...document.querySelectorAll('.slide-dot')];
  const current = document.getElementById('slide-current');
  if (!slides.length || !dots.length || !current) return;
  let active = 0;
  const intervalMs = 10000;
  let timer;
  const progress = document.getElementById('slide-progress-fill');
  const track = document.getElementById('home-slideshow-track');
  const show = (index) => {
    active = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => slide.classList.toggle('is-active', i === active));
    const activeSlide = slides[active];
    if (activeSlide.complete && activeSlide.naturalWidth) {
      track.style.setProperty('--banner-ratio', `${activeSlide.naturalWidth} / ${activeSlide.naturalHeight}`);
    }
    dots.forEach((dot, i) => {
      dot.classList.toggle('is-active', i === active);
      if (i === active) { dot.setAttribute('aria-current', 'true'); }
      else { dot.removeAttribute('aria-current'); }
    });
    current.textContent = String(active + 1);
    if (progress) {
      progress.classList.remove('is-running');
      void progress.offsetWidth;
      progress.classList.add('is-running');
    }
  };
  slides.forEach(slide => slide.addEventListener('load', () => {
    if (slide === slides[active] && slide.naturalWidth) {
      track.style.setProperty('--banner-ratio', `${slide.naturalWidth} / ${slide.naturalHeight}`);
    }
  }));
  const restartTimer = () => { window.clearInterval(timer); timer = window.setInterval(() => show(active + 1), intervalMs); };
  document.querySelector('.slide-prev').addEventListener('click', () => { show(active - 1); restartTimer(); });
  document.querySelector('.slide-next').addEventListener('click', () => { show(active + 1); restartTimer(); });
  dots.forEach((dot, i) => dot.addEventListener('click', () => { show(i); restartTimer(); }));
  const slideshow = document.querySelector('.home-slideshow');
  let startX = null;
  slideshow.addEventListener('touchstart', e => { startX = e.changedTouches[0].clientX; }, {passive:true});
  slideshow.addEventListener('touchend', e => {
    if (startX === null) return;
    const delta = e.changedTouches[0].clientX - startX;
    if (Math.abs(delta) > 45) { show(active + (delta < 0 ? 1 : -1)); restartTimer(); }
    startX = null;
  }, {passive:true});
  show(active);
  restartTimer();
})();
</script>
HTML;
?>

<!-- Signature items -->
<section class="container py-5 home-section">
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
          <a href="order.php#order-category-5" style="text-decoration: none;">
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
          </a>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="text-center mt-5">
      <a href="menu.php" class="btn btn-order px-4">See Full Menu</a>
    </div>
  <?php endif; ?>
</section>

<!-- Our Story -->
<section class="band-dark py-5 home-story-section">
  <div class="container text-center col-lg-8">
    <h2 class="font-display mb-3">Our Story</h2>
    <p class="mb-4 opacity-75">Discover the delicious journey of Nomcha! From its humble beginnings to growing success, a deep passion for flavorful comfort food led to the creation of the ultimate crispy, tender, and satisfying dining experience. Beyond just great meals, Nomcha brings people together to celebrate culture and tradition with every delectable bite</p>
    <a href="#" class="btn btn-login">Read More</a>
  </div>
</section>

<!-- Branches CTA -->
<section class="container py-5 text-center home-section">
  <h2 class="section-title mb-3">Find a Branch Near You</h2>
  <p class="text-secondary mb-4">Dine in, take out, or order for delivery.</p>
  <a href="#" class="btn btn-order px-4">View Branches</a>
</section>

<?php require 'includes/footer.php'; ?>
