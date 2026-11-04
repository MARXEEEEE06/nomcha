<footer class="footer-nomcha mt-5">
  <div class="container py-5">
    <div class="row g-5 align-items-center">
      <div class="col-lg-6">
        <h3 class="footer-heading mb-4">Get fresh and hot news straight to your inbox</h3>
        <form class="footer-subscribe d-flex gap-2" action="#" method="post" onsubmit="return false;">
          <input type="email" class="form-control border-0" placeholder="Email Address" aria-label="Email Address" required>
          <button type="submit" class="btn btn-login rounded-0 px-4">Subscribe</button>
        </form>
      </div>

      <div class="col-lg-6">
        <div class="row footer-links">
          <ul class="list-unstyled col-4 mb-0">
            <li class="mb-3"><a href="menu.php">Menu</a></li>
            <li class="mb-3"><a href="branches.php">Branches</a></li>
            <li><a href="#">Franchise</a></li>
          </ul>
          <ul class="list-unstyled col-4 mb-0">
            <li class="mb-3"><a href="order.php">Order Now</a></li>
            <li class="mb-3"><a href="our-story.php">Our Story</a></li>
            <li><a href="#">Promos</a></li>
          </ul>
          <ul class="list-unstyled col-4 mb-0">
            <li class="mb-3"><a href="#">Careers</a></li>
            <li class="mb-3"><a href="#">Terms of Use</a></li>
            <li><a href="#">Legal</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <div class="footer-bar">
    <div class="container d-flex justify-content-between align-items-center py-3">
      <span class="small">Copyright <?= date('Y') ?> Nomcha. All rights reserved.</span>
      <div class="d-flex align-items-center gap-3 fs-5">
        <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
        <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
        <a href="#" aria-label="X"><i class="bi bi-twitter-x"></i></a>
        <button type="button" class="btn-top" aria-label="Back to top" onclick="window.scrollTo({top:0,behavior:'smooth'})">
          <i class="bi bi-arrow-up"></i>
        </button>
      </div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?= $pageScripts ?? '' ?>
</body>
</html>
