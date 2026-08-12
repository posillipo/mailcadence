<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();
$lists = $pdo->query('SELECT id, name FROM contact_lists ORDER BY name')->fetchAll();
$error = null;
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $listId = (int) ($_POST['list_id'] ?? 0);

    if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Carica un file CSV valido.';
    } else {
        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$handle) {
            $error = 'Impossibile leggere il file caricato.';
        } else {
            $insert = $pdo->prepare('INSERT INTO contacts (email, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = IF(VALUES(name) IS NOT NULL, VALUES(name), name)');
            $addToList = $pdo->prepare('INSERT IGNORE INTO contact_list_members (list_id, contact_id) SELECT ?, id FROM contacts WHERE email = ?');

            $imported = 0;
            $invalid = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue; // riga vuota
                }
                $email = trim((string) ($row[0] ?? ''));
                $name = trim((string) ($row[1] ?? ''));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalid++;
                    continue; // salta anche la eventuale riga di intestazione "email,name"
                }

                $insert->execute([$email, $name !== '' ? $name : null]);
                if ($listId > 0) {
                    $addToList->execute([$listId, $email]);
                }
                $imported++;
            }
            fclose($handle);

            $summary = "{$imported} contatti importati/aggiornati, {$invalid} righe scartate (email non valida).";
            flash('success', $summary);
            redirect('/contacts.php');
        }
    }
}

$pageTitle = 'Importa contatti — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Importa contatti da CSV</h1>
<div class="card">
  <p class="hint">Il file deve avere una colonna "email" e, opzionalmente, una colonna "nome" (es. <code>mario@esempio.it,Mario Rossi</code>). Se il tuo file ha una riga di intestazione verrà scartata automaticamente perché non contiene un'email valida. I contatti già presenti vengono aggiornati, non duplicati.</p>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <label for="csv">File CSV</label>
    <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
    <label for="list_id">Aggiungi anche a una lista (opzionale)</label>
    <select id="list_id" name="list_id">
      <option value="0">Nessuna lista</option>
      <?php foreach ($lists as $l): ?>
        <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="actions">
      <button class="btn" type="submit">Importa</button>
      <a class="btn btn-secondary" href="/contacts.php">Annulla</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
