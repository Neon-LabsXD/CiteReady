<?php
declare(strict_types=1);

// Запуск: php tests/ssrf_test.php
require __DIR__ . '/../src/Fetcher.php';

$failed = 0;
$check = static function (string $name, bool $cond, string $info = '') use (&$failed): void {
    if (!$cond) {
        $failed++;
    }
    printf("[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $name, $info !== '' ? '  → ' . $info : '');
};

echo "== normalizeDomain ==\n";
$norm = [
    'example.com' => 'example.com',
    'https://www.example.com/some/page' => 'example.com',
    'HTTP://Example.COM/?a=1#x' => 'example.com',
    'www.example.com/path' => 'example.com',
    'example.com.' => 'example.com',
    'https://example.com:443/' => 'example.com',
    'пример.рф' => 'xn--e1afmkfd.xn--p1ai',
    'localhost' => null,
    '127.0.0.1' => null,
    'http://127.0.0.1/' => null,
    '192.168.1.1' => null,
    '[::1]' => null,
    'http://[::1]/' => null,
    'abc' => null,
    'http://' => null,
    '' => null,
    'example.com:8080' => null,
    'https://example.com:8443/' => null,
    'user:pass@example.com' => null,
    'https://user@example.com/' => null,
    'ftp://example.com' => null,
    'javascript:alert(1)' => null,
    'exa mple.com' => null,
    '-bad-.com' => null,
    'a..com' => null,
];
foreach ($norm as $in => $expected) {
    $got = Fetcher::normalizeDomain((string) $in);
    $check('normalize ' . var_export((string) $in, true), $got === $expected, var_export($got, true));
}

echo "\n== isPublicIp ==\n";
$ips = [
    '8.8.8.8' => true, '1.1.1.1' => true, '140.82.112.3' => true, '2606:4700:4700::1111' => true,
    '127.0.0.1' => false, '127.1.2.3' => false, '10.0.0.1' => false, '172.16.5.5' => false,
    '172.32.0.1' => true, '192.168.1.1' => false, '169.254.169.254' => false, '0.0.0.0' => false,
    '100.64.0.1' => false, '224.0.0.1' => false, '255.255.255.255' => false,
    '::1' => false, '::' => false, 'fc00::1' => false, 'fd12:3456::1' => false, 'fe80::1' => false,
    '::ffff:127.0.0.1' => false, '::ffff:10.0.0.1' => false, '::ffff:8.8.8.8' => true,
    '2001:db8::1' => false, 'not-an-ip' => false,
];
foreach ($ips as $ip => $expected) {
    $check('isPublicIp ' . $ip, Fetcher::isPublicIp((string) $ip) === $expected);
}

echo "\n== get(): заборонені цілі ==\n";
$fetcher = new Fetcher();
$blocked = [
    'http://localhost/', 'http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/',
    'http://10.0.0.1/', 'http://192.168.1.1/', 'http://[::1]/', 'http://0.0.0.0/',
    'http://2130706433/', 'http://0x7f.0.0.1/', 'file:///etc/passwd', 'gopher://example.com/',
    'http://user:pass@example.com/', 'https://example.com:8443/', 'http://example.com:22/',
];
foreach ($blocked as $url) {
    $r = $fetcher->get($url);
    $check('blocked ' . $url, $r['ok'] === false && $r['error'] === 'blocked', (string) $r['error'] . ':' . $r['detail']);
}

echo "\n== get(): hostname, що резолвиться у приватну IP ==\n";
foreach (['127.0.0.1.nip.io', 'localtest.me', '10.0.0.1.nip.io', '169.254.169.254.nip.io'] as $host) {
    $r = $fetcher->get('http://' . $host . '/');
    $ok = $r['ok'] === false && in_array($r['error'], ['blocked', 'unreachable'], true);
    $check('private-dns ' . $host, $ok, $r['error'] . ':' . $r['detail'] . ($r['error'] === 'unreachable' ? ' (DNS недоступний — не доводить захист)' : ''));
}

echo "\n== get(): перевірка цілей редиректів (prepare) ==\n";
$prepare = new ReflectionMethod(Fetcher::class, 'prepare');
foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/', 'http://localhost:80/', 'gopher://example.com/', 'http://example.com:6379/'] as $url) {
    try {
        $prepare->invoke($fetcher, $url);
        $check('redirect-target ' . $url, false, 'не відхилено');
    } catch (FetchException $e) {
        $check('redirect-target ' . $url, $e->reason === 'blocked', $e->getMessage());
    }
}
$resolve = new ReflectionMethod(Fetcher::class, 'resolveLocation');
$base = ['scheme' => 'https', 'host' => 'example.com', 'port' => 443, 'explicit_port' => false, 'path' => '/a/b?x=1', 'ips' => []];
$check('resolveLocation relative', $resolve->invoke($fetcher, $base, 'c') === 'https://example.com/a/c');
$check('resolveLocation root', $resolve->invoke($fetcher, $base, '/z') === 'https://example.com/z');
$check('resolveLocation scheme-relative', $resolve->invoke($fetcher, $base, '//evil.test/p') === 'https://evil.test/p');
$check('resolveLocation absolute', $resolve->invoke($fetcher, $base, 'http://127.0.0.1/') === 'http://127.0.0.1/');

echo "\n== get(): реальні запити ==\n";
$r = $fetcher->get('https://github.com/');
$check('github.com https', $r['ok'] && $r['status'] === 200 && strlen($r['body']) > 1000, "status={$r['status']} bytes=" . strlen($r['body']) . " err={$r['error']}:{$r['detail']}");

$r = $fetcher->get('http://github.com/');
$check('http→https редирект', $r['ok'] && $r['redirects'] >= 1 && str_starts_with($r['final_url'], 'https://'), "final={$r['final_url']} redirects={$r['redirects']} status={$r['status']}");

$r = $fetcher->get('https://github.com/robots.txt');
$check('github robots.txt', $r['ok'] && $r['status'] === 200 && str_contains($r['headers']['content-type'] ?? '', 'text/plain'), ($r['headers']['content-type'] ?? '?'));

$r = $fetcher->get('https://example.com/definitely-not-found-404');
$check('404 повертається як ok=true', $r['ok'] && $r['status'] === 404, "status={$r['status']}");

$r = (new Fetcher(maxBytes: 1000))->get('https://github.com/');
$check('ліміт розміру', $r['ok'] && $r['truncated'] && strlen($r['body']) === 1000, 'bytes=' . strlen($r['body']) . ' truncated=' . var_export($r['truncated'], true));

$r = $fetcher->get('https://neisnuyuchyi-domen-12345.com/');
$check('неіснуючий домен', $r['ok'] === false && $r['error'] === 'unreachable', "{$r['error']}:{$r['detail']}");

$r = $fetcher->get('https://httpbin.org/redirect-to?url=http%3A%2F%2F127.0.0.1%2F&status=302');
echo '[INFO] httpbin redirect→127.0.0.1: ok=' . var_export($r['ok'], true) . ' error=' . var_export($r['error'], true)
    . ' detail=' . $r['detail'] . ' status=' . $r['status'] . "\n";
$check('редирект на приватну IP не виконується', !($r['ok'] && $r['status'] === 200 && str_contains($r['final_url'], '127.0.0.1')));

echo "\n" . ($failed === 0 ? 'ВСІ ТЕСТИ ПРОЙДЕНО' : "ПРОВАЛЕНО: $failed") . "\n";
exit($failed === 0 ? 0 : 1);
