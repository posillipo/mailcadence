<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

$pdo = getDB();
$lists = $pdo->query(
    "SELECT l.id, l.name,
            (SELECT COUNT(*) FROM contact_list_members m JOIN contacts c ON c.id = m.contact_id
             WHERE m.list_id = l.id AND c.status = 'active') AS active_count
     FROM contact_lists l ORDER BY l.name"
)->fetchAll();

$error = null;
$defaults = [
    'name' => '',
    'list_id' => '',
    'subject' => '',
    'from_name' => getenv('SMTP_FROM_NAME') ?: '',
    'from_email' => getenv('SMTP_FROM') ?: '',
    'body_html' => '',
    'batch_size' => 50,
    'interval_minutes' => 60,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $start = ($_POST['submit_action'] ?? '') === 'start';

    $values = [
        'name' => trim($_POST['name'] ?? ''),
        'list_id' => (int) ($_POST['list_id'] ?? 0),
        'subject' => trim($_POST['subject'] ?? ''),
        'from_name' => trim($_POST['from_name'] ?? ''),
        'from_email' => trim($_POST['from_email'] ?? ''),
        'body_html' => $_POST['body_html'] ?? '',
        'batch_size' => max(1, (int) ($_POST['batch_size'] ?? 50)),
        'interval_minutes' => max(1, (int) ($_POST['interval_minutes'] ?? 60)),
    ];
    $defaults = $values;

    if ($values['name'] === '' || $values['subject'] === '' || trim($values['body_html']) === '') {
        $error = 'Nome, oggetto e testo del messaggio sono obbligatori.';
    } elseif ($values['list_id'] <= 0) {
        $error = 'Seleziona una lista di destinatari.';
    } elseif (!filter_var($values['from_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Indirizzo mittente non valido.';
    } elseif ($values['from_name'] === '') {
        $error = 'Il nome mittente è obbligatorio.';
    } else {
        $pdo->beginTransaction();
        try {
            $status = $start ? 'running' : 'draft';
            $stmt = $pdo->prepare(
                "INSERT INTO campaigns (name, subject, body_html, from_name, from_email, list_id, batch_size, interval_minutes, status, next_batch_at, started_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $values['name'],
                $values['subject'],
                $values['body_html'],
                $values['from_name'],
                $values['from_email'],
                $values['list_id'],
                $values['batch_size'],
                $values['interval_minutes'],
                $status,
                $start ? date('Y-m-d H:i:s') : null,
                $start ? date('Y-m-d H:i:s') : null,
            ]);
            $campaignId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO campaign_recipients (campaign_id, contact_id)
                 SELECT ?, m.contact_id FROM contact_list_members m
                 JOIN contacts c ON c.id = m.contact_id
                 WHERE m.list_id = ? AND c.status = 'active'"
            )->execute([$campaignId, $values['list_id']]);

            $pdo->commit();
            flash('success', $start ? 'Campagna creata e avviata: il primo gruppo partirà al prossimo passaggio del worker (entro 5 minuti).' : 'Campagna salvata come bozza.');
            redirect('/campaign_view.php?id=' . $campaignId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[campaign_new] ' . $e->getMessage());
            $error = 'Errore durante la creazione della campagna.';
        }
    }
}

$pageTitle = 'Nuova campagna — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Nuova campagna</h1>
<div class="card">
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <?php if (empty($lists)): ?>
    <p class="hint">Non hai ancora nessuna lista. <a href="/lists.php">Creane una</a> prima di avviare una campagna.</p>
  <?php else: ?>
  <form method="post">
    <?= csrfField() ?>
    <label for="name">Nome campagna (uso interno)</label>
    <input type="text" id="name" name="name" required value="<?= e($defaults['name']) ?>">

    <label for="list_id">Lista destinatari</label>
    <select id="list_id" name="list_id" required>
      <option value="">— seleziona —</option>
      <?php foreach ($lists as $l): ?>
        <option value="<?= (int) $l['id'] ?>" <?= (string) $l['id'] === (string) $defaults['list_id'] ? 'selected' : '' ?>>
          <?= e($l['name']) ?> (<?= (int) $l['active_count'] ?> attivi)
        </option>
      <?php endforeach; ?>
    </select>

    <label for="subject">Oggetto</label>
    <input type="text" id="subject" name="subject" required value="<?= e($defaults['subject']) ?>">

    <label for="from_name">Nome mittente</label>
    <input type="text" id="from_name" name="from_name" required value="<?= e($defaults['from_name']) ?>">

    <label for="from_email">Email mittente</label>
    <input type="email" id="from_email" name="from_email" required value="<?= e($defaults['from_email']) ?>">

    <label for="body_html">Testo del messaggio (HTML semplice: &lt;p&gt;, &lt;br&gt;, &lt;a&gt;...)</label>
    <textarea id="body_html" name="body_html" required><?= e($defaults['body_html']) ?></textarea>
    <p class="hint">Il link di disiscrizione viene aggiunto automaticamente in fondo a ogni email.</p>

    <label for="batch_size">Contatti per gruppo</label>
    <input type="number" id="batch_size" name="batch_size" min="1" value="<?= (int) $defaults['batch_size'] ?>">

    <label for="interval_minutes">Minuti tra un gruppo e il successivo</label>
    <input type="number" id="interval_minutes" name="interval_minutes" min="1" value="<?= (int) $defaults['interval_minutes'] ?>">
    <p class="hint">60 = un gruppo ogni ora, 120 = un gruppo ogni due ore.</p>

    <div class="actions">
      <button class="btn" type="submit" name="submit_action" value="start">Salva e avvia subito</button>
      <button class="btn btn-secondary" type="submit" name="submit_action" value="draft">Salva come bozza</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
