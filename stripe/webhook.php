<?php

declare(strict_types=1);

/**
 * Stripe Webhook Endpoint
 *
 * This endpoint does NOT include security.php — it must be reachable by
 * Stripe's servers without a session cookie or Coming-Soon gate.
 *
 * Configure in Stripe Dashboard → Webhooks:
 *   URL: https://yoursite.com/stripe/webhook.php
 *   Events:
 *     - checkout.session.completed
 *     - checkout.session.expired
 *     - payment_intent.payment_failed
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/storefront.php';
require_once dirname(__DIR__) . '/includes/stripe.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload   = (string) file_get_contents('php://input');
$sigHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if ($payload === '' || $sigHeader === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payload or signature']);
    exit;
}

$event = stripe_construct_webhook_event($payload, $sigHeader);
if ($event === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$eventType = (string) ($event['type'] ?? '');

// ── checkout.session.completed ────────────────────────────────────────────────
// Primary happy-path: payment confirmed by Stripe. Mark order paid/processing.
if ($eventType === 'checkout.session.completed') {
    $session         = $event['data']['object'] ?? [];
    $orderId         = (string) ($session['metadata']['order_id'] ?? '');
    $paymentIntentId = (string) ($session['payment_intent'] ?? '');
    $paymentStatus   = strtolower((string) ($session['payment_status'] ?? ''));

    if ($orderId !== '' && $paymentStatus === 'paid') {
        $confirmation = stripe_confirm_order_payment($orderId, $paymentIntentId);
        if (!($confirmation['ok'] ?? false)) {
            http_response_code(500);
            echo json_encode(['error' => 'Paid order could not be saved']);
            exit;
        }
    }
}

// ── checkout.session.expired ──────────────────────────────────────────────────
// The hosted page timed out (24 h default) without a payment. Mark the order
// cancelled so the customer's account doesn't show a stuck 'awaiting' order.
if ($eventType === 'checkout.session.expired') {
    $session = $event['data']['object'] ?? [];
    $orderId = (string) ($session['metadata']['order_id'] ?? '');

    if ($orderId !== '') {
        $order = supabase_get_order($orderId);
        if ($order === null) {
            http_response_code(500);
            echo json_encode(['error' => 'Expired order was not found']);
            exit;
        }

        if (strtolower((string) ($order['payment_status'] ?? '')) === 'awaiting'
            && !checkout_abandon_order($orderId, (string) ($order['customer_email'] ?? ''))
        ) {
            http_response_code(500);
            echo json_encode(['error' => 'Expired order could not be cancelled']);
            exit;
        }
    }
}

// ── payment_intent.payment_failed ─────────────────────────────────────────────
// Card declined or other hard failure. The Stripe Checkout page will show the
// customer the error and let them retry, so we only log this on the order record
// without touching the status (the session may still succeed on a retry).
if ($eventType === 'payment_intent.payment_failed') {
    $paymentIntent   = $event['data']['object'] ?? [];
    $paymentIntentId = (string) ($paymentIntent['id'] ?? '');

    // Find order by payment intent ID (if already tagged) or by matching the
    // checkout session's metadata — nothing to update status-wise, just note it.
    if ($paymentIntentId !== '') {
        $content = site_content();
        foreach ($content['orders']['items'] ?? [] as $order) {
            if ((string) ($order['stripe_payment_intent_id'] ?? '') === $paymentIntentId) {
                // Order already linked — nothing to change; Stripe will retry.
                break;
            }
        }
    }
}

// Always return 200 so Stripe doesn't retry unnecessarily.
http_response_code(200);
echo json_encode(['received' => true]);
