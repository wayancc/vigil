<?php $v->layout('layout/admin'); ?>
<div class="wyn-auth">
  <div class="wyn-auth-brand">Vigil<span class="wyn-dot">.</span></div>
  <h1 class="wyn-display">Set up your status page<span class="wyn-dot">.</span></h1>
  <p class="wyn-muted">Create the admin account. You can add more monitors and alert channels next.</p>

  <form method="post" class="wyn-stack">
    <?= $v->csrf() ?>
    <label class="wyn-field">
      <span>Status page name</span>
      <input name="site_name" value="<?= $v->e($old['site_name'] ?? '') ?>" placeholder="Acme Status" autocomplete="organization">
    </label>
    <label class="wyn-field<?= isset($errors['email']) ? ' wyn-has-error' : '' ?>">
      <span>Your email</span>
      <input type="email" name="email" value="<?= $v->e($old['email'] ?? '') ?>" required autocomplete="email">
      <?php if (isset($errors['email'])): ?><small class="wyn-error"><?= $v->e($errors['email']) ?></small><?php endif ?>
    </label>
    <label class="wyn-field<?= isset($errors['password']) ? ' wyn-has-error' : '' ?>">
      <span>Password</span>
      <input type="password" name="password" required minlength="10" autocomplete="new-password">
      <small><?= isset($errors['password']) ? '<span class="wyn-error">' . $v->e($errors['password']) . '</span>' : 'At least 10 characters.' ?></small>
    </label>
    <button class="wyn-btn wyn-primary" type="submit">Create account</button>
  </form>
</div>
