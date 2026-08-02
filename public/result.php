<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/PerfumeRepository.php';
require_once __DIR__ . '/../classes/QuizEngine.php';

$db = getDb();
$engine = new QuizEngine($db);
$repo = new PerfumeRepository($db);

$pathType = 'quiz';
$results = [];
$startingPerfume = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = cleanInput($_POST['mode'] ?? 'quiz');

    if ($mode === 'favorite') {
        $pathType = 'favorite';
        $perfumeId = (int)($_POST['perfume_id'] ?? 0);
        $preference = cleanInput($_POST['preference'] ?? 'similaire');

        if ($perfumeId > 0) {
            $startingPerfume = $repo->findById($perfumeId);
            $results = $engine->recommendFromFavoritePerfume($perfumeId, $preference, 3);
        }
    } else {
        $answersRaw = $_POST['answers'] ?? '[]';
        $answers = json_decode($answersRaw, true);
        $answers = is_array($answers) ? array_map('strval', $answers) : [];
        $coffretsOnly = ($_POST['coffrets_only'] ?? '0') === '1';
        $maxPriceRaw = $_POST['max_price'] ?? '';
        $maxPrice = null;
        if ($maxPriceRaw !== '' && is_numeric($maxPriceRaw) && (float)$maxPriceRaw > 0) {
            $maxPrice = (float)$maxPriceRaw;
        }
        $results = $engine->recommendFromQuiz($answers, 3, $coffretsOnly, $maxPrice);
    }

    // Sauvegarde de la session en base
    try {
        $token = generateSessionToken();
        $stmt = $db->prepare(
            "INSERT INTO quiz_sessions (session_token, path_type, selected_perfume_id, answers_json, result_perfume_id)
             VALUES (:token, :path, :sel, :answers, :result)"
        );
        $stmt->execute([
            'token'   => $token,
            'path'    => $pathType,
            'sel'     => $startingPerfume['id'] ?? null,
            'answers' => $_POST['answers'] ?? $_POST['preference'] ?? null,
            'result'  => $results[0]['perfume']['id'] ?? null,
        ]);
        $sessionId = (int)$db->lastInsertId();

        if (!empty($results)) {
            $ins = $db->prepare(
                "INSERT INTO quiz_results (session_id, perfume_id, score, position, reason_text) VALUES (:sid, :pid, :score, :pos, :reason)"
            );
            foreach ($results as $r) {
                $ins->execute([
                    'sid'    => $sessionId,
                    'pid'    => $r['perfume']['id'],
                    'score'  => $r['score'],
                    'pos'    => $r['position'],
                    'reason' => $r['reason_text'],
                ]);
            }
        }
    } catch (Throwable $e) {
        // La persistance de session ne doit jamais bloquer l'affichage du résultat.
    }
} else {
    redirect('index.php');
}

