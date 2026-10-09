<?php
declare(strict_types=1);

/**
 * GET /api/check.php?domain=example.com → JSON-звіт про AI-готовність.
 * Повертає лише id/status перевірок; тексти бере фронтенд з i18n.
 * (Етап 5: + кеш результатів на 1 годину за доменом і rate-limit за IP.)
 */

const APP_ROOT = '/var/www/citeready';

require_once APP_ROOT . '/src/Fetcher.php';
require_once APP_ROOT . '/src/Checks.php';
require_once APP_ROOT . '/src/FreeSerpClient.php';
require_once APP_ROOT . '/src/Cache.php';
require_once APP_ROOT . '/src/RateLimiter.php';
require_once APP_ROOT . '/src/Scorer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $http, array $payload): never
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $http, string $error): never
{
    respond($http, ['ok' => false, 'error' => $error]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail(405, 'method_not_allowed');
}

$raw = (string) ($_GET['domain'] ?? '');
$domain = Fetcher::normalizeDomain($raw);
if ($domain === null) {
    fail(400, 'invalid_domain');
}

$storage = APP_ROOT . '/storage';
if (!(new RateLimiter($storage))->allow(RateLimiter::clientIp())) {
    fail(429, 'rate_limited');
}

$cache = new Cache($storage);
$cached = $cache->get($domain);
if ($cached !== null) {
    $cached['cached'] = true;
    respond(200, $cached);
}

$config = require APP_ROOT . '/config/checks.php';

try {
    $fetcher = new Fetcher();
    $freeSerp = new FreeSerpClient();
    $result = (new Checks($fetcher, $config, $freeSerp))->run($domain);

    // Якщо головна недоступна і robots теж — вважаємо домен недосяжним.
    $a3 = null;
    foreach ($result['checks'] as $c) {
        if ($c['id'] === 'A3') {
            $a3 = $c;
        }
    }
    $homeDown = $a3 !== null && $a3['status'] === 'fail';

    if ($homeDown) {
        fail(502, 'unreachable');
    }

    $scored = (new Scorer($config))->score($result['checks']);

    $payload = [
        'ok' => true,
        'domain' => $domain,
        'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'score' => $scored['score'],
        'grade' => $scored['grade'],
        'categories' => $scored['categories'],
        'checks' => $scored['checks'],
        'ai_bots' => $result['ai_bots'],
        'profile' => $result['profile'],
        'competitors' => $result['competitors'],
        'cached' => false,
    ];
    $cache->set($domain, $payload);
    respond(200, $payload);
} catch (Throwable $e) {
    error_log('[citeready] ' . $e->getMessage());
    fail(500, 'internal');
}
