<?php

declare(strict_types=1);

namespace Vigil;

/**
 * Uptime and response-time numbers for the dashboard, status page, API and badges.
 */
final class Stats
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Uptime % over the last N hours, from raw checks. Null when there's no data. */
    public function uptimeHours(int $monitorId, int $hours, ?int $now = null): ?float
    {
        $since = ($now ?? time()) - $hours * 3600;
        $row = $this->db->one('SELECT COUNT(*) AS total, SUM(ok) AS ok FROM wyn_checks WHERE monitor_id = ? AND checked_at >= ?', [$monitorId, $since]);

        return (int) $row['total'] > 0 ? 100 * (int) $row['ok'] / (int) $row['total'] : null;
    }

    /** Uptime % over the last N days, from the daily rollup (survives pruning). */
    public function uptimeDays(int $monitorId, int $days, ?int $now = null): ?float
    {
        $since = date('Y-m-d', ($now ?? time()) - ($days - 1) * 86400);
        $row = $this->db->one('SELECT SUM(checks) AS total, SUM(ok) AS ok FROM wyn_daily_stats WHERE monitor_id = ? AND day >= ?', [$monitorId, $since]);

        return (int) $row['total'] > 0 ? 100 * (int) $row['ok'] / (int) $row['total'] : null;
    }

    public function avgResponse(int $monitorId, int $hours = 24, ?int $now = null): ?int
    {
        $since = ($now ?? time()) - $hours * 3600;
        $avg = $this->db->value('SELECT AVG(response_ms) FROM wyn_checks WHERE monitor_id = ? AND checked_at >= ? AND ok = 1 AND response_ms IS NOT NULL', [$monitorId, $since]);

        return $avg === null ? null : (int) round((float) $avg);
    }

    /**
     * One entry per day, oldest first, for the uptime bars.
     *
     * @return list<array{day: string, pct: ?float, checks: int, incidents: int, downtime: int}>
     */
    public function days(int $monitorId, int $days = 90, ?int $now = null): array
    {
        $now ??= time();
        $first = date('Y-m-d', $now - ($days - 1) * 86400);
        $rows = array_column(
            $this->db->all('SELECT day, checks, ok FROM wyn_daily_stats WHERE monitor_id = ? AND day >= ?', [$monitorId, $first]),
            null,
            'day',
        );

        $incidents = $this->db->all(
            'SELECT started_at, COALESCE(resolved_at, ?) AS ended_at FROM wyn_incidents WHERE monitor_id = ? AND COALESCE(resolved_at, ?) >= ?',
            [$now, $monitorId, $now, strtotime($first)],
        );

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', $now - $i * 86400);
            $start = (int) strtotime($day);
            $end = $start + 86400;
            $count = 0;
            $downtime = 0;
            foreach ($incidents as $inc) {
                $overlap = min($end, (int) $inc['ended_at']) - max($start, (int) $inc['started_at']);
                if ($overlap > 0) {
                    $count++;
                    $downtime += $overlap;
                }
            }
            $row = $rows[$day] ?? null;
            $out[] = [
                'day'       => $day,
                'pct'       => $row && (int) $row['checks'] > 0 ? 100 * (int) $row['ok'] / (int) $row['checks'] : null,
                'checks'    => (int) ($row['checks'] ?? 0),
                'incidents' => $count,
                'downtime'  => $downtime,
            ];
        }

        return $out;
    }

    /** @return list<array{checked_at: int, ok: int, response_ms: ?int}> */
    public function recentChecks(int $monitorId, int $limit = 50): array
    {
        return array_reverse($this->db->all(
            'SELECT checked_at, ok, response_ms, status_code, error FROM wyn_checks WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT ?',
            [$monitorId, $limit],
        ));
    }

    /** @return list<array{checked_at: int, ok: int, response_ms: ?int}> */
    public function checksSince(int $monitorId, int $since): array
    {
        return $this->db->all('SELECT checked_at, ok, response_ms FROM wyn_checks WHERE monitor_id = ? AND checked_at >= ? ORDER BY checked_at', [$monitorId, $since]);
    }

    /** @return list<array> incidents with the monitor name, newest first */
    public function incidents(?int $monitorId = null, int $limit = 50, bool $publicOnly = false): array
    {
        $where = [];
        $params = [];
        if ($monitorId !== null) {
            $where[] = 'i.monitor_id = ?';
            $params[] = $monitorId;
        }
        if ($publicOnly) {
            $where[] = 'm.is_public = 1';
        }
        $params[] = $limit;

        return $this->db->all(
            'SELECT i.*, m.name AS monitor_name, m.slug AS monitor_slug FROM wyn_incidents i JOIN wyn_monitors m ON m.id = i.monitor_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY i.started_at DESC LIMIT ?',
            $params,
        );
    }

    /**
     * Overall state for a set of monitors.
     *
     * @return array{state: string, label: string}
     */
    public static function overall(array $monitors, bool $maintenance = false): array
    {
        $active = array_filter($monitors, fn ($m) => !$m['paused']);
        $down = count(array_filter($active, fn ($m) => $m['status'] === 'down'));

        return match (true) {
            $active === []                  => ['state' => 'unknown', 'label' => 'No services are being monitored yet'],
            $down > 0 && $down === count($active) => ['state' => 'down', 'label' => 'Major outage'],
            $down > 0                       => ['state' => 'partial', 'label' => $down === 1 ? 'One service is down' : "{$down} services are down"],
            $maintenance                    => ['state' => 'maintenance', 'label' => 'Scheduled maintenance in progress'],
            default                         => ['state' => 'up', 'label' => 'All systems operational'],
        };
    }
}
