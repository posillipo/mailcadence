<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();

$campaigns = $pdo->query(
    "SELECT c.*, l.name AS list_name,
            (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = c.id) AS total,
            (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = c.id AND status = 'sent') AS sent
     FROM campaigns c
     JOIN contact_lists l ON l.id = c.list_id
     ORDER BY c.created_at DESC"
)->fetchAll();

$pageTitle = 'Campagne — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Campagne</h1>
<div class="actions" style="margin-bottom:16px">
  <a class="btn" href="/campaign_new.php">+ Nuova campagna</a>
</div>

<div class="card">
  <div class="table-scroll">
  <table>
    <thead><tr><th>Nome</th><th>Lista</th><th>Stato</th><th>Avanzamento</th><th>Cadenza</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($campaigns as $c): $pct = $c['total'] > 0 ? round($c['sent'] / $c['total'] * 100) : 0; ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['list_name']) ?></td>
        <td><span class="badge badge-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
        <td><?= (int) $c['sent'] ?>/<?= (int) $c['total'] ?> (<?= $pct ?>%)</td>
        <td><?= (int) $c['batch_size'] ?> ogni <?= (int) $c['interval_minutes'] ?> min</td>
        <td><a href="/campaign_view.php?id=<?= (int) $c['id'] ?>">Apri</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($campaigns)): ?>
      <tr><td colspan="6" class="hint">Nessuna campagna ancora.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
