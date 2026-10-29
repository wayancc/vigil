<?php
use Vigil\Notify\Channels;

$v->layout('layout/admin');
[$typeLabel, $fields] = Channels::TYPES[$c['type']];
$isNew = empty($c['id']);
$help = [
    'telegram' => 'Create a bot with @BotFather to get the token. Add the bot to your group, send it a message, then open api.telegram.org/bot<token>/getUpdates to find the chat ID.',
    'slack'    => 'In Slack, add the “Incoming Webhooks” app to a channel and copy its webhook URL.',
    'discord'  => 'In Discord, open Channel settings → Integrations → Webhooks → New webhook, then copy the URL.',
    'ntfy'     => 'Install the ntfy app and subscribe to a hard-to-guess topic name. Anyone who knows the topic on ntfy.sh can read it.',
    'webhook'  => 'Vigil POSTs a JSON body with event, title, message, time, link and monitor. With a secret, the body is signed with HMAC-SHA256 in the X-Wyn-Signature header.',
    'email'    => 'Separate several addresses with commas. For reliable delivery, add SMTP details under Settings.',
][$c['type']] ?? '';
?>
<div class="wyn-page-head">
  <div>
    <p class="wyn-crumb"><a href="<?= $v->url('admin/channels') ?>">Alerts</a></p>
    <h1 class="wyn-display"><?= $isNew ? 'Add ' . $v->e($typeLabel) : 'Edit ' . $v->e($c['name']) ?><span class="wyn-dot">.</span></h1>
  </div>
</div>

<form method="post" action="<?= $v->url($isNew ? 'admin/channels' : 'admin/channels/' . $c['id']) ?>" class="wyn-form wyn-narrow">
  <?= $v->csrf() ?>
  <input type="hidden" name="type" value="<?= $v->e($c['type']) ?>">
  <?php if ($help): ?><p class="wyn-hint"><?= $v->e($help) ?></p><?php endif ?>

  <label class="wyn-field">
    <span>Name</span>
    <input name="name" value="<?= $v->e($c['name']) ?>" placeholder="<?= $v->e($typeLabel) ?>" maxlength="80">
  </label>

  <?php foreach ($fields as $field => [$label, $placeholder, $required]): ?>
  <label class="wyn-field<?= isset($errors[$field]) ? ' wyn-has-error' : '' ?>">
    <span><?= $v->e($label) ?></span>
    <input name="config[<?= $v->e($field) ?>]" value="<?= $v->e($c['config'][$field] ?? '') ?>" placeholder="<?= $v->e($placeholder) ?>"<?= $required ? ' required' : '' ?> autocomplete="off" spellcheck="false">
    <?php if (isset($errors[$field])): ?><small class="wyn-error"><?= $v->e($errors[$field]) ?></small><?php endif ?>
  </label>
  <?php endforeach ?>

  <label class="wyn-check"><input type="checkbox" name="enabled" value="1"<?= $c['enabled'] ? ' checked' : '' ?>> Send alerts to this channel</label>

  <div class="wyn-form-actions">
    <button class="wyn-btn wyn-primary" type="submit"><?= $isNew ? 'Add channel' : 'Save changes' ?></button>
    <a class="wyn-btn" href="<?= $v->url('admin/channels') ?>">Cancel</a>
  </div>
</form>

<?php if (!$isNew): ?>
<section class="wyn-danger">
  <form method="post" action="<?= $v->url('admin/channels/' . $c['id'] . '/delete') ?>" data-wyn-confirm="Delete <?= $v->e($c['name']) ?>?">
    <?= $v->csrf() ?><button class="wyn-btn wyn-danger" type="submit">Delete channel</button>
  </form>
</section>
<?php endif ?>
