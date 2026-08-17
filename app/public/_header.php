<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'MailCadence') ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/dashboard.php">MailCadence</a>
  <nav>
    <a href="/dashboard.php">Dashboard</a>
    <a href="/contacts.php">Contatti</a>
    <a href="/lists.php">Liste</a>
    <a href="/campaigns.php">Campagne</a>
    <a href="/smtp_settings.php">SMTP</a>
    <a href="/cron_status.php">Worker</a>
  </nav>
  <a class="logout" href="/logout.php">Esci</a>
</header>
<main class="container">
<?php foreach (takeFlashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
