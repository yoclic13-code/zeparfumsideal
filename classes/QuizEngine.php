<?php
/**
 * Moteur de recommandation : calcule les scores de compatibilité
 * entre les préférences du client (quiz ou parfum aimé) et le catalogue local.
 */
class QuizEngine
{
    private PDO $db;
    private PerfumeRepository $repo;
    private const TOP_POOL_MIN = 8;
    private const TOP_POOL_MULTIPLIER = 4;
    private const RECENT_RESULTS_MAX = 12;

    /** Mapping des réponses du quiz vers des tags pondérés */
    private const ANSWER_TAG_MAP = [
        // Pour qui
        'homme'  => ['homme' => 3.0],
        'femme'  => ['femme' => 3.0],
        'mixte'  => ['mixte' => 3.0],
        // Occasion
        'quotidien' => ['quotidien' => 2.0, 'discret' => 1.0],
        'travail'   => ['travail' => 2.0, 'elegant' => 1.0],
        'soiree'    => ['soiree' => 2.0, 'puissant' => 1.0, 'seducteur' => 1.0],
        'mariage'   => ['mariage' => 2.0, 'elegant' => 1.5, 'luxueux' => 1.0],
        'cadeau'    => ['cadeau' => 2.0, 'elegant' => 1.0],
        // Famille olfactive
        'frais'    => ['frais' => 3.0, 'propre' => 1.0, 'citrus' => 1.0, 'bergamot' => 1.0, 'aquatic' => 1.0],
        'sucre'    => ['sucre' => 3.0, 'vanilla' => 1.0, 'caramel' => 1.0, 'gourmand' => 1.5, 'tonka' => 1.0],
        'oriental' => ['oriental' => 3.0, 'amber' => 1.0, 'oud' => 1.0, 'spice' => 1.0, 'incense' => 1.0],
        'boise'    => ['boise' => 3.0, 'woody' => 1.0, 'cedar' => 1.0, 'sandalwood' => 1.0, 'vetiver' => 1.0],
        'floral'   => ['floral' => 3.0, 'rose' => 1.0, 'jasmine' => 1.0, 'white_floral' => 1.0],
        'musque'   => ['musque' => 3.0, 'musk' => 1.5, 'clean' => 1.0, 'soft' => 1.0],
        'gourmand' => ['gourmand' => 3.0, 'vanilla' => 1.0, 'caramel' => 1.0, 'chocolate' => 1.0, 'almond' => 1.0, 'tonka' => 1.0],
        // Intensité
        'discret'      => ['discret' => 3.0],
        'equilibre'    => ['equilibre' => 3.0],
        'puissant'     => ['puissant' => 3.0],
        'tres_intense' => ['tres_intense' => 3.0, 'puissant' => 1.5],
        // Ambiance
        'propre'    => ['propre' => 3.0],
        'elegant'   => ['elegant' => 3.0],
        'seducteur' => ['seducteur' => 3.0],
        'doux'      => ['doux' => 3.0],
        'luxueux'   => ['luxueux' => 3.0],
        'original'  => ['original' => 3.0],
        // Saison
        'ete'         => ['ete' => 3.0, 'frais' => 1.0],
        'hiver'       => ['hiver' => 3.0, 'chaud' => 1.0],
        'printemps'   => ['printemps' => 3.0],
        'automne'     => ['automne' => 3.0],
        'toute_annee' => ['toute_annee' => 3.0],
    ];

    /** Ajustements de pondération pour le parcours "parfum aimé" */
    private const PREFERENCE_ADJUSTMENTS = [
        'similaire' => [],
        'plus_frais'    => ['frais' => 2.5, 'citrus' => 1.5, 'bergamot' => 1.5, 'aquatic' => 1.5],
        'plus_sucre'    => ['sucre' => 2.5, 'vanilla' => 1.5, 'tonka' => 1.5, 'amber' => 1.0, 'gourmand' => 2.0],
        'plus_puissant' => ['puissant' => 2.5, 'tres_intense' => 1.5],
        'plus_discret'  => ['discret' => 2.5],
        'plus_oriental' => ['oriental' => 2.5, 'amber' => 1.5, 'oud' => 1.5, 'spice' => 1.0],
        'plus_elegant'  => ['elegant' => 2.5, 'luxueux' => 1.0],
        'moins_cher'    => [],
        'soir'          => ['soiree' => 2.0, 'puissant' => 1.0, 'seducteur' => 1.0],
        'quotidien'     => ['quotidien' => 2.0, 'discret' => 1.0],
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->repo = new PerfumeRepository($db);
    }

