<?php

declare(strict_types=1);

namespace Vigil\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,    // relative to the base URL, always starts with "/"
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $ip = '0.0.0.0',
        public readonly bool $secure = false,
    ) {
    }

    public static function fromGlobals(string $base, bool $trustProxy): self
    {
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        if (str_starts_with($path, rtrim($base, '/'))) {
            $path = substr($path, strlen(rtrim($base, '/')));
        }
        $path = '/' . trim($path, '/');

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($trustProxy) {
            $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $ip = trim(explode(',', $forwarded)[0]);
            }
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($trustProxy && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            post: $_POST,
            ip: $ip,
            secure: $secure,
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    public function checkbox(string $key): int
    {
        return isset($this->post[$key]) && $this->post[$key] !== '0' ? 1 : 0;
    }
}
