<?php
session_start();
require_once __DIR__ . '/../src/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (isLoggedIn()) {
    redirect('/dashboard.php');
}

if (!hasAdmin()) {
    redirect('/setup.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attemptLogin($email, $password)) {
        redirect('/dashboard.php');
    }
    $error = 'Email o password non corretti.';
}

$pageTitle = 'Accedi — MailCadence';
require __DIR__ . '/_header.php';
?>
<div class="login-box card">
  <h1>MailCadence</h1>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrfField() ?>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>
    <div class="actions"><button class="btn" type="submit">Accedi</button></div>
  </form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
