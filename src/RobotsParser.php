<?php
declare(strict_types=1);

/**
 * Розбір robots.txt і перевірка доступу конкретних ботів.
 * Групи User-agent, директиви Allow/Disallow, рядок Sitemap.
 */
final class RobotsParser
{
    /** @var array<string, array<int, array{type: string, path: string}>> агент(lowercase) → правила */
    private array $groups = [];

    /** @var list<string> */
    private array $sitemaps = [];

    public function __construct(string $content)
    {
        $this->parse($content);
    }

    private function parse(string $content): void
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        /** @var list<string> $currentAgents */
        $currentAgents = [];
        $expectingAgent = false; // група розпочата; підряд ідучі User-agent додаються до неї

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $value = trim($value);

            switch ($field) {
                case 'user-agent':
                    if (!$expectingAgent) {
                        $currentAgents = [];
                    }
                    $agent = strtolower($value);
                    if ($agent !== '') {
                        $currentAgents[] = $agent;
                        $this->groups[$agent] ??= [];
                    }
                    $expectingAgent = true;
                    break;

                case 'allow':
                case 'disallow':
                    $expectingAgent = false;
                    foreach ($currentAgents as $agent) {
                        $this->groups[$agent][] = ['type' => $field, 'path' => $value];
                    }
                    break;

                case 'sitemap':
                    if ($value !== '') {
                        $this->sitemaps[] = $value;
                    }
                    break;

                default:
                    $expectingAgent = false;
            }
        }
    }

    /** @return list<string> */
    public function sitemaps(): array
    {
        return array_values(array_unique($this->sitemaps));
    }

    public function hasAnyGroup(): bool
    {
        return $this->groups !== [];
    }

    /** Чи є окрема (не *) група для цього бота. */
    public function hasGroupFor(string $bot): bool
    {
        return isset($this->groups[strtolower($bot)]);
    }

    /**
     * Чи дозволено боту шлях $path. Застосовується власна група бота,
     * інакше — група '*'. Перемагає найдовше правило (стандарт Google);
     * за рівної довжини Allow має перевагу над Disallow.
     */
    public function isAllowed(string $bot, string $path = '/'): bool
    {
        $bot = strtolower($bot);
        $rules = $this->groups[$bot] ?? $this->groups['*'] ?? null;
        if ($rules === null || $rules === []) {
            return true; // немає правил — дозволено
        }

        $best = null; // ['len' => int, 'type' => string]
        foreach ($rules as $rule) {
            if (!$this->matches($rule['path'], $path)) {
                continue;
            }
            $len = strlen($rule['path']);
            if ($best === null
                || $len > $best['len']
                || ($len === $best['len'] && $rule['type'] === 'allow')) {
                $best = ['len' => $len, 'type' => $rule['type']];
            }
        }

        if ($best === null) {
            return true;
        }
        return $best['type'] === 'allow';
    }

    /** Зіставлення шаблону robots (* і $) зі шляхом. Порожній Disallow нічого не блокує. */
    private function matches(string $pattern, string $path): bool
    {
        if ($pattern === '') {
            return false; // Disallow: (порожньо) = дозволити все → правило не застосовується
        }

        $anchored = str_ends_with($pattern, '$');
        $pattern = rtrim($pattern, '$');

        $regex = '';
        foreach (preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $regex .= $ch === '*' ? '.*' : preg_quote($ch, '#');
        }
        $regex = '#^' . $regex . ($anchored ? '$' : '') . '#';

        return preg_match($regex, $path) === 1;
    }
}
