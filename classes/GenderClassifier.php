<?php
/**
 * Détection / correction du genre catalogue (homme, femme, mixte).
 * Source unique pour sync, import CSV, quiz et reclassement en masse.
 */
class GenderClassifier
{
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
        $femmeTag = (float)($tagWeights['femme'] ?? 0);
        $hommeTag = (float)($tagWeights['homme'] ?? 0);

        if ($femmeTag > 0 || $hommeTag > 0) {
            if ($femmeTag > $hommeTag + 0.25) {
                return 'femme';
            }
            if ($hommeTag > $femmeTag + 0.25) {
                return 'homme';
            }
        }

        $fromName = self::fromName($name);
        if ($fromName === 'femme' || $fromName === 'homme') {
            return $fromName;
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
