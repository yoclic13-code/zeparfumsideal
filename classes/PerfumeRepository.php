<?php
/**
 * Accès aux parfums et tags en base de données locale (MySQL).
 */
class PerfumeRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retourne tous les parfums actifs avec leurs tags (poids inclus).
     */
    public function getAllActiveWithTags(): array
    {
        $stmt = $this->db->query("SELECT * FROM perfumes WHERE is_active = 1");
        $perfumes = $stmt->fetchAll();

        foreach ($perfumes as &$p) {
            $p['tags'] = $this->getTagsForPerfume((int)$p['id']);
        }

        return $perfumes;
    }

    /**
     * Prix le plus bas parmi les parfums actifs ayant un prix renseigné.
     */
    public function getMinActivePrice(): ?float
    {
        $stmt = $this->db->query(
            "SELECT MIN(price) AS min_price
             FROM perfumes
             WHERE is_active = 1 AND price IS NOT NULL AND price > 0"
        );
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        $normalized = function_exists('normalizeShopPrice')
            ? normalizeShopPrice((float)$value)
            : (float)$value;
        return $normalized;
    }

    /**
     * Recalcule et réécrit tous les prix pour coller au TTC boutique.
     * @return array{updated:int,total:int}
     */
    public function normalizeAllPrices(): array
    {
        if (!function_exists('normalizeShopPrice')) {
            return ['updated' => 0, 'total' => 0];
        }

        $rows = $this->db->query(
            "SELECT id, price FROM perfumes WHERE price IS NOT NULL AND price > 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        $update = $this->db->prepare("UPDATE perfumes SET price = :price WHERE id = :id");
        $updated = 0;

        foreach ($rows as $row) {
            $current = (float)$row['price'];
            $normalized = normalizeShopPrice($current);
            if ($normalized === null) {
                continue;
            }
            if (abs($normalized - $current) >= 0.005) {
                $update->execute([
                    'price' => $normalized,
                    'id' => (int)$row['id'],
                ]);
                $updated++;
            }
        }

        return ['updated' => $updated, 'total' => count($rows)];
    }

    /**
     * Retourne les tags (avec poids) associés à un parfum.
     */
    public function getTagsForPerfume(int $perfumeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.name, t.type, pt.weight
             FROM perfume_tags pt
             JOIN tags t ON t.id = pt.tag_id
             WHERE pt.perfume_id = :id"
        );
        $stmt->execute(['id' => $perfumeId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM perfumes WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Met à jour uniquement les champs olfactifs d'un parfum existant (sans toucher image/prix/lien produit).
     */
    public function updateOlfactiveData(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            "UPDATE perfumes SET
                gender = :gender, top_notes = :top_notes, middle_notes = :middle_notes,
                base_notes = :base_notes, accords = :accords, rating = :rating, votes = :votes,
                longevity = :longevity, sillage = :sillage, updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id'           => $id,
            'gender'       => $data['gender'] ?? 'mixte',
            'top_notes'    => $data['top_notes'] ?? '[]',
            'middle_notes' => $data['middle_notes'] ?? '[]',
            'base_notes'   => $data['base_notes'] ?? '[]',
            'accords'      => $data['accords'] ?? '[]',
            'rating'       => $data['rating'] ?? null,
            'votes'        => $data['votes'] ?? 0,
            'longevity'    => $data['longevity'] ?? null,
            'sillage'      => $data['sillage'] ?? null,
        ]);
    }

    /**
     * Recherche locale par nom ou marque (LIKE), résultats limités.
     */
    public function search(string $query, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM perfumes
             WHERE is_active = 1 AND (name LIKE :q1 OR brand LIKE :q2)
             ORDER BY rating DESC
             LIMIT :limit"
        );
        $stmt->bindValue('q1', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->bindValue('q2', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findByApiId(string $apiId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM perfumes WHERE api_id = :api_id");
        $stmt->execute(['api_id' => $apiId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByProductUrl(string $productUrl): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM perfumes WHERE product_url = :url LIMIT 1");
        $stmt->execute(['url' => $productUrl]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Trouve un parfum dont product_url commence par le chemin donné
     * (ignore les fragments PrestaShop #/49,contenance,...).
     */
    public function findByProductUrlPrefix(string $urlPrefix): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM perfumes
             WHERE product_url = :exact OR product_url LIKE :prefix
             ORDER BY (product_url = :exact2) DESC
             LIMIT 1"
        );
        $stmt->execute([
            'exact'  => $urlPrefix,
            'exact2' => $urlPrefix,
            'prefix' => $urlPrefix . '#%',
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Met à jour uniquement les champs boutique (prix, image, lien, actif…)
     * sans écraser les notes olfactives déjà enrichies.
     */
    public function updateShopFields(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            "UPDATE perfumes SET
                name = :name, brand = :brand, gender = :gender,
                image_url = :image_url, price = :price, product_url = :product_url,
                is_active = :is_active, updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id'          => $id,
            'name'        => $data['name'],
            'brand'       => $data['brand'] ?? null,
            'gender'      => $data['gender'] ?? 'mixte',
            'image_url'   => $data['image_url'] ?? null,
            'price'       => $data['price'] ?? null,
            'product_url' => $data['product_url'] ?? null,
            'is_active'   => $data['is_active'] ?? 1,
        ]);
    }

    public function setActiveByApiId(string $apiId, bool $active): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE perfumes SET is_active = :active, updated_at = NOW() WHERE api_id = :api_id"
        );
        $stmt->execute(['active' => $active ? 1 : 0, 'api_id' => $apiId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Insère ou met à jour un parfum (par api_id) via ON DUPLICATE KEY UPDATE.
     */
    public function upsert(array $data): int
    {
        $sql = "INSERT INTO perfumes
                (api_id, name, brand, gender, release_year, top_notes, middle_notes, base_notes,
                 accords, rating, votes, longevity, sillage, image_url, source_url, description,
                 price, product_url, is_active)
                VALUES
                (:api_id, :name, :brand, :gender, :release_year, :top_notes, :middle_notes, :base_notes,
                 :accords, :rating, :votes, :longevity, :sillage, :image_url, :source_url, :description,
                 :price, :product_url, :is_active)
                ON DUPLICATE KEY UPDATE
                 name = VALUES(name), brand = VALUES(brand), gender = VALUES(gender),
                 release_year = VALUES(release_year), top_notes = VALUES(top_notes),
                 middle_notes = VALUES(middle_notes), base_notes = VALUES(base_notes),
                 accords = VALUES(accords), rating = VALUES(rating), votes = VALUES(votes),
                 longevity = VALUES(longevity), sillage = VALUES(sillage),
                 image_url = VALUES(image_url), source_url = VALUES(source_url),
                 description = VALUES(description), price = VALUES(price),
                 product_url = VALUES(product_url), is_active = VALUES(is_active),
                 updated_at = NOW()";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'api_id'       => $data['api_id'] ?? null,
            'name'         => $data['name'],
            'brand'        => $data['brand'] ?? null,
            'gender'       => $data['gender'] ?? 'mixte',
            'release_year' => $data['release_year'] ?? null,
            'top_notes'    => $data['top_notes'] ?? null,
            'middle_notes' => $data['middle_notes'] ?? null,
            'base_notes'   => $data['base_notes'] ?? null,
            'accords'      => $data['accords'] ?? null,
            'rating'       => $data['rating'] ?? null,
            'votes'        => $data['votes'] ?? 0,
            'longevity'    => $data['longevity'] ?? null,
            'sillage'      => $data['sillage'] ?? null,
            'image_url'    => $data['image_url'] ?? null,
            'source_url'   => $data['source_url'] ?? null,
            'description'  => $data['description'] ?? null,
            'price'        => $data['price'] ?? null,
            'product_url'  => $data['product_url'] ?? null,
            'is_active'    => $data['is_active'] ?? 1,
        ]);

        if ($data['api_id']) {
            $existing = $this->findByApiId($data['api_id']);
            return $existing ? (int)$existing['id'] : (int)$this->db->lastInsertId();
        }

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            "UPDATE perfumes SET
                name = :name, brand = :brand, image_url = :image_url,
                top_notes = :top_notes, middle_notes = :middle_notes, base_notes = :base_notes,
                accords = :accords, price = :price, product_url = :product_url,
                is_active = :is_active, updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'id'           => $id,
            'name'         => $data['name'],
            'brand'        => $data['brand'],
            'image_url'    => $data['image_url'],
            'top_notes'    => $data['top_notes'],
            'middle_notes' => $data['middle_notes'],
            'base_notes'   => $data['base_notes'],
            'accords'      => $data['accords'],
            'price'        => $data['price'],
            'product_url'  => $data['product_url'],
            'is_active'    => $data['is_active'],
        ]);
    }

    /**
     * Remplace les tags associés à un parfum.
     * $tags = [ ['name' => 'frais', 'weight' => 1.5], ... ]
     */
    public function setTags(int $perfumeId, array $tags): void
    {
        $del = $this->db->prepare("DELETE FROM perfume_tags WHERE perfume_id = :id");
        $del->execute(['id' => $perfumeId]);

        $findTag = $this->db->prepare("SELECT id FROM tags WHERE name = :name");
        $insTag  = $this->db->prepare("INSERT INTO tags (name, label_fr, type) VALUES (:name, :label, 'note')");
        $link    = $this->db->prepare("INSERT INTO perfume_tags (perfume_id, tag_id, weight) VALUES (:pid, :tid, :w)
                                        ON DUPLICATE KEY UPDATE weight = VALUES(weight)");

        foreach ($tags as $tag) {
            $findTag->execute(['name' => $tag['name']]);
            $row = $findTag->fetch();
            if ($row) {
                $tagId = (int)$row['id'];
            } else {
                $insTag->execute(['name' => $tag['name'], 'label' => ucfirst($tag['name'])]);
                $tagId = (int)$this->db->lastInsertId();
            }
            $link->execute(['pid' => $perfumeId, 'tid' => $tagId, 'w' => $tag['weight'] ?? 1.0]);
        }
    }

    public function getAllTags(): array
    {
        return $this->db->query("SELECT * FROM tags ORDER BY type, label_fr")->fetchAll();
    }

    /**
     * Liste paginée pour l'admin avec filtres.
     */
    public function listForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['name'])) {
            $where[] = 'name LIKE :name';
            $params['name'] = '%' . $filters['name'] . '%';
        }
        if (!empty($filters['brand'])) {
            $where[] = 'brand = :brand';
            $params['brand'] = $filters['brand'];
        }
        if (!empty($filters['gender'])) {
            $where[] = 'gender = :gender';
            $params['gender'] = $filters['gender'];
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'is_active = :is_active';
            $params['is_active'] = (int)$filters['is_active'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("SELECT * FROM perfumes $whereSql ORDER BY updated_at DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM perfumes $whereSql");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        return ['items' => $items, 'total' => $total];
    }

    public function distinctBrands(): array
    {
        return $this->db->query("SELECT DISTINCT brand FROM perfumes WHERE brand IS NOT NULL ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function count(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM perfumes")->fetchColumn();
    }

    public function countActive(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM perfumes WHERE is_active = 1")->fetchColumn();
    }

    /**
     * Extrait l'id produit PrestaShop depuis une URL boutique.
     * Ex. /accueil/103310-1787-slug.html → 103310
     */
    public static function extractPrestashopIdFromUrl(?string $url): ?int
    {
        if ($url === null || $url === '') {
            return null;
        }
        // Délimiteur ~ : éviter le conflit avec # fragment dans la classe [^...].
        if (preg_match('~/(?:accueil/)?(\d+)(?:-\d+)?-[^/\#?]+\.html~i', $url, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Trouve un produit déjà importé pour un id PrestaShop (toutes variantes d'URL).
     */
    public function findByPrestashopId(int $psId): ?array
    {
        if ($psId <= 0) {
            return null;
        }

        $byApi = $this->findByApiId('ps-' . $psId);
        if ($byApi) {
            return $byApi;
        }

        $patterns = [
            '%/' . $psId . '-%.html%',
            '%/' . $psId . '-%.html',
        ];
        $stmt = $this->db->prepare(
            "SELECT * FROM perfumes
             WHERE api_id = :api
                OR product_url LIKE :p1
                OR product_url LIKE :p2
             ORDER BY
                (image_url IS NOT NULL AND image_url <> '') DESC,
                (price IS NOT NULL) DESC,
                id ASC
             LIMIT 1"
        );
        $stmt->execute([
            'api' => 'ps-' . $psId,
            'p1' => '%/' . $psId . '-%.html%',
            'p2' => '%/' . $psId . '-%.html',
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Désactive les produits zeparfums absents de la dernière sync.
     * @param list<int> $seenPsIds
     */
    public function deactivateMissingZeparfums(array $seenPsIds): int
    {
        $seenPsIds = array_values(array_unique(array_filter(array_map('intval', $seenPsIds), fn($id) => $id > 0)));
        if ($seenPsIds === []) {
            return 0;
        }

        $rows = $this->db->query(
            "SELECT id, product_url FROM perfumes
             WHERE is_active = 1
               AND product_url IS NOT NULL
               AND product_url LIKE '%zeparfums%'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $seenLookup = array_fill_keys($seenPsIds, true);
        $deactivate = $this->db->prepare(
            "UPDATE perfumes SET is_active = 0, updated_at = NOW() WHERE id = :id"
        );
        $count = 0;
        foreach ($rows as $row) {
            $psId = self::extractPrestashopIdFromUrl($row['product_url'] ?? '');
            if ($psId === null) {
                continue;
            }
            if (!isset($seenLookup[$psId])) {
                $deactivate->execute(['id' => (int)$row['id']]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Déduplique les doublons d'un même id PrestaShop (garde le plus complet).
     * @return array{groups:int,deactivated:int}
     */
    public function dedupeZeparfumsByPrestashopId(): array
    {
        $rows = $this->db->query(
            "SELECT id, product_url, image_url, price, top_notes, api_id
             FROM perfumes
             WHERE product_url IS NOT NULL AND product_url LIKE '%zeparfums%'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $row) {
            $psId = self::extractPrestashopIdFromUrl($row['product_url'] ?? '');
            if ($psId === null) {
                continue;
            }
            $groups[$psId][] = $row;
        }

        $deactivate = $this->db->prepare(
            "UPDATE perfumes SET is_active = 0, updated_at = NOW() WHERE id = :id"
        );
        $groupCount = 0;
        $deactivated = 0;

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $groupCount++;
            usort($group, function ($a, $b) {
                $score = function ($row) {
                    $s = 0;
                    if (!empty($row['image_url'])) {
                        $s += 100;
                    }
                    if ($row['price'] !== null && $row['price'] !== '') {
                        $s += 20;
                    }
                    $notes = (string)($row['top_notes'] ?? '');
                    if ($notes !== '' && $notes !== '[]' && $notes !== 'null') {
                        $s += 50;
                    }
                    return $s;
                };
                $diff = $score($b) <=> $score($a);
                return $diff !== 0 ? $diff : ((int)$a['id'] <=> (int)$b['id']);
            });
            $keepId = (int)$group[0]['id'];
            foreach ($group as $row) {
                $id = (int)$row['id'];
                if ($id === $keepId) {
                    continue;
                }
                $deactivate->execute(['id' => $id]);
                $deactivated++;
            }
        }

        return ['groups' => $groupCount, 'deactivated' => $deactivated];
    }

    /**
     * Recalcule et corrige le genre de tous les parfums (tags + nom + héritage ligne).
     * @return array{updated:int,total:int,femme:int,homme:int,mixte:int,inherited:int}
     */
    public function reclassifyAllGenders(): array
    {
        require_once __DIR__ . '/GenderClassifier.php';

        $rows = $this->db->query(
            "SELECT id, name, brand, gender FROM perfumes"
        )->fetchAll(PDO::FETCH_ASSOC);

        $update = $this->db->prepare(
            "UPDATE perfumes SET gender = :gender, updated_at = NOW() WHERE id = :id"
        );

        $resolvedById = [];
        $updated = 0;

        foreach ($rows as $row) {
            $tagRows = $this->getTagsForPerfume((int)$row['id']);
            $tagWeights = [];
            foreach ($tagRows as $t) {
                $tagWeights[$t['name']] = (float)$t['weight'];
            }

            $resolvedById[(int)$row['id']] = GenderClassifier::resolve(
                (string)$row['name'],
                $tagWeights,
                $row['gender'] ?? null,
                isset($row['brand']) ? (string)$row['brand'] : null
            );
        }

        // Héritage exact : même brand + lineKey.
        $lineIndex = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $g = $resolvedById[$id] ?? 'mixte';
            if ($g !== 'homme' && $g !== 'femme') {
                continue;
            }
            $brand = mb_strtolower(trim((string)($row['brand'] ?? '')));
            $key = $brand . '|' . GenderClassifier::lineKey((string)$row['name'], $row['brand'] ?? null);
            if ($key === '|' || str_ends_with($key, '|')) {
                continue;
            }
            $lineIndex[$key] = $g;
        }

        // Héritage souple : même brand + lineBase (coffret « Born In Roma » ← Donna).
        $baseVotes = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $g = $resolvedById[$id] ?? 'mixte';
            if ($g !== 'homme' && $g !== 'femme') {
                continue;
            }
            $brand = mb_strtolower(trim((string)($row['brand'] ?? '')));
            $base = GenderClassifier::lineBase((string)$row['name'], $row['brand'] ?? null);
            if ($base === '') {
                continue;
            }
            $bk = $brand . '|' . $base;
            $baseVotes[$bk][$g] = ($baseVotes[$bk][$g] ?? 0) + 1;
        }
        $baseIndex = [];
        foreach ($baseVotes as $bk => $votes) {
            $h = (int)($votes['homme'] ?? 0);
            $f = (int)($votes['femme'] ?? 0);
            // Unanimité uniquement (évite Born In Roma homme+femme → pas d’héritage).
            if ($h > 0 && $f === 0) {
                $baseIndex[$bk] = 'homme';
            } elseif ($f > 0 && $h === 0) {
                $baseIndex[$bk] = 'femme';
            }
        }

        $inherited = 0;
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (($resolvedById[$id] ?? 'mixte') !== 'mixte') {
                continue;
            }
            $brand = mb_strtolower(trim((string)($row['brand'] ?? '')));
            $key = $brand . '|' . GenderClassifier::lineKey((string)$row['name'], $row['brand'] ?? null);
            if (isset($lineIndex[$key])) {
                $resolvedById[$id] = $lineIndex[$key];
                $inherited++;
                continue;
            }
            $base = GenderClassifier::lineBase((string)$row['name'], $row['brand'] ?? null);
            $bk = $brand . '|' . $base;
            if ($base !== '' && isset($baseIndex[$bk])) {
                $resolvedById[$id] = $baseIndex[$bk];
                $inherited++;
            }
        }

        $counts = ['femme' => 0, 'homme' => 0, 'mixte' => 0];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $resolved = $resolvedById[$id] ?? 'mixte';
            $counts[$resolved] = ($counts[$resolved] ?? 0) + 1;

            $current = strtolower(trim((string)($row['gender'] ?? '')));
            if ($current !== $resolved) {
                $update->execute([
                    'gender' => $resolved,
                    'id' => $id,
                ]);
                $updated++;
            }
        }

        return [
            'updated' => $updated,
            'total' => count($rows),
            'femme' => $counts['femme'],
            'homme' => $counts['homme'],
            'mixte' => $counts['mixte'],
            'inherited' => $inherited,
        ];
    }

    /**
     * Met à jour uniquement le genre + tag genre associé.
     */
    public function updateGenderOnly(int $id, string $gender): void
    {
        if (!in_array($gender, ['homme', 'femme', 'mixte'], true)) {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE perfumes SET gender = :gender, updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['gender' => $gender, 'id' => $id]);
    }

    /**
     * @return list<array{id:int,name:string,brand:?string,gender:string}>
     */
    public function getMixtePerfumes(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, brand, gender FROM perfumes
             WHERE is_active = 1 AND LOWER(TRIM(gender)) = 'mixte'
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
