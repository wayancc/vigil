<?php

declare(strict_types=1);

namespace Vigil\Controllers;

use Vigil\App;
use Vigil\Charts;
use Vigil\Format;
use Vigil\Http\Request;
use Vigil\Http\Response;
use Vigil\Monitor\CheckResult;
use Vigil\NotFound;
use Vigil\Stats;

/**
 * Everything that works without signing in: the status page, JSON API, badges, heartbeats and web cron.
 */
final class PublicController
{
    public function __construct(private readonly App $app)
    {
    }

    public function statusPage(Request $request): Response
    {
        $monitors = $this->app->monitors->public();
        $notices = $this->activeNotices();
        $maintenance = self::inMaintenance($notices);

        $groups = [];
        foreach ($monitors as $m) {
            $m['days'] = $this->app->stats->days((int) $m['id'], 90);
            $m['uptime90'] = $this->app->stats->uptimeDays((int) $m['id'], 90);
            $groups[$m['group_name']][] = $m;
        }

        $html = $this->app->view()->render('status/page', [
            'overall'   => Stats::overall($monitors, $maintenance),
            'groups'    => $groups,
            'notices'   => $notices,
            'past'      => $this->pastNotices(),
            'incidents' => $this->app->stats->incidents(limit: 15, publicOnly: true),
            'lastRun'   => (int) $this->app->settings->get('last_run_at'),
        ]);

        return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=30']);
    }

    public function api(Request $request): Response
    {
        $monitors = $this->app->monitors->public();
        $notices = $this->activeNotices();
        $maintenance = self::inMaintenance($notices);
        $overall = Stats::overall($monitors, $maintenance);

        return Response::json([
            'name'      => $this->app->settings->get('site_name'),
            'status'    => $overall['state'],
            'summary'   => $overall['label'],
            'updated_at' => gmdate('c', (int) $this->app->settings->get('last_run_at') ?: time()),
            'services'  => array_map(fn ($m) => [
                'name'          => $m['name'],
                'slug'          => $m['slug'],
                'group'         => $m['group_name'] ?: null,
                'status'        => $m['paused'] ? 'paused' : $m['status'],
                'since'         => $m['status_since'] ? gmdate('c', (int) $m['status_since']) : null,
                'response_ms'   => $m['last_response_ms'] !== null ? (int) $m['last_response_ms'] : null,
                'uptime_24h'    => self::round($this->app->stats->uptimeHours((int) $m['id'], 24)),
                'uptime_30d'    => self::round($this->app->stats->uptimeDays((int) $m['id'], 30)),
                'uptime_90d'    => self::round($this->app->stats->uptimeDays((int) $m['id'], 90)),
            ], $monitors),
            'notices'   => array_map(fn ($n) => [
                'title'     => $n['title'],
                'body'      => $n['body'],
                'kind'      => $n['kind'],
                'starts_at' => gmdate('c', (int) $n['starts_at']),
                'ends_at'   => $n['ends_at'] ? gmdate('c', (int) $n['ends_at']) : null,
            ], $notices),
        ]);
    }

    /** /badge/{slug}/status.svg, /badge/{slug}/uptime.svg, /badge/{slug}/response.svg */
    public function badge(Request $request, string $slug, string $kind): Response
    {
        $m = $this->app->monitors->findBySlug($slug);
        if ($m === null || !$m['is_public']) {
            throw new NotFound();
        }

        $label = (string) ($request->query['label'] ?? '');
        [$label, $value, $color] = match ($kind) {
            'status.svg' => [$label ?: $m['name'], ...match (true) {
                (bool) $m['paused']    => ['paused', '#9f9f9f'],
                $m['status'] === 'up'   => ['up', '#2ea043'],
                $m['status'] === 'down' => ['down', '#d73a49'],
                default                 => ['pending', '#9f9f9f'],
            }],
            'uptime.svg' => (function () use ($m, $label, $request) {
                $days = in_array((int) ($request->query['days'] ?? 30), [1, 7, 30, 90], true) ? (int) ($request->query['days'] ?? 30) : 30;
                $pct = $days === 1 ? $this->app->stats->uptimeHours((int) $m['id'], 24) : $this->app->stats->uptimeDays((int) $m['id'], $days);
                $color = $pct === null ? '#9f9f9f' : ($pct >= 99.9 ? '#2ea043' : ($pct >= 99 ? '#7fae1b' : ($pct >= 95 ? '#dfb317' : '#d73a49')));

                return [$label ?: "uptime {$days}d", Format::percent($pct), $color];
            })(),
            'response.svg' => [$label ?: 'response', Format::ms($this->app->stats->avgResponse((int) $m['id'])), '#0e75b6'],
            default => throw new NotFound(),
        };

        return Response::svg(Charts::badge($label, $value, $color));
    }

    /** Cron jobs and scripts call this URL after each successful run. */
    public function heartbeat(Request $request, string $token): Response
    {
        $m = $this->app->monitors->findByToken($token);
        if ($m === null || $m['type'] !== 'heartbeat') {
            return Response::text("Unknown heartbeat URL\n", 404);
        }
        $this->app->runner->heartbeat($m);

        return Response::text("OK\n");
    }

    /** Scripts can report their own failure: /hb/{token}/fail */
    public function heartbeatFail(Request $request, string $token): Response
    {
        $m = $this->app->monitors->findByToken($token);
        if ($m === null || $m['type'] !== 'heartbeat') {
            return Response::text("Unknown heartbeat URL\n", 404);
        }
        $reason = mb_substr($request->input('msg') ?: 'Job reported a failure', 0, 300);
        if (!$m['paused']) {
            $this->app->runner->apply($m, CheckResult::down($reason), time());
        }

        return Response::text("Failure recorded\n");
    }

    /** For hosts without cron: point an external scheduler (cron-job.org, UptimeRobot…) at this URL every minute. */
    public function cron(Request $request, string $token): Response
    {
        if (!hash_equals($this->app->settings->cronToken(), $token)) {
            return Response::text("Invalid token\n", 403);
        }
        ignore_user_abort(true);
        set_time_limit(120);
        $r = $this->app->runner->runDue();

        return Response::text($r['skipped']
            ? "Another check run is in progress\n"
            : "Checked {$r['checked']}: {$r['up']} up, {$r['down']} down\n");
    }

    public function notFound(): Response
    {
        return Response::html($this->app->view()->render('status/not-found'), 404);
    }

    private function activeNotices(): array
    {
        $now = time();

        return $this->app->db->all(
            'SELECT * FROM wyn_notices WHERE starts_at <= ? AND (ends_at IS NULL OR ends_at > ?) ORDER BY starts_at DESC',
            [$now + 7 * 86400, $now],  // include maintenance scheduled within the next week
        );
    }

    private function pastNotices(): array
    {
        $now = time();

        return $this->app->db->all(
            'SELECT * FROM wyn_notices WHERE ends_at IS NOT NULL AND ends_at <= ? AND ends_at > ? ORDER BY ends_at DESC LIMIT 10',
            [$now, $now - 30 * 86400],
        );
    }

    private static function inMaintenance(array $notices): bool
    {
        $now = time();

        return (bool) array_filter($notices, fn ($n) => $n['kind'] === 'maintenance' && (int) $n['starts_at'] <= $now);
    }

    private static function round(?float $pct): ?float
    {
        return $pct === null ? null : floor($pct * 1000) / 1000;
    }
}
