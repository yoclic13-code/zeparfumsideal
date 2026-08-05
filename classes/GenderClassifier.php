<?php
/**
 * Détection / correction du genre catalogue (homme, femme, mixte).
 * Source unique pour sync, import CSV, quiz et reclassement en masse.
 */
class GenderClassifier
{
    /** Mots d’emballage / format à ignorer pour isoler la ligne parfum. */
    private const PACKAGING_WORDS = [
        'coffret', 'coffrets', 'set', 'kit', 'gift', 'cadeau', 'recharge', 'refill',
        'vaporisateur', 'spray', 'flacon', 'bottle', 'travel', 'miniature', 'miniatures',
        'decant', 'sample', 'tester', 'edition', 'collector', 'limited',
        'eau', 'parfum', 'toilette', 'intense', 'absolu', 'absolute', 'elixir',
        'extrait', 'concentree', 'concentrée', 'legere', 'légère',
        'edp', 'edt', 'edc', 'parfumée', 'naturelle',
        'ml', 'cl', 'oz', 'pour', 'de', 'du', 'la', 'le', 'les', 'des', 'un', 'une',
        'the', 'and', 'with', 'by',
    ];

    /** Indices forts dans le nom (priorité haute). */
    private const FEMME_MARKERS = [
        'pour femme', 'for her', 'pour elle', 'woman', 'women', 'femme',
        'mademoiselle', 'madame', 'lady', 'girl', 'goddess',
        'good girl', 'very good girl', 'miss dior', 'coco mademoiselle',
        'la vie est belle', 'black opium', 'j\'adore', 'jadore', 'idole', 'idôle',
        'olympea', 'olympéa', 'chanel chance', 'gucci bloom', 'bloom gucci',
        'alien', 'mugler angel', 'hypnotic poison', 'pure poison', 'poison girl',
        'ysl libre', 'libre intense', 'si intense', 'si passione', 'giorgio armani si',
        'l\'interdit', 'linterdit', 'mon paris', 'manifesto', 'jean paul gaultier scandal',
        'flowerbomb', 'bombshell', 'marc jacobs daisy', 'dolce garden',
        'yes i am', 'amour amour', 'kenzo flower', 'flower by kenzo',
        'elixir des merveilles', 'twilly', 'jour d\'hermes', 'jour d\'hermès',
        'delina', 'delina exclusif', 'baccarat rouge',
        'ariana grande cloud', 'prada candy', 'prada paradoxe', 'paradoxe intense',
        'giorgio armani my way', 'chanel gabrielle', 'n°5', 'n° 5', 'chanel n°19',
        'coco noir', 'allure sensuelle', 'chance eau tendre', 'chance eau vive',
        'white linen', 'clinique happy', 'bvlgari omnia', 'bulgari omnia',
        'mon guerlain', 'shalimar', 'innocent', 'amor amor',
    ];

    private const HOMME_MARKERS = [
        'pour homme', 'for him', 'pour lui', 'gentleman', 'masculin', 'homme',
        'sauvage', 'bleu de chanel', 'acqua di gio', 'acqua di giò',
        'invictus', '1 million', 'one million', 'le male', 'le mâle',
        'versace eros', 'spicebomb', 'creed aventus', 'aventus',
        'stronger with you', 'dior homme', 'prada luna rossa', 'luna rossa',
        'homme intense', 'homme sport', 'kouros', 'fahrenheit', 'farenheit',
        'terre d\'hermes', 'terre d\'hermès', 'habit rouge', 'eau sauvage',
        'polo blue', 'polo red', 'arman code', 'armani code',
        'montblanc legend', 'ultra male', 'ultramale', 'azzaro wanted',
        'azzaro chrome', 'cool water', 'davidoff cool water',
        'obsession for men', 'ck one shock for him',
        'givenchy gentleman', 'l\'homme', 'lhomme',
        'ysl y ', 'yves saint laurent y ',
        // Lignes récentes souvent en « mixte » à tort (ex. coffrets)
        'myslf', 'myself', ' ysl y', 'y edp', 'y eau',
        'the one for men', 'light blue forever', 'light blue pour homme',
        'acqua di gio profondo', 'armani code cologne',
        'montblanc explorer', 'legend spirit',
        'pacorabanne phantom', 'rabanne phantom', 'paco rabanne phantom',
        'born in roma uomo', 'valentino uomo',
        'spicebomb extreme', 'la nuit de l\'homme', 'la nuit de lhomme',
        'y le parfum', 'y eau de parfum',
    ];

