<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../config/app.php';
}
?><!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="assets/css/app.css?v=78" />
</head>
<body>
