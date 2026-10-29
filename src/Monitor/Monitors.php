<?php

declare(strict_types=1);

namespace Vigil\Monitor;

use Vigil\Database;
use Vigil\Format;

/**
 * Monitor storage and validation.
 */
final class Monitors
{
    public const TYPES = [
        'http'      => 'HTTP(S)',
        'keyword'   => 'Keyword',
        'tcp'       => 'TCP port',
        'heartbeat' => 'Heartbeat (cron job)',
    ];

    public const INTERVALS = [30 => '30 seconds', 60 => '1 minute', 120 => '2 minutes', 300 => '5 minutes', 600 => '10 minutes', 1800 => '30 minutes', 3600 => '1 hour', 86400 => '1 day'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM wyn_monitors ORDER BY group_name, sort, name COLLATE NOCASE');
    }

    /** @return list<array> */
    public function public(): array
    {
        return $this->db->all('SELECT * FROM wyn_monitors WHERE is_public = 1 ORDER BY group_name, sort, name COLLATE NOCASE');
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM wyn_monitors WHERE id = ?', [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM wyn_monitors WHERE slug = ?', [$slug]);
    }

    public function findByToken(string $token): ?array
    {
        return $this->db->one('SELECT * FROM wyn_monitors WHERE heartbeat_token = ?', [$token]);
    }

    public function defaults(): array
    {
        return [
            'id' => null, 'name' => '', 'type' => 'http', 'url' => 'https://', 'method' => 'GET',
            'expected_status' => '200-299', 'keyword' => '', 'keyword_absent' => 0, 'host' => '', 'port' => '',
            'interval_sec' => 60, 'timeout_sec' => 10, 'confirmations' => 2, 'verify_ssl' => 1,
            'ssl_warn_days' => 14, 'is_public' => 1, 'group_name' => '', 'sort' => 0, 'paused' => 0,
        ];
    }

    /**
     * Validate and store a monitor.
     *
     * @return array{0: ?int, 1: array<string, string>, 2: array} [id, errors, normalized input]
     */
    public function save(array $in, ?int $id = null): array
    {
        $existing = $id ? $this->find($id) : null;
        $type = array_key_exists($in['type'] ?? '', self::TYPES) ? $in['type'] : 'http';

        $data = [
            'name'            => mb_substr(trim((string) ($in['name'] ?? '')), 0, 100),
            'type'            => $type,
            'url'             => trim((string) ($in['url'] ?? '')),
            'method'          => in_array($method = strtoupper((string) ($in['method'] ?? 'GET')), ['GET', 'HEAD', 'POST'], true) ? $method : 'GET',
            'expected_status' => preg_replace('/\s+/', '', (string) ($in['expected_status'] ?? '200-299')) ?: '200-299',
            'keyword'         => trim((string) ($in['keyword'] ?? '')),
            'keyword_absent'  => (int) !empty($in['keyword_absent']),
            'host'            => trim((string) ($in['host'] ?? '')),
            'port'            => (int) ($in['port'] ?? 0),
            'interval_sec'    => (int) ($in['interval_sec'] ?? 60),
            'timeout_sec'     => (int) ($in['timeout_sec'] ?? 10),
            'confirmations'   => (int) ($in['confirmations'] ?? 2),
            'verify_ssl'      => (int) !empty($in['verify_ssl']),
            'ssl_warn_days'   => (int) ($in['ssl_warn_days'] ?? 14),
            'is_public'       => (int) !empty($in['is_public']),
            'group_name'      => mb_substr(trim((string) ($in['group_name'] ?? '')), 0, 60),
            'sort'            => (int) ($in['sort'] ?? 0),
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Enter a name, for example “Website” or “API”.';
        }

        if (in_array($type, ['http', 'keyword'], true)) {
            $scheme = strtolower((string) parse_url($data['url'], PHP_URL_SCHEME));
            if (!filter_var($data['url'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                $errors['url'] = 'Enter a full URL starting with http:// or https://';
            }
            if (!preg_match('/^\d{3}(-\d{3})?(,\d{3}(-\d{3})?)*$/', $data['expected_status'])) {
                $errors['expected_status'] = 'Use status codes and ranges, for example 200-299 or 200,301';
            }
        }
        if ($type === 'keyword' && $data['keyword'] === '') {
            $errors['keyword'] = 'Enter the text to look for on the page.';
        }
        if ($type === 'tcp') {
            if ($data['host'] === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $data['host'])) {
                $errors['host'] = 'Enter a hostname or IP address, for example db.example.com';
            }
            if ($data['port'] < 1 || $data['port'] > 65535) {
                $errors['port'] = 'Enter a port between 1 and 65535.';
            }
        }

        $data['interval_sec'] = max(30, min(86400, $data['interval_sec']));
        $data['timeout_sec'] = max(1, min(60, $data['timeout_sec']));
        $data['confirmations'] = $type === 'heartbeat' ? 1 : max(1, min(10, $data['confirmations']));
        $data['ssl_warn_days'] = max(0, min(90, $data['ssl_warn_days']));

        if ($type === 'heartbeat') {
            // For heartbeats the timeout field is the grace period, in minutes on the form.
            $data['timeout_sec'] = max(0, min(86400, (int) ($in['grace_min'] ?? 5) * 60));
            $data['url'] = null;
        }
        if ($type !== 'tcp') {
            $data['host'] = null;
            $data['port'] = null;
        }
        if ($type === 'tcp') {
            $data['url'] = null;
        }

        if ($errors !== []) {
            return [null, $errors, $data + ['id' => $id, 'grace_min' => $in['grace_min'] ?? 5]];
        }

        $now = time();
        if ($existing === null) {
            $data['slug'] = $this->uniqueSlug($data['name']);
            $data['created_at'] = $now;
            $data['status'] = 'pending';
            $data['next_check_at'] = 0;
            $data['heartbeat_token'] = $type === 'heartbeat' ? bin2hex(random_bytes(12)) : null;

            return [$this->db->insert('wyn_monitors', $data), [], $data];
        }

        // A new target means old results no longer apply.
        $targetChanged = $existing['type'] !== $type || $existing['url'] !== $data['url']
            || $existing['host'] !== $data['host'] || (int) $existing['port'] !== (int) $data['port'];
        if ($targetChanged) {
            $data += ['status' => 'pending', 'fail_count' => 0, 'next_check_at' => 0, 'ssl_expires_at' => null, 'ssl_checked_at' => null, 'last_error' => null];
        }
        if ($type === 'heartbeat' && empty($existing['heartbeat_token'])) {
            $data['heartbeat_token'] = bin2hex(random_bytes(12));
        }
        if ($existing['name'] !== $data['name']) {
            $data['slug'] = $this->uniqueSlug($data['name'], $id);
        }

        $this->db->update('wyn_monitors', $data, 'id = :id', ['id' => $id]);

        return [$id, [], $data];
    }

    public function setPaused(int $id, bool $paused): void
    {
        $this->db->update('wyn_monitors', [
            'paused'        => (int) $paused,
            'status'        => 'pending',
            'fail_count'    => 0,
            'next_check_at' => 0,
        ], 'id = :id', ['id' => $id]);

        if ($paused) {
            // A paused monitor shouldn't keep an incident open forever.
            $this->db->query('UPDATE wyn_incidents SET resolved_at = ? WHERE monitor_id = ? AND resolved_at IS NULL', [time(), $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM wyn_monitors WHERE id = ?', [$id]);
    }

    public function checkSoon(int $id): void
    {
        $this->db->update('wyn_monitors', ['next_check_at' => 0], 'id = :id', ['id' => $id]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Format::slug($name);
        $slug = $base;
        $n = 2;
        while ($this->db->value('SELECT id FROM wyn_monitors WHERE slug = ? AND id IS NOT ?', [$slug, $ignoreId]) !== null) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
