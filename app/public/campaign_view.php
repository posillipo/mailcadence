<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT c.*, l.name AS list_name FROM campaigns c JOIN contact_lists l ON l.id = c.list_id WHERE c.id = ?');
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) {
    flash('error', 'Campagna non trovata.');
    redirect('/campaigns.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'start' && $campaign['status'] === 'draft') {
        $pdo->prepare("UPDATE campaigns SET status = 'running', started_at = NOW(), next_batch_at = NOW() WHERE id = ?")->execute([$id]);
        flash('success', 'Campagna avviata: il primo gruppo partirà al prossimo passaggio del worker (entro 5 minuti).');
    } elseif ($action === 'pause' && $campaign['status'] === 'running') {
        $pdo->prepare("UPDATE campaigns SET status = 'paused' WHERE id = ?")->execute([$id]);
        flash('success', 'Campagna messa in pausa.');
    } elseif ($action === 'resume' && $campaign['status'] === 'paused') {
        $pdo->prepare("UPDATE campaigns SET status = 'running', next_batch_at = NOW() WHERE id = ?")->execute([$id]);
        flash('success', 'Campagna ripresa.');
    } elseif ($action === 'cancel' && in_array($campaign['status'], ['draft', 'running', 'paused'], true)) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE campaigns SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE campaign_recipients SET status = 'skipped', error = 'campagna annullata' WHERE campaign_id = ? AND status = 'pending'")->execute([$id]);
        $pdo->commit();
        flash('success', 'Campagna annullata. I destinatari non ancora raggiunti non riceveranno il messaggio.');
    }
    redirect('/campaign_view.php?id=' . $id);
}

$counts = $pdo->prepare(
    "SELECT status, COUNT(*) AS n FROM campaign_recipients WHERE campaign_id = ? GROUP BY status"
);
$counts->execute([$id]);
$byStatus = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
foreach ($counts->fetchAll() as $row) {
    $byStatus[$row['status']] = (int) $row['n'];
}
$total = array_sum($byStatus);
$pct = $total > 0 ? round($byStatus['sent'] / $total * 100) : 0;

$recent = $pdo->prepare(
    "SELECT cr.*, c.email FROM campaign_recipients cr
     JOIN contacts c ON c.id = cr.contact_id
     WHERE cr.campaign_id = ? AND cr.status IN ('sent','failed')
     ORDER BY cr.sent_at DESC LIMIT 20"
);
$recent->execute([$id]);
$recent = $recent->fetchAll();

$pageTitle = e($campaign['name']) . ' — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1><?= e($campaign['name']) ?> <span class="badge badge-<?= e($campaign['status']) ?>"><?= e($campaign['status']) ?></span></h1>

<div class="card">
  <div class="stats">
    <div class="stat"><div class="value"><?= $byStatus['sent'] ?></div><div class="label">Inviati</div></div>
    <div class="stat"><div class="value"><?= $byStatus['pending'] ?></div><div class="label">In coda</div></div>
    <div class="stat"><div class="value"><?= $byStatus['failed'] ?></div><div class="label">Falliti</div></div>
    <div class="stat"><div class="value"><?= $byStatus['skipped'] ?></div><div class="label">Saltati</div></div>
  </div>
  <div class="progress" style="margin-top:12px"><div style="width:<?= $pct ?>%"></div></div>
  <p class="hint"><?= $pct ?>% completato su <?= $total ?> destinatari totali.</p>

  <table style="margin-top:12px">
    <tr><th>Lista</th><td><?= e($campaign['list_name']) ?></td></tr>
    <tr><th>Oggetto</th><td><?= e($campaign['subject']) ?></td></tr>
    <tr><th>Mittente</th><td><?= e($campaign['from_name']) ?> &lt;<?= e($campaign['from_email']) ?>&gt;</td></tr>
    <tr><th>Cadenza</th><td><?= (int) $campaign['batch_size'] ?> contatti ogni <?= (int) $campaign['interval_minutes'] ?> minuti</td></tr>
    <tr><th>Prossimo gruppo</th><td><?= formatDateIt($campaign['next_batch_at']) ?></td></tr>
    <tr><th>Avviata il</th><td><?= formatDateIt($campaign['started_at']) ?></td></tr>
    <tr><th>Completata il</th><td><?= formatDateIt($campaign['completed_at']) ?></td></tr>
  </table>

  <div class="actions">
    <?php if ($campaign['status'] === 'draft'): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="start">
        <button class="btn" type="submit">Avvia campagna</button></form>
    <?php endif; ?>
    <?php if ($campaign['status'] === 'running'): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="pause">
        <button class="btn btn-secondary" type="submit">Metti in pausa</button></form>
    <?php endif; ?>
    <?php if ($campaign['status'] === 'paused'): ?>
      <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="resume">
        <button class="btn" type="submit">Riprendi</button></form>
    <?php endif; ?>
    <?php if (in_array($campaign['status'], ['draft', 'running', 'paused'], true)): ?>
      <form method="post" onsubmit="return confirm('Annullare la campagna? I destinatari non ancora raggiunti non riceveranno più il messaggio.');">
        <?= csrfField() ?><input type="hidden" name="action" value="cancel">
        <button class="btn btn-danger" type="submit">Annulla campagna</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Anteprima messaggio</h2>
  <div style="border:1px solid var(--border); border-radius:6px; padding:12px">
    <?= $campaign['body_html'] ?>
  </div>
</div>

<div class="card">
  <h2>Invii recenti</h2>
  <table>
    <thead><tr><th>Email</th><th>Stato</th><th>Quando</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e($r['email']) ?></td>
        <td><span class="badge badge-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td><?= formatDateIt($r['sent_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($recent)): ?>
      <tr><td colspan="3" class="hint">Nessun invio ancora.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
