<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle_status' && $id > 0) {
        $current = $pdo->prepare('SELECT status FROM contacts WHERE id = ?');
        $current->execute([$id]);
        $status = $current->fetchColumn();
        if ($status !== false) {
            $newStatus = $status === 'active' ? 'unsubscribed' : 'active';
            $pdo->prepare('UPDATE contacts SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            flash('success', 'Stato del contatto aggiornato.');
        }
    } elseif ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
        flash('success', 'Contatto eliminato.');
    }
    redirect('/contacts.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

if ($q !== '') {
    $like = '%' . $q . '%';
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE email LIKE ? OR name LIKE ?');
    $countStmt->execute([$like, $like]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT * FROM contacts WHERE email LIKE ? OR name LIKE ? ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute([$like, $like]);
} else {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    $stmt = $pdo->query('SELECT * FROM contacts ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
}
$contacts = $stmt->fetchAll();
$totalPages = max(1, (int) ceil($total / $perPage));

$pageTitle = 'Contatti — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Contatti <span class="hint">(<?= $total ?>)</span></h1>

<div class="card">
  <form method="get" style="display:flex; gap:8px; align-items:flex-end;">
    <div style="flex:1"><label for="q">Cerca</label><input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="email o nome"></div>
    <div><button class="btn btn-secondary" type="submit">Cerca</button></div>
  </form>
  <div class="actions">
    <a class="btn" href="/contact_add.php">+ Aggiungi contatto</a>
    <a class="btn btn-secondary" href="/contacts_import.php">Importa da CSV</a>
  </div>
</div>

<div class="card">
  <table>
    <thead><tr><th>Email</th><th>Nome</th><th>Stato</th><th>Aggiunto</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($contacts as $c): ?>
      <tr>
        <td><?= e($c['email']) ?></td>
        <td><?= e($c['name'] ?: '—') ?></td>
        <td><span class="badge badge-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
        <td><?= formatDateIt($c['created_at']) ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-secondary" type="submit"><?= $c['status'] === 'active' ? 'Disiscrivi' : 'Riattiva' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Eliminare definitivamente questo contatto?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-danger" type="submit">Elimina</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($contacts)): ?>
      <tr><td colspan="5" class="hint">Nessun contatto trovato.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
    <div class="actions">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a class="btn btn-secondary" href="?page=<?= $p ?>&q=<?= urlencode($q) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
