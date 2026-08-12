<?php
require_once __DIR__ . '/db.php';

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Segreto usato per firmare i token di unsubscribe (HMAC). Deve restare stabile nel tempo: se
// cambia, tutti i link di disiscrizione già inviati smettono di funzionare.
function appSecret(): string {
    $secret = getenv('APP_SECRET');
    if ($secret === false || $secret === '') {
        // Non blocchiamo l'avvio per questo (l'app deve restare utilizzabile anche in sviluppo
        // locale senza .env completo), ma un default fisso e noto non protegge nulla in
        // produzione: chi fa il deploy deve impostare APP_SECRET nel proprio .env.
        error_log('[MailCadence] ATTENZIONE: APP_SECRET non impostato, uso un valore di default insicuro. Impostalo nel file .env.');
        return 'insecure-default-change-me';
    }
    return $secret;
}

function siteUrl(): string {
    $url = getenv('SITE_URL');
    if ($url !== false && $url !== '') {
        return rtrim($url, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// --- CSRF ---------------------------------------------------------------

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function checkCsrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        die('Richiesta non valida (CSRF). Ricarica la pagina e riprova.');
    }
}

// --- Messaggi flash (sopravvivono a un redirect) -------------------------

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function takeFlashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// --- Unsubscribe -----------------------------------------------------------

function unsubscribeToken(int $contactId): string {
    return hash_hmac('sha256', (string) $contactId, appSecret());
}

function unsubscribeUrl(int $contactId): string {
    return siteUrl() . '/unsubscribe.php?c=' . $contactId . '&t=' . unsubscribeToken($contactId);
}

// --- Varie -----------------------------------------------------------------

function formatDateIt(?string $dt): string {
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

// --- Import CSV --------------------------------------------------------

// I CSV esportati da Excel in locale italiano usano quasi sempre il punto e virgola come
// separatore (la virgola è il separatore decimale), mentre gli export "internazionali" usano la
// virgola: si sceglie quello più frequente nella prima riga invece di assumerne uno fisso.
function detectCsvDelimiter(string $path): string {
    $handle = fopen($path, 'r');
    $firstLine = $handle ? (string) fgets($handle) : '';
    if ($handle) {
        fclose($handle);
    }
    $candidates = [
        ',' => substr_count($firstLine, ','),
        ';' => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
    ];
    arsort($candidates);
    $best = array_key_first($candidates);
    return $candidates[$best] > 0 ? $best : ',';
}

// Molti CSV esportati da Excel/Windows iniziano con un BOM UTF-8: se non lo si salta, finisce
// dentro il valore della prima colonna della prima riga (intestazione compresa), che può rompere
// la validazione email quando l'email è proprio nella prima colonna.
function openCsvSkippingBom(string $path) {
    $handle = fopen($path, 'r');
    if ($handle) {
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
    }
    return $handle;
}

// Prova a indovinare a quale colonna corrisponde un campo cercando parole chiave nell'intestazione
// (utile solo se il CSV ha una riga di intestazione: senza, $columns contiene solo etichette
// generiche "Colonna N" e nessuna corrisponderà).
function guessColumnByLabel(array $columns, array $needles): ?int {
    foreach ($columns as $i => $label) {
        $normalized = mb_strtolower(trim((string) $label));
        foreach ($needles as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return $i;
            }
        }
    }
    return null;
}
