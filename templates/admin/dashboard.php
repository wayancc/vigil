<?php
use Vigil\Charts;
use Vigil\Format;
use Vigil\Monitor\Monitors;
use Vigil\Monitor\Runner;

$v->layout('layout/admin');
$state = static fn (array $m) => $m['paused'] ? 'paused' : ($m['status'] === 'up' && $m['fail_count'] > 0 ? 'retrying' : $m['status']);
$stateLabel = ['up' => 'Up', 'down' => 'Down', 'pending' => 'Pending', 'paused' => 'Paused', 'retrying' => 'Retrying'];
$groups = [];
foreach ($monitors as $m) {
    $groups[$m['group_name']][] = $m;
}
?>
<div class="wyn-page-head">
  <div>
    <h1 class="wyn-display">Monitors<span class="wyn-dot">.</span></h1>
    <p class="wyn-summary">
      <?php if ($monitors === []): ?>Nothing monitored yet.
      <?php else: ?>
      <span class="wyn-count wyn-up"><?= $counts['up'] ?> up</span>
      <?php if ($counts['down']): ?><span class="wyn-count wyn-down"><?= $counts['down'] ?> down</span><?php endif ?>
      <?php if ($counts['pending']): ?><span class="wyn-count wyn-pending"><?= $counts['pending'] ?> pending</span><?php endif ?>
      <?php if ($counts['paused']): ?><span class="wyn-count wyn-paused"><?= $counts['paused'] ?> paused</span><?php endif ?>
      <?php endif ?>
    </p>
  </div>
  <a class="wyn-btn wyn-primary" href="<?= $v->url('admin/monitors/new') ?>">Add monitor</a>
</div>

<?php if ($monitors !== [] && $cron['stale']): ?>
<section class="wyn-callout wyn-warn">
  <h2><?= $cron['last'] ? 'Checks stopped running ' . $v->e(Format::ago($cron['last'])) : 'Checks aren’t running yet' ?></h2>
  <p>Vigil needs something to wake it up every minute. Pick one:</p>
  <dl class="wyn-howto">
    <dt>Cron (VPS, most hosting panels)</dt>
    <dd><code class="wyn-copyable" data-wyn-copy><?= $v->e($cron['command']) ?></code></dd>
    <dt>No cron? Use a free scheduler like cron-job.org to open this URL every minute</dt>
    <dd><code class="wyn-copyable" data-wyn-copy><?= $v->e($cron['url']) ?></code></dd>
    <dt>Docker or systemd</dt>
    <dd><code>php bin/vigil work</code></dd>
  </dl>
</section>
<?php endif ?>

<?php if ($monitors !== [] && $channels === 0): ?>
<p class="wyn-callout wyn-info">No alert channels yet, so nobody will be told when something goes down. <a href="<?= $v->url('admin/channels') ?>">Add email, Telegram, Slack or another channel</a>.</p>
<?php endif ?>

<?php if ($open !== []): ?>
<section class="wyn-incident-strip" aria-label="Ongoing incidents">
  <?php foreach ($open as $i): ?>
  <a href="<?= $v->url('admin/monitors/' . $i['monitor_id']) ?>">
    <strong><?= $v->e($i['monitor_name']) ?></strong> has been down for <?= $v->e(Format::duration(time() - (int) $i['started_at'])) ?>
    <span class="wyn-muted"><?= $v->e($i['cause']) ?></span>
  </a>
  <?php endforeach ?>
</section>
<?php endif ?>

<?php if ($monitors === []): ?>
<section class="wyn-empty">
  <h2>Add your first monitor</h2>
  <p>Vigil can watch a website or API, look for text on a page, check that a port is open, or wait for a cron job to check in.</p>
  <a class="wyn-btn wyn-primary" href="<?= $v->url('admin/monitors/new') ?>">Add monitor</a>
</section>
<?php else: ?>

<?php foreach ($groups as $group => $items): ?>
<section class="wyn-mgroup">
  <?php if ($group !== '' || count($groups) > 1): ?><h2 class="wyn-group-title"><?= $v->e($group ?: 'Other') ?></h2><?php endif ?>
  <table class="wyn-mtable">
    <thead>
      <tr>
        <th scope="col">Monitor</th>
        <th scope="col" class="wyn-hide-sm">Last 40 checks</th>
        <th scope="col" class="wyn-num">24h</th>
        <th scope="col" class="wyn-num wyn-hide-sm">30 days</th>
        <th scope="col" class="wyn-num wyn-hide-sm">Avg response</th>
        <th scope="col" class="wyn-num wyn-hide-md">Checked</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $m): $s = $state($m); ?>
      <tr class="wyn-is-<?= $s ?>">
        <td>
          <a class="wyn-mname" href="<?= $v->url('admin/monitors/' . $m['id']) ?>">
            <span class="wyn-pip wyn-<?= $s ?>" title="<?= $stateLabel[$s] ?>"></span>
            <span>
              <strong><?= $v->e($m['name']) ?></strong>
              <small><?= $v->e(Monitors::TYPES[$m['type']]) ?> · <?= $v->e(Runner::target($m)) ?></small>
              <?php if ($s === 'down' || $s === 'retrying'): ?><small class="wyn-reason"><?= $v->e($m['last_error']) ?></small><?php endif ?>
            </span>
          </a>
        </td>
        <td class="wyn-hide-sm"><?= Charts::sparkline($m['recent']) ?></td>
        <td class="wyn-num"><?= Format::percent($m['uptime24']) ?></td>
        <td class="wyn-num wyn-hide-sm"><?= Format::percent($m['uptime30']) ?></td>
        <td class="wyn-num wyn-hide-sm"><?= Format::ms($m['avg24']) ?></td>
        <td class="wyn-num wyn-hide-md wyn-muted"><?= $m['paused'] ? 'paused' : $v->e($v->ago($m['last_checked_at'] ? (int) $m['last_checked_at'] : null)) ?></td>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endforeach ?>
<?php endif ?>
