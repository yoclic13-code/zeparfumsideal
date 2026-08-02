<?php
/**
 * Scrape catalogue zeparfums.com avec le cookie de session CSE (PHP pur, sans Python).
 * Adapté à l'hébergement o2switch où pip/Python ne sont pas disponibles.
 */
class ZeparfumsScraper
{
    private string $baseUrl;
    private string $cookie;
    private int $maxPages;
    private int $delayMs;

    public function __construct(
        string $cookie,
        string $baseUrl = 'https://zeparfums.com',
        int $maxPages = 200,
        int $delayMs = 250
    ) {
        $this->cookie = trim($cookie);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->maxPages = max(1, $maxPages);
        $this->delayMs = max(0, $delayMs);

        if ($this->cookie === '') {
            throw new InvalidArgumentException('Cookie de session CSE manquant.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extension PHP cURL requise pour la sync.');
        }
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('Extension PHP DOM requise pour la sync.');
        }
    }

    /**
     * @param list<string> $categories
     * @return array{ok:bool,count:int,categories:array<string,int>,products:list<array>,auth:string}
     */
    public function scrape(array $categories = []): array
    {
        if ($categories === []) {
            $categories = [$this->baseUrl . '/2-accueil'];
        }

        $this->assertLoggedIn();

        $all = [];
        $seen = [];
        $perCategory = [];

        foreach ($categories as $cat) {
            $cat = trim($cat);
            if ($cat === '') {
                continue;
            }
            $items = $this->scrapeCategory($cat);
            $added = 0;
            foreach ($items as $item) {
                $key = $item['product_url'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $all[] = $item;
                $added++;
            }
            $perCategory[$cat] = $added;
        }

        if ($all === []) {
            throw new RuntimeException(
                'Aucun produit trouvé. Vérifiez le cookie et l’URL catégorie (ex. https://zeparfums.com/2-accueil).'
            );
        }

        return [
            'ok' => true,
            'count' => count($all),
            'categories' => $perCategory,
            'products' => $all,
            'auth' => 'cookie',
            'engine' => 'php',
        ];
    }

    private function assertLoggedIn(): void
    {
        $probes = [
            $this->baseUrl . '/2-accueil',
            $this->baseUrl . '/2-parfums',
            $this->baseUrl . '/',
            $this->baseUrl . '/mon-compte',
        ];
        $lastUrl = '';
        foreach ($probes as $url) {
            [$finalUrl, $html] = $this->httpGet($url);
            $lastUrl = $finalUrl;
            if (!$this->isLoginPage($finalUrl, $html)) {
                return;
            }
        }
        throw new RuntimeException(
            'Session CSE invalide ou expirée. Reconnectez-vous sur zeparfums.com puis recollez le cookie. '
            . 'Dernière URL : ' . $lastUrl
        );
    }

    /** @return list<array> */
    private function scrapeCategory(string $categoryUrl): array
    {
        $collected = [];
        $seen = [];

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $url = $page > 1 ? $this->withPage($categoryUrl, $page) : $categoryUrl;
            [$finalUrl, $html] = $this->httpGet($url);
            if ($this->isLoginPage($finalUrl, $html)) {
                throw new RuntimeException('Session expirée pendant le scrape. Recollez un cookie frais.');
            }

            $pageProducts = $this->extractProducts($html);
            if ($pageProducts === []) {
                break;
            }

            $newCount = 0;
            foreach ($pageProducts as $p) {
                if (isset($seen[$p['product_url']])) {
                    continue;
                }
                $seen[$p['product_url']] = true;
                $collected[] = $p;
                $newCount++;
            }
            if ($newCount === 0) {
                break;
            }

            if ($this->delayMs > 0 && $page < $this->maxPages) {
                usleep($this->delayMs * 1000);
            }
        }

        return $collected;
    }

    private function withPage(string $url, int $page): string
    {
        $parts = parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['page'] = $page;
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'zeparfums.com';
        $path = $parts['path'] ?? '/';
        return $scheme . '://' . $host . $path . '?' . http_build_query($query);
    }

    /** @return array{0:string,1:string} [finalUrl, html] */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept-Language: fr-FR,fr;q=0.9',
                'Cookie: ' . $this->cookie,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $html = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('Erreur cURL #' . $errno . ' : ' . $error);
        }
        if ($html === false || $html === '') {
            throw new RuntimeException('Réponse vide pour ' . $url);
        }
        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status . ' pour ' . $finalUrl);
        }

        return [$finalUrl, $html];
    }

    private function isLoginPage(string $url, string $html): bool
    {
        if (str_contains($url, 'zeparfumsreg/connexion')) {
            return true;
        }
        $low = strtolower($html);
        return str_contains($low, 'name="password"')
            && str_contains($low, 'name="email"')
            && str_contains($low, 'submitlogin');
    }

    /** @return list<array> */
    private function extractProducts(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        $nodes = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' product-miniature ')]"
            . " | //*[@data-id-product]"
            . " | //article[contains(@class,'js-product')]"
        );

        $products = [];
        $seen = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $item = $this->extractFromNode($node, $xpath);
                if ($item === null) {
                    continue;
                }
                if (isset($seen[$item['product_url']])) {
                    continue;
                }
                $seen[$item['product_url']] = true;
                $products[] = $item;
            }
        }

        if ($products !== []) {
            return $products;
        }

        // Fallback : liens /accueil/ID-slug.html
        $links = $xpath->query("//a[contains(@href,'.html')]");
        if ($links === false) {
            return [];
        }
        foreach ($links as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            if (!preg_match('#/\d+(?:-\d+)?-[^/]+\.html#', $href)) {
                continue;
            }
            $productUrl = $this->normalizeUrl($href);
            if (isset($seen[$productUrl])) {
                continue;
            }
            $name = $this->cleanText($a->textContent);
            if (mb_strlen($name) < 3) {
                continue;
            }
            $seen[$productUrl] = true;
            $psId = PerfumeRepository::extractPrestashopIdFromUrl($productUrl);
            $imageUrl = '';
            $parent = $a->parentNode;
            if ($parent instanceof DOMElement) {
                $imageUrl = $this->extractImageUrl($parent, $xpath);
            }
            $products[] = [
                'name' => $name,
                'brand' => '',
                'price' => null,
                'image_url' => $imageUrl,
                'product_url' => $productUrl,
                'prestashop_id' => $psId,
                'is_active' => 1,
            ];
        }

        return $products;
    }

    private function extractFromNode(DOMElement $node, DOMXPath $xpath): ?array
    {
        $link = $xpath->query(
            ".//a[contains(@class,'product-thumbnail')] | .//h2//a | .//h3//a | .//a[contains(@href,'.html')]",
            $node
        )->item(0);

        if (!$link instanceof DOMElement) {
            return null;
        }
        $href = $link->getAttribute('href');
        if ($href === '') {
            return null;
        }
        $productUrl = $this->normalizeUrl($href);
        if (str_contains($productUrl, '/module/zeparfumsreg/')) {
            return null;
        }

        $nameNode = $xpath->query(
            ".//*[contains(@class,'product-title')] | .//h2 | .//h3",
            $node
        )->item(0);
        $name = $this->cleanText($nameNode ? $nameNode->textContent : $link->textContent);
        if ($name === '') {
            return null;
        }

        $price = null;
        $priceNodes = $xpath->query(
            ".//*[contains(@class,'price')] | .//*[@itemprop='price']",
            $node
        );
        foreach ($priceNodes as $priceNode) {
            if (!$priceNode instanceof DOMElement) {
                continue;
            }
            // Priorité au texte visible (celui affiché sur le site), puis à content=.
            $candidates = [
                $this->cleanText($priceNode->textContent),
                $priceNode->getAttribute('content'),
            ];
            foreach ($candidates as $raw) {
                if ($raw === '') {
                    continue;
                }
                $parsed = $this->parsePrice($raw);
                if ($parsed !== null) {
                    $price = $parsed;
                    break 2;
                }
            }
        }

        $imageUrl = $this->extractImageUrl($node, $xpath);

        $brand = '';
        $brandNode = $xpath->query(
            ".//*[contains(@class,'product-brand') or contains(@class,'manufacturer')] | .//*[@itemprop='brand']",
            $node
        )->item(0);
        if ($brandNode) {
            $brand = $this->cleanText($brandNode->textContent);
        }

        $psId = null;
        $rawId = $node->getAttribute('data-id-product');
        if ($rawId !== '' && ctype_digit($rawId)) {
            $psId = (int)$rawId;
        } else {
            $psId = PerfumeRepository::extractPrestashopIdFromUrl($productUrl);
        }

        return [
            'name' => $name,
            'brand' => $brand,
            'price' => $price,
            'image_url' => $imageUrl,
            'product_url' => $productUrl,
            'prestashop_id' => $psId,
            'is_active' => 1,
        ];
    }

    private function extractImageUrl(DOMElement $node, DOMXPath $xpath): string
    {
        $imgs = $xpath->query('.//img', $node);
        if ($imgs === false) {
            return '';
        }

        $candidates = [];
        foreach ($imgs as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }
            foreach ([
                'data-full-size-image-url',
                'data-image-large-src',
                'data-lazy-src',
                'data-src',
                'data-original',
                'src',
            ] as $attr) {
                $value = trim($img->getAttribute($attr));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }

            $srcset = trim($img->getAttribute('srcset'));
            if ($srcset !== '') {
                $best = $this->bestFromSrcset($srcset);
                if ($best !== '') {
                    $candidates[] = $best;
                }
            }
        }

        $ranked = [];
        foreach ($candidates as $raw) {
            $url = $this->absoluteUrl($raw);
            if (!$this->isUsableImageUrl($url)) {
                continue;
            }
            $ranked[] = [
                'url' => $url,
                'score' => $this->scoreImageUrl($url),
            ];
        }

        if ($ranked === []) {
            return '';
        }

        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
        return $ranked[0]['url'];
    }

    private function bestFromSrcset(string $srcset): string
    {
        $bestUrl = '';
        $bestW = -1;
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = preg_split('/\s+/', $part) ?: [];
            $url = $bits[0] ?? '';
            $w = 0;
            if (isset($bits[1]) && preg_match('/(\d+)w/', $bits[1], $m)) {
                $w = (int)$m[1];
            }
            if ($url !== '' && $w >= $bestW) {
                $bestW = $w;
                $bestUrl = $url;
            }
        }
        return $bestUrl;
    }

    private function isUsableImageUrl(string $url): bool
    {
        if ($url === '' || str_starts_with($url, 'data:')) {
            return false;
        }
        $low = strtolower($url);
        foreach (['blank.gif', 'placeholder', 'no-picture', 'no_picture', 'lazy.svg', 'spacer.gif'] as $bad) {
            if (str_contains($low, $bad)) {
                return false;
            }
        }
        return (bool)preg_match('#\.(jpe?g|png|webp|gif)(\?|#|$)#i', $url)
            || str_contains($low, '_default/')
            || str_contains($low, '-home_default')
            || str_contains($low, '-large_default')
            || str_contains($low, '-medium_default');
    }

    private function scoreImageUrl(string $url): int
    {
        $low = strtolower($url);
        $score = 0;
        if (str_contains($low, 'large_default') || str_contains($low, 'thickbox')) {
            $score += 50;
        } elseif (str_contains($low, 'home_default') || str_contains($low, 'medium_default')) {
            $score += 30;
        }
        if (str_contains($low, 'zeparfums.com')) {
            $score += 10;
        }
        $score += min(20, (int)(strlen($url) / 20));
        return $score;
    }

    private function normalizeUrl(string $url): string
    {
        $abs = $this->absoluteUrl($url);
        $parts = parse_url($abs);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'zeparfums.com';
        $path = $parts['path'] ?? '/';
        return $scheme . '://' . $host . $path;
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return $this->baseUrl . $url;
        }
        return $this->baseUrl . '/' . ltrim($url, '/');
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function parsePrice(string $raw): ?float
    {
        if (function_exists('parseShopPrice')) {
            return parseShopPrice($raw);
        }
        $raw = str_replace(["\xc2\xa0", ' ', '€'], '', $raw);
        $raw = str_replace(',', '.', $raw);
        $raw = preg_replace('/[^\d.]/', '', $raw) ?? '';
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return round((float)$raw, 2);
    }
}
