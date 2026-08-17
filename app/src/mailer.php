<?php
require_once __DIR__ . '/functions.php';

/**
 * Client SMTP minimale e autonomo (nessuna dipendenza esterna/Composer), sul modello dello
 * stesso mailer usato in chifacosa. Rispetto a quello, qui il messaggio è sempre
 * multipart/alternative (testo semplice + HTML): avere sempre una parte testuale accanto
 * all'HTML è una delle cose che i filtri antispam guardano per valutare la reputazione del
 * messaggio, oltre a un header List-Unsubscribe valido — entrambi richiesti esplicitamente per
 * questo progetto proprio per ridurre il rischio di finire in spam.
 */
class SimpleSmtpMailer {
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $secure; // 'ssl', 'tls' oppure '' (nessuna cifratura)
    private bool $verifyCert;
    private int $timeout = 15;
    private string $lastError = '';

    public function __construct(string $host, int $port, string $user, string $pass, string $secure = 'tls', bool $verifyCert = true) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->secure = strtolower($secure);
        $this->verifyCert = $verifyCert;
    }

    public static function fromConfig(array $cfg): self {
        return new self(
            $cfg['host'] ?? '',
            (int) ($cfg['port'] ?? 587),
            $cfg['user'] ?? '',
            $cfg['pass'] ?? '',
            $cfg['secure'] ?? 'tls',
            true
        );
    }

    // Dettaglio dell'ultimo errore incontrato da send(), che internamente lo inghiotte
    // (restituisce solo true/false) per non interrompere mai l'invio di un batch di campagna:
    // serve solo per la pagina di test SMTP, dove invece l'admin vuole sapere cosa è andato storto.
    public function lastError(): string {
        return $this->lastError;
    }

    /**
     * Invia un'email HTML con fallback testuale automatico e header List-Unsubscribe.
     * Restituisce true/false; in caso di errore scrive il dettaglio nei log PHP del container
     * senza sollevare eccezioni, così un fallimento di invio non deve mai interrompere il worker
     * che processa il resto del batch.
     */
    public function send(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $unsubscribeUrl
    ): bool {
        try {
            $remote = ($this->secure === 'ssl' ? 'ssl://' : '') . $this->host;

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => $this->verifyCert,
                    'verify_peer_name' => $this->verifyCert,
                    'allow_self_signed' => !$this->verifyCert,
                ],
            ]);

            $socket = @stream_socket_client("{$remote}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                throw new Exception("Connessione SMTP fallita: {$errstr} ({$errno})");
            }
            stream_set_timeout($socket, $this->timeout);

            // Il dominio del mittente, non l'host HTTP della richiesta: quest'ultimo può includere
            // una porta (es. "1.2.3.4:8086") o essere del tutto assente quando send() viene
            // chiamato dal worker cron (niente $_SERVER['HTTP_HOST'] fuori da un contesto web),
            // e alcuni server SMTP (Aruba compreso) rifiutano con "501 EHLO requires valid
            // address" un argomento che non sia un hostname valido.
            $heloHost = $this->heloHostFor($fromEmail);

            $this->expect($socket, 220);
            $this->command($socket, "EHLO {$heloHost}", 250);

            if ($this->secure === 'tls') {
                $this->command($socket, "STARTTLS", 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("Attivazione TLS fallita (verifica certificato: " . ($this->verifyCert ? 'attiva' : 'disattivata') . ")");
                }
                $this->command($socket, "EHLO {$heloHost}", 250);
            }

            if ($this->user !== '') {
                $this->command($socket, "AUTH LOGIN", 334);
                $this->command($socket, base64_encode($this->user), 334);
                $this->command($socket, base64_encode($this->pass), 235);
            }

            $this->command($socket, "MAIL FROM:<{$fromEmail}>", 250);
            $this->command($socket, "RCPT TO:<{$toEmail}>", 250);
            $this->command($socket, "DATA", 354);

            $message = $this->buildMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $unsubscribeUrl);
            $this->command($socket, $message, 250);
            $this->command($socket, "QUIT", 221);
            fclose($socket);
            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log('[SimpleSmtpMailer] invio a ' . $toEmail . ' fallito: ' . $e->getMessage());
            return false;
        }
    }

    private function heloHostFor(string $fromEmail): string {
        $at = strrpos($fromEmail, '@');
        if ($at === false) {
            return 'localhost';
        }
        $domain = substr($fromEmail, $at + 1);
        return $domain !== '' ? $domain : 'localhost';
    }

    private function buildMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $unsubscribeUrl
    ): string {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
        $textBody .= "\n\n---\nPer non ricevere più queste email: {$unsubscribeUrl}";

        $htmlBody .= '<hr><p style="font-size:12px;color:#888888">Non vuoi più ricevere queste email? '
            . '<a href="' . $unsubscribeUrl . '">Annulla iscrizione</a></p>';

        $boundary = 'mc-' . bin2hex(random_bytes(12));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
            "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>",
            "Subject: {$encodedSubject}",
            "MIME-Version: 1.0",
            "List-Unsubscribe: <{$unsubscribeUrl}>",
            "List-Unsubscribe-Post: List-Unsubscribe=One-Click",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody))
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody))
            . "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    }

    private function command($socket, string $cmd, int $expectedCode): string {
        fwrite($socket, $cmd . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, int $expectedCode): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // Le risposte multilinea SMTP hanno un trattino dopo il codice sulle righe intermedie
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("Risposta SMTP inattesa: atteso {$expectedCode}, ricevuto '{$response}'");
        }
        return $response;
    }
}

