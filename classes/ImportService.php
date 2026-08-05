<?php
/**
 * Service d'import : récupère les parfums depuis PerfumApiClient,
 * normalise les champs (tolérant aux variations de noms de l'API),
 * les enregistre dans la base locale et génère les tags automatiquement.
 */
class ImportService
{
    private PDO $db;
    private PerfumApiClient $api;
    private PerfumeRepository $repo;

    /** Familles olfactives du quiz (question à choix unique) — voir limitDominantFamilies(). */
    private const FAMILY_TAGS = ['frais', 'sucre', 'oriental', 'boise', 'floral', 'musque', 'gourmand'];

    /** Mapping notes olfactives -> tags avec poids */
    private const NOTE_TAG_MAP = [
        'vanilla'       => ['sucre' => 1.5, 'gourmand' => 1.5, 'doux' => 1.2],
        'musk'          => ['musque' => 1.5, 'propre' => 1.2, 'doux' => 1.0],
        'oud'           => ['oriental' => 1.8, 'puissant' => 1.5, 'luxueux' => 1.2],
        'amber'         => ['oriental' => 1.5, 'chaud' => 1.2, 'hiver' => 1.0],
        'bergamot'      => ['frais' => 1.5, 'ete' => 1.2, 'propre' => 1.0],
        'rose'          => ['floral' => 1.5, 'elegant' => 1.2],
        'jasmine'       => ['floral' => 1.5, 'feminin' => 1.2, 'elegant' => 1.0],
        'cedar'         => ['boise' => 1.5, 'masculin' => 1.2, 'elegant' => 1.0],
        'sandalwood'    => ['boise' => 1.5, 'doux' => 1.2, 'cremeux' => 1.0],
        'vetiver'       => ['boise' => 1.5, 'frais' => 1.2, 'masculin' => 1.0],
        'tonka bean'    => ['sucre' => 1.5, 'gourmand' => 1.5, 'chaud' => 1.0],
        'caramel'       => ['sucre' => 1.5, 'gourmand' => 1.5],
        'patchouli'     => ['oriental' => 1.2, 'boise' => 1.2, 'puissant' => 1.2],
        'citrus'        => ['frais' => 1.5, 'ete' => 1.2],
        'aquatic notes' => ['frais' => 1.5, 'propre' => 1.2, 'ete' => 1.0],
    ];

    public function __construct(PDO $db)
    {
        $this->db  = $db;
        $this->api = new PerfumApiClient($db);
        $this->repo = new PerfumeRepository($db);
    }

    /**
     * Importe une page de parfums depuis l'API. Retourne le nombre importés.
     */
    public function importPage(int $limit = 100, int $offset = 0): array
    {
        $raw = $this->api->getPerfumes($limit, $offset);
        $items = $this->extractList($raw);

        $imported = 0;
        foreach ($items as $item) {
            $this->importOne($item);
            $imported++;
        }

        return ['imported' => $imported, 'total_seen' => count($items)];
    }

    /**
     * Recherche + import à la demande (utilisé par search-perfume.php côté serveur).
     */
    public function searchAndImport(string $query, int $limit = 10): array
    {
        $raw = $this->api->searchPerfume($query, $limit);
        $items = $this->extractList($raw);

        $ids = [];
        foreach ($items as $item) {
            $ids[] = $this->importOne($item);
        }

        return $ids;
    }

    /**
     * Mots purement grammaticaux à ignorer lors de la comparaison de noms.
     * Volontairement PAS "coffret", "intense", "absolu", "parfum", "toilette", "homme", "femme"...
     * qui distinguent de vraies variantes différentes (ex: "Shalimar" vs "Shalimar Coffret" vs
     * "Shalimar Eau de Toilette" sont des produits distincts, pas des synonymes).
     */
    private const NAME_FILLER_WORDS = [
        'eau', 'de', 'du', 'la', 'le', 'des', 'pour', 'edp', 'edt',
        'ml', 'vaporisateur', 'naturelle', 'rechargeable',
        'coffret', 'coffrets', 'set', 'kit', 'cadeau', 'gift', 'recharge',
        'toilette', 'parfum', 'intense', 'absolu', 'elixir', 'flacon',
    ];

