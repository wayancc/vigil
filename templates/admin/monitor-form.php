<?php
use Vigil\Monitor\Monitors;

$v->layout('layout/admin');
$isNew = empty($m['id']);
$err = fn (string $f) => isset($errors[$f]) ? '<small class="wyn-error">' . $v->e($errors[$f]) . '</small>' : '';
$cls = fn (string $f) => isset($errors[$f]) ? ' wyn-has-error' : '';
?>
<div class="wyn-page-head">
  <div>
    <p class="wyn-crumb"><a href="<?= $v->url('admin') ?>">Monitors</a><?php if (!$isNew): ?> / <a href="<?= $v->url('admin/monitors/' . $m['id']) ?>"><?= $v->e($m['name']) ?></a><?php endif ?></p>
    <h1 class="wyn-display"><?= $isNew ? 'New monitor' : 'Edit monitor' ?><span class="wyn-dot">.</span></h1>
  </div>
</div>

<form method="post" action="<?= $v->url($isNew ? 'admin/monitors' : 'admin/monitors/' . $m['id']) ?>" class="wyn-form" data-wyn-monitor-form>
  <?= $v->csrf() ?>

  <fieldset class="wyn-types">
    <legend>What should Vigil watch?</legend>
    <?php foreach (['http' => 'A website or API responds', 'keyword' => 'A page contains (or lacks) some text', 'tcp' => 'A port accepts connections', 'heartbeat' => 'A cron job or script checks in'] as $type => $hint): ?>
    <label class="wyn-type-option">
      <input type="radio" name="type" value="<?= $type ?>"<?= $m['type'] === $type ? ' checked' : '' ?>>
      <span><strong><?= $v->e(Monitors::TYPES[$type]) ?></strong><small><?= $hint ?></small></span>
    </label>
    <?php endforeach ?>
  </fieldset>

  <div class="wyn-grid2">
    <label class="wyn-field<?= $cls('name') ?>">
      <span>Name</span>
      <input name="name" value="<?= $v->e($m['name']) ?>" required maxlength="100" placeholder="Website">
      <?= $err('name') ?>
    </label>
    <label class="wyn-field">
      <span>Group <small class="wyn-muted">optional</small></span>
      <input name="group_name" value="<?= $v->e($m['group_name']) ?>" list="groups" maxlength="60" placeholder="Production">
      <datalist id="groups"><?php foreach ($groups as $g): ?><option value="<?= $v->e($g) ?>"><?php endforeach ?></datalist>
    </label>
  </div>

  <div data-wyn-show="http keyword">
    <label class="wyn-field<?= $cls('url') ?>">
      <span>URL</span>
      <input name="url" type="url" value="<?= $v->e($m['url'] ?? '') ?>" placeholder="https://example.com/health" inputmode="url">
      <?= $err('url') ?>
    </label>
    <div class="wyn-grid3">
      <label class="wyn-field">
        <span>Method</span>
        <select name="method"><?php foreach (['GET', 'HEAD', 'POST'] as $method): ?><option<?= $m['method'] === $method ? ' selected' : '' ?>><?= $method ?></option><?php endforeach ?></select>
      </label>
      <label class="wyn-field<?= $cls('expected_status') ?>">
        <span>Expected status</span>
        <input name="expected_status" value="<?= $v->e($m['expected_status']) ?>" placeholder="200-299">
        <?= $err('expected_status') ?>
      </label>
      <label class="wyn-field">
        <span>Warn before SSL expiry</span>
        <select name="ssl_warn_days"><?php foreach ([0 => 'Never', 7 => '7 days', 14 => '14 days', 30 => '30 days'] as $d => $label): ?><option value="<?= $d ?>"<?= (int) $m['ssl_warn_days'] === $d ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
    </div>
    <label class="wyn-check"><input type="checkbox" name="verify_ssl" value="1"<?= $m['verify_ssl'] ? ' checked' : '' ?>> Fail when the SSL certificate is invalid or expired</label>
  </div>

  <div data-wyn-show="keyword" class="wyn-grid2">
    <label class="wyn-field<?= $cls('keyword') ?>">
      <span>Text to look for</span>
      <input name="keyword" value="<?= $v->e($m['keyword'] ?? '') ?>" placeholder="Add to cart">
      <?= $err('keyword') ?>
    </label>
    <label class="wyn-field">
      <span>Alert when the text is</span>
      <select name="keyword_absent">
        <option value="0"<?= !$m['keyword_absent'] ? ' selected' : '' ?>>Missing from the page</option>
        <option value="1"<?= $m['keyword_absent'] ? ' selected' : '' ?>>Found on the page</option>
      </select>
    </label>
  </div>

  <div data-wyn-show="tcp" class="wyn-grid2">
    <label class="wyn-field<?= $cls('host') ?>">
      <span>Host</span>
      <input name="host" value="<?= $v->e($m['host'] ?? '') ?>" placeholder="db.example.com">
      <?= $err('host') ?>
    </label>
    <label class="wyn-field<?= $cls('port') ?>">
      <span>Port</span>
      <input name="port" type="number" min="1" max="65535" value="<?= $v->e($m['port'] ?? '') ?>" placeholder="5432">
      <?= $err('port') ?>
    </label>
  </div>

  <div class="wyn-grid3">
    <label class="wyn-field">
      <span data-wyn-show="http keyword tcp">Check every</span>
      <span data-wyn-show="heartbeat">Expect a check-in every</span>
      <select name="interval_sec"><?php foreach (Monitors::INTERVALS as $sec => $label): ?><option value="<?= $sec ?>"<?= (int) $m['interval_sec'] === $sec ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
    </label>
    <label class="wyn-field" data-wyn-show="http keyword tcp">
      <span>Timeout</span>
      <select name="timeout_sec"><?php foreach ([5, 10, 15, 30, 60] as $t): ?><option value="<?= $t ?>"<?= (int) $m['timeout_sec'] === $t ? ' selected' : '' ?>><?= $t ?> seconds</option><?php endforeach ?></select>
    </label>
    <label class="wyn-field" data-wyn-show="http keyword tcp">
      <span>Alert after</span>
      <select name="confirmations"><?php foreach ([1, 2, 3, 5] as $c): ?><option value="<?= $c ?>"<?= (int) $m['confirmations'] === $c ? ' selected' : '' ?>><?= $c ?> failed <?= $c === 1 ? 'check' : 'checks in a row' ?></option><?php endforeach ?></select>
    </label>
    <label class="wyn-field" data-wyn-show="heartbeat">
      <span>Grace period</span>
      <select name="grace_min"><?php foreach ([0 => 'None', 1 => '1 minute', 5 => '5 minutes', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour'] as $g => $label): ?><option value="<?= $g ?>"<?= (int) ($m['grace_min'] ?? 5) === $g ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
    </label>
  </div>

  <label class="wyn-check"><input type="checkbox" name="is_public" value="1"<?= $m['is_public'] ? ' checked' : '' ?>> Show on the public status page</label>

  <div class="wyn-form-actions">
    <button class="wyn-btn wyn-primary" type="submit"><?= $isNew ? 'Add monitor' : 'Save changes' ?></button>
    <a class="wyn-btn" href="<?= $v->url($isNew ? 'admin' : 'admin/monitors/' . $m['id']) ?>">Cancel</a>
  </div>
</form>
