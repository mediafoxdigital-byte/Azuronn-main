<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

require_customer_auth('/account/order/');
$customer = current_customer();
if ($customer === null) {
    redirect(resolve_link('/account/login/'));
}

$orderId = clean_string($_GET['id'] ?? '', 80);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        site_flash_set('error', 'Session expired. Please try again.');
    } else {
        $action = clean_string($_POST['action'] ?? '', 40);
        if ($action === 'request-order-action') {
            $result = customer_request_order_action(
                $orderId,
                clean_string($_POST['request_type'] ?? '', 20),
                clean_multiline($_POST['request_reason'] ?? '', 500)
            );
            site_flash_set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Unable to send the request.'));
        }
    }

    redirect(resolve_link('/account/order/?id=' . urlencode($orderId)));
}

$order = customer_order_by_id($customer, $orderId);
if ($order === null) {
    site_flash_set('error', 'Order record was not found for this account.');
    redirect(resolve_link('/account/'));
}

$presented = order_presenter_data($order, $customer);
$pageFlash = site_flash_pull();
$pageTitle = 'Order ' . (string) ($order['id'] ?? '') . ' - ' . SITE_NAME;
$bodyClass = 'account-page order-detail-page';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
  /* ============================================================
     ORDER / INVOICE PAGE — classic / elegant override (on-screen).
     Mirrors the cart / account design language. Scoped entirely to
     .order-detail-page so no other page is affected. Presentation
     only: no markup, form, CSRF token, link or button handler is
     changed, so the order flow + request form are untouched.
     ============================================================ */
  .order-detail-page {
    --iv-serif: 'Cormorant Garamond', 'Jost', Georgia, serif;
    --iv-sans: 'Jost', 'Helvetica Neue', Arial, sans-serif;
    --iv-ink: #1c1c1c;
    --iv-ink-soft: #6b6b6b;
    --iv-mute: #9a948a;
    --iv-gold: #b08d57;
    --iv-line: #e7e2d9;
    --iv-tint: #fbfaf7;
  }
  .order-detail-page .account-shell { padding: 0 0 84px; }
  .order-detail-page .account-shell .container { width: min(1200px, calc(100vw - 48px)); max-width: min(1200px, calc(100vw - 48px)); }

  /* Hero -> flat classic (no green gradient) */
  .order-detail-page .account-hero.order-hero {
    background: none; box-shadow: none; border-radius: 0; color: var(--iv-ink);
    padding: 42px 0 20px; margin: 0; border-bottom: 1px solid var(--iv-line);
    display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; flex-wrap: wrap;
  }
  .order-detail-page .account-hero.order-hero::before { display: none; }
  .order-detail-page .order-hero > div:first-child { min-width: 0; }
  .order-detail-page .order-hero .auth-kicker { color: var(--iv-gold); font-size: .66rem; letter-spacing: .2em; font-weight: 600; margin-bottom: 10px; }
  .order-detail-page .order-hero h1 { font-family: var(--iv-serif); font-size: clamp(1.9rem, 3.2vw, 2.6rem); font-weight: 500; color: var(--iv-ink); line-height: 1.1; margin: 0 0 8px; }
  .order-detail-page .order-hero p { color: var(--iv-ink-soft); font-size: .92rem; line-height: 1.6; margin: 0; max-width: 560px; }
  .order-detail-page .account-hero-actions { gap: 10px; flex-wrap: wrap; margin: 0; flex-shrink: 0; }
  .order-detail-page .store-btn-secondary {
    background: transparent; color: var(--iv-ink); border: 1px solid var(--iv-ink); border-radius: 2px;
    padding: 11px 20px; font-family: var(--iv-sans); font-size: .68rem; letter-spacing: .14em; text-transform: uppercase;
    font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: background .25s, color .25s;
  }
  .order-detail-page .store-btn-secondary:hover { background: var(--iv-ink); color: #fff; }

  .order-detail-page .store-flash { background: var(--iv-tint); color: var(--iv-ink); border: 1px solid var(--iv-line); border-radius: 4px; box-shadow: none; padding: 11px 20px; text-align: center; font-size: .85rem; margin: 24px 0 0; }

  /* ---- Structured document layout ------------------------------------
     Meta strip runs full width, then a two-column body (lines | totals),
     then a full-width footer row for address + operational detail.
     Every block is a bordered card of equal rhythm so nothing floats. */
  .order-detail-page .order-detail-layout { display: block; margin-top: 32px; }

  .order-detail-page .invoice-doc-section { margin: 0 0 22px; }
  .order-detail-page .invoice-doc-body {
    display: grid; grid-template-columns: minmax(0, 1fr) 340px;
    gap: 22px; align-items: start; margin: 0 0 22px;
  }
  .order-detail-page .order-side-stack { display: grid; gap: 22px; align-content: start; }
  .order-detail-page .invoice-doc-footer { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px; align-items: stretch; }

  /* Panels -> uniform bordered cards */
  .order-detail-page .account-panel {
    background: #fff; border: 1px solid var(--iv-line); border-radius: 4px; box-shadow: none;
    padding: 24px 26px; margin: 0; overflow: visible; height: 100%;
  }
  .order-detail-page .invoice-lines-panel { height: auto; align-self: start; }
  .order-detail-page .invoice-panel::before, .order-detail-page .invoice-summary-card::before { display: none; }

  /* Uniform card heading block */
  .order-detail-page .account-panel > .auth-kicker { display: block; margin: 0 0 4px; }
  .order-detail-page .account-panel > h2 { margin: 0 0 18px; padding-bottom: 14px; border-bottom: 1px solid var(--iv-line); }

  .order-detail-page .auth-kicker { color: var(--iv-gold); font-size: .64rem; letter-spacing: .18em; font-weight: 600; }
  .order-detail-page .invoice-head h2, .order-detail-page .invoice-section-head h2, .order-detail-page .account-panel h2 {
    font-family: var(--iv-serif); font-size: 1.35rem; font-weight: 500; color: var(--iv-ink); letter-spacing: .02em; line-height: 1.2;
  }
  .order-detail-page .invoice-head h2::before, .order-detail-page .invoice-section-head h2::before, .order-detail-page .account-panel h2::before { display: none; }
  .order-detail-page .invoice-head { margin: 0 0 20px; padding-bottom: 16px; border-bottom: 1px solid var(--iv-line); align-items: center; }
  .order-detail-page .invoice-head h2 { margin: 4px 0 0; padding: 0; border: 0; }
  .order-detail-page .invoice-section-head { margin: 0 0 16px; padding-bottom: 14px; border-bottom: 1px solid var(--iv-line); align-items: center; }
  .order-detail-page .invoice-section-head h2 { margin: 4px 0 0; }
  .order-detail-page .invoice-section-head + * { margin-top: 0; }

  .order-detail-page .status-pill { background: transparent; border: 1px solid var(--iv-line); border-radius: 2px; color: var(--iv-ink-soft); font-family: var(--iv-sans); font-size: .6rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; padding: 4px 9px; }
  .order-detail-page .status-pill-accent { border-color: var(--iv-gold); color: var(--iv-gold); }

  .order-detail-page .invoice-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0; margin: 0; }
  .order-detail-page .invoice-info-card {
    background: transparent; border: 0; border-right: 1px solid var(--iv-line); border-radius: 0; box-shadow: none;
    padding: 0 22px; display: flex; flex-direction: column; gap: 6px;
  }
  .order-detail-page .invoice-info-card:first-child { padding-left: 0; }
  .order-detail-page .invoice-info-card:last-child { border-right: 0; padding-right: 0; }
  .order-detail-page .invoice-note-block { background: var(--iv-tint); border: 1px solid var(--iv-line); border-radius: 3px; box-shadow: none; padding: 16px 18px; }
  .order-detail-page .invoice-info-card span { margin: 0; color: var(--iv-mute); font-size: .62rem; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; }
  .order-detail-page .invoice-info-card strong { margin: 0; font-family: var(--iv-serif); font-size: 1.15rem; font-weight: 500; color: var(--iv-ink); line-height: 1.3; word-break: break-word; }
  .order-detail-page .invoice-info-card small { color: var(--iv-mute); font-size: .74rem; line-height: 1.5; margin-top: auto; }

  .order-detail-page .invoice-line-list { gap: 0; margin: 0; }
  .order-detail-page .invoice-line-card {
    display: grid; grid-template-columns: 64px minmax(0, 1fr) auto; gap: 18px; align-items: center;
    background: transparent; border: 0; border-bottom: 1px solid var(--iv-line); border-radius: 0; box-shadow: none; padding: 14px 0;
  }
  .order-detail-page .invoice-line-card:first-child { padding-top: 0; }
  .order-detail-page .invoice-line-card:last-child { border-bottom: 0; padding-bottom: 0; }
  .order-detail-page .invoice-line-media { width: 64px; height: 64px; border: 1px solid var(--iv-line); border-radius: 3px; background: var(--iv-tint); padding: 6px; }
  .order-detail-page .invoice-line-media img { width: 100%; height: 100%; object-fit: contain; mix-blend-mode: multiply; }
  .order-detail-page .invoice-line-copy strong { font-family: var(--iv-serif); font-size: 1.08rem; font-weight: 500; color: var(--iv-ink); margin-bottom: 4px; }
  .order-detail-page .invoice-line-copy span, .order-detail-page .invoice-line-copy small { color: var(--iv-mute); font-size: .72rem; letter-spacing: .04em; line-height: 1.6; }
  .order-detail-page .invoice-line-total { font-family: var(--iv-sans); font-size: .95rem; font-weight: 600; color: var(--iv-ink); white-space: nowrap; }

  .order-detail-page .invoice-note-block p { color: var(--iv-ink-soft); line-height: 1.7; font-size: .88rem; }
  .order-detail-page .invoice-empty-state { background: var(--iv-tint); border: 1px solid var(--iv-line); border-radius: 3px; box-shadow: none; padding: 22px; margin: 0; }
  .order-detail-page .invoice-empty-state h3 { font-family: var(--iv-serif); font-size: 1.15rem; font-weight: 500; color: var(--iv-ink); margin: 0 0 8px; }
  .order-detail-page .invoice-empty-state p { color: var(--iv-ink-soft); font-size: .88rem; line-height: 1.7; margin: 0; }

  .order-detail-page .summary-row { border-bottom: 1px solid var(--iv-line); padding: 11px 0; margin: 0; font-size: .87rem; }
  .order-detail-page .summary-row span { color: var(--iv-ink-soft); }
  .order-detail-page .summary-row strong { color: var(--iv-ink); font-weight: 600; white-space: nowrap; }
  .order-detail-page .summary-row-total { border-bottom: 0; border-top: 2px solid var(--iv-ink); padding-top: 16px; margin-top: 8px; align-items: baseline; }
  .order-detail-page .summary-row-total span { color: var(--iv-ink); font-size: .7rem; text-transform: uppercase; letter-spacing: .16em; font-family: var(--iv-sans); font-weight: 600; }
  .order-detail-page .summary-row-total strong { font-family: var(--iv-serif); font-size: 1.5rem; font-weight: 500; }

  .order-detail-page .invoice-address-block { gap: 4px; }
  .order-detail-page .invoice-address-block strong { font-family: var(--iv-serif); font-size: 1.1rem; font-weight: 500; color: var(--iv-ink); margin-bottom: 6px; }
  .order-detail-page .invoice-address-block p { color: var(--iv-ink-soft); font-size: .88rem; line-height: 1.7; }
  .order-detail-page .invoice-muted-copy { color: var(--iv-mute); font-size: .88rem; margin: 0; }
  .order-detail-page .account-details.profile-detail-list { display: grid; gap: 0; }
  .order-detail-page .account-details.profile-detail-list > div {
    display: flex; align-items: baseline; justify-content: space-between; gap: 18px;
    padding: 10px 0; border-bottom: 1px solid var(--iv-line);
  }
  .order-detail-page .account-details.profile-detail-list > div:first-child { padding-top: 0; }
  .order-detail-page .account-details.profile-detail-list > div:last-child { border-bottom: 0; padding-bottom: 0; }
  .order-detail-page .account-details span { color: var(--iv-mute); font-size: .62rem; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; flex-shrink: 0; }
  .order-detail-page .account-details strong { color: var(--iv-ink); font-weight: 600; font-size: .88rem; text-align: right; word-break: break-word; }

  .order-detail-page .order-request-card p { color: var(--iv-ink-soft); font-size: .88rem; line-height: 1.7; margin: 0; }
  .order-detail-page .order-request-form { margin-top: 16px; display: grid; gap: 14px; }
  .order-detail-page .order-request-form .store-field { display: flex; flex-direction: column; gap: 7px; }
  .order-detail-page .order-request-form .store-field span { color: var(--iv-mute); font-size: .62rem; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; }
  .order-detail-page .order-request-form .store-field textarea { border: 1px solid var(--iv-line); border-radius: 3px; background: #fff; padding: 12px 14px; font-family: var(--iv-sans); font-size: .88rem; color: var(--iv-ink); min-height: 96px; resize: vertical; }
  .order-detail-page .order-request-form .store-field textarea:focus { outline: none; border-color: var(--iv-gold); box-shadow: 0 0 0 1px rgba(176, 141, 87, .18); }
  .order-detail-page .store-btn-primary { background: var(--iv-ink); color: #fff; border: 1px solid var(--iv-ink); border-radius: 2px; padding: 13px 22px; font-family: var(--iv-sans); font-size: .7rem; letter-spacing: .16em; text-transform: uppercase; font-weight: 600; transition: background .25s, border-color .25s; justify-self: start; }
  .order-detail-page .store-btn-primary:hover { background: var(--iv-gold); border-color: var(--iv-gold); }

  @media (max-width: 1080px) {
    .order-detail-page .invoice-doc-body { grid-template-columns: 1fr; }
    .order-detail-page .invoice-doc-footer { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .order-detail-page .invoice-notes-card { grid-column: 1 / -1; }
  }
  @media (max-width: 900px) {
    .order-detail-page .invoice-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px 0; }
    .order-detail-page .invoice-info-card:nth-child(2n) { border-right: 0; padding-right: 0; }
    .order-detail-page .invoice-info-card:nth-child(2n+1) { padding-left: 0; }
    .order-detail-page .invoice-doc-footer { grid-template-columns: 1fr; }
  }
  @media (max-width: 640px) {
    .order-detail-page .account-panel { padding: 20px; }
    .order-detail-page .invoice-grid { grid-template-columns: 1fr; gap: 18px; }
    .order-detail-page .invoice-info-card { border-right: 0; padding: 0; }
    .order-detail-page .invoice-line-card { grid-template-columns: 56px minmax(0, 1fr); gap: 14px; }
    .order-detail-page .invoice-line-total { grid-column: 2; }
  }

  /* ============================================================
     PRINT-ONLY INVOICE — a real A4 invoice, not a page screenshot.
     The #print-invoice block is hidden on screen; @media print hides
     ALL site chrome (header/footer/hero/buttons/cookie/scroll-top) by
     targeting direct body children, then shows only this block. Uses
     print-safe system fonts (no web-font dependency at print time).
     ============================================================ */
  #print-invoice { display: none; }
  #print-invoice, #print-invoice * { margin: 0; padding: 0; box-sizing: border-box; }
  #print-invoice { font-family: Arial, Helvetica, sans-serif; color: #1c1c1c; }
  @media print {
    @page { size: A4; margin: 14mm; }
    html, body { background: #fff !important; }
    body > *:not(#print-invoice) { display: none !important; }
    #print-invoice { display: block !important; width: 100%; }
  }
  #print-invoice .pi-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #b08d57; padding-bottom: 10px; margin-bottom: 20px; }
  #print-invoice .pi-brand { font-family: Georgia, 'Times New Roman', serif; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
  #print-invoice .pi-brand small { display: block; font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: 400; letter-spacing: .4px; color: #6b6b6b; margin-top: 5px; line-height: 1.5; }
  #print-invoice .pi-doctype { text-align: right; font-family: Georgia, 'Times New Roman', serif; font-size: 16px; font-weight: 700; letter-spacing: 2px; }
  #print-invoice .pi-doctype small { display: block; font-family: Arial, Helvetica, sans-serif; font-size: 9px; font-weight: 400; color: #6b6b6b; margin-top: 5px; letter-spacing: .3px; }
  #print-invoice .pi-cols { display: flex; justify-content: space-between; gap: 28px; margin-bottom: 20px; }
  #print-invoice .pi-col { flex: 1; min-width: 0; }
  #print-invoice .pi-label { font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #b08d57; margin-bottom: 6px; }
  #print-invoice .pi-name { font-size: 12px; font-weight: 700; }
  #print-invoice .pi-line { font-size: 10px; color: #444; line-height: 1.5; }
  #print-invoice .pi-meta-row { display: flex; justify-content: space-between; gap: 12px; font-size: 10px; padding: 2px 0; }
  #print-invoice .pi-meta-row span { color: #6b6b6b; }
  #print-invoice .pi-meta-row strong { color: #1c1c1c; font-weight: 700; text-align: right; }
  #print-invoice .pi-ship { margin-bottom: 18px; }
  #print-invoice table.pi-items { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10px; }
  #print-invoice table.pi-items thead th { background: #0d2019; color: #fff; text-align: left; padding: 7px 8px; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; font-weight: 700; }
  #print-invoice table.pi-items thead th.r, #print-invoice table.pi-items td.r { text-align: right; }
  #print-invoice table.pi-items tbody td { padding: 8px; border-bottom: 1px solid #e7e2d9; vertical-align: top; }
  #print-invoice table.pi-items td.prod { font-weight: 700; }
  #print-invoice table.pi-items td.var { color: #6b6b6b; font-size: 9px; padding-top: 2px; }
  #print-invoice .pi-totals { width: 250px; margin-left: auto; border-collapse: collapse; font-size: 10px; }
  #print-invoice .pi-totals td { padding: 4px 8px; }
  #print-invoice .pi-totals td.l { color: #6b6b6b; }
  #print-invoice .pi-totals td.v { text-align: right; color: #1c1c1c; font-weight: 700; }
  #print-invoice .pi-totals tr.pi-grand td { border-top: 2px solid #0d2019; padding-top: 8px; font-size: 13px; }
  #print-invoice .pi-totals tr.pi-grand td.l { color: #1c1c1c; font-weight: 700; letter-spacing: 1px; }
  #print-invoice .pi-notes { margin-top: 20px; border-top: 1px solid #e7e2d9; padding-top: 12px; }
  #print-invoice .pi-notes .pi-label { margin-bottom: 4px; }
  #print-invoice .pi-notes p { font-size: 10px; color: #444; line-height: 1.5; }
  #print-invoice .pi-foot { margin-top: 28px; border-top: 1px solid #e7e2d9; padding-top: 8px; display: flex; justify-content: space-between; font-size: 9px; color: #6b6b6b; }
</style>

<section class="account-shell reveal-in">
  <div class="container">
    <div class="account-hero order-hero">
      <div>
        <span class="auth-kicker">Order Detail</span>
        <h1><?= h((string) ($order['id'] ?? 'Invoice')) ?></h1>
        <p>Invoice-style record with payment status, delivery destination, and every product line tied to this order.</p>
      </div>
      <div class="account-hero-actions">
        <a class="store-btn-secondary" href="<?= h(resolve_link('/account/')) ?>">Back to Account</a>
        <a class="store-btn-secondary" href="<?= h(resolve_link('/account/order/invoice/?id=' . urlencode((string) ($order['id'] ?? '')))) ?>">Download PDF</a>
        <button class="store-btn-secondary" type="button" data-print-order>Print Invoice</button>
      </div>
    </div>

    <?php if ($pageFlash !== null): ?>
      <div class="store-flash <?= h($pageFlash['type']) ?>"><?= h($pageFlash['message']) ?></div>
    <?php endif; ?>

    <div class="order-detail-layout">
      <div class="account-panel invoice-panel invoice-doc-section">
        <div class="invoice-head">
          <div>
            <span class="auth-kicker">Azuronn Invoice</span>
            <h2>Order Summary</h2>
          </div>
          <div class="invoice-head-meta">
            <span class="status-pill"><?= h((string) ($presented['status_label'] ?? '')) ?></span>
            <span class="status-pill"><?= h((string) ($presented['payment_status_label'] ?? '')) ?></span>
            <?php if (is_array($presented['request_summary'] ?? null)): ?>
              <span class="status-pill status-pill-accent"><?= h((string) (($presented['request_summary']['label'] ?? 'Request Submitted'))) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="invoice-grid">
          <article class="invoice-info-card">
            <span>Invoice Number</span>
            <strong><?= h((string) ($order['id'] ?? '')) ?></strong>
            <small>Placed <?= h((string) (($presented['placed_at_formatted'] ?? '') !== '' ? $presented['placed_at_formatted'] : 'Recently')) ?></small>
          </article>
          <article class="invoice-info-card">
            <span>Payment Method</span>
            <strong><?= h((string) ($presented['payment_method_label'] ?? '')) ?></strong>
            <small><?= h((string) (($order['payment_reference'] ?? '') !== '' ? 'Reference ' . $order['payment_reference'] : 'Reference available after dispatch when needed')) ?></small>
          </article>
          <article class="invoice-info-card">
            <span>Tracking ID</span>
            <strong><?= h((string) (($presented['tracking_id'] ?? '') !== '' ? $presented['tracking_id'] : 'Not issued yet')) ?></strong>
            <small><?= h((string) (($presented['tracking_id'] ?? '') !== '' ? 'Use this reference to track your delivery.' : 'Available once your order is dispatched.')) ?></small>
          </article>
          <article class="invoice-info-card">
            <span>Customer</span>
            <strong><?= h((string) ($order['customer_name'] ?? ($customer['name'] ?? ''))) ?></strong>
            <small><?= h((string) ($order['customer_email'] ?? ($customer['email'] ?? ''))) ?></small>
          </article>
        </div>
      </div>

      <div class="invoice-doc-body">
        <div class="account-panel invoice-lines-panel">
          <div class="invoice-section-head">
            <div>
              <span class="auth-kicker">Items</span>
              <h2>Order Lines</h2>
            </div>
            <div class="invoice-head-meta">
              <span class="status-pill"><?= h((string) ($presented['item_count'] ?? 0)) ?> Item<?= ((int) ($presented['item_count'] ?? 0)) === 1 ? '' : 's' ?></span>
            </div>
          </div>

          <?php if (($presented['items'] ?? []) === []): ?>
            <div class="account-empty account-empty-compact invoice-empty-state">
              <h3>Legacy order record</h3>
              <p>This order was stored before detailed line items were available. Payment and status information are preserved here.</p>
            </div>
          <?php else: ?>
            <div class="invoice-line-list">
              <?php foreach ($presented['items'] as $line): ?>
                <article class="invoice-line-card">
                  <div class="invoice-line-media">
                    <img src="<?= h((string) ($line['image'] ?? '')) ?>" alt="<?= h((string) ($line['product_name'] ?? 'Ordered item')) ?>">
                  </div>
                  <div class="invoice-line-copy">
                    <strong><?= h((string) ($line['product_name'] ?? 'Item')) ?></strong>
                    <span><?= h(line_variant_summary($line)) ?></span>
                    <small>Unit <?= h((string) ($line['invoice_unit_price'] ?? $line['price'] ?? money_format(0))) ?> · Qty <?= h((string) ($line['quantity'] ?? 1)) ?></small>
                  </div>
                  <strong class="invoice-line-total"><?= h((string) ($line['invoice_line_total'] ?? $line['line_total'] ?? money_format(0))) ?></strong>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <aside class="order-side-stack">
          <div class="account-panel invoice-summary-card">
            <span class="auth-kicker">Invoice Totals</span>
            <h2><?= h((string) ($presented['invoice_total_heading'] ?? 'Invoice Total')) ?></h2>
            <div class="summary-row"><span>Items</span><strong><?= h((string) ($presented['item_count'] ?? 0)) ?></strong></div>
            <div class="summary-row"><span>Subtotal</span><strong><?= h((string) ($presented['subtotal_label'] ?? '')) ?></strong></div>
            <div class="summary-row"><span>Discount</span><strong>-<?= h((string) ($presented['discount_label'] ?? '')) ?></strong></div>
            <?php if (!empty($presented['delivery_has_express'])): ?>
              <div class="summary-row"><span>Express Delivery</span><strong><?= h((string) ($presented['delivery_label'] ?? '')) ?></strong></div>
            <?php endif; ?>
            <div class="summary-row"><span>Shipping</span><strong><?= h((string) ($presented['shipping_label'] ?? '')) ?></strong></div>
            <?php if (($order['coupon_code'] ?? '') !== ''): ?>
              <div class="summary-row"><span>Coupon</span><strong><?= h((string) $order['coupon_code']) ?></strong></div>
            <?php endif; ?>
            <div class="summary-row summary-row-total"><span><?= h((string) ($presented['invoice_total_caption'] ?? 'Total')) ?></span><strong><?= h((string) ($presented['total_label'] ?? '')) ?></strong></div>
          </div>

          <?php if (is_array($presented['request_summary'] ?? null)): ?>
            <div class="account-panel order-request-card">
              <span class="auth-kicker">Customer Request</span>
              <h2><?= h((string) ($presented['request_summary']['label'] ?? 'Request Submitted')) ?></h2>
              <p><?= h((string) (($presented['request_summary']['requested_at_formatted'] ?? '') !== '' ? 'Submitted on ' . $presented['request_summary']['requested_at_formatted'] . '.' : 'Your request has been logged on this order.')) ?></p>
              <?php if (($presented['request_summary']['reason'] ?? '') !== ''): ?>
                <div class="invoice-note-block compact-note-block">
                  <p><?= h((string) $presented['request_summary']['reason']) ?></p>
                </div>
              <?php endif; ?>
            </div>
          <?php elseif (is_array($presented['available_action'] ?? null)): ?>
            <div class="account-panel order-request-card" id="order-action">
              <span class="auth-kicker">Order Action</span>
              <h2><?= h((string) ($presented['available_action']['headline'] ?? 'Need help with this order?')) ?></h2>
              <p><?= h((string) ($presented['available_action']['description'] ?? '')) ?></p>
              <form method="post" action="<?= h(resolve_link('/account/order/?id=' . urlencode((string) ($order['id'] ?? '')))) ?>" class="auth-form order-request-form">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="request-order-action">
                <input type="hidden" name="request_type" value="<?= h((string) ($presented['available_action']['type'] ?? '')) ?>">
                <label class="store-field">
                  <span><?= h((string) ($presented['available_action']['reason_label'] ?? 'Reason')) ?></span>
                  <textarea name="request_reason" required placeholder="Add the detail the team should review before responding."></textarea>
                </label>
                <button type="submit" class="store-btn-primary"><?= h((string) ($presented['available_action']['button_label'] ?? 'Send Request')) ?></button>
              </form>
            </div>
          <?php elseif (is_array($presented['return_window'] ?? null) && !$presented['return_window']['is_open']): ?>
            <div class="account-panel order-request-card">
              <span class="auth-kicker">Order Action</span>
              <h2>Return Window Closed</h2>
              <p>This order was delivered on <?= h((string) $presented['return_window']['delivered_at_formatted']) ?>. Returns are only accepted within <?= h((string) order_return_window_days()) ?> days of delivery, so this order can no longer be returned.</p>
            </div>
          <?php endif; ?>
        </aside>
      </div>

      <div class="invoice-doc-footer">
        <div class="account-panel invoice-address-card">
          <span class="auth-kicker">Delivery Destination</span>
          <h2>Shipping Address</h2>
          <?php if (($presented['address_lines'] ?? []) === []): ?>
            <p class="invoice-muted-copy">No shipping address was stored on this order record.</p>
          <?php else: ?>
            <div class="invoice-address-block">
              <strong><?= h((string) ($order['customer_name'] ?? ($customer['name'] ?? ''))) ?></strong>
              <?php foreach ($presented['address_lines'] as $line): ?>
                <p><?= h($line) ?></p>
              <?php endforeach; ?>
              <p><?= h((string) (($order['customer_phone'] ?? '') !== '' ? $order['customer_phone'] : ($customer['phone'] ?? ''))) ?></p>
            </div>
          <?php endif; ?>
        </div>

        <div class="account-panel invoice-meta-card">
          <span class="auth-kicker">Status Snapshot</span>
          <h2>Operational Detail</h2>
          <div class="account-details profile-detail-list">
            <div><span>Order Status</span><strong><?= h((string) ($presented['status_label'] ?? '')) ?></strong></div>
            <div><span>Tracking ID</span><strong><?= h((string) (($presented['tracking_id'] ?? '') !== '' ? $presented['tracking_id'] : 'Not issued')) ?></strong></div>
            <div><span>Payment Status</span><strong><?= h((string) ($presented['payment_status_label'] ?? '')) ?></strong></div>
            <div><span>Payment Method</span><strong><?= h((string) ($presented['payment_method_label'] ?? '')) ?></strong></div>
            <div><span>Reference</span><strong><?= h((string) (($order['payment_reference'] ?? '') !== '' ? $order['payment_reference'] : 'Not issued')) ?></strong></div>
          </div>
        </div>

        <div class="account-panel invoice-notes-card">
          <span class="auth-kicker">Notes</span>
          <h2>Order Notes</h2>
          <p class="invoice-muted-copy"><?= h((string) (($order['notes'] ?? '') !== '' ? $order['notes'] : 'No additional notes were stored with this order.')) ?></p>
        </div>
      </div>
    </div>
  </div>
</section>

<?php
  // ── Print-only invoice (real A4 layout, not a page screenshot) ─────────
  // Hidden on screen; revealed only by the @media print block above, which
  // hides every other direct<body> child. Built from the same $presented /
  // $order data the visible page uses, so it never drifts out of sync.
  $ivBillName = (string) (($order['customer_name'] ?? '') !== '' ? $order['customer_name'] : ($customer['name'] ?? ''));
  $ivBillEmail = (string) (($order['customer_email'] ?? '') !== '' ? $order['customer_email'] : ($customer['email'] ?? ''));
  $ivBillPhone = (string) (($order['customer_phone'] ?? '') !== '' ? $order['customer_phone'] : ($customer['phone'] ?? ''));
  $ivRef = (string) ($order['payment_reference'] ?? '');
  $ivPay = trim((string) ($presented['payment_method_label'] ?? '') . ' / ' . (string) ($presented['payment_status_label'] ?? ''), ' /');
  $ivContact = 'Azuronn Fine Jewellery';
  if (defined('STORE_EMAIL') && STORE_EMAIL !== '') { $ivContact .= '  •  ' . STORE_EMAIL; }
  if (defined('STORE_PHONE') && STORE_PHONE !== '') { $ivContact .= '  •  ' . STORE_PHONE; }
  $ivWeb = (defined('SITE_URL') && SITE_URL !== '') ? str_replace(['https://', 'http://'], '', rtrim(SITE_URL, '/')) : '';
?>
<div id="print-invoice">
  <div class="pi-head">
    <div class="pi-brand">AZURONN<small><?= h($ivContact) ?><?php if ($ivWeb !== ''): ?><br><?= h($ivWeb) ?><?php endif; ?></small></div>
    <div class="pi-doctype">TAX INVOICE<small>Invoice <?= h((string) ($order['id'] ?? '')) ?></small></div>
  </div>

  <div class="pi-cols">
    <div class="pi-col">
      <div class="pi-label">Bill To</div>
      <div class="pi-name"><?= h($ivBillName !== '' ? $ivBillName : '—') ?></div>
      <?php if ($ivBillEmail !== ''): ?><div class="pi-line"><?= h($ivBillEmail) ?></div><?php endif; ?>
      <?php if ($ivBillPhone !== ''): ?><div class="pi-line"><?= h($ivBillPhone) ?></div><?php endif; ?>
    </div>
    <div class="pi-col">
      <div class="pi-label">Invoice Details</div>
      <div class="pi-meta-row"><span>Invoice No</span><strong><?= h((string) ($order['id'] ?? '')) ?></strong></div>
      <div class="pi-meta-row"><span>Date</span><strong><?= h((string) ($presented['placed_at_formatted'] ?? '')) ?></strong></div>
      <div class="pi-meta-row"><span>Status</span><strong><?= h((string) ($presented['status_label'] ?? '')) ?></strong></div>
      <div class="pi-meta-row"><span>Payment</span><strong><?= h($ivPay) ?></strong></div>
      <div class="pi-meta-row"><span>Reference</span><strong><?= h($ivRef !== '' ? $ivRef : '—') ?></strong></div>
      <?php if ((string) ($presented['tracking_id'] ?? '') !== ''): ?>
        <div class="pi-meta-row"><span>Tracking</span><strong><?= h((string) $presented['tracking_id']) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="pi-ship">
    <div class="pi-label">Ship To</div>
    <?php if (($presented['address_lines'] ?? []) === []): ?>
      <div class="pi-line">No shipping address stored.</div>
    <?php else: ?>
      <div class="pi-name"><?= h($ivBillName !== '' ? $ivBillName : '') ?></div>
      <?php foreach ($presented['address_lines'] as $ivAddrLine): ?><div class="pi-line"><?= h($ivAddrLine) ?></div><?php endforeach; ?>
    <?php endif; ?>
  </div>

  <table class="pi-items">
    <thead>
      <tr><th style="width:52%;">Item / Description</th><th class="r">Qty</th><th class="r">Unit</th><th class="r">Total</th></tr>
    </thead>
    <tbody>
      <?php if (($presented['items'] ?? []) === []): ?>
        <tr><td colspan="4" class="var">Legacy order record — line-item detail was not stored for this order.</td></tr>
      <?php else: ?>
        <?php foreach ($presented['items'] as $ivLine):
          $ivVariant = trim((string) line_variant_summary($ivLine));
        ?>
          <tr>
            <td class="prod"><?= h((string) ($ivLine['product_name'] ?? 'Item')) ?><?php if ($ivVariant !== ''): ?><div class="var">Variant: <?= h($ivVariant) ?></div><?php endif; ?></td>
            <td class="r"><?= h((string) ($ivLine['quantity'] ?? 1)) ?></td>
            <td class="r"><?= h((string) ($ivLine['invoice_unit_price'] ?? $ivLine['price'] ?? money_format(0))) ?></td>
            <td class="r"><?= h((string) ($ivLine['invoice_line_total'] ?? $ivLine['line_total'] ?? money_format(0))) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <table class="pi-totals">
    <tr><td class="l">Items</td><td class="v"><?= h((string) ($presented['item_count'] ?? 0)) ?></td></tr>
    <tr><td class="l">Subtotal</td><td class="v"><?= h((string) ($presented['subtotal_label'] ?? '')) ?></td></tr>
    <tr><td class="l">Discount</td><td class="v">-<?= h((string) ($presented['discount_label'] ?? '')) ?></td></tr>
    <?php if (!empty($presented['delivery_has_express'])): ?><tr><td class="l">Express Delivery</td><td class="v"><?= h((string) ($presented['delivery_label'] ?? '')) ?></td></tr><?php endif; ?>
    <tr><td class="l">Shipping</td><td class="v"><?= h((string) ($presented['shipping_label'] ?? '')) ?></td></tr>
    <?php if (($order['coupon_code'] ?? '') !== ''): ?><tr><td class="l">Coupon</td><td class="v"><?= h((string) $order['coupon_code']) ?></td></tr><?php endif; ?>
    <tr class="pi-grand"><td class="l"><?= h(strtoupper((string) ($presented['invoice_total_caption'] ?? 'Total'))) ?></td><td class="v"><?= h((string) ($presented['total_label'] ?? '')) ?></td></tr>
  </table>

  <?php if ((string) ($order['notes'] ?? '') !== ''): ?>
    <div class="pi-notes">
      <div class="pi-label">Notes</div>
      <p><?= h((string) $order['notes']) ?></p>
    </div>
  <?php endif; ?>

  <div class="pi-foot">
    <span>Thank you for your purchase — Azuronn</span>
    <span><?= h((string) ($order['id'] ?? '')) ?></span>
  </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
