<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/storefront.php';

// One-time session token set by stripe/success.php
$confirmed = $_SESSION['order_confirmed'] ?? null;
if (!is_array($confirmed) || ($confirmed['id'] ?? '') === '') {
    // No valid token — send to account page
    redirect(resolve_link('/account/'));
}

// Consume immediately so refreshing the page redirects instead of showing stale data
unset($_SESSION['order_confirmed']);

$orderId    = clean_string((string) ($confirmed['id'] ?? ''), 80);
$orderTotal = clean_string((string) ($confirmed['total'] ?? ''), 40);
$firstName  = clean_string(explode(' ', (string) ($confirmed['name'] ?? 'there'))[0], 60);

$pageTitle = 'Order Confirmed - ' . SITE_NAME;
$bodyClass = 'order-confirmed-page';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="checkout-shell reveal-in" style="background:var(--cream,#faf8f5); min-height:70vh;">
  <div class="container" style="max-width:660px; text-align:center; padding:72px 24px 80px;">

    <div style="width:64px;height:64px;border-radius:50%;background:#1a5c30;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:1.75rem;color:#fff;">&#10003;</div>

    <h1 style="font-family:var(--serif);font-size:2.2rem;font-weight:400;margin-bottom:10px;color:#1a1a1a;">
      Thank you, <?= h($firstName) ?>!
    </h1>
    <p style="color:#5c6360;font-size:1rem;line-height:1.75;margin-bottom:6px;">
      Your payment was successful and your order has been placed.
    </p>
    <p style="color:#5c6360;font-size:0.95rem;margin-bottom:32px;">
      Order reference: <strong style="color:#1a1a1a;"><?= h($orderId) ?></strong>
      <?php if ($orderTotal !== ''): ?>
        &nbsp;&middot;&nbsp; Total: <strong style="color:#1a1a1a;"><?= h($orderTotal) ?></strong>
      <?php endif; ?>
    </p>

    <div style="background:#fff;border:1px solid #e8e2da;border-radius:10px;padding:28px 32px;text-align:left;margin-bottom:36px;">
      <h2 style="font-family:var(--serif);font-size:1.1rem;font-weight:400;margin:0 0 14px;color:#1a1a1a;">What happens next?</h2>
      <ul style="list-style:none;margin:0;padding:0;color:#5c6360;font-size:0.92rem;line-height:1.8;">
        <li style="padding:6px 0;border-bottom:1px solid #f0ede8;">&#9654;&nbsp; Our team will review and begin crafting your order</li>
        <li style="padding:6px 0;border-bottom:1px solid #f0ede8;">&#9654;&nbsp; You&rsquo;ll receive updates via your account as the order progresses</li>
        <li style="padding:6px 0;">&#9654;&nbsp; Once dispatched, you&rsquo;ll receive tracking information</li>
      </ul>
    </div>

    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
      <a href="<?= h(resolve_link('/account/')) ?>" class="store-btn-primary" style="display:inline-block;padding:14px 32px;text-decoration:none;">
        View My Orders
      </a>
      <a href="<?= h(resolve_link('/')) ?>" class="store-btn-secondary" style="display:inline-block;padding:14px 32px;text-decoration:none;">
        Continue Shopping
      </a>
    </div>

  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
