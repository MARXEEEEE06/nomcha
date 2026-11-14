<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
$homeRole = current_role($pdo);
if ($homeRole === 'admin') { header('Location: admin.php'); exit; }
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
        "SELECT ranked.item_id,ranked.name,COALESCE(m.description,'') AS description,m.price,
                IF(m.image_data IS NOT NULL,CONCAT('menu-image.php?id=',m.item_id),m.image_url) AS image_url,ranked.sold
         FROM (SELECT oi.item_id,oi.item_name AS name,SUM(oi.quantity) AS sold
               FROM customer_order_items oi JOIN customer_orders o ON o.order_id=oi.order_id
               WHERE o.created_at >= DATE_SUB(NOW(),INTERVAL 2 MONTH)
                 AND LOWER(o.status) NOT IN ('cancelled','rejected','failed')
               GROUP BY oi.item_id,oi.item_name ORDER BY sold DESC,oi.item_name ASC LIMIT 12) ranked
         LEFT JOIN menu_items m ON m.item_id=ranked.item_id
         WHERE m.item_kind='item' AND m.is_available=1
           AND (m.publish_at IS NULL OR m.publish_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR))
         ORDER BY ranked.sold DESC,ranked.name ASC"
    )->fetchAll();
    $optionRows = $pdo->prepare("SELECT oi.quantity,oi.options_json FROM customer_order_items oi JOIN customer_orders o ON o.order_id=oi.order_id WHERE oi.item_id <=> ? AND oi.item_name=? AND o.created_at>=DATE_SUB(NOW(),INTERVAL 2 MONTH) AND LOWER(o.status) NOT IN ('cancelled','rejected','failed') AND oi.options_json IS NOT NULL");
    foreach ($items as &$item) {
        $optionRows->execute([$item['item_id']!==null?(int)$item['item_id']:null,$item['name']]);
        $popularOptions=[];
        foreach ($optionRows->fetchAll() as $orderLine) {
            $selected=json_decode((string)$orderLine['options_json'],true);
            if(!is_array($selected)) continue;
            $collect=function(array $values) use (&$collect,&$popularOptions,$orderLine): void {
                foreach($values as $key=>$value){
                    if(in_array((string)$key,['specialRequest','special_request'],true)) continue;
                    if(is_array($value)){
                        foreach($value as $optionName=>$count){
                            if(is_string($optionName)&&is_numeric($count)&&$count>0)$popularOptions[$optionName]=($popularOptions[$optionName]??0)+(int)$count*(int)$orderLine['quantity'];
                            elseif(is_string($count)&&$count!=='')$popularOptions[$count]=($popularOptions[$count]??0)+(int)$orderLine['quantity'];
                        }
                        if(array_is_list($value))$collect($value);
                    } elseif(is_string($value)&&$value!=='') $popularOptions[$value]=($popularOptions[$value]??0)+(int)$orderLine['quantity'];
                }
            };
            $collect($selected);
        }
        arsort($popularOptions);$item['popular_options']=array_slice($popularOptions,0,3,true);
    }
    unset($item);
} catch (PDOException $e) {
    $dbError = 'Could not load recent favorites. Check order and menu data availability.';
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
$pageScripts .= <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const slider=document.getElementById('favorites-slider');if(!slider)return;
 const step=()=>{const card=slider.querySelector('.favorite-slide');return card?card.getBoundingClientRect().width+16:320;};
 document.querySelector('[data-favorites-prev]').addEventListener('click',()=>slider.scrollBy({left:-step(),behavior:'smooth'}));
 document.querySelector('[data-favorites-next]').addEventListener('click',()=>slider.scrollBy({left:step(),behavior:'smooth'}));
 window.setInterval(()=>{if(slider.matches(':hover')||slider.contains(document.activeElement))return;const end=slider.scrollWidth-slider.clientWidth;if(slider.scrollLeft>=end-8)slider.scrollTo({left:0,behavior:'smooth'});else slider.scrollBy({left:step(),behavior:'smooth'});},5000);
});
</script>
HTML;
?>

<!-- Signature items -->
<section class="container py-5 home-section">
  <div class="text-center mb-5">
    <h2 class="section-title">Our Signature Favorites</h2>
    <p class="text-secondary mb-0">Top ordered items and options from the last two months.</p>
  </div>

  <?php if ($dbError): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($dbError) ?></div>
  <?php elseif (!$items): ?>
    <div class="alert alert-info">No recent order favorites yet.</div>
  <?php else: ?>
    <div class="favorites-slider-wrap">
      <button class="favorites-arrow" type="button" data-favorites-prev aria-label="Previous favorites"><i class="bi bi-chevron-left"></i></button>
      <div class="favorites-slider" id="favorites-slider" tabindex="0" aria-label="Popular menu items from the last two months">
      <?php foreach ($items as $item): ?>
        <div class="favorite-slide">
          <a href="menu.php" style="text-decoration: none;">
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
              <p class="small text-secondary mt-2 mb-1"><?=number_format((int)$item['sold'])?> ordered in 2 months</p>
              <?php if($item['popular_options']): ?><div class="small"><strong>Popular options</strong><ul class="mb-0 ps-3"><?php foreach($item['popular_options'] as $optionName=>$optionCount): ?><li><?=htmlspecialchars((string)$optionName)?> <span class="text-secondary">(<?=number_format((int)$optionCount)?>)</span></li><?php endforeach; ?></ul></div><?php endif; ?>
            </div>
          </div>
          </a>
        </div>
      <?php endforeach; ?>
      </div><button class="favorites-arrow" type="button" data-favorites-next aria-label="Next favorites"><i class="bi bi-chevron-right"></i></button>
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
