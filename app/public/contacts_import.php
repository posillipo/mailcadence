<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();
$lists = $pdo->query('SELECT id, name FROM contact_lists ORDER BY name')->fetchAll();

// I file caricati durante il passaggio 1 (upload) restano qui finché non vengono confermati al
// passaggio 2 (mappatura colonne): l'HTML dell'upload multipart non può essere "ripetuto"
// automaticamente da un secondo submit, quindi il file va salvato lato server tra le due request.
define('IMPORT_TMP_DIR', sys_get_temp_dir() . '/mailcadence-imports');

// Legge intestazione (o prime righe, se il file non ne ha una), un'anteprima di massimo 5 righe di
// dati e prova a indovinare quale colonna è l'email e quale il nome. Usata sia subito dopo
// l'upload sia se la conferma della mappatura fallisce (per non far perdere l'anteprima già
// caricata all'utente).
function buildImportPreview(string $path, string $delimiter, bool $hasHeader): ?array {
    $handle = openCsvSkippingBom($path);
    $firstRow = $handle ? fgetcsv($handle, 0, $delimiter) : false;
    if ($firstRow === false) {
        if ($handle) {
            fclose($handle);
        }
        return null;
    }

    $sampleRows = [];
    if (!$hasHeader) {
        // senza intestazione la prima riga letta è già un dato: la mostriamo nell'anteprima
        // invece di scartarla
        $sampleRows[] = $firstRow;
    }
    for ($i = 0; $i < 5 && ($row = fgetcsv($handle, 0, $delimiter)) !== false; $i++) {
        $sampleRows[] = $row;
    }
    fclose($handle);

    $columns = [];
    foreach ($firstRow as $i => $value) {
        $columns[$i] = $hasHeader ? trim((string) $value) : ('Colonna ' . ($i + 1));
    }

    $guessedEmail = guessColumnByLabel($columns, ['email', 'e-mail', 'mail']);
    if ($guessedEmail === null && !empty($sampleRows)) {
        foreach ($sampleRows[0] as $i => $val) {
            if (str_contains((string) $val, '@')) {
                $guessedEmail = $i;
                break;
            }
        }
    }
    $guessedName = guessColumnByLabel($columns, ['nome', 'name', 'nominativo', 'cognome']);

    $totalLines = count(file($path)) - ($hasHeader ? 1 : 0);

    return [
        'columns' => $columns,
        'rows' => $sampleRows,
        'guessed_email' => $guessedEmail,
        'guessed_name' => $guessedName,
        'total_lines' => max(0, $totalLines),
    ];
}

$error = null;
$preview = null;
$step = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['step'] ?? '') : '';

// Un GET su questa pagina (es. l'utente torna indietro o clicca "Ricomincia") annulla
// un'eventuale importazione lasciata a metà, così non restano file temporanei orfani.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['import']['path'])) {
    @unlink($_SESSION['import']['path']);
    unset($_SESSION['import']);
}

