<?php
/**
 * Client Fragrantica via Algolia (pas de scrape HTML des fiches produit).
 * 1) Récupère la clé search sécurisée (toAbby) depuis la homepage
 * 2) Interroge l’index fragrantica_perfumes (champ spol = male|female|unisex)
 */
class FragranticaClient
{
    private const APP_ID = 'FGVI612DFZ';
    private const INDEX = 'fragrantica_perfumes';
    private const HOME_URL = 'https://www.fragrantica.com/';

    private ?string $apiKey = null;
    private ?int $validUntil = null;

    /**
     * @return array{gender:?string,name:?string,brand:?string,year:?int,rating:?float,object_id:?string,raw_spol:?string}|null
     */
    public function searchBestMatch(string $query, int $hitsPerPage = 8): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $hits = $this->search($query, $hitsPerPage);
        if ($hits === []) {
            return null;
        }

        return $this->normalizeHit($hits[0]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function search(string $query, int $hitsPerPage = 8): array
    {
        $this->ensureApiKey();
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new RuntimeException('Clé Algolia Fragrantica indisponible (Cloudflare ?).');
        }

        $body = json_encode([
            'requests' => [[
                'indexName' => self::INDEX,
                'params' => http_build_query([
                    'query' => $query,
                    'hitsPerPage' => max(1, min(20, $hitsPerPage)),
                ]),
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $url = 'https://' . strtolower(self::APP_ID) . '-dsn.algolia.net/1/indexes/*/queries';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Algolia-Application-Id: ' . self::APP_ID,
                'X-Algolia-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            throw new RuntimeException('Algolia Fragrantica HTTP ' . $code . ($err !== '' ? " ($err)" : ''));
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            return [];
        }

        $hits = $data['results'][0]['hits'] ?? [];
        return is_array($hits) ? $hits : [];
    }

    /**
     * @param array<string,mixed> $hit
     * @return array{gender:?string,name:?string,brand:?string,year:?int,rating:?float,object_id:?string,raw_spol:?string}
     */
    public function normalizeHit(array $hit): array
    {
        $spol = strtolower(trim((string)($hit['spol'] ?? '')));
        $gender = null;
        if ($spol === 'male' || $spol === 'men' || $spol === 'man') {
            $gender = 'homme';
        } elseif ($spol === 'female' || $spol === 'women' || $spol === 'woman') {
            $gender = 'femme';
        } elseif ($spol === 'unisex' || $spol === 'unisexe') {
            $gender = 'mixte';
        }

        return [
            'gender' => $gender,
            'name' => isset($hit['naslov']) ? (string)$hit['naslov'] : null,
            'brand' => isset($hit['dizajner']) ? (string)$hit['dizajner'] : null,
            'year' => isset($hit['godina']) ? (int)$hit['godina'] : null,
            'rating' => isset($hit['rating']) ? (float)$hit['rating'] : null,
            'object_id' => isset($hit['objectID']) ? (string)$hit['objectID'] : null,
            'raw_spol' => $spol !== '' ? $spol : null,
        ];
    }

    public function mapSpolToGender(string $spol): ?string
    {
        $hit = $this->normalizeHit(['spol' => $spol]);
        return $hit['gender'];
    }

    private function ensureApiKey(): void
    {
        if ($this->apiKey !== null && $this->apiKey !== ''
            && ($this->validUntil === null || $this->validUntil > time() + 60)
        ) {
            return;
        }

        // Réutilise une clé encore valide stockée en settings si dispo.
        if (function_exists('getSetting')) {
            $cachedKey = (string)getSetting('fragrantica_algolia_key', '');
            $cachedUntil = (int)getSetting('fragrantica_algolia_valid_until', '0');
            if ($cachedKey !== '' && $cachedUntil > time() + 120) {
                $this->apiKey = $cachedKey;
                $this->validUntil = $cachedUntil;
                return;
            }
        }

        $this->harvestApiKey();
    }

    private function harvestApiKey(): void
    {
        $ch = curl_init(self::HOME_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        $html = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $code >= 400 || stripos((string)$html, 'Just a moment') !== false) {
            throw new RuntimeException(
                'Impossible de récupérer la clé Fragrantica (HTTP ' . $code . ', Cloudflare ?). Réessayez plus tard.'
            );
        }

        if (!preg_match('/toAbby\s*=\s*"([^"]+)"/', (string)$html, $m)) {
            throw new RuntimeException('Clé toAbby introuvable dans la homepage Fragrantica.');
        }

        $this->apiKey = $m[1];
        $this->validUntil = null;
        if (preg_match('/validUntil\s*=\s*(\d+)/', (string)$html, $v)) {
            $this->validUntil = (int)$v[1];
        }

        if (function_exists('setSetting')) {
            setSetting('fragrantica_algolia_key', $this->apiKey);
            setSetting('fragrantica_algolia_valid_until', (string)($this->validUntil ?? (time() + 86400)));
        }
    }
}
