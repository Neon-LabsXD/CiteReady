<?php
declare(strict_types=1);

require_once __DIR__ . '/FetchException.php';

/**
 * Клієнт FreeSerp API (https://freeserp.ai/api.php) — без ключа.
 * Усі запити йдуть із PHP (не з браузера): одна точка обробки помилок і кешу.
 *
 * Спостереження (etap-study):
 * - профіль домену надійний за exact-match: results[0] беремо лише якщо
 *   results[0].domain === domain;
 * - index=web для доменного/брендового запиту часто падає у fallback
 *   (engine_fallback=true, форма sites) — тоді реальних сторінок порахувати не можна,
 *   тож перевірку сторінок вважаємо недоступною (na);
 * - для конкурентів працює фільтр ai_categories; якщо його нема — ключові слова з title.
 */
final class FreeSerpClient
{
    private const BASE = 'https://freeserp.ai/api.php';
    private const IDENT = [
        'agent' => 'CiteReady/1.0',
        'project' => 'CiteReady',
        'website' => 'https://ws-109.ws.semalt.dev',
    ];

    public function __construct(
        private readonly int $timeout = 8,
        private readonly int $maxBytes = 1_048_576
    ) {
    }

    /**
     * Профіль домену з індексу sites. Повертає null, якщо точного збігу немає
     * або сервіс недоступний (відрізнити «нема в індексі» від «API лягло» дає ok).
     *
     * @return array{domain: string, title: ?string, ai_summary: ?string, dr: ?int,
     *               ai_categories: list<string>, ai_source: ?string, category: ?string,
     *               went_live: ?string}|null
     */
    public function profile(string $domain): ?array
    {
        $res = $this->request(['q' => $domain, 'all' => '1', 'size' => '1']);
        if ($res === null) {
            return null;
        }
        $first = $res['results'][0] ?? null;
        if (!is_array($first) || strtolower((string) ($first['domain'] ?? '')) !== $domain) {
            return null;
        }

        $cats = $first['ai_categories'] ?? [];
        return [
            'domain' => $domain,
            'title' => $this->str($first['title'] ?? null),
            'ai_summary' => $this->str($first['ai_summary'] ?? null),
            'dr' => isset($first['dr']) && is_numeric($first['dr']) ? (int) $first['dr'] : null,
            'ai_categories' => is_array($cats) ? array_values(array_filter($cats, 'is_string')) : [],
            'ai_source' => $this->str($first['ai_source'] ?? null),
            'category' => $this->str($first['category'] ?? null),
            'went_live' => $this->str($first['went_live'] ?? null),
        ];
    }

    /**
     * Скільки сторінок домену у глобальному (web) індексі.
     * Повертає null, коли веб-рушій недоступний (engine_fallback / не web / API лягло) —
     * перевірка E4 тоді стає na.
     *
     * @return array{count: int, examples: list<array{url: string, published_at: ?string}>}|null
     */
    public function domainPages(string $domain): ?array
    {
        $res = $this->request(['index' => 'web', 'q' => $domain, 'size' => '20']);
        if ($res === null
            || ($res['index'] ?? null) !== 'web'
            || !empty($res['engine_fallback'])) {
            return null;
        }

        $examples = [];
        $count = 0;
        foreach (($res['results'] ?? []) as $r) {
            if (!is_array($r) || strtolower((string) ($r['domain'] ?? '')) !== $domain) {
                continue;
            }
            $count++;
            if (count($examples) < 3) {
                $examples[] = [
                    'url' => $this->str($r['url'] ?? '') ?? '',
                    'published_at' => $this->str($r['published_at'] ?? null),
                ];
            }
        }

        return ['count' => $count, 'examples' => $examples];
    }

    /**
     * Конкуренти в ніші: за ai_categories, інакше — за ключовими словами з title.
     * Сам домен виключається.
     *
     * @param list<string> $aiCategories
     * @return list<array{domain: string, dr: ?int, summary: ?string}>
     */
    public function competitors(string $domain, array $aiCategories, ?string $title): array
    {
        if ($aiCategories !== []) {
            $params = ['ai_categories' => $aiCategories[0], 'sort' => 'dr', 'order' => 'desc', 'size' => '8'];
        } else {
            $kw = $this->keywords($title);
            if ($kw === '') {
                return [];
            }
            $params = ['q' => $kw, 'sort' => 'dr', 'order' => 'desc', 'size' => '8'];
        }

        $res = $this->request($params);
        if ($res === null) {
            return [];
        }

        $out = [];
        foreach (($res['results'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $d = strtolower((string) ($r['domain'] ?? ''));
            if ($d === '' || $d === $domain) {
                continue;
            }
            $out[] = [
                'domain' => $d,
                'dr' => isset($r['dr']) && is_numeric($r['dr']) ? (int) $r['dr'] : null,
                'summary' => $this->str($r['ai_summary'] ?? null),
            ];
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }

    /**
     * Один GET-запит до FreeSerp. Повертає декодований масив або null при будь-якій помилці.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function request(array $params): ?array
    {
        $url = self::BASE . '?' . http_build_query($params + self::IDENT);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_USERAGENT => 'CiteReadyBot/1.0 (+https://ws-109.ws.semalt.dev)',
            CURLOPT_ENCODING => '',
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn ($c, $dlTotal, $dlNow) => $dlNow > $this->maxBytes ? 1 : 0,
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        } else {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $status !== 200 || !is_string($body) || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['ok'])) {
            return null;
        }
        return $data;
    }

    private function keywords(?string $title): string
    {
        if ($title === null) {
            return '';
        }
        // Відрізаємо хвіст після роздільників (" - ", " | ", " · ", " — ").
        $title = preg_split('/\s[|\-–—·:]\s/u', $title)[0] ?? $title;
        $title = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title) ?? $title;
        $words = preg_split('/\s+/u', trim($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 3);
        return implode(' ', array_slice(array_values($words), 0, 4));
    }

    private function str(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
