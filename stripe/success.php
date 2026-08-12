<?php

declare(strict_types=1);

/**
 * Stripe Checkout Success Page
 *
 * Stripe redirects here after a successful payment with ?session_id=cs_...
 * We verify the session server-side, confirm the order belongs to the
 * logged-in customer, and idempotently flip it to paid/processing.
 *
 * Handles edge cases:
 *  - Order marked cancelled by cancel.php before this page ran (re-confirms it).
 *  - Order already confirmed (idempotent — safe to hit twice).
 *  - Webhook race: retries the order lookup up to 3 times with short delays.
 *  - Browser caching: Cache-Control: no-store prevents replaying a stale page.
 */

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/storefront.php';
require_once dirname(__DIR__) . '/includes/stripe.php';

// Prevent the browser from caching this page so that hitting Back never
// replays a stale success/error state.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

$customer  = current_customer(); // enrichment only — not required for confirmation
$sessionId = clean_string($_GET['session_id'] ?? '', 120);

$confirmed = false;
$orderId   = '';
$errorMsg  = '';
$errorType = ''; // 'no_session' | 'stripe_error' | 'not_paid' | 'not_found' | 'email_mismatch'

if ($sessionId === '') {
    $errorMsg  = 'No payment reference found. If you just completed a payment, please check your order history or contact support.';
    $errorType = 'no_session';
} elseif (!stripe_configured()) {
    $errorMsg  = 'Payment verification is not available. Please contact support.';
    $errorType = 'stripe_error';
} else {
    $sessionResult = stripe_retrieve_session($sessionId);
    if (!($sessionResult['ok'] ?? false)) {
        $errorMsg  = 'Unable to verify your payment right now. Please check your order history or contact support with your payment reference.';
        $errorType = 'stripe_error';
    } else {
        $session              = $sessionResult['data'] ?? [];
        $sessionPaymentStatus = strtolower((string) ($session['payment_status'] ?? ''));
        $sessionOrderId       = (string) ($session['metadata']['order_id'] ?? '');
        $paymentIntentId      = (string) ($session['payment_intent'] ?? '');
        // customer_details.email is the Stripe-captured buyer email — ownership proof
        $stripeEmail = strtolower(trim((string) ($session['customer_details']['email'] ?? $session['customer_email'] ?? '')));

        if ($sessionPaymentStatus !== 'paid') {
            $errorMsg  = 'Your payment has not been confirmed yet. Please wait a moment and refresh this page. If the problem persists, contact support.';
            $errorType = 'not_paid';
        } elseif ($sessionOrderId === '') {
            $errorMsg  = 'Payment was received but could not be linked to an order. Please contact support quoting your Stripe session ID.';
            $errorType = 'stripe_error';
        } else {
            // ── Order lookup with retry ──────────────────────────────────────
            // Retry up to 3 times with a short sleep in case the order write
            // hasn't reached Supabase yet (edge case under load).
            $order      = null;
            $maxRetries = 3;
            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                if ($attempt > 0) {
                    usleep(700000); // 0.7 s
                }
                $order = supabase_get_order($sessionOrderId);
                if ($order !== null) {
                    break;
                }
            }

            if ($order === null) {
                $errorMsg  = 'Your payment was received but we could not locate your order. Please check your order history — if the order is not there within a few minutes, contact support quoting: ' . h($sessionId);
                $errorType = 'not_found';
            } elseif ((string) ($order['stripe_checkout_session_id'] ?? '') !== ''
                && (string) ($order['stripe_checkout_session_id'] ?? '') !== $sessionId
            ) {
                $errorMsg  = 'Payment could not be matched to this order. Please contact support.';
                $errorType = 'email_mismatch';
            } elseif ($stripeEmail !== '' && strtolower((string) ($order['customer_email'] ?? '')) !== $stripeEmail) {
                $errorMsg  = 'Order ownership could not be verified. Please contact support.';
                $errorType = 'email_mismatch';
            } else {
                $orderId = $sessionOrderId;

                // Never show a success page until the paid status was persisted.
                $confirmation = stripe_confirm_order_payment($orderId, $paymentIntentId);
                $confirmedOrder = is_array($confirmation['order'] ?? null)
                    ? $confirmation['order']
                    : supabase_get_order($orderId);

                if (!($confirmation['ok'] ?? false)
                    || $confirmedOrder === null
                    || strtolower((string) ($confirmedOrder['payment_status'] ?? '')) !== 'paid'
                ) {
                    $errorMsg  = 'Your payment was received, but we could not finish saving your order. Please refresh this page or contact support.';
                    $errorType = 'stripe_error';
                } else {
                    cart_clear();

                    $confirmed = true;
                    $_SESSION['order_confirmed'] = [
                        'id'    => $orderId,
                        'total' => (string) ($confirmedOrder['total'] ?? $order['total'] ?? ''),
                        'name'  => (string) ($customer['name'] ?? ($confirmedOrder['customer_name'] ?? '')),
                    ];
                    redirect(resolve_link('/order-confirmed/'));
                    exit;
                }
            }
        }
    }
}

$pageTitle = 'Payment Status - ' . SITE_NAME;
$bodyClass = 'stripe-result-page';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="checkout-shell reveal-in">
  <div class="container" style="max-width:640px; text-align:center; padding:60px 20px;">

    <div style="font-size:3rem; margin-bottom:16px;">&#9888;</div>
    <h1 style="font-family:var(--serif); font-size:2rem; margin-bottom:12px;">
      <?= $errorType === 'no_session' ? 'Page Not Found' : 'Payment Not Confirmed' ?>
    </h1>
    <p style="color:#5c6360; font-size:1rem; line-height:1.7; margin-bottom:28px;">
      <?= h($errorMsg) ?>
    </p>

    <?php if ($errorType === 'not_paid'): ?>
      <p style="color:#5c6360; font-size:0.9rem; margin-bottom:28px;">
        Payments can occasionally take a few seconds to propagate. You can refresh this page to check again.
      </p>
      <a href="<?= h($_SERVER['REQUEST_URI']) ?>" class="store-btn-primary" style="display:inline-block; padding:14px 32px;">
        Refresh &amp; Check Again
      </a>
      <a href="<?= h(resolve_link('/account/')) ?>" class="store-btn-secondary" style="display:inline-block; padding:14px 32px; margin-left:12px;">
        My Account
      </a>
    <?php elseif ($errorType === 'no_session'): ?>
      <a href="<?= h(resolve_link('/account/')) ?>" class="store-btn-primary" style="display:inline-block; padding:14px 32px;">
        My Account
      </a>
      <a href="<?= h(resolve_link('/shop/')) ?>" class="store-btn-secondary" style="display:inline-block; padding:14px 32px; margin-left:12px;">
        Continue Shopping
      </a>
    <?php else: ?>
      <a href="<?= h(resolve_link('/cart/')) ?>" class="store-btn-primary" style="display:inline-block; padding:14px 32px;">
        Return to Cart
      </a>
      <a href="<?= h(resolve_link('/account/')) ?>" class="store-btn-secondary" style="display:inline-block; padding:14px 32px; margin-left:12px;">
        My Account
      </a>
    <?php endif; ?>

  </div>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
