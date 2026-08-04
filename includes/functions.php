<?php
/**
 * Fonctions utilitaires partagées.
 */

/**
 * Échappe une chaîne pour affichage HTML sûr.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Décode un champ JSON stocké en base, retourne un tableau vide si invalide.
 */
function jdecode(?string $json): array
{
    if (!$json) {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Encode un tableau en JSON pour stockage.
 */
function jencode($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

/**
 * Génère un token de session aléatoire.
 */
function generateSessionToken(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Récupère la première valeur non vide parmi plusieurs clés d'un tableau (tolérance aux noms de champs API différents).
 */
function firstNonEmpty(array $arr, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (isset($arr[$key]) && $arr[$key] !== '' && $arr[$key] !== null) {
            return $arr[$key];
        }
    }
    return $default;
}

/**
 * Nettoie une entrée POST/GET en chaîne simple.
 */
function cleanInput(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

/**
 * Redirige vers une URL et arrête l'exécution.
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Vérifie si l'admin est authentifié.
 */
function isAdminLoggedIn(): bool
{
    return !empty($_SESSION['is_admin']);
}

/**
 * Exige une authentification admin, sinon redirige vers la page de login.
 */
function requireAdmin(): void
{
    if (!isAdminLoggedIn()) {
        redirect('index.php');
    }
}

/**
 * Formate un prix pour affichage.
 */
function formatPrice($price): string
{
    $normalized = normalizeShopPrice($price);
    if ($normalized === null) {
        return '';
    }
    return number_format($normalized, 2, ',', ' ') . ' €';
}

/**
 * Arrondi monétaire à 2 décimales.
 */
function roundMoney($price): ?float
{
    if ($price === null || $price === '') {
        return null;
    }
    if (!is_numeric($price)) {
        return null;
    }
    return round((float)$price, 2);
}

/**
 * Parse une chaîne prix boutique (FR/EN) vers un float.
 */
function parseShopPrice(string $raw): ?float
{
    $raw = str_replace(["\xc2\xa0", ' ', '€', 'EUR', 'eur'], '', $raw);
    $raw = str_replace(',', '.', $raw);
    $raw = preg_replace('/[^\d.]/', '', $raw) ?? '';
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return normalizeShopPrice((float)$raw);
}

/**
 * Normalise un prix catalogue pour coller au TTC affiché sur zeparfums.com.
 *
 * - Convertit les prix HT typiques (TTC/1,2) vers le TTC boutique.
 * - Corrige les écarts d'1 centime dus aux arrondis flottants (46,91 → 46,90).
 */
function normalizeShopPrice($price): ?float
{
    $price = roundMoney($price);
    if ($price === null || $price <= 0) {
        return $price;
    }

    $cents = (int)round(fmod($price * 100, 100));
    // Centimes typiques après division d'un prix .90 / 1,20.
    $htLikeCents = [8, 25, 42, 58, 75, 92];
    if (in_array($cents, $htLikeCents, true)) {
        $asTtc = roundMoney($price * 1.2);
        if ($asTtc !== null) {
            $ttcCents = (int)round(fmod($asTtc * 100, 100));
            // Prix TTC boutique souvent en .00 / .50 / .90 / .95
            if (in_array($ttcCents, [0, 50, 90, 95], true)) {
                $price = $asTtc;
            }
        }
    }

    // Écart d'1 centime autour d'un dixième (46,91 → 46,90).
    $nearestTenth = round($price * 10) / 10;
    if (abs($price - $nearestTenth) <= 0.011) {
        $price = roundMoney($nearestTenth) ?? $price;
    }

    return roundMoney($price);
}

/**
 * Construit un lien WhatsApp pré-rempli pour commander un parfum.
 */
function whatsappLink(string $perfumeName, string $brand): string
{
    $text = rawurlencode("Bonjour, je souhaite commander le parfum \"$perfumeName\" ($brand). Est-il disponible ?");
    return 'https://wa.me/' . WHATSAPP_NUMBER . '?text=' . $text;
}

function whatsappButtonEnabled(): bool
{
    return getSetting('whatsapp_enabled', WHATSAPP_ENABLED_DEFAULT ? '1' : '0') === '1';
}

/**
 * URL d'un asset public avec cache-busting (?v=timestamp de modification).
 */
function asset(string $path): string
{
    $fullPath = __DIR__ . '/../public/' . ltrim($path, '/');
    $version = is_file($fullPath) ? filemtime($fullPath) : time();

    return $path . '?v=' . $version;
}

/**
 * Lit un réglage du site (table site_settings).
 */
function getSetting(string $key, ?string $default = null): string
{
    if ($default === null) {
        $default = '';
    }

    if (!isset($GLOBALS['_settings_cache'])) {
        $GLOBALS['_settings_cache'] = [];
        try {
            $db = getDb();
            $stmt = $db->query('SELECT setting_key, setting_value FROM site_settings');
            foreach ($stmt->fetchAll() as $row) {
                $GLOBALS['_settings_cache'][$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            // Table absente ou base indisponible : fallback sur les constantes.
        }
    }

    return $GLOBALS['_settings_cache'][$key] ?? $default;
}

/**
 * Enregistre un réglage du site.
 */
function setSetting(string $key, string $value): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute(['key' => $key, 'value' => $value]);

    if (!isset($GLOBALS['_settings_cache'])) {
        $GLOBALS['_settings_cache'] = [];
    }
    $GLOBALS['_settings_cache'][$key] = $value;
}

/**
 * Opacité du voile noir sur la vidéo d'accueil (0 à 1).
 */
function heroOverlayOpacity(): float
{
    $value = getSetting('hero_overlay_opacity', (string)HERO_OVERLAY_OPACITY_DEFAULT);

    return max(0.0, min(1.0, (float)$value));
}

/**
 * Liste des vidéos disponibles pour le hero.
 */
function heroVideoOptions(): array
{
    $videoDir = __DIR__ . '/../public/assets/video';
    $patterns = ['*.mp4', '*.webm', '*.ogg'];
    $files = [];

    foreach ($patterns as $pattern) {
        foreach (glob($videoDir . '/' . $pattern) ?: [] as $fullPath) {
            if (is_file($fullPath)) {
                $files[] = basename($fullPath);
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return $files;
}

/**
 * Nom du fichier vidéo sélectionné pour le hero.
 */
function heroVideoFilename(): string
{
    $default = defined('HERO_VIDEO_DEFAULT') ? HERO_VIDEO_DEFAULT : 'hero-background.mp4';
    $selected = basename(getSetting('hero_video_file', $default));
    $available = heroVideoOptions();

    if (in_array($selected, $available, true)) {
        return $selected;
    }

    if (in_array($default, $available, true)) {
        return $default;
    }

    return $available[0] ?? $default;
}

/**
 * Le bandeau/prix parrainage est-il activé ? Réglable dans /admin/settings.php.
 */
function referralEnabled(): bool
{
    return getSetting('referral_enabled', REFERRAL_ENABLED_DEFAULT ? '1' : '0') === '1';
}

/**
 * Pourcentage de réduction affiché quand le parrainage est activé.
 */
function referralDiscountAmount(): float
{
    $value = getSetting('referral_discount', (string)REFERRAL_DISCOUNT_DEFAULT);

    return max(0.0, min(100.0, (float)$value));
}

/**
 * Prix estimé après réduction parrainage en % (plancher à 0).
 */
function referralDiscountedPrice(?float $price): ?float
{
    $price = normalizeShopPrice($price);
    if ($price === null) {
        return null;
    }

    $percent = referralDiscountAmount();
    return roundMoney(max(0.0, $price * (1 - $percent / 100)));
}

/**
 * Bloc HTML du prix catalogue + estimation parrainage (si activé).
 */
function renderPerfumePriceBlock($price): string
{
    $catalogPrice = normalizeShopPrice($price);
    if ($catalogPrice === null) {
        return '';
    }

    $html = '<div class="result-price">';

    if (referralEnabled()) {
        $discount = referralDiscountAmount();
        $estimated = referralDiscountedPrice($catalogPrice);

        $html .= '<p class="result-price-label">Prix catalogue</p>';
        $html .= '<p class="result-price-original">' . e(formatPrice($catalogPrice)) . '</p>';
        $html .= '<p class="result-price-label-referral">Parrain / Filleul <span class="result-price-badge-large">-' . (int)$discount . '%</span>'
              . ' <span class="referral-info-trigger" tabindex="0" aria-label="Conditions de parrainage">Voir conditions</span></p>';
        $html .= '<div class="referral-info-bubble">'
              . '<p><strong>Conditions de parrainage :</strong></p>'
              . '<ul>'
              . '<li>Votre filleul obtient <strong>-' . (int)$discount . '%</strong> sur sa 1<sup>re</sup> commande <strong>(dès 50 € d\'achat)</strong>.</li>'
              . '<li>Vous profitez de <strong>-' . (int)$discount . '% pour chaque filleul ayant passé commande</strong>.</li>'
              . '</ul>'
              . '<p class="referral-info-note">Compte Ze Parfums requis. Créez-le sur zeparfums.com si vous n’êtes pas encore inscrit, puis demandez votre code dans « Mon Profil ». Offre non cumulable.</p>'
              . '</div>';
        $html .= '<p class="result-price-estimate-referral">' . e(formatPrice($estimated)) . '</p>';
    } else {
        $html .= '<p class="result-price-label">Avec Promo Ze Parfums</p>';
        $html .= '<p class="result-price-estimate">' . e(formatPrice($catalogPrice)) . '</p>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Valeurs par défaut de la vignette parrainage (page résultat).
 * Placeholders : {discount} = pourcentage de réduction.
 *
 * @return array{
 *   title:string,text1:string,text2:string,
 *   btn_label:string,btn_url:string,
 *   link_label:string,link_url:string,
 *   note:string
 * }
 */
function referralBannerDefaults(): array
{
    return [
        'title' => 'Offre parrainage Ze Parfums',
        'text1' => 'Pour profiter de cette recommandation et de l’offre parrain / filleul <strong>-{discount}%</strong>, vous devez disposer d’un compte sur Ze Parfums.',
        'text2' => 'Pas encore inscrit ? <strong>Créez votre compte gratuitement</strong> sur le site Ze Parfums (espace CSE), puis retrouvez votre code de parrainage dans la rubrique <strong>« Mon Profil »</strong>.',
        'btn_label' => 'Créer mon compte',
        'btn_url' => 'https://zeparfums.com/module/zeparfumsreg/inscription',
        'link_label' => 'Déjà inscrit ? Se connecter',
        'link_url' => 'https://zeparfums.com',
        'note' => 'La réduction s’applique dès votre 1<sup>re</sup> commande (conditions détaillées sous chaque prix).',
    ];
}

/**
 * Contenu éditable de la vignette (réglages admin + défauts).
 *
 * @return array{
 *   title:string,text1:string,text2:string,
 *   btn_label:string,btn_url:string,
 *   link_label:string,link_url:string,
 *   note:string,
 *   show_btn:bool,show_link:bool
 * }
 */
function referralBannerContent(): array
{
    $defaults = referralBannerDefaults();

    return [
        'title' => trim(getSetting('referral_banner_title', $defaults['title'])) ?: $defaults['title'],
        'text1' => trim(getSetting('referral_banner_text1', $defaults['text1'])) ?: $defaults['text1'],
        'text2' => trim(getSetting('referral_banner_text2', $defaults['text2'])) ?: $defaults['text2'],
        'btn_label' => trim(getSetting('referral_banner_btn_label', $defaults['btn_label'])) ?: $defaults['btn_label'],
        'btn_url' => sanitizeExternalUrl(getSetting('referral_banner_btn_url', $defaults['btn_url']), $defaults['btn_url']),
        'link_label' => trim(getSetting('referral_banner_link_label', $defaults['link_label'])) ?: $defaults['link_label'],
        'link_url' => sanitizeExternalUrl(getSetting('referral_banner_link_url', $defaults['link_url']), $defaults['link_url']),
        'note' => trim(getSetting('referral_banner_note', $defaults['note'])) ?: $defaults['note'],
        'show_btn' => getSetting('referral_banner_show_btn', '1') === '1',
        'show_link' => getSetting('referral_banner_show_link', '1') === '1',
    ];
}

/**
 * N’accepte que les URLs http(s) absolues.
 */
function sanitizeExternalUrl(string $url, string $fallback = '#'): string
{
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return $fallback;
    }
    return $url;
}

/**
 * Texte riche sécurisé pour la vignette (tags limités + {discount}).
 */
function referralBannerRichText(string $text): string
{
    $discount = (int)referralDiscountAmount();
    $text = str_replace(['{discount}', '{DISCOUNT}'], (string)$discount, $text);
    $text = strip_tags($text, '<strong><em><b><i><br><sup>');
    return $text;
}

/**
 * URL boutique Ze Parfums (espace CSE).
 */
function zeparfumsShopUrl(): string
{
    return referralBannerContent()['link_url'];
}

/**
 * Page d'inscription Ze Parfums (compte CSE requis pour commander).
 */
function zeparfumsRegisterUrl(): string
{
    return referralBannerContent()['btn_url'];
}

/**
 * Bandeau promotionnel parrainage (placé en bas des résultats).
 */
function renderReferralBanner(): string
{
    if (!referralEnabled()) {
        return '';
    }

    $c = referralBannerContent();
    $discount = (int)referralDiscountAmount();

    $html = '<aside class="referral-banner" id="offre-parrainage" role="note">'
        . '<p class="referral-banner-title">' . e(str_replace(['{discount}', '{DISCOUNT}'], (string)$discount, $c['title'])) . '</p>';

    if ($c['text1'] !== '') {
        $html .= '<p class="referral-banner-text">' . referralBannerRichText($c['text1']) . '</p>';
    }
    if ($c['text2'] !== '') {
        $html .= '<p class="referral-banner-text">' . referralBannerRichText($c['text2']) . '</p>';
    }

    $showBtn = !empty($c['show_btn']);
    $showLink = !empty($c['show_link']) && $c['link_label'] !== '';

    if ($showBtn || $showLink) {
        $html .= '<div class="referral-banner-actions">';
        if ($showBtn) {
            $html .= '<a href="' . e($c['btn_url']) . '" target="_blank" rel="noopener" class="btn-primary referral-banner-btn">'
                . e($c['btn_label']) . '</a>';
        }
        if ($showLink) {
            $html .= '<a href="' . e($c['link_url']) . '" target="_blank" rel="noopener" class="referral-banner-link">'
                . e($c['link_label']) . '</a>';
        }
        $html .= '</div>';
    }

    if ($c['note'] !== '') {
        $html .= '<p class="referral-banner-note">' . referralBannerRichText($c['note']) . '</p>';
    }

    $html .= '</aside>';

    return $html;
}

/**
 * Pastille discrète (mobile) : renvoie vers la vignette en bas de page.
 */
function renderReferralMobileTeaser(): string
{
    if (!referralEnabled()) {
        return '';
    }

    $discount = (int)referralDiscountAmount();
    $c = referralBannerContent();
    $title = str_replace(['{discount}', '{DISCOUNT}'], (string)$discount, $c['title']);

    return '<a href="#offre-parrainage" class="referral-mobile-teaser">'
        . '<span class="referral-mobile-teaser-label">' . e($title) . '</span>'
        . '<span class="referral-mobile-teaser-badge">-' . $discount . '%</span>'
        . '<span class="referral-mobile-teaser-hint" aria-hidden="true">↓</span>'
        . '</a>';
}

/**
 * Image de remplacement encodée en data-URI (aucune dépendance réseau/fichier).
 * Utilisée en attribut onerror des <img> pour ne jamais afficher d'image cassée.
 */
function placeholderDataUri(): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="500" viewBox="0 0 400 500">'
         . '<rect width="400" height="500" fill="rgb(239,231,218)"/>'
         . '<text x="50%" y="50%" font-family="Georgia, serif" font-size="60" fill="rgb(184,149,91)" '
         . 'text-anchor="middle" dominant-baseline="middle">&#10022;</text></svg>';

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

/**
 * Crée la table de suivi des recherches parfum si absente.
 */
function ensurePerfumeSearchesTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        getDb()->exec(
            "CREATE TABLE IF NOT EXISTS perfume_searches (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                query VARCHAR(255) NOT NULL,
                results_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                source ENUM('local','api','empty') NOT NULL DEFAULT 'local',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_created (created_at),
                KEY idx_query (query)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $ready = true;
    } catch (Throwable $e) {
        // Ne jamais bloquer le site public.
    }
}

/**
 * Enregistre une recherche parfum (parcours "parfum aimé").
 */
function logPerfumeSearch(string $query, int $resultsCount, string $source = 'local'): void
{
    $query = trim($query);
    if ($query === '' || mb_strlen($query) < 2) {
        return;
    }

    if (!in_array($source, ['local', 'api', 'empty'], true)) {
        $source = 'local';
    }

    try {
        ensurePerfumeSearchesTable();
        $stmt = getDb()->prepare(
            'INSERT INTO perfume_searches (query, results_count, source) VALUES (:q, :count, :source)'
        );
        $stmt->execute([
            'q' => mb_substr($query, 0, 255),
            'count' => max(0, $resultsCount),
            'source' => $source,
        ]);
    } catch (Throwable $e) {
        // Ne jamais bloquer la recherche.
    }
}

/**
 * Navigation admin partagée.
 */
function renderAdminNav(string $active = ''): void
{
    $items = [
        'dashboard' => ['href' => 'dashboard.php', 'label' => 'Dashboard'],
        'perfumes' => ['href' => 'perfumes.php', 'label' => 'Parfums'],
        'import' => ['href' => 'import.php', 'label' => 'Import API'],
        'import-csv' => ['href' => 'import-csv.php', 'label' => 'Import CSV'],
        'sync' => ['href' => 'sync.php', 'label' => 'Sync ZeParfums'],
        'tags' => ['href' => 'tags.php', 'label' => 'Tags'],
        'settings' => ['href' => 'settings.php', 'label' => 'Réglages'],
    ];

    echo '<nav class="admin-nav">';
    foreach ($items as $key => $item) {
        $class = $active === $key ? ' class="active"' : '';
        echo '<a href="' . e($item['href']) . '"' . $class . '>' . e($item['label']) . '</a>';
    }
    echo '<a href="logout.php" class="admin-logout">D&eacute;connexion</a>';
    echo '</nav>';
}
