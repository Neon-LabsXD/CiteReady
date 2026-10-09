<?php
declare(strict_types=1);

/**
 * Аналіз HTML головної сторінки через DOMDocument:
 * title, meta description, h1, lang, canonical, JSON-LD (типи), Open Graph,
 * обсяг видимого тексту, формат «питання-відповідь».
 */
final class HtmlAnalyzer
{
    private DOMDocument $dom;
    private DOMXPath $xp;

    public function __construct(string $html)
    {
        $this->dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // meta із charset, щоб кирилиця й інші не ламались; HTML може бути частковим.
        $prefix = '<?xml encoding="UTF-8">';
        $this->dom->loadHTML($prefix . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $this->xp = new DOMXPath($this->dom);
    }

    /** @return array<string, mixed> зведення сигналів для перевірок C–D */
    public function analyze(): array
    {
        return [
            'title' => $this->title(),
            'meta_description' => $this->metaDescription(),
            'h1_count' => $this->h1Count(),
            'lang' => $this->htmlLang(),
            'canonical' => $this->canonical(),
            'word_count' => $this->wordCount(),
            'jsonld_types' => $this->jsonLdTypes(),
            'jsonld_valid' => $this->jsonLdValidCount() > 0,
            'open_graph' => $this->openGraph(),
            'qa_format' => $this->hasQaFormat(),
        ];
    }

    private function firstNodeValue(string $query): ?string
    {
        $node = $this->xp->query($query)->item(0);
        if ($node === null) {
            return null;
        }
        $value = trim($node->nodeValue ?? '');
        return $value === '' ? null : $value;
    }

    private function title(): ?string
    {
        return $this->firstNodeValue('//head/title');
    }

    private function metaDescription(): ?string
    {
        $node = $this->xp->query('//meta[translate(@name,"DESCRIPTION","description")="description"][1]/@content')->item(0);
        $v = $node !== null ? trim($node->nodeValue ?? '') : '';
        return $v === '' ? null : $v;
    }

    private function h1Count(): int
    {
        return $this->xp->query('//h1')->length;
    }

    private function htmlLang(): ?string
    {
        $node = $this->xp->query('/html/@lang | //html/@lang')->item(0);
        $v = $node !== null ? trim($node->nodeValue ?? '') : '';
        return $v === '' ? null : $v;
    }

    private function canonical(): ?string
    {
        $node = $this->xp->query('//link[translate(@rel,"CANONICAL","canonical")="canonical"][1]/@href')->item(0);
        $v = $node !== null ? trim($node->nodeValue ?? '') : '';
        return $v === '' ? null : $v;
    }

    private function wordCount(): int
    {
        $clone = $this->dom->cloneNode(true);
        $xp = new DOMXPath($clone instanceof DOMDocument ? $clone : $this->dom);
        foreach ($xp->query('//script | //style | //noscript | //template | //svg') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $xp->query('//body')->item(0);
        $text = $body !== null ? ($body->textContent ?? '') : '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return 0;
        }
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return count($words);
    }

    /** @return list<string> унікальні типи з усіх валідних JSON-LD (з урахуванням @graph) */
    private function jsonLdTypes(): array
    {
        $types = [];
        foreach ($this->jsonLdBlocks() as $data) {
            $this->collectTypes($data, $types);
        }
        return array_values(array_unique($types));
    }

    private function jsonLdValidCount(): int
    {
        return count($this->jsonLdBlocks());
    }

    /** @return list<mixed> декодовані валідні JSON-LD блоки */
    private function jsonLdBlocks(): array
    {
        $blocks = [];
        foreach ($this->xp->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]') as $node) {
            $raw = trim($node->textContent ?? '');
            if ($raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
                $blocks[] = $data;
            }
        }
        return $blocks;
    }

    /** @param list<string> $out */
    private function collectTypes(mixed $data, array &$out): void
    {
        if (is_array($data)) {
            if (array_is_list($data)) {
                foreach ($data as $item) {
                    $this->collectTypes($item, $out);
                }
                return;
            }
            if (isset($data['@graph'])) {
                $this->collectTypes($data['@graph'], $out);
            }
            if (isset($data['@type'])) {
                foreach ((array) $data['@type'] as $type) {
                    if (is_string($type)) {
                        $out[] = $type;
                    }
                }
            }
            foreach ($data as $key => $value) {
                if ($key !== '@type' && $key !== '@graph' && is_array($value)) {
                    $this->collectTypes($value, $out);
                }
            }
        }
    }

    /** @return array<string, string> og:title, og:description, og:image (якщо є) */
    private function openGraph(): array
    {
        $og = [];
        foreach ($this->xp->query('//meta[starts-with(translate(@property,"OG","og"),"og:")]') as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $prop = strtolower($node->getAttribute('property'));
            $content = trim($node->getAttribute('content'));
            if ($content !== '' && !isset($og[$prop])) {
                $og[$prop] = $content;
            }
        }
        return $og;
    }

    private function hasQaFormat(): bool
    {
        foreach ($this->xp->query('//h2 | //h3 | //summary') as $node) {
            if (str_ends_with(trim($node->textContent ?? ''), '?')) {
                return true;
            }
        }
        return in_array('FAQPage', $this->jsonLdTypes(), true)
            || $this->xp->query('//details')->length > 0;
    }
}
