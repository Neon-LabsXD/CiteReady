<?php
declare(strict_types=1);

require_once __DIR__ . '/FetchException.php';

/**
 * Безпечні HTTP-запити до довільних доменів (захист від SSRF).
 *
 * - hostname перевіряється регуляркою (IP-адреси, localhost, порти, логіни відхиляються);
 * - DNS резолвиться вручну, усі адреси мають бути публічними;
 * - перевірений IP підставляється через CURLOPT_RESOLVE (без DNS-rebinding);
 * - лише http/https, порти 80/443, редиректи вручну (макс. 3) з повторною перевіркою;
 * - таймаут і ліміт розміру відповіді.
 */
final class Fetcher
{
    private const HOST_REGEX = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';
    private const MAX_REDIRECTS = 3;
    private const TOTAL_DEADLINE = 20.0;
    private const ALLOWED_PORTS = [80, 443];

    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public function __construct(
        private readonly int $maxBytes = 2_097_152,
        private readonly int $timeout = 8,
        private readonly string $userAgent = 'CiteReadyBot/1.0 (+https://ws-109.ws.semalt.dev)'
    ) {
    }

    /**
     * Приводить ввід користувача до домену: example.com, https://www.example.com/a → example.com.
     * Повертає null, якщо ввід не є валідним публічним hostname.
     */
    public static function normalizeDomain(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 2048 || preg_match('/\s/', $input)) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $input)) {
            $parts = parse_url($input);
            if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
                return null;
            }
            if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && !in_array($parts['port'], self::ALLOWED_PORTS, true))) {
                return null;
            }
            $host = $parts['host'];
        } else {
            $host = preg_split('#[/?\#]#', $input, 2)[0];
            if (str_contains($host, '@') || str_contains($host, ':')) {
                return null;
            }
        }

        $host = strtolower(rtrim($host, '.'));
        if (preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return preg_match(self::HOST_REGEX, $host) === 1 ? $host : null;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (str_contains($ip, ':')) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return false;
            }
            // IPv4-mapped (::ffff:a.b.c.d): перевіряємо вбудовану IPv4-адресу.
            if (self::inCidr($ip, '::ffff:0:0/96')) {
                $v4 = inet_ntop(substr($packed, 12, 4));
                return $v4 !== false && self::isPublicIp($v4);
            }
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }
        } else {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr($ip, $cidr)) {
                    return false;
                }
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * GET-запит із ручною обробкою редиректів.
     *
     * @return array{ok: bool, status: int, headers: array<string,string>, body: string,
     *               final_url: string, redirects: int, truncated: bool, error: ?string, detail: string}
     */
    public function get(string $url): array
    {
        $deadline = microtime(true) + self::TOTAL_DEADLINE;
        $current = $url;
        $redirects = 0;

        try {
            while (true) {
                $target = $this->prepare($current);
                $response = $this->request($target);

                $location = $response['headers']['location'] ?? '';
                if (in_array($response['status'], [301, 302, 303, 307, 308], true) && $location !== '') {
                    if ($redirects >= self::MAX_REDIRECTS) {
                        throw new FetchException('too_many_redirects');
                    }
                    if (microtime(true) > $deadline) {
                        throw new FetchException('unreachable', 'deadline');
                    }
                    $current = $this->resolveLocation($target, $location);
                    $redirects++;
                    continue;
                }

                return [
                    'ok' => true,
                    'status' => $response['status'],
                    'headers' => $response['headers'],
                    'body' => $response['body'],
                    'final_url' => $current,
                    'redirects' => $redirects,
                    'truncated' => $response['truncated'],
                    'error' => null,
                    'detail' => '',
                ];
            }
        } catch (FetchException $e) {
            return [
                'ok' => false,
                'status' => 0,
                'headers' => [],
                'body' => '',
                'final_url' => $current,
                'redirects' => $redirects,
                'truncated' => false,
                'error' => $e->reason,
                'detail' => $e->detail,
            ];
        }
    }

    /**
     * Перевіряє URL (схема, hostname, порт, DNS) і готує дані для запиту.
     *
     * @return array{scheme: string, host: string, port: int, explicit_port: bool, path: string, ips: list<string>}
     */
    private function prepare(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new FetchException('blocked', 'bad_url');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchException('blocked', 'scheme_or_credentials');
        }

        $host = strtolower($parts['host']);
        if (preg_match(self::HOST_REGEX, $host) !== 1) {
            throw new FetchException('blocked', 'host');
        }

        $explicit = isset($parts['port']);
        $port = $explicit ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new FetchException('blocked', 'port');
        }

        $path = ($parts['path'] ?? '/') === '' ? '/' : ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'explicit_port' => $explicit,
            'path' => $path,
            'ips' => $this->resolvePublic($host),
        ];
    }

    /**
     * @return list<string> до 3 публічних адрес (IPv4 першими). Якщо хоч одна адреса приватна — blocked.
     */
    private function resolvePublic(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }
        if ($ips === []) {
            throw new FetchException('unreachable', 'dns');
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new FetchException('blocked', 'private_ip');
            }
        }

        $ips = array_values(array_unique($ips));
        usort($ips, static fn (string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));

        return array_slice($ips, 0, 3);
    }

    /**
     * @param array{scheme: string, host: string, port: int, explicit_port: bool, path: string, ips: list<string>} $target
     * @return array{status: int, headers: array<string,string>, body: string, truncated: bool}
     */
    private function request(array $target): array
    {
        $url = $target['scheme'] . '://' . $target['host']
            . ($target['explicit_port'] ? ':' . $target['port'] : '') . $target['path'];

        $lastDetail = '';
        foreach ($target['ips'] as $ip) {
            $body = '';
            $truncated = false;
            $headers = [];
            $status = 0;
            $max = $this->maxBytes;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_ENCODING => '',
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml,text/plain;q=0.9,*/*;q=0.5'],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$status): int {
                    $len = strlen($line);
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int) $m[1];
                        $headers = [];
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }
                    return $len;
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated, $max): int {
                    $room = $max - strlen($body);
                    if (strlen($chunk) > $room) {
                        $body .= substr($chunk, 0, max(0, $room));
                        $truncated = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
            } else {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);

            if ($status > 0 && ($errno === 0 || $truncated)) {
                return ['status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => $truncated];
            }
            $lastDetail = $error !== '' ? $error : 'curl_errno_' . $errno;
        }

        throw new FetchException('unreachable', $lastDetail);
    }

    /** Перетворює заголовок Location (абсолютний чи відносний) на абсолютний URL. */
    private function resolveLocation(array $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location)) {
            return $location;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . ($base['explicit_port'] ? ':' . $base['port'] : '');

        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $dir = preg_replace('#[^/]*$#', '', explode('?', $base['path'], 2)[0]) ?? '/';
        return $origin . $dir . $location;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $a = inet_pton($ip);
        $b = inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;

        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
