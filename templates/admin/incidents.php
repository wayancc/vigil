<?php
use Vigil\Format;

$v->layout('layout/admin');
?>
<div class="wyn-page-head"><h1 class="wyn-display">Incidents<span class="wyn-dot">.</span></h1></div>

<?php if ($incidents === []): ?>
<section class="wyn-empty">
  <h2>No incidents yet</h2>
  <p>When a monitor goes down, it’s recorded here with how long it lasted and why.</p>
</section>
<?php else: ?>
<table class="wyn-table">
  <thead><tr><th scope="col">Monitor</th><th scope="col">Started</th><th scope="col">Duration</th><th scope="col">Cause</th></tr></thead>
  <tbody>
    <?php foreach ($incidents as $i): ?>
    <tr>
      <td><a href="<?= $v->url('admin/monitors/' . $i['monitor_id']) ?>"><span class="wyn-pip wyn-<?= $i['resolved_at'] ? 'up' : 'down' ?>"></span> <?= $v->e($i['monitor_name']) ?></a></td>
      <td class="wyn-muted"><?= $v->time((int) $i['started_at'], 'M j, Y H:i') ?></td>
      <td><?= $i['resolved_at'] ? $v->e(Format::duration((int) $i['resolved_at'] - (int) $i['started_at'])) : '<strong class="wyn-text-down">Ongoing · ' . $v->e(Format::duration(time() - (int) $i['started_at'])) . '</strong>' ?></td>
      <td class="wyn-clip"><?= $v->e($i['cause']) ?></td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
