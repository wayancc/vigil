<?php

declare(strict_types=1);

namespace Vigil\Monitor;

/**
 * Reads a site's TLS certificate expiry date, even when the certificate is invalid
 * (so an expired certificate still reports a date instead of just failing).
 */
final class SslInspector
{
    public static function expiresAt(string $url, int $timeout = 10): ?int
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert ? openssl_x509_parse($cert) : false;

        return is_array($info) && isset($info['validTo_time_t']) ? (int) $info['validTo_time_t'] : null;
    }
}
