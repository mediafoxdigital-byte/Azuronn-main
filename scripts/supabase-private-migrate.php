#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * supabase-private-migrate.php
 *
 * One-shot migration: read everything currently sitting in JSON files on disk
 * and push it into the dedicated Supabase tables, then strip the private
 * records out of those JSON files (or delete them outright).
 *
 * What it migrates:
 *   site-content.json    →  customers, orders, newsletter subscribers
 *   appointments.json    →  appointments (config + bookings)
 *   admin-employees.json →  admin_users (role='employee')
 *   admin-requests.json  →  admin_requests
 *   env AZURONN_ADMIN_USERNAME / _PASSWORD_HASH → admin_users (role='super')
 *   admin-login-attempts.json / *_login_attempts.json  → app_state keys
 *
 * What it deletes from disk after a green run:
 *   appointments.json          appointments.lock
 *   admin-employees.json       admin-requests.json
 *   admin-login-attempts.json  admin_login_attempts.json
 *   employee_admin_login_attempts.json
 *
 * What it strips from disk:
 *   site-content.json  →  drops customers.items / orders.items /
 *                          newsletter.subscribers before the file is rewritten
 *
 * Aborts before any destructive step if any Supabase write fails. Re-run is
 * idempotent: every private-table upsert uses the row's primary key, so a
 * second run just refreshes the same rows.
 */

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin-auth.php';

if (!supabase_enabled()) {
    fwrite(STDERR, "Supabase is not configured. Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY.\n");
    exit(1);
}

if (!supabase_private_write_enabled()) {
    fwrite(STDERR, "SUPABASE_SERVICE_ROLE_KEY is missing — private-table writes need it.\n");
    exit(1);
}

$summary = [
    'customers_uploaded' => 0,
    'orders_uploaded' => 0,
    'newsletter_uploaded' => 0,
    'appointments_saved' => 0,
    'admin_users_uploaded' => 0,
    'admin_requests_uploaded' => 0,
    'login_attempts_seeded' => 0,
    'site_content_stripped' => false,
    'json_files_deleted' => [],
    'errors' => [],
];

