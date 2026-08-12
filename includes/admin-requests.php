<?php

declare(strict_types=1);

function admin_requests_file(): string
{
    return content_data_dir() . '/admin-requests.json';
}

function admin_clean_request_payload(mixed $value, int $depth = 0): mixed
{
    if ($depth > 10) {
        return null;
    }

    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $cleanKey = is_int($key) ? $key : clean_string((string) $key, 120);
            $clean[$cleanKey] = admin_clean_request_payload($item, $depth + 1);
        }
        return $clean;
    }

    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    return clean_multiline((string) $value, 20000);
}

function admin_clean_request_item(array $item, int $index = 0): array
{
    $id = clean_string((string) ($item['id'] ?? ''), 80);
    if ($id === '') {
        $id = 'admin-request-' . ($index + 1);
    }

    $status = strtolower(clean_string((string) ($item['status'] ?? 'pending'), 20));
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $status = 'pending';
    }

    $payload = admin_clean_request_payload($item['payload'] ?? []);
    $payloadHash = clean_string((string) ($item['payload_hash'] ?? ''), 128);
    if ($payloadHash === '') {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = hash('sha256', clean_string((string) ($item['action'] ?? ''), 80) . '|' . ($encodedPayload === false ? '' : $encodedPayload));
    }

    $details = [];
    foreach ((array) ($item['details'] ?? []) as $detail) {
        $line = clean_string((string) $detail, 240);
        if ($line !== '') {
            $details[] = $line;
        }
    }
    if ($details === []) {
        $details = admin_request_detail_lines(clean_string((string) ($item['action'] ?? ''), 80), is_array($payload) ? $payload : []);
    }

    return [
        'id' => $id,
        'action' => clean_string((string) ($item['action'] ?? ''), 80),
        'view' => clean_string((string) ($item['view'] ?? 'dashboard'), 80),
        'summary' => clean_string((string) ($item['summary'] ?? ''), 200),
        'status' => $status,
        'actor_portal' => clean_string((string) ($item['actor_portal'] ?? 'employee'), 40),
        'actor_name' => clean_string((string) ($item['actor_name'] ?? ''), 120),
        'actor_username' => clean_string((string) ($item['actor_username'] ?? ''), 120),
        'created_at' => clean_string((string) ($item['created_at'] ?? ''), 40),
        'resolved_at' => clean_string((string) ($item['resolved_at'] ?? ''), 40),
        'resolved_by' => clean_string((string) ($item['resolved_by'] ?? ''), 120),
        'note' => clean_multiline((string) ($item['note'] ?? ''), 1000),
        'payload_hash' => $payloadHash,
        'details' => $details,
        'payload' => $payload,
    ];
}

function admin_request_humanize_key(string $key): string
{
    $key = str_replace(['_', '.'], ' ', trim($key));
    $key = preg_replace('/\s+/', ' ', $key) ?? $key;
    return ucwords($key);
}

function admin_request_preview_value(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if ($value === null) {
        return 'None';
    }
    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
    if (is_int($value)) {
        return (string) $value;
    }

    $text = clean_string((string) $value, 180);
    return $text !== '' ? $text : 'Empty';
}

function admin_request_payload_lines(mixed $value, string $prefix = '', int &$count = 0, int $max = 18): array
{
    if ($count >= $max) {
        return [];
    }

    if (!is_array($value)) {
        $count++;
        return [$prefix . ': ' . admin_request_preview_value($value)];
    }

    $lines = [];
    $isAssoc = array_keys($value) !== range(0, count($value) - 1);

    if (!$isAssoc) {
        if ($value === []) {
            return [];
        }

        $allScalar = true;
        foreach ($value as $item) {
            if (is_array($item)) {
                $allScalar = false;
                break;
            }
        }

        if ($allScalar) {
            $count++;
            $joined = implode(', ', array_map('admin_request_preview_value', array_slice($value, 0, 6)));
            if (count($value) > 6) {
                $joined .= ' +' . (count($value) - 6) . ' more';
            }
            return [$prefix . ': ' . $joined];
        }

        foreach ($value as $index => $item) {
            if ($count >= $max) {
                break;
            }
            if (is_array($item)) {
                $itemLabel = '';
                foreach (['label', 'title', 'name', 'code', 'id', 'value'] as $labelKey) {
                    $candidate = clean_string((string) ($item[$labelKey] ?? ''), 120);
                    if ($candidate !== '') {
                        $itemLabel = $candidate;
                        break;
                    }
                }
                if ($itemLabel !== '') {
                    $count++;
                    $lines[] = $prefix . ' ' . ($index + 1) . ': ' . $itemLabel;
                } else {
                    $nestedPrefix = trim($prefix . ' ' . ($index + 1));
                    $lines = array_merge($lines, admin_request_payload_lines($item, $nestedPrefix, $count, $max));
                }
            }
        }

        return $lines;
    }

    foreach ($value as $key => $item) {
        if ($count >= $max) {
            break;
        }
        if (in_array((string) $key, ['current_image', 'payload_hash', 'return_view'], true)) {
            continue;
        }
        $nextPrefix = trim($prefix === '' ? admin_request_humanize_key((string) $key) : ($prefix . ' / ' . admin_request_humanize_key((string) $key)));
        $lines = array_merge($lines, admin_request_payload_lines($item, $nextPrefix, $count, $max));
    }

    return $lines;
}

