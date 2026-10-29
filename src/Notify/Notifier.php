<?php

declare(strict_types=1);

namespace Vigil\Notify;

use Vigil\Database;

/**
 * Sends an alert to every enabled channel and logs the outcome.
 * One failing channel never stops the others.
 */
final class Notifier
{
    /** @var list<Alert> alerts sent during this run (used by tests and the CLI summary) */
    public array $sent = [];

    public function __construct(private readonly Database $db, private readonly Channels $channels)
    {
    }

    /** @return list<array{channel: string, ok: bool, error: ?string}> */
    public function notify(Alert $alert, ?int $onlyChannelId = null): array
    {
        $rows = $onlyChannelId !== null
            ? $this->db->all('SELECT * FROM wyn_channels WHERE id = ?', [$onlyChannelId])
            : $this->db->all('SELECT * FROM wyn_channels WHERE enabled = 1');

        $this->sent[] = $alert;
        $report = [];
        foreach ($rows as $row) {
            $error = null;
            try {
                $this->channels->send($row['type'], json_decode($row['config'], true) ?: [], $alert);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $this->db->insert('wyn_notification_log', [
                'channel_id' => $row['id'],
                'event'      => $alert->event,
                'ok'         => (int) ($error === null),
                'error'      => $error,
                'sent_at'    => time(),
            ]);
            $report[] = ['channel' => $row['name'], 'ok' => $error === null, 'error' => $error];
        }

        return $report;
    }
}
