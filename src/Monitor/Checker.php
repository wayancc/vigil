<?php

declare(strict_types=1);

namespace Vigil\Monitor;

use Vigil\Format;

/**
 * Runs checks. HTTP and keyword checks run in parallel with curl_multi,
 * so 50 monitors take about as long as the slowest one.
 */
final class Checker
{
    private const MAX_BODY = 2_000_000; // bytes kept for keyword matching

    public function __construct(private readonly string $userAgent)
    {
    }

    /**
     * @param list<array> $monitors
     * @return array<int, CheckResult> keyed by monitor id; heartbeat monitors still in their first window are omitted
     */
    public function run(array $monitors, ?int $now = null): array
    {
        $now ??= time();
        $results = [];
        $http = [];

        foreach ($monitors as $m) {
            match ($m['type']) {
                'http', 'keyword' => $http[] = $m,
                'tcp'             => $results[$m['id']] = $this->tcp($m),
                'heartbeat'       => ($r = $this->heartbeat($m, $now)) !== null ? $results[$m['id']] = $r : null,
                default           => null,
            };
        }

        return $results + $this->httpBatch($http);
    }

    /** @return array<int, CheckResult> */
    private function httpBatch(array $monitors): array
    {
        if ($monitors === []) {
            return [];
        }

        $multi = curl_multi_init();
        $handles = [];
        $bodies = [];

        foreach ($monitors as $m) {
            $id = (int) $m['id'];
            $bodies[$id] = '';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $m['url'],
                CURLOPT_CUSTOMREQUEST  => $m['method'] === 'HEAD' ? null : $m['method'],
                CURLOPT_NOBODY         => $m['method'] === 'HEAD',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => min(10, (int) $m['timeout_sec']),
                CURLOPT_TIMEOUT        => (int) $m['timeout_sec'],
                CURLOPT_SSL_VERIFYPEER => (bool) $m['verify_ssl'],
                CURLOPT_SSL_VERIFYHOST => $m['verify_ssl'] ? 2 : 0,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_ENCODING       => '',            // accept gzip/br
                CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache', 'Accept: */*'],
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$bodies, $id): int {
                    if (strlen($bodies[$id]) < self::MAX_BODY) {
                        $bodies[$id] .= $chunk;
                    }

                    return strlen($chunk);
                },
            ]);
            if ($m['method'] === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            }
            curl_multi_add_handle($multi, $ch);
            $handles[$id] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Collect per-handle result codes (curl_errno isn't reliable for multi handles).
        $codes = [];
        while ($info = curl_multi_info_read($multi)) {
            $codes[spl_object_id($info['handle'])] = $info['result'];
        }

        $results = [];
        $byId = array_column($monitors, null, 'id');
        foreach ($handles as $id => $ch) {
            $m = $byId[$id];
            $code = $codes[spl_object_id($ch)] ?? curl_errno($ch);
            $ms = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($code !== CURLE_OK) {
                $results[$id] = CheckResult::down(self::curlMessage($code, curl_error($ch) ?: curl_strerror($code), $m), $ms ?: null);
            } elseif (!self::statusMatches($httpCode, $m['expected_status'])) {
                $results[$id] = CheckResult::down("HTTP {$httpCode} (expected {$m['expected_status']})", $ms, $httpCode);
            } elseif ($m['type'] === 'keyword') {
                $found = mb_stripos($bodies[$id], (string) $m['keyword']) !== false;
                if ($found === (bool) $m['keyword_absent']) {
                    $results[$id] = CheckResult::down($m['keyword_absent']
                        ? "Found “{$m['keyword']}”, which should be absent"
                        : "Couldn’t find “{$m['keyword']}” on the page", $ms, $httpCode);
                } else {
                    $results[$id] = CheckResult::up($ms, $httpCode);
                }
            } else {
                $results[$id] = CheckResult::up($ms, $httpCode);
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $results;
    }

    private function tcp(array $m): CheckResult
    {
        $host = trim((string) $m['host'], '[]');
        $target = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;

        $start = hrtime(true);
        $socket = @stream_socket_client("tcp://{$target}:{$m['port']}", $errno, $errstr, (float) $m['timeout_sec']);
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        if ($socket === false) {
            $reason = $errstr !== '' ? $errstr : 'connection failed';

            return CheckResult::down("Port {$m['port']} unreachable: {$reason}", $ms);
        }
        fclose($socket);

        return CheckResult::up($ms);
    }

    private function heartbeat(array $m, int $now): ?CheckResult
    {
        $last = $m['last_heartbeat_at'] !== null ? (int) $m['last_heartbeat_at'] : null;
        $deadline = ($last ?? (int) $m['created_at']) + (int) $m['interval_sec'] + (int) $m['timeout_sec'];

        if ($now <= $deadline) {
            // Still within the expected window. Before the first ping there's nothing to report yet.
            return $last === null ? null : CheckResult::up();
        }

        return CheckResult::down($last === null
            ? 'No heartbeat received yet'
            : 'No heartbeat for ' . Format::duration($now - $last));
    }

    public static function statusMatches(int $code, string $expected): bool
    {
        foreach (explode(',', $expected) as $part) {
            [$from, $to] = array_pad(explode('-', $part, 2), 2, null);
            if ($code >= (int) $from && $code <= (int) ($to ?? $from)) {
                return true;
            }
        }

        return false;
    }

    private static function curlMessage(int $code, string $message, array $m): string
    {
        // libcurl error codes: 28 timeout, 6 DNS, 7 connect, 47 redirects, 35/51/58/60/77 TLS
        return match (true) {
            $code === 28 => "Timed out after {$m['timeout_sec']}s",
            $code === 6  => 'DNS lookup failed for ' . parse_url((string) $m['url'], PHP_URL_HOST),
            $code === 7  => 'Connection refused',
            $code === 47 => 'Too many redirects',
            in_array($code, [35, 51, 58, 60, 77], true) => 'SSL error: ' . $message,
            default      => $message,
        };
    }
}
