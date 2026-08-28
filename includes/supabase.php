<?php

declare(strict_types=1);

function app_runtime_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $path = dirname(__DIR__) . '/data/runtime-config.php';
    if (!is_file($path)) {
        $config = [];
        return $config;
    }

    $loaded = require $path;
    $config = is_array($loaded) ? $loaded : [];
    return $config;
}

function app_runtime_config_value(string $key, string $default = ''): string
{
    $config = app_runtime_config();
    $value = $config[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function supabase_project_url(): string
{
    // No hardcoded fallback. SUPABASE_URL must be set on the host (env var
    // or data/runtime-config.php). Hardcoded values were removed so a stale
    // copy of this file cannot accidentally talk to a previous project's DB.
    $url = trim((string) (
        app_runtime_config_value('supabase_url')
        ?: app_runtime_config_value('SUPABASE_URL')
        ?:
        getenv('SUPABASE_URL')
        ?: getenv('SUPABASE_PROJECT_URL')
    ));

    return rtrim($url, '/');
}

function supabase_publishable_key(): string
{
    return trim((string) (
        app_runtime_config_value('supabase_publishable_key')
        ?: app_runtime_config_value('SUPABASE_PUBLISHABLE_KEY')
        ?:
        getenv('SUPABASE_PUBLISHABLE_KEY')
        ?: getenv('SUPABASE_ANON_KEY')
    ));
}

function supabase_service_role_key(): string
{
    return trim((string) (
        app_runtime_config_value('supabase_service_role_key')
        ?: app_runtime_config_value('SUPABASE_SERVICE_ROLE_KEY')
        ?:
        getenv('SUPABASE_SERVICE_ROLE_KEY')
        ?: getenv('SUPABASE_SECRET_KEY')
    ));
}

function supabase_key_for_request(bool $write = false): string
{
    $serviceKey = supabase_service_role_key();
    if ($write && $serviceKey !== '') {
        return $serviceKey;
    }

    return $serviceKey !== '' ? $serviceKey : supabase_publishable_key();
}

function supabase_enabled(): bool
{
    return supabase_project_url() !== '' && supabase_key_for_request() !== '';
}

function supabase_private_write_enabled(): bool
{
    return supabase_service_role_key() !== '';
}

function supabase_rest_headers(bool $write = false, bool $returnRepresentation = false, bool $mergeDuplicates = false): array
{
    $key = supabase_key_for_request($write);
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    if ($write) {
        $preferParts = [$returnRepresentation ? 'return=representation' : 'return=minimal'];
        if ($mergeDuplicates) {
            $preferParts[] = 'resolution=merge-duplicates';
        }
        $headers[] = 'Prefer: ' . implode(',', $preferParts);
    }

    return $headers;
}

function supabase_http_status(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function supabase_http_request(string $method, string $path, array $query = [], ?array $payload = null, bool $write = false, bool $returnRepresentation = false, bool $mergeDuplicates = false, int $timeoutSeconds = 20): array
{
    if (!supabase_enabled()) {
        return ['ok' => false, 'status' => 0, 'error' => 'Supabase is not configured.'];
    }

    $url = supabase_project_url() . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'ignore_errors' => true,
            'timeout' => max(1, $timeoutSeconds),
            'header' => implode("\r\n", supabase_rest_headers($write, $returnRepresentation, $mergeDuplicates)),
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'Unable to encode payload.'];
        }
        $options['http']['content'] = $json;
    }

    $context = stream_context_create($options);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = supabase_http_status($headers);
    $decoded = null;

    if (is_string($body) && trim($body) !== '') {
        $decoded = json_decode($body, true);
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'headers' => $headers,
        'body' => is_string($body) ? $body : '',
        'json' => is_array($decoded) ? $decoded : null,
        'error' => ($status >= 200 && $status < 300) ? '' : ((is_array($decoded) ? ($decoded['message'] ?? $decoded['hint'] ?? '') : '') ?: 'Supabase request failed.'),
    ];
}

function supabase_filter_query(array $filters): array
{
    $query = [];
    foreach ($filters as $column => $value) {
        if ($value === null) {
            $query[$column] = 'is.null';
            continue;
        }

        $query[$column] = 'eq.' . (string) $value;
    }

    return $query;
}

function supabase_select_rows(string $table, array $filters = [], string $columns = '*', array $extraQuery = []): array
{
    $query = array_merge(['select' => $columns], supabase_filter_query($filters), $extraQuery);
    $result = supabase_http_request('GET', '/rest/v1/' . rawurlencode($table), $query);
    if (!($result['ok'] ?? false) || !is_array($result['json'] ?? null)) {
        return [];
    }

    return array_values(array_filter($result['json'], 'is_array'));
}

