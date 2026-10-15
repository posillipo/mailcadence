<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/mailer.php';
requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? getSmtpProfile($id) : null;
if ($id !== null && $existing === null) {
    flash('error', 'Profilo non trovato.');
    redirect('/smtp_settings.php');
}

$error = null;
$testResult = null;
$defaults = $existing !== null
    ? [
        'name' => $existing['name'],
        'host' => $existing['host'],
        'port' => (int) $existing['port'],
        'user' => $existing['username'],
        'secure' => $existing['secure'],
        'from_email' => $existing['from_email'],
        'from_name' => $existing['from_name'],
    ]
    : [
        'name' => '', 'host' => '', 'port' => 587, 'user' => '',
        'secure' => 'tls', 'from_email' => '', 'from_name' => '',
    ];
$admin = currentAdminCredentials();
$testTo = $admin['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['submit_action'] ?? '';

    $values = [
        'name' => trim($_POST['name'] ?? ''),
        'host' => trim($_POST['host'] ?? ''),
        'port' => (int) ($_POST['port'] ?? 0),
        'user' => trim($_POST['user'] ?? ''),
        'pass' => $_POST['pass'] ?? '', // vuota = non cambiare la password già salvata
        'secure' => $_POST['secure'] ?? 'tls',
        'from_email' => trim($_POST['from_email'] ?? ''),
        'from_name' => trim($_POST['from_name'] ?? ''),
    ];
    $defaults = [
        'name' => $values['name'],
        'host' => $values['host'],
        'port' => $values['port'],
        'user' => $values['user'],
        'secure' => $values['secure'],
        'from_email' => $values['from_email'],
        'from_name' => $values['from_name'],
    ];
    $testTo = trim($_POST['test_to'] ?? $testTo);

    if ($values['name'] === '') {
        $error = 'Il nome del profilo è obbligatorio (es. "Principale", "Newsletter clienti").';
    } elseif ($values['host'] === '') {
        $error = 'Il server SMTP è obbligatorio.';
    } elseif ($values['port'] < 1 || $values['port'] > 65535) {
        $error = 'La porta deve essere tra 1 e 65535.';
    } elseif (!in_array($values['secure'], ['tls', 'ssl', ''], true)) {
        $error = 'Tipo di cifratura non valido.';
    } elseif (!filter_var($values['from_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Indirizzo mittente non valido.';
    } elseif ($values['from_name'] === '') {
        $error = 'Il nome mittente è obbligatorio.';
    } elseif ($action === 'test' && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Inserisci un indirizzo email valido a cui inviare il test.';
    } else {
        // La password effettiva da usare (per salvare o per il test): quella nuova se inserita,
        // altrimenti quella già salvata nel profilo (per un profilo nuovo, nessuna).
        $effectivePass = $values['pass'] !== '' ? $values['pass'] : ($existing['password'] ?? '');

        if ($effectivePass === '') {
            $error = 'Nessuna password SMTP impostata: inseriscila per poter salvare o testare la connessione.';
        } elseif ($action === 'save') {
            if ($existing !== null) {
                updateSmtpProfile($existing['id'], $values);
                flash('success', 'Profilo SMTP aggiornato.');
                redirect('/smtp_profile_edit.php?id=' . $existing['id']);
            } else {
                $newId = createSmtpProfile($values);
                flash('success', 'Profilo SMTP creato.');
                redirect('/smtp_profile_edit.php?id=' . $newId);
            }
        } elseif ($action === 'test') {
            $mailer = SimpleSmtpMailer::fromConfig([
                'host' => $values['host'],
                'port' => $values['port'],
                'user' => $values['user'],
                'pass' => $effectivePass,
                'secure' => $values['secure'],
            ]);
            $ok = $mailer->send(
                $values['from_email'],
                $values['from_name'],
                $testTo,
                $testTo,
                'Email di test — MailCadence',
                '<p>Questa è una email di test inviata da MailCadence per verificare la configurazione SMTP.</p><p>Inviata il ' . e(date('d/m/Y H:i')) . '.</p>',
                unsubscribeUrl(0)
            );
            $testResult = $ok
                ? ['ok' => true, 'message' => "Email di test inviata correttamente a {$testTo}."]
                : ['ok' => false, 'message' => 'Invio fallito: ' . $mailer->lastError()];
        }
    }
}

$pageTitle = ($existing !== null ? 'Modifica profilo SMTP' : 'Nuovo profilo SMTP') . ' — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1><?= $existing !== null ? 'Modifica profilo SMTP' : 'Nuovo profilo SMTP' ?></h1>

<div class="card">
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($testResult): ?>
    <div class="flash flash-<?= $testResult['ok'] ? 'success' : 'error' ?>"><?= e($testResult['message']) ?></div>
  <?php endif; ?>

  <form method="post">
    <?= csrfField() ?>

    <label for="name">Nome profilo</label>
    <input type="text" id="name" name="name" required value="<?= e($defaults['name']) ?>" placeholder="es. Principale, Newsletter clienti">

    <label for="host">Server SMTP</label>
    <input type="text" id="host" name="host" required value="<?= e($defaults['host']) ?>" placeholder="smtps.aruba.it">

    <label for="port">Porta</label>
    <input type="number" id="port" name="port" required min="1" max="65535" value="<?= (int) $defaults['port'] ?>">

    <label for="secure">Cifratura</label>
    <select id="secure" name="secure">
      <option value="tls" <?= $defaults['secure'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
      <option value="ssl" <?= $defaults['secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
      <option value="" <?= $defaults['secure'] === '' ? 'selected' : '' ?>>Nessuna</option>
    </select>

    <label for="user">Utente SMTP</label>
    <input type="text" id="user" name="user" value="<?= e($defaults['user']) ?>">

    <label for="pass">Password SMTP</label>
    <input type="password" id="pass" name="pass" placeholder="<?= $existing !== null ? 'lascia vuoto per non modificarla' : '' ?>" autocomplete="new-password">
    <?php if ($existing !== null): ?>
      <p class="hint">Per motivi di sicurezza la password salvata non viene mai mostrata qui. Lascia il campo vuoto per mantenere quella attuale, oppure inseriscine una nuova per sostituirla.</p>
    <?php endif; ?>

    <label for="from_email">Email mittente</label>
    <input type="email" id="from_email" name="from_email" required value="<?= e($defaults['from_email']) ?>">

    <label for="from_name">Nome mittente</label>
    <input type="text" id="from_name" name="from_name" required value="<?= e($defaults['from_name']) ?>">

    <div class="actions">
      <button class="btn" type="submit" name="submit_action" value="save"><?= $existing !== null ? 'Salva modifiche' : 'Crea profilo' ?></button>
      <a class="btn btn-secondary" href="/smtp_settings.php">Annulla</a>
    </div>

    <hr>

    <label for="test_to">Invia una email di test a</label>
    <input type="email" id="test_to" name="test_to" value="<?= e($testTo) ?>">
    <p class="hint">Usa i valori compilati qui sopra (anche se non ancora salvati) per provare subito la connessione, senza dover prima salvare.</p>
    <div class="actions">
      <button class="btn btn-secondary" type="submit" name="submit_action" value="test">Invia test</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