    /**
     * Normalise un nom de parfum pour comparaison : minuscule, sans accents, marque retirée une
     * seule fois en préfixe (convention CSV : "MARQUE Nom") ou en suffixe (convention PerfumAPI :
     * "Nom Marque"), mots purement grammaticaux retirés. Retourne l'ensemble des mots restants.
     *
     * Le retrait de marque est ancré en début/fin (pas une simple substitution globale) pour ne
     * pas casser les parfums homonymes de leur marque (ex: "Mon Guerlain" de Guerlain).
     */
    private function coreWords(string $name, ?string $brand): array
    {
        $s = mb_strtolower(trim($name));

        if ($brand) {
            $brandPattern = preg_quote(mb_strtolower(trim($brand)), '/');
            $s = preg_replace('/^' . $brandPattern . '\b\s*/u', '', $s, 1);
            $s = preg_replace('/\s*\b' . $brandPattern . '$/u', '', $s, 1);
        }

        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($transliterated !== false) {
            $s = $transliterated;
        }

        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $words = array_filter(explode(' ', $s), fn($w) => $w !== '' && !in_array($w, self::NAME_FILLER_WORDS, true));

        return array_values($words);
    }

    /**
     * Score de similarité entre deux ensembles de mots (indice de Jaccard : intersection / union).
     */
    private function wordOverlapScore(array $wordsA, array $wordsB): float
    {
        if (empty($wordsA) || empty($wordsB)) {
            return 0.0;
        }

        $setA = array_unique($wordsA);
        $setB = array_unique($wordsB);
        $intersection = array_intersect($setA, $setB);
        $union = array_unique(array_merge($setA, $setB));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    /**
     * Pour chaque parfum encore « mixte », interroge PerfumAPI (search) et applique le genre
     * officiel Men/Women/Unisex. À lancer par lots (offset/limit) pour respecter le rate-limit.
     *
     * @return array{checked:int,updated:int,skipped:int,errors:int}
     */
    public function enrichGendersFromApiSearch(int $limit = 40, int $offset = 0): array
    {
        require_once __DIR__ . '/GenderClassifier.php';

        $rows = $this->repo->getMixtePerfumes($limit, $offset);
        $checked = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $checked++;
            $query = GenderClassifier::searchQuery(
                (string)$row['name'],
                isset($row['brand']) ? (string)$row['brand'] : null
            );
            if ($query === '') {
                $skipped++;
                continue;
            }

            try {
                $raw = $this->api->searchPerfume($query, 8);
                $items = $this->extractList($raw);
                if ($items === []) {
                    // Repli : ligne seule sans marque
                    $lineOnly = GenderClassifier::lineKey(
                        (string)$row['name'],
                        isset($row['brand']) ? (string)$row['brand'] : null
                    );
                    if ($lineOnly !== '' && $lineOnly !== $query) {
                        $raw = $this->api->searchPerfume($lineOnly, 8);
                        $items = $this->extractList($raw);
                    }
                }

                if ($items === []) {
                    $skipped++;
                    usleep(200000);
                    continue;
                }

                $localKey = GenderClassifier::lineKey(
                    (string)$row['name'],
                    isset($row['brand']) ? (string)$row['brand'] : null
                );
                $best = null;
                $bestScore = 0.0;
                foreach ($items as $item) {
                    $normalized = $this->normalize($item);
                    $apiKey = GenderClassifier::lineKey(
                        (string)$normalized['name'],
                        $normalized['brand'] ?? null
                    );
                    $score = $this->wordOverlapScore(
                        $localKey !== '' ? explode(' ', $localKey) : [],
                        $apiKey !== '' ? explode(' ', $apiKey) : []
                    );
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $normalized;
                    }
                }

                if (!$best || $bestScore < 0.34) {
                    $skipped++;
                    usleep(200000);
                    continue;
                }

                $gender = $best['gender'] ?? 'mixte';
                if (!in_array($gender, ['homme', 'femme', 'mixte'], true)) {
                    $skipped++;
                    continue;
                }

                if ($gender === 'mixte') {
                    $skipped++;
                    usleep(150000);
                    continue;
                }

                $this->repo->updateGenderOnly((int)$row['id'], $gender);

                // Aligne le tag genre sans écraser les autres tags olfactifs.
                $existingTags = $this->repo->getTagsForPerfume((int)$row['id']);
                $tagMap = [];
                foreach ($existingTags as $t) {
                    $n = strtolower((string)$t['name']);
                    if ($n === 'homme' || $n === 'femme' || $n === 'mixte') {
                        continue;
                    }
                    $tagMap[$t['name']] = (float)$t['weight'];
                }
                $tagMap[$gender] = 2.0;
                $payload = [];
                foreach ($tagMap as $name => $weight) {
                    $payload[] = ['name' => $name, 'weight' => $weight];
                }
                if ($payload !== []) {
                    $this->repo->setTags((int)$row['id'], $payload);
                }

                $updated++;
                usleep(250000);
            } catch (Throwable $e) {
                $errors++;
            }
        }