    /**
     * Convertit les réponses du quiz en tags pondérés cumulés.
     */
    public function getTagsFromAnswers(array $answers): array
    {
        $tagWeights = [];
        foreach ($answers as $answer) {
            $key = strtolower((string)$answer);
            if (isset(self::ANSWER_TAG_MAP[$key])) {
                foreach (self::ANSWER_TAG_MAP[$key] as $tag => $weight) {
                    $tagWeights[$tag] = ($tagWeights[$tag] ?? 0) + $weight;
                }
            }
        }
        return $tagWeights;
    }

    /**
     * Récupère les tags pondérés d'un parfum existant dans le catalogue.
     */
    public function getTagsFromPerfume(int $perfumeId): array
    {
        $rows = $this->repo->getTagsForPerfume($perfumeId);
        $tagWeights = [];
        foreach ($rows as $row) {
            $tagWeights[$row['name']] = (float)$row['weight'];
        }
        return $tagWeights;
    }

    /**
     * Recommandation à partir des réponses du quiz classique.
     */
    public function recommendFromQuiz(array $answers, int $limit = 3, bool $coffretsOnly = false): array
    {
        $wantedTags = $this->getTagsFromAnswers($answers);

        $requiredGender = null;
        foreach ($answers as $answer) {
            if (in_array($answer, ['homme', 'femme', 'mixte'], true)) {
                $requiredGender = $answer;
                break;
            }
        }

        $allowCoffrets = in_array('cadeau', $answers, true);
        $coffretsOnly = $allowCoffrets && $coffretsOnly;

        return $this->rankPerfumes($wantedTags, $limit, null, $requiredGender, $allowCoffrets, $coffretsOnly);
    }

    /**
     * Recommandation à partir d'un parfum aimé + préférence de variation.
     */
    public function recommendFromFavoritePerfume(int $perfumeId, string $preference, int $limit = 3): array
    {
        $baseTags = $this->getTagsFromPerfume($perfumeId);
        $adjustments = self::PREFERENCE_ADJUSTMENTS[$preference] ?? [];

        $wantedTags = $baseTags;
        foreach ($adjustments as $tag => $weight) {
            $wantedTags[$tag] = ($wantedTags[$tag] ?? 0) + $weight;
        }

        $startingPerfume = $this->repo->findById($perfumeId);
        $requiredGender = $startingPerfume['gender'] ?? null;

        return $this->rankPerfumes($wantedTags, $limit, $perfumeId, $requiredGender, false, false);
    }

    /**
     * Calcule le score brut d'un parfum par rapport aux tags souhaités.
     * perfumeTags: [name => weight], wantedTags: [name => weight]
     */
    public function calculateScore(array $perfumeTags, array $wantedTags): float
    {
        $score = 0.0;
        foreach ($wantedTags as $tag => $wantedWeight) {
            if (isset($perfumeTags[$tag])) {
                $score += 10 * $perfumeTags[$tag] * min($wantedWeight, 3.0) / 3.0;
            }
        }
        return $score;
    }