    /**
     * Déduit le genre à partir du nom seul.
     */
    public static function fromName(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        if ($lower === '') {
            return 'mixte';
        }

        $femme = self::nameHits($lower, self::FEMME_MARKERS);
        $homme = self::nameHits($lower, self::HOMME_MARKERS);

        if ($femme && !$homme) {
            return 'femme';
        }
        if ($homme && !$femme) {
            return 'homme';
        }

        // Conflit rare : garder mixte.
        return 'mixte';
    }

    /**
     * Déduit le genre : tags olfactifs > indices nom > genre DB existant.
     *
     * @param array<string,float|int> $tagWeights
     */
    public static function resolve(string $name, array $tagWeights = [], ?string $currentGender = null): string
    {
        $fromName = self::fromName($name);

        $femmeTag = (float)($tagWeights['femme'] ?? 0);
        $hommeTag = (float)($tagWeights['homme'] ?? 0);

        // Nom explicite (ex. MYSLF, Sauvage) prime sur des tags absents / ambigus.
        if ($fromName === 'femme' || $fromName === 'homme') {
            if ($femmeTag <= 0 && $hommeTag <= 0) {
                return $fromName;
            }
            // Tag fort opposé au nom → on fait confiance au tag API.
            if ($fromName === 'femme' && $hommeTag > $femmeTag + 0.5) {
                return 'homme';
            }
            if ($fromName === 'homme' && $femmeTag > $hommeTag + 0.5) {
                return 'femme';
            }
            return $fromName;
        }

        if ($femmeTag > 0 || $hommeTag > 0) {
            if ($femmeTag > $hommeTag + 0.25) {
                return 'femme';
            }
            if ($hommeTag > $femmeTag + 0.25) {
                return 'homme';
            }
        }

        $current = strtolower(trim((string)$currentGender));
        if ($current === 'homme' || $current === 'femme' || $current === 'mixte') {
            return $current;
        }

        return 'mixte';
    }

    /**
     * Alias rétrocompatible avec CatalogSyncService / import CSV.
     */
    public static function detectGender(string $name): string
    {
        return self::fromName($name);
    }

    /**
     * Clé de ligne (ex. "ysl coffret myslf edp" → "myslf") pour héritage coffret ↔ flacon.
     */
    public static function lineKey(string $name, ?string $brand = null): string
    {
        $s = mb_strtolower(trim($name));
        if ($brand) {
            $brandLower = mb_strtolower(trim($brand));
            $s = preg_replace('/^' . preg_quote($brandLower, '/') . '\b\s*/u', '', $s, 1) ?? $s;
            $s = preg_replace('/\s*\b' . preg_quote($brandLower, '/') . '$/u', '', $s, 1) ?? $s;
            // Marques courtes fréquentes dans le titre
            foreach (['ysl', 'yves saint laurent', 'dior', 'chanel', 'rabanne', 'paco rabanne'] as $alias) {
                $s = preg_replace('/\b' . preg_quote($alias, '/') . '\b/u', ' ', $s) ?? $s;
            }
        }

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($transliterated !== false) {
            $s = $transliterated;
        }

        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        $words = array_values(array_filter(
            explode(' ', $s),
            static function (string $w): bool {
                if ($w === '' || is_numeric($w)) {
                    return false;
                }
                return !in_array($w, self::PACKAGING_WORDS, true);
            }
        ));

        return implode(' ', $words);
    }

    /**
     * Requête de recherche tierce (PerfumAPI / web) : marque + ligne sans « coffret ».
     */
    public static function searchQuery(string $name, ?string $brand = null): string
    {
        $line = self::lineKey($name, $brand);
        $brandClean = trim((string)$brand);
        if ($brandClean !== '' && $line !== '') {
            return $brandClean . ' ' . $line;
        }
        return $line !== '' ? $line : trim($name);
    }

    /**
     * @param list<string> $markers
     */
    private static function nameHits(string $haystack, array $markers): bool
    {
        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($haystack, $marker)) {
                return true;
            }
        }
        return false;
    }
}
