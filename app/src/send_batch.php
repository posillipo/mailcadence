<?php
// Worker eseguito da cron ogni 5 minuti (vedi app/crontab). Per ogni campagna 'running' il cui
// next_batch_at è scaduto, invia fino a batch_size destinatari pending e pianifica il batch
// successivo tra interval_minutes. L'intervallo del cron (5 minuti) è più fine della cadenza
// delle campagne (tipicamente 60-120 minuti) solo per non far slittare troppo l'orario reale di
// invio rispetto a quello pianificato.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

// Evita che due esecuzioni sovrapposte (es. un batch lento a cavallo di due tick di cron)
// processino la stessa campagna due volte.
$lockFile = fopen('/tmp/mailcadence-send-batch.lock', 'c');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Un'altra esecuzione è già in corso, esco.\n");
    exit(0);
}

function processCampaign(PDO $pdo, SimpleSmtpMailer $mailer, array $campaign): void {
    $campaignId = (int) $campaign['id'];

    // I destinatari il cui contatto non è più attivo (disiscritto nel frattempo, o segnato come
    // bounced) non verranno mai inviati: li segniamo come "skipped" così non restano "pending"
    // per sempre e il conteggio di avanzamento resta corretto.
    $skip = $pdo->prepare(
        "UPDATE campaign_recipients cr
         JOIN contacts c ON c.id = cr.contact_id
         SET cr.status = 'skipped', cr.error = 'contatto non più attivo'
         WHERE cr.campaign_id = ? AND cr.status = 'pending' AND c.status != 'active'"
    );
    $skip->execute([$campaignId]);

    $batchSize = (int) $campaign['batch_size'];
    $stmt = $pdo->prepare(
        "SELECT cr.id AS recipient_id, c.id AS contact_id, c.email, c.name
         FROM campaign_recipients cr
         JOIN contacts c ON c.id = cr.contact_id
         WHERE cr.campaign_id = ? AND cr.status = 'pending' AND c.status = 'active'
         ORDER BY cr.id ASC
         LIMIT {$batchSize}"
    );
    $stmt->execute([$campaignId]);
    $recipients = $stmt->fetchAll();

    if (empty($recipients)) {
        $pdo->prepare("UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")
            ->execute([$campaignId]);
        echo "Campagna #{$campaignId}: nessun destinatario rimasto, segnata come completata.\n";
        return;
    }

    $updateSent = $pdo->prepare("UPDATE campaign_recipients SET status = 'sent', sent_at = NOW() WHERE id = ?");
    $updateFailed = $pdo->prepare("UPDATE campaign_recipients SET status = 'failed', error = ? WHERE id = ?");

    $sentCount = 0;
    foreach ($recipients as $r) {
        $unsubUrl = unsubscribeUrl((int) $r['contact_id']);
        $ok = $mailer->send(
            $campaign['from_email'],
            $campaign['from_name'],
            $r['email'],
            $r['name'] ?: $r['email'],
            $campaign['subject'],
            $campaign['body_html'],
            $unsubUrl
        );
        if ($ok) {
            $updateSent->execute([$r['recipient_id']]);
            $sentCount++;
        } else {
            $updateFailed->execute(['invio SMTP fallito, vedi log del container', $r['recipient_id']]);
        }
    }

    $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'");
    $remainingStmt->execute([$campaignId]);
    $remaining = (int) $remainingStmt->fetchColumn();

    if ($remaining === 0) {
        $pdo->prepare("UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")
            ->execute([$campaignId]);
    } else {
        $pdo->prepare("UPDATE campaigns SET next_batch_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?")
            ->execute([(int) $campaign['interval_minutes'], $campaignId]);
    }

    echo "Campagna #{$campaignId}: inviati {$sentCount}/" . count($recipients) . ", rimangono {$remaining} in coda.\n";
}

$pdo = getDB();
$mailer = SimpleSmtpMailer::fromConfig(effectiveSmtpConfig());

$campaigns = $pdo->query(
    "SELECT * FROM campaigns WHERE status = 'running' AND (next_batch_at IS NULL OR next_batch_at <= NOW())"
)->fetchAll();

foreach ($campaigns as $campaign) {
    processCampaign($pdo, $mailer, $campaign);
}

flock($lockFile, LOCK_UN);
fclose($lockFile);