        return [
            'checked' => $checked,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Enrichit le catalogue existant (ex: importé depuis un CSV réel) avec les données olfactives
     * de PerfumAPI, SANS créer de nouveau parfum ni toucher à l'image/prix/lien produit réels.
     * La correspondance se fait par similarité de mots (les noms API et CSV n'ont pas le même
     * ordre marque/produit), groupée par marque pour rester performante.
     * Retourne le nombre de parfums effectivement enrichis.
     */
    public function enrichCatalogFromApi(int $limit = 200, int $offset = 0, float $threshold = 0.4): array
    {
        $raw = $this->api->getPerfumes($limit, $offset);
        $items = $this->extractList($raw);

        // Charge tout le catalogue local une seule fois, groupé par marque (en minuscule).
        $catalog = $this->repo->getAllActiveWithTags();
        $byBrand = [];
        foreach ($catalog as $p) {
            $brandKey = mb_strtolower(trim($p['brand'] ?? ''));
            $byBrand[$brandKey][] = $p;
        }

        $matched = 0;
        $seen = 0;

        foreach ($items as $item) {
            $seen++;
            $normalized = $this->normalize($item);
            $apiWords = $this->coreWords($normalized['name'], $normalized['brand']);
            $brandKey = mb_strtolower(trim((string)$normalized['brand']));

            $candidates = $byBrand[$brandKey] ?? $catalog;

            $bestMatch = null;
            $bestScore = 0.0;

            foreach ($candidates as $candidate) {
                $candidateWords = $this->coreWords($candidate['name'], $candidate['brand']);
                $score = $this->wordOverlapScore($apiWords, $candidateWords);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $candidate;
                }
            }

            if (!$bestMatch || $bestScore < $threshold) {
                continue;
            }

            $this->repo->updateOlfactiveData((int)$bestMatch['id'], $normalized);

            $tags = $this->generateTagsFromNotes($normalized);
            if (!empty($tags)) {
                $this->repo->setTags((int)$bestMatch['id'], $tags);
            }

            $matched++;
        }

        return ['matched' => $matched, 'seen' => $seen];
    }

