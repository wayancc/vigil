<?php

declare(strict_types=1);

namespace Vigil\Controllers;

use Vigil\App;
use Vigil\Format;
use Vigil\Http\Request;
use Vigil\Http\Response;
use Vigil\Monitor\Monitors;
use Vigil\Notify\Alert;
use Vigil\Notify\Channels;
use Vigil\NotFound;
use Vigil\Settings;
use Vigil\Urls;

final class AdminController
{
    public function __construct(private readonly App $app)
    {
    }

    // ---------------------------------------------------------------- Auth

    public function setup(Request $r): Response
    {
        $errors = [];
        if ($r->method === 'POST' && !$this->app->session->validCsrf($r->post['_token'] ?? null)) {
            $errors['email'] = 'Your session expired. Try again.';
        } elseif ($r->method === 'POST') {
            $email = $r->input('email');
            $password = (string) ($r->post['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if (strlen($password) < 10) {
                $errors['password'] = 'Use at least 10 characters.';
            }
            if ($errors === []) {
                $id = $this->app->auth->createUser($email, $password);
                $this->app->settings->set('site_name', $r->input('site_name') ?: 'Status');
                $this->app->auth->login($id);
                $this->app->session->flash('You’re all set. Add your first monitor.');

                return Response::redirect($this->app->url('admin/monitors/new'));
            }
        }

        return $this->page('admin/setup', ['errors' => $errors, 'old' => $r->post], 'Set up Vigil', bare: true);
    }

    public function login(Request $r): Response
    {
        $error = null;
        if ($r->method === 'POST') {
            if (!$this->app->session->validCsrf($r->post['_token'] ?? null)) {
                $error = 'Your session expired. Try again.';
            } elseif ($this->app->auth->isThrottled($r->ip)) {
                $error = 'Too many attempts. Wait 15 minutes and try again.';
            } elseif ($this->app->auth->attempt($r->input('email'), (string) ($r->post['password'] ?? ''), $r->ip)) {
                return Response::redirect($this->app->url('admin'));
            } else {
                $error = 'That email and password don’t match.';
            }
        }

        return $this->page('admin/login', ['error' => $error, 'email' => $r->input('email')], 'Sign in', bare: true);
    }

    public function logout(Request $r): Response
    {
        $this->app->auth->logout();

        return Response::redirect($this->app->url('admin/login'));
    }

    // ---------------------------------------------------------------- Dashboard

    public function dashboard(Request $r): Response
    {
        $stats = $this->app->stats;
        $monitors = array_map(function (array $m) use ($stats) {
            $id = (int) $m['id'];

            return $m + [
                'uptime24' => $stats->uptimeHours($id, 24),
                'uptime30' => $stats->uptimeDays($id, 30),
                'avg24'    => $stats->avgResponse($id),
                'recent'   => $stats->recentChecks($id, 40),
            ];
        }, $this->app->monitors->all());

        $counts = ['up' => 0, 'down' => 0, 'paused' => 0, 'pending' => 0];
        foreach ($monitors as $m) {
            $counts[$m['paused'] ? 'paused' : $m['status']]++;
        }

        return $this->page('admin/dashboard', [
            'monitors' => $monitors,
            'counts'   => $counts,
            'open'     => $this->app->db->all('SELECT i.*, m.name AS monitor_name FROM wyn_incidents i JOIN wyn_monitors m ON m.id = i.monitor_id WHERE i.resolved_at IS NULL ORDER BY i.started_at'),
            'cron'     => $this->cronInfo(),
            'channels' => (int) $this->app->db->value('SELECT COUNT(*) FROM wyn_channels WHERE enabled = 1'),
        ], 'Monitors', 'dashboard');
    }

    // ---------------------------------------------------------------- Monitors

    public function monitorForm(Request $r, ?string $id = null): Response
    {
        $m = $id !== null ? $this->monitor($id) : $this->app->monitors->defaults();
        if ($m['type'] === 'heartbeat') {
            $m['grace_min'] = intdiv((int) $m['timeout_sec'], 60);
        }

        return $this->page('admin/monitor-form', [
            'm'      => $m,
            'errors' => [],
            'groups' => $this->groups(),
        ], $id ? "Edit {$m['name']}" : 'New monitor', 'dashboard');
    }

    public function monitorSave(Request $r, ?string $id = null): Response
    {
        $existing = $id !== null ? $this->monitor($id) : null;
        [$savedId, $errors, $data] = $this->app->monitors->save($r->post, $existing ? (int) $existing['id'] : null);

        if ($errors !== []) {
            return $this->page('admin/monitor-form', [
                'm'      => $data + ['id' => $existing['id'] ?? null, 'heartbeat_token' => $existing['heartbeat_token'] ?? null],
                'errors' => $errors,
                'groups' => $this->groups(),
            ], $existing ? "Edit {$existing['name']}" : 'New monitor', 'dashboard', 422);
        }

        $m = $this->app->monitors->find($savedId);
        if ($existing === null && $m['type'] !== 'heartbeat') {
            // Give immediate feedback instead of waiting for the next cron run.
            $result = $this->app->runner->checkNow($m);
            $this->app->session->flash($result?->ok
                ? "{$m['name']} is up (" . Format::ms($result->responseMs) . ').'
                : "{$m['name']} was added, but the first check failed: {$result?->error}", $result?->ok ? 'ok' : 'warn');
        } else {
            $this->app->session->flash($existing ? 'Changes saved.' : "{$m['name']} was added. Copy its heartbeat URL into your job.");
        }

        return Response::redirect($this->app->url('admin/monitors/' . $savedId));
    }

    public function monitorShow(Request $r, string $id): Response
    {
        $m = $this->monitor($id);
        $range = in_array($r->input('range'), ['24h', '7d', '30d'], true) ? $r->input('range') : '24h';
        $seconds = ['24h' => 86400, '7d' => 604800, '30d' => 2592000][$range];
        $now = time();
        $stats = $this->app->stats;
        $mid = (int) $m['id'];

        return $this->page('admin/monitor', [
            'm'         => $m,
            'range'     => $range,
            'from'      => $now - $seconds,
            'to'        => $now,
            'series'    => $stats->checksSince($mid, $now - $seconds),
            'uptime'    => ['24h' => $stats->uptimeHours($mid, 24), '7d' => $stats->uptimeDays($mid, 7), '30d' => $stats->uptimeDays($mid, 30), '90d' => $stats->uptimeDays($mid, 90)],
            'avg'       => $stats->avgResponse($mid, (int) ($seconds / 3600)),
            'days'      => $stats->days($mid, 90),
            'incidents' => $stats->incidents($mid, 20),
            'recent'    => array_reverse($stats->recentChecks($mid, 15)),
            'hbUrl'     => $m['heartbeat_token'] ? Urls::absolute($this->app->config, 'hb/' . $m['heartbeat_token']) : null,
            'badgeBase' => Urls::absolute($this->app->config, 'badge/' . $m['slug']),
        ], $m['name'], 'dashboard');
    }

    public function monitorAction(Request $r, string $id, string $action): Response
    {
        $m = $this->monitor($id);
        $mid = (int) $m['id'];
        $back = Response::redirect($this->app->url('admin/monitors/' . $mid));

        switch ($action) {
            case 'pause':
                $this->app->monitors->setPaused($mid, true);
                $this->app->session->flash("{$m['name']} is paused. No checks or alerts until you resume it.");

                return $back;
            case 'resume':
                $this->app->monitors->setPaused($mid, false);
                $this->app->session->flash("{$m['name']} is active again.");

                return $back;
            case 'check':
                $result = $this->app->runner->checkNow($m);
                $this->app->session->flash(match (true) {
                    $result === null => 'No heartbeat has arrived yet, so there’s nothing to check.',
                    $result->ok      => 'Up · ' . Format::ms($result->responseMs),
                    default          => 'Check failed: ' . $result->error,
                }, $result?->ok ? 'ok' : 'warn');

                return $back;
            case 'delete':
                $this->app->monitors->delete($mid);
                $this->app->session->flash("{$m['name']} and its history were deleted.");

                return Response::redirect($this->app->url('admin'));
        }

        throw new NotFound();
    }

    public function incidents(Request $r): Response
    {
        return $this->page('admin/incidents', ['incidents' => $this->app->stats->incidents(limit: 200)], 'Incidents', 'incidents');
    }

    // ---------------------------------------------------------------- Alert channels

    public function channels(Request $r): Response
    {
        $rows = $this->app->db->all(
            'SELECT c.*, (SELECT ok FROM wyn_notification_log l WHERE l.channel_id = c.id ORDER BY l.id DESC LIMIT 1) AS last_ok,
                    (SELECT error FROM wyn_notification_log l WHERE l.channel_id = c.id ORDER BY l.id DESC LIMIT 1) AS last_error,
                    (SELECT sent_at FROM wyn_notification_log l WHERE l.channel_id = c.id ORDER BY l.id DESC LIMIT 1) AS last_sent
             FROM wyn_channels c ORDER BY c.name',
        );

        return $this->page('admin/channels', ['channels' => $rows, 'smtp' => $this->app->settings->get('smtp_host') !== ''], 'Alerts', 'channels');
    }

    public function channelForm(Request $r, ?string $id = null): Response
    {
        $channel = $id !== null ? $this->channel($id) : [
            'id' => null, 'name' => '', 'enabled' => 1, 'config' => [],
            'type' => array_key_exists($r->input('type'), Channels::TYPES) ? $r->input('type') : 'email',
        ];

        return $this->page('admin/channel-form', ['c' => $channel, 'errors' => []], $id ? 'Edit alert channel' : 'New alert channel', 'channels');
    }

    public function channelSave(Request $r, ?string $id = null): Response
    {
        $existing = $id !== null ? $this->channel($id) : null;
        $type = $existing['type'] ?? $r->input('type');
        if (!array_key_exists($type, Channels::TYPES)) {
            throw new NotFound();
        }

        $config = [];
        foreach (Channels::TYPES[$type][1] as $field => $_) {
            $config[$field] = trim((string) ($r->post['config'][$field] ?? ''));
        }
        $name = $r->input('name') ?: Channels::TYPES[$type][0];
        $errors = Channels::validate($type, $config);

        if ($errors !== []) {
            return $this->page('admin/channel-form', [
                'c'      => ['id' => $existing['id'] ?? null, 'type' => $type, 'name' => $name, 'enabled' => $r->checkbox('enabled'), 'config' => $config],
                'errors' => $errors,
            ], 'Alert channel', 'channels', 422);
        }

        $data = ['type' => $type, 'name' => mb_substr($name, 0, 80), 'config' => json_encode($config), 'enabled' => $r->checkbox('enabled')];
        if ($existing) {
            $this->app->db->update('wyn_channels', $data, 'id = :id', ['id' => $existing['id']]);
            $this->app->session->flash('Channel saved.');
        } else {
            $this->app->db->insert('wyn_channels', $data + ['created_at' => time()]);
            $this->app->session->flash("{$data['name']} added. Send a test alert to make sure it arrives.");
        }

        return Response::redirect($this->app->url('admin/channels'));
    }

    public function channelAction(Request $r, string $id, string $action): Response
    {
        $c = $this->channel($id);
        if ($action === 'delete') {
            $this->app->db->query('DELETE FROM wyn_channels WHERE id = ?', [$c['id']]);
            $this->app->session->flash("{$c['name']} was deleted.");
        } elseif ($action === 'test') {
            $report = $this->app->notifier->notify(new Alert(
                event: 'test',
                title: 'Test alert from ' . $this->app->settings->get('site_name'),
                message: 'If you can read this, Vigil can reach this channel.',
                link: Urls::absolute($this->app->config, 'admin'),
                time: time(),
            ), (int) $c['id']);
            $ok = $report[0]['ok'] ?? false;
            $this->app->session->flash($ok ? "Test alert sent to {$c['name']}." : "Couldn’t send to {$c['name']}: " . ($report[0]['error'] ?? 'unknown error'), $ok ? 'ok' : 'error');
        } else {
            throw new NotFound();
        }

        return Response::redirect($this->app->url('admin/channels'));
    }

    // ---------------------------------------------------------------- Notices (status page announcements)

    public function notices(Request $r): Response
    {
        return $this->page('admin/notices', [
            'notices' => $this->app->db->all('SELECT * FROM wyn_notices ORDER BY starts_at DESC LIMIT 100'),
            'errors'  => [],
            'old'     => [],
        ], 'Announcements', 'notices');
    }

    public function noticeSave(Request $r): Response
    {
        $title = mb_substr($r->input('title'), 0, 140);
        $kind = in_array($r->input('kind'), ['info', 'maintenance', 'incident'], true) ? $r->input('kind') : 'info';
        $starts = $r->input('starts_at') !== '' ? strtotime($r->input('starts_at')) : time();
        $ends = $r->input('ends_at') !== '' ? strtotime($r->input('ends_at')) : null;

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Enter a title.';
        }
        if ($starts === false || $ends === false) {
            $errors['ends_at'] = 'Enter valid dates.';
        } elseif ($ends !== null && $ends <= $starts) {
            $errors['ends_at'] = 'The end must be after the start.';
        }

        if ($errors !== []) {
            return $this->page('admin/notices', [
                'notices' => $this->app->db->all('SELECT * FROM wyn_notices ORDER BY starts_at DESC LIMIT 100'),
                'errors'  => $errors,
                'old'     => $r->post,
            ], 'Announcements', 'notices', 422);
        }

        $this->app->db->insert('wyn_notices', [
            'title' => $title, 'body' => mb_substr($r->input('body'), 0, 2000), 'kind' => $kind,
            'starts_at' => $starts, 'ends_at' => $ends, 'created_at' => time(),
        ]);
        $this->app->session->flash('Announcement published on the status page.');

        return Response::redirect($this->app->url('admin/notices'));
    }

