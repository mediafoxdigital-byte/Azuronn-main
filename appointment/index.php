<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/appointments.php';
require_once dirname(__DIR__) . '/includes/appointment-mail.php';

// ── POST: confirm booking ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'book-appointment') {
    if (!csrf_verify()) {
        site_flash_set('error', 'Session expired. Please try again.');
        redirect(resolve_link('/appointment/'));
    }

    $store = appointments_load();
    $config = $store['config'];
    $services = appointment_services($config);

    // Normalize mobile to digits-only and map the single marketing consent box to
    // the stored consent flags (one tick grants email + SMS + post permission).
    $_POST['mobile'] = preg_replace('/\D/', '', (string) ($_POST['mobile'] ?? ''));
    $apMarketingConsent = !empty($_POST['consent_marketing']) ? '1' : '';
    $_POST['consent_email'] = $apMarketingConsent;
    $_POST['consent_sms'] = $apMarketingConsent;
    $_POST['consent_mail'] = $apMarketingConsent;

    $booking = appointment_clean_booking($_POST, $services, $config);

    // Validate required fields (mirrors the client-side rules exactly).
    $errors = [];
    if ($booking['service'] === '') { $errors[] = 'Please select a service.'; }
    if ($booking['date'] === '') { $errors[] = 'Please select a date.'; }
    if ($booking['time'] === '') { $errors[] = 'Please select a time.'; }
    if (mb_strlen($booking['first_name']) < 3) { $errors[] = 'First name must be at least 3 characters.'; }
    if (mb_strlen($booking['last_name']) < 3) { $errors[] = 'Last name must be at least 3 characters.'; }
    if ($booking['email'] === '' || !filter_var($booking['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid email address is required.'; }
    if (!preg_match('/^\d{10}$/', $booking['mobile'])) { $errors[] = 'Mobile must be exactly 10 digits.'; }

    if ($errors !== []) {
        site_flash_set('error', implode(' ', $errors));
        redirect(resolve_link('/appointment/'));
    }

    // Under one lock: re-validate availability, then persist.
    $result = appointments_with_lock(static function () use ($booking, $config): array {
        $store = appointments_load();
        $bookings = $store['bookings'];
        if (!appointment_is_slot_available($store['config'], $bookings, $booking['service'], $booking['date'], $booking['time'])) {
            return ['ok' => false, 'reason' => 'That time is no longer available. Please choose another.'];
        }
        $bookings[] = $booking;
        appointments_save(['config' => $store['config'], 'bookings' => $bookings]);
        return ['ok' => true];
    });

    if (!$result['ok']) {
        site_flash_set('error', (string) ($result['reason'] ?? 'Unable to book this slot.'));
        redirect(resolve_link('/appointment/'));
    }

    // Send confirmation email OUTSIDE the lock so a slow SMTP never blocks others.
    $emailSent = appointment_send_confirmation($booking);

    // Record the send result with a short locked update.
    if ($emailSent) {
        appointments_with_lock(static function () use ($booking): void {
            $store = appointments_load();
            foreach ($store['bookings'] as &$b) {
                if (($b['id'] ?? '') === $booking['id']) {
                    $b['confirmation_email_sent'] = true;
                    $b['confirmation_email_at'] = date('c');
                    break;
                }
            }
            unset($b);
            appointments_save($store);
        });
    }

    redirect(resolve_link('/appointment/confirmed/?ref=' . urlencode($booking['ref'])));
}

// ── GET: render wizard ────────────────────────────────────────────────────
$store = appointments_load();
$config = $store['config'];
$services = appointment_services($config);

$pageTitle = 'Book an Appointment - ' . SITE_NAME;
$bodyClass = 'appointment-page';

$customer = current_customer();
$prefillFirst = $customer !== null ? clean_string((string) ($customer['first_name'] ?? ''), 80) : '';
$prefillLast = $customer !== null ? clean_string((string) ($customer['last_name'] ?? ''), 80) : '';
$prefillEmail = $customer !== null ? (string) ($customer['email'] ?? '') : '';
// Only prefill mobile when it cleanly yields a 10-digit national number; the
// stored customer phone often carries a country code / spaces that would fail
// the 10-digit rule, so we leave the field empty in that case.
$prefillPhoneDigits = $customer !== null ? preg_replace('/\D/', '', (string) ($customer['phone'] ?? '')) : '';
$prefillPhone = preg_match('/^\d{10}$/', $prefillPhoneDigits) ? $prefillPhoneDigits : '';

$flash = function_exists('site_flash_pull') ? site_flash_pull() : null;

// Country codes for the select.
$countryCodes = ['+1','+7','+20','+27','+30','+31','+32','+33','+34','+36','+39','+40','+41','+43','+44','+45','+46','+47','+48','+49','+51','+52','+54','+55','+56','+57','+58','+60','+61','+62','+63','+64','+65','+66','+81','+82','+84','+86','+90','+91','+92','+93','+94','+95','+98','+212','+213','+216','+218','+220','+221','+223','+224','+225','+226','+227','+228','+229','+230','+231','+232','+233','+234','+235','+236','+237','+238','+239','+240','+241','+242','+243','+244','+245','+246','+248','+249','+250','+251','+252','+253','+254','+255','+256','+257','+258','+260','+261','+262','+263','+264','+265','+266','+267','+268','+269','+290','+291','+297','+298','+299','+350','+351','+352','+353','+354','+355','+356','+357','+358','+359','+370','+371','+372','+373','+374','+375','+376','+377','+378','+380','+381','+382','+385','+386','+387','+389','+420','+421','+423','+852','+853','+855','+856','+880','+886','+960','+961','+962','+963','+964','+965','+966','+967','+968','+970','+971','+972','+973','+974','+975','+976','+977','+979','+992','+993','+994','+995','+996','+998'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= h(asset_url('assets/css/appointment.css')) ?>">

<section class="ap-hero">
  <span class="ap-hero-kicker">Private Consultation</span>
  <h1>Book an Appointment</h1>
  <p>Reserve a one-to-one session with our specialists — in store or online — to explore designs, discuss bespoke commissions, or find the perfect piece.</p>
</section>

<?php if ($flash !== null): ?>
  <div class="ap-flash ap-flash--<?= h((string) ($flash['type'] ?? 'success')) ?>"><?= h((string) ($flash['message'] ?? '')) ?></div>
<?php endif; ?>

<div class="ap-shell">
  <!-- Step indicator -->
  <div class="ap-steps">
    <div class="ap-step is-active" data-step-ind="1"><span class="ap-step-num">1</span><span>Service</span></div>
    <div class="ap-step-line"></div>
    <div class="ap-step" data-step-ind="2"><span class="ap-step-num">2</span><span>Date & Time</span></div>
    <div class="ap-step-line"></div>
    <div class="ap-step" data-step-ind="3"><span class="ap-step-num">3</span><span>Your Details</span></div>
    <div class="ap-step-line"></div>
    <div class="ap-step" data-step-ind="4"><span class="ap-step-num">4</span><span>Confirm</span></div>
  </div>

 <form method="post" action="<?= h(resolve_link('/appointment/')) ?>" id="ap-form" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="book-appointment">
    <input type="hidden" name="service" id="ap-service" value="">
    <input type="hidden" name="date" id="ap-date" value="">
    <input type="hidden" name="time" id="ap-time" value="">

    <!-- ═══ Step 1: Service ═══ -->
    <div class="ap-panel is-active" data-panel="1">
      <h2 class="ap-panel-title">Select an Appointment Type</h2>
      <p class="ap-panel-sub">Choose the consultation that best suits your needs.</p>
      <div class="ap-service-list">
        <?php foreach ($services as $svc): ?>
          <div class="ap-service" data-service-key="<?= h($svc['key']) ?>" tabindex="0" role="button" aria-pressed="false">
            <span class="ap-service-name"><?= h($svc['label']) ?></span>
            <span class="ap-service-dur"><?= h((string) intdiv((int) $svc['duration_minutes'], 60)) ?> hour<?= (int) $svc['duration_minutes'] >= 120 ? 's' : '' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="ap-nav">
        <span></span>
        <button type="button" class="ap-btn ap-btn-primary" data-next="1" disabled>Next</button>
      </div>
    </div>

    <!-- ═══ Step 2: Date & Time ═══ -->
    <div class="ap-panel" data-panel="2">
      <h2 class="ap-panel-title">Select Date & Time</h2>
      <p class="ap-panel-sub">Pick a day, then choose an available time slot.</p>
      <div class="ap-selected-banner" id="ap-sel-banner" style="display:none;">You selected date: <strong id="ap-sel-date-text"></strong></div>
      <div class="ap-datetime">
        <div class="ap-cal-wrap">
          <div class="ap-cal-head">
            <span class="ap-cal-title" id="ap-cal-title"></span>
            <div class="ap-cal-nav">
              <button type="button" id="ap-cal-prev" aria-label="Previous month"><i class="fas fa-chevron-left"></i></button>
              <button type="button" id="ap-cal-next" aria-label="Next month"><i class="fas fa-chevron-right"></i></button>
            </div>
          </div>
          <div class="ap-cal-grid" id="ap-cal-grid"></div>
        </div>
        <div class="ap-slots-wrap">
          <h3 class="ap-slots-title">Availability</h3>
          <div class="ap-slots" id="ap-slots"><div class="ap-slots-empty">Select a date to see available times.</div></div>
        </div>
      </div>
      <div class="ap-nav">
        <button type="button" class="ap-btn" data-prev="2">Previous</button>
        <button type="button" class="ap-btn ap-btn-primary" data-next="2" disabled>Next</button>
      </div>
    </div>

    <!-- ═══ Step 3: Details ═══ -->
    <div class="ap-panel" data-panel="3">
      <h2 class="ap-panel-title">Provide Contact Details</h2>
      <p class="ap-panel-sub">We'll use these to confirm your appointment and send reminders.</p>
      <div class="ap-grid2">
        <div class="ap-field"><label><span class="ap-req">*</span> First Name</label><input type="text" name="first_name" id="ap-fn" value="<?= h($prefillFirst) ?>" minlength="3" required><span class="ap-err" data-err="fn"></span></div>
        <div class="ap-field"><label><span class="ap-req">*</span> Last Name</label><input type="text" name="last_name" id="ap-ln" value="<?= h($prefillLast) ?>" minlength="3" required><span class="ap-err" data-err="ln"></span></div>
        <div class="ap-field"><label><span class="ap-req">*</span> Email</label><input type="email" name="email" id="ap-em" value="<?= h($prefillEmail) ?>" required><span class="ap-err" data-err="em"></span></div>
        <div class="ap-field"><label><span class="ap-req">*</span> Country Code</label>
          <select name="country_code" id="ap-cc">
            <?php foreach ($countryCodes as $cc): ?><option value="<?= h($cc) ?>"<?= $cc === '+44' ? ' selected' : '' ?>><?= h($cc) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="ap-field"><label><span class="ap-req">*</span> Mobile <small class="ap-hint">(10 digits)</small></label><input type="tel" name="mobile" id="ap-mob" value="<?= h($prefillPhone) ?>" inputmode="numeric" maxlength="10" pattern="\d{10}" required><span class="ap-err" data-err="mob"></span></div>
        <div class="ap-field"></div>
        <div class="ap-field ap-field-full">
          <label class="ap-consent"><input type="checkbox" name="consent_marketing" value="1"> I consent to Azuronn contacting me about appointments, offers and updates by email, SMS and post.</label>
        </div>
        <div class="ap-field ap-field-full"><label>How can we help?</label><textarea name="notes" id="ap-notes" placeholder="Tell us what you'd like to discuss or any special requirements…"></textarea></div>
      </div>
      <p class="ap-terms">By confirming your appointment, you agree to our <a href="<?= h(resolve_link('/cookie-policy/')) ?>">terms and privacy policy</a>.</p>
      <div class="ap-nav">
        <button type="button" class="ap-btn" data-prev="3">Previous</button>
        <button type="button" class="ap-btn ap-btn-primary" data-next="3" id="ap-next3" disabled>Review &amp; Confirm</button>
      </div>
    </div>

    <!-- ═══ Step 4: Confirm ═══ -->
    <div class="ap-panel" data-panel="4">
      <h2 class="ap-panel-title">Check Details & Confirm</h2>
      <p class="ap-panel-sub">Please review the following before confirming your booking.</p>
      <div class="ap-summary">
        <div class="ap-summary-head">
          <h3>Appointment</h3>
          <p id="ap-sum-headline"></p>
        </div>
        <div class="ap-summary-rows" id="ap-sum-rows"></div>
      </div>
      <div class="ap-nav">
        <button type="button" class="ap-btn" data-prev="4">Previous</button>
        <button type="submit" class="ap-btn ap-btn-primary" id="ap-confirm-btn">Confirm Booking</button>
      </div>
    </div>
  </form>
</div>

<script>
(function () {
  var form = document.getElementById('ap-form');
  var panels = document.querySelectorAll('.ap-panel');
  var steps = document.querySelectorAll('.ap-step');
  var current = 1;
  var state = { service: '', serviceLabel: '', date: '', time: '' };
  var calYear, calMonth;

  function go(step) {
    current = step;
    panels.forEach(function (p) { p.classList.toggle('is-active', +p.dataset.panel === step); });
    steps.forEach(function (s) {
      var n = +s.dataset.stepInd;
      s.classList.toggle('is-active', n === step);
      s.classList.toggle('is-done', n < step);
    });
    if (step === 2 && !calYear) { initCal(); }
    if (step === 3) { detailsValid(false); }
    if (step === 4) { buildSummary(); }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // ── Step 1: service selection ──
  document.querySelectorAll('.ap-service').forEach(function (el) {
    el.addEventListener('click', function () {
      document.querySelectorAll('.ap-service').forEach(function (s) { s.classList.remove('is-selected'); s.setAttribute('aria-pressed', 'false'); });
      el.classList.add('is-selected');
      el.setAttribute('aria-pressed', 'true');
      state.service = el.dataset.serviceKey;
      state.serviceLabel = el.querySelector('.ap-service-name').textContent;
      document.getElementById('ap-service').value = state.service;
      form.querySelector('[data-next="1"]').disabled = false;
    });
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); } });
  });

  // ── Step 2: calendar + slots ──
  var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var DOWS = ['Su','Mo','Tu','We','Th','Fr','Sa'];

  function initCal() {
    var now = new Date();
    calYear = now.getFullYear();
    calMonth = now.getMonth();
    renderCal();
  }

  function renderCal() {
    document.getElementById('ap-cal-title').textContent = MONTHS[calMonth] + ' ' + calYear;
    var grid = document.getElementById('ap-cal-grid');
    grid.innerHTML = '';
    DOWS.forEach(function (d) { var el = document.createElement('div'); el.className = 'ap-cal-dow'; el.textContent = d; grid.appendChild(el); });
    var first = new Date(calYear, calMonth, 1).getDay();
    var days = new Date(calYear, calMonth + 1, 0).getDate();
    var today = new Date(); today.setHours(0,0,0,0);
    for (var i = 0; i < first; i++) { var empty = document.createElement('div'); empty.className = 'ap-cal-day is-empty'; grid.appendChild(empty); }
    for (var d = 1; d <= days; d++) {
      var dt = new Date(calYear, calMonth, d);
      var iso = calYear + '-' + String(calMonth + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-cal-day';
      btn.textContent = d;
      btn.dataset.date = iso;
      if (dt < today) btn.classList.add('is-disabled');
      if (dt.getTime() === today.getTime()) btn.classList.add('is-today');
      if (iso === state.date) btn.classList.add('is-selected');
      btn.addEventListener('click', function () { selectDate(this.dataset.date); });
      grid.appendChild(btn);
    }
  }

  document.getElementById('ap-cal-prev').addEventListener('click', function () { calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; } renderCal(); });
  document.getElementById('ap-cal-next').addEventListener('click', function () { calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; } renderCal(); });

  function selectDate(iso) {
    state.date = iso;
    state.time = '';
    document.getElementById('ap-date').value = iso;
    document.getElementById('ap-time').value = '';
    document.querySelectorAll('.ap-cal-day').forEach(function (b) { b.classList.toggle('is-selected', b.dataset.date === iso); });
    var banner = document.getElementById('ap-sel-banner');
    banner.style.display = '';
    document.getElementById('ap-sel-date-text').textContent = iso;
    form.querySelector('[data-next="2"]').disabled = true;
    loadSlots(iso);
  }

  function loadSlots(iso) {
    var slotsEl = document.getElementById('ap-slots');
    slotsEl.innerHTML = '<div class="ap-slots-empty">Loading…</div>';
    var url = '<?= h(resolve_link('/appointment/slots/')) ?>?service=' + encodeURIComponent(state.service) + '&date=' + encodeURIComponent(iso);
    fetch(url).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.slots || data.slots.length === 0) {
        slotsEl.innerHTML = '<div class="ap-slots-empty">No available times on this date.</div>';
        return;
      }
      slotsEl.innerHTML = '';
      data.slots.forEach(function (s) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ap-slot' + (s.available ? '' : ' is-disabled');
        btn.textContent = formatTime12(s.time);
        if (s.available) {
          btn.addEventListener('click', function () { selectSlot(s.time); });
        }
        slotsEl.appendChild(btn);
      });
    }).catch(function () { slotsEl.innerHTML = '<div class="ap-slots-empty">Unable to load availability.</div>'; });
  }

  function selectSlot(time) {
    state.time = time;
    document.getElementById('ap-time').value = time;
    document.querySelectorAll('.ap-slot').forEach(function (b) { b.classList.toggle('is-selected', b.textContent === formatTime12(time)); });
    form.querySelector('[data-next="2"]').disabled = false;
  }

  function formatTime12(hhmm) {
    var parts = hhmm.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
  }

  // ── Step 4: summary ──
  function buildSummary() {
    var headline = state.serviceLabel + ' on ' + state.date + ' at ' + formatTime12(state.time);
    document.getElementById('ap-sum-headline').textContent = headline;
    var rows = [
      ['Service', state.serviceLabel],
      ['Date', state.date],
      ['Time', formatTime12(state.time)],
      ['Name', (document.getElementById('ap-fn').value + ' ' + document.getElementById('ap-ln').value).trim()],
      ['Email', document.getElementById('ap-em').value],
      ['Mobile', document.getElementById('ap-cc').value + ' ' + document.getElementById('ap-mob').value]
    ];
    var notes = document.getElementById('ap-notes').value.trim();
    if (notes) rows.push(['Notes', notes]);
    var html = '';
    rows.forEach(function (r) {
      html += '<div class="ap-summary-row"><span class="ap-k">' + esc(r[0]) + '</span><span class="ap-v">' + esc(r[1]) + '</span></div>';
    });
    document.getElementById('ap-sum-rows').innerHTML = html;
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  // ── Step 3: live validation (gates the Review & Confirm button) ──
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  function fieldErr(name, msg) {
    var err = form.querySelector('[data-err="' + name + '"]');
    if (err) err.textContent = msg || '';
    var input = document.getElementById('ap-' + name);
    if (input) input.classList.toggle('is-invalid', !!msg);
  }
  function detailsValid(showErrors) {
    var fn = document.getElementById('ap-fn').value.trim();
    var ln = document.getElementById('ap-ln').value.trim();
    var em = document.getElementById('ap-em').value.trim();
    var mob = document.getElementById('ap-mob').value.trim();
    var ok = true;
    if (fn.length < 3) { if (showErrors) fieldErr('fn', 'At least 3 characters.'); ok = false; } else { fieldErr('fn', ''); }
    if (ln.length < 3) { if (showErrors) fieldErr('ln', 'At least 3 characters.'); ok = false; } else { fieldErr('ln', ''); }
    if (!EMAIL_RE.test(em)) { if (showErrors) fieldErr('em', 'Enter a valid email address.'); ok = false; } else { fieldErr('em', ''); }
    if (!/^\d{10}$/.test(mob)) { if (showErrors) fieldErr('mob', 'Enter exactly 10 digits.'); ok = false; } else { fieldErr('mob', ''); }
    var btn = document.getElementById('ap-next3');
    if (btn) btn.disabled = !ok;
    return ok;
  }
  ['ap-fn', 'ap-ln', 'ap-em', 'ap-mob'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      if (id === 'ap-mob') { el.value = el.value.replace(/\D/g, '').slice(0, 10); }
      detailsValid(el.value.trim() !== '');
    });
  });

  // ── Navigation ──
  form.querySelectorAll('[data-next]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = current + 1;
      if (target === 4 && !detailsValid(true)) { return; }
      go(target);
    });
  });
  form.querySelectorAll('[data-prev]').forEach(function (btn) {
    btn.addEventListener('click', function () { go(current - 1); });
  });

  // ── Submit guard (server re-validates too) ──
  form.addEventListener('submit', function (e) {
    if (!detailsValid(true)) { e.preventDefault(); go(3); }
  });
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
