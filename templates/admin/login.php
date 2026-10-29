<?php $v->layout('layout/admin'); ?>
<div class="wyn-auth">
  <div class="wyn-auth-brand"><?= $v->e($settings->get('site_name')) ?><span class="wyn-dot">.</span></div>
  <h1 class="wyn-display">Sign in<span class="wyn-dot">.</span></h1>
  <?php if ($error): ?><div class="wyn-flash wyn-flash-error" role="alert"><?= $v->e($error) ?></div><?php endif ?>
  <form method="post" class="wyn-stack">
    <?= $v->csrf() ?>
    <label class="wyn-field">
      <span>Email</span>
      <input type="email" name="email" value="<?= $v->e($email) ?>" required autofocus autocomplete="username">
    </label>
    <label class="wyn-field">
      <span>Password</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="wyn-btn wyn-primary" type="submit">Sign in</button>
  </form>
  <p class="wyn-muted wyn-small">Forgot your password? Run <code>php bin/vigil user:password --email=you@example.com</code> on the server.</p>
</div>