$pageTitle = 'Votre parfum recommandé — ' . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<main class="result-screen">
  <div class="parallax-scene parallax-scene-subtle" aria-hidden="true">
    <div class="parallax-layer parallax-glow parallax-glow-a" data-depth="0.1"></div>
    <div class="parallax-layer parallax-glow parallax-glow-b" data-depth="0.15"></div>
    <div class="parallax-layer parallax-particles" data-depth="0.3">
      <span></span><span></span><span></span><span></span><span></span><span></span>
    </div>
  </div>

  <?php if (empty($results)): ?>
    <div class="result-empty">
      <h1>Aucun résultat trouvé</h1>
      <p>Nous n'avons pas pu déterminer de recommandation. Merci de recommencer le quiz.</p>
      <a href="index.php" class="btn-primary">Recommencer le quiz</a>
    </div>
  <?php else: ?>
    <?php $main = $results[0]; $alts = array_slice($results, 1); $p = $main['perfume']; ?>

    <div class="result-inner">
      <p class="eyebrow">Votre sélection</p>
      <h1 class="result-title">Votre parfum recommandé</h1>
      <p class="result-subtitle">D'après vos réponses, ce parfum semble être le meilleur choix pour vous.</p>

      <?= renderReferralBanner() ?>

      <div class="result-main-card">
        <div class="result-image">
          <?php if (!empty($p['image_url'])): ?>
            <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" onerror="this.onerror=null;this.src='<?= placeholderDataUri() ?>';">
          <?php else: ?>
            <div class="result-image-placeholder">✦</div>
          <?php endif; ?>
        </div>
        <div class="result-details">
          <span class="result-score">Compatibilité <?= (int)$main['percent'] ?>%</span>
          <h2><?= e($p['name']) ?></h2>
          <p class="result-brand"><?= e($p['brand']) ?></p>

          <?= renderPerfumePriceBlock($p['price'] ?? null) ?>

          <?php if (!empty($p['rating'])): ?>
            <p class="result-rating">
              ★ <?= e(number_format((float)$p['rating'], 2)) ?>/5
              <?php if (!empty($p['votes'])): ?>
                <span class="result-votes">(<?= number_format((int)$p['votes'], 0, ',', ' ') ?> avis)</span>
              <?php endif; ?>
            </p>
          <?php endif; ?>

          <p class="result-reason"><?= e($main['reason_text']) ?></p>

          <?php
            $top = jdecode($p['top_notes']);
            $accords = jdecode($p['accords']);
          ?>
          <?php if (!empty($top)): ?>
            <div class="result-tags-block">
              <h3>Notes principales</h3>
              <div class="tag-pills"><?php foreach ($top as $n): ?><span class="pill"><?= e($n) ?></span><?php endforeach; ?></div>
            </div>
          <?php endif; ?>
          <?php if (!empty($accords)): ?>
            <div class="result-tags-block">
              <h3>Accords principaux</h3>
              <div class="tag-pills"><?php foreach ($accords as $a): ?><span class="pill pill-gold"><?= e($a) ?></span><?php endforeach; ?></div>
            </div>
          <?php endif; ?>

          <div class="result-actions">
            <?php if (!empty($p['product_url'])): ?>
              <a href="<?= e($p['product_url']) ?>" target="_blank" rel="noopener" class="btn-secondary">Voir le parfum</a>
            <?php endif; ?>
            <?php if (whatsappButtonEnabled()): ?>
              <a href="<?= e(whatsappLink($p['name'], $p['brand'] ?? '')) ?>" target="_blank" rel="noopener" class="btn-primary">Commander sur WhatsApp</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (!empty($alts)): ?>
        <h3 class="alt-title">Alternatives</h3>
        <div class="alt-grid">
          <?php foreach ($alts as $alt): $ap = $alt['perfume']; ?>
            <div class="alt-card">
              <div class="alt-image">
                <?php if (!empty($ap['image_url'])): ?>
                  <img src="<?= e($ap['image_url']) ?>" alt="<?= e($ap['name']) ?>" onerror="this.onerror=null;this.src='<?= placeholderDataUri() ?>';">
                <?php else: ?>
                  <div class="result-image-placeholder small">✦</div>
                <?php endif; ?>
              </div>
              <span class="result-score small">Compatibilité <?= (int)$alt['percent'] ?>%</span>
              <h4><?= e($ap['name']) ?></h4>
              <p class="alt-brand"><?= e($ap['brand']) ?></p>

              <?= renderPerfumePriceBlock($ap['price'] ?? null) ?>

              <?php if (!empty($ap['rating'])): ?>
                <p class="result-rating small">
                  ★ <?= e(number_format((float)$ap['rating'], 2)) ?>/5
                  <?php if (!empty($ap['votes'])): ?>
                    <span class="result-votes">(<?= number_format((int)$ap['votes'], 0, ',', ' ') ?> avis)</span>
                  <?php endif; ?>
                </p>
              <?php endif; ?>

              <p class="alt-reason"><?= e($alt['reason_text']) ?></p>

              <?php
                $altTop = jdecode($ap['top_notes']);
                $altAccords = jdecode($ap['accords']);
              ?>
              <?php if (!empty($altTop)): ?>
                <div class="result-tags-block small">
                  <h5>Notes principales</h5>
                  <div class="tag-pills"><?php foreach ($altTop as $n): ?><span class="pill small"><?= e($n) ?></span><?php endforeach; ?></div>
                </div>
              <?php endif; ?>
              <?php if (!empty($altAccords)): ?>
                <div class="result-tags-block small">
                  <h5>Accords principaux</h5>
                  <div class="tag-pills"><?php foreach ($altAccords as $a): ?><span class="pill pill-gold small"><?= e($a) ?></span><?php endforeach; ?></div>
                </div>
              <?php endif; ?>

              <div class="result-actions">
                <?php if (!empty($ap['product_url'])): ?>
                  <a href="<?= e($ap['product_url']) ?>" target="_blank" rel="noopener" class="btn-secondary small">Voir le parfum</a>
                <?php endif; ?>
                <?php if (whatsappButtonEnabled()): ?>
                  <a href="<?= e(whatsappLink($ap['name'], $ap['brand'] ?? '')) ?>" target="_blank" rel="noopener" class="btn-primary small">Commander</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="result-restart">
        <a href="index.php" class="link-restart">↺ Recommencer le quiz</a>
      </div>
    </div>
  <?php endif; ?>
</main>

<script>
document.addEventListener('click', function(e) {
  var trigger = e.target.closest('.referral-info-trigger');
  document.querySelectorAll('.referral-info-bubble.is-visible').forEach(function(b) {
    var tl = trigger ? (trigger.closest('.result-price-label') || trigger.closest('.result-price-label-referral')) : null;
    if (!tl || b !== tl.nextElementSibling) b.classList.remove('is-visible');
  });
  if (trigger) {
    var label = trigger.closest('.result-price-label') || trigger.closest('.result-price-label-referral');
    var bubble = label ? label.nextElementSibling : null;
    if (bubble && !bubble.classList.contains('referral-info-bubble')) bubble = null;
    if (bubble && bubble.classList.contains('referral-info-bubble')) bubble.classList.toggle('is-visible');
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
