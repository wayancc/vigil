<?php
/**
 * 90 thin bars, one per day. Colour shows that day's uptime; the tooltip gives the detail.
 *
 * @var Vigil\View $v @var list<array{day: string, pct: ?float, checks: int, incidents: int, downtime: int}> $days
 */
use Vigil\Format;

$level = static fn (?float $pct) => match (true) {
    $pct === null => 'none',
    $pct >= 99.9  => 'good',
    $pct >= 98    => 'minor',
    default       => 'major',
};
?>
<div class="wyn-bars" role="img" aria-label="Daily uptime for the last <?= count($days) ?> days">
  <?php foreach ($days as $d): ?>
  <span class="wyn-bar wyn-<?= $level($d['pct']) ?>" data-wyn-tip="<?= $v->e(date('M j, Y', (int) strtotime($d['day']))) ?>|<?= $d['pct'] === null ? 'No data' : $v->e(Format::percent($d['pct']) . ' uptime') ?>|<?= $d['incidents'] ? $v->e($d['incidents'] . ' incident' . ($d['incidents'] > 1 ? 's' : '') . ', down ' . Format::duration($d['downtime'])) : ($d['pct'] === null ? '' : 'No incidents') ?>"></span>
  <?php endforeach ?>
</div>
<?php if ($legend ?? true): ?>
<div class="wyn-bars-legend"><span><span class="wyn-d-long"><?= count($days) ?> days ago</span><span class="wyn-d-short">30 days ago</span></span><span>Today</span></div>
<?php endif ?>
