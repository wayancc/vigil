<?php

declare(strict_types=1);

namespace Vigil\Monitor;

use Vigil\Database;
use Vigil\Format;
use Vigil\Notify\Alert;
use Vigil\Notify\Notifier;
use Vigil\Settings;

/**
 * Runs the checks that are due and turns results into status changes, incidents and alerts.
 *
 *   ok   → status up, any open incident resolved, "back up" alert
 *   fail → counted; after `confirmations` failures in a row: status down, incident opened, "down" alert
 *
 * While a monitor is failing but not yet confirmed down it's re-checked sooner,
 * so a real outage is confirmed quickly and a single blip never pages anyone.
 */
final class Runner
{
    private const RETRY_DELAY = 20;          // seconds between confirmation re-checks
    private const SSL_CHECK_EVERY = 21600;   // 6 hours

    public function __construct(
        private readonly Database $db,
        private readonly Checker $checker,
        private readonly Notifier $notifier,
        private readonly Settings $settings,
        private readonly string $lockFile,
        private readonly string $dashboardUrl = '',
    ) {
    }

    /**
     * @return array{checked: int, up: int, down: int, skipped: bool}
     */
    public function runDue(?int $now = null, bool $all = false): array
    {
        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['checked' => 0, 'up' => 0, 'down' => 0, 'skipped' => true]; // another run is in progress
        }

