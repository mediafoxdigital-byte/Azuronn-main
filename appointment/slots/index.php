<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/appointments.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$service = clean_string((string) ($_GET['service'] ?? ''), 80);
$date = appointment_date((string) ($_GET['date'] ?? ''));

if ($service === '' || $date === '') {
    echo json_encode(['date' => $date, 'slots' => []]);
    exit;
}

$store = appointments_load();
$config = $store['config'];
$services = appointment_services($config);

if (!isset($services[$service])) {
    echo json_encode(['date' => $date, 'slots' => []]);
    exit;
}

$slots = appointment_day_slots($config, $store['bookings'], $service, $date);

echo json_encode([
    'date' => $date,
    'service' => $service,
    'slots' => $slots,
], JSON_UNESCAPED_SLASHES);
