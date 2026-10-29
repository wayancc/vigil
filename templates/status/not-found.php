<!doctype html>
<html lang="en" data-wyn-theme="<?= $v->e($settings->get('theme')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page not found · <?= $v->e($settings->get('site_name')) ?></title>
<?= $v->partial('partials/head') ?>
</head>
<body class="wyn-status">
<div class="wyn-sp">
  <section class="wyn-sp-state wyn-state-unknown">
    <h1 class="wyn-display">Page not found<span class="wyn-dot">.</span></h1>
    <p>There’s nothing at this address. <a href="<?= $v->url('') ?>">Go to the status page</a>.</p>
  </section>
</div>
</body>
</html>
