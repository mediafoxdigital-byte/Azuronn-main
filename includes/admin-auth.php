<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-employees.php';

function admin_portal_mode(): string
{
    $mode = defined('ADMIN_PORTAL_MODE') ? (string) ADMIN_PORTAL_MODE : 'super';
    return $mode === 'employee' ? 'employee' : 'super';
}

function admin_is_employee_portal(): bool
{
    return admin_portal_mode() === 'employee';
}

function admin_is_super_portal(): bool
{
    return !admin_is_employee_portal();
}

function admin_portal_title(): string
{
    return admin_is_employee_portal() ? 'Employee Admin' : 'Super Admin';
}

function admin_session_key(): string
{
    return admin_is_employee_portal() ? 'employee_admin_auth' : 'admin_auth';
}

function admin_attempts_state_key(): string
{
    return admin_is_employee_portal() ? 'employee_admin_login_attempts' : 'admin_login_attempts';
}

function admin_attempts_file(): string
{
    return content_data_dir() . '/' . admin_attempts_state_key() . '.json';
}

function admin_client_key(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return preg_replace('/[^a-zA-Z0-9:\.]/', '', $ip) ?: 'unknown';
}

function admin_load_attempts(): array
{
    if (supabase_enabled()) {
        $attempts = supabase_read_state(admin_attempts_state_key());
        if (is_array($attempts)) {
            return $attempts;
        }
    }

    $file = admin_attempts_file();
    if (!is_file($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function admin_save_attempts(array $attempts): void
{
    if (supabase_enabled() && supabase_write_state(admin_attempts_state_key(), $attempts)) {
        return;
    }

    ensure_content_storage();
    $json = json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    file_put_contents(admin_attempts_file(), $json, LOCK_EX);
}

function admin_attempt_state(): array
{
    $attempts = admin_load_attempts();
    $key = admin_client_key();
    $state = $attempts[$key] ?? ['count' => 0, 'first' => time(), 'locked_until' => 0];

    if (($state['locked_until'] ?? 0) < time() && ($state['first'] ?? 0) < (time() - ADMIN_LOCKOUT_MINUTES * 60)) {
        $state = ['count' => 0, 'first' => time(), 'locked_until' => 0];
    }

    return $state;
}

function admin_is_locked(): bool
{
    $state = admin_attempt_state();
    return (int) ($state['locked_until'] ?? 0) > time();
}

function admin_lock_remaining_minutes(): int
{
    $state = admin_attempt_state();
    $seconds = max(0, (int) ($state['locked_until'] ?? 0) - time());
    return (int) ceil($seconds / 60);
}

function admin_register_failed_login(): void
{
    $attempts = admin_load_attempts();
    $key = admin_client_key();
    $state = $attempts[$key] ?? ['count' => 0, 'first' => time(), 'locked_until' => 0];

    if (($state['first'] ?? 0) < (time() - ADMIN_LOCKOUT_MINUTES * 60)) {
        $state = ['count' => 0, 'first' => time(), 'locked_until' => 0];
    }

    $state['count'] = (int) ($state['count'] ?? 0) + 1;
    $state['first'] = (int) ($state['first'] ?? time());

    if ($state['count'] >= ADMIN_MAX_LOGIN_ATTEMPTS) {
        $state['locked_until'] = time() + (ADMIN_LOCKOUT_MINUTES * 60);
        $state['count'] = 0;
        $state['first'] = time();
    }

    $attempts[$key] = $state;
    admin_save_attempts($attempts);
}

function admin_clear_failed_logins(): void
{
    $attempts = admin_load_attempts();
    unset($attempts[admin_client_key()]);
    admin_save_attempts($attempts);
}

function admin_fingerprint(): string
{
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . '|' . admin_client_key());
}

function admin_is_authenticated(): bool
{
    $sessionKey = admin_session_key();
    if (empty($_SESSION[$sessionKey]['logged_in'])) {
        return false;
    }

    $auth = $_SESSION[$sessionKey];
    if (($auth['fingerprint'] ?? '') !== admin_fingerprint()) {
        admin_logout();
        return false;
    }

    if ((int) ($auth['last_seen'] ?? 0) < (time() - ADMIN_IDLE_TIMEOUT)) {
        admin_logout();
        return false;
    }

    $_SESSION[$sessionKey]['last_seen'] = time();
    return true;
}

function admin_login(string $username, string $password): bool
{
    $expectedUsername = $username;
    $expectedPasswordHash = '';
    $displayName = $expectedUsername;
    $matched = false;

    if (admin_is_employee_portal()) {
        // Employee admins live in admin_users with role='employee'. The
        // env-var bootstrap fallback (EMPLOYEE_ADMIN_USERNAME /
        // EMPLOYEE_ADMIN_PASSWORD_HASH) is intentionally disabled — once
        // private data has been migrated to Supabase, the employee row is
        // the only authority.
        $matched = false;
        if (supabase_enabled()) {
            $account = supabase_get_admin_user_by_username($username);
            if (is_array($account) && (string) ($account['role'] ?? '') === 'employee') {
                $matched = true;
            }
        }

        if ($matched) {
            if (strtolower((string) ($account['status'] ?? 'active')) !== 'active') {
                return false;
            }
            $expectedUsername = (string) ($account['username'] ?? '');
            $expectedPasswordHash = (string) ($account['password_hash'] ?? '');
            $displayName = (string) ($account['name'] ?? $expectedUsername);
        } else {
            // No matching employee row found. Refuse login rather than
            // silently falling back to a stale env-var password hash.
            return false;
        }
    } else {
        // Super admin: must come from the admin_users row in Supabase.
        // The legacy env-var bootstrap (ADMIN_USERNAME / ADMIN_PASSWORD_HASH)
        // is intentionally disabled — the super admin row is the only
        // authority once private data has been migrated to Supabase.
        $super = supabase_enabled() ? supabase_get_admin_user_by_username($username) : null;
        if (is_array($super) && (string) ($super['role'] ?? '') === 'super') {
            if (strtolower((string) ($super['status'] ?? 'active')) !== 'active') {
                return false;
            }
            $expectedUsername = (string) ($super['username'] ?? '');
            $expectedPasswordHash = (string) ($super['password_hash'] ?? '');
            $displayName = (string) ($super['name'] ?? $expectedUsername);
        } else {
            // No matching super admin row found. Refuse login rather than
            // silently falling back to a stale env-var password hash.
            return false;
        }
    }

    if (!hash_equals($expectedUsername, $username)) {
        return false;
    }

    if ($expectedPasswordHash === '' || !password_verify($password, $expectedPasswordHash)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION[admin_session_key()] = [
        'logged_in' => true,
        'username' => $expectedUsername,
        'name' => $displayName,
        'portal' => admin_portal_mode(),
        'last_seen' => time(),
        'fingerprint' => admin_fingerprint(),
    ];

    admin_clear_failed_logins();
    return true;
}

function admin_logout(): void
{
    unset($_SESSION[admin_session_key()]);
    session_regenerate_id(true);
}

function admin_require_auth(): void
{
    if (!admin_is_authenticated()) {
        redirect($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    }
}
