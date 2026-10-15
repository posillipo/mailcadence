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
        ensureAdminsTable($pdo);
        ensureSmtpProfilesTable($pdo);
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

// La tabella "admins" (account creato da /setup.php) è stata aggiunta dopo il primo rilascio:
// va creata esplicitamente qui, non solo in schema.sql, così i deployment già esistenti (dove
// "contacts" c'è già e l'import completo sopra non viene rieseguito) la ricevono comunque al
// prossimo avvio.
function ensureAdminsTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_admins_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Tabella dei profili SMTP (vedi src/mailer.php) e colonna contact_lists.smtp_profile_id per
// assegnarne uno specifico a una lista: aggiunte dopo i primi rilasci (che avevano una sola
// configurazione SMTP, tabella "smtp_settings"), quindi create/migrate qui esplicitamente così
// arrivano anche ai deployment già esistenti, dove "contacts" c'è già e l'import completo di
// schema.sql non viene rieseguito.
function ensureSmtpProfilesTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS smtp_profiles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        host VARCHAR(255) NOT NULL,
        port SMALLINT UNSIGNED NOT NULL,
        username VARCHAR(255) NOT NULL DEFAULT '',
        password VARCHAR(255) NOT NULL DEFAULT '',
        secure ENUM('tls','ssl','') NOT NULL DEFAULT 'tls',
        from_email VARCHAR(255) NOT NULL,
        from_name VARCHAR(255) NOT NULL,
        is_default TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_smtp_profiles_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $colExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_lists' AND COLUMN_NAME = 'smtp_profile_id'"
    )->fetchColumn();
    if ((int) $colExists === 0) {
        $pdo->exec('ALTER TABLE contact_lists ADD COLUMN smtp_profile_id INT UNSIGNED NULL AFTER description');
    }

    $fkExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_lists'
           AND CONSTRAINT_NAME = 'fk_contact_lists_smtp_profile' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchColumn();
    if ((int) $fkExists === 0) {
        $pdo->exec(
            'ALTER TABLE contact_lists ADD CONSTRAINT fk_contact_lists_smtp_profile
             FOREIGN KEY (smtp_profile_id) REFERENCES smtp_profiles(id) ON DELETE SET NULL'
        );
    }

    migrateLegacySmtpSettings($pdo);
}

// Se esiste ancora la vecchia tabella "smtp_settings" (configurazione unica, pre-profili) e non è
// mai stato creato nessun profilo, la trasforma nel primo profilo (predefinito): così chi aveva
// già configurato e testato l'SMTP prima di questo aggiornamento non se lo ritrova vuoto.
function migrateLegacySmtpSettings(PDO $pdo): void {
    $hasProfiles = (int) $pdo->query('SELECT COUNT(*) FROM smtp_profiles')->fetchColumn();
    if ($hasProfiles > 0) {
        return;
    }
    try {
        $legacy = $pdo->query(
            'SELECT host, port, username, password, secure, from_email, from_name FROM smtp_settings ORDER BY id DESC LIMIT 1'
        )->fetch();
    } catch (PDOException $e) {
        return; // tabella smtp_settings assente: deployment nuovo, mai esistita prima dei profili
    }
    if ($legacy === false) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO smtp_profiles (name, host, port, username, password, secure, from_email, from_name, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        'Predefinito',
        $legacy['host'], $legacy['port'], $legacy['username'], $legacy['password'],
        $legacy['secure'], $legacy['from_email'], $legacy['from_name'],
    ]);
}

// Scostamento UTC corrente (es. "+02:00") per il fuso orario dell'app (vedi date.timezone in
// Dockerfile), calcolato da PHP invece che affidato a "SET time_zone = 'Europe/Rome'" lato MySQL:
// quest'ultimo richiederebbe le tabelle di fuso orario caricate nel server MySQL, che su un DB
// condiviso con un'altra applicazione (non gestito da questo repo) potrebbero non esserci. Uno
// scostamento numerico invece funziona sempre, e viene ricalcolato a ogni connessione così tiene
// conto da solo del passaggio tra ora legale e solare.
function appTimezoneOffset(): string {
    $offset = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('P');
    return preg_match('/^[+-]\d{2}:\d{2}$/', $offset) ? $offset : '+00:00';
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
        $pdo->exec("SET time_zone = '" . appTimezoneOffset() . "'");
        ensureSchema($pdo);
    }
    return $pdo;
}
