<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($name === '') {
        $error = 'Il nome della lista è obbligatorio.';
    } else {
        $pdo->prepare('INSERT INTO contact_lists (name, description) VALUES (?, ?)')
            ->execute([$name, $description !== '' ? $description : null]);
        flash('success', 'Lista creata.');
        redirect('/lists.php');
    }
}

$lists = $pdo->query(
    "SELECT l.*, (SELECT COUNT(*) FROM contact_list_members WHERE list_id = l.id) AS member_count
     FROM contact_lists l ORDER BY l.created_at DESC"
)->fetchAll();

$pageTitle = 'Liste — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Liste destinatari</h1>

<div class="card">
  <h2>Nuova lista</h2>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrfField() ?>
    <label for="name">Nome</label>
    <input type="text" id="name" name="name" required>
    <label for="description">Descrizione (opzionale)</label>
    <input type="text" id="description" name="description">
    <div class="actions"><button class="btn" type="submit">Crea lista</button></div>
  </form>
</div>

<div class="card">
  <div class="table-scroll">
  <table>
    <thead><tr><th>Nome</th><th>Descrizione</th><th>Contatti</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lists as $l): ?>
      <tr>
        <td><?= e($l['name']) ?></td>
        <td><?= e($l['description'] ?: '—') ?></td>
        <td><?= (int) $l['member_count'] ?></td>
        <td><a href="/list_view.php?id=<?= (int) $l['id'] ?>">Gestisci</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($lists)): ?>
      <tr><td colspan="4" class="hint">Nessuna lista ancora.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
