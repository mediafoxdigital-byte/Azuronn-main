<?php

declare(strict_types=1);

require_once __DIR__ . '/supabase.php';

function admin_employees_file(): string
{
    return content_data_dir() . '/admin-employees.json';
}

function admin_clean_employee_item(array $item, int $index = 0): array
{
    $name = clean_string((string) ($item['name'] ?? ''), 120);
    $username = clean_string((string) ($item['username'] ?? ''), 120);
    $status = strtolower(clean_string((string) ($item['status'] ?? 'active'), 20));
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    return [
        'id' => clean_string((string) ($item['id'] ?? ('emp-admin-' . ($index + 1))), 80),
        'name' => $name !== '' ? $name : ('Employee ' . ($index + 1)),
        'username' => $username,
        'password_hash' => clean_string((string) ($item['password_hash'] ?? ''), 255),
        'status' => $status,
        'created_at' => clean_string((string) ($item['created_at'] ?? ''), 40),
        'updated_at' => clean_string((string) ($item['updated_at'] ?? ''), 40),
    ];
}

function admin_load_employee_accounts(): array
{
    if (supabase_enabled()) {
        $items = array_values(array_filter(supabase_list_admin_users(), static fn (array $user): bool => (string) ($user['role'] ?? '') === 'employee'));
        return array_values(array_map('admin_clean_employee_item', $items, array_keys($items)));
    }

    $file = admin_employees_file();
    if (!is_file($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_map('admin_clean_employee_item', $decoded, array_keys($decoded)));
}

function admin_save_employee_accounts(array $items): void
{
    $clean = array_values(array_map('admin_clean_employee_item', $items, array_keys($items)));

    if (supabase_enabled()) {
        $existing = array_values(array_filter(supabase_list_admin_users(), static fn (array $user): bool => (string) ($user['role'] ?? '') === 'employee'));
        $existingIds = array_column($existing, 'id');
        $keepIds = array_column($clean, 'id');

        $ok = true;
        foreach ($clean as $employee) {
            if (!supabase_upsert_admin_user($employee + ['role' => 'employee'])) {
                $ok = false;
            }
        }
        foreach ($existingIds as $existingId) {
            if (!in_array($existingId, $keepIds, true) && !supabase_delete_admin_user($existingId)) {
                $ok = false;
            }
        }

        if ($ok) {
            return;
        }
    }

    ensure_content_storage();
    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    file_put_contents(admin_employees_file(), $json, LOCK_EX);
}

function admin_find_employee_account(array $items, string $employeeId): ?array
{
    foreach ($items as $item) {
        if ((string) ($item['id'] ?? '') === $employeeId) {
            return $item;
        }
    }
    return null;
}

function admin_find_employee_by_username(string $username): ?array
{
    if (!supabase_enabled()) {
        foreach (admin_load_employee_accounts() as $item) {
            if (strcasecmp((string) ($item['username'] ?? ''), $username) === 0) {
                return $item;
            }
        }
        return null;
    }

    $user = supabase_get_admin_user_by_username($username);
    if ($user === null || (string) ($user['role'] ?? '') !== 'employee') {
        return null;
    }
    return $user;
}

function admin_employee_accounts_with_fallback(): array
{
    $items = admin_load_employee_accounts();
    $hasFallback = false;
    foreach ($items as $item) {
        if (strcasecmp((string) ($item['username'] ?? ''), EMPLOYEE_ADMIN_USERNAME) === 0) {
            $hasFallback = true;
            break;
        }
    }

    if (!$hasFallback) {
        $items[] = admin_clean_employee_item([
            'id' => 'emp-admin-default',
            'name' => 'Default Employee Admin',
            'username' => EMPLOYEE_ADMIN_USERNAME,
            'password_hash' => EMPLOYEE_ADMIN_PASSWORD_HASH,
            'status' => 'active',
            'created_at' => '',
            'updated_at' => '',
        ], count($items));
    }

    return $items;
}

function admin_upsert_employee_account(array $input, ?string $employeeId = null): array
{
    $items = admin_load_employee_accounts();
    $existing = $employeeId !== null ? admin_find_employee_account($items, $employeeId) : null;
    $now = date('Y-m-d H:i');

    $username = clean_string((string) ($input['username'] ?? ''), 120);
    $name = clean_string((string) ($input['name'] ?? ''), 120);
    $status = strtolower(clean_string((string) ($input['status'] ?? 'active'), 20));
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    $password = (string) ($input['password'] ?? '');
    $passwordHash = (string) ($existing['password_hash'] ?? '');
    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    }

    $employee = admin_clean_employee_item([
        'id' => $employeeId ?? ('emp-admin-' . date('YmdHis') . '-' . bin2hex(random_bytes(3))),
        'name' => $name,
        'username' => $username,
        'password_hash' => $passwordHash,
        'status' => $status,
        'created_at' => (string) ($existing['created_at'] ?? $now),
        'updated_at' => $now,
    ], count($items));

    $targetIndex = null;
    foreach ($items as $index => $item) {
        if ((string) ($item['id'] ?? '') === (string) $employee['id']) {
            $targetIndex = $index;
            break;
        }
    }

    if ($targetIndex === null) {
        $items[] = $employee;
    } else {
        $items[$targetIndex] = $employee;
    }

    admin_save_employee_accounts($items);
    return $employee;
}

function admin_delete_employee_account(string $employeeId): bool
{
    $items = admin_load_employee_accounts();
    $filtered = array_values(array_filter($items, static fn (array $item): bool => (string) ($item['id'] ?? '') !== $employeeId));
    if (count($filtered) === count($items)) {
        return false;
    }

    admin_save_employee_accounts($filtered);
    return true;
}

function admin_employee_display_name(?array $employee): string
{
    if (!is_array($employee)) {
        return '';
    }

    $name = clean_string((string) ($employee['name'] ?? ''), 120);
    $username = clean_string((string) ($employee['username'] ?? ''), 120);
    if ($name !== '' && $username !== '') {
        return $name . ' (@' . $username . ')';
    }
    return $name !== '' ? $name : $username;
}
