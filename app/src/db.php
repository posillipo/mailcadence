<?php
// Connessione PDO al database MySQL. Le credenziali arrivano sempre da variabili d'ambiente del
// container (DB_HOST/DB_NAME/DB_USER/DB_PASS) — a differenza di chifacosa non esiste un wizard
// da browser, quindi non serve nessuna configurazione scritta su file.

function getDbCredentials(): array {
    return [
        'host' => getenv('DB_HOST') ?: 'db',
        'name' => getenv('DB_NAME') ?: 'mailcadence',
        'user' => getenv('DB_USER') ?: 'mailcadence_user',
        'pass' => getenv('DB_PASS') ?: '',
    ];
}

// Applica lo schema (database/schema.sql, copiato nell'immagine Docker) se la tabella "contacts"
// non esiste ancora. Così un container nuovo con un database vuoto si auto-inizializza al primo
// avvio, senza bisogno di un passaggio manuale.
function ensureSchema(PDO $pdo): void {
    try {
        $pdo->query('SELECT 1 FROM contacts LIMIT 1');
        return; // schema già presente
    } catch (PDOException $e) {
        // tabella mancante: si procede con l'import qui sotto
    }

    $path = '/var/www/database/schema.sql';
    $sql = @file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Schema non trovato in {$path} e tabella 'contacts' assente: impossibile inizializzare il database.");
    }

    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = getDbCredentials();
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
        ensureSchema($pdo);
    }
    return $pdo;
}
