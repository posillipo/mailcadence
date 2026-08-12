<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');
    $name = trim($_POST['name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Indirizzo email non valido.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO contacts (email, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = IF(VALUES(name) IS NOT NULL, VALUES(name), name)');
        $stmt->execute([$email, $name !== '' ? $name : null]);
        flash('success', 'Contatto salvato.');
        redirect('/contacts.php');
    }
}

$pageTitle = 'Nuovo contatto — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Nuovo contatto</h1>
<div class="card">
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrfField() ?>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
    <label for="name">Nome (opzionale)</label>
    <input type="text" id="name" name="name" value="<?= e($_POST['name'] ?? '') ?>">
    <div class="actions">
      <button class="btn" type="submit">Salva</button>
      <a class="btn btn-secondary" href="/contacts.php">Annulla</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
