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

    /**
     * Marques quasi exclusivement féminines (sauf si token homme explicite).
     * @var array<string,string>
     */
    private const BRAND_DEFAULTS = [
        'lolita lempicka' => 'femme',
        'lolita' => 'femme',
        'lancôme' => 'femme',
        'lancome' => 'femme',
        'nina ricci' => 'femme',
        'chloé' => 'femme',
        'chloe' => 'femme',
        'marc jacobs' => 'femme',
        'escada' => 'femme',
        'jimmy choo' => 'femme',
        'elie saab' => 'femme',
        'carolina herrera' => 'femme', // CH Men géré via marqueurs homme
    ];

    /** Indices forts dans le nom (priorité haute). */
    private const FEMME_MARKERS = [
        'pour femme', 'for her', 'pour elle', 'for women', 'woman', 'women', 'femme',
        'mademoiselle', 'madame', 'lady', 'girl', 'goddess', 'donna',
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
        'giorgio armani my way', 'my way', 'chanel gabrielle',
        'coco noir', 'allure sensuelle', 'chance eau tendre', 'chance eau vive',
        'white linen', 'clinique happy', 'bvlgari omnia', 'bulgari omnia',
        'mon guerlain', 'shalimar', 'innocent', 'amor amor',
        // Valentino / Lolita / designer femme
        'born in roma', 'born in roma coral fantasy', 'born in roma yellow dream',
        'born in roma green stravaganza', 'voce viva', 'valentina',
        'mon premier', 'lolitaland', 'lolita lempicka',
        'wanted girl', 'wanted tonic girl',
        'burberry her', 'her london dream', 'la bomba',
        'baiser vole', 'baiser volé', 'loulou', 'ella ella', 'ciao bella',
        'in love with you', 'rose alexandrie', 'rose d\'arabie',
        'chanel coco', 'chanel allure', 'chanel n',
        'la tulipe', 'inflorescence', 'lil fleur',
        'bowtastic', 'rose cruise',
        'this is her', 'this is her!', 'zadig this is her',
    ];

    private const HOMME_MARKERS = [
        'pour homme', 'for him', 'pour lui', 'for men', 'gentleman', 'masculin', 'homme',
        'uomo',
        'sauvage', 'bleu de chanel', 'acqua di gio', 'acqua di giò',
        'invictus', '1 million', 'one million', 'le male', 'le mâle',
        'versace eros', 'spicebomb', 'creed aventus', 'aventus',
        'stronger with you', 'dior homme', 'prada luna rossa', 'luna rossa',
        'homme intense', 'homme sport', 'kouros', 'fahrenheit', 'farenheit',
        'terre d\'hermes', 'terre d\'hermès', 'habit rouge', 'eau sauvage',
        'polo blue', 'polo red', 'arman code', 'armani code',
        'montblanc legend', 'ultra male', 'ultramale',
        'azzaro chrome', 'cool water', 'davidoff cool water',
        'obsession for men', 'ck one shock for him',
        'givenchy gentleman', 'l\'homme', 'lhomme',
        'ysl y ', 'yves saint laurent y ',
        'myslf', 'myself', ' ysl y', 'y edp', 'y eau',
        'the one for men', 'light blue forever', 'light blue pour homme',
        'acqua di gio profondo', 'armani code cologne',
        'montblanc explorer', 'legend spirit',
        'pacorabanne phantom', 'rabanne phantom', 'paco rabanne phantom',
        'born in roma uomo', 'valentino uomo',
        'spicebomb extreme', 'la nuit de l\'homme', 'la nuit de lhomme',
        'y le parfum', 'y eau de parfum',
        'the most wanted', 'forever wanted', 'wanted by night',
        'pasha', 'mister marvelous', 'ch men', 'carolina herrera men',
        'brit for men', 'london for men', 'touch for men', 'weekend for men',
        'burberry for men',
        'this is him', 'this is him!', 'zadig this is him',
    ];

    /**
     * Déduit le genre à partir du nom seul.
     */
    public static function fromName(string $name, ?string $brand = null): string
    {
        $lower = mb_strtolower(trim($name));
        if ($lower === '') {
            return 'mixte';
        }

        // 1) Tokens explicites (donna / uomo / for men…) — priorité absolue.
        $explicit = self::explicitGender($lower);
        if ($explicit !== null) {
            return $explicit;
        }

        // Born In Roma : Uomo/EDT → homme, Donna/EDP → femme (coffrets inclus).
        if (str_contains($lower, 'born in roma')) {
            if (str_contains($lower, 'toilette')) {
                return 'homme';
            }
            return 'femme';
        }

        // 2) Chanel N°5 / N°19 même avec caractères bizarres (N░5).
        if (str_contains($lower, 'chanel') && preg_match('/\bn[\W_]?(5|19)\b/u', $lower)) {
            return 'femme';
        }

        $femmeHits = self::matchingMarkers($lower, self::FEMME_MARKERS);
        $hommeHits = self::matchingMarkers($lower, self::HOMME_MARKERS);

        if ($femmeHits !== [] && $hommeHits === []) {
            return 'femme';
        }
        if ($hommeHits !== [] && $femmeHits === []) {
            return 'homme';
        }
        if ($femmeHits !== [] && $hommeHits !== []) {
            // « Wanted Girl » vs marqueurs Wanted homme.
            if (str_contains($lower, 'girl') && !preg_match('/\buomo\b|\bmen\b|for men|pour homme/u', $lower)) {
                return 'femme';
            }
            // Le marqueur le plus long gagne (ex. « born in roma uomo » > « born in roma »).
            $bestF = max(array_map('strlen', $femmeHits));
            $bestH = max(array_map('strlen', $hommeHits));
            if ($bestH > $bestF) {
                return 'homme';
            }
            if ($bestF > $bestH) {
                return 'femme';
            }
            return 'mixte';
        }

        // 3) Défaut marque (ex. Lolita Lempicka → femme).
        $brandGender = self::brandDefault($brand, $name);
        if ($brandGender !== null) {
            return $brandGender;
        }

        return 'mixte';
    }

    /**
     * Déduit le genre : nom (fort) > tags > défaut marque > genre DB.
     *
     * @param array<string,float|int> $tagWeights
     */
    public static function resolve(string $name, array $tagWeights = [], ?string $currentGender = null, ?string $brand = null): string
    {
        $fromName = self::fromName($name, $brand);

        $femmeTag = (float)($tagWeights['femme'] ?? 0);
        $hommeTag = (float)($tagWeights['homme'] ?? 0);

        if ($fromName === 'femme' || $fromName === 'homme') {
            if ($femmeTag <= 0 && $hommeTag <= 0) {
                return $fromName;
            }
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
            foreach (['ysl', 'yves saint laurent', 'dior', 'chanel', 'rabanne', 'paco rabanne', 'valentino', 'lolita lempicka'] as $alias) {
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
     * Base de ligne sans donna/uomo/variantes pour héritage coffret → flacon.
     */
    public static function lineBase(string $name, ?string $brand = null): string
    {
        $key = self::lineKey($name, $brand);
        $drop = ['donna', 'uomo', 'men', 'women', 'her', 'him', 'girl', 'boy'];
        $words = array_values(array_filter(
            explode(' ', $key),
            static fn(string $w): bool => $w !== '' && !in_array($w, $drop, true)
        ));
        // Garde les 4 premiers mots significatifs (ex. born in roma …).
        return implode(' ', array_slice($words, 0, 4));
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

    private static function explicitGender(string $lower): ?string
    {
        $hasUomo = (bool)preg_match('/\buomo\b/u', $lower);
        $hasDonna = (bool)preg_match('/\bdonna\b/u', $lower);
        $hasWomen = str_contains($lower, 'for women')
            || str_contains($lower, 'pour femme')
            || str_contains($lower, 'pour elle')
            || str_contains($lower, 'for her')
            || str_contains($lower, 'this is her')
            || (bool)preg_match('/\bwomen\b/u', $lower)
            || (bool)preg_match('/\bwoman\b/u', $lower);
        $hasMen = str_contains($lower, 'for men')
            || str_contains($lower, 'pour homme')
            || str_contains($lower, 'pour lui')
            || str_contains($lower, 'for him')
            || str_contains($lower, 'this is him')
            || (bool)preg_match('/\bmen\b/u', $lower);

        // « Wanted Girl » : girl ≠ men
        if (str_contains($lower, 'girl') && !$hasUomo && !$hasMen) {
            // ne pas court-circuiter ici : laissé aux marqueurs femme
        }

        if ($hasUomo && !$hasDonna) {
            return 'homme';
        }
        if ($hasDonna && !$hasUomo) {
            return 'femme';
        }
        if ($hasMen && !$hasWomen) {
            return 'homme';
        }
        if ($hasWomen && !$hasMen) {
            return 'femme';
        }

        return null;
    }

    private static function brandDefault(?string $brand, string $name): ?string
    {
        $hay = mb_strtolower(trim(($brand ?? '') . ' ' . $name));
        foreach (self::BRAND_DEFAULTS as $needle => $gender) {
            if (str_contains($hay, $needle)) {
                // Exception CH Men / Carolina Herrera men
                if ($gender === 'femme' && (
                    str_contains($hay, ' men')
                    || str_contains($hay, 'uomo')
                    || str_contains($hay, 'for him')
                    || str_contains($hay, 'pour homme')
                )) {
                    return 'homme';
                }
                return $gender;
            }
        }
        return null;
    }

    /**
     * @param list<string> $markers
     * @return list<string>
     */
    private static function matchingMarkers(string $haystack, array $markers): array
    {
        $hits = [];
        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($haystack, $marker)) {
                $hits[] = $marker;
            }
        }
        return $hits;
    }
}
