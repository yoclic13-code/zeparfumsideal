<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';

$mode = ($_GET['mode'] ?? 'quiz') === 'favorite' ? 'favorite' : 'quiz';
$pageTitle = 'Quelques questions — ' . SITE_NAME;

// Questions du parcours "quiz classique"
$questions = [
    [
        'key' => 'gender',
        'question' => 'À qui ce parfum est-il destiné ?',
        'options' => [
            ['value' => 'homme', 'label' => 'Pour un homme'],
            ['value' => 'femme', 'label' => 'Pour une femme'],
            ['value' => 'mixte', 'label' => 'Mixte'],
        ],
    ],
    [
        'key' => 'occasion',
        'question' => 'Pour quelle occasion souhaitez-vous ce parfum ?',
        'options' => [
            ['value' => 'quotidien', 'label' => 'Pour un usage quotidien'],
            ['value' => 'travail', 'label' => 'Pour le cadre professionnel'],
            ['value' => 'soiree', 'label' => 'Pour une soirée'],
            ['value' => 'mariage', 'label' => 'Pour un mariage / événement'],
            ['value' => 'cadeau', 'label' => 'Pour offrir en cadeau'],
        ],
    ],
    [
        'key' => 'budget',
        'type' => 'budget',
        'question' => 'Quel budget maximal ne souhaitez-vous pas dépasser ?',
    ],
    [
        'key' => 'family',
        'question' => 'Quelles familles olfactives correspondent le mieux à vos préférences ?',
        'multi' => true,
        'maxSelect' => 3,
        'options' => [
            ['value' => 'frais', 'label' => 'Frais', 'hint' => 'Évoque une brise d\'agrumes, légère et vivifiante', 'image' => 'assets/img/familles/frais.jpg'],
            ['value' => 'sucre', 'label' => 'Sucré', 'hint' => 'Rappelle des notes de caramel ou de vanille', 'image' => 'assets/img/familles/sucre.jpg'],
            ['value' => 'oriental', 'label' => 'Oriental', 'hint' => 'Inspiré de l\'ambre et des épices, chaud et enveloppant', 'image' => 'assets/img/familles/oriental.jpg'],
            ['value' => 'boise', 'label' => 'Boisé', 'hint' => 'Évoque le cèdre ou le bois de santal', 'image' => 'assets/img/familles/boise.jpg'],
            ['value' => 'floral', 'label' => 'Floral', 'hint' => 'Rappelle un bouquet de rose ou de jasmin', 'image' => 'assets/img/familles/floral.jpg'],
            ['value' => 'musque', 'label' => 'Musqué', 'hint' => 'Sensation de peau propre, douce et élégante', 'image' => 'assets/img/familles/musque.jpg'],
            ['value' => 'gourmand', 'label' => 'Gourmand', 'hint' => 'Évoque une pâtisserie, pralinée et vanillée', 'image' => 'assets/img/familles/gourmand.jpg'],
        ],
    ],
    [
        'key' => 'intensity',
        'question' => 'Quel niveau de concentration privilégiez-vous ?',
        'options' => [
            ['value' => 'discret', 'label' => 'Eau de Cologne', 'hint' => 'Légère et rafraîchissante, idéale au quotidien'],
            ['value' => 'equilibre', 'label' => 'Eau de Toilette', 'hint' => 'Fraîche et équilibrée, avec une tenue modérée'],
            ['value' => 'puissant', 'label' => 'Eau de Parfum', 'hint' => 'Plus intense, avec une tenue prolongée'],
            ['value' => 'tres_intense', 'label' => 'Parfum / Extrait', 'hint' => 'Très concentré, au sillage marqué et durable'],
        ],
    ],
    [
        'key' => 'mood',
        'question' => 'Quelle impression souhaitez-vous laisser ?',
        'options' => [
            ['value' => 'propre', 'label' => 'Propre et fraîche'],
            ['value' => 'elegant', 'label' => 'Élégante'],
            ['value' => 'seducteur', 'label' => 'Séduisante'],
            ['value' => 'doux', 'label' => 'Douce et rassurante'],
            ['value' => 'luxueux', 'label' => 'Luxueuse'],
            ['value' => 'original', 'label' => 'Originale'],
        ],
    ],
    [
        'key' => 'season',
        'question' => 'Pour quelle saison ce parfum sera-t-il principalement porté ?',
        'options' => [
            ['value' => 'ete', 'label' => 'Été'],
            ['value' => 'hiver', 'label' => 'Hiver'],
            ['value' => 'toute_annee', 'label' => 'Tout au long de l\'année'],
            ['value' => 'printemps', 'label' => 'Printemps'],
            ['value' => 'automne', 'label' => 'Automne'],
        ],
    ],
];

