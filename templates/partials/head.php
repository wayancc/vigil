<?php /** Shared <head> assets. Inter is bundled: no requests to Google. @var Vigil\View $v */ ?>
<link rel="icon" href="<?= $v->asset('wyn-favicon.svg') ?>" type="image/svg+xml">
<link rel="preload" href="<?= $v->url('assets/fonts/wyn-inter-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $v->asset('wyn-tokens.css') ?>">
<link rel="stylesheet" href="<?= $v->asset('wyn-vigil.css') ?>">
<script src="<?= $v->asset('wyn-vigil.js') ?>" defer></script>
