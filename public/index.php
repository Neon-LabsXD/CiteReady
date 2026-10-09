<?php
declare(strict_types=1);

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


/** Інлайн lucide-іконки (MIT), stroke=currentColor. */
function icon(string $name): string
{
    $paths = [
        'sparkles' => '<path d="M9.94 4.66 11 2l1.06 2.66L15 6l-2.94 1.34L11 10 9.94 7.34 7 6z"/><path d="M19 11l.7 1.8L21.5 13.5 19.7 14.2 19 16l-.7-1.8L16.5 13.5l1.8-.7z"/><path d="M5 13l.7 1.8L7.5 15.5 5.7 16.2 5 18l-.7-1.8L2.5 15.5l1.8-.7z"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'radar' => '<path d="M19.07 4.93A10 10 0 0 0 6.99 3.34"/><path d="M4 6h.01"/><path d="M2.29 9.62A10 10 0 1 0 21.31 8.35"/><path d="M16.24 7.76A6 6 0 1 0 8.23 16.67"/><path d="M12 18h.01"/><path d="M17.99 11.66A6 6 0 0 1 15.77 16.67"/><circle cx="12" cy="12" r="2"/><path d="m13.41 10.59 5.66-5.66"/>',
        'bot' => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
        'route' => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <script>document.documentElement.className+=" js";</script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CiteReady — Is your website ready to be cited by AI?</title>
  <meta name="description" content="Free AI visibility checker: score your site 0–100 for readiness to be found and cited by ChatGPT, Claude, Perplexity and Google AI Overviews.">
  <link rel="canonical" href="https://ws-109.ws.semalt.dev/">
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="CiteReady">
  <meta property="og:image" content="https://ws-109.ws.semalt.dev/assets/img/og.png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta property="og:title" content="CiteReady — Is your website ready to be cited by AI?">
  <meta property="og:description" content="Free AI visibility checker: score your site 0–100 for readiness to be found and cited by ChatGPT, Claude, Perplexity and Google AI Overviews.">
  <meta property="og:url" content="https://ws-109.ws.semalt.dev/">
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/results.css">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "WebApplication",
        "name": "CiteReady",
        "url": "https://ws-109.ws.semalt.dev/",
        "applicationCategory": "BusinessApplication",
        "operatingSystem": "Web",
        "description": "Free AI visibility checker that scores how ready a website is to be found and cited by AI assistants and AI search.",
        "offers": { "@type": "Offer", "price": "0", "priceCurrency": "USD" }
      },
      {
        "@type": "FAQPage",
        "mainEntity": [
          { "@type": "Question", "name": "What is GEO (Generative Engine Optimization)?", "acceptedAnswer": { "@type": "Answer", "text": "It's optimizing your site so AI assistants and AI search can find, understand and cite it. Think of it as the AI-era counterpart to SEO." } },
          { "@type": "Question", "name": "What is llms.txt?", "acceptedAnswer": { "@type": "Answer", "text": "An emerging plain-text file at your site root that tells AI models what your site is about and which pages matter, like robots.txt but for describing content to LLMs." } },
          { "@type": "Question", "name": "Should I block AI crawlers?", "acceptedAnswer": { "@type": "Answer", "text": "Usually not. Blocking them means assistants can't read or cite you. Block specific bots only when you have a clear reason, such as protecting paid content." } },
          { "@type": "Question", "name": "Does a high score guarantee my site will be cited?", "acceptedAnswer": { "@type": "Answer", "text": "No. The score measures technical readiness. Whether you get cited also depends on content quality, authority and relevance. It's a guide, not a guarantee." } },
          { "@type": "Question", "name": "What data does CiteReady use?", "acceptedAnswer": { "@type": "Answer", "text": "Our own live checks of your robots.txt, llms.txt, sitemap and homepage HTML, plus the free FreeSerp API for index presence and competitors." } }
        ]
      }
    ]
  }
  </script>

