<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Trouvez le parfum qui vous correspond — ' . SITE_NAME;
$heroOverlayOpacity = heroOverlayOpacity();
$heroVideoFile = heroVideoFilename();
$heroVideoExt = strtolower(pathinfo($heroVideoFile, PATHINFO_EXTENSION));
$heroVideoMime = 'video/mp4';
if ($heroVideoExt === 'webm') {
    $heroVideoMime = 'video/webm';
} elseif ($heroVideoExt === 'ogg') {
    $heroVideoMime = 'video/ogg';
}
require_once __DIR__ . '/../includes/header.php';
?>

<main class="home-screen home-screen--typewriter">
  <header class="home-topbar" aria-label="En-tete Ze Parfums">
    <img src="assets/img/logo-zeparfums.png" alt="ZE Parfums" class="home-topbar-logo">
    <a href="https://zeparfums.com" target="_blank" rel="noopener" class="home-topbar-link">Revenir sur le site</a>
  </header>

  <div class="home-video-bg" aria-hidden="true">
    <video autoplay muted loop playsinline id="heroVideo">
      <source src="<?= e(asset('assets/video/' . $heroVideoFile)) ?>" type="<?= e($heroVideoMime) ?>">
    </video>
    <div class="home-video-overlay" style="--hero-overlay-opacity: <?= $heroOverlayOpacity ?>"></div>
  </div>

  <div class="parallax-scene" aria-hidden="true">
    <div class="parallax-layer parallax-particles" data-depth="0.5">
      <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
    </div>
  </div>

  <div class="home-inner">
    <h1 class="home-title" id="heroTypewriter" aria-label="Trouvez Ze Parfums qui vous correspond."></h1>
    <p class="home-subtitle">
      Répondez à quelques questions ou partez d'un parfum que vous aimez déjà.
      Nous vous proposerons une sélection adaptée à vos goûts, à votre style et à vos envies.
    </p>

    <div class="home-choices">
      <a href="quiz.php" class="choice-card">
        <span class="choice-index">01</span>
        <h2>Je découvre mon parfum idéal</h2>
        <p>Quelques questions simples pour révéler vos préférences.</p>
        <span class="choice-arrow">→</span>
      </a>

      <a href="quiz.php?mode=favorite" class="choice-card">
        <span class="choice-index">02</span>
        <h2>Je pars d'un parfum que j'aime déjà</h2>
        <p>Indiquez un parfum connu et trouvez une alternative adaptée.</p>
        <span class="choice-arrow">→</span>
      </a>
    </div>
  </div>
</main>

<script src="assets/js/home.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