        try {
            $now ??= time();
            $monitors = $this->db->all(
                'SELECT * FROM wyn_monitors WHERE paused = 0' . ($all ? '' : ' AND next_check_at <= ?'),
                $all ? [] : [$now],
            );

            $results = $this->checker->run($monitors, $now);
            $up = $down = 0;
            foreach ($monitors as $m) {
                $result = $results[$m['id']] ?? null;
                if ($result === null) {
                    // Heartbeat still waiting for its first ping: look again next interval.
                    $this->db->update('wyn_monitors', ['next_check_at' => $now + 30], 'id = :id', ['id' => $m['id']]);
                    continue;
                }
                $this->apply($m, $result, $now);
                $result->ok ? $up++ : $down++;
            }

            $this->checkCertificates($now);
            $this->prune($now);
            $this->settings->set('last_run_at', (string) $now);

            return ['checked' => count($results), 'up' => $up, 'down' => $down, 'skipped' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Run one monitor right now (the "Check now" button). */
    public function checkNow(array $m, ?int $now = null): ?CheckResult
    {
        $now ??= time();
        $result = $this->checker->run([$m], $now)[$m['id']] ?? null;
        if ($result !== null) {
            $this->apply($m, $result, $now);
        }

        return $result;
    }

    /** Record one result and handle any status change. */
    public function apply(array $m, CheckResult $r, int $now): void
    {
        $this->db->transaction(function (Database $db) use ($m, $r, $now): void {
            $db->insert('wyn_checks', [
                'monitor_id'  => $m['id'],
                'checked_at'  => $now,
                'ok'          => (int) $r->ok,
                'response_ms' => $r->responseMs,
                'status_code' => $r->statusCode,
                'error'       => $r->error,
            ]);

            $db->query(
                'INSERT INTO wyn_daily_stats (monitor_id, day, checks, ok, total_ms) VALUES (?, ?, 1, ?, ?)
                 ON CONFLICT(monitor_id, day) DO UPDATE SET checks = checks + 1, ok = ok + excluded.ok, total_ms = total_ms + excluded.total_ms',
                [$m['id'], date('Y-m-d', $now), (int) $r->ok, $r->ok ? (int) $r->responseMs : 0],
            );
        });

        $previous = $m['status'];
        $update = [
            'last_checked_at'  => $now,
            'last_response_ms' => $r->responseMs,
            'last_error'       => $r->error,
        ];

        if ($r->ok) {
            $update += ['status' => 'up', 'fail_count' => 0, 'next_check_at' => $now + (int) $m['interval_sec']];
            if ($previous !== 'up') {
                $update['status_since'] = $now;
            }
            $this->db->update('wyn_monitors', $update, 'id = :id', ['id' => $m['id']]);

            if ($previous === 'down') {
                $this->recovered($m, $now);
            }

            return;
        }

        $fails = (int) $m['fail_count'] + 1;
        $confirmed = $fails >= (int) $m['confirmations'];
        $update['fail_count'] = $fails;
        $update['next_check_at'] = $confirmed
            ? $now + (int) $m['interval_sec']
            : $now + min(self::RETRY_DELAY, (int) $m['interval_sec']);

        if ($confirmed && $previous !== 'down') {
            $update['status'] = 'down';
            $update['status_since'] = $now;
            $this->db->update('wyn_monitors', $update, 'id = :id', ['id' => $m['id']]);
            $this->wentDown(array_replace($m, $update), (string) $r->error, $now);

            return;
        }

        $this->db->update('wyn_monitors', $update, 'id = :id', ['id' => $m['id']]);
    }

    /** A heartbeat ping arrived from a cron job or script. */
    public function heartbeat(array $m, ?int $now = null): void
    {
        $now ??= time();
        $this->db->update('wyn_monitors', ['last_heartbeat_at' => $now], 'id = :id', ['id' => $m['id']]);

        if (!$m['paused'] && $m['status'] !== 'up') {
            $this->apply($m, CheckResult::up(), $now);
        }
    }

    private function wentDown(array $m, string $cause, int $now): void
    {
        $this->db->insert('wyn_incidents', ['monitor_id' => $m['id'], 'started_at' => $now, 'cause' => $cause]);

        $this->notifier->notify(new Alert(
            event: 'down',
            title: "{$m['name']} is down",
            message: self::target($m) . "\nReason: {$cause}\nSince: " . date('Y-m-d H:i T', $now),
            monitor: $m,
            link: $this->link($m),
            time: $now,
        ));
    }

    private function recovered(array $m, int $now): void
    {
        $incident = $this->db->one('SELECT * FROM wyn_incidents WHERE monitor_id = ? AND resolved_at IS NULL ORDER BY started_at DESC LIMIT 1', [$m['id']]);
        $this->db->query('UPDATE wyn_incidents SET resolved_at = ? WHERE monitor_id = ? AND resolved_at IS NULL', [$now, $m['id']]);
        $downFor = $incident ? Format::duration($now - (int) $incident['started_at']) : null;

        $this->notifier->notify(new Alert(
            event: 'up',
            title: "{$m['name']} is back up",
            message: self::target($m) . ($downFor ? "\nDown for {$downFor}" : ''),
            monitor: ['status' => 'up'] + $m,
            link: $this->link($m),
            time: $now,
            extra: $incident ? ['downtime_seconds' => $now - (int) $incident['started_at']] : [],
        ));
    }

    /** Refresh certificate expiry dates and warn once a day when one is close. */
    private function checkCertificates(int $now): void
    {
        $due = $this->db->all(
            "SELECT * FROM wyn_monitors WHERE paused = 0 AND type IN ('http', 'keyword') AND url LIKE 'https://%'
             AND (ssl_checked_at IS NULL OR ssl_checked_at < ?)",
            [$now - self::SSL_CHECK_EVERY],
        );

        foreach ($due as $m) {
            $expires = SslInspector::expiresAt($m['url'], min(10, (int) $m['timeout_sec']));
            $this->db->update('wyn_monitors', ['ssl_expires_at' => $expires, 'ssl_checked_at' => $now], 'id = :id', ['id' => $m['id']]);
            if ($expires === null || (int) $m['ssl_warn_days'] === 0) {
                continue;
            }

            $days = (int) floor(($expires - $now) / 86400);
            $alreadyWarned = $m['ssl_warned_at'] !== null && $now - (int) $m['ssl_warned_at'] < 86400;
            if ($days <= (int) $m['ssl_warn_days'] && !$alreadyWarned) {
                $this->db->update('wyn_monitors', ['ssl_warned_at' => $now], 'id = :id', ['id' => $m['id']]);
                $this->notifier->notify(new Alert(
                    event: 'ssl',
                    title: $days < 0
                        ? "SSL certificate for {$m['name']} has expired"
                        : "SSL certificate for {$m['name']} expires in {$days} " . ($days === 1 ? 'day' : 'days'),
                    message: $m['url'] . "\nExpires: " . date('Y-m-d H:i T', $expires),
                    monitor: $m,
                    link: $this->link($m),
                    time: $now,
                    extra: ['ssl_expires_at' => gmdate('c', $expires), 'days_left' => $days],
                ));
            }
        }
    }

    /** Drop raw checks past the retention window. Daily uptime numbers are kept. */
    private function prune(int $now): void
    {
        if ($now - (int) $this->settings->get('last_prune_at') < 86400) {
            return;
        }
        $days = max(1, (int) $this->settings->get('retention_days'));
        $this->db->query('DELETE FROM wyn_checks WHERE checked_at < ?', [$now - $days * 86400]);
        $this->db->query('DELETE FROM wyn_notification_log WHERE sent_at < ?', [$now - 90 * 86400]);
        $this->settings->set('last_prune_at', (string) $now);
    }

    private function link(array $m): ?string
    {
        return $this->dashboardUrl !== '' ? rtrim($this->dashboardUrl, '/') . '/admin/monitors/' . $m['id'] : null;
    }

    public static function target(array $m): string
    {
        return match ($m['type']) {
            'tcp'       => "{$m['host']}:{$m['port']}",
            'heartbeat' => 'Expected every ' . Format::duration((int) $m['interval_sec']),
            default     => (string) $m['url'],
        };
    }
}
