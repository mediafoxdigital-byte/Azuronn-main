<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/appointments.php';

$ref = clean_string((string) ($_GET['ref'] ?? ''), 40);
$booking = null;

if ($ref !== '') {
    $store = appointments_load();
    foreach ($store['bookings'] as $b) {
        if (($b['ref'] ?? '') === $ref) {
            $booking = $b;
            break;
        }
    }
}

$pageTitle = 'Appointment Confirmed - ' . SITE_NAME;
$bodyClass = 'appointment-page';

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= h(asset_url('assets/css/appointment.css')) ?>">

<?php if ($booking === null): ?>
  <div class="ap-confirmed">
    <div class="ap-confirmed-check" style="background:#c0392b;"><i class="fas fa-exclamation"></i></div>
    <h1>Booking Not Found</h1>
    <p>We couldn't find a booking with that reference. It may have already been processed or the link may be incorrect.</p>
    <a href="<?= h(resolve_link('/appointment/')) ?>" class="ap-btn ap-btn-primary" style="display:inline-block; text-decoration:none;">Book an Appointment</a>
  </div>
<?php else: ?>
  <div class="ap-confirmed">
    <div class="ap-confirmed-check"><i class="fas fa-check"></i></div>
    <h1>Your Appointment is Booked</h1>
    <p>Thank you, <?= h((string) ($booking['first_name'] ?? '')) ?>. A confirmation<?= !empty($booking['email']) ? ' has been sent to <strong>' . h((string) $booking['email']) . '</strong>' : '' ?>. We look forward to seeing you.</p>

    <div class="ap-summary">
      <div class="ap-summary-head">
        <h3>Appointment</h3>
        <p><?= h((string) ($booking['service_label'] ?? '')) ?> at <?= h(SITE_NAME) ?> on <?= h($booking['date'] !== '' ? appointment_format_date_long((string) $booking['date']) : '') ?> at <?= h($booking['time'] !== '' ? appointment_format_time_12((string) $booking['time']) : '') ?></p>
      </div>
      <div class="ap-summary-rows">
        <div class="ap-summary-row"><span class="ap-k">Reference</span><span class="ap-v"><?= h((string) ($booking['ref'] ?? '')) ?></span></div>
        <div class="ap-summary-row"><span class="ap-k">Service</span><span class="ap-v"><?= h((string) ($booking['service_label'] ?? '')) ?></span></div>
        <div class="ap-summary-row"><span class="ap-k">Date</span><span class="ap-v"><?= h($booking['date'] !== '' ? appointment_format_date_long((string) $booking['date']) : '') ?></span></div>
        <div class="ap-summary-row"><span class="ap-k">Time</span><span class="ap-v"><?= h($booking['time'] !== '' ? appointment_format_time_12((string) $booking['time']) : '') ?></span></div>
        <div class="ap-summary-row"><span class="ap-k">Duration</span><span class="ap-v"><?= h((string) (int) ($booking['duration'] ?? 60)) ?> minutes</span></div>
        <div class="ap-summary-row"><span class="ap-k">Name</span><span class="ap-v"><?= h(trim((string) ($booking['first_name'] ?? '') . ' ' . (string) ($booking['last_name'] ?? ''))) ?></span></div>
        <?php if (!empty($booking['email'])): ?>
        <div class="ap-summary-row"><span class="ap-k">Email</span><span class="ap-v"><?= h((string) $booking['email']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($booking['mobile'])): ?>
        <div class="ap-summary-row"><span class="ap-k">Mobile</span><span class="ap-v"><?= h(trim((string) ($booking['country_code'] ?? '') . ' ' . (string) $booking['mobile'])) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($booking['notes'])): ?>
        <div class="ap-summary-row"><span class="ap-k">Notes</span><span class="ap-v"><?= h((string) $booking['notes']) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <a href="<?= h(resolve_link('/')) ?>" class="ap-btn" style="display:inline-block; text-decoration:none; margin-right:12px;">Return Home</a>
    <a href="<?= h(resolve_link('/shop/')) ?>" class="ap-btn ap-btn-primary" style="display:inline-block; text-decoration:none;">Browse Collection</a>
  </div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
