<?php
declare(strict_types=1);

// php tests/cache_ratelimit_test.php — Cache і RateLimiter на тимчасовій теці.
require __DIR__ . '/../src/Cache.php';
require __DIR__ . '/../src/RateLimiter.php';

$failed = 0;
$check = static function (string $name, bool $cond, string $info = '') use (&$failed): void {
    if (!$cond) { $failed++; }
    printf("[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $name, $info !== '' ? '  → ' . $info : '');
};

$dir = sys_get_temp_dir() . '/cr_test_' . bin2hex(random_bytes(4));
mkdir($dir);

echo "== Cache ==\n";
$cache = new Cache($dir, 2);
$check('міс → null', $cache->get('example.com') === null);
$cache->set('example.com', ['score' => 72, 'grade' => 'B']);
$got = $cache->get('example.com');
$check('hit повертає дані', is_array($got) && $got['score'] === 72);
$check('ключ не чутливий до регістру', $cache->get('Example.COM') !== null);
// протермінування
$expCache = new Cache($dir, -1);
$expCache->set('old.com', ['x' => 1]);
$check('прострочене → null', $expCache->get('old.com') === null);

echo "\n== RateLimiter (max 3, window 100) ==\n";
$rl = new RateLimiter($dir, 3, 100);
$check('1-й дозволено', $rl->allow('1.2.3.4') === true);
$check('2-й дозволено', $rl->allow('1.2.3.4') === true);
$check('3-й дозволено', $rl->allow('1.2.3.4') === true);
$check('4-й заблоковано', $rl->allow('1.2.3.4') === false);
$check('інший IP не зачеплено', $rl->allow('5.6.7.8') === true);

echo "\n== RateLimiter: вікно, що минуло ==\n";
$rl2 = new RateLimiter($dir, 2, -1); // вікно -1 → усі старі мітки поза вікном
$check('після вікна знову дозволено', $rl2->allow('9.9.9.9') && $rl2->allow('9.9.9.9') && $rl2->allow('9.9.9.9'));

echo "\n== clientIp ==\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$check('валідний REMOTE_ADDR', RateLimiter::clientIp() === '203.0.113.9');
$_SERVER['REMOTE_ADDR'] = 'garbage';
$check('невалідний → 0.0.0.0', RateLimiter::clientIp() === '0.0.0.0');

// прибрати
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);

echo "\n" . ($failed === 0 ? 'ВСІ ТЕСТИ ПРОЙДЕНО' : "ПРОВАЛЕНО: $failed") . "\n";
exit($failed === 0 ? 0 : 1);
