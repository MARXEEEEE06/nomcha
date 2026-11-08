<?php
$pageTitle = 'Menu';
require 'includes/db.php';
require 'includes/header.php';

$tree = [];
$itemsByCat = [];
$dbError = null;

try {
    $cats = $pdo->query(
        'SELECT category_id, parent_id, name, description
         FROM menu_categories ORDER BY sort_order, category_id'
    )->fetchAll();

    $items = $pdo->query(
        'SELECT category_id, name, description, image_url
         FROM menu_items WHERE is_available = 1 ORDER BY sort_order, item_id'
    )->fetchAll();

    foreach ($items as $it) {
        $itemsByCat[$it['category_id']][] = $it;
    }
    foreach ($cats as $c) {
        if ($c['parent_id'] === null) {
            $tree[$c['category_id']] = $c + ['children' => []];
        }
    }
    foreach ($cats as $c) {
        if ($c['parent_id'] !== null && isset($tree[$c['parent_id']])) {
            $tree[$c['parent_id']]['children'][] = $c;
        }
    }
} catch (PDOException $e) {
    $dbError = 'Could not load the menu. Check that menu_categories and menu_items exist.';
}

function renderItems(array $items): void {
    if (!$items) return;
    echo '<div class="row row-cols-1 row-cols-md-2 g-4">';
    foreach ($items as $it) {
        echo '<div class="col text-center">';
        if (!empty($it['image_url'])) {
            echo '<img src="' . htmlspecialchars($it['image_url']) . '" class="menu-item-img mb-3" alt="' . htmlspecialchars($it['name']) . '">';
        } else {
            echo '<div class="menu-item-placeholder mb-3"><i class="bi bi-egg-fried"></i></div>';
        }
        echo '<h4 class="menu-item-name">' . htmlspecialchars($it['name']) . '</h4>';
        if (!empty($it['description'])) {
            echo '<p class="small text-secondary">' . htmlspecialchars($it['description']) . '</p>';
        }
        echo '</div>';
    }
    echo '</div>';
}
?>

<!-- Featured hero moved from the homepage -->
<section class="hero" style="background-image:url('https://www.bonchon.com.ph/storage/cms_images/ira2JcqW9ygL2LFWED0yJnfB7xbypOVp1iWBPLfq.webp');">
  <div class="container py-5">
    <div class="col-lg-6">
      <p class="text-uppercase fw-semibold mb-2" style="color:var(--nc-tan);letter-spacing:2px;">Welcome to Nomcha</p>
      <h1 class="mb-3">Crispy. Saucy. Unforgettable.</h1>
      <p class="lead mb-0">Double-fried to perfection and glazed with our signature sauces.</p>
    </div>
  </div>
</section>

<!-- Intro -->
<section class="menu-intro text-center py-5">
  <div class="container col-lg-8">
    <h1 class="mb-3">Your Korean Favorites</h1>
    <p class="mb-0">Treat yourself to an irresistible journey of flavors with our mouthwatering selection of Korean Fried Chicken and an array of delectable dishes inspired by the vibrant cuisine of Korea!</p>
  </div>
</section>

<?php if ($dbError): ?>
  <div class="container"><div class="alert alert-warning"><?= htmlspecialchars($dbError) ?></div></div>
<?php else: ?>

  <!-- Mobile category pills -->
  <div class="menu-pills sticky-top d-lg-none py-2 px-3">
    <?php foreach ($tree as $cat): ?>
      <a href="#cat-<?= $cat['category_id'] ?>" class="btn btn-sm rounded-pill"><?= htmlspecialchars($cat['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="container">
    <div class="row g-5">

      <!-- Sidebar (desktop) -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="menu-sidebar-wrap">
          <h2 class="menu-sidebar-title mb-3">Menu</h2>
          <nav id="menu-sidebar" class="nav flex-column menu-sidebar">
            <?php foreach ($tree as $cat): ?>
              <?php if ($cat['children']): ?>
                <div class="d-flex align-items-center justify-content-between">
                  <a class="nav-link flex-grow-1" href="#cat-<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></a>
                  <button class="btn btn-sm menu-toggle" type="button"
                          data-bs-toggle="collapse" data-bs-target="#sub-<?= $cat['category_id'] ?>"
                          aria-expanded="true" aria-label="Toggle <?= htmlspecialchars($cat['name']) ?>">
                    <i class="bi bi-chevron-up"></i>
                  </button>
                </div>
                <div class="collapse show" id="sub-<?= $cat['category_id'] ?>">
                  <div class="nav flex-column ms-3 menu-sub">
                    <?php foreach ($cat['children'] as $sub): ?>
                      <a class="nav-link" href="#cat-<?= $sub['category_id'] ?>"><?= htmlspecialchars($sub['name']) ?></a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php else: ?>
                <a class="nav-link" href="#cat-<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </nav>
        </div>
      </aside>

      <!-- Continuous item list -->
      <div class="col-lg-9">
        <?php foreach ($tree as $cat): ?>
          <section id="cat-<?= $cat['category_id'] ?>" class="menu-section">
            <h2 class="menu-title mb-3"><?= htmlspecialchars($cat['name']) ?></h2>
            <?php if (!empty($cat['description'])): ?>
              <p class="menu-desc"><?= htmlspecialchars($cat['description']) ?></p>
            <?php endif; ?>

            <?php renderItems($itemsByCat[$cat['category_id']] ?? []); ?>

            <?php foreach ($cat['children'] as $sub): ?>
              <section id="cat-<?= $sub['category_id'] ?>" class="menu-subsection mt-5">
                <h3 class="menu-subtitle mb-3"><?= htmlspecialchars($sub['name']) ?></h3>
                <?php if (!empty($sub['description'])): ?>
                  <p class="menu-desc"><?= htmlspecialchars($sub['description']) ?></p>
                <?php endif; ?>
                <?php renderItems($itemsByCat[$sub['category_id']] ?? []); ?>
              </section>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </div>

    </div>
  </div>

<?php endif; ?>

<?php
$pageScripts = <<<'HTML'
<script>
  new bootstrap.ScrollSpy(document.body, { target: '#menu-sidebar', rootMargin: '-100px 0px -60% 0px' });
</script>
HTML;
require 'includes/footer.php';
