<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();
$listId = (int) ($_GET['id'] ?? 0);

$listStmt = $pdo->prepare('SELECT * FROM contact_lists WHERE id = ?');
$listStmt->execute([$listId]);
$list = $listStmt->fetch();
if (!$list) {
    flash('error', 'Lista non trovata.');
    redirect('/lists.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_all_active') {
        $pdo->prepare(
            "INSERT IGNORE INTO contact_list_members (list_id, contact_id)
             SELECT ?, id FROM contacts WHERE status = 'active'"
        )->execute([$listId]);
        flash('success', 'Tutti i contatti attivi sono stati aggiunti alla lista.');
    } elseif ($action === 'add' && !empty($_POST['contact_id'])) {
        $pdo->prepare('INSERT IGNORE INTO contact_list_members (list_id, contact_id) VALUES (?, ?)')
            ->execute([$listId, (int) $_POST['contact_id']]);
        flash('success', 'Contatto aggiunto alla lista.');
    } elseif ($action === 'remove' && !empty($_POST['contact_id'])) {
        $pdo->prepare('DELETE FROM contact_list_members WHERE list_id = ? AND contact_id = ?')
            ->execute([$listId, (int) $_POST['contact_id']]);
        flash('success', 'Contatto rimosso dalla lista.');
    } elseif ($action === 'remove_all') {
        $pdo->prepare('DELETE FROM contact_list_members WHERE list_id = ?')->execute([$listId]);
        flash('success', 'Lista svuotata: tutti i contatti sono stati rimossi.');
    }
    redirect('/list_view.php?id=' . $listId);
}

$members = $pdo->prepare(
    "SELECT c.* FROM contacts c
     JOIN contact_list_members m ON m.contact_id = c.id
     WHERE m.list_id = ? ORDER BY c.email"
);
$members->execute([$listId]);
$members = $members->fetchAll();

$q = trim($_GET['q'] ?? '');
$searchResults = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $search = $pdo->prepare(
        "SELECT c.* FROM contacts c
         WHERE (c.email LIKE ? OR c.name LIKE ?)
           AND c.id NOT IN (SELECT contact_id FROM contact_list_members WHERE list_id = ?)
         ORDER BY c.email LIMIT 20"
    );
    $search->execute([$like, $like, $listId]);
    $searchResults = $search->fetchAll();
}

$pageTitle = e($list['name']) . ' — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1><?= e($list['name']) ?> <span class="hint">(<?= count($members) ?> contatti)</span></h1>
<?php if ($list['description']): ?><p class="hint"><?= e($list['description']) ?></p><?php endif; ?>

<div class="card">
  <h2>Aggiungi contatti</h2>
  <form method="post" style="margin-bottom:16px">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_all_active">
    <button class="btn" type="submit" onclick="return confirm('Aggiungere tutti i contatti attivi a questa lista?');">Aggiungi tutti i contatti attivi</button>
  </form>

  <form method="get">
    <label for="q">Cerca un contatto da aggiungere</label>
    <input type="hidden" name="id" value="<?= $listId ?>">
    <input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="email o nome">
    <div class="actions"><button class="btn btn-secondary" type="submit">Cerca</button></div>
  </form>
  <?php if ($q !== ''): ?>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Email</th><th>Nome</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($searchResults as $c): ?>
        <tr>
          <td><?= e($c['email']) ?></td>
          <td><?= e($c['name'] ?: '—') ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
              <button class="btn btn-secondary" type="submit">Aggiungi</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($searchResults)): ?>
        <tr><td colspan="3" class="hint">Nessun contatto trovato (o già presente nella lista).</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Contatti nella lista</h2>
  <?php if (!empty($members)): ?>
    <form method="post" style="margin-bottom:16px">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="remove_all">
      <button class="btn btn-danger" type="submit" onclick="return confirm('Svuotare completamente questa lista? Verranno rimossi tutti i <?= count($members) ?> contatti. L\'azione non è reversibile.');">Svuota lista</button>
    </form>
  <?php endif; ?>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Email</th><th>Nome</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($members as $c): ?>
      <tr>
        <td><?= e($c['email']) ?></td>
        <td><?= e($c['name'] ?: '—') ?></td>
        <td><span class="badge badge-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
        <td>
          <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-secondary" type="submit">Rimuovi</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($members)): ?>
      <tr><td colspan="4" class="hint">Ancora nessun contatto in questa lista.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
