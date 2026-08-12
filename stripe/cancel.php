<?php

declare(strict_types=1);

/**
 * Stripe Checkout Cancel Page
 *
 * Stripe redirects here when the customer clicks "back" on the hosted page.
 * We mark the order as cancelled (if it was genuinely not paid) so the customer
 * can return to their cart and try again.
 *
 * If the order has already been paid (e.g. the user pressed Back after paying),
 * we redirect to /order-confirmed/ or show a "payment already completed" message
 * rather than wrongly abandoning a paid order.
 */

// Prevent browser caching so Back never replays a stale cancel state.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/storefront.php';
require_once dirname(__DIR__) . '/includes/stripe.php';

$orderId  = clean_string($_GET['order_id'] ?? '', 80);
$token    = clean_string($_GET['token'] ?? '', 80);
$customer = current_customer();

$alreadyPaid = false;

if ($orderId !== '') {
    // Before abandoning, check whether this order was already paid.
    // This happens when a customer completes payment but then presses Back
    // through the browser history and lands back on the Stripe cancel URL.
    $orderRecord = supabase_get_order($orderId);

    if ($orderRecord !== null) {
        $currentPaymentStatus = strtolower((string) ($orderRecord['payment_status'] ?? ''));

        if ($currentPaymentStatus === 'paid') {
            // Order is already confirmed — redirect to account so the customer
            // can see it, and do NOT call checkout_abandon_order.
            $alreadyPaid = true;
        } elseif ($currentPaymentStatus === 'awaiting') {
            // Order not yet paid — safe to abandon via session or token.
            if ($customer !== null) {
                checkout_abandon_order($orderId, (string) ($customer['email'] ?? ''));
            } elseif ($token !== '') {
                checkout_abandon_order($orderId, '', $token);
            }
        }
        // 'cancelled' or other statuses: already handled — nothing to do.
    }
}

// If the order was already paid, send the customer to their account immediately.
if ($alreadyPaid) {
    // Re-set the order-confirmed session so /order-confirmed/ renders properly
    // if the customer hasn't seen it yet. If they have, account page is fine.
    if ($orderRecord !== null && empty($_SESSION['order_confirmed'])) {
        $_SESSION['order_confirmed'] = [
            'id'    => (string) ($orderRecord['id'] ?? ''),
            'total' => (string) ($orderRecord['total'] ?? ''),
            'name'  => (string) ($customer['name'] ?? ($orderRecord['customer_name'] ?? '')),
        ];
        redirect(resolve_link('/order-confirmed/'));
    } else {
        redirect(resolve_link('/account/'));
    }
    exit;
}

$cartCount = cart_state()['count'] ?? 0;

$pageTitle = 'Payment Cancelled - ' . SITE_NAME;
$bodyClass = 'stripe-cancel-page';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="checkout-shell reveal-in" style="background:var(--cream,#faf8f5); min-height:70vh;">
  <div class="container" style="max-width:640px; text-align:center; padding:72px 24px 80px;">

    <div style="width:64px;height:64px;border-radius:50%;background:#f5f0ea;border:2px solid #d4c9b8;display:flex;align-items:center;justify-content:center;margin:0 auto 28px;font-size:1.6rem;color:#9a8878;">&#10005;</div>

    <h1 style="font-family:var(--serif);font-size:2.1rem;font-weight:400;margin-bottom:12px;color:#1a1a1a;">
      Payment Not Completed
    </h1>
    <p style="color:#5c6360;font-size:1rem;line-height:1.75;margin-bottom:8px;">
      No payment has been taken and your order has been cancelled.
    </p>
    <p style="color:#5c6360;font-size:0.95rem;line-height:1.7;margin-bottom:36px;">
      <?php if ($cartCount > 0): ?>
        Your <?= $cartCount === 1 ? 'item is' : $cartCount . ' items are' ?> still saved in your cart &mdash; head back whenever you&rsquo;re ready to complete your order.
      <?php else: ?>
        Your items are saved in your cart &mdash; head back whenever you&rsquo;re ready to complete your order.
      <?php endif; ?>
    </p>

    <div style="background:#fff;border:1px solid #e8e2da;border-radius:10px;padding:22px 28px;margin-bottom:36px;text-align:left;">
      <p style="margin:0;color:#5c6360;font-size:0.9rem;line-height:1.7;">
        <strong style="color:#1a1a1a;">Changed your mind?</strong><br>
        Simply return to your cart and place your order again when you&rsquo;re ready. Your selections have been kept exactly as you left them.
      </p>
    </div>

    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
      <a href="<?= h(resolve_link('/cart/')) ?>" class="store-btn-primary" style="display:inline-block;padding:14px 36px;text-decoration:none;">
        Return to Cart
      </a>
      <a href="<?= h(resolve_link('/shop/')) ?>" class="store-btn-secondary" style="display:inline-block;padding:14px 32px;text-decoration:none;">
        Continue Browsing
      </a>
    </div>

  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