    /**
     * Similarité entre 2 profils de tags (cosinus pondéré 0..1).
     */
    private function tagSimilarity(array $aTags, array $bTags): float
    {
        if (empty($aTags) || empty($bTags)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($aTags as $tag => $w) {
            $w = (float)$w;
            $normA += $w * $w;
            if (isset($bTags[$tag])) {
                $dot += $w * (float)$bTags[$tag];
            }
        }
        foreach ($bTags as $w) {
            $w = (float)$w;
            $normB += $w * $w;
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }

    /**
     * Réduction légère des répétitions pour la session en cours.
     */
    private function getRecentResultIds(): array
    {
        $recent = $_SESSION['recent_result_ids'] ?? [];
        if (!is_array($recent)) {
            return [];
        }

        $recent = array_values(array_filter(array_map('intval', $recent), fn($id) => $id > 0));
        return array_slice($recent, -self::RECENT_RESULTS_MAX);
    }

    private function rememberResultIds(array $perfumeIds): void
    {
        $recent = $this->getRecentResultIds();
        $recent = array_merge($recent, array_values(array_filter(array_map('intval', $perfumeIds), fn($id) => $id > 0)));
        $_SESSION['recent_result_ids'] = array_slice($recent, -self::RECENT_RESULTS_MAX);
    }

    /**
     * Paramètres de tuning (avec fallback robuste).
     */
    private function boolTuning(string $key, bool $default): bool
    {
        if (function_exists('getSetting')) {
            return getSetting($key, $default ? '1' : '0') === '1';
        }
        return $default;
    }

    private function floatTuning(string $key, float $default, float $min, float $max): float
    {
        $value = $default;
        if (function_exists('getSetting')) {
            $value = (float)getSetting($key, (string)$default);
        }
        return max($min, min($max, $value));
    }

    /**
     * Signature stable d'une requête pour une rotation déterministe.
     */
    private function requestSignature(array $wantedTags, ?int $excludePerfumeId, ?string $requiredGender): string
    {
        ksort($wantedTags);
        return json_encode([
            'tags' => $wantedTags,
            'exclude' => $excludePerfumeId,
            'gender' => $requiredGender,
        ], JSON_UNESCAPED_UNICODE) ?: 'default';
    }

    /**
     * Réordonne légèrement un pool déjà pertinent, de manière stable.
     */
    private function applyControlledRotation(array $entries, string $signature): array
    {
        $strength = $this->floatTuning('reco_rotation_strength', 0.5, 0.0, 3.0);
        if ($strength <= 0.0) {
            return $entries;
        }

        foreach ($entries as &$entry) {
            $pid = (int)($entry['perfume']['id'] ?? 0);
            $hash = crc32($signature . '|' . $pid . '|' . date('Y-m-d'));
            $noise = (($hash % 1001) / 1000) - 0.5; // -0.5 .. +0.5
            // Jitter faible, proportionnel à la force choisie.
            $entry['score'] += $noise * $strength;
        }
        unset($entry);

        usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);
        return $entries;
    }

    /**
     * Sélection finale avec contrainte de diversité inter-résultats.
     */
    private function selectDiversifiedTop(array $ranked, int $limit, string $signature): array
    {
        $poolSize = max(self::TOP_POOL_MIN, $limit * self::TOP_POOL_MULTIPLIER);
        $pool = array_slice($ranked, 0, $poolSize);
        $pool = $this->applyControlledRotation($pool, $signature);

        $diversify = $this->boolTuning('reco_diversify_enabled', true);
        $maxSimilarity = $this->floatTuning('reco_diversify_max_similarity', 0.78, 0.55, 0.95);

        if (!$diversify) {
            return array_slice($pool, 0, $limit);
        }

        $selected = [];
        foreach ($pool as $candidate) {
            $tooClose = false;
            foreach ($selected as $existing) {
                $similarity = $this->tagSimilarity($candidate['tags'], $existing['tags']);
                if ($similarity >= $maxSimilarity) {
                    $tooClose = true;
                    break;
                }
            }
            if (!$tooClose) {
                $selected[] = $candidate;
            }
            if (count($selected) >= $limit) {
                break;
            }
        }

        if (count($selected) < $limit) {
            foreach ($pool as $candidate) {
                $already = false;
                foreach ($selected as $existing) {
                    if ((int)$existing['perfume']['id'] === (int)$candidate['perfume']['id']) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $selected[] = $candidate;
                }
                if (count($selected) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($selected, 0, $limit);
    }

    /**
     * Classe tous les parfums actifs selon leur score de compatibilité.
     * $requiredGender, si fourni ('homme'/'femme'), exclut strictement les parfums de l'autre
     * genre (les parfums 'mixte' restent toujours compatibles). 'mixte' ou null n'exclut rien.
     */
    private function rankPerfumes(
        array $wantedTags,
        int $limit,
        ?int $excludePerfumeId = null,
        ?string $requiredGender = null,
        bool $allowCoffrets = false,
        bool $coffretsOnly = false
    ): array {
        $perfumes = $this->repo->getAllActiveWithTags();
        $ranked = [];
        $recentIds = $this->getRecentResultIds();
        $useSocialProof = $this->boolTuning('reco_use_social_proof', true);
        $ratingWeight = $this->floatTuning('reco_rating_weight', 1.0, 0.0, 2.0);
        $votesWeight = $this->floatTuning('reco_votes_weight', 1.0, 0.0, 2.0);
        $repeatPenalty = $this->floatTuning('reco_repeat_penalty', 4.5, 0.0, 12.0);

        foreach ($perfumes as $perfume) {
            if ($excludePerfumeId !== null && (int)$perfume['id'] === $excludePerfumeId) {
                continue;
            }

            if ($coffretsOnly && !$this->isCoffret($perfume)) {
                continue;
            }

            if (!$allowCoffrets && $this->isCoffret($perfume)) {
                continue;
            }

            if (!$this->isGenderCompatible($perfume['gender'] ?? null, $requiredGender)) {
                continue;
            }

            $perfumeTags = [];
            foreach ($perfume['tags'] as $t) {
                $perfumeTags[$t['name']] = (float)$t['weight'];
            }

            $score = $this->calculateScore($perfumeTags, $wantedTags);

            if ($useSocialProof) {
                // Bonus rating : léger, ne doit pas écraser les tags de compatibilité.
                if (!empty($perfume['rating'])) {
                    $score += max(0, min(2, ((float)$perfume['rating'] - 3.5))) * $ratingWeight;
                }

                // Bonus votes progressif (faible plafond)
                $votes = (int)($perfume['votes'] ?? 0);
                if ($votes > 0) {
                    $score += min(3, log10($votes + 1) * 0.6) * $votesWeight;
                }
            }

            // Malus image manquante
            if (empty($perfume['image_url'])) {
                $score -= 3;
            }

            // Malus de répétition dans la session en cours.
            if (in_array((int)$perfume['id'], $recentIds, true)) {
                $score -= $repeatPenalty;
            }

            $ranked[] = [
                'perfume' => $perfume,
                'score'   => $score,
                'tags'    => $perfumeTags,
            ];
        }

        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);

        if ($allowCoffrets && !$coffretsOnly) {
            $coffretRanked = array_values(array_filter(
                $ranked,
                fn($entry) => $this->isCoffret($entry['perfume'])
            ));
            $regularRanked = array_values(array_filter(
                $ranked,
                fn($entry) => !$this->isCoffret($entry['perfume'])
            ));
            $merged = array_merge($coffretRanked, $regularRanked);
            $signature = $this->requestSignature($wantedTags, $excludePerfumeId, $requiredGender) . '|coffret-mix';
            $top = $this->selectDiversifiedTop($merged, $limit, $signature);
        } else {
            $signature = $this->requestSignature($wantedTags, $excludePerfumeId, $requiredGender);
            $top = $this->selectDiversifiedTop($ranked, $limit, $signature);
        }
        $maxScore = $top[0]['score'] ?? 1;
        $maxScore = $maxScore > 0 ? $maxScore : 1;

        $results = [];
        foreach ($top as $position => $entry) {
            $percent = max(0, min(100, round(($entry['score'] / $maxScore) * 100)));
            $results[] = [
                'perfume'     => $entry['perfume'],
                'score'       => round($entry['score'], 2),
                'percent'     => (int)$percent,
                'position'    => $position + 1,
                'reason_text' => $this->buildReasonText($entry['perfume'], $wantedTags, $entry['tags']),
            ];
        }

        $this->rememberResultIds(array_map(fn($r) => (int)$r['perfume']['id'], $results));

        return $results;
    }

    /**
     * Un parfum 'homme' ne doit jamais être proposé quand on cherche 'femme', et inversement.
     * Un parfum 'mixte' est toujours accepté ; une recherche 'mixte' (ou sans genre requis)
     * n'exclut rien.
     */
    private function isGenderCompatible(?string $candidateGender, ?string $requiredGender): bool
    {
        if ($requiredGender === null || $requiredGender === 'mixte') {
            return true;
        }

        return $candidateGender === $requiredGender || $candidateGender === 'mixte';
    }

    /**
     * Détecte un coffret à partir du nom produit (ex. "Coffret Dior Sauvage").
     */
    private function isCoffret(array $perfume): bool
    {
        $name = mb_strtolower(trim($perfume['name'] ?? ''));

        return $name !== '' && str_contains($name, 'coffret');
    }

    /**
     * Génère un texte explicatif simple à partir des tags correspondants.
     */
    private function buildReasonText(array $perfume, array $wantedTags, array $perfumeTags): string
    {
        $matched = [];
        arsort($wantedTags);
        foreach ($wantedTags as $tag => $weight) {
            if (isset($perfumeTags[$tag])) {
                $matched[] = str_replace('_', ' ', $tag);
            }
            if (count($matched) >= 3) {
                break;
            }
        }

        if (empty($matched)) {
            return "Ce parfum a été sélectionné parmi les mieux notés de notre sélection pour son équilibre général.";
        }

        $list = implode(', ', array_slice($matched, 0, 2));
        $last = count($matched) > 1 ? ' et ' . end($matched) : '';
        if (count($matched) > 1) {
            $listPart = implode(', ', array_slice($matched, 0, -1)) . ' et ' . end($matched);
        } else {
            $listPart = $matched[0];
        }

        return "Ce parfum correspond à votre recherche car il combine des notes " . $listPart . ", adaptées à votre style et à vos envies.";
    }
}
