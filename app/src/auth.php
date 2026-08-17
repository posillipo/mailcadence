<?php
require_once __DIR__ . '/functions.php';

// Autenticazione a singolo amministratore: non esiste una tabella "users" multiutente.
// Le credenziali possono arrivare in due modi:
//  - da .env (ADMIN_EMAIL / ADMIN_PASSWORD_HASH), per chi preferisce configurarle prima del deploy;
//  - dalla tabella "admins" (una sola riga), creata tramite la pagina /setup.php al primo avvio
//    se non è ancora presente nessun amministratore. Il DB ha la precedenza sul .env.

function adminEmail(): string {
    return getenv('ADMIN_EMAIL') ?: '';
}

function adminPasswordHash(): string {
    return getenv('ADMIN_PASSWORD_HASH') ?: '';
}

// Amministratore creato da /setup.php, oppure null se non esiste ancora.
function getStoredAdmin(): ?array {
    $stmt = getDB()->query('SELECT email, password_hash FROM admins ORDER BY id ASC LIMIT 1');
    $row = $stmt->fetch();
    return $row ?: null;
}

// Combina la sorgente DB (prioritaria) con quella .env, per capire con quali credenziali si può
// effettivamente accedere in questo momento.
function currentAdminCredentials(): ?array {
    $stored = getStoredAdmin();
    if ($stored !== null) {
        return $stored;
    }
    if (adminEmail() !== '' && adminPasswordHash() !== '') {
        return ['email' => adminEmail(), 'password_hash' => adminPasswordHash()];
    }
    return null;
}

// True se esiste già un amministratore (DB o .env): in tal caso /setup.php non deve più essere
// utilizzabile, per evitare che chiunque possa crearsi un secondo account.
function hasAdmin(): bool {
    return currentAdminCredentials() !== null;
}

function createAdmin(string $email, string $password): void {
    $stmt = getDB()->prepare('INSERT INTO admins (email, password_hash) VALUES (?, ?)');
    $stmt->execute([strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT)]);
}

function attemptLogin(string $email, string $password): bool {
    $admin = currentAdminCredentials();
    if ($admin === null) {
        return false;
    }
    if (!hash_equals(strtolower($admin['email']), strtolower($email))) {
        return false;
    }
    if (!password_verify($password, $admin['password_hash'])) {
        return false;
    }
    $_SESSION['admin'] = true;
    session_regenerate_id(true);
    return true;
}

function isLoggedIn(): bool {
    return $_SESSION['admin'] ?? false;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('/login.php');
    }
}

function logoutAdmin(): void {
    $_SESSION = [];
    session_destroy();
}
