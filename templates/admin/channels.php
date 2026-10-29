<?php
use Vigil\Notify\Channels;

$v->layout('layout/admin');
?>
<div class="wyn-page-head">
  <div>
    <h1 class="wyn-display">Alerts<span class="wyn-dot">.</span></h1>
    <p class="wyn-muted">Every enabled channel is told when a monitor goes down, comes back up, or its SSL certificate is about to expire.</p>
  </div>
</div>

<?php if ($channels !== []): ?>
<table class="wyn-table">
  <thead><tr><th scope="col">Channel</th><th scope="col">Type</th><th scope="col">Last delivery</th><th scope="col"><span class="wyn-sr-only">Actions</span></th></tr></thead>
  <tbody>
    <?php foreach ($channels as $c): ?>
    <tr<?= $c['enabled'] ? '' : ' class="wyn-is-paused"' ?>>
      <td><a href="<?= $v->url('admin/channels/' . $c['id'] . '/edit') ?>"><strong><?= $v->e($c['name']) ?></strong></a><?= $c['enabled'] ? '' : ' <span class="wyn-tag">Off</span>' ?></td>
      <td class="wyn-muted"><?= $v->e(Channels::TYPES[$c['type']][0] ?? $c['type']) ?></td>
      <td>
        <?php if ($c['last_sent'] === null): ?><span class="wyn-muted">Nothing sent yet</span>
        <?php elseif ($c['last_ok']): ?><span class="wyn-text-up">Delivered</span> <span class="wyn-muted"><?= $v->e($v->ago((int) $c['last_sent'])) ?></span>
        <?php else: ?><span class="wyn-text-down" title="<?= $v->e($c['last_error']) ?>">Failed <?= $v->e($v->ago((int) $c['last_sent'])) ?></span><small class="wyn-block wyn-muted wyn-clip"><?= $v->e($c['last_error']) ?></small>
        <?php endif ?>
      </td>
      <td class="wyn-row-actions">
        <form method="post" action="<?= $v->url('admin/channels/' . $c['id'] . '/test') ?>"><?= $v->csrf() ?><button class="wyn-btn wyn-small" type="submit">Send test</button></form>
      </td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>

<section class="wyn-panel">
  <h2><?= $channels === [] ? 'Add your first channel' : 'Add a channel' ?></h2>
  <div class="wyn-channel-picker">
    <?php foreach (Channels::TYPES as $type => [$label]): ?>
    <a class="wyn-channel-option" href="<?= $v->url('admin/channels/new?type=' . $type) ?>">
      <strong><?= $v->e($label) ?></strong>
      <small><?= match ($type) {
          'email'    => $smtp ? 'Uses your SMTP settings' : 'Uses PHP mail() until you add SMTP',
          'telegram' => 'Message a chat or group via a bot',
          'slack'    => 'Post to a channel',
          'discord'  => 'Post to a server channel',
          'ntfy'     => 'Free push notifications to your phone',
          'webhook'  => 'POST JSON to your own endpoint',
      } ?></small>
    </a>
    <?php endforeach ?>
  </div>
</section>
