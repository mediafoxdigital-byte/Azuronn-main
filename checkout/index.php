<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_customer_auth('/checkout/');
$customer = current_customer();
if ($customer === null) {
    redirect(resolve_link('/account/login/?next=' . urlencode('/checkout/')));
}

$savedAddresses = customer_saved_addresses($customer);
$savedAddressIndexInput = clean_string($_POST['saved_address_index'] ?? $_GET['saved_address_index'] ?? '', 20);
$savedAddressIndex = ctype_digit($savedAddressIndexInput) ? clean_int($savedAddressIndexInput, 0, 50) : null;
$selectedSavedAddress = ($savedAddressIndex !== null && isset($savedAddresses[$savedAddressIndex])) ? $savedAddresses[$savedAddressIndex] : customer_primary_saved_address($customer);

$cart = cart_state();
if ($cart['items'] === []) {
    site_flash_set('error', 'Add items to your cart before checkout.');
    redirect(resolve_link('/cart/'));
}

$pageFlash = site_flash_pull();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $pageFlash = ['type' => 'error', 'message' => 'Session expired. Please try again.'];
    } else {
        $result = checkout_place_order($_POST);
        if ($result['ok'] ?? false) {
            // Stripe redirect: send the customer to Stripe's hosted Checkout page.
            if (!empty($result['redirect'])) {
                header('Location: ' . $result['redirect']);
                exit;
            }
            $order = $result['order'] ?? [];
            site_flash_set('success', 'Order ' . (string) ($order['id'] ?? '') . ' placed successfully.');
            redirect(resolve_link('/account/'));
        }
        $pageFlash = ['type' => 'error', 'message' => (string) ($result['message'] ?? 'Unable to place order.')];
    }
}