    /**
     * Enrichit le catalogue local à partir d'un fichier JSON (data.json de PerfumAPI).
     * Même logique que enrichCatalogFromApi mais sans passer par l'API.
     */
    public function enrichCatalogFromJsonFile(string $jsonPath, float $threshold = 0.4): array
    {
        if (!is_file($jsonPath)) {
            throw new RuntimeException("Fichier introuvable : $jsonPath");
        }

        $json = file_get_contents($jsonPath);
        $items = json_decode($json, true);

        if (!is_array($items) || empty($items)) {
            return ['matched' => 0, 'seen' => 0, 'skipped' => 0];
        }

        $catalog = $this->repo->getAllActiveWithTags();
        $byBrand = [];
        foreach ($catalog as $p) {
            $brandKey = mb_strtolower(trim($p['brand'] ?? ''));
            $byBrand[$brandKey][] = $p;
        }

        $matched = 0;
        $seen = 0;

        foreach ($items as $item) {
            $seen++;
            $normalized = $this->normalize($item);
            $apiWords = $this->coreWords($normalized['name'], $normalized['brand']);
            $brandKey = mb_strtolower(trim((string)$normalized['brand']));

            $candidates = $byBrand[$brandKey] ?? $catalog;

            $bestMatch = null;
            $bestScore = 0.0;

            foreach ($candidates as $candidate) {
                $candidateWords = $this->coreWords($candidate['name'], $candidate['brand']);
                $score = $this->wordOverlapScore($apiWords, $candidateWords);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $candidate;
                }
            }

            if (!$bestMatch || $bestScore < $threshold) {
                continue;
            }

            $this->repo->updateOlfactiveData((int)$bestMatch['id'], $normalized);

            $tags = $this->generateTagsFromNotes($normalized);
            if (!empty($tags)) {
                $this->repo->setTags((int)$bestMatch['id'], $tags);
            }

            $matched++;
        }

