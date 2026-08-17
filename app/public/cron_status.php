<?php
session_start();
require_once __DIR__ . '/../src/auth.php';
requireLogin();

const SEND_BATCH_LOG = '/var/log/send_batch.log';
const SEND_BATCH_SCRIPT = '/var/www/src/send_batch.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (($_POST['action'] ?? '') === 'run_now') {
        // Con batch grandi il worker può metterci più dei tempici limiti di default di PHP per
        // una richiesta web: qui è un'azione amministrativa esplicita, non pubblica né frequente,
        // quindi va bene toglierli solo per questa richiesta.
        set_time_limit(0);
        // Non redirigiamo l'output del comando su file (>> ... 2>&1): così facendo shell_exec()
        // non riceverebbe mai nulla in stdout e non potremmo distinguere un'esecuzione riuscita
        // senza output da shell_exec disabilitata sul server. Lo catturiamo qui e lo scriviamo
        // noi nel log, in append, così resta un'unica timeline continua con quello del cron.
        $output = shell_exec('php ' . escapeshellarg(SEND_BATCH_SCRIPT) . ' 2>&1');
        if ($output === null) {
            flash('error', 'Impossibile eseguire il worker da qui: la funzione shell_exec risulta disabilitata su questo server.');
        } else {
            file_put_contents(SEND_BATCH_LOG, $output, FILE_APPEND | LOCK_EX);
            flash('success', 'Worker eseguito manualmente. Guarda il log qui sotto per il risultato.');
        }
    }
    redirect('/cron_status.php');
}

$pdo = getDB();
$running = $pdo->query(
    "SELECT id, name, next_batch_at, NOW() AS db_now, (next_batch_at IS NULL OR next_batch_at <= NOW()) AS is_due
     FROM campaigns WHERE status = 'running' ORDER BY next_batch_at"
)->fetchAll();

$logExists = is_file(SEND_BATCH_LOG);
$lastRun = $logExists ? filemtime(SEND_BATCH_LOG) : null;
$minutesAgo = $lastRun !== null ? (int) round((time() - $lastRun) / 60) : null;

// Solo le ultime righe: il file può crescere nel tempo, non ha senso caricarlo tutto a ogni
// visita della pagina.
$logTail = '';
if ($logExists) {
    $size = filesize(SEND_BATCH_LOG);
    if ($size > 0) {
        $handle = fopen(SEND_BATCH_LOG, 'r');
        if ($handle) {
            $readFrom = max(0, $size - 20000);
            fseek($handle, $readFrom);
            $logTail = fread($handle, $size - $readFrom);
            fclose($handle);
            if ($readFrom > 0) {
                $logTail = "[...log troncato, mostrate solo le ultime righe...]\n" . $logTail;
            }
        }
    }
}

$pageTitle = 'Worker di invio — MailCadence';
require __DIR__ . '/_header.php';
?>
<h1>Worker di invio</h1>

<div class="card">
  <h2>Stato</h2>
  <?php if ($lastRun === null): ?>
    <p class="hint">Il worker non ha ancora scritto nessun log: non è chiaro se sia mai partito.</p>
  <?php else: ?>
    <p>Ultima esecuzione registrata: <strong><?= (int) $minutesAgo ?> minuti fa</strong> (<?= e(date('d/m/Y H:i:s', $lastRun)) ?>).</p>
    <?php if ($minutesAgo > 7): ?>
      <div class="flash flash-error">Sono passati più di 7 minuti dall'ultima riga scritta nel log, ma il worker dovrebbe girare ogni 5 minuti: probabile che il cron non sia attivo nel container. Prova "Esegui il worker ora" qui sotto.</div>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" style="margin-top:12px">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="run_now">
    <button class="btn" type="submit">Esegui il worker ora</button>
  </form>
  <p class="hint">Lancia subito una passata del worker (la stessa cosa che farebbe il cron), utile per testare senza aspettare o accedere alla console del server.</p>
</div>

<div class="card">
  <h2>Campagne in corso</h2>
  <?php if (empty($running)): ?>
    <p class="hint">Nessuna campagna attualmente in stato "running".</p>
  <?php else: ?>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Nome</th><th>Prossimo gruppo</th><th>Ora del database</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($running as $c): ?>
        <tr>
          <td><a href="/campaign_view.php?id=<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a></td>
          <td><?= formatDateIt($c['next_batch_at']) ?></td>
          <td><?= formatDateIt($c['db_now']) ?></td>
          <td>
            <?php if ($c['is_due']): ?>
              <span class="badge badge-failed">in ritardo</span>
            <?php else: ?>
              <span class="badge badge-active">in attesa</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Ultime righe di log</h2>
  <p class="hint"><?= e(SEND_BATCH_LOG) ?></p>
  <?php if ($logTail === ''): ?>
    <p class="hint">Nessun log ancora.</p>
  <?php else: ?>
    <pre style="white-space:pre-wrap; word-break:break-word; max-height:400px; overflow-y:auto; background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:12px; font-size:12px;"><?= e($logTail) ?></pre>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