function supabase_select_first(string $table, array $filters = [], string $columns = '*', array $extraQuery = []): ?array
{
    $rows = supabase_select_rows($table, $filters, $columns, array_merge($extraQuery, ['limit' => 1]));
    return $rows[0] ?? null;
}

function supabase_upsert_rows(string $table, array $rows, string $onConflict = '', int $timeoutSeconds = 20): bool
{
    if ($rows === []) {
        return true;
    }

    $query = [];
    if ($onConflict !== '') {
        $query['on_conflict'] = $onConflict;
    }

    $result = supabase_http_request('POST', '/rest/v1/' . rawurlencode($table), $query, $rows, true, false, $onConflict !== '', $timeoutSeconds);
    return (bool) ($result['ok'] ?? false);
}

function supabase_delete_rows(string $table, array $filters): bool
{
    if ($filters === []) {
        return false;
    }

    $result = supabase_http_request('DELETE', '/rest/v1/' . rawurlencode($table), supabase_filter_query($filters), null, true);
    return (bool) ($result['ok'] ?? false);
}

function supabase_state_key(string $name): string
{
    return preg_replace('/[^a-z0-9_\-]/i', '_', trim($name)) ?: 'state';
}

function supabase_read_state(string $key): ?array
{
    $row = supabase_select_first('app_state', ['key' => supabase_state_key($key)], 'key,payload,updated_at');
    if ($row === null || !is_array($row['payload'] ?? null)) {
        return null;
    }

    return $row['payload'];
}

function supabase_write_state(string $key, array $payload): bool
{
    return supabase_upsert_rows('app_state', [[
        'key' => supabase_state_key($key),
        'payload' => $payload,
        'updated_at' => gmdate('c'),
    ]], 'key');
}

function supabase_register_media_assets(array $assets): bool
{
    $rows = [];
    $timestamp = gmdate('c');
    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $publicUrl = trim((string) ($asset['public_url'] ?? ''));
        if ($publicUrl === '') {
            continue;
        }
        $rows[] = [
            'public_url' => $publicUrl,
            'file_path' => trim((string) ($asset['file_path'] ?? '')),
            'file_name' => trim((string) ($asset['file_name'] ?? '')),
            'mime_type' => trim((string) ($asset['mime_type'] ?? '')),
            'media_type' => trim((string) ($asset['media_type'] ?? 'file')),
            'file_size' => max(0, (int) ($asset['file_size'] ?? 0)),
            'source' => trim((string) ($asset['source'] ?? 'hosting')),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    if ($rows === []) {
        return false;
    }

    // Media metadata is supplementary: the saved content already references
    // the uploaded file. Keep this best-effort sync from delaying an admin save.
    return supabase_upsert_rows('media_assets', $rows, 'public_url', 5);
}

function supabase_register_media_asset(array $asset): bool
{
    return supabase_register_media_assets([$asset]);
}

// ── Row normalization for private tables ────────────────────────────────────
// Keeping these helpers next to the existing supabase_* wrappers so every
// private-table CRUD call goes through one chokepoint that strips unexpected
// keys, normalizes timestamps, and converts money values to a canonical shape.

function supabase_iso_to_datetime(?string $value): ?string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
}

function supabase_optional_text(mixed $value, int $maxLength = 500): string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLength);
    } else {
        $value = substr($value, 0, $maxLength);
    }
    return $value;
}