        return ['matched' => $matched, 'seen' => $seen, 'skipped' => $seen - $matched];
    }

    /**
     * Normalise et enregistre un parfum unique, retourne son id local.
     */
    public function importOne(array $item): int
    {
        $normalized = $this->normalize($item);
        $id = $this->repo->upsert($normalized);

        $tags = $this->generateTagsFromNotes($normalized);
        if (!empty($tags)) {
            $this->repo->setTags($id, $tags);
        }

        return $id;
    }

    /**
     * Extrait une liste de parfums quelle que soit la forme de la réponse API
     * (tableau direct, ou objet avec clé "data"/"results"/"perfumes").
     */
    private function extractList(array $raw): array
    {
        foreach (['data', 'results', 'perfumes', 'items'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                return $raw[$key];
            }
        }
        // Si $raw est déjà une liste indexée numériquement
        if (array_is_list($raw)) {
            return $raw;
        }
        // Un seul parfum retourné en objet
        if (isset($raw['name']) || isset($raw['title'])) {
            return [$raw];
        }
        return [];
    }

    /**
     * Normalise les champs hétérogènes de l'API vers notre schéma interne.
     * Tolère plusieurs noms de champs possibles (fallback).
     */
    private function normalize(array $item): array
    {
        $name  = firstNonEmpty($item, ['name', 'title', 'perfume_name'], 'Parfum inconnu');
        $brand = firstNonEmpty($item, ['brand', 'house', 'brand_name'], null);
        $apiId = firstNonEmpty($item, ['id', 'api_id', '_id', 'slug'], md5($name . $brand));

        $genderRaw = strtolower((string)firstNonEmpty($item, ['gender', 'for_gender', 'sex'], 'mixte'));
        $gender = 'mixte';
        if (str_contains($genderRaw, 'men') && !str_contains($genderRaw, 'women')) {
            $gender = 'homme';
        } elseif (str_contains($genderRaw, 'women') || str_contains($genderRaw, 'her')) {
            $gender = 'femme';
        } elseif (in_array($genderRaw, ['homme', 'femme', 'mixte'], true)) {
            $gender = $genderRaw;
        }

        $top    = firstNonEmpty($item, ['top_notes', 'notes_top', 'topNotes'], []);
        $middle = firstNonEmpty($item, ['middle_notes', 'notes_middle', 'middleNotes', 'heart_notes'], []);
        $base   = firstNonEmpty($item, ['base_notes', 'notes_base', 'baseNotes'], []);
        $accords = firstNonEmpty($item, ['accords', 'main_accords', 'mainAccords'], []);

        $image = firstNonEmpty($item, ['image_url', 'image', 'img', 'picture'], null);
        $source = firstNonEmpty($item, ['source_url', 'url', 'perfume_url', 'link'], null);
        $productUrl = firstNonEmpty($item, ['product_url', 'buy_url', 'shop_url'], null);

        $description = firstNonEmpty($item, ['description', 'about', 'summary'], null);
        $rating = firstNonEmpty($item, ['rating', 'score'], null);
        $votes  = firstNonEmpty($item, ['votes', 'vote_count', 'num_votes'], 0);
        $longevity = firstNonEmpty($item, ['longevity'], null);
        $sillage   = firstNonEmpty($item, ['sillage'], null);
        $year      = firstNonEmpty($item, ['release_year', 'year'], null);
        $price     = firstNonEmpty($item, ['price'], null);

        return [
            'api_id'       => (string)$apiId,
            'name'         => (string)$name,
            'brand'        => $brand !== null ? (string)$brand : null,
            'gender'       => $gender,
            'release_year' => $year !== null ? (int)$year : null,
            'top_notes'    => jencode(is_array($top) ? $top : [$top]),
            'middle_notes' => jencode(is_array($middle) ? $middle : [$middle]),
            'base_notes'   => jencode(is_array($base) ? $base : [$base]),
            'accords'      => jencode(is_array($accords) ? $accords : [$accords]),
            'rating'       => $rating !== null ? (float)$rating : null,
            'votes'        => (int)$votes,
            'longevity'    => $longevity,
            'sillage'      => $sillage,
            'image_url'    => $image,
            'source_url'   => $source,
            'description'  => $description,
            'price'        => $price !== null ? (float)$price : null,
            'product_url'  => $productUrl,
            'is_active'    => 1,
        ];
    }

    /**
     * Génère automatiquement les tags d'un parfum selon ses notes/accords normalisés.
     * Retourne un tableau [ ['name'=>.., 'weight'=>..], ... ]
     */
    public function generateTagsFromNotes(array $normalized): array
    {
        $allNotes = array_merge(
            jdecode($normalized['top_notes']),
            jdecode($normalized['middle_notes']),
            jdecode($normalized['base_notes']),
            jdecode($normalized['accords'])
        );

        $tagWeights = [];

        foreach ($allNotes as $note) {
            $key = strtolower(trim((string)$note));
            if ($key === '') {
                continue;
            }
            foreach (self::NOTE_TAG_MAP as $noteKey => $tags) {
                if (str_contains($key, $noteKey)) {
                    foreach ($tags as $tagName => $weight) {
                        $tagWeights[$tagName] = max($tagWeights[$tagName] ?? 0, $weight);
                    }
                }
            }
        }

        // Tag de genre
        if (!empty($normalized['gender'])) {
            $tagWeights[$normalized['gender']] = max($tagWeights[$normalized['gender']] ?? 0, 2.0);
        }

        $tagWeights = $this->limitDominantFamilies($tagWeights);

        $result = [];
        foreach ($tagWeights as $name => $weight) {
            $result[] = ['name' => $name, 'weight' => $weight];
        }

        return $result;
    }

    /**
     * Un parfum aux notes variées peut techniquement matcher les 7 familles olfactives à la fois
     * (ex: vanille+bois+fleurs = sucré+boisé+floral simultanément), ce qui le fait gagner à chaque
     * question du quiz quelle que soit la famille choisie. On ne garde que les 2 familles les
     * mieux représentées (poids le plus fort) pour que le classement par famille reste discriminant.
     */
    private function limitDominantFamilies(array $tagWeights, int $maxFamilies = 2): array
    {
        $families = array_intersect_key($tagWeights, array_flip(self::FAMILY_TAGS));
        if (count($families) <= $maxFamilies) {
            return $tagWeights;
        }

        arsort($families);
        $keep = array_slice(array_keys($families), 0, $maxFamilies);

        foreach (array_keys($families) as $family) {
            if (!in_array($family, $keep, true)) {
                unset($tagWeights[$family]);
            }
        }

        return $tagWeights;
    }
}
