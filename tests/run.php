<?php

declare(strict_types=1);

/*
 * Dependency-free test suite: php tests/run.php
 * Uses a throwaway SQLite file, a local HTTP server and local sockets. No internet needed.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Vigil\App;
use Vigil\Config;
use Vigil\Format;
use Vigil\Http\Request;
use Vigil\Monitor\CheckResult;
use Vigil\Monitor\Checker;
use Vigil\Notify\Channels;
use Vigil\Stats;

$passed = $failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? "\033[32m  ✓\033[0m " : "\033[31m  ✗\033[0m ") . $name . ($ok || $detail === '' ? '' : "  ({$detail})") . PHP_EOL;
}

$tmp = sys_get_temp_dir() . '/vigil-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$app = App::boot(VIGIL_ROOT, ['database' => $tmp . '/test.sqlite', 'app_url' => 'https://status.test']);

// A tiny local web server for HTTP checks.
file_put_contents($tmp . '/router.php', <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/ok') { echo 'Hello <b>world</b>'; }
elseif ($p === '/teapot') { http_response_code(418); echo 'short and stout'; }
elseif ($p === '/hook') { file_put_contents(__DIR__ . '/hook.log', file_get_contents('php://input') . "\n", FILE_APPEND); echo 'ok'; }
else { http_response_code(404); }
PHP);
$port = random_int(20000, 40000);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $tmp . '/router.php'], [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:{$port}/ok") === false; $i++) {
    usleep(50_000);
}
$base = "http://127.0.0.1:{$port}";
register_shutdown_function(function () use ($server, $tmp) {
    proc_terminate($server);
    // Close SQLite first: Windows can't delete a file that's still open.
    $GLOBALS['app'] = $GLOBALS['mk'] = $GLOBALS['get'] = $GLOBALS['stats'] = null;
    gc_collect_cycles();
    array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);
});

$mk = function (array $d) use ($app) {
    [$id, $errors] = $app->monitors->save($d + ['interval_sec' => 60, 'timeout_sec' => 5, 'confirmations' => 2, 'is_public' => 1, 'verify_ssl' => 1]);
    if ($errors) {
        throw new RuntimeException(json_encode($errors));
    }

    return $app->monitors->find($id);
};

// ---------------------------------------------------------------------------
echo "Formatting\n";
check('duration', Format::duration(59) === '59s' && Format::duration(3720) === '1h 2m' && Format::duration(90061) === '1d 1h');
check('percent never rounds up to 100', Format::percent(99.9999) === '99.99%' && Format::percent(100.0) === '100%' && Format::percent(null) === '—');
check('expected status ranges', Checker::statusMatches(204, '200-299') && Checker::statusMatches(301, '200,301') && !Checker::statusMatches(500, '200-299'));
$dbPath = fn (string $p) => Config::load('/app', ['database' => $p])->databasePath();
check('absolute database paths on Linux and Windows', $dbPath('/var/vigil.sqlite') === '/var/vigil.sqlite'
    && $dbPath('C:\data\vigil.sqlite') === 'C:\data\vigil.sqlite' && $dbPath('D:/vigil.sqlite') === 'D:/vigil.sqlite'
    && $dbPath('\\\\nas\share\vigil.sqlite') === '\\\\nas\share\vigil.sqlite' && $dbPath('storage/x.sqlite') === '/app/storage/x.sqlite');

// ---------------------------------------------------------------------------
echo "Validation\n";
[, $e] = $app->monitors->save(['name' => '', 'type' => 'http', 'url' => 'ftp://x']);
check('rejects missing name and non-http URL', isset($e['name'], $e['url']));
[, $e] = $app->monitors->save(['name' => 'X', 'type' => 'tcp', 'host' => 'db', 'port' => 70000]);
check('rejects invalid port', isset($e['port']));
[, $e] = $app->monitors->save(['name' => 'X', 'type' => 'keyword', 'url' => 'https://a.test', 'keyword' => '']);
check('keyword monitor needs keyword', isset($e['keyword']));
check('channel validation', isset(Channels::validate('slack', ['webhook_url' => 'nope'])['webhook_url']) && Channels::validate('email', ['to' => 'a@b.co, c@d.co']) === []);
$a = $mk(['name' => 'Same name', 'type' => 'http', 'url' => "$base/ok"]);
$b = $mk(['name' => 'Same name', 'type' => 'http', 'url' => "$base/ok"]);
check('slugs stay unique', $a['slug'] === 'same-name' && $b['slug'] === 'same-name-2');
$app->monitors->delete((int) $a['id']);
$app->monitors->delete((int) $b['id']);

// ---------------------------------------------------------------------------
echo "Checks\n";
$web = $mk(['name' => 'Web', 'type' => 'http', 'url' => "$base/ok"]);
$tea = $mk(['name' => 'Teapot', 'type' => 'http', 'url' => "$base/teapot"]);
$tea418 = $mk(['name' => 'Teapot ok', 'type' => 'http', 'url' => "$base/teapot", 'expected_status' => '418']);
$kw = $mk(['name' => 'Keyword', 'type' => 'keyword', 'url' => "$base/ok", 'keyword' => 'WORLD']);
$kwMissing = $mk(['name' => 'Keyword missing', 'type' => 'keyword', 'url' => "$base/ok", 'keyword' => 'checkout']);
$kwAbsent = $mk(['name' => 'Must be absent', 'type' => 'keyword', 'url' => "$base/ok", 'keyword' => 'hello', 'keyword_absent' => 1]);
$listener = stream_socket_server('tcp://127.0.0.1:0');
$openPort = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
$tcpOpen = $mk(['name' => 'TCP open', 'type' => 'tcp', 'host' => '127.0.0.1', 'port' => $openPort]);
$tcpClosed = $mk(['name' => 'TCP closed', 'type' => 'tcp', 'host' => '127.0.0.1', 'port' => 1]);

$results = (new Checker('Vigil-test'))->run([$web, $tea, $tea418, $kw, $kwMissing, $kwAbsent, $tcpOpen, $tcpClosed]);
check('HTTP 200 is up', $results[$web['id']]->ok && $results[$web['id']]->statusCode === 200);
check('unexpected status is down', !$results[$tea['id']]->ok && str_contains((string) $results[$tea['id']]->error, 'HTTP 418'));
check('custom expected status', $results[$tea418['id']]->ok);
check('keyword found (case-insensitive)', $results[$kw['id']]->ok);
check('keyword missing is down', !$results[$kwMissing['id']]->ok);
check('keyword that must be absent', !$results[$kwAbsent['id']]->ok);
check('open TCP port is up', $results[$tcpOpen['id']]->ok);
check('closed TCP port is down', !$results[$tcpClosed['id']]->ok);
foreach ([$tea, $tea418, $kw, $kwMissing, $kwAbsent, $tcpOpen, $tcpClosed] as $m) {
    $app->monitors->delete((int) $m['id']);
}

// ---------------------------------------------------------------------------
echo "Incidents and alerts\n";
$app->db->insert('wyn_channels', ['type' => 'webhook', 'name' => 'Hook', 'config' => json_encode(['url' => "$base/hook", 'secret' => 'k']), 'enabled' => 1, 'created_at' => time()]);
$t = time();
$m = $app->monitors->find((int) $web['id']);
$app->runner->apply($m, CheckResult::up(50, 200), $t);
$m = $app->monitors->find((int) $web['id']);
check('first success marks up', $m['status'] === 'up');

$app->runner->apply($m, CheckResult::down('Timed out after 5s'), $t + 60);
$m = $app->monitors->find((int) $web['id']);
check('one failure is not an outage yet', $m['status'] === 'up' && (int) $m['fail_count'] === 1);
check('failing monitor is re-checked sooner', (int) $m['next_check_at'] === $t + 60 + 20);
check('no alert on a single blip', $app->notifier->sent === []);

$app->runner->apply($m, CheckResult::down('Timed out after 5s'), $t + 80);
$m = $app->monitors->find((int) $web['id']);
check('second failure confirms the outage', $m['status'] === 'down');
check('incident opened', (int) $app->db->value('SELECT COUNT(*) FROM wyn_incidents WHERE monitor_id = ? AND resolved_at IS NULL', [$m['id']]) === 1);
check('down alert sent', ($app->notifier->sent[0]->event ?? null) === 'down');

$app->runner->apply($m, CheckResult::down('Timed out after 5s'), $t + 140);
check('no repeat alert while still down', count($app->notifier->sent) === 1);

$m = $app->monitors->find((int) $web['id']);
$app->runner->apply($m, CheckResult::up(40, 200), $t + 380);
$m = $app->monitors->find((int) $web['id']);
$incident = $app->db->one('SELECT * FROM wyn_incidents WHERE monitor_id = ?', [$m['id']]);
check('recovery resolves the incident', $m['status'] === 'up' && (int) $incident['resolved_at'] === $t + 380);
check('recovery alert includes downtime', ($app->notifier->sent[1]->event ?? null) === 'up' && str_contains($app->notifier->sent[1]->message, 'Down for 5m'));
$hook = (string) @file_get_contents($tmp . '/hook.log');
check('webhook received both alerts', substr_count($hook, '"event":"down"') === 1 && substr_count($hook, '"event":"up"') === 1);
check('channel delivery logged', (int) $app->db->value('SELECT COUNT(*) FROM wyn_notification_log WHERE ok = 1') === 2);

// ---------------------------------------------------------------------------
echo "Heartbeats\n";
$hb = $mk(['name' => 'Backup', 'type' => 'heartbeat', 'interval_sec' => 3600, 'grace_min' => 10]);
$created = (int) $hb['created_at'];
check('heartbeat gets a token', strlen((string) $hb['heartbeat_token']) === 24);
check('no verdict before the first window ends', (new Checker('x'))->run([$hb], $created + 100) === []);
check('missing first ping is down', !(new Checker('x'))->run([$hb], $created + 3600 + 601)[$hb['id']]->ok);
$app->runner->heartbeat($hb, $created + 50);
$hb = $app->monitors->find((int) $hb['id']);
check('ping marks it up', $hb['status'] === 'up');
$late = (new Checker('x'))->run([$hb], $created + 50 + 3600 + 601)[$hb['id']];
check('late ping is down with a reason', !$late->ok && str_contains((string) $late->error, 'No heartbeat for'));

// ---------------------------------------------------------------------------
echo "Stats\n";
$stats = new Stats($app->db);
check('uptime from checks', abs((float) $stats->uptimeHours((int) $web['id'], 24, $t + 400) - 40.0) < 0.01);
$days = $stats->days((int) $web['id'], 90, $t + 400);
check('90 daily bars, today last', count($days) === 90 && end($days)['day'] === date('Y-m-d', $t + 400) && end($days)['incidents'] === 1);
check('overall: one down of two', Stats::overall([['paused' => 0, 'status' => 'down'], ['paused' => 0, 'status' => 'up']])['state'] === 'partial');
check('overall: maintenance', Stats::overall([['paused' => 0, 'status' => 'up']], true)['state'] === 'maintenance');

// ---------------------------------------------------------------------------
echo "HTTP routes\n";
$get = fn (string $path) => $app->handle(new Request('GET', $path));
check('status page renders', $get('/')->status === 200 && str_contains($get('/')->body, 'Web'));
$api = json_decode($get('/api/status.json')->body, true);
check('JSON API', is_array($api) && in_array('Web', array_column($api['services'], 'name'), true));
check('status badge is SVG', str_starts_with(trim($get('/badge/web/status.svg')->body), '<svg') && str_contains($get('/badge/web/status.svg')->body, '>up<'));
check('uptime badge', str_contains($app->handle(new Request('GET', '/badge/web/uptime.svg', query: ['days' => '30']))->body, 'uptime 30d'));
check('unknown badge is 404', $get('/badge/nope/status.svg')->status === 404);
check('heartbeat URL works', $get('/hb/' . $hb['heartbeat_token'])->status === 200);
check('wrong heartbeat token is 404', $get('/hb/' . str_repeat('0', 24))->status === 404);
check('web cron needs the token', $get('/cron/wrong')->status === 403);
check('admin redirects to setup before an account exists', $get('/admin')->headers['Location'] === '/admin/setup');
$_SESSION = [];
$token = $app->session->csrfToken();
$res = $app->handle(new Request('POST', '/admin/setup', post: ['_token' => $token, 'email' => 'me@example.com', 'password' => 'long-enough-pw']));
check('setup creates the admin and signs in', $res->status === 303 && $app->auth->user()['email'] === 'me@example.com');
check('admin POST without CSRF token is refused', $app->handle(new Request('POST', '/admin/monitors', post: ['name' => 'X']))->status === 419);
check('dashboard renders', $get('/admin')->status === 200 && str_contains($get('/admin')->body, 'Backup'));
check('monitor page renders', $get('/admin/monitors/' . $web['id'])->status === 200);
check('bold theme by default, no external fonts', str_contains($get('/')->body, 'data-wyn-theme="wayan"') && !str_contains($get('/')->body, 'googleapis') && str_contains($get('/')->body, 'wyn-tokens.css'));
$app->handle(new Request('POST', '/admin/settings', post: ['_token' => $token, 'site_name' => 'Acme', 'theme' => 'neutral', 'retention_days' => '30']));
check('neutral theme applies to dashboard and status page', str_contains($get('/admin')->body, 'data-wyn-theme="neutral"') && str_contains($get('/')->body, 'data-wyn-theme="neutral"'));
check('webhook signed with X-Wyn-Signature', str_contains((string) file_get_contents(VIGIL_ROOT . '/src/Notify/Channels.php'), "'X-Wyn-Signature: sha256='"));
$app->auth->logout();
check('signed out users are sent to login', $get('/admin')->headers['Location'] === '/admin/login');
for ($i = 0; $i < 5; $i++) {
    $app->auth->attempt('me@example.com', 'wrong', '10.0.0.9');
}
check('login is rate limited per IP', $app->auth->isThrottled('10.0.0.9') && !$app->auth->isThrottled('10.0.0.10'));

echo PHP_EOL . ($failed === 0 ? "\033[32m{$passed} passed\033[0m" : "\033[31m{$failed} failed\033[0m, {$passed} passed") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
