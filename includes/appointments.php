<?php

declare(strict_types=1);

// Appointment booking data layer. Bookings + availability config live in a
// standalone locked JSON file (NOT the Supabase site-content blob) so a slow
// mail call or a content sync never touches them, and concurrent submissions
// are serialized through one file lock. Mirrors the repo's own persistence
// idiom (local_save_site_content / site_content_with_lock in content.php).

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/functions.php';

function appointments_file(): string
{
    return content_data_dir() . '/appointments.json';
}

function appointments_lock_file(): string
{
    return content_data_dir() . '/appointments.lock';
}

/** Sensible showroom defaults; merged under any saved config so newly added
 *  keys never break an older file. Sunday closed, Mon–Sat 10:00–18:00. */
function appointment_default_config(): array
{
    $weekday = static function (string $open, string $close, bool $closed): array {
        return ['open' => $open, 'close' => $close, 'closed' => $closed];
    };
    return [
        'default_duration' => 60,
        'slot_interval' => 15,
        'lead_time_hours' => 2,
        'max_advance_days' => 90,
        'capacity' => 1,
        'weekdays' => [
            'mon' => $weekday('10:00', '18:00', false),
            'tue' => $weekday('10:00', '18:00', false),
            'wed' => $weekday('10:00', '18:00', false),
            'thu' => $weekday('10:00', '18:00', false),
            'fri' => $weekday('10:00', '18:00', false),
            'sat' => $weekday('10:00', '16:00', false),
            'sun' => $weekday('10:00', '18:00', true),
        ],
        'blackout_dates' => [],
        'blackout_ranges' => [],
        'service_durations' => [],
    ];
}

function appointment_normalize_config(array $raw): array
{
    $defaults = appointment_default_config();
    $cfg = is_array($raw) ? $raw : [];

    $cfg['default_duration'] = max(15, clean_int($cfg['default_duration'] ?? $defaults['default_duration'], 15, 480));
    $cfg['slot_interval'] = max(5, clean_int($cfg['slot_interval'] ?? $defaults['slot_interval'], 5, 120));
    $cfg['lead_time_hours'] = max(0, clean_int($cfg['lead_time_hours'] ?? $defaults['lead_time_hours'], 0, 720));
    $cfg['max_advance_days'] = max(1, clean_int($cfg['max_advance_days'] ?? $defaults['max_advance_days'], 1, 3650));
    $cfg['capacity'] = max(1, clean_int($cfg['capacity'] ?? $defaults['capacity'], 1, 50));

    $weekdays = [];
    foreach ($defaults['weekdays'] as $key => $def) {
        $src = is_array($cfg['weekdays'][$key] ?? null) ? $cfg['weekdays'][$key] : [];
        $weekdays[$key] = [
            'open' => appointment_hhmm((string) ($src['open'] ?? $def['open']), $def['open']),
            'close' => appointment_hhmm((string) ($src['close'] ?? $def['close']), $def['close']),
            'closed' => clean_bool($src['closed'] ?? $def['closed']),
        ];
    }
    $cfg['weekdays'] = $weekdays;

    $cfg['blackout_dates'] = array_values(array_filter(array_map(
        static fn (mixed $d): string => appointment_date((string) $d),
        (array) ($cfg['blackout_dates'] ?? [])
    ), static fn (string $d): bool => $d !== ''));

    $ranges = [];
    foreach ((array) ($cfg['blackout_ranges'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $date = appointment_date((string) ($r['date'] ?? ''));
        $from = appointment_hhmm((string) ($r['from'] ?? ''), '');
        $to = appointment_hhmm((string) ($r['to'] ?? ''), '');
        if ($date === '' || $from === '' || $to === '' || appointment_to_min($from) >= appointment_to_min($to)) {
            continue;
        }
        $ranges[] = ['date' => $date, 'from' => $from, 'to' => $to];
    }
    $cfg['blackout_ranges'] = $ranges;

    $durations = [];
    foreach ((array) ($cfg['service_durations'] ?? []) as $key => $mins) {
        $key = clean_string((string) $key, 80);
        $mins = clean_int($mins, 0, 480);
        if ($key !== '' && $mins > 0) {
            $durations[$key] = $mins;
        }
    }
    $cfg['service_durations'] = $durations;

    return $cfg;
}

function appointments_load(): array
{
    if (!supabase_enabled()) {
        $file = appointments_file();
        $raw = [];
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $bookings = [];
        foreach ((array) ($raw['bookings'] ?? []) as $b) {
            if (is_array($b)) {
                $bookings[] = $b;
            }
        }

        return [
            'config' => appointment_normalize_config((array) ($raw['config'] ?? [])),
            'bookings' => array_values($bookings),
        ];
    }

    $payload = supabase_load_appointments();
    $bookings = [];
    foreach ((array) ($payload['bookings'] ?? []) as $b) {
        if (is_array($b)) {
            $bookings[] = $b;
        }
    }

    return [
        'config' => appointment_normalize_config((array) ($payload['config'] ?? [])),
        'bookings' => array_values($bookings),
    ];
}

function appointments_save(array $data): void
{
    $payload = [
        'config' => appointment_normalize_config((array) ($data['config'] ?? [])),
        'bookings' => array_values((array) ($data['bookings'] ?? [])),
    ];

    if (supabase_enabled() && supabase_save_appointments($payload['config'], $payload['bookings'])) {
        return;
    }

    // Local fallback for the brief moment before Supabase is provisioned or
    // during a service outage. The fallback file holds no PII until a booking
    // is created — at which point a Supabase outage also blocks new bookings,
    // so the two stay in sync.
    ensure_content_storage();
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode appointments.');
    }
    $file = appointments_file();
    $temp = $file . '.tmp';
    $handle = fopen($temp, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open temporary appointments file.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock appointments file.');
        }
        fwrite($handle, $json);
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
    rename($temp, $file);
}

/** Read-mutate-write the appointments store under one exclusive lock so a
 *  "is this slot free? then book it" sequence is atomic across requests. */
function appointments_with_lock(callable $callback): mixed
{
    ensure_content_storage();
    $handle = fopen(appointments_lock_file(), 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Unable to open appointments lock file.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock appointments.');
        }
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Services offered for booking, auto-derived from the admin-managed category
 *  list so a brand-new category appears here with no extra config. Rings split
 *  into Engagement + Wedding (the two ring sections); every other type is one
 *  service. Duration per service comes from config overrides, else the default. */
function appointment_services(?array $config = null): array
{
    $config = $config ?? appointments_load()['config'];
    $durationFor = static function (string $key) use ($config): int {
        return (int) ($config['service_durations'][$key] ?? $config['default_duration']);
    };

    $types = array_values(array_filter(array_map(
        static fn (mixed $t): string => clean_string((string) $t, 80),
        (array) (site_content()['catalog_meta']['product_types'] ?? [])
    ), static fn (string $t): bool => $t !== ''));

    $services = [];
    foreach ($types as $type) {
        $norm = strtolower(trim($type));
        if (in_array($norm, ['ring', 'rings'], true)) {
            foreach (['engagement', 'wedding'] as $section) {
                $key = 'ring-' . $section;
                $services[$key] = [
                    'key' => $key,
                    'label' => ring_section_label($section),
                    'duration_minutes' => $durationFor($key),
                ];
            }
            continue;
        }
        $key = content_slug($type, strtolower($type));
        if ($key === '') {
            $key = 'svc-' . count($services);
        }
        $services[$key] = [
            'key' => $key,
            'label' => homepage_style_type_label($type),
            'duration_minutes' => $durationFor($key),
        ];
    }
    return $services;
}

function appointment_service_label(string $key, ?array $services = null): string
{
    $services = $services ?? appointment_services();
    return (string) ($services[$key]['label'] ?? $key);
}

function appointment_service_duration(string $key, ?array $config = null): int
{
    $config = $config ?? appointments_load()['config'];
    $services = appointment_services($config);
    return (int) ($services[$key]['duration_minutes'] ?? $config['default_duration']);
}

// ── time / date primitives ────────────────────────────────────────────────

function appointment_hhmm(string $value, string $fallback): string
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
        $h = max(0, min(23, (int) $m[1]));
        $min = max(0, min(59, (int) $m[2]));
        return sprintf('%02d:%02d', $h, $min);
    }
    return $fallback;
}

