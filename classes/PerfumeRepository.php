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
                 product_url = VALUES(product_url), updated_at = NOW()";

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
}
