<?php
require_once __DIR__ . '/functions.php';

// Autenticazione a singolo amministratore: non esiste una tabella "users", le credenziali sono
// nel .env del server (ADMIN_EMAIL / ADMIN_PASSWORD_HASH). Coerente con l'uso previsto
// dell'app — uno strumento personale, non multiutente.

function adminEmail(): string {
    return getenv('ADMIN_EMAIL') ?: '';
}

function adminPasswordHash(): string {
    return getenv('ADMIN_PASSWORD_HASH') ?: '';
}

function attemptLogin(string $email, string $password): bool {
    $hash = adminPasswordHash();
    if ($hash === '' || adminEmail() === '') {
        // Nessuna credenziale configurata: evitiamo di far passare chiunque, l'app resta
        // inutilizzabile finché ADMIN_EMAIL/ADMIN_PASSWORD_HASH non sono impostate nel .env.
        return false;
    }
    if (!hash_equals(strtolower(adminEmail()), strtolower($email))) {
        return false;
    }
    if (!password_verify($password, $hash)) {
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