$pageTitle = 'Checkout - ' . SITE_NAME;
$bodyClass = 'checkout-page';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
  /* ============================================================
     CHECKOUT PAGE — Classic / Elegant single-source styles.
     Mirrors the cart page design language exactly (same tokens,
     same open editorial layout, same classic summary panel).
     Presentation only: every form field, name attribute, the
     address-picker onchange, the payment radios, the CSRF token
     and the submit button in the markup below are untouched, so
     the order flow is unchanged.
     ============================================================ */
  .checkout-page {
    --ck-serif: 'Cormorant Garamond', 'Jost', Georgia, serif;
    --ck-sans: 'Jost', 'Helvetica Neue', Arial, sans-serif;
    --ck-ink: #1c1c1c;
    --ck-ink-soft: #6b6b6b;
    --ck-mute: #9a948a;
    --ck-gold: #b08d57;
    --ck-line: #e7e2d9;
    --ck-bg-tint: #fbfaf7;
    font-family: var(--ck-sans);
    background: #ffffff;
    color: var(--ck-ink);
  }

  /* ---- Wider shell: fills the viewport, kills the side gutters ---- */
  .checkout-page .checkout-shell .container {
    width: min(1500px, calc(100vw - 48px));
    max-width: min(1500px, calc(100vw - 48px));
  }
  .checkout-page .checkout-shell { padding: 0 0 80px; }

  /* ---- Header (was the green gradient banner) ---- */
  .checkout-page .commerce-hero {
    background: none;
    box-shadow: none;
    border-radius: 0;
    color: var(--ck-ink);
    display: block;
    padding: 46px 0 18px;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--ck-line);
  }
  .checkout-page .commerce-hero .auth-kicker {
    color: var(--ck-gold);
    font-size: 0.66rem;
    letter-spacing: 0.2em;
    font-weight: 600;
    margin-bottom: 12px;
  }
  .checkout-page .commerce-hero h1 {
    font-family: var(--ck-serif);
    font-size: clamp(2.1rem, 3.6vw, 3rem);
    font-weight: 500;
    color: var(--ck-ink);
    line-height: 1.1;
    letter-spacing: 0.01em;
    margin: 0 0 8px;
  }
  .checkout-page .commerce-hero p {
    margin: 0;
    color: var(--ck-ink-soft);
    font-size: 1rem;
    line-height: 1.6;
    max-width: 620px;
  }

  /* ---- Flash message ---- */
  .checkout-page .store-flash {
    background: var(--ck-bg-tint);
    color: var(--ck-ink);
    border: 1px solid var(--ck-line);
    border-radius: 4px;
    padding: 11px 20px;
    text-align: center;
    font-size: 0.85rem;
    box-shadow: none;
    margin: 24px 0 0;
  }

  /* ---- Two-column page grid (form | summary) ---- */
  .checkout-page .checkout-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 380px;
    gap: 56px;
    align-items: start;
    margin-top: 36px;
  }
  .checkout-page .checkout-main { gap: 0; }

  /* ---- Cards -> open editorial sections (no glass box) ---- */
  .checkout-page .checkout-card {
    background: transparent;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    padding: 0 0 38px;
    margin-bottom: 38px;
    border-bottom: 1px solid var(--ck-line);
  }
  .checkout-page .checkout-card:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
  .checkout-page .checkout-card-head {
    display: block;
    margin-bottom: 24px;
  }
  .checkout-page .checkout-card-head .auth-kicker {
    color: var(--ck-gold);
    font-size: 0.66rem;
    letter-spacing: 0.2em;
    font-weight: 600;
    margin-bottom: 8px;
  }
  .checkout-page .checkout-card-head h2 {
    font-family: var(--ck-serif);
    font-size: 1.7rem;
    font-weight: 500;
    color: var(--ck-ink);
    line-height: 1.2;
    letter-spacing: 0.01em;
    margin: 0;
  }
  .checkout-page .checkout-card-head h2::before { display: none; }

  /* ---- Form fields: clean, thin-bordered inputs ---- */
  .checkout-page .checkout-grid { gap: 18px 20px; }
  .checkout-page .store-field { display: flex; flex-direction: column; gap: 7px; margin: 0; }
  .checkout-page .store-field > span {
    font-size: 0.66rem;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: var(--ck-mute);
    font-weight: 600;
  }
  .checkout-page .store-field input,
  .checkout-page .store-field textarea,
  .checkout-page .store-field select {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 14px;
    border: 1px solid var(--ck-line);
    border-radius: 3px;
    background: #fff;
    color: var(--ck-ink);
    font-family: var(--ck-sans);
    font-size: 0.9rem;
    letter-spacing: 0.02em;
    transition: border-color .2s, box-shadow .2s;
  }
  .checkout-page .store-field textarea { resize: vertical; line-height: 1.6; }
  .checkout-page .store-field input:focus,
  .checkout-page .store-field textarea:focus,
  .checkout-page .store-field select:focus {
    outline: none;
    border-color: var(--ck-gold);
    box-shadow: 0 0 0 1px rgba(176, 141, 87, 0.18);
  }
  .checkout-page .store-field input::placeholder,
  .checkout-page .store-field textarea::placeholder { color: #c4bdb1; }
  .checkout-page .checkout-address-picker { margin-bottom: 22px; }

  /* ---- Payment method radios: classic selectable rows ---- */
  .checkout-page .option-card-grid { gap: 16px; margin-bottom: 22px; }
  .checkout-page .option-card {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 18px 20px;
    border-radius: 4px;
    border: 1px solid var(--ck-line);
    background: #fff;
    box-shadow: none;
    transform: none;
    transition: border-color .2s, background .2s, box-shadow .2s;
  }
  .checkout-page .option-card:hover { border-color: var(--ck-ink-soft); transform: none; box-shadow: none; }
  .checkout-page .option-card:has(input:checked) {
    border-color: var(--ck-ink);
    background: var(--ck-bg-tint);
    box-shadow: 0 0 0 1px var(--ck-ink);
  }
  .checkout-page .option-card span {
    color: var(--ck-ink);
    font-family: var(--ck-sans);
    font-size: 0.92rem;
    font-weight: 600;
    letter-spacing: 0.02em;
  }
  .checkout-page .option-card small {
    color: var(--ck-ink-soft);
    font-size: 0.8rem;
    line-height: 1.55;
  }
  .checkout-page .option-card:has(input:checked) span { color: var(--ck-ink); }

  /* ---- Order summary: classic panel (matches cart) ---- */
  .checkout-page .summary-panel { position: static; }
  .checkout-page .summary-card {
    background: var(--ck-bg-tint);
    border: 1px solid var(--ck-line);
    border-radius: 4px;
    padding: 32px 28px;
    box-shadow: none;
    position: sticky;
    top: 24px;
  }
  .checkout-page .summary-card .auth-kicker {
    color: var(--ck-gold);
    font-size: 0.66rem;
    letter-spacing: 0.2em;
    font-weight: 600;
    display: block;
    text-align: center;
    margin-bottom: 6px;
  }
  .checkout-page .summary-card h2 {
    font-family: var(--ck-serif);
    font-size: 1.6rem;
    font-weight: 500;
    color: var(--ck-ink);
    text-align: center;
    margin: 0 0 6px;
    display: block;
  }
  .checkout-page .summary-card h2::before { display: none; }
  .checkout-page .summary-card h2::after {
    content: "";
    display: block;
    width: 40px;
    height: 1px;
    background: var(--ck-gold);
    margin: 14px auto 22px;
  }

  /* Mini line items */
  .checkout-page .checkout-mini-lines { gap: 0; margin-bottom: 8px; }
  .checkout-page .checkout-mini-line {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--ck-line);
  }
  .checkout-page .checkout-mini-line:first-child { padding-top: 0; }
  .checkout-page .checkout-mini-line > :first-child {
    flex: 0 0 auto;
    width: 56px; height: 56px;
    border-radius: 3px;
    background: #fff;
    border: 1px solid var(--ck-line);
    padding: 5px;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
  }
  .checkout-page .checkout-mini-line img,
  .checkout-page .checkout-mini-line video {
    width: 100%; height: 100%; object-fit: contain; mix-blend-mode: multiply;
    border-radius: 0; margin: 0;
  }
  .checkout-page .checkout-mini-line > div {
    flex: 1 1 auto; min-width: 0;
    display: flex; flex-direction: column; gap: 3px;
  }
  .checkout-page .checkout-mini-line > div strong {
    font-family: var(--ck-serif);
    font-size: 1.05rem; font-weight: 500; color: var(--ck-ink); line-height: 1.2;
  }
  .checkout-page .checkout-mini-line > div span {
    font-size: 0.74rem; color: var(--ck-mute); line-height: 1.4;
    text-transform: uppercase; letter-spacing: 0.06em;
  }
  .checkout-page .checkout-mini-line > strong:last-child {
    flex: 0 0 auto;
    font-family: var(--ck-sans);
    font-size: 0.95rem; font-weight: 600; color: var(--ck-ink);
  }

  /* Summary rows */
  .checkout-page .summary-row {
    display: flex; justify-content: space-between; align-items: baseline;
    margin: 0; padding: 11px 0;
    border-bottom: 1px solid var(--ck-line);
    font-size: 0.9rem;
  }
  .checkout-page .summary-row span { color: var(--ck-ink-soft); }
  .checkout-page .summary-row strong { color: var(--ck-ink); font-weight: 500; }
  .checkout-page .summary-row-total {
    display: flex; justify-content: space-between; align-items: baseline;
    background: transparent;
    border: 0; border-top: 1px solid var(--ck-line);
    border-radius: 0;
    padding: 20px 0 4px; margin-top: 6px;
    font-family: var(--ck-serif); font-size: 1.4rem;
  }
  .checkout-page .summary-row-total span {
    color: var(--ck-ink-soft); font-size: 0.7rem; text-transform: uppercase;
    letter-spacing: 0.16em; font-family: var(--ck-sans); font-weight: 600;
  }
  .checkout-page .summary-row-total strong { color: var(--ck-ink); font-weight: 500; }

  /* ---- Place Order button: classic ink -> gold ---- */
  .checkout-page .summary-card .store-btn-primary,
  .checkout-page .store-btn-primary {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; box-sizing: border-box;
    margin-top: 24px;
    background: var(--ck-ink); color: #fff;
    border: 1px solid var(--ck-ink); border-radius: 2px;
    font-family: var(--ck-sans);
    font-size: 0.74rem; letter-spacing: 0.18em; font-weight: 600;
    padding: 16px 24px; text-transform: uppercase;
    text-decoration: none; cursor: pointer;
    transition: background .3s, color .3s, border-color .3s;
  }
  .checkout-page .summary-card .store-btn-primary:hover,
  .checkout-page .store-btn-primary:hover {
    background: var(--ck-gold); border-color: var(--ck-gold); color: #fff;
  }

  /* ============================================================
     RESPONSIVE
     ============================================================ */
  @media (max-width: 1100px) {
    .checkout-page .checkout-shell .container { width: min(100%, calc(100vw - 40px)); max-width: min(100%, calc(100vw - 40px)); }
    .checkout-page .checkout-layout { grid-template-columns: minmax(0, 1fr) 320px; gap: 40px; }
  }
  @media (max-width: 900px) {
    .checkout-page .checkout-shell .container { width: min(100%, calc(100vw - 32px)); max-width: min(100%, calc(100vw - 32px)); }
    .checkout-page .checkout-layout { grid-template-columns: 1fr; gap: 36px; }
    .checkout-page .summary-card { position: static; }
  }
  @media (max-width: 560px) {
    .checkout-page .checkout-grid { grid-template-columns: 1fr; }
    .checkout-page .option-card-grid { grid-template-columns: 1fr; }
    .checkout-page .commerce-hero { padding-top: 32px; }
  }
