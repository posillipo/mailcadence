<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/mailer.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'set_default' && $id > 0) {
        setDefaultSmtpProfile($id);
        flash('success', 'Profilo impostato come predefinito.');
    } elseif ($action === 'delete' && $id > 0) {
        deleteSmtpProfile($id);
        flash('success', 'Profilo eliminato. Le liste che lo usavano torneranno al profilo predefinito.');
    }
    redirect('/smtp_settings.php');
}

$profiles = listSmtpProfiles();

$pageTitle = 'Profili SMTP — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Profili SMTP</h1>
<p class="hint">Ogni lista di contatti può usare un profilo specifico (si assegna da "Gestisci" nella lista); altrimenti si usa il profilo predefinito.</p>

<div class="card">
  <div class="actions">
    <a class="btn" href="/smtp_profile_edit.php">+ Nuovo profilo</a>
  </div>
</div>

<div class="card">
  <?php if (empty($profiles)): ?>
    <p class="hint">Nessun profilo SMTP configurato: l'invio usa le variabili SMTP_* del .env, se impostate.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Nome</th><th>Server</th><th>Mittente</th><th></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($profiles as $p): ?>
        <tr>
          <td>
            <a href="/smtp_profile_edit.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a>
          </td>
          <td><?= e($p['host']) ?>:<?= (int) $p['port'] ?></td>
          <td><?= e($p['from_name']) ?> &lt;<?= e($p['from_email']) ?>&gt;</td>
          <td><?php if ($p['is_default']): ?><span class="badge badge-active">predefinito</span><?php endif; ?></td>
          <td>
            <?php if (!$p['is_default']): ?>
              <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_default">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-secondary" type="submit">Rendi predefinito</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare il profilo &quot;<?= e($p['name']) ?>&quot;? Le liste che lo usano torneranno al profilo predefinito.');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn-danger" type="submit">Elimina</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
