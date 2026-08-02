<?php
/**
 * Synchronisation catalogue depuis PrestaShop (webhook) vers la base locale.
 * Préserve les notes olfactives déjà enrichies via PerfumAPI.
 */
class CatalogSyncService
{
    /** Marques composées de plusieurs mots (même logique que l'import CSV). */
    private const KNOWN_MULTI_WORD_BRANDS = [
        'MAISON FRANCIS KURKDJIAN', 'PARFUMS DE MARLY', 'JEAN PAUL GAULTIER', 'NARCISO RODRIGUEZ',
        'ZADIG & VOLTAIRE', 'VAN CLEEF & ARPELS', 'GIORGIO BEVERLY HILLS', 'GIORGIO ARMANI',
        'ELIZABETH ARDEN', 'ESTÉE LAUDER', 'HUGO BOSS', 'JIMMY CHOO', 'JO MALONE', 'KARL LAGERFELD',
        'PACO RABANNE', 'TOM FORD', 'TOMMY HILFIGER', 'SERGE LUTENS', 'FRANCK BOCLET',
        'PALOMA PICASSO', 'CAROLINA HERRERA', "PENHALIGON'S",
    ];

    private const IGNORED_PREFIXES = ['COFFRET', 'ETUI'];

    private PerfumeRepository $repo;

    public function __construct(PerfumeRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Upsert un produit boutique. Retourne ['action' => created|updated, 'id' => int].
     */
    public function syncProduct(array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Le champ name est obligatoire.');
        }

        $productUrl = trim((string)($payload['product_url'] ?? ''));
        $psId = isset($payload['prestashop_id']) ? (int)$payload['prestashop_id'] : 0;

        $apiId = $this->resolveApiId($productUrl, $psId);
        $existing = $this->findExisting($apiId, $productUrl, $psId);

        if ($existing) {
            $apiId = $existing['api_id'];
        }

        $brand = trim((string)($payload['brand'] ?? ''));
        if ($brand === '') {
            $brand = self::detectBrand($name);
        }

        $gender = trim((string)($payload['gender'] ?? ''));
        if ($gender === '' || !in_array($gender, ['homme', 'femme', 'mixte'], true)) {
            $gender = self::detectGender($name);
        }

        $price = $payload['price'] ?? null;
        if ($price !== null && $price !== '') {
            if (is_numeric($price)) {
                $price = normalizeShopPrice((float)$price);
            } else {
                $price = function_exists('parseShopPrice')
                    ? parseShopPrice((string)$price)
                    : self::parsePrice((string)$price);
            }
        } else {
            $price = null;
        }

        $isActive = array_key_exists('is_active', $payload)
            ? (int)(bool)$payload['is_active']
            : 1;

        $shopData = [
            'name'        => $name,
            'brand'       => $brand,
            'gender'      => $gender,
            'image_url'   => ($payload['image_url'] ?? '') !== '' ? trim((string)$payload['image_url']) : null,
            'price'       => $price,
            'product_url' => $productUrl !== '' ? $productUrl : null,
            'is_active'   => $isActive,
        ];

        // Ne jamais écraser une image / un prix existants avec une valeur vide.
        if ($existing) {
            if (($shopData['image_url'] === null || $shopData['image_url'] === '')
                && !empty($existing['image_url'])) {
                $shopData['image_url'] = $existing['image_url'];
            }
            if (($shopData['price'] === null || $shopData['price'] === '')
                && isset($existing['price']) && $existing['price'] !== null && $existing['price'] !== '') {
                $shopData['price'] = $existing['price'];
            }
        }

        // Conserve l'URL existante si elle n'ajoute qu'un fragment PrestaShop (#/contenance,...).
        if ($existing && !empty($existing['product_url']) && !empty($shopData['product_url'])) {
            $oldUrl = (string)$existing['product_url'];
            $newUrl = (string)$shopData['product_url'];
            if (str_starts_with($oldUrl, $newUrl) || self::samePrestashopProduct($oldUrl, $newUrl)) {
                // Préférer l'URL la plus complète (avec attribut / fragment) si déjà connue.
                if (strlen($oldUrl) >= strlen($newUrl)) {
                    $shopData['product_url'] = $oldUrl;
                }
            }
        }

        if ($existing) {
            $this->repo->updateShopFields((int)$existing['id'], $shopData);
            return ['action' => 'updated', 'id' => (int)$existing['id'], 'api_id' => $apiId];
        }

        $id = $this->repo->upsert([
            'api_id'       => $apiId,
            'name'         => $shopData['name'],
            'brand'        => $shopData['brand'],
            'gender'       => $shopData['gender'],
            'release_year' => null,
            'top_notes'    => jencode([]),
            'middle_notes' => jencode([]),
            'base_notes'   => jencode([]),
            'accords'      => jencode([]),
            'rating'       => null,
            'votes'        => 0,
            'longevity'    => null,
            'sillage'      => null,
            'image_url'    => $shopData['image_url'],
            'source_url'   => null,
            'description'  => null,
            'price'        => $shopData['price'],
            'product_url'  => $shopData['product_url'],
            'is_active'    => $shopData['is_active'],
        ]);

        return ['action' => 'created', 'id' => $id, 'api_id' => $apiId];
    }