</style>

<section class="checkout-shell reveal-in">
  <div class="container">
    <div class="commerce-hero">
      <div>
        <span class="auth-kicker">Checkout</span>
        <h1>Complete your order</h1>
        <p>Your payment method, delivery details, and order summary are connected to real stored order records.</p>
      </div>
    </div>

    <?php if ($pageFlash !== null): ?>
      <div class="store-flash <?= h($pageFlash['type']) ?>"><?= h($pageFlash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= h(resolve_link('/checkout/')) ?>" class="checkout-layout">
      <?php csrf_field(); ?>

      <div class="checkout-main">
        <div class="checkout-card">
          <div class="checkout-card-head">
            <span class="auth-kicker">Delivery</span>
            <h2>Shipping Details</h2>
          </div>
          <?php if ($savedAddresses !== []): ?>
            <div class="checkout-address-picker">
              <label class="store-field">
                <span>Use a Saved Address</span>
                <select onchange="window.location.href='<?= h(resolve_link('/checkout/')) ?>' + (this.value !== '' ? '?saved_address_index=' + encodeURIComponent(this.value) : '')">
                  <option value="">Choose from saved addresses</option>
                  <?php foreach ($savedAddresses as $index => $address): ?>
                    <option value="<?= h((string) $index) ?>" <?= $savedAddressIndex === $index ? 'selected' : '' ?>>
                      <?= h($address['label']) ?> - <?= h($address['city']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          <?php endif; ?>
          <?php if ($savedAddressIndex !== null): ?><input type="hidden" name="saved_address_index" value="<?= h((string) $savedAddressIndex) ?>"><?php endif; ?>
          <div class="checkout-grid">
            <label class="store-field">
              <span>Full Name</span>
              <input type="text" name="full_name" required value="<?= h((string) ($_POST['full_name'] ?? ($selectedSavedAddress['recipient_name'] ?? $customer['name']))) ?>">
            </label>
            <label class="store-field">
              <span>Phone</span>
              <input type="tel" name="phone" required autocomplete="tel" placeholder="07700 900123" value="<?= h((string) ($_POST['phone'] ?? ($selectedSavedAddress['phone'] ?? $customer['phone']))) ?>">
            </label>
            <label class="store-field store-field-wide">
              <span>Address Line 1</span>
              <input type="text" name="address_line_1" required autocomplete="address-line1" placeholder="House number and street" value="<?= h((string) ($_POST['address_line_1'] ?? ($selectedSavedAddress['address_line_1'] ?? $customer['address_line_1']))) ?>">
            </label>
            <label class="store-field store-field-wide">
              <span>Address Line 2 <small>(optional)</small></span>
              <input type="text" name="address_line_2" autocomplete="address-line2" placeholder="Flat, building, locality" value="<?= h((string) ($_POST['address_line_2'] ?? ($selectedSavedAddress['address_line_2'] ?? $customer['address_line_2']))) ?>">
            </label>
            <label class="store-field">
              <span>Town / City</span>
              <input type="text" name="city" required autocomplete="address-level2" placeholder="London" value="<?= h((string) ($_POST['city'] ?? ($selectedSavedAddress['city'] ?? $customer['city']))) ?>">
            </label>
            <label class="store-field">
              <span>County <small>(optional)</small></span>
              <input type="text" name="state" autocomplete="address-level1" placeholder="Greater London" value="<?= h((string) ($_POST['state'] ?? ($selectedSavedAddress['state'] ?? $customer['state']))) ?>">
            </label>
            <label class="store-field">
              <span>Postcode</span>
              <input type="text" name="postal_code" required autocomplete="postal-code" placeholder="SW1A 1AA" maxlength="8" pattern="<?= h(uk_postcode_html_pattern()) ?>" title="Enter a valid UK postcode, for example SW1A 1AA" value="<?= h((string) ($_POST['postal_code'] ?? ($selectedSavedAddress['postal_code'] ?? $customer['postal_code']))) ?>">
            </label>
            <label class="store-field">
              <span>Country</span>
              <input type="text" value="<?= h(uk_country_name()) ?>" readonly disabled>
            </label>
          </div>
        </div>

        <div class="checkout-card">
          <div class="checkout-card-head">
            <span class="auth-kicker">Payment</span>
            <h2>Secure Card Payment</h2>
          </div>
          <div class="option-card-grid">
            <div class="option-card option-card-static is-selected">
              <span>Online Payment</span>
              <small>Pay securely by card via Stripe. You'll be redirected to complete payment.</small>
            </div>
          </div>
          <label class="store-field">
            <span>Order Notes</span>
            <textarea name="notes" rows="4" placeholder="Any gifting or delivery instructions"><?= h((string) ($_POST['notes'] ?? '')) ?></textarea>
          </label>
        </div>
      </div>

      <aside class="summary-panel">
        <div class="summary-card">
          <span class="auth-kicker">Summary</span>
          <h2><?= count($cart['items']) ?> items</h2>
          <div class="checkout-mini-lines">
            <?php foreach ($cart['items'] as $line): ?>
              <div class="checkout-mini-line">
                <?= store_media_markup((string) ($line['ring_media'] ?? ''), (string) ($line['ring_media_alt'] ?? ($line['product']['name'] ?? 'Ring')), 'checkout-mini-media') ?>
                <div>
                  <strong><?= h($line['product']['name']) ?></strong>
                  <span><?= h(line_variant_summary($line)) ?> / Qty <?= h((string) $line['quantity']) ?></span>
                  <?php if ((string) ($line['diamond_title'] ?? '') !== ''): ?>
                    <span>Diamond: <?= h((string) ($line['diamond_title'] ?? '')) ?></span>
                  <?php endif; ?>
                </div>
                <strong><?= h($line['line_total_label']) ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="summary-row"><span>Subtotal</span><strong><?= h($cart['subtotal_label']) ?></strong></div>
          <div class="summary-row"><span><?= h((string) ($cart['delivery_summary_label'] ?? 'Delivery')) ?></span><strong><?= h((string) ($cart['delivery_total_label'] ?? 'Free')) ?></strong></div>
          <div class="summary-row"><span>Shipping</span><strong><?= h((string) $cart['shipping_label']) ?></strong></div>
          <?php if ($cart['discount'] > 0): ?>
            <div class="summary-row"><span>Discount</span><strong>-<?= h($cart['discount_label']) ?></strong></div>
          <?php endif; ?>
          <div class="summary-row summary-row-total"><span>Total</span><strong><?= h($cart['total_label']) ?></strong></div>
          <button type="submit" class="store-btn-primary">Place Order</button>
        </div>
      </aside>
    </form>
  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
