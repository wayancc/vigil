<?php

declare(strict_types=1);

namespace Vigil\Notify;

final class HttpPost
{
    public static function json(string $url, array $payload): string
    {
        return self::send($url, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
    }

    /** POST and return the response body. Throws on network errors and non-2xx responses. */
    public static function send(string $url, string $body, array $headers = [], int $timeout = 10): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'Vigil/' . (defined('VIGIL_VERSION') ? VIGIL_VERSION : '0'),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $detail = trim(mb_substr(strip_tags((string) $response), 0, 200));
            throw new \RuntimeException("HTTP {$status}" . ($detail !== '' ? ": {$detail}" : ''));
        }

        return (string) $response;
    }
}