function appointment_to_min(string $hhmm): int
{
    $parts = explode(':', $hhmm);
    return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
}

function appointment_from_min(int $minutes): string
{
    $minutes = max(0, $minutes);
    return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
}

function appointment_date(string $value): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? trim($value) : '';
}

function appointment_weekday_key(string $date): string
{
    $ts = strtotime($date . ' 12:00:00');
    return $ts === false ? '' : strtolower(date('D', $ts));
}

function appointment_format_date_long(string $date): string
{
    $ts = strtotime($date . ' 12:00:00');
    return $ts === false ? $date : date('l, jS F Y', $ts);
}

function appointment_format_time_12(string $hhmm): string
{
    $ts = strtotime('1970-01-01 ' . $hhmm . ':00');
    return $ts === false ? $hhmm : date('g:i A', $ts);
}

// ── availability ──────────────────────────────────────────────────────────

function appointment_is_day_open(array $config, string $date): bool
{
    if ($date === '') {
        return false;
    }
    $today = date('Y-m-d');
    if ($date < $today || $date > date('Y-m-d', strtotime($today . ' +' . ((int) $config['max_advance_days']) . ' days'))) {
        return false;
    }
    if (in_array($date, (array) ($config['blackout_dates'] ?? []), true)) {
        return false;
    }
    $wd = (array) ($config['weekdays'][appointment_weekday_key($date)] ?? []);
    return empty($wd['closed']);
}

/** Overlap test for two [start,end) minute windows. */
function appointment_windows_overlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
{
    return $aStart < $bEnd && $bStart < $aEnd;
}

/** All start-times for a service on a date, each flagged available. Honours the
 *  day's open/close, the per-slot blackout ranges, the lead-time window, and the
 *  configured capacity against overlapping confirmed bookings. */
