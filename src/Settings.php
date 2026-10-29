<?php

declare(strict_types=1);

namespace Vigil;

/**
 * Key/value settings edited from the dashboard.
 */
final class Settings
{
    public const DEFAULTS = [
        'site_name'        => 'Status',
        'theme'            => 'wayan',   // wayan (bold, based on wayan.cc) | neutral
        'site_description' => '',
        'site_url'         => '',      // "Back to website" link on the status page
        'retention_days'   => '30',    // raw check history; daily uptime is kept forever
        'smtp_host'        => '',
        'smtp_port'        => '587',
        'smtp_encryption'  => 'tls',   // tls | ssl | none
        'smtp_user'        => '',
        'smtp_pass'        => '',
        'smtp_from'        => '',
        'cron_token'       => '',
        'last_prune_at'    => '0',
        'last_run_at'      => '0',
    ];

    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key): string
    {
        $this->cache ??= array_column($this->db->all('SELECT key, value FROM wyn_settings'), 'value', 'key');

        return (string) ($this->cache[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    public function set(string $key, string $value): void
    {
        $this->db->query('INSERT INTO wyn_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    /** The secret in the web-cron URL. Created on first use. */
    public function cronToken(): string
    {
        $token = $this->get('cron_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $this->set('cron_token', $token);
        }

        return $token;
    }
}
