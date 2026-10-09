# CiteReady — AI Visibility Checker

**CiteReady** — безкоштовний інструмент, що оцінює, наскільки сайт готовий до того, щоб AI-асистенти та AI-пошук (ChatGPT, Claude, Perplexity, Google AI Overviews) його знаходили, розуміли й цитували. Користувач вводить домен → за 10–20 секунд отримує оцінку 0–100, розбивку за 5 категоріями та конкретний чекліст покращень.

**Жива версія:** https://ws-109.ws.semalt.dev
**Методологія:** https://ws-109.ws.semalt.dev/methodology.php

Детальний план — у [PLAN.md](PLAN.md), лог роботи з AI — у [WORKLOG.md](WORKLOG.md).

---

## Що воно робить

Оцінка (100 балів) складається з 5 категорій:

| Категорія | Балів | Перевірки |
|---|---|---|
| A. Доступ AI-краулерів | 25 | robots.txt доступний, AI-боти не заблоковані, HTTPS 200 |
| B. Файли для AI та пошуку | 15 | llms.txt, sitemap.xml |
| C. Структуровані дані | 20 | JSON-LD, корисні типи schema, Open Graph |
| D. Контент і мета-теги | 20 | title, description, один H1, lang+canonical, обсяг тексту, формат Q&A |
| E. Присутність в індексах | 20 | є в індексі, AI-опис, Domain Rating, сторінки в web-індексі (FreeSerp) |

Кожна перевірка має статус `pass` / `warn` / `fail` / `na`. Статус `na` (немає даних) виключається з розрахунку, а бали категорії перераховуються пропорційно з решти — тож відсутність сторонніх даних ніколи не знижує оцінку. Грейди: A 85–100, B 70–84, C 50–69, D 30–49, F <30.

## Джерела даних