function appointment_day_slots(array $config, array $bookings, string $service, string $date): array
{
    if (!appointment_is_day_open($config, $date)) {
        return [];
    }
    $wd = (array) $config['weekdays'][appointment_weekday_key($date)];
    $openMin = appointment_to_min((string) ($wd['open'] ?? '10:00'));
    $closeMin = appointment_to_min((string) ($wd['close'] ?? '18:00'));
    $duration = appointment_service_duration($service, $config);
    $interval = max(5, (int) $config['slot_interval']);
    $capacity = max(1, (int) $config['capacity']);

    // A start time is only offered if the whole appointment finishes by close.
    $lastStart = $closeMin - $duration;
    if ($lastStart < $openMin) {
        return [];
    }

    $now = time();
    $leadSeconds = ((int) $config['lead_time_hours']) * 3600;

    // Blackout ranges that fall on this date, in minutes.
    $dayRanges = [];
    foreach ((array) ($config['blackout_ranges'] ?? []) as $r) {
        if (($r['date'] ?? '') !== $date) {
            continue;
        }
        $dayRanges[] = [appointment_to_min((string) $r['from']), appointment_to_min((string) $r['to'])];
    }

    // Confirmed (non-cancelled) bookings overlapping this date, as minute windows.
    $dayBookings = [];
    foreach ($bookings as $b) {
        if (!is_array($b) || ($b['date'] ?? '') !== $date) {
            continue;
        }
        if (strcasecmp((string) ($b['status'] ?? ''), 'cancelled') === 0) {
            continue;
        }
        $bStart = appointment_to_min((string) ($b['time'] ?? ''));
        $bDur = max(1, (int) ($b['duration'] ?? $duration));
        $dayBookings[] = [$bStart, $bStart + $bDur];
    }

    $slots = [];
    for ($start = $openMin; $start <= $lastStart; $start += $interval) {
        $end = $start + $duration;

        // Lead time: the slot's wall-clock moment must be in the future.
        $slotTs = strtotime($date . ' ' . appointment_from_min($start) . ':00');
        if ($slotTs !== false && $slotTs - $now < $leadSeconds) {
            continue;
        }

        // A blackout range blocks the slot if the appointment would overlap it.
        $blocked = false;
        foreach ($dayRanges as [$rStart, $rEnd]) {
            if (appointment_windows_overlap($start, $end, $rStart, $rEnd)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }

        // Capacity: count overlapping live bookings.
        $overlap = 0;
        foreach ($dayBookings as [$bStart, $bEnd]) {
            if (appointment_windows_overlap($start, $end, $bStart, $bEnd)) {
                $overlap++;
            }
        }

        $slots[] = [
            'time' => appointment_from_min($start),
            'available' => $overlap < $capacity,
        ];
    }
    return $slots;
}

function appointment_is_slot_available(array $config, array $bookings, string $service, string $date, string $time): bool
{
    foreach (appointment_day_slots($config, $bookings, $service, $date) as $slot) {
        if (($slot['time'] ?? '') === $time) {
            return (bool) ($slot['available'] ?? false);
        }
    }
    return false;
}

// ── booking record helpers ────────────────────────────────────────────────

function appointment_new_id(): string
{
    return 'apt-' . bin2hex(random_bytes(8));
}

function appointment_new_ref(): string
{
    return 'AZ-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function appointment_clean_booking(array $post, array $services, array $config): array
{
    $service = clean_string((string) ($post['service'] ?? ''), 80);
    if (!isset($services[$service])) {
        $service = '';
    }
    $date = appointment_date((string) ($post['date'] ?? ''));
    $time = appointment_hhmm((string) ($post['time'] ?? ''), '');

    return [
        'id' => clean_string((string) ($post['id'] ?? ''), 80) ?: appointment_new_id(),
        'ref' => clean_string((string) ($post['ref'] ?? ''), 40) ?: appointment_new_ref(),
        'service' => $service,
        'service_label' => $service !== '' ? (string) $services[$service]['label'] : '',
        'date' => $date,
        'time' => $time,
        'duration' => $service !== '' ? appointment_service_duration($service, $config) : (int) $config['default_duration'],
        'first_name' => clean_string((string) ($post['first_name'] ?? ''), 80),
        'last_name' => clean_string((string) ($post['last_name'] ?? ''), 80),
        'email' => sanitize_email((string) ($post['email'] ?? '')),
        'country_code' => clean_string((string) ($post['country_code'] ?? ''), 8),
        'mobile' => clean_string((string) ($post['mobile'] ?? ''), 30),
        'consent_email' => clean_bool($post['consent_email'] ?? false),
        'consent_sms' => clean_bool($post['consent_sms'] ?? false),
        'consent_mail' => clean_bool($post['consent_mail'] ?? false),
        'notes' => clean_multiline((string) ($post['notes'] ?? ''), 1000),
        'status' => 'confirmed',
        'created_at' => date('c'),
        'confirmation_email_sent' => false,
        'confirmation_email_at' => null,
    ];
}

/** A booking is "live" for availability unless explicitly cancelled. */
function appointment_is_live(array $booking): bool
{
    return strcasecmp((string) ($booking['status'] ?? ''), 'cancelled') !== 0;
}
