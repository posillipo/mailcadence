<?php
// Pagina pubblica, nessun login richiesto: il link arriva dentro le email inviate ed è protetto
// da un token HMAC (vedi src/functions.php: unsubscribeToken()), non da CSRF — chi lo riceve non
// ha una sessione autenticata. Accetta sia GET (l'utente clicca il link nell'email: mostriamo una
// pagina di conferma prima di agire, per non disiscrivere per errore chi ha solo un prefetcher
// email che segue i link automaticamente) sia POST (i client email compatibili con RFC 8058
// "List-Unsubscribe=One-Click" inviano direttamente una POST, che qui viene eseguita subito).

require_once __DIR__ . '/../src/functions.php';

$contactId = (int) ($_GET['c'] ?? $_POST['c'] ?? 0);
$token = $_GET['t'] ?? $_POST['t'] ?? '';

$valid = $contactId > 0 && is_string($token) && $token !== '' && hash_equals(unsubscribeToken($contactId), $token);

$done = false;
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDB();
    $pdo->prepare("UPDATE contacts SET status = 'unsubscribed' WHERE id = ?")->execute([$contactId]);
    $done = true;
}

$pageTitle = 'Disiscrizione — MailCadence';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<main class="container">
  <div class="login-box card">
    <?php if (!$valid): ?>
      <h1>Link non valido</h1>
      <p>Questo link di disiscrizione non è valido o è scaduto.</p>
    <?php elseif ($done): ?>
      <h1>Disiscrizione completata</h1>
      <p>Non riceverai più email da questa lista.</p>
    <?php else: ?>
      <h1>Vuoi disiscriverti?</h1>
      <p>Non riceverai più email da questa lista.</p>
      <form method="post">
        <input type="hidden" name="c" value="<?= (int) $contactId ?>">
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <button class="btn" type="submit">Conferma disiscrizione</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