$preferenceOptions = [
    ['value' => 'similaire', 'label' => 'Très proche de celui-ci'],
    ['value' => 'plus_frais', 'label' => 'Plus fraîche'],
    ['value' => 'plus_sucre', 'label' => 'Plus sucrée'],
    ['value' => 'plus_puissant', 'label' => 'Plus intense'],
    ['value' => 'plus_discret', 'label' => 'Plus discrète'],
    ['value' => 'plus_oriental', 'label' => 'Plus orientale'],
    ['value' => 'plus_elegant', 'label' => 'Plus élégante'],
    ['value' => 'moins_cher', 'label' => 'À un prix plus accessible'],
    ['value' => 'soir', 'label' => 'Davantage adaptée au soir'],
    ['value' => 'quotidien', 'label' => 'Davantage adaptée au quotidien'],
];

require_once __DIR__ . '/../includes/header.php';
?>

<main class="quiz-screen" data-mode="<?= e($mode) ?>">
  <div class="parallax-scene parallax-scene-subtle" aria-hidden="true">
    <div class="parallax-layer parallax-glow parallax-glow-a" data-depth="0.1"></div>
    <div class="parallax-layer parallax-glow parallax-glow-b" data-depth="0.15"></div>
    <div class="parallax-layer parallax-particles" data-depth="0.3">
      <span></span><span></span><span></span><span></span><span></span><span></span>
    </div>
  </div>

  <div class="quiz-topbar">
    <a href="index.php" class="btn-back" aria-label="Retour">←</a>
    <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
  </div>

  <?php if ($mode === 'quiz'): ?>
    <div id="quizQuestions" class="quiz-steps"></div>
  <?php else: ?>
    <!-- Étape 1 : recherche d'un parfum aimé -->
    <section class="quiz-step active" data-step="search">
      <p class="eyebrow">Étape 1</p>
      <h2 class="quiz-question">Quel parfum appréciez-vous déjà ?</h2>
      <div class="search-box">
        <input type="text" id="perfumeSearchInput" placeholder="Exemple : Dior Sauvage, Baccarat Rouge, Bleu de Chanel…" autocomplete="off">
        <div id="searchResults" class="search-results"></div>
      </div>
      <div id="selectedPerfumeCard" class="selected-perfume-card" style="display:none;"></div>
      <button type="button" class="btn-primary" id="favoriteNextBtn" disabled>Continuer</button>
    </section>

    <!-- Étape 2 : préférence de variation -->
    <section class="quiz-step" data-step="preference">
      <p class="eyebrow">Étape 2</p>
      <h2 class="quiz-question">Souhaitez-vous une recommandation…</h2>
      <div class="options-grid" id="preferenceOptions">
        <?php foreach ($preferenceOptions as $opt): ?>
          <button type="button" class="option-btn" data-value="<?= e($opt['value']) ?>"><?= e($opt['label']) ?></button>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>

<script>
  window.QUIZ_MODE = <?= json_encode($mode) ?>;
  window.QUIZ_QUESTIONS = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
  window.PLACEHOLDER_IMG = <?= json_encode(placeholderDataUri()) ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
