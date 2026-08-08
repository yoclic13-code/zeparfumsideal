<?php
/**
 * Client pour l'API PerfumAPI (https://github.com/seccaz/PerfumAPI).
 * N'est jamais appelé directement par le frontend : uniquement via ImportService / admin.
 * Ne bloque jamais le site : toute erreur est catchée et loggée dans api_logs.
 */
class PerfumApiClient
{
    private PDO $db;
    private string $baseUrl;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->baseUrl = rtrim(PERFUM_API_BASE_URL, '/');
    }

    public function getPerfumes(int $limit = 100, int $offset = 0): array
    {
        return $this->request('/perfumes', ['limit' => $limit, 'offset' => $offset]);
    }

    public function searchPerfume(string $query, int $limit = 10): array
    {
        // PerfumAPI expose la recherche en paramètre de chemin : /perfumes/search/{query}
        return $this->request('/perfumes/search/' . rawurlencode($query), ['limit' => $limit]);
    }

    public function getPerfume($id): array
    {
        return $this->request('/perfumes/' . rawurlencode((string)$id), []);
    }

    /**
     * Déclenche un scrape marque côté PerfumAPI (peut prendre plusieurs minutes).
     * @return array{status?:string,scraped_count?:int,perfumes?:list<array>,message?:string}
     */
    public function scrapeBrand(string $brandName, int $limit = 15): array
    {
        return $this->requestPost('/scrape/brand', [
            'brand_name' => $brandName,
            'limit' => max(1, min(100, $limit)),
        ], 600);
    }

    /**
     * Effectue une requête HTTP GET vers l'API et logue le résultat.
     * Retourne toujours un tableau (vide en cas d'échec) pour ne jamais casser le site.
     */
    private function request(string $endpoint, array $query): array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $status = 'ok';
        $preview = '';
        $result = [];

        try {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 8,
                    'header'  => PERFUM_API_KEY ? "Authorization: Bearer " . PERFUM_API_KEY . "\r\n" : '',
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $ctx);

            if ($response === false) {
                throw new RuntimeException('Aucune réponse de l\'API');
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Réponse API invalide (JSON attendu)');
            }

            $result = $decoded;
            $preview = mb_substr($response, 0, 500);
        } catch (Throwable $e) {
            $status = 'error';
            $preview = $e->getMessage();
            $result = [];
        }

        $this->log($endpoint, http_build_query($query), $status, $preview);

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function requestPost(string $endpoint, array $payload, int $timeout = 120): array
    {
        $url = $this->baseUrl . $endpoint;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $status = 'ok';
        $preview = '';
        $result = [];

        try {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('cURL requis pour les scrapes PerfumAPI');
            }
            $ch = curl_init($url);
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            if (PERFUM_API_KEY) {
                $headers[] = 'Authorization: Bearer ' . PERFUM_API_KEY;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException($err !== '' ? $err : 'Aucune réponse de l\'API');
            }
            $decoded = json_decode((string)$response, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Réponse API invalide (HTTP ' . $code . ')');
            }
            $result = $decoded;
            $preview = mb_substr((string)$response, 0, 500);
            if ($code >= 400) {
                $status = 'error';
            }
        } catch (Throwable $e) {
            $status = 'error';
            $preview = $e->getMessage();
            $result = [];
        }

        $this->log($endpoint, $body ?: '', $status, $preview);
        return $result;
    }

    private function log(string $endpoint, string $query, string $status, string $preview): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO api_logs (endpoint, query, status, response_preview) VALUES (:e, :q, :s, :p)"
            );
            $stmt->execute(['e' => $endpoint, 'q' => $query, 's' => $status, 'p' => $preview]);
        } catch (Throwable $e) {
            // Ne jamais casser le site si le log échoue.
        }
    }
}
