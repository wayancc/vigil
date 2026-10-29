<?php
/** @var Vigil\View $v @var string $content @var string $title @var string $nav @var bool $bare */
$links = ['dashboard' => ['Monitors', 'admin'], 'incidents' => ['Incidents', 'admin/incidents'], 'channels' => ['Alerts', 'admin/channels'], 'notices' => ['Announcements', 'admin/notices'], 'settings' => ['Settings', 'admin/settings']];
?><!doctype html>
<html lang="en" data-wyn-theme="<?= $v->e($settings->get('theme')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $v->e($title) ?> · <?= $v->e($settings->get('site_name')) ?></title>
<?= $v->partial('partials/head') ?>
</head>
<body class="wyn-admin<?= $bare ? ' wyn-bare' : '' ?>">
<?php if (!$bare): ?>
<header class="wyn-adminbar">
  <div class="wyn-adminbar-inner">
    <a class="wyn-brand" href="<?= $v->url('admin') ?>">
      <span>Vigil<span class="wyn-dot">.</span></span>
    </a>
    <nav class="wyn-adminnav" aria-label="Main">
      <?php foreach ($links as $key => [$label, $href]): ?>
      <a href="<?= $v->url($href) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
      <?php endforeach ?>
    </nav>
    <div class="wyn-adminbar-end">
      <a class="wyn-quiet" href="<?= $v->url('') ?>" target="_blank" rel="noopener">Status page</a>
      <form method="post" action="<?= $v->url('admin/logout') ?>"><?= $v->csrf() ?><button class="wyn-linklike" type="submit">Sign out</button></form>
    </div>
  </div>
</header>
<?php endif ?>

<main class="wyn-wrap">
  <?php foreach ($v->flashes() as $flash): ?>
  <div class="wyn-flash wyn-flash-<?= $v->e($flash['type']) ?>" role="status"><?= $v->e($flash['message']) ?></div>
  <?php endforeach ?>
  <?= $content ?>
</main>
</body>
</html>
