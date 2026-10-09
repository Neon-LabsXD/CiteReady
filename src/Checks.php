<?php
declare(strict_types=1);

require_once __DIR__ . '/Fetcher.php';
require_once __DIR__ . '/RobotsParser.php';
require_once __DIR__ . '/HtmlAnalyzer.php';
require_once __DIR__ . '/FreeSerpClient.php';

/**
 * Виконує перевірки A–D для домену: завантажує robots.txt, llms.txt, sitemap.xml,
 * HTML головної та формує статуси (pass/warn/fail/na) з даними для фронтенду.
 *
 * Категорія E (FreeSerp) виконується, коли переданий FreeSerpClient.
 */
final class Checks
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly Fetcher $fetcher,
        ?array $config = null,
        private readonly ?FreeSerpClient $freeSerp = null
    ) {
        $this->config = $config ?? require __DIR__ . '/../config/checks.php';
    }

    /**
     * @return array{checks: list<array<string,mixed>>, ai_bots: list<array<string,mixed>>,
     *               html: ?array<string,mixed>, sitemaps: list<string>,
     *               profile: ?array<string,mixed>, competitors: list<array<string,mixed>>}
     */
    public function run(string $domain): array
    {
        $base = 'https://' . $domain;

        $robotsResp = $this->fetcher->get($base . '/robots.txt');
        $robotsOk = $robotsResp['ok'] && $robotsResp['status'] === 200;
        $robots = new RobotsParser($robotsOk ? $robotsResp['body'] : '');

        $home = $this->fetcher->get($base . '/');
        $homeHttps = $home['ok'] && $home['status'] >= 200 && $home['status'] < 400;
        $html = null;
        if ($homeHttps && $home['body'] !== '') {
            $html = (new HtmlAnalyzer($home['body']))->analyze();
        }

        $checks = [];
        [$a2, $aiBots] = $this->checkA2($robots, $robotsOk);

        $checks[] = $this->checkA1($robotsResp);
        $checks[] = $a2;
        $checks[] = $this->checkA3($base, $home);

        [$b1] = $this->checkB1($base);
        $checks[] = $b1;
        [$b2, $sitemaps] = $this->checkB2($base, $robots);
        $checks[] = $b2;

        $checks[] = $this->checkC1($html);
        $checks[] = $this->checkC2($html);
        $checks[] = $this->checkC3($html);

        $checks[] = $this->checkD1($html);
        $checks[] = $this->checkD2($html);
        $checks[] = $this->checkD3($html);
        $checks[] = $this->checkD4($html);
        $checks[] = $this->checkD5($html);
        $checks[] = $this->checkD6($html);

        [$eChecks, $profile, $competitors] = $this->runFreeSerp($domain);
        foreach ($eChecks as $c) {
            $checks[] = $c;
        }

        return [
            'checks' => $checks,
            'ai_bots' => $aiBots,
            'html' => $html,
            'sitemaps' => $sitemaps,
            'profile' => $profile,
            'competitors' => $competitors,
        ];
    }

    /**
     * Категорія E. Якщо клієнта немає — усі E як na (бали лишаються чесними).
     *
     * @return array{0: list<array<string,mixed>>, 1: ?array<string,mixed>, 2: list<array<string,mixed>>}
     */
    private function runFreeSerp(string $domain): array
    {
        if ($this->freeSerp === null) {
            return [[
                $this->make('E1', 'na'), $this->make('E2', 'na'),
                $this->make('E3', 'na'), $this->make('E4', 'na'),
            ], null, []];
        }

        $profile = $this->freeSerp->profile($domain);
        $checks = [];

        // E1 — сайт є в індексі sites
        $checks[] = $profile !== null
            ? $this->make('E1', 'pass', ['found' => true])
            : $this->make('E1', 'na');

        // E2 — AI-опис сайту
        if ($profile !== null && ($profile['ai_summary'] ?? null) !== null) {
            $checks[] = $this->make('E2', 'pass', ['ai_summary' => $profile['ai_summary']]);
        } else {
            $checks[] = $this->make('E2', 'na');
        }

        // E3 — Domain Rating
        $dr = $profile['dr'] ?? null;
        if ($dr === null) {
            $checks[] = $this->make('E3', 'na');
        } elseif ($dr >= 30) {
            $checks[] = $this->make('E3', 'pass', ['dr' => $dr]);
        } elseif ($dr >= 10) {
            $checks[] = $this->make('E3', 'warn', ['dr' => $dr]);
        } else {
            $checks[] = $this->make('E3', 'fail', ['dr' => $dr]);
        }

        // E4 — сторінки в глобальному індексі (na, якщо web-рушій недоступний)
        $pages = $this->freeSerp->domainPages($domain);
        if ($pages === null) {
            $checks[] = $this->make('E4', 'na', ['reason' => 'web_unavailable']);
        } elseif ($pages['count'] >= 5) {
            $checks[] = $this->make('E4', 'pass', $pages);
        } elseif ($pages['count'] >= 1) {
            $checks[] = $this->make('E4', 'warn', $pages);
        } else {
            $checks[] = $this->make('E4', 'fail', $pages);
        }

        $competitors = $profile !== null
            ? $this->freeSerp->competitors($domain, $profile['ai_categories'], $profile['title'])
            : [];

        return [$checks, $profile, $competitors];
    }

    private function make(string $id, string $status, array $data = []): array
    {
        return ['id' => $id, 'status' => $status, 'data' => $data];
    }

    // --- A. Доступ AI-краулерів ---

    private function checkA1(array $resp): array
    {
        if ($resp['ok'] && $resp['status'] === 200) {
            return $this->make('A1', 'pass', ['status' => 200]);
        }
        if ($resp['ok'] && $resp['status'] === 404) {
            return $this->make('A1', 'warn', ['status' => 404]);
        }
        return $this->make('A1', 'fail', ['status' => $resp['status'], 'error' => $resp['error']]);
    }

    /** @return array{0: array<string,mixed>, 1: list<array<string,mixed>>} */
    private function checkA2(RobotsParser $robots, bool $robotsOk): array
    {
        $bots = [];
        $blockedSearch = false;
        $blockedAny = false;

        foreach ($this->config['ai_bots'] as $spec) {
            $allowed = $robots->isAllowed($spec['bot'], '/');
            if (!$allowed) {
                $blockedAny = true;
                if ($spec['search']) {
                    $blockedSearch = true;
                }
            }
            $bots[] = [
                'bot' => $spec['bot'],
                'owner' => $spec['owner'],
                'search' => $spec['search'],
                'allowed' => $allowed,
                'explicit' => $robots->hasGroupFor($spec['bot']),
            ];
        }

        if ($blockedSearch) {
            $status = 'fail';
        } elseif ($blockedAny) {
            $status = 'warn';
        } else {
            $status = 'pass';
        }

        return [$this->make('A2', $status, ['has_robots' => $robotsOk, 'blocked_any' => $blockedAny]), $bots];
    }

    private function checkA3(string $base, array $home): array
    {
        if ($home['ok'] && $home['status'] >= 200 && $home['status'] < 400) {
            return $this->make('A3', 'pass', ['status' => $home['status'], 'scheme' => 'https']);
        }
        $http = $this->fetcher->get('http://' . substr($base, 8) . '/');
        if ($http['ok'] && $http['status'] >= 200 && $http['status'] < 400) {
            return $this->make('A3', 'warn', ['status' => $http['status'], 'scheme' => 'http']);
        }
        return $this->make('A3', 'fail', ['status' => $home['status'] ?: $http['status'], 'error' => $home['error']]);
    }

    // --- B. Файли для AI ---

    /** @return array{0: array<string,mixed>} */
    private function checkB1(string $base): array
    {
        $resp = $this->fetcher->get($base . '/llms.txt');
        if (!$resp['ok'] || $resp['status'] !== 200) {
            return [$this->make('B1', 'fail', ['status' => $resp['status']])];
        }
        $ctype = strtolower($resp['headers']['content-type'] ?? '');
        $body = ltrim($resp['body']);
        $looksHtml = $ctype !== '' && (str_contains($ctype, 'text/html') || str_contains($ctype, 'application/xhtml'));
        $startsHtml = stripos($body, '<!doctype') === 0 || stripos($body, '<html') === 0;
        if ($looksHtml || $startsHtml || $body === '') {
            return [$this->make('B1', 'fail', ['status' => 200, 'reason' => 'html_or_empty'])];
        }
        return [$this->make('B1', 'pass', ['status' => 200, 'bytes' => strlen($resp['body'])])];
    }

    /** @return array{0: array<string,mixed>, 1: list<string>} */
    private function checkB2(string $base, RobotsParser $robots): array
    {
        $candidates = [];
        foreach ($robots->sitemaps() as $sm) {
            $candidates[] = $sm;
        }
        $candidates[] = $base . '/sitemap.xml';

        $found = [];
        $valid = false;
        foreach (array_slice(array_values(array_unique($candidates)), 0, 3) as $url) {
            if (Fetcher::normalizeDomain($url) === null && !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $resp = $this->fetcher->get($url);
            if ($resp['ok'] && $resp['status'] === 200) {
                $found[] = $url;
                if ($this->looksLikeSitemap($resp['body'])) {
                    $valid = true;
                    break;
                }
            }
        }

        $status = $valid ? 'pass' : 'fail';
        return [$this->make('B2', $status, ['found' => $found]), $found];
    }

    private function looksLikeSitemap(string $body): bool
    {
        $head = ltrim($body);
        if ($head === '' || stripos($head, '<') !== 0) {
            return false;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($head);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return false;
        }
        $name = strtolower($xml->getName());
        return $name === 'urlset' || $name === 'sitemapindex';
    }

    // --- C. Структуровані дані ---

    private function checkC1(?array $html): array
    {
        if ($html === null) {
            return $this->make('C1', 'fail', ['reason' => 'no_html']);
        }
        return $html['jsonld_valid']
            ? $this->make('C1', 'pass', ['types' => $html['jsonld_types']])
            : $this->make('C1', 'fail', []);
    }

    private function checkC2(?array $html): array
    {
        if ($html === null) {
            return $this->make('C2', 'fail', ['reason' => 'no_html']);
        }
        $types = $html['jsonld_types'];
        $useful = array_values(array_intersect($this->config['useful_schema'], $types));
        if ($useful !== []) {
            return $this->make('C2', 'pass', ['useful' => $useful, 'all' => $types]);
        }
        if ($types !== []) {
            return $this->make('C2', 'warn', ['all' => $types]);
        }
        return $this->make('C2', 'fail', []);
    }

    private function checkC3(?array $html): array
    {
        if ($html === null) {
            return $this->make('C3', 'fail', ['reason' => 'no_html']);
        }
        $og = $html['open_graph'];
        $hasTitle = isset($og['og:title']);
        $hasDesc = isset($og['og:description']);
        $hasImage = isset($og['og:image']);
        if ($hasTitle && $hasDesc) {
            return $this->make('C3', 'pass', ['image' => $hasImage, 'found' => array_keys($og)]);
        }
        if ($hasTitle || $hasDesc || $hasImage) {
            return $this->make('C3', 'warn', ['found' => array_keys($og)]);
        }
        return $this->make('C3', 'fail', []);
    }

    // --- D. Контент і мета-теги ---

    private function checkD1(?array $html): array
    {
        $title = $html['title'] ?? null;
        if ($title === null) {
            return $this->make('D1', 'fail', []);
        }
        $len = mb_strlen($title);
        $status = ($len >= 30 && $len <= 65) ? 'pass' : 'warn';
        return $this->make('D1', $status, ['length' => $len]);
    }

    private function checkD2(?array $html): array
    {
        $desc = $html['meta_description'] ?? null;
        if ($desc === null) {
            return $this->make('D2', 'fail', []);
        }
        $len = mb_strlen($desc);
        $status = ($len >= 70 && $len <= 160) ? 'pass' : 'warn';
        return $this->make('D2', $status, ['length' => $len]);
    }

    private function checkD3(?array $html): array
    {
        $count = $html['h1_count'] ?? 0;
        if ($count === 1) {
            return $this->make('D3', 'pass', ['count' => 1]);
        }
        return $this->make('D3', $count > 1 ? 'warn' : 'fail', ['count' => $count]);
    }

    private function checkD4(?array $html): array
    {
        $hasLang = !empty($html['lang']);
        $hasCanonical = !empty($html['canonical']);
        if ($hasLang && $hasCanonical) {
            return $this->make('D4', 'pass', ['lang' => $html['lang'] ?? null, 'canonical' => true]);
        }
        if ($hasLang || $hasCanonical) {
            return $this->make('D4', 'warn', ['lang' => $hasLang, 'canonical' => $hasCanonical]);
        }
        return $this->make('D4', 'fail', []);
    }

    private function checkD5(?array $html): array
    {
        $words = $html['word_count'] ?? 0;
        if ($words >= 300) {
            return $this->make('D5', 'pass', ['words' => $words]);
        }
        if ($words >= 100) {
            return $this->make('D5', 'warn', ['words' => $words]);
        }
        return $this->make('D5', 'fail', ['words' => $words]);
    }

    private function checkD6(?array $html): array
    {
        if ($html === null) {
            return $this->make('D6', 'warn', ['reason' => 'no_html']);
        }
        return !empty($html['qa_format'])
            ? $this->make('D6', 'pass', [])
            : $this->make('D6', 'warn', []);
    }
}
