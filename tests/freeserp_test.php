<?php
declare(strict_types=1);

// php tests/freeserp_test.php — перевіряє логіку парсингу FreeSerpClient на мок-даних
// (приватні методи через рефлексію) + стабільність Scorer для категорії E.
require __DIR__ . '/../src/FreeSerpClient.php';
require __DIR__ . '/../src/Scorer.php';

$failed = 0;
$check = static function (string $name, bool $cond, string $info = '') use (&$failed): void {
    if (!$cond) { $failed++; }
    printf("[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $name, $info !== '' ? '  → ' . $info : '');
};

$client = new FreeSerpClient();
$kw = new ReflectionMethod(FreeSerpClient::class, 'keywords');

echo "== keywords() з title ==\n";
$check('відрізає хвіст після |', $kw->invoke($client, 'Best Dental Clinic in Kyiv | SmileCo') === 'Best Dental Clinic Kyiv', $kw->invoke($client, 'Best Dental Clinic in Kyiv | SmileCo'));
$check('відрізає після тире', $kw->invoke($client, 'Acme — AI data platform') === 'Acme', $kw->invoke($client, 'Acme — AI data platform'));
$check('max 4 слова', count(explode(' ', $kw->invoke($client, 'one two three four five six'))) === 4);
$check('null → порожньо', $kw->invoke($client, null) === '');
$check('прибирає короткі (<3) слова', $kw->invoke($client, 'AI to go now platform') === 'now platform', $kw->invoke($client, 'AI to go now platform'));

echo "\n== Scorer: категорія E (real FreeSerp сценарії) ==\n";
$config = require __DIR__ . '/../config/checks.php';
$scorer = new Scorer($config);

// Сценарій github-подібний: A–D різні, E = {E1 pass, E2 pass, E3 pass(dr80), E4 na}
$mk = fn(string $id, string $st) => ['id'=>$id,'status'=>$st,'data'=>[]];
$checks = [];
foreach ($config['checks'] as $c) {
    if ($c['category'] !== 'E') { $checks[] = $mk($c['id'],'pass'); }
}
$checks[] = $mk('E1','pass'); $checks[] = $mk('E2','pass'); $checks[] = $mk('E3','pass'); $checks[] = $mk('E4','na');
$s = $scorer->score($checks);
$catE = array_values(array_filter($s['categories'], fn($c)=>$c['id']==='E'))[0];
// E: 3 активні перевірки по 5 (15 ваги) масштабуються до max 20 → усі pass = 20
$check('E {pass,pass,pass,na} → 20', $catE['score'] === 20, (string)$catE['score']);
$check('повний бал 100 / A', $s['score'] === 100 && $s['grade'] === 'A', (string)$s['score']);

// Сценарій «немає в індексі зовсім»: усі E = na → E all_na, не тягне вниз
$checks2 = [];
foreach ($config['checks'] as $c) {
    $checks2[] = $mk($c['id'], $c['category']==='E' ? 'na' : 'pass');
}
$s2 = $scorer->score($checks2);
$catE2 = array_values(array_filter($s2['categories'], fn($c)=>$c['id']==='E'))[0];
$check('усі E na → E all_na, score 80/B', $catE2['all_na'] && $s2['score'] === 80 && $s2['grade']==='B', (string)$s2['score']);

// Сценарій низького DR: E3 fail(dr5), E1/E2 pass, E4 warn
$checks3 = [];
foreach ($config['checks'] as $c) {
    if ($c['category'] !== 'E') { $checks3[] = $mk($c['id'],'fail'); }
}
$checks3[] = $mk('E1','pass'); $checks3[] = $mk('E2','pass'); $checks3[] = $mk('E3','fail'); $checks3[] = $mk('E4','warn');
$s3 = $scorer->score($checks3);
$catE3 = array_values(array_filter($s3['categories'], fn($c)=>$c['id']==='E'))[0];
// активні 4×5=20 ваги → max 20; earned = 5(E1)+5(E2)+0(E3)+2.5(E4)=12.5 → 13
$check('E {pass,pass,fail,warn} → 13', $catE3['score'] === 13, (string)$catE3['score']);

echo "\n" . ($failed === 0 ? 'ВСІ ТЕСТИ ПРОЙДЕНО' : "ПРОВАЛЕНО: $failed") . "\n";
exit($failed === 0 ? 0 : 1);
