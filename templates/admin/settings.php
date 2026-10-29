<?php
use Vigil\Format;

$v->layout('layout/admin');
?>
<div class="wyn-page-head"><h1 class="wyn-display">Settings<span class="wyn-dot">.</span></h1></div>

<form method="post" action="<?= $v->url('admin/settings') ?>" class="wyn-form">
  <?= $v->csrf() ?>

  <section class="wyn-panel">
    <h2>Status page</h2>
    <div class="wyn-grid2">
      <label class="wyn-field"><span>Name</span><input name="site_name" value="<?= $v->e($s['site_name']) ?>" maxlength="80"></label>
      <label class="wyn-field"><span>Your website <small class="wyn-muted">adds a “Back to site” link</small></span><input type="url" name="site_url" value="<?= $v->e($s['site_url']) ?>" placeholder="https://example.com"></label>
    </div>
    <label class="wyn-field"><span>Description <small class="wyn-muted">optional</small></span><input name="site_description" value="<?= $v->e($s['site_description']) ?>" maxlength="200" placeholder="Live status of Acme’s website and API"></label>
  </section>

  <section class="wyn-panel" id="theme">
    <h2>Look</h2>
    <p class="wyn-muted">Applies to the dashboard and the public status page.</p>
    <div class="wyn-grid2">
      <label class="wyn-check"><input type="radio" name="theme" value="wayan"<?= $s['theme'] !== 'neutral' ? ' checked' : '' ?>> <span><strong>Bold</strong><small class="wyn-block wyn-muted">Black and white, heavy uppercase headlines, square corners.</small></span></label>
      <label class="wyn-check"><input type="radio" name="theme" value="neutral"<?= $s['theme'] === 'neutral' ? ' checked' : '' ?>> <span><strong>Neutral</strong><small class="wyn-block wyn-muted">Quieter type and rounded corners. Fits alongside any brand.</small></span></label>
    </div>
  </section>

  <section class="wyn-panel">
    <h2>Email (SMTP)</h2>
    <p class="wyn-muted">Used by email alert channels. Without SMTP, Vigil falls back to PHP’s mail(), which many hosts block or send to spam.</p>
    <div class="wyn-grid3">
      <label class="wyn-field"><span>Host</span><input name="smtp_host" value="<?= $v->e($s['smtp_host']) ?>" placeholder="smtp.gmail.com"></label>
      <label class="wyn-field"><span>Port</span><input name="smtp_port" type="number" value="<?= $v->e($s['smtp_port']) ?>"></label>
      <label class="wyn-field"><span>Encryption</span>
        <select name="smtp_encryption"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (25)'] as $k => $label): ?><option value="<?= $k ?>"<?= $s['smtp_encryption'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select>
      </label>
    </div>
    <div class="wyn-grid3">
      <label class="wyn-field"><span>Username</span><input name="smtp_user" value="<?= $v->e($s['smtp_user']) ?>" autocomplete="off"></label>
      <label class="wyn-field"><span>Password</span><input type="password" name="smtp_pass" placeholder="<?= $s['smtp_pass'] !== '' ? '•••••••• (saved)' : '' ?>" autocomplete="new-password"></label>
      <label class="wyn-field"><span>From address</span><input name="smtp_from" value="<?= $v->e($s['smtp_from']) ?>" placeholder="Vigil &lt;alerts@example.com&gt;"></label>
    </div>
  </section>

  <section class="wyn-panel">
    <h2>Checks</h2>
    <p>Last check run: <strong><?= $cron['last'] ? $v->e(Format::ago($cron['last'])) : 'never' ?></strong></p>
    <dl class="wyn-howto">
      <dt>Cron line</dt>
      <dd><code class="wyn-copyable" data-wyn-copy><?= $v->e($cron['command']) ?></code></dd>
      <dt>Web-cron URL (for hosts without cron). Keep it secret.</dt>
      <dd><code class="wyn-copyable" data-wyn-copy><?= $v->e($cron['url']) ?></code></dd>
    </dl>
    <label class="wyn-check"><input type="checkbox" name="regenerate_cron" value="1"> Replace the web-cron URL with a new one</label>
    <label class="wyn-field wyn-inline"><span>Keep detailed check history for</span>
      <select name="retention_days"><?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>"<?= (int) $s['retention_days'] === $d ? ' selected' : '' ?>><?= $d ?> days</option><?php endforeach ?></select>
    </label>
    <p class="wyn-muted wyn-small">Daily uptime numbers are kept forever. Only individual checks are deleted.</p>
  </section>

  <div class="wyn-form-actions"><button class="wyn-btn wyn-primary" type="submit">Save settings</button></div>
</form>

<form method="post" action="<?= $v->url('admin/settings/password') ?>" class="wyn-form wyn-panel" id="account">
  <?= $v->csrf() ?>
  <h2>Your account</h2>
  <p class="wyn-muted">Signed in as <?= $v->e($user['email'] ?? '') ?></p>
  <div class="wyn-grid2">
    <label class="wyn-field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
    <label class="wyn-field"><span>New password</span><input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
  </div>
  <div class="wyn-form-actions"><button class="wyn-btn" type="submit">Change password</button></div>
</form>