// --- Configurazione SMTP -----------------------------------------------------
//
// Stesso schema già usato per l'account amministratore (vedi src/auth.php): una riga opzionale
// nel DB, impostabile dalla pagina /smtp_settings.php, ha priorità sulle variabili SMTP_* del
// .env. Se non è mai stata salvata nessuna configurazione da interfaccia, si continua a usare
// quella del .env così i deployment esistenti non richiedono nessuna azione.

function getStoredSmtpSettings(): ?array {
    $stmt = getDB()->query('SELECT host, port, username, password, secure, from_email, from_name FROM smtp_settings ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    return $row ?: null;
}

// Configurazione effettivamente in uso in questo momento (per l'invio delle campagne e per i
// valori precompilati nei form), combinando DB (se presente) e .env.
function effectiveSmtpConfig(): array {
    $stored = getStoredSmtpSettings();
    if ($stored !== null) {
        return [
            'host' => $stored['host'],
            'port' => (int) $stored['port'],
            'user' => $stored['username'],
            'pass' => $stored['password'],
            'secure' => $stored['secure'],
            'from_email' => $stored['from_email'],
            'from_name' => $stored['from_name'],
        ];
    }
    return [
        'host' => getenv('SMTP_HOST') ?: '',
        'port' => (int) (getenv('SMTP_PORT') ?: 587),
        'user' => getenv('SMTP_USER') ?: '',
        'pass' => getenv('SMTP_PASS') ?: '',
        'secure' => getenv('SMTP_SECURE') ?: 'tls',
        'from_email' => getenv('SMTP_FROM') ?: '',
        'from_name' => getenv('SMTP_FROM_NAME') ?: '',
    ];
}

// Salva (o aggiorna) la configurazione SMTP nel DB. Una password vuota lascia invariata quella
// già salvata (o, alla primissima configurazione, quella del .env), per evitare che il form debba
// per forza mostrarla in chiaro per poterla "confermare" a ogni salvataggio.
function saveSmtpSettings(array $cfg): void {
    $pdo = getDB();
    $existing = getStoredSmtpSettings();

    $password = $cfg['pass'];
    if ($password === '') {
        $password = $existing['password'] ?? (getenv('SMTP_PASS') ?: '');
    }

    if ($existing === null) {
        $pdo->prepare(
            'INSERT INTO smtp_settings (host, port, username, password, secure, from_email, from_name) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$cfg['host'], $cfg['port'], $cfg['user'], $password, $cfg['secure'], $cfg['from_email'], $cfg['from_name']]);
        return;
    }

    $pdo->prepare(
        'UPDATE smtp_settings SET host = ?, port = ?, username = ?, password = ?, secure = ?, from_email = ?, from_name = ?'
    )->execute([$cfg['host'], $cfg['port'], $cfg['user'], $password, $cfg['secure'], $cfg['from_email'], $cfg['from_name']]);
}