function migrate_log(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function migrate_fail(string $key, string $message): void
{
    global $summary;
    $summary['errors'][] = $key . ': ' . $message;
    migrate_log('[FAIL] ' . $key . ' — ' . $message);
}

// ── site-content.json → customers + orders + newsletter ────────────────────
$siteContentPath = content_data_dir() . '/site-content.json';
$siteContentRaw = [];
if (is_file($siteContentPath)) {
    $decoded = json_decode((string) file_get_contents($siteContentPath), true);
    if (is_array($decoded)) {
        $siteContentRaw = $decoded;
    }
}

$customers = is_array($siteContentRaw['customers']['items'] ?? null) ? $siteContentRaw['customers']['items'] : [];
$orders = is_array($siteContentRaw['orders']['items'] ?? null) ? $siteContentRaw['orders']['items'] : [];
$subscribers = is_array($siteContentRaw['newsletter']['subscribers'] ?? null) ? $siteContentRaw['newsletter']['subscribers'] : [];

foreach ($customers as $customer) {
    if (!is_array($customer)) {
        continue;
    }
    if (supabase_upsert_customer($customer)) {
        $summary['customers_uploaded']++;
    } else {
        migrate_fail('customer', 'failed to upload row id=' . ($customer['id'] ?? ''));
    }
}

foreach ($orders as $order) {
    if (!is_array($order)) {
        continue;
    }
    if (supabase_upsert_order($order)) {
        $summary['orders_uploaded']++;
    } else {
        migrate_fail('order', 'failed to upload row id=' . ($order['id'] ?? ''));
    }
}

foreach ($subscribers as $subscriber) {
    if (!is_array($subscriber)) {
        continue;
    }
    if (supabase_upsert_newsletter_subscriber($subscriber)) {
        $summary['newsletter_uploaded']++;
    } else {
        migrate_fail('newsletter_subscriber', 'failed to upload row id=' . ($subscriber['id'] ?? ''));
    }
}

// ── appointments.json → appointments table ─────────────────────────────────
$appointmentsPath = content_data_dir() . '/appointments.json';
$appointmentsRaw = ['config' => [], 'bookings' => []];
if (is_file($appointmentsPath)) {
    $decoded = json_decode((string) file_get_contents($appointmentsPath), true);
    if (is_array($decoded)) {
        $appointmentsRaw = $decoded;
    }
}
if (supabase_save_appointments((array) ($appointmentsRaw['config'] ?? []), (array) ($appointmentsRaw['bookings'] ?? []))) {
    $summary['appointments_saved']++;
} else {
    migrate_fail('appointments', 'failed to save appointments row');
}

// ── admin-employees.json → admin_users (role='employee') ────────────────────
$employeesPath = content_data_dir() . '/admin-employees.json';
if (is_file($employeesPath)) {
    $decoded = json_decode((string) file_get_contents($employeesPath), true);
    if (is_array($decoded)) {
        foreach ($decoded as $employee) {
            if (!is_array($employee)) {
                continue;
            }
            if (supabase_upsert_admin_user($employee + ['role' => 'employee'])) {
                $summary['admin_users_uploaded']++;
            } else {
                migrate_fail('admin_user', 'failed to upload employee id=' . ($employee['id'] ?? ''));
            }
        }
    }
}

// ── Super admin bootstrap: env vars → admin_users (role='super') ──────────
$envSuperUser = getenv('AZURONN_ADMIN_USERNAME');
$envSuperHash = getenv('AZURONN_ADMIN_PASSWORD_HASH');
if (is_string($envSuperUser) && $envSuperUser !== '' && is_string($envSuperHash) && $envSuperHash !== '') {
    $superRow = [
        'id' => 'super-admin-env',
        'role' => 'super',
        'name' => $envSuperUser,
        'username' => $envSuperUser,
        'password_hash' => $envSuperHash,
        'status' => 'active',
    ];
    if (supabase_upsert_admin_user($superRow)) {
        $summary['admin_users_uploaded']++;
        migrate_log('[OK] super admin uploaded from env vars');
    } else {
        migrate_fail('super_admin_env', 'failed to upload super admin row');
    }
} else {
    migrate_log('[INFO] no AZURONN_ADMIN_USERNAME / _PASSWORD_HASH env vars; skipping env-based super admin.');
}

// ── admin-requests.json → admin_requests ────────────────────────────────────
$requestsPath = content_data_dir() . '/admin-requests.json';
if (is_file($requestsPath)) {
    $decoded = json_decode((string) file_get_contents($requestsPath), true);
    if (is_array($decoded)) {
        foreach ($decoded as $request) {
            if (!is_array($request)) {
                continue;
            }
            if (supabase_upsert_admin_request($request)) {
                $summary['admin_requests_uploaded']++;
            } else {
                migrate_fail('admin_request', 'failed to upload request id=' . ($request['id'] ?? ''));
            }
        }
    }
}

// ── Login-attempts JSON → app_state keys ────────────────────────────────────
$attemptKeys = [
    'admin-login-attempts.json' => 'admin_login_attempts',
    'admin_login_attempts.json' => 'admin_login_attempts',
    'employee_admin_login_attempts.json' => 'employee_admin_login_attempts',
];
foreach ($attemptKeys as $filename => $stateKey) {
    $path = content_data_dir() . '/' . $filename;
    if (!is_file($path)) {
        continue;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        continue;
    }
    if (supabase_write_state($stateKey, $decoded)) {
        $summary['login_attempts_seeded']++;
        migrate_log('[OK] login attempts seeded into app_state:' . $stateKey);
    } else {
        migrate_fail('login_attempts:' . $filename, 'failed to seed ' . $stateKey);
    }
}

// ── Stop here if anything failed before destructive steps ───────────────────
if ($summary['errors'] !== []) {
    migrate_log('');
    migrate_log('Aborting before destructive cleanup because of the errors above. Re-run after fixing them.');
    foreach ($summary as $key => $value) {
        if ($key === 'errors') {
            continue;
        }
        migrate_log('  ' . $key . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value));
    }
    exit(2);
}

// ── Strip private keys out of site-content.json ────────────────────────────
if (is_file($siteContentPath)) {
    unset($siteContentRaw['customers'], $siteContentRaw['orders']);
    if (isset($siteContentRaw['newsletter']) && is_array($siteContentRaw['newsletter'])) {
        $siteContentRaw['newsletter']['subscribers'] = [];
    }
    $json = json_encode($siteContentRaw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        if (file_put_contents($siteContentPath, $json) !== false) {
            $summary['site_content_stripped'] = true;
            migrate_log('[OK] site-content.json stripped of private keys');
        } else {
            migrate_fail('site_content_strip', 'failed to rewrite site-content.json');
        }
    } else {
        migrate_fail('site_content_strip', 'failed to encode site-content.json');
    }
}

// ── Delete the JSON files that now hold only Supabase-owned data ───────────
$filesToDelete = [
    'appointments.json',
    'appointments.lock',
    'admin-employees.json',
    'admin-requests.json',
    'admin-login-attempts.json',
    'admin_login_attempts.json',
    'employee_admin_login_attempts.json',
];
foreach ($filesToDelete as $filename) {
    $path = content_data_dir() . '/' . $filename;
    if (!is_file($path)) {
        continue;
    }
    if (@unlink($path)) {
        $summary['json_files_deleted'][] = $filename;
        migrate_log('[OK] deleted ' . $filename);
    } else {
        migrate_fail('delete:' . $filename, 'failed to delete ' . $filename);
    }
}

migrate_log('');
migrate_log('Migration complete:');
foreach ($summary as $key => $value) {
    if ($key === 'errors') {
        continue;
    }
    migrate_log('  ' . $key . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value));
}
if ($summary['errors'] !== []) {
    migrate_log('');
    migrate_log('Errors:');
    foreach ($summary['errors'] as $error) {
        migrate_log('  ' . $error);
    }
    exit(2);
}

exit(0);