function supabase_customer_to_row(array $customer): array
{
    $wishlist = array_values(array_filter(
        array_map(static fn (mixed $id): string => is_scalar($id) ? trim((string) $id) : '', (array) ($customer['wishlist_product_ids'] ?? [])),
        static fn (string $id): bool => $id !== ''
    ));

    $addresses = [];
    foreach ((array) ($customer['saved_addresses'] ?? []) as $address) {
        if (!is_array($address)) {
            continue;
        }
        $addresses[] = [
            'id' => supabase_optional_text($address['id'] ?? '', 80),
            'label' => supabase_optional_text($address['label'] ?? '', 80),
            'recipient_name' => supabase_optional_text($address['recipient_name'] ?? '', 120),
            'phone' => supabase_optional_text($address['phone'] ?? '', 40),
            'address_line_1' => supabase_optional_text($address['address_line_1'] ?? '', 160),
            'address_line_2' => supabase_optional_text($address['address_line_2'] ?? '', 160),
            'city' => supabase_optional_text($address['city'] ?? '', 80),
            'state' => supabase_optional_text($address['state'] ?? '', 80),
            'postal_code' => supabase_optional_text($address['postal_code'] ?? '', 20),
            'country' => supabase_optional_text($address['country'] ?? 'United Kingdom', 80),
        ];
    }

    return [
        'id' => supabase_optional_text($customer['id'] ?? '', 80),
        'email' => strtolower(supabase_optional_text($customer['email'] ?? '', 120)),
        'password_hash' => supabase_optional_text($customer['password_hash'] ?? '', 255),
        'name' => supabase_optional_text($customer['name'] ?? '', 120),
        'phone' => supabase_optional_text($customer['phone'] ?? '', 40),
        'city' => supabase_optional_text($customer['city'] ?? '', 80),
        'state' => supabase_optional_text($customer['state'] ?? '', 80),
        'country' => supabase_optional_text($customer['country'] ?? 'United Kingdom', 80),
        'postal_code' => supabase_optional_text($customer['postal_code'] ?? '', 20),
        'address_line_1' => supabase_optional_text($customer['address_line_1'] ?? '', 160),
        'address_line_2' => supabase_optional_text($customer['address_line_2'] ?? '', 160),
        'status' => supabase_optional_text($customer['status'] ?? 'active', 40),
        'joined_at' => supabase_iso_to_datetime((string) ($customer['joined_at'] ?? '')),
        'last_order_at' => supabase_iso_to_datetime((string) ($customer['last_order_at'] ?? '')),
        'total_orders' => max(0, (int) ($customer['total_orders'] ?? 0)),
        'total_spent' => (float) preg_replace('/[^0-9.]/', '', (string) ($customer['total_spent'] ?? '0')) ?: 0.0,
        'wishlist_product_ids' => $wishlist,
        'saved_addresses' => $addresses,
        'notes' => supabase_optional_text($customer['notes'] ?? '', 500),
        'updated_at' => gmdate('c'),
    ];
}

