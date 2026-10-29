<?php
use Vigil\Charts;
use Vigil\Format;
use Vigil\Monitor\Monitors;
use Vigil\Monitor\Runner;

$v->layout('layout/admin');
$s = $m['paused'] ? 'paused' : ($m['status'] === 'up' && $m['fail_count'] > 0 ? 'retrying' : $m['status']);
$headline = [
    'up'       => 'Up',
    'down'     => 'Down',
    'pending'  => $m['type'] === 'heartbeat' ? 'Waiting for the first check-in' : 'Waiting for the first check',
    'paused'   => 'Paused',
    'retrying' => 'Failing, re-checking before alerting',
][$s];
?>
<div class="wyn-page-head">
  <div>
    <p class="wyn-crumb"><a href="<?= $v->url('admin') ?>">Monitors</a><?= $m['group_name'] ? ' / ' . $v->e($m['group_name']) : '' ?></p>
    <h1 class="wyn-display"><?= $v->e($m['name']) ?><span class="wyn-dot">.</span></h1>
    <p class="wyn-muted"><?= $v->e(Monitors::TYPES[$m['type']]) ?> · <?= $v->e(Runner::target($m)) ?></p>
  </div>
  <div class="wyn-actions">
    <?php if (!$m['paused']): ?>
    <form method="post" action="<?= $v->url('admin/monitors/' . $m['id'] . '/check') ?>"><?= $v->csrf() ?><button class="wyn-btn" type="submit">Check now</button></form>
    <?php endif ?>
    <a class="wyn-btn" href="<?= $v->url('admin/monitors/' . $m['id'] . '/edit') ?>">Edit</a>
    <form method="post" action="<?= $v->url('admin/monitors/' . $m['id'] . '/' . ($m['paused'] ? 'resume' : 'pause')) ?>"><?= $v->csrf() ?><button class="wyn-btn" type="submit"><?= $m['paused'] ? 'Resume' : 'Pause' ?></button></form>
  </div>
</div>

<section class="wyn-hero-state wyn-is-<?= $s ?>">
  <span class="wyn-pip wyn-<?= $s ?>"></span>
  <div>
    <strong><?= $v->e($headline) ?></strong>
    <?php if ($m['status_since'] && !$m['paused'] && $s !== 'pending'): ?><span>for <?= $v->e(Format::duration(time() - (int) $m['status_since'])) ?></span><?php endif ?>
    <?php if (in_array($s, ['down', 'retrying'], true) && $m['last_error']): ?><p><?= $v->e($m['last_error']) ?></p><?php endif ?>
  </div>
  <dl class="wyn-kpis">
    <div><dt>Last check</dt><dd><?= $v->e($v->ago($m['last_checked_at'] ? (int) $m['last_checked_at'] : null)) ?></dd></div>
    <?php if ($m['type'] !== 'heartbeat'): ?><div><dt>Response</dt><dd><?= Format::ms($m['last_response_ms'] !== null ? (int) $m['last_response_ms'] : null) ?></dd></div><?php endif ?>
    <?php if ($m['ssl_expires_at']): $sslDays = (int) floor(((int) $m['ssl_expires_at'] - time()) / 86400); ?>
    <div class="wyn-<?= $sslDays <= (int) $m['ssl_warn_days'] ? 'warn' : '' ?>"><dt>SSL expires</dt><dd><?= $sslDays < 0 ? 'Expired' : "in {$sslDays} days" ?></dd></div>
    <?php endif ?>
  </dl>
</section>

<?php if ($hbUrl): ?>
<section class="wyn-panel">
  <h2>Heartbeat URL</h2>
  <p>Request this URL at the end of each successful run. If it’s not called every <?= $v->e(Format::duration((int) $m['interval_sec'])) ?> (plus a <?= $v->e(Format::duration((int) $m['timeout_sec'])) ?> grace period), Vigil alerts you.</p>
  <code class="wyn-copyable wyn-block" data-wyn-copy><?= $v->e($hbUrl) ?></code>
  <details class="wyn-examples">
    <summary>Examples</summary>
    <pre><code># crontab: run the backup, then check in only if it succeeded
0 3 * * * /usr/local/bin/backup.sh &amp;&amp; curl -fsS -m 10 <?= $v->e($hbUrl) ?> &gt;/dev/null

# report a failure straight away (optional message)
curl -fsS -m 10 "<?= $v->e($hbUrl) ?>/fail?msg=disk+full"</code></pre>
  </details>
