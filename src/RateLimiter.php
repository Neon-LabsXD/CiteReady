<?php
declare(strict_types=1);

/**
 * Файловий rate limit за IP: не більше $max запитів у вікні $window секунд.
 * Зберігає часові мітки у storage; старі (поза вікном) відсікаються при кожній перевірці.
 * Ковзне вікно; доступ під LOCK_EX, щоб уникнути гонок.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $dir,
        private readonly int $max = 10,
        private readonly int $window = 600
    ) {
    }

    /**
     * Реєструє спробу. Повертає true, якщо дозволено (ліміт не перевищено).
     */
    public function allow(string $ip): bool
    {
        $file = rtrim($this->dir, '/') . '/rl_' . hash('sha256', $ip) . '.json';
        $now = time();

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return true; // не ламаємо сервіс, якщо сховище недоступне
        }

        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $hits = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($hits)) {
                $hits = [];
            }

            $cutoff = $now - $this->window;
            $hits = array_values(array_filter($hits, static fn ($t): bool => is_int($t) && $t > $cutoff));

            if (count($hits) >= $this->max) {
                return false;
            }

            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Поточний клієнтський IP (без довіри до проксі-заголовків). */
    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
