<?php
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

    public function __construct(string $host, int $port, string $user, string $pass, string $secure = 'tls', bool $verifyCert = true) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->secure = strtolower($secure);
        $this->verifyCert = $verifyCert;
    }

    public static function fromEnv(): self {
        return new self(
            getenv('SMTP_HOST') ?: '',
            (int) (getenv('SMTP_PORT') ?: 587),
            getenv('SMTP_USER') ?: '',
            getenv('SMTP_PASS') ?: '',
            getenv('SMTP_SECURE') ?: 'tls',
            true
        );
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

            $heloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

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
            error_log('[SimpleSmtpMailer] invio a ' . $toEmail . ' fallito: ' . $e->getMessage());
            return false;
        }
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