if ($step === 'upload') {
    checkCsrf();
    $listId = (int) ($_POST['list_id'] ?? 0);
    $hasHeader = !empty($_POST['has_header']);

    if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Carica un file CSV valido.';
    } elseif (!is_dir(IMPORT_TMP_DIR) && !@mkdir(IMPORT_TMP_DIR, 0700, true) && !is_dir(IMPORT_TMP_DIR)) {
        $error = 'Impossibile preparare la cartella temporanea per l\'import.';
    } else {
        $token = bin2hex(random_bytes(16));
        $path = IMPORT_TMP_DIR . '/' . $token . '.csv';

        if (!move_uploaded_file($_FILES['csv']['tmp_name'], $path)) {
            $error = 'Impossibile salvare il file caricato.';
        } else {
            $delimiter = detectCsvDelimiter($path);
            $preview = buildImportPreview($path, $delimiter, $hasHeader);

            if ($preview === null) {
                $error = 'Il file sembra vuoto o non è un CSV valido.';
                @unlink($path);
            } else {
                $_SESSION['import'] = [
                    'token' => $token,
                    'path' => $path,
                    'delimiter' => $delimiter,
                    'has_header' => $hasHeader,
                    'list_id' => $listId,
                ];
            }
        }
    }
} elseif ($step === 'confirm') {
    checkCsrf();
    $token = $_POST['token'] ?? '';
    $session = $_SESSION['import'] ?? null;

    if (!$session || !hash_equals($session['token'], (string) $token) || !is_file($session['path'])) {
        $error = 'La sessione di importazione è scaduta. Ricarica il file.';
        unset($_SESSION['import']);
    } else {
        $emailCol = $_POST['email_col'] ?? '';
        $nameCol = $_POST['name_col'] ?? '';

        if ($emailCol === '') {
            $error = 'Seleziona quale colonna contiene l\'indirizzo email.';
            $preview = buildImportPreview($session['path'], $session['delimiter'], $session['has_header']);
        } else {
            $emailCol = (int) $emailCol;
            $nameCol = $nameCol === '' ? null : (int) $nameCol;

            $handle = openCsvSkippingBom($session['path']);
            $insert = $pdo->prepare('INSERT INTO contacts (email, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = IF(VALUES(name) IS NOT NULL, VALUES(name), name)');
            $addToList = $pdo->prepare('INSERT IGNORE INTO contact_list_members (list_id, contact_id) SELECT ?, id FROM contacts WHERE email = ?');

            $imported = 0;
            $invalid = 0;
            $rowIndex = 0;
            while (($row = fgetcsv($handle, 0, $session['delimiter'])) !== false) {
                $isHeaderRow = $rowIndex === 0 && $session['has_header'];
                $rowIndex++;
                if ($isHeaderRow) {
                    continue;
                }
                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue; // riga vuota
                }

                $email = trim((string) ($row[$emailCol] ?? ''));
                $name = $nameCol !== null ? trim((string) ($row[$nameCol] ?? '')) : '';

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $invalid++;
                    continue;
                }

                $insert->execute([$email, $name !== '' ? $name : null]);
                if ($session['list_id'] > 0) {
                    $addToList->execute([$session['list_id'], $email]);
                }
                $imported++;
            }
            fclose($handle);
            @unlink($session['path']);
            unset($_SESSION['import']);

            flash('success', "{$imported} contatti importati/aggiornati, {$invalid} righe scartate (email non valida).");
            redirect('/contacts.php');
        }
    }
}

$pageTitle = 'Importa contatti — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Importa contatti da CSV</h1>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($preview === null): ?>
  <div class="card">
    <p class="hint">Carica un file CSV con i tuoi contatti. Al passaggio successivo potrai scegliere quale colonna del file contiene l'email e quale il nome, qualunque sia l'ordine o l'intestazione usati nel file.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="step" value="upload">
      <label for="csv">File CSV</label>
      <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>

      <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-top:16px">
        <input type="checkbox" id="has_header" name="has_header" value="1" checked style="width:auto">
        La prima riga del file è un'intestazione (nomi delle colonne), non un contatto
      </label>

      <label for="list_id">Aggiungi anche a una lista (opzionale)</label>
      <select id="list_id" name="list_id">
        <option value="0">Nessuna lista</option>
        <?php foreach ($lists as $l): ?>
          <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <div class="actions">
        <button class="btn" type="submit">Continua</button>
        <a class="btn btn-secondary" href="/contacts.php">Annulla</a>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Anteprima</h2>
    <p class="hint">Circa <?= (int) $preview['total_lines'] ?> righe da importare. Ecco le prime <?= count($preview['rows']) ?>:</p>
    <div style="overflow-x:auto">
      <table>
        <thead><tr><?php foreach ($preview['columns'] as $label): ?><th><?= e((string) $label) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $row): ?>
          <tr><?php foreach ($preview['columns'] as $i => $label): ?><td><?= e((string) ($row[$i] ?? '')) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Fai corrispondere le colonne</h2>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="step" value="confirm">
      <input type="hidden" name="token" value="<?= e($_SESSION['import']['token']) ?>">

      <label for="email_col">Colonna email</label>
      <select id="email_col" name="email_col" required>
        <option value="">— seleziona —</option>
        <?php foreach ($preview['columns'] as $i => $label): ?>
          <option value="<?= $i ?>" <?= $i === $preview['guessed_email'] ? 'selected' : '' ?>><?= e((string) $label) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="name_col">Colonna nome (opzionale)</label>
      <select id="name_col" name="name_col">
        <option value="">Nessuna</option>
        <?php foreach ($preview['columns'] as $i => $label): ?>
          <option value="<?= $i ?>" <?= $i === $preview['guessed_name'] ? 'selected' : '' ?>><?= e((string) $label) ?></option>
        <?php endforeach; ?>
      </select>

      <div class="actions">
        <button class="btn" type="submit">Conferma e importa</button>
        <a class="btn btn-secondary" href="/contacts_import.php">Ricomincia</a>
      </div>
    </form>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