function admin_request_detail_lines(string $action, array $payload): array
{
    $lines = [];
    $area = clean_string((string) ($payload['area'] ?? ''), 80);
    $kind = ucfirst(clean_string((string) ($payload['kind'] ?? ''), 20));
    $entity = clean_string((string) ($payload['entity'] ?? ''), 80);
    $label = clean_string((string) ($payload['label'] ?? ''), 160);
    $targetId = clean_string((string) ($payload['target_id'] ?? ''), 80);

    if ($area !== '') {
        $lines[] = 'Area: ' . $area;
    }
    if ($entity !== '') {
        $lines[] = 'Change: ' . trim($kind . ' ' . $entity);
    }
    if ($label !== '') {
        $lines[] = 'Item: ' . $label;
    }
    if ($targetId !== '') {
        $lines[] = 'Target ID: ' . $targetId;
    }
    foreach ((array) ($payload['context'] ?? []) as $key => $value) {
        $line = admin_request_humanize_key((string) $key) . ': ' . admin_request_preview_value($value);
        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
        }
    }
    return array_slice($lines, 0, 8);
}

function admin_load_requests(): array
{
    if (supabase_enabled()) {
        $requests = supabase_list_admin_requests();
        if (is_array($requests)) {
            return array_values(array_map('admin_clean_request_item', $requests, array_keys($requests)));
        }
    }

    $file = admin_requests_file();
    if (!is_file($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_map('admin_clean_request_item', $decoded, array_keys($decoded)));
}

function admin_save_requests(array $requests): void
{
    $clean = array_values(array_map('admin_clean_request_item', $requests, array_keys($requests)));

    if (supabase_enabled()) {
        $existing = supabase_list_admin_requests();
        $existingIds = array_column($existing, 'id');
        $keepIds = array_column($clean, 'id');

        $ok = true;
        foreach ($clean as $request) {
            if (!supabase_upsert_admin_request($request)) {
                $ok = false;
            }
        }
        foreach ($existingIds as $existingId) {
            if (!in_array($existingId, $keepIds, true) && !supabase_delete_admin_request($existingId)) {
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

    file_put_contents(admin_requests_file(), $json, LOCK_EX);
}

function admin_create_request(string $action, string $view, string $summary, array $payload, string $actorUsername, string $actorName = ''): array
{
    $requests = admin_load_requests();
    $encodedPayload = json_encode(admin_clean_request_payload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $payloadHash = hash('sha256', $action . '|' . ($encodedPayload === false ? '' : $encodedPayload));

    foreach ($requests as $request) {
        if (
            (string) ($request['status'] ?? '') === 'pending' &&
            (string) ($request['action'] ?? '') === $action &&
            (string) ($request['actor_username'] ?? '') === $actorUsername &&
            (string) ($request['payload_hash'] ?? '') === $payloadHash
        ) {
            $request['_created'] = false;
            return $request;
        }
    }

    $request = admin_clean_request_item([
        'id' => 'admin-request-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
        'action' => $action,
        'view' => $view,
        'summary' => $summary,
        'status' => 'pending',
        'actor_portal' => admin_portal_mode(),
        'actor_name' => $actorName,
        'actor_username' => $actorUsername,
        'created_at' => date('Y-m-d H:i'),
        'resolved_at' => '',
        'resolved_by' => '',
        'note' => '',
        'payload_hash' => $payloadHash,
        'details' => admin_request_detail_lines($action, $payload),
        'payload' => $payload,
    ], count($requests));
    array_unshift($requests, $request);
    admin_save_requests($requests);
    $request['_created'] = true;
    return $request;
}

function admin_find_request(array $requests, string $requestId): ?array
{
    foreach ($requests as $request) {
        if ((string) ($request['id'] ?? '') === $requestId) {
            return $request;
        }
    }
    return null;
}

function admin_update_request_status(string $requestId, string $status, string $resolvedBy, string $note = ''): ?array
{
    $requests = admin_load_requests();
    foreach ($requests as $index => $request) {
        if ((string) ($request['id'] ?? '') !== $requestId) {
            continue;
        }
        $requests[$index]['status'] = $status;
        $requests[$index]['resolved_at'] = date('Y-m-d H:i');
        $requests[$index]['resolved_by'] = $resolvedBy;
        $requests[$index]['note'] = $note;
        admin_save_requests($requests);
        return $requests[$index];
    }
    return null;
}

function admin_pending_requests(): array
{
    return array_values(array_filter(admin_load_requests(), static fn (array $request): bool => (string) ($request['status'] ?? '') === 'pending'));
}
