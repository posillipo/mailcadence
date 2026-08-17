<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();

$totalContacts = (int) $pdo->query("SELECT COUNT(*) FROM contacts WHERE status = 'active'")->fetchColumn();
$unsubscribedContacts = (int) $pdo->query("SELECT COUNT(*) FROM contacts WHERE status = 'unsubscribed'")->fetchColumn();
$totalLists = (int) $pdo->query("SELECT COUNT(*) FROM contact_lists")->fetchColumn();
$runningCampaigns = (int) $pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'running'")->fetchColumn();

$campaigns = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = c.id) AS total,
            (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = c.id AND status = 'sent') AS sent
     FROM campaigns c
     ORDER BY c.created_at DESC
     LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Dashboard</h1>

<div class="stats card">
  <div class="stat"><div class="value"><?= $totalContacts ?></div><div class="label">Contatti attivi</div></div>
  <div class="stat"><div class="value"><?= $unsubscribedContacts ?></div><div class="label">Disiscritti</div></div>
  <div class="stat"><div class="value"><?= $totalLists ?></div><div class="label">Liste</div></div>
  <div class="stat"><div class="value"><?= $runningCampaigns ?></div><div class="label">Campagne in corso</div></div>
</div>

<div class="card">
  <h2>Campagne recenti</h2>
  <?php if (empty($campaigns)): ?>
    <p class="hint">Nessuna campagna ancora. <a href="/campaign_new.php">Creane una</a>.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Nome</th><th>Stato</th><th>Avanzamento</th><th>Prossimo batch</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($campaigns as $c): $pct = $c['total'] > 0 ? round($c['sent'] / $c['total'] * 100) : 0; ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><span class="badge badge-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
          <td><?= (int) $c['sent'] ?>/<?= (int) $c['total'] ?> (<?= $pct ?>%)</td>
          <td><?= formatDateIt($c['next_batch_at']) ?></td>
          <td><a href="/campaign_view.php?id=<?= (int) $c['id'] ?>">Apri</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
