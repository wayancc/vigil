<?php
$v->layout('layout/admin');
$now = time();
$kinds = ['info' => 'Information', 'maintenance' => 'Scheduled maintenance', 'incident' => 'Incident update'];
?>
<div class="wyn-page-head">
  <div>
    <h1 class="wyn-display">Announcements<span class="wyn-dot">.</span></h1>
    <p class="wyn-muted">Messages shown at the top of the public status page. Use them for planned maintenance or to explain an outage.</p>
  </div>
</div>

<form method="post" action="<?= $v->url('admin/notices') ?>" class="wyn-form wyn-panel">
  <?= $v->csrf() ?>
  <h2>New announcement</h2>
  <div class="wyn-grid2">
    <label class="wyn-field<?= isset($errors['title']) ? ' wyn-has-error' : '' ?>">
      <span>Title</span>
      <input name="title" value="<?= $v->e($old['title'] ?? '') ?>" maxlength="140" required placeholder="Database upgrade on Sunday">
      <?php if (isset($errors['title'])): ?><small class="wyn-error"><?= $v->e($errors['title']) ?></small><?php endif ?>
    </label>
    <label class="wyn-field">
      <span>Type</span>
      <select name="kind"><?php foreach ($kinds as $k => $label): ?><option value="<?= $k ?>"<?= ($old['kind'] ?? '') === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
    </label>
  </div>
  <label class="wyn-field">
    <span>Details <small class="wyn-muted">optional</small></span>
    <textarea name="body" rows="3" maxlength="2000" placeholder="The API may be unavailable for up to 15 minutes."><?= $v->e($old['body'] ?? '') ?></textarea>
  </label>
  <div class="wyn-grid2">
    <label class="wyn-field">
      <span>Starts <small class="wyn-muted">empty = now</small></span>
      <input type="datetime-local" name="starts_at" value="<?= $v->e($old['starts_at'] ?? '') ?>">
    </label>
    <label class="wyn-field<?= isset($errors['ends_at']) ? ' wyn-has-error' : '' ?>">
      <span>Ends <small class="wyn-muted">empty = until you end it</small></span>
      <input type="datetime-local" name="ends_at" value="<?= $v->e($old['ends_at'] ?? '') ?>">
      <?php if (isset($errors['ends_at'])): ?><small class="wyn-error"><?= $v->e($errors['ends_at']) ?></small><?php endif ?>
    </label>
  </div>
  <div class="wyn-form-actions"><button class="wyn-btn wyn-primary" type="submit">Publish announcement</button></div>
</form>

<?php if ($notices !== []): ?>
<table class="wyn-table">
  <thead><tr><th scope="col">Announcement</th><th scope="col">When</th><th scope="col">State</th><th scope="col"><span class="wyn-sr-only">Actions</span></th></tr></thead>
  <tbody>
    <?php foreach ($notices as $n):
        $state = (int) $n['starts_at'] > $now ? 'Scheduled' : ($n['ends_at'] && (int) $n['ends_at'] <= $now ? 'Ended' : 'Live'); ?>
    <tr>
      <td><strong><?= $v->e($n['title']) ?></strong><small class="wyn-block wyn-muted"><?= $v->e($kinds[$n['kind']] ?? $n['kind']) ?></small></td>
      <td class="wyn-muted"><?= $v->time((int) $n['starts_at'], 'M j, H:i') ?><?= $n['ends_at'] ? ' – ' . $v->time((int) $n['ends_at'], 'M j, H:i') : '' ?></td>
      <td><span class="wyn-tag wyn-<?= strtolower($state) ?>"><?= $state ?></span></td>
      <td class="wyn-row-actions">
        <?php if ($state !== 'Ended'): ?>
        <form method="post" action="<?= $v->url('admin/notices/' . $n['id'] . '/end') ?>"><?= $v->csrf() ?><button class="wyn-btn wyn-small" type="submit">End now</button></form>
        <?php endif ?>
        <form method="post" action="<?= $v->url('admin/notices/' . $n['id'] . '/delete') ?>" data-wyn-confirm="Delete this announcement?"><?= $v->csrf() ?><button class="wyn-btn wyn-small wyn-ghost" type="submit">Delete</button></form>
      </td>
    </tr>
    <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