</section>
<?php endif ?>

<section class="wyn-panel">
  <div class="wyn-panel-head">
    <h2>Response time</h2>
    <nav class="wyn-seg" aria-label="Time range">
      <?php foreach (['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'] as $key => $label): ?>
      <a href="?range=<?= $key ?>"<?= $range === $key ? ' aria-current="true"' : '' ?>><?= $label ?></a>
      <?php endforeach ?>
    </nav>
  </div>
  <?= Charts::responseChart($series, $from, $to) ?>
  <dl class="wyn-stats">
    <div><dt>Uptime 24h</dt><dd><?= Format::percent($uptime['24h']) ?></dd></div>
    <div><dt>7 days</dt><dd><?= Format::percent($uptime['7d']) ?></dd></div>
    <div><dt>30 days</dt><dd><?= Format::percent($uptime['30d']) ?></dd></div>
    <div><dt>90 days</dt><dd><?= Format::percent($uptime['90d']) ?></dd></div>
    <div><dt>Avg response (<?= $range ?>)</dt><dd><?= Format::ms($avg) ?></dd></div>
  </dl>
</section>

<section class="wyn-panel">
  <h2>Last 90 days</h2>
  <?= $v->partial('partials/bars', ['days' => $days]) ?>
</section>

<div class="wyn-grid2 wyn-top">
  <section class="wyn-panel">
    <h2>Incidents</h2>
    <?php if ($incidents === []): ?>
    <p class="wyn-muted">No incidents. Nice.</p>
    <?php else: ?>
    <ul class="wyn-incidents">
      <?php foreach ($incidents as $i): ?>
      <li>
        <span class="wyn-pip wyn-<?= $i['resolved_at'] ? 'up' : 'down' ?>"></span>
        <div>
          <strong><?= $i['resolved_at'] ? 'Down for ' . $v->e(Format::duration((int) $i['resolved_at'] - (int) $i['started_at'])) : 'Ongoing for ' . $v->e(Format::duration(time() - (int) $i['started_at'])) ?></strong>
          <small><?= $v->time((int) $i['started_at'], 'M j, Y H:i') ?> · <?= $v->e($i['cause']) ?></small>
        </div>
      </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>
  </section>

  <section class="wyn-panel">
    <h2>Recent checks</h2>
    <?php if ($recent === []): ?>
    <p class="wyn-muted">No checks yet.</p>
    <?php else: ?>
    <table class="wyn-checks">
      <?php foreach ($recent as $c): ?>
      <tr>
        <td><span class="wyn-pip wyn-<?= $c['ok'] ? 'up' : 'down' ?>"></span></td>
        <td class="wyn-muted"><?= $v->time((int) $c['checked_at'], 'M j H:i:s') ?></td>
        <td class="wyn-num"><?= Format::ms($c['response_ms'] !== null ? (int) $c['response_ms'] : null) ?></td>
        <td class="wyn-clip"><?= $c['ok'] ? ($c['status_code'] ? 'HTTP ' . (int) $c['status_code'] : 'OK') : $v->e($c['error']) ?></td>
      </tr>
      <?php endforeach ?>
    </table>
    <?php endif ?>
  </section>
</div>

<?php if ($m['is_public']): ?>
<section class="wyn-panel">
  <h2>Badges</h2>
  <p class="wyn-muted">Paste into a README or website. They update automatically.</p>
  <div class="wyn-badges">
    <?php foreach (['status' => 'Status', 'uptime' => 'Uptime (30 days)', 'response' => 'Average response'] as $kind => $label): $src = $badgeBase . '/' . $kind . '.svg'; ?>
    <div>
      <img src="<?= $v->e($v->url('badge/' . $m['slug'] . '/' . $kind . '.svg')) ?>" alt="<?= $v->e($label) ?> badge" height="20">
      <code class="wyn-copyable" data-wyn-copy>![<?= $v->e($m['name']) ?>](<?= $v->e($src) ?>)</code>
    </div>
    <?php endforeach ?>
  </div>
</section>
<?php endif ?>

<section class="wyn-danger">
  <form method="post" action="<?= $v->url('admin/monitors/' . $m['id'] . '/delete') ?>" data-wyn-confirm="Delete <?= $v->e($m['name']) ?> and all of its history? This can’t be undone.">
    <?= $v->csrf() ?>
    <button class="wyn-btn wyn-danger" type="submit">Delete monitor</button>
  </form>
</section>
