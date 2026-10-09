<?php
declare(strict_types=1);

/**
 * Простий файловий кеш JSON-результатів за ключем (доменом).
 * TTL за замовчуванням — 1 година. Атомарний запис через tmp + rename.
 */
final class Cache
{
    public function __construct(
        private readonly string $dir,
        private readonly int $ttl = 3600
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        if (time() - filemtime($file) > $this->ttl) {
            @unlink($file);
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value): void
    {
        $file = $this->path($key);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->dir, '/') . '/cache_' . hash('sha256', strtolower($key)) . '.json';
    }
}
