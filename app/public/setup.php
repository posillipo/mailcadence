<?php
session_start();
require_once __DIR__ . '/../src/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Questa pagina serve solo alla primissima configurazione: appena esiste un amministratore
// (nel DB o via .env) si disattiva da sola e reindirizza al login, per evitare che chiunque
// arrivi qui possa crearsi un secondo account.
if (hasAdmin()) {
    redirect('/login.php');
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Inserisci un indirizzo email valido.';
    } elseif (strlen($password) < 8) {
        $error = 'La password deve avere almeno 8 caratteri.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Le due password inserite non coincidono.';
    } elseif (hasAdmin()) {
        // Ricontrollo appena prima di scrivere: copre il caso limite di due richieste di setup
        // concorrenti che arrivano entrambe qui prima che la prima abbia già creato l'account.
        redirect('/login.php');
    } else {
        createAdmin($email, $password);
        attemptLogin($email, $password);
        redirect('/dashboard.php');
    }
}

$pageTitle = 'Crea account amministratore — MailCadence';
require __DIR__ . '/_header.php';
?>
<div class="login-box card">
  <h1>Benvenuto in MailCadence</h1>
  <p class="hint">Nessun amministratore configurato: crea il tuo account per accedere. Questa pagina si disattiva da sola dopo la creazione.</p>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrfField() ?>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus value="<?= e($email) ?>">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="8">
    <label for="password_confirm">Conferma password</label>
    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
    <div class="actions"><button class="btn" type="submit">Crea account e accedi</button></div>
  </form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