</head>
<body>
  <header class="site-header">
    <div class="container header-inner">
      <a class="logo" href="/" aria-label="CiteReady">Cite<span>Ready</span></a>
      <nav class="nav">
        <a href="/#features" data-i18n="nav.how">How it works</a>
        <a href="/methodology.php" data-i18n="nav.methodology">Methodology</a>
        <div class="lang-switch" role="group" aria-label="Language">
          <button type="button" data-lang="en" aria-pressed="true">EN</button>
          <button type="button" data-lang="uk" aria-pressed="false">UA</button>
        </div>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="hero-bg" aria-hidden="true">
        <span class="glow glow-1"></span>
        <span class="glow glow-2"></span>
        <canvas id="hero-canvas"></canvas>
      </div>

      <div class="container hero-content">
        <span class="badge reveal">
          <?= icon('sparkles') ?>
          <span class="dot"></span>
          <span data-i18n="badge.text">Powered by FreeSerp API &amp; LLM Scanner</span>
        </span>

        <h1 class="reveal" data-i18n="hero.title">Is your website ready to be cited by AI?</h1>
        <p class="subtitle reveal" data-i18n="hero.subtitle">Enter a domain and get an AI-readiness score from 0 to 100 with a concrete checklist of fixes.</p>

        <form class="check-form reveal" id="check-form" novalidate>
          <label class="visually-hidden" for="domain" data-i18n="hero.label">Website domain</label>
          <input id="domain" name="domain" type="text" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="example.com" data-i18n-placeholder="hero.placeholder">
          <button type="submit">
            <?= icon('search') ?>
            <span data-i18n="hero.button">Check</span>
          </button>
        </form>

        <div class="examples reveal">
          <span data-i18n="hero.try">Try:</span>
          <button type="button" class="tag" data-example="stripe.com">stripe.com</button>
          <button type="button" class="tag" data-example="github.com">github.com</button>
          <button type="button" class="tag" data-example="vercel.com">vercel.com</button>
        </div>
      </div>
    </section>

    <section id="results" class="section results-section" aria-live="polite"></section>

    <!-- Sample audit -->
    <section class="section" id="sample">
      <div class="container">
        <div class="section-head reveal">
          <h2 data-i18n="sample.heading">A sample report</h2>
          <p data-i18n="sample.sub">This is a demo. Run a real check above.</p>
        </div>

        <div class="sample-wrap reveal">
          <div class="card sample" id="sample-card" data-tilt>
            <div class="sample-top">
              <div class="ring" id="sample-ring" style="--p:84" role="img" aria-label="AI Visibility Index 84 of 100">
                <div style="text-align:center">
                  <b><span id="sample-score">84</span></b>
                  <small>/ 100</small>
                </div>
              </div>
              <div class="sample-intro">
                <span class="pill" data-i18n="sample.pill">Sample report</span>
                <h3 data-i18n="sample.index">AI Visibility Index</h3>
                <p data-i18n="sample.grade">Grade B — good, with a few gaps to close.</p>
              </div>
            </div>

            <div class="metrics">
              <div class="metric">
                <div class="val" data-count="71">71</div>
                <div class="lbl" data-i18n="sample.dr">FreeSerp DR</div>
              </div>
              <div class="metric">
                <div class="val" data-count="92" data-suffix="%">92%</div>
                <div class="lbl" data-i18n="sample.schema">Schema.org Indexing</div>
              </div>
              <div class="metric">
                <div class="val" data-count="84">84</div>
                <div class="lbl" data-i18n="sample.citation">LLM Citation Score</div>
              </div>
            </div>

            <ul class="checklist">
              <li class="ok"><?= icon('check') ?><span data-i18n="sample.c1">AI crawlers are allowed in robots.txt</span></li>
              <li class="ok"><?= icon('check') ?><span data-i18n="sample.c2">Open Graph tags are present</span></li>
              <li class="bad"><?= icon('x') ?><span data-i18n="sample.c3">llms.txt file is missing</span></li>
              <li class="ok"><?= icon('check') ?><span data-i18n="sample.c4">Valid JSON-LD structured data found</span></li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- Features -->
    <section class="section features" id="features">
      <div class="container">
        <div class="section-head reveal">
          <h2 data-i18n="features.heading">Why CiteReady</h2>
        </div>
        <div class="feature-grid">
          <div class="card feature reveal" data-tilt>
            <div class="icon"><?= icon('radar') ?></div>
            <h3 data-i18n="feat1.title">FreeSerp Integration</h3>
            <p data-i18n="feat1.desc">Instant domain and competitor analysis from a 20M+ site index.</p>
          </div>
          <div class="card feature reveal" data-tilt>
            <div class="icon"><?= icon('bot') ?></div>
            <h3 data-i18n="feat2.title">LLM Crawlability</h3>
            <p data-i18n="feat2.desc">Checks access for ChatGPT, Claude and Perplexity crawlers.</p>
          </div>
          <div class="card feature reveal" data-tilt>
            <div class="icon"><?= icon('route') ?></div>
            <h3 data-i18n="feat3.title">Actionable Roadmap</h3>
            <p data-i18n="feat3.desc">Ready-to-apply fixes for your code and page structure.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="section faq" id="faq">
      <div class="container">
        <div class="section-head reveal">
          <h2 data-i18n="faq.heading">Frequently asked questions</h2>
        </div>
        <div class="faq-list reveal">
          <details class="card faq-item"><summary data-i18n="faq.q1">What is GEO?</summary><p data-i18n="faq.a1"><?= d('faq.a1') ?></p></details>
          <details class="card faq-item"><summary data-i18n="faq.q2">What is llms.txt?</summary><p data-i18n="faq.a2"><?= d('faq.a2') ?></p></details>
          <details class="card faq-item"><summary data-i18n="faq.q3">Should I block AI crawlers?</summary><p data-i18n="faq.a3"><?= d('faq.a3') ?></p></details>
          <details class="card faq-item"><summary data-i18n="faq.q4">Does a high score guarantee citation?</summary><p data-i18n="faq.a4"><?= d('faq.a4') ?></p></details>
          <details class="card faq-item"><summary data-i18n="faq.q5">What data does CiteReady use?</summary><p data-i18n="faq.a5"><?= d('faq.a5') ?></p></details>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container footer-inner">
      <span data-i18n="footer.data">Data: our own checks + FreeSerp API</span>
      <span>
        <a href="/methodology.php" data-i18n="footer.methodology">Methodology</a>
        · © <?= htmlspecialchars($year, ENT_QUOTES) ?> CiteReady
      </span>
    </div>
  </footer>

  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/effects.js"></script>
  <script src="/assets/js/app.js"></script>
</body>
</html>
