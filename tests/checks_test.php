<?php
declare(strict_types=1);

// php tests/checks_test.php — unit-тести парсерів і Scorer (без мережі).
require __DIR__ . '/../src/RobotsParser.php';
require __DIR__ . '/../src/HtmlAnalyzer.php';
require __DIR__ . '/../src/Scorer.php';

$failed = 0;
$check = static function (string $name, bool $cond, string $info = '') use (&$failed): void {
    if (!$cond) { $failed++; }
    printf("[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $name, $info !== '' ? '  → ' . $info : '');
};

echo "== RobotsParser ==\n";
$r = new RobotsParser("User-agent: *\nDisallow: /private\nSitemap: https://x.test/sitemap.xml\n\nUser-agent: GPTBot\nDisallow: /\n");
$check('GPTBot заблоковано', $r->isAllowed('GPTBot', '/') === false);
$check('* дозволяє /', $r->isAllowed('PerplexityBot', '/') === true);
$check('* блокує /private', $r->isAllowed('PerplexityBot', '/private') === false);
$check('sitemap знайдено', $r->sitemaps() === ['https://x.test/sitemap.xml']);
$check('hasGroupFor GPTBot', $r->hasGroupFor('GPTBot') === true);
$check('hasGroupFor CCBot (нема)', $r->hasGroupFor('CCBot') === false);

$r2 = new RobotsParser("User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n\nUser-agent: *\nDisallow:\n");
$check('кілька UA в групі: GPTBot', $r2->isAllowed('GPTBot', '/') === false);
$check('кілька UA в групі: CCBot', $r2->isAllowed('CCBot', '/') === false);
$check('* порожній Disallow дозволяє', $r2->isAllowed('PerplexityBot', '/') === true);

$r3 = new RobotsParser("User-agent: *\nDisallow: /\nAllow: /blog\n");
$check('Allow /blog перекриває', $r3->isAllowed('GPTBot', '/blog/post') === true);
$check('Disallow / блокує корінь', $r3->isAllowed('GPTBot', '/') === false);

$r4 = new RobotsParser("");
$check('порожній robots дозволяє все', $r4->isAllowed('GPTBot', '/') === true && $r4->hasAnyGroup() === false);

echo "\n== HtmlAnalyzer ==\n";
$html = '<!doctype html><html lang="uk"><head><title>Тестова сторінка про котиків та все інше</title>'
      . '<meta name="description" content="' . str_repeat('опис ', 20) . '">'
      . '<link rel="canonical" href="https://x.test/">'
      . '<meta property="og:title" content="T"><meta property="og:description" content="D">'
      . '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite"},{"@type":"FAQPage"}]}</script>'
      . '</head><body><h1>Заголовок</h1><h2>Що це таке?</h2><p>' . str_repeat('слово ', 350) . '</p>'
      . '<script>var x=1;</script></body></html>';
$a = (new HtmlAnalyzer($html))->analyze();
$check('title', $a['title'] === 'Тестова сторінка про котиків та все інше');
$check('lang uk', $a['lang'] === 'uk');
$check('canonical', $a['canonical'] === 'https://x.test/');
$check('h1 = 1', $a['h1_count'] === 1);
$check('jsonld types @graph', in_array('WebSite', $a['jsonld_types'], true) && in_array('FAQPage', $a['jsonld_types'], true));
$check('jsonld_valid', $a['jsonld_valid'] === true);
$check('og title+desc', isset($a['open_graph']['og:title'], $a['open_graph']['og:description']));
$check('word_count > 300 без script', $a['word_count'] >= 350 && $a['word_count'] < 360, (string) $a['word_count']);
$check('qa_format (h2 з ? + FAQPage)', $a['qa_format'] === true);

$a2 = (new HtmlAnalyzer('<html><head><title>x</title></head><body><h1>a</h1><h1>b</h1></body></html>'))->analyze();
$check('2 h1', $a2['h1_count'] === 2);
$check('без lang', $a2['lang'] === null);
$check('без jsonld', $a2['jsonld_valid'] === false && $a2['jsonld_types'] === []);

echo "\n== Scorer ==\n";
$config = require __DIR__ . '/../config/checks.php';
$scorer = new Scorer($config);

// Усе pass, окрім E (na) → 80/80 активних = score 80, grade B.
$allPassADsomeE = [];
foreach ($config['checks'] as $c) {
    $allPassADsomeE[] = ['id' => $c['id'], 'status' => $c['category'] === 'E' ? 'na' : 'pass', 'data' => []];
}
$s = $scorer->score($allPassADsomeE);
$check('A–D pass, E na → score 80', $s['score'] === 80, (string) $s['score']);
$check('grade B', $s['grade'] === 'B');
$catE = array_values(array_filter($s['categories'], fn($c) => $c['id'] === 'E'))[0];
$check('категорія E all_na', $catE['all_na'] === true && $catE['score'] === 0);

// na перерозподіл у категорії E: E1 pass, решта na → E = 20 (повний бал).
$checks = [];
foreach ($config['checks'] as $c) {
    $st = 'pass';
    if ($c['category'] === 'E') { $st = $c['id'] === 'E1' ? 'pass' : 'na'; }
    $checks[] = ['id' => $c['id'], 'status' => $st, 'data' => []];
}
$s2 = $scorer->score($checks);
$catE2 = array_values(array_filter($s2['categories'], fn($c) => $c['id'] === 'E'))[0];
$check('E: лише E1 pass, решта na → E = 20', $catE2['score'] === 20, (string) $catE2['score']);
$check('повний бал = 100', $s2['score'] === 100 && $s2['grade'] === 'A', (string) $s2['score']);

// Усе fail → 0, grade F.
$allFail = array_map(fn($c) => ['id' => $c['id'], 'status' => 'fail', 'data' => []], $config['checks']);
$sf = $scorer->score($allFail);
$check('усе fail → 0 / F', $sf['score'] === 0 && $sf['grade'] === 'F');

// warn = половина ваги: категорія A усе warn → 12 або 13 (25/2).
$aWarn = array_map(fn($c) => ['id' => $c['id'], 'status' => $c['category'] === 'A' ? 'warn' : 'na', 'data' => []], $config['checks']);
$sw = $scorer->score($aWarn);
$catA = array_values(array_filter($sw['categories'], fn($c) => $c['id'] === 'A'))[0];
$check('A усе warn → ~12.5', $catA['score'] === 13 || $catA['score'] === 12, (string) $catA['score']);

echo "\n" . ($failed === 0 ? 'ВСІ ТЕСТИ ПРОЙДЕНО' : "ПРОВАЛЕНО: $failed") . "\n";
exit($failed === 0 ? 0 : 1);