1. **Власний PHP-бекенд.** Сервер сам завантажує й аналізує `robots.txt`, `llms.txt`, `sitemap.xml` і HTML головної сторінки (браузер не може через CORS).
2. **[FreeSerp API](https://freeserp.ai)** (без ключа) — присутність домену в індексі, AI-згенерований опис, Domain Rating і конкуренти в ніші. Усі запити йдуть із PHP (кеш + одна точка обробки помилок).

## Архітектура

Чистий **PHP 8.4 + HTML + CSS + vanilla JS**, без фреймворків і збірки. Причина: швидко, прозоро, легко перевірити. База даних не використовується.

```
public/                         ← деплоїться в /var/www/html (webroot)
├── index.php                   лендінг + чекер + вивід результатів
├── methodology.php             сторінка методології (двомовна)
├── api/check.php               GET ?domain=… → JSON-звіт
├── assets/css/                 style.css (лендінг), results.css (звіт)
├── assets/js/                  app.js (логіка звіту), effects.js (canvas/анімації), i18n.js
├── assets/img/                 favicon.svg, og.svg, og.png
├── i18n/                       en.json, uk.json (усі тексти)
├── robots.txt, llms.txt, sitemap.xml
src/                            ← деплоїться в /var/www/citeready/src (поза webroot)
├── Fetcher.php                 безпечні HTTP-запити (SSRF-захист)
├── FetchException.php
├── RobotsParser.php            розбір robots.txt, перевірка доступу ботів
├── HtmlAnalyzer.php            DOMDocument: title, meta, h1, JSON-LD, OG, текст
├── FreeSerpClient.php          клієнт FreeSerp (профіль, web-індекс, конкуренти)
├── Checks.php                  виконання перевірок A–E → статуси
├── Scorer.php                  бали за config, перерозподіл na, грейди
├── Cache.php                   файловий кеш результатів (1 год)
└── RateLimiter.php             ліміт за IP (10/10 хв)
config/checks.php               ← /var/www/citeready/config — ваги й список перевірок (дані, не код)
storage/                        ← /var/www/citeready/storage — кеш і rate-limit (поза webroot)
tests/                          CLI-тести (php tests/<файл>.php)
deploy.sh                       синхронізація public/ → webroot, src+config → поза webroot
```

**Чому src/ поза webroot:** `/home/ws` має права 700, тож php-fpm (`www-data`) не читав би код звідти; плюс код і кеш не мають лежати в публічній папці. `config/checks.php` — єдине джерело правди для ваг і переліку перевірок.

### API

`GET /api/check.php?domain=example.com` → JSON:

```json
{
  "ok": true, "domain": "example.com", "checked_at": "…Z",
  "score": 72, "grade": "B",
  "categories": [{"id":"A","score":20,"max":25,"all_na":false}],
  "checks": [{"id":"A2","category":"A","status":"warn","points":7.5,"max":15,"data":{}}],
  "ai_bots": [{"bot":"GPTBot","owner":"OpenAI","allowed":true}],
  "profile": {"ai_summary":"…","dr":34,"ai_categories":["…"],"ai_source":"wordpress"},
  "competitors": [{"domain":"…","dr":50,"summary":"…"}],
  "cached": false
}
```

API повертає лише `id` + `status` перевірок; тексти (назви, «чому важливо», «як виправити») фронтенд бере з i18n за ключами `checks.<id>.name/why/fix` — так двомовність працює без дублювання логіки. Помилки: `{"ok":false,"error":"invalid_domain|unreachable|rate_limited|internal"}` з відповідним HTTP-кодом.

## Безпека

Сервер завантажує довільні URL, тож захист від **SSRF** критичний (`src/Fetcher.php`):

- валідація hostname регуляркою; нормалізація `https://www.example.com/page` → `example.com`;
- ручний DNS-резолв і **відхилення приватних/службових IP** (IPv4 і IPv6, у т.ч. IPv4-mapped), через чорний список діапазонів + `filter_var(... NO_PRIV_RANGE|NO_RES_RANGE)`;
- підстановка перевіреного IP через `CURLOPT_RESOLVE` (захист від DNS-rebinding);
- лише http/https і порти 80/443; редиректи обробляються вручну (макс. 3), кожна нова адреса перевіряється заново;
- таймаут 8 с, ліміт розміру відповіді 2 МБ;
- rate limit ~10 перевірок/IP/10 хв, кеш результатів 1 год;
- увесь вивід екранується (`htmlspecialchars` у PHP, `textContent` у JS для даних з API/FreeSerp).

## Фронтенд

- Двомовність EN/UA з перемикачем; мова з `localStorage` → `navigator.language`. Серверний HTML містить повний англійський текст (важливо для AI-краулерів), JS підміняє на UA.
- Адаптив від 360px, темна преміум-тема, SVG-діаграма оцінки, canvas-мережа частинок у hero, анімації появи — усе з урахуванням `prefers-reduced-motion`.
- Шеринг результату через `/?d=domain` (автозапуск), друк/PDF через `window.print()`.
- CiteReady проходить власну перевірку: robots.txt, llms.txt, sitemap.xml, JSON-LD (WebApplication + FAQPage), OG, один H1, lang, canonical.

## Деплой

```bash
./deploy.sh
```

Скрипт перевіряє весь PHP (`php -l`), потім через `rsync` розкладає `public/` → `/var/www/html`, а `src/` і `config/` → `/var/www/citeready/` (поза webroot). `storage/` не чіпається. Одноразово перед першим деплоєм створено теку поза webroot:

```bash
sudo mkdir -p /var/www/citeready/{src,config,storage}
sudo chown -R ws:www-data /var/www/citeready
sudo chmod 2770 /var/www/citeready/storage
```

Конфіг nginx не змінювався (root `/var/www/html`, PHP через php-fpm).

## Тести

```bash
php tests/ssrf_test.php            # нормалізація домену, приватні IP, редиректи, реальні запити
php tests/checks_test.php          # RobotsParser, HtmlAnalyzer, Scorer (перерозподіл na)
php tests/freeserp_test.php        # FreeSerpClient, сценарії Scorer для категорії E
php tests/cache_ratelimit_test.php # Cache і RateLimiter
```

## Стек

PHP 8.4 (curl, dom, libxml, mbstring, intl, SimpleXML), nginx + php-fpm. Жодних Composer-залежностей.
