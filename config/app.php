<?php
/**
 * Configuration générale de l'application.
 */

// URL de base de l'API PerfumAPI (https://github.com/seccaz/PerfumAPI)
// Ne jamais appeler cette URL depuis le frontend / JS public.
define('PERFUM_API_BASE_URL', 'http://localhost:9000');
define('PERFUM_API_KEY', ''); // si l'API en déploie une, sinon laisser vide

// Mot de passe d'accès à l'admin (à changer impérativement)
define('ADMIN_PASSWORD', 'admin123');

// Numéro WhatsApp pour le bouton "Commander sur WhatsApp" (format international, sans +)
define('WHATSAPP_NUMBER', '33695375945');
define('WHATSAPP_ENABLED_DEFAULT', false);

// Clé API Freepik — utilisée UNIQUEMENT côté serveur (script d'import d'images), jamais exposée au frontend.
define('FREEPIK_API_KEY', 'MSd8a497ed0c92411b8f7043ceca44243e');

// Nom du site
define('SITE_NAME', 'Trouvez Votre Parfum');

// Opacité par défaut du voile noir sur la vidéo d'accueil (0 à 1)
define('HERO_OVERLAY_OPACITY_DEFAULT', 0.55);
define('HERO_VIDEO_DEFAULT', 'hero-background.mp4');

// Bandeau de parrainage sur la page résultat (réduction affichée, désactivable dans l'admin)
define('REFERRAL_ENABLED_DEFAULT', false);
define('REFERRAL_DISCOUNT_DEFAULT', 10.0);

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Session (utilisée par l'admin et le suivi de quiz)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