    /**
     * Désactive un produit (suppression / désactivation PrestaShop).
     */
    public function deactivate(array $payload): array
    {
        $productUrl = trim((string)($payload['product_url'] ?? ''));
        $psId = isset($payload['prestashop_id']) ? (int)$payload['prestashop_id'] : 0;
        $apiId = trim((string)($payload['api_id'] ?? ''));

        $existing = null;
        if ($apiId !== '') {
            $existing = $this->repo->findByApiId($apiId);
        }
        if (!$existing) {
            $existing = $this->findExisting(
                $this->resolveApiId($productUrl, $psId),
                $productUrl,
                $psId
            );
        }

        if (!$existing) {
            return ['action' => 'not_found', 'id' => null];
        }

        $this->repo->updateShopFields((int)$existing['id'], [
            'name'        => $existing['name'],
            'brand'       => $existing['brand'],
            'gender'      => $existing['gender'] ?? 'mixte',
            'image_url'   => $existing['image_url'],
            'price'       => $existing['price'],
            'product_url' => $existing['product_url'],
            'is_active'   => 0,
        ]);

        return ['action' => 'deactivated', 'id' => (int)$existing['id'], 'api_id' => $existing['api_id']];
    }

    private function resolveApiId(string $productUrl, int $psId): string
    {
        if ($psId <= 0) {
            $psId = (int)(PerfumeRepository::extractPrestashopIdFromUrl($productUrl) ?? 0);
        }
        // Clé stable par id PrestaShop (évite les doublons quand l'URL change de format).
        if ($psId > 0) {
            return 'ps-' . $psId;
        }
        if ($productUrl !== '') {
            $pathOnly = preg_replace('/[#?].*$/', '', $productUrl) ?: $productUrl;
            return 'csv-' . md5($pathOnly);
        }
        throw new InvalidArgumentException('product_url ou prestashop_id est obligatoire.');
    }

    private function findExisting(string $apiId, string $productUrl, int $psId): ?array
    {
        if ($psId <= 0) {
            $psId = (int)(PerfumeRepository::extractPrestashopIdFromUrl($productUrl) ?? 0);
        }

        if ($psId > 0) {
            $existing = $this->repo->findByPrestashopId($psId);
            if ($existing) {
                return $existing;
            }
        }

        $existing = $this->repo->findByApiId($apiId);
        if ($existing) {
            return $existing;
        }
        if ($psId > 0) {
            $existing = $this->repo->findByApiId('ps-' . $psId);
            if ($existing) {
                return $existing;
            }
        }
        if ($productUrl !== '') {
            $pathOnly = preg_replace('/[#?].*$/', '', $productUrl) ?: $productUrl;
            $existing = $this->repo->findByProductUrl($productUrl);
            if ($existing) {
                return $existing;
            }
            $existing = $this->repo->findByProductUrlPrefix($pathOnly);
            if ($existing) {
                return $existing;
            }
            $existing = $this->repo->findByApiId('csv-' . md5($productUrl));
            if ($existing) {
                return $existing;
            }
            if ($pathOnly !== $productUrl) {
                $existing = $this->repo->findByApiId('csv-' . md5($pathOnly));
                if ($existing) {
                    return $existing;
                }
            }
        }
        return null;
    }

    private static function samePrestashopProduct(string $urlA, string $urlB): bool
    {
        $a = PerfumeRepository::extractPrestashopIdFromUrl($urlA);
        $b = PerfumeRepository::extractPrestashopIdFromUrl($urlB);
        return $a !== null && $b !== null && $a === $b;
    }

    public static function detectBrand(string $name): string
    {
        $clean = trim($name);
        $upper = mb_strtoupper($clean);

        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix . ' ')) {
                $clean = trim(mb_substr($clean, mb_strlen($prefix)));
                $upper = mb_strtoupper($clean);
                break;
            }
        }

        foreach (self::KNOWN_MULTI_WORD_BRANDS as $brand) {
            if (str_starts_with($upper, $brand)) {
                return ucwords(mb_strtolower($brand));
            }
        }

        $firstWord = strtok($clean, ' ');
        return $firstWord !== false ? ucfirst(mb_strtolower($firstWord)) : 'Inconnu';
    }

    public static function detectGender(string $name): string
    {
        $lower = mb_strtolower($name);
        if (str_contains($lower, 'homme')) {
            return 'homme';
        }
        if (str_contains($lower, 'femme')) {
            return 'femme';
        }
        return 'mixte';
    }

    public static function parsePrice(string $raw): ?float
    {
        if (function_exists('parseShopPrice')) {
            return parseShopPrice($raw);
        }
        $raw = str_replace(["\xC2\xA0", ' ', '€'], '', $raw);
        $raw = str_replace(',', '.', $raw);
        return is_numeric($raw) ? round((float)$raw, 2) : null;
    }
}
