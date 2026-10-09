<?php
declare(strict_types=1);

const APP_ROOT = '/var/www/citeready';
$config = require APP_ROOT . '/config/checks.php';

$year = date('Y');
$I18N = json_decode((string) file_get_contents(__DIR__ . '/i18n/en.json'), true) ?: [];
function d(string $key): string
{
    global $I18N;
    $v = $I18N;
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return '';
        }
        $v = $v[$k];
    }
    return is_string($v) ? htmlspecialchars($v, ENT_QUOTES) : '';
}

$catMax = $config['categories'];
// Згрупувати перевірки за категоріями, зберігаючи порядок.
$byCat = [];
foreach ($config['checks'] as $c) {
    $byCat[$c['category']][] = $c;
}
?>
<!doctype html>
<html lang="en" data-title-key="method.metaTitle" data-desc-key="method.metaDesc">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Methodology — CiteReady</title>
  <meta name="description" content="How CiteReady scores AI-readiness: every check, its weight, the data sources and what the score does and doesn't guarantee.">
  <link rel="canonical" href="https://ws-109.ws.semalt.dev/methodology.php">
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="CiteReady">
  <meta property="og:image" content="https://ws-109.ws.semalt.dev/assets/img/og.png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta property="og:title" content="Methodology — CiteReady">
  <meta property="og:description" content="How CiteReady scores AI-readiness: every check, its weight, the data sources and what the score does and doesn't guarantee.">
  <meta property="og:url" content="https://ws-109.ws.semalt.dev/methodology.php">
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="plain">
  <header class="site-header">
    <div class="container header-inner">
      <a class="logo" href="/" aria-label="CiteReady">Cite<span>Ready</span></a>
      <nav class="nav">
        <a href="/#faq" data-i18n="nav.how">How it works</a>
        <a href="/methodology.php" data-i18n="nav.methodology">Methodology</a>
        <div class="lang-switch" role="group" aria-label="Language">
          <button type="button" data-lang="en" aria-pressed="true">EN</button>
          <button type="button" data-lang="uk" aria-pressed="false">UA</button>
        </div>
      </nav>
    </div>
  </header>

  <main>
    <article class="container page">
      <h1 data-i18n="method.title">Methodology</h1>
      <p class="lead" data-i18n="method.intro"><?= d('method.intro') ?></p>

      <h2 data-i18n="method.sourcesH">Data sources</h2>
      <ul class="method-sources">
        <li data-i18n="method.source1"><?= d('method.source1') ?></li>
        <li data-i18n="method.source2"><?= d('method.source2') ?></li>
      </ul>

      <h2 data-i18n="method.scoringH">How scoring works</h2>
      <p data-i18n="method.scoring"><?= d('method.scoring') ?></p>
      <p class="muted" data-i18n="method.grades"><?= d('method.grades') ?></p>

      <h2 data-i18n="method.checksH">What we check</h2>
      <?php foreach (['A', 'B', 'C', 'D', 'E'] as $cat): ?>
        <section class="method-cat">
          <h3>
            <span data-i18n="cat.<?= $cat ?>"><?= d('cat.' . $cat) ?></span>
            <span class="cat-weight"><?= (int) $catMax[$cat]['max'] ?> <span data-i18n="method.pts">pts</span></span>
          </h3>
          <table class="method-table">
            <thead>
              <tr>
                <th data-i18n="method.check">Check</th>
                <th data-i18n="method.weight">Weight</th>
                <th data-i18n="method.why">Why it matters</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($byCat[$cat] as $c): ?>
                <tr>
                  <td data-i18n="checks.<?= $c['id'] ?>.name"><?= d('checks.' . $c['id'] . '.name') ?></td>
                  <td class="w"><?= (int) $c['weight'] ?></td>
                  <td data-i18n="checks.<?= $c['id'] ?>.why"><?= d('checks.' . $c['id'] . '.why') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      <?php endforeach; ?>

      <h2 data-i18n="method.disclaimerH">A note on the score</h2>
      <p data-i18n="method.disclaimer"><?= d('method.disclaimer') ?></p>

      <p class="method-back"><a href="/" data-i18n="method.back">Back to the checker</a></p>
    </article>
  </main>

  <footer class="site-footer">
    <div class="container footer-inner">
      <span data-i18n="footer.data">Data: our own checks + FreeSerp API</span>
      <span>© <?= htmlspecialchars($year, ENT_QUOTES) ?> CiteReady</span>
    </div>
  </footer>

  <script src="/assets/js/i18n.js"></script>
  <script>document.addEventListener('DOMContentLoaded', function () { window.CiteI18n.init(); });</script>
</body>
</html>