function supabase_row_to_customer(?array $row): ?array
{
    if ($row === null || !is_array($row)) {
        return null;
    }

    $wishlist = [];
    foreach ((array) ($row['wishlist_product_ids'] ?? []) as $id) {
        if (is_scalar($id)) {
            $wishlist[] = (string) $id;
        }
    }

    $addresses = [];
    foreach ((array) ($row['saved_addresses'] ?? []) as $address) {
        if (is_array($address)) {
            $addresses[] = $address;
        }
    }

    $totalSpent = $row['total_spent'] ?? 0;
    if (is_numeric($totalSpent)) {
        $totalSpent = number_format((float) $totalSpent, 2, '.', '');
        $totalSpent = '£' . number_format((float) $totalSpent, 2);
    } else {
        $totalSpent = (string) $totalSpent;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'password_hash' => (string) ($row['password_hash'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'state' => (string) ($row['state'] ?? ''),
        'country' => (string) ($row['country'] ?? 'United Kingdom'),
        'postal_code' => (string) ($row['postal_code'] ?? ''),
        'address_line_1' => (string) ($row['address_line_1'] ?? ''),
        'address_line_2' => (string) ($row['address_line_2'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'joined_at' => (string) ($row['joined_at'] ?? ''),
        'last_order_at' => (string) ($row['last_order_at'] ?? ''),
        'total_orders' => (string) ($row['total_orders'] ?? '0'),
        'total_spent' => $totalSpent,
        'wishlist_product_ids' => $wishlist,
        'saved_addresses' => $addresses,
        'notes' => (string) ($row['notes'] ?? ''),
    ];
}

function supabase_order_to_row(array $order): array
{
    $shipping = (array) ($order['shipping_address'] ?? []);
    $shippingAddress = [
        'address_line_1' => supabase_optional_text($shipping['address_line_1'] ?? '', 160),
        'address_line_2' => supabase_optional_text($shipping['address_line_2'] ?? '', 160),
        'city' => supabase_optional_text($shipping['city'] ?? '', 80),
        'state' => supabase_optional_text($shipping['state'] ?? '', 80),
        'postal_code' => supabase_optional_text($shipping['postal_code'] ?? '', 20),
        'country' => supabase_optional_text($shipping['country'] ?? 'United Kingdom', 80),
    ];

    $requestType = strtolower(supabase_optional_text($order['customer_request_type'] ?? '', 20));
    $requestStatus = strtolower(supabase_optional_text($order['customer_request_status'] ?? '', 20));
    $customerRequest = [
        'type' => in_array($requestType, ['cancel', 'return'], true) ? $requestType : '',
        'status' => $requestStatus !== '' ? $requestStatus : '',
        'reason' => supabase_optional_text($order['customer_request_reason'] ?? '', 500),
        'requested_at' => supabase_iso_to_datetime((string) ($order['customer_request_requested_at'] ?? '')),
        'resolved_at' => supabase_iso_to_datetime((string) ($order['customer_request_resolved_at'] ?? '')),
    ];

    $items = [];
    foreach ((array) ($order['items'] ?? []) as $line) {
        if (is_array($line)) {
            $items[] = $line;
        }
    }

    return [
        'id' => supabase_optional_text($order['id'] ?? '', 80),
        'customer_id' => supabase_optional_text($order['customer_id'] ?? '', 80) ?: null,
        'customer_email' => strtolower(supabase_optional_text($order['customer_email'] ?? '', 120)),
        'customer_name' => supabase_optional_text($order['customer_name'] ?? '', 120),
        'customer_phone' => supabase_optional_text($order['customer_phone'] ?? '', 40),
        'status' => supabase_optional_text($order['status'] ?? 'received', 40),
        'payment_method' => supabase_optional_text($order['payment_method'] ?? 'online', 40),
        'payment_status' => supabase_optional_text($order['payment_status'] ?? 'awaiting', 40),
        'payment_reference' => supabase_optional_text($order['payment_reference'] ?? '', 120),
        'stripe_checkout_session_id' => supabase_optional_text($order['stripe_checkout_session_id'] ?? '', 120),
        'stripe_payment_intent_id' => supabase_optional_text($order['stripe_payment_intent_id'] ?? '', 120),
        'stripe_cancel_token' => supabase_optional_text($order['stripe_cancel_token'] ?? '', 120),
        'refund_id' => supabase_optional_text($order['refund_id'] ?? '', 120),
        'refunded_amount' => supabase_optional_text($order['refunded_amount'] ?? '', 40),
        'refunded_at' => supabase_iso_to_datetime((string) ($order['refunded_at'] ?? '')),
        'cancelled_at' => supabase_iso_to_datetime((string) ($order['cancelled_at'] ?? '')),
        'total' => supabase_optional_text($order['total'] ?? '', 40),
        'subtotal' => supabase_optional_text($order['subtotal'] ?? '', 40),
        'discount_amount' => supabase_optional_text($order['discount_amount'] ?? '', 40),
        'shipping_amount' => supabase_optional_text($order['shipping_amount'] ?? '', 40),
        'coupon_code' => supabase_optional_text($order['coupon_code'] ?? '', 40),
        'item_count' => supabase_optional_text($order['item_count'] ?? '', 20),
        'placed_at' => supabase_iso_to_datetime((string) ($order['placed_at'] ?? '')),
        'delivered_at' => supabase_iso_to_datetime((string) ($order['delivered_at'] ?? '')),
        'shipping_address' => $shippingAddress,
        'customer_request' => $customerRequest,
        'items' => $items,
        'notes' => supabase_optional_text($order['notes'] ?? '', 500),
        'tracking_id' => supabase_optional_text($order['tracking_id'] ?? '', 120),
        'updated_at' => gmdate('c'),
    ];
}

function supabase_row_to_order(?array $row): ?array
{
    if ($row === null || !is_array($row)) {
        return null;
    }

    $customerRequest = is_array($row['customer_request'] ?? null) ? $row['customer_request'] : [];

    return [
        'id' => (string) ($row['id'] ?? ''),
        'customer_id' => (string) ($row['customer_id'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'customer_email' => (string) ($row['customer_email'] ?? ''),
        'customer_phone' => (string) ($row['customer_phone'] ?? ''),
        'status' => (string) ($row['status'] ?? 'received'),
        'payment_method' => (string) ($row['payment_method'] ?? 'online'),
        'payment_status' => (string) ($row['payment_status'] ?? 'awaiting'),
        'payment_reference' => (string) ($row['payment_reference'] ?? ''),
        'stripe_checkout_session_id' => (string) ($row['stripe_checkout_session_id'] ?? ''),
        'stripe_payment_intent_id' => (string) ($row['stripe_payment_intent_id'] ?? ''),
        'stripe_cancel_token' => (string) ($row['stripe_cancel_token'] ?? ''),
        'refund_id' => (string) ($row['refund_id'] ?? ''),
        'refunded_amount' => (string) ($row['refunded_amount'] ?? ''),
        'refunded_at' => (string) ($row['refunded_at'] ?? ''),
        'cancelled_at' => (string) ($row['cancelled_at'] ?? ''),
        'total' => (string) ($row['total'] ?? ''),
        'subtotal' => (string) ($row['subtotal'] ?? ''),
        'discount_amount' => (string) ($row['discount_amount'] ?? ''),
        'shipping_amount' => (string) ($row['shipping_amount'] ?? ''),
        'coupon_code' => (string) ($row['coupon_code'] ?? ''),
        'item_count' => (string) ($row['item_count'] ?? ''),
        'placed_at' => (string) ($row['placed_at'] ?? ''),
        'delivered_at' => (string) ($row['delivered_at'] ?? ''),
        'shipping_address' => is_array($row['shipping_address'] ?? null) ? $row['shipping_address'] : [],
        'items' => array_values(array_filter((array) ($row['items'] ?? []), 'is_array')),
        'notes' => (string) ($row['notes'] ?? ''),
        'tracking_id' => (string) ($row['tracking_id'] ?? ''),
        'customer_request_type' => (string) ($customerRequest['type'] ?? ''),
        'customer_request_status' => (string) ($customerRequest['status'] ?? ''),
        'customer_request_reason' => (string) ($customerRequest['reason'] ?? ''),
        'customer_request_requested_at' => (string) ($customerRequest['requested_at'] ?? ''),
        'customer_request_resolved_at' => (string) ($customerRequest['resolved_at'] ?? ''),
    ];
}

function supabase_newsletter_to_row(array $subscriber): array
{
    return [
        'id' => supabase_optional_text($subscriber['id'] ?? '', 80),
        'subscribed_email' => strtolower(supabase_optional_text($subscriber['subscribed_email'] ?? '', 120)),
        'account_customer_id' => supabase_optional_text($subscriber['account_customer_id'] ?? '', 80),
        'account_holder_name' => supabase_optional_text($subscriber['account_holder_name'] ?? '', 120),
        'account_holder_email' => strtolower(supabase_optional_text($subscriber['account_holder_email'] ?? '', 120)),
        'source' => supabase_optional_text($subscriber['source'] ?? 'guest', 40),
        'status' => supabase_optional_text($subscriber['status'] ?? 'active', 20),
        'subscribed_at' => supabase_iso_to_datetime((string) ($subscriber['subscribed_at'] ?? '')),
        'updated_at' => gmdate('c'),
    ];
}

function supabase_row_to_newsletter(?array $row): ?array
{
    if ($row === null || !is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'subscribed_email' => (string) ($row['subscribed_email'] ?? ''),
        'account_customer_id' => (string) ($row['account_customer_id'] ?? ''),
        'account_holder_name' => (string) ($row['account_holder_name'] ?? ''),
        'account_holder_email' => (string) ($row['account_holder_email'] ?? ''),
        'source' => (string) ($row['source'] ?? 'guest'),
        'status' => (string) ($row['status'] ?? 'active'),
        'subscribed_at' => (string) ($row['subscribed_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function supabase_appointment_to_row(array $config, array $bookings): array
{
    $cleanBookings = [];
    foreach ($bookings as $booking) {
        if (is_array($booking)) {
            $cleanBookings[] = $booking;
        }
    }

    return [
        'id' => 'appointments',
        'config' => $config,
        'bookings' => $cleanBookings,
        'updated_at' => gmdate('c'),
    ];
}

function supabase_row_to_appointment(?array $row): array
{
    if ($row === null || !is_array($row)) {
        return ['config' => [], 'bookings' => []];
    }

    return [
        'config' => is_array($row['config'] ?? null) ? $row['config'] : [],
        'bookings' => array_values(array_filter((array) ($row['bookings'] ?? []), 'is_array')),
    ];
}

function supabase_admin_user_to_row(array $user): array
{
    return [
        'id' => supabase_optional_text($user['id'] ?? '', 80),
        'role' => in_array((string) ($user['role'] ?? ''), ['super', 'employee'], true) ? (string) $user['role'] : 'employee',
        'name' => supabase_optional_text($user['name'] ?? '', 120),
        'username' => supabase_optional_text($user['username'] ?? '', 120),
        'password_hash' => supabase_optional_text($user['password_hash'] ?? '', 255),
        'status' => supabase_optional_text($user['status'] ?? 'active', 20),
        'updated_at' => gmdate('c'),
    ];
}

function supabase_row_to_admin_user(?array $row): ?array
{
    if ($row === null || !is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'role' => (string) ($row['role'] ?? 'employee'),
        'name' => (string) ($row['name'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'password_hash' => (string) ($row['password_hash'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function supabase_admin_request_to_row(array $request): array
{
    $details = [];
    foreach ((array) ($request['details'] ?? []) as $line) {
        if (is_scalar($line)) {
            $clean = trim((string) $line);
            if ($clean !== '') {
                $details[] = $clean;
            }
        }
    }

    return [
        'id' => supabase_optional_text($request['id'] ?? '', 80),
        'action' => supabase_optional_text($request['action'] ?? '', 80),
        'view' => supabase_optional_text($request['view'] ?? 'dashboard', 80),
        'summary' => supabase_optional_text($request['summary'] ?? '', 200),
        'status' => supabase_optional_text($request['status'] ?? 'pending', 20),
        'actor_portal' => supabase_optional_text($request['actor_portal'] ?? 'employee', 40),
        'actor_name' => supabase_optional_text($request['actor_name'] ?? '', 120),
        'actor_username' => supabase_optional_text($request['actor_username'] ?? '', 120),
        'created_at' => supabase_iso_to_datetime((string) ($request['created_at'] ?? '')),
        'resolved_at' => supabase_iso_to_datetime((string) ($request['resolved_at'] ?? '')),
        'resolved_by' => supabase_optional_text($request['resolved_by'] ?? '', 120),
        'note' => supabase_optional_text($request['note'] ?? '', 1000),
        'payload_hash' => supabase_optional_text($request['payload_hash'] ?? '', 128),
        'details' => $details,
        'payload' => is_array($request['payload'] ?? null) ? $request['payload'] : [],
    ];
}

function supabase_row_to_admin_request(?array $row): ?array
{
    if ($row === null || !is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'action' => (string) ($row['action'] ?? ''),
        'view' => (string) ($row['view'] ?? 'dashboard'),
        'summary' => (string) ($row['summary'] ?? ''),
        'status' => (string) ($row['status'] ?? 'pending'),
        'actor_portal' => (string) ($row['actor_portal'] ?? 'employee'),
        'actor_name' => (string) ($row['actor_name'] ?? ''),
        'actor_username' => (string) ($row['actor_username'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'resolved_at' => (string) ($row['resolved_at'] ?? ''),
        'resolved_by' => (string) ($row['resolved_by'] ?? ''),
        'note' => (string) ($row['note'] ?? ''),
        'payload_hash' => (string) ($row['payload_hash'] ?? ''),
        'details' => array_values(array_filter((array) ($row['details'] ?? []), 'is_scalar')),
        'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : [],
    ];
}

// ── CRUD wrappers ──────────────────────────────────────────────────────────
// Every private-table read or write flows through these so the schema mapping
// stays in one place. Service role key is required.

function supabase_list_customers(): array
{
    $rows = supabase_select_rows('customers', [], 'id,email,password_hash,name,phone,city,state,country,postal_code,address_line_1,address_line_2,status,joined_at,last_order_at,total_orders,total_spent,wishlist_product_ids,saved_addresses,notes,created_at,updated_at');
    return array_values(array_filter(array_map('supabase_row_to_customer', $rows)));
}

function supabase_get_customer(string $customerId): ?array
{
    $row = supabase_select_first('customers', ['id' => $customerId], 'id,email,password_hash,name,phone,city,state,country,postal_code,address_line_1,address_line_2,status,joined_at,last_order_at,total_orders,total_spent,wishlist_product_ids,saved_addresses,notes,created_at,updated_at');
    return supabase_row_to_customer($row);
}

function supabase_get_customer_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    // Unique index is built on lower(email); use ilike so this also catches any
    // rows that were inserted before the index was added.
    $rows = supabase_select_rows(
        'customers',
        [],
        'id,email,password_hash,name,phone,city,state,country,postal_code,address_line_1,address_line_2,status,joined_at,last_order_at,total_orders,total_spent,wishlist_product_ids,saved_addresses,notes,created_at,updated_at',
        ['email' => 'ilike.' . $email]
    );
    return $rows === [] ? null : supabase_row_to_customer($rows[0]);
}

function supabase_upsert_customer(array $customer): bool
{
    $row = supabase_customer_to_row($customer);
    if ($row['id'] === '' || $row['email'] === '') {
        return false;
    }

    return supabase_upsert_rows('customers', [$row], 'id');
}

function supabase_delete_customer(string $customerId): bool
{
    if ($customerId === '') {
        return false;
    }

    return supabase_delete_rows('customers', ['id' => $customerId]);
}

function supabase_list_orders(): array
{
    $rows = supabase_select_rows('orders', [], 'id,customer_id,customer_email,customer_name,customer_phone,status,payment_method,payment_status,payment_reference,stripe_checkout_session_id,stripe_payment_intent_id,stripe_cancel_token,refund_id,refunded_amount,refunded_at,cancelled_at,total,subtotal,discount_amount,shipping_amount,coupon_code,item_count,placed_at,delivered_at,shipping_address,customer_request,items,notes,tracking_id,created_at,updated_at');
    return array_values(array_filter(array_map('supabase_row_to_order', $rows)));
}

function supabase_get_order(string $orderId): ?array
{
    $row = supabase_select_first('orders', ['id' => $orderId], 'id,customer_id,customer_email,customer_name,customer_phone,status,payment_method,payment_status,payment_reference,stripe_checkout_session_id,stripe_payment_intent_id,stripe_cancel_token,refund_id,refunded_amount,refunded_at,cancelled_at,total,subtotal,discount_amount,shipping_amount,coupon_code,item_count,placed_at,delivered_at,shipping_address,customer_request,items,notes,tracking_id,created_at,updated_at');
    return supabase_row_to_order($row);
}

function supabase_upsert_order(array $order): bool
{
    $row = supabase_order_to_row($order);
    if ($row['id'] === '') {
        return false;
    }

    return supabase_upsert_rows('orders', [$row], 'id');
}

function supabase_delete_order(string $orderId): bool
{
    if ($orderId === '') {
        return false;
    }

    return supabase_delete_rows('orders', ['id' => $orderId]);
}

function supabase_list_newsletter_subscribers(): array
{
    $rows = supabase_select_rows('newsletter_subscribers', [], 'id,subscribed_email,account_customer_id,account_holder_name,account_holder_email,source,status,subscribed_at,updated_at');
    return array_values(array_filter(array_map('supabase_row_to_newsletter', $rows)));
}

function supabase_get_newsletter_subscriber(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $rows = supabase_select_rows('newsletter_subscribers', ['subscribed_email' => $email], 'id,subscribed_email,account_customer_id,account_holder_name,account_holder_email,source,status,subscribed_at,updated_at');
    return $rows === [] ? null : supabase_row_to_newsletter($rows[0]);
}

function supabase_upsert_newsletter_subscriber(array $subscriber): bool
{
    $row = supabase_newsletter_to_row($subscriber);
    if ($row['id'] === '' || $row['subscribed_email'] === '') {
        return false;
    }

    return supabase_upsert_rows('newsletter_subscribers', [$row], 'id');
}

function supabase_load_appointments(): array
{
    $row = supabase_select_first('appointments', ['id' => 'appointments'], 'config,bookings');
    return supabase_row_to_appointment($row);
}

function supabase_save_appointments(array $config, array $bookings): bool
{
    return supabase_upsert_rows('appointments', [supabase_appointment_to_row($config, $bookings)], 'id');
}

function supabase_list_admin_users(): array
{
    $rows = supabase_select_rows('admin_users', [], 'id,role,name,username,password_hash,status,created_at,updated_at');
    return array_values(array_filter(array_map('supabase_row_to_admin_user', $rows)));
}

function supabase_get_admin_user_by_username(string $username): ?array
{
    $needle = strtolower(trim($username));
    if ($needle === '') {
        return null;
    }

    $rows = supabase_select_rows(
        'admin_users',
        [],
        'id,role,name,username,password_hash,status,created_at,updated_at',
        ['username' => 'ilike.' . $username]
    );
    return $rows === [] ? null : supabase_row_to_admin_user($rows[0]);
}

function supabase_get_admin_user(string $userId): ?array
{
    $rows = supabase_select_rows('admin_users', ['id' => $userId], 'id,role,name,username,password_hash,status,created_at,updated_at');
    return $rows === [] ? null : supabase_row_to_admin_user($rows[0]);
}

function supabase_upsert_admin_user(array $user): bool
{
    $row = supabase_admin_user_to_row($user);
    if ($row['id'] === '' || $row['username'] === '') {
        return false;
    }

    return supabase_upsert_rows('admin_users', [$row], 'id');
}

function supabase_delete_admin_user(string $userId): bool
{
    if ($userId === '') {
        return false;
    }

    return supabase_delete_rows('admin_users', ['id' => $userId]);
}

function supabase_list_admin_requests(): array
{
    $rows = supabase_select_rows('admin_requests', [], 'id,action,view,summary,status,actor_portal,actor_name,actor_username,created_at,resolved_at,resolved_by,note,payload_hash,details,payload');
    return array_values(array_filter(array_map('supabase_row_to_admin_request', $rows)));
}

function supabase_upsert_admin_request(array $request): bool
{
    $row = supabase_admin_request_to_row($request);
    if ($row['id'] === '') {
        return false;
    }

    return supabase_upsert_rows('admin_requests', [$row], 'id');
}

function supabase_delete_admin_request(string $requestId): bool
{
    if ($requestId === '') {
        return false;
    }

    return supabase_delete_rows('admin_requests', ['id' => $requestId]);
}

function supabase_health_check(): array
{
    $checks = [];

    $checks[] = [
        'name' => 'Supabase URL configured',
        'ok' => supabase_project_url() !== '',
        'detail' => supabase_project_url() !== '' ? supabase_project_url() : 'Missing SUPABASE_URL',
    ];

    $checks[] = [
        'name' => 'Publishable key configured',
        'ok' => supabase_publishable_key() !== '',
        'detail' => supabase_publishable_key() !== '' ? 'Present' : 'Missing SUPABASE_PUBLISHABLE_KEY',
    ];

    $checks[] = [
        'name' => 'Service role key configured',
        'ok' => supabase_private_write_enabled(),
        'detail' => supabase_private_write_enabled() ? 'Present' : 'Missing SUPABASE_SERVICE_ROLE_KEY',
    ];

    if (!supabase_enabled()) {
        return [
            'ok' => false,
            'checks' => $checks,
            'message' => 'Supabase is not configured.',
        ];
    }

    $privateTables = [
        'app_state' => 'site_content + login lockout state',
        'cart_sessions' => 'persistent cart storage',
        'media_assets' => 'media metadata',
        'customers' => 'private customer accounts',
        'orders' => 'private order history',
        'newsletter_subscribers' => 'private newsletter list',
        'appointments' => 'private booking scheduler',
        'admin_users' => 'private admin accounts',
        'admin_requests' => 'private admin approval queue',
    ];

    foreach ($privateTables as $table => $label) {
        // app_state and cart_sessions use a non-id primary key column, so
        // probing `id` would 404 on healthy tables. Probe the actual PK.
        $probeColumn = $table === 'app_state' ? 'key' : ($table === 'cart_sessions' ? 'session_key' : 'id');
        $read = supabase_http_request('GET', '/rest/v1/' . rawurlencode($table), ['select' => $probeColumn, 'limit' => 1]);
        $checks[] = [
            'name' => sprintf('%s table reachable', $table),
            'ok' => (bool) ($read['ok'] ?? false),
            'detail' => (bool) ($read['ok'] ?? false) ? sprintf('Reachable (%s)', $label) : (string) ($read['error'] ?? 'Request failed'),
        ];
    }

    if (supabase_private_write_enabled()) {
        $tempStateKey = 'healthcheck_' . bin2hex(random_bytes(6));
        $stateWrite = supabase_write_state($tempStateKey, ['checked_at' => gmdate('c')]);
        $checks[] = [
            'name' => 'app_state write',
            'ok' => $stateWrite,
            'detail' => $stateWrite ? 'Write succeeded' : 'Write failed',
        ];

        $stateCleanup = supabase_delete_rows('app_state', ['key' => $tempStateKey]);
        $checks[] = [
            'name' => 'app_state cleanup',
            'ok' => $stateCleanup,
            'detail' => $stateCleanup ? 'Delete succeeded' : 'Delete failed',
        ];

        $tempCartKey = 'healthcheck_cart_' . bin2hex(random_bytes(6));
        $cartWrite = supabase_upsert_rows('cart_sessions', [[
            'session_key' => $tempCartKey,
            'customer_id' => null,
            'payload' => ['items' => [], 'coupon_code' => ''],
            'updated_at' => gmdate('c'),
        ]], 'session_key');
        $checks[] = [
            'name' => 'cart_sessions write',
            'ok' => $cartWrite,
            'detail' => $cartWrite ? 'Write succeeded' : 'Write failed',
        ];

        $cartCleanup = supabase_delete_rows('cart_sessions', ['session_key' => $tempCartKey]);
        $checks[] = [
            'name' => 'cart_sessions cleanup',
            'ok' => $cartCleanup,
            'detail' => $cartCleanup ? 'Delete succeeded' : 'Delete failed',
        ];
    }

    $allOk = count(array_filter($checks, static fn (array $check): bool => !($check['ok'] ?? false))) === 0;

    return [
        'ok' => $allOk,
        'checks' => $checks,
        'message' => $allOk ? 'Supabase health check passed.' : 'Supabase health check found issues.',
    ];
}
