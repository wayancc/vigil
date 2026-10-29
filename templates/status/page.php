<?php
/**
 * Public status page.
 *
 * @var Vigil\View $v @var array $overall @var array $groups @var array $notices @var array $past @var array $incidents @var int $lastRun
 */
use Vigil\Format;

$name = $settings->get('site_name');
$labels = ['up' => 'Operational', 'down' => 'Down', 'pending' => 'Checking', 'paused' => 'Not monitored'];
$kindLabel = ['info' => 'Notice', 'maintenance' => 'Maintenance', 'incident' => 'Incident'];
$now = time();
?><!doctype html>
<html lang="en" data-wyn-theme="<?= $v->e($settings->get('theme')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($name) ?> · <?= $v->e($overall['label']) ?></title>
<meta name="description" content="<?= $v->e($settings->get('site_description') ?: "Live status and uptime history for {$name}.") ?>">
<meta property="og:title" content="<?= $v->e($name) ?>">
<meta property="og:description" content="<?= $v->e($overall['label']) ?>">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b0b0b" media="(prefers-color-scheme: dark)">
<link rel="alternate" type="application/json" href="<?= $v->url('api/status.json') ?>">
<?= $v->partial('partials/head') ?>
</head>
<body class="wyn-status" data-wyn-autorefresh="60">
<div class="wyn-sp">
  <header class="wyn-sp-head">
    <div class="wyn-sp-brand"><?= $v->e($name) ?><span class="wyn-dot">.</span></div>
    <?php if ($settings->get('site_url')): ?><a class="wyn-quiet" href="<?= $v->e($settings->get('site_url')) ?>">Back to site</a><?php endif ?>
  </header>

  <section class="wyn-sp-state wyn-state-<?= $v->e($overall['state']) ?>">
    <h1 class="wyn-display"><?= $v->e($overall['label']) ?><span class="wyn-dot">.</span></h1>
    <p><?= $lastRun ? 'Checked ' . $v->e(Format::ago($lastRun)) : 'Monitoring starts in a minute' ?><?= $settings->get('site_description') ? ' · ' . $v->e($settings->get('site_description')) : '' ?></p>
  </section>

  <?php foreach ($notices as $n): $upcoming = (int) $n['starts_at'] > $now; ?>
  <article class="wyn-notice wyn-notice-<?= $v->e($n['kind']) ?>">
    <p class="wyn-notice-meta"><?= $upcoming ? 'Upcoming ' . strtolower($kindLabel[$n['kind']] ?? 'notice') : ($kindLabel[$n['kind']] ?? 'Notice') ?> · <?= $v->time((int) $n['starts_at'], 'M j, H:i') ?><?= $n['ends_at'] ? ' – ' . $v->time((int) $n['ends_at'], date('Y-m-d', (int) $n['ends_at']) === date('Y-m-d', (int) $n['starts_at']) ? 'H:i' : 'M j, H:i') : '' ?> <?= $v->e(date('T')) ?></p>
    <h2><?= $v->e($n['title']) ?></h2>
    <?php if ($n['body'] !== ''): ?><p><?= nl2br($v->e($n['body'])) ?></p><?php endif ?>
  </article>
  <?php endforeach ?>

  <?php if ($groups === []): ?>
  <p class="wyn-sp-empty">No services are listed on this page yet.</p>
  <?php endif ?>

  <?php foreach ($groups as $group => $items): ?>
  <section class="wyn-sp-group">
    <?php if ($group !== ''): ?><h2><?= $v->e($group) ?></h2><?php endif ?>
    <?php foreach ($items as $m): $s = $m['paused'] ? 'paused' : $m['status']; ?>
    <div class="wyn-service">
      <div class="wyn-service-head">
        <h3><?= $v->e($m['name']) ?></h3>
        <span class="wyn-service-state wyn-<?= $s ?>"><span class="wyn-pip wyn-<?= $s ?>"></span><?= $labels[$s] ?></span>
      </div>
      <?= $v->partial('partials/bars', ['days' => $m['days'], 'legend' => false]) ?>
      <p class="wyn-service-foot"><span><span class="wyn-d-long">90 days ago</span><span class="wyn-d-short">30 days ago</span></span><span>90-day uptime <?= Format::percent($m['uptime90']) ?></span><span>Today</span></p>
    </div>
    <?php endforeach ?>
  </section>
  <?php endforeach ?>

  <section class="wyn-sp-history">
    <h2>Recent incidents</h2>
    <?php if ($incidents === [] && $past === []): ?>
    <p class="wyn-muted">No incidents reported in the last 90 days.</p>
    <?php else: ?>
    <ol class="wyn-timeline">
      <?php
      $items = [];
      foreach ($incidents as $i) {
          $items[] = ['t' => (int) $i['started_at'], 'html' =>
              '<strong>' . $v->e($i['monitor_name']) . ($i['resolved_at'] ? ' was down' : ' is down') . '</strong>'
              . '<span>' . $v->time((int) $i['started_at'], 'M j, H:i') . ' · '
              . ($i['resolved_at'] ? 'resolved after ' . $v->e(Format::duration((int) $i['resolved_at'] - (int) $i['started_at'])) : 'ongoing for ' . $v->e(Format::duration($now - (int) $i['started_at'])))
              . '</span>', 'state' => $i['resolved_at'] ? 'resolved' : 'ongoing'];
      }
      foreach ($past as $n) {
          $items[] = ['t' => (int) $n['starts_at'], 'html' => '<strong>' . $v->e($n['title']) . '</strong><span>' . $v->e($kindLabel[$n['kind']] ?? 'Notice') . ' · ' . $v->time((int) $n['starts_at'], 'M j, H:i') . ' – ' . $v->time((int) $n['ends_at'], 'M j, H:i') . '</span>', 'state' => 'notice'];
      }
      usort($items, fn ($a, $b) => $b['t'] <=> $a['t']);
      foreach (array_slice($items, 0, 15) as $item): ?>
      <li class="wyn-<?= $item['state'] ?>"><?= $item['html'] ?></li>
      <?php endforeach ?>
    </ol>
    <?php endif ?>
  </section>

  <footer class="wyn-sp-foot">
    <span>Times in <?= $v->e(date_default_timezone_get()) ?></span>
    <a href="<?= $v->url('api/status.json') ?>">JSON API</a>
    <a href="https://github.com/wayancc/vigil">Powered by Vigil</a>
  </footer>
</div>
<div class="wyn-tip" hidden></div>
</body>
</html>