    public function noticeAction(Request $r, string $id, string $action): Response
    {
        $n = $this->app->db->one('SELECT * FROM wyn_notices WHERE id = ?', [(int) $id]) ?? throw new NotFound();
        match ($action) {
            'end'    => $this->app->db->update('wyn_notices', ['ends_at' => time()], 'id = :id', ['id' => $n['id']]),
            'delete' => $this->app->db->query('DELETE FROM wyn_notices WHERE id = ?', [$n['id']]),
            default  => throw new NotFound(),
        };
        $this->app->session->flash($action === 'end' ? 'Announcement ended.' : 'Announcement deleted.');

        return Response::redirect($this->app->url('admin/notices'));
    }

    // ---------------------------------------------------------------- Settings

    public function settings(Request $r): Response
    {
        $values = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $values[$key] = $this->app->settings->get($key);
        }

        return $this->page('admin/settings', ['s' => $values, 'errors' => [], 'cron' => $this->cronInfo()], 'Settings', 'settings');
    }

    public function settingsSave(Request $r): Response
    {
        $fields = ['site_name', 'site_description', 'site_url', 'retention_days', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_from'];
        foreach ($fields as $key) {
            $this->app->settings->set($key, mb_substr($r->input($key), 0, 500));
        }
        // Keep the stored password unless a new one is typed.
        if (($pass = (string) ($r->post['smtp_pass'] ?? '')) !== '') {
            $this->app->settings->set('smtp_pass', $pass);
        }
        if ($r->checkbox('regenerate_cron')) {
            $this->app->settings->set('cron_token', bin2hex(random_bytes(16)));
        }
        $this->app->settings->set('theme', $r->input('theme') === 'neutral' ? 'neutral' : 'wayan');
        $this->app->settings->set('retention_days', (string) max(1, min(365, (int) $r->input('retention_days'))));
        $this->app->session->flash('Settings saved.');

        return Response::redirect($this->app->url('admin/settings'));
    }

    public function passwordSave(Request $r): Response
    {
        $user = $this->app->auth->user();
        $current = (string) ($r->post['current'] ?? '');
        $new = (string) ($r->post['new'] ?? '');

        if (!$this->app->auth->verifyPassword((int) $user['id'], $current)) {
            $this->app->session->flash('Your current password is incorrect.', 'error');
        } elseif (strlen($new) < 10) {
            $this->app->session->flash('Use at least 10 characters for the new password.', 'error');
        } else {
            $this->app->auth->changePassword((int) $user['id'], $new);
            $this->app->session->flash('Password changed.');
        }

        return Response::redirect($this->app->url('admin/settings') . '#account');
    }

    // ---------------------------------------------------------------- Helpers

    private function page(string $template, array $vars, string $title, string $nav = '', int $status = 200, bool $bare = false): Response
    {
        $html = $this->app->view()->render($template, $vars + ['title' => $title, 'nav' => $nav, 'bare' => $bare]);

        return new Response($status, $html, [
            'Content-Type'    => 'text/html; charset=utf-8',
            'Cache-Control'   => 'no-store',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    private function monitor(string $id): array
    {
        return $this->app->monitors->find((int) $id) ?? throw new NotFound();
    }

    private function channel(string $id): array
    {
        $c = $this->app->db->one('SELECT * FROM wyn_channels WHERE id = ?', [(int) $id]) ?? throw new NotFound();
        $c['config'] = json_decode($c['config'], true) ?: [];

        return $c;
    }

    private function groups(): array
    {
        return array_column($this->app->db->all("SELECT DISTINCT group_name FROM wyn_monitors WHERE group_name != '' ORDER BY group_name"), 'group_name');
    }

    /** @return array{last: int, stale: bool, command: string, url: string} */
    private function cronInfo(): array
    {
        $last = (int) $this->app->settings->get('last_run_at');

        return [
            'last'    => $last,
            'stale'   => $last < time() - 180,
            'command' => '* * * * * php ' . VIGIL_ROOT . '/bin/vigil run >/dev/null 2>&1',
            'url'     => Urls::absolute($this->app->config, 'cron/' . $this->app->settings->cronToken()),
        ];
    }
}
