<?php
declare(strict_types=1);

/**
 * Єдине джерело правди для перевірок: id, категорія, вага, i18n-ключ.
 * Тексти (title/why/fix) зберігаються у фронтендних i18n-файлах за ключами
 * checks.<id>.<status>.title / .fix — тут лише структура й ваги.
 *
 * Статуси: pass = 100% ваги, warn = 50%, fail = 0%, na = виключається з розрахунку.
 */

return [
    'categories' => [
        'A' => ['max' => 25],
        'B' => ['max' => 15],
        'C' => ['max' => 20],
        'D' => ['max' => 20],
        'E' => ['max' => 20],
    ],

    'checks' => [
        // A. Доступ AI-краулерів — 25
        ['id' => 'A1', 'category' => 'A', 'weight' => 5],
        ['id' => 'A2', 'category' => 'A', 'weight' => 15],
        ['id' => 'A3', 'category' => 'A', 'weight' => 5],

        // B. Файли для AI та пошуковиків — 15
        ['id' => 'B1', 'category' => 'B', 'weight' => 8],
        ['id' => 'B2', 'category' => 'B', 'weight' => 7],

        // C. Структуровані дані — 20
        ['id' => 'C1', 'category' => 'C', 'weight' => 8],
        ['id' => 'C2', 'category' => 'C', 'weight' => 7],
        ['id' => 'C3', 'category' => 'C', 'weight' => 5],

        // D. Контент і мета-теги — 20
        ['id' => 'D1', 'category' => 'D', 'weight' => 4],
        ['id' => 'D2', 'category' => 'D', 'weight' => 4],
        ['id' => 'D3', 'category' => 'D', 'weight' => 3],
        ['id' => 'D4', 'category' => 'D', 'weight' => 3],
        ['id' => 'D5', 'category' => 'D', 'weight' => 3],
        ['id' => 'D6', 'category' => 'D', 'weight' => 3],

        // E. Присутність в індексах (FreeSerp) — 20
        ['id' => 'E1', 'category' => 'E', 'weight' => 5],
        ['id' => 'E2', 'category' => 'E', 'weight' => 5],
        ['id' => 'E3', 'category' => 'E', 'weight' => 5],
        ['id' => 'E4', 'category' => 'E', 'weight' => 5],
    ],

    // AI-боти для перевірки A2: id у robots → власник, чи це пошуковий бот (цитує у відповідях).
    'ai_bots' => [
        ['bot' => 'GPTBot', 'owner' => 'OpenAI', 'search' => false],
        ['bot' => 'OAI-SearchBot', 'owner' => 'OpenAI', 'search' => true],
        ['bot' => 'ChatGPT-User', 'owner' => 'OpenAI', 'search' => true],
        ['bot' => 'ClaudeBot', 'owner' => 'Anthropic', 'search' => false],
        ['bot' => 'Claude-SearchBot', 'owner' => 'Anthropic', 'search' => true],
        ['bot' => 'PerplexityBot', 'owner' => 'Perplexity', 'search' => true],
        ['bot' => 'Google-Extended', 'owner' => 'Google', 'search' => false],
        ['bot' => 'Applebot-Extended', 'owner' => 'Apple', 'search' => false],
        ['bot' => 'CCBot', 'owner' => 'Common Crawl', 'search' => false],
    ],

    // Корисні для AI типи schema.org (перевірка C2).
    'useful_schema' => ['Organization', 'LocalBusiness', 'WebSite', 'WebApplication', 'Product', 'Article', 'FAQPage'],
];
