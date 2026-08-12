<?php

declare(strict_types=1);

/**
 * Stripe API helper — thin cURL wrapper (no SDK / no Composer).
 *
 * Secrets live in data/runtime-config.php (gitignored):
 *   stripe_secret_key, stripe_webhook_secret, stripe_publishable_key (optional)
 *
 * Only the redirect-based Checkout flow is used (no Stripe.js on our pages),
 * so no CSP changes are needed.
 */

require_once __DIR__ . '/config.php';

// ── Configuration ────────────────────────────────────────────────────────────

function stripe_configured(): bool
{
    return stripe_secret_key() !== '';
}

function stripe_secret_key(): string
{
    return trim((string) app_runtime_config_value('stripe_secret_key'));
}

function stripe_webhook_secret(): string
{
    return trim((string) app_runtime_config_value('stripe_webhook_secret'));
}

function stripe_publishable_key(): string
{
    return trim((string) app_runtime_config_value('stripe_publishable_key'));
}

// ── cURL wrapper ─────────────────────────────────────────────────────────────

/**
 * @return array{ok: bool, data?: array, error?: string, http_code?: int}
 */
function stripe_api_request(string $method, string $path, array $params = []): array
{
    $url = 'https://api.stripe.com/v1' . $path;
    $headers = [
        'Authorization: Bearer ' . stripe_secret_key(),
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($method === 'GET') {
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
    } else {
        // POST — form-encoded body (Stripe's API expects this)
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'cURL error: ' . $curlError, 'http_code' => 0];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from Stripe', 'http_code' => $httpCode];
    }

    if ($httpCode >= 400) {
        $msg = (string) ($decoded['error']['message'] ?? 'Stripe API error');
        return ['ok' => false, 'error' => $msg, 'http_code' => $httpCode, 'data' => $decoded];
    }

    return ['ok' => true, 'data' => $decoded, 'http_code' => $httpCode];
}

// ── Checkout Session ─────────────────────────────────────────────────────────

/**
 * Create a Stripe Checkout Session for the given order.
 *
 * @param array  $order      The order array (must contain 'id', 'items', 'total' etc.)
 * @param float  $totalFloat The numeric total in major units (e.g. 81.99)
 * @param string $customerEmail
 * @param string $orderId    Our internal order ID (stored in metadata)
 * @return array{ok: bool, url?: string, session_id?: string, error?: string}
 */
function stripe_create_checkout_session(
    array $orderItems,
    float $totalFloat,
    string $customerEmail,
    string $orderId
): array {
    if (!stripe_configured()) {
        return ['ok' => false, 'error' => 'Stripe is not configured.'];
    }

    $totalPence = (int) round($totalFloat * 100);
    if ($totalPence <= 0) {
        return ['ok' => false, 'error' => 'Order total must be greater than zero.'];
    }

    // Build a single line item for the full order total (simplest approach that
    // keeps the Stripe page clean and avoids per-item rounding issues).
    $lineItemDescription = 'Azuronn Order ' . $orderId;
    if (count($orderItems) === 1) {
        $lineItemDescription = (string) ($orderItems[0]['product_name'] ?? $lineItemDescription);
    }

    $baseUrl = rtrim((string) SITE_URL, '/');
    $successUrl = $baseUrl . '/stripe/success.php?session_id={CHECKOUT_SESSION_ID}';
    $cancelToken = bin2hex(random_bytes(16));
    $cancelUrl   = $baseUrl . '/stripe/cancel.php?order_id=' . urlencode($orderId) . '&token=' . urlencode($cancelToken);

    $params = [
        'mode'                          => 'payment',
        'success_url'                   => $successUrl,
        'cancel_url'                    => $cancelUrl,
        'customer_email'                => $customerEmail !== '' ? $customerEmail : null,
        'metadata[order_id]'            => $orderId,
        'line_items[0][price_data][currency]'     => 'gbp',
        'line_items[0][price_data][unit_amount]'  => $totalPence,
        'line_items[0][price_data][product_data][name]'        => $lineItemDescription,
        'line_items[0][price_data][product_data][description]' => 'Jewellery order from ' . SITE_NAME,
        'line_items[0][quantity]'       => 1,
    ];

    // Remove null values (Stripe rejects them)
    $params = array_filter($params, static fn ($v) => $v !== null);

    $result = stripe_api_request('POST', '/checkout/sessions', $params);
    if (!($result['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Failed to create Stripe session.')];
    }

    $session = $result['data'] ?? [];
    $url = (string) ($session['url'] ?? '');
    $sessionId = (string) ($session['id'] ?? '');

    if ($url === '' || $sessionId === '') {
        return ['ok' => false, 'error' => 'Stripe returned a session without a URL.'];
    }

    return ['ok' => true, 'url' => $url, 'session_id' => $sessionId, 'cancel_token' => $cancelToken];
}

// ── Retrieve Session ─────────────────────────────────────────────────────────

/**
 * @return array{ok: bool, data?: array, error?: string}
 */
function stripe_retrieve_session(string $sessionId): array
{
    if ($sessionId === '' || !stripe_configured()) {
        return ['ok' => false, 'error' => 'Invalid session or Stripe not configured.'];
    }

    return stripe_api_request('GET', '/checkout/sessions/' . urlencode($sessionId));
}

// ── Webhook signature verification ───────────────────────────────────────────

/**
 * Verify the Stripe-Signature header and return the decoded event, or null.
 *
 * Implements the Stripe webhook signature scheme:
 *   t=<timestamp>,v1=<hex-hmac>
 * The signed payload is "<timestamp>.<raw_body>".
 * We use hash_equals for timing-safe comparison and a 5-minute tolerance.
 */
function stripe_construct_webhook_event(string $payload, string $sigHeader): ?array
{
    $secret = stripe_webhook_secret();
    if ($secret === '' || $sigHeader === '') {
        return null;
    }

    // Parse the signature header. Stripe may include MULTIPLE v1= entries (one
    // per signing secret during a key-rotation window), so we collect every v1
    // and accept the event if the computed HMAC matches ANY of them — matching
    // Stripe's official libraries. Storing them in a key=>value map would let a
    // later v1 overwrite the valid one and reject legitimate webhooks.
    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $sigHeader) as $item) {
        $kv = explode('=', $item, 2);
        if (count($kv) !== 2) {
            continue;
        }
        $key = trim($kv[0]);
        $val = trim($kv[1]);
        if ($key === 't') {
            $timestamp = $val;
        } elseif ($key === 'v1' && $val !== '') {
            $signatures[] = $val;
        }
    }

    if ($timestamp === '' || $signatures === []) {
        return null;
    }

    // Tolerance: 5 minutes
    $tolerance = 300;
    if (abs(time() - (int) $timestamp) > $tolerance) {
        return null;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expectedSig = hash_hmac('sha256', $signedPayload, $secret);

    $matched = false;
    foreach ($signatures as $signature) {
        if (hash_equals($expectedSig, $signature)) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        return null;
    }

    $event = json_decode($payload, true);
    return is_array($event) ? $event : null;
}

// ── Refunds ───────────────────────────────────────────────────────────────────

/**
 * Issue a (partial or full) refund against a Stripe PaymentIntent.
 *
 * @param string $paymentIntentId  pi_... from the order
 * @param int    $amountPence      Amount in pence to refund (0 = full refund)
 * @return array{ok: bool, refund_id?: string, error?: string}
 */
function stripe_create_refund(string $paymentIntentId, int $amountPence = 0): array
{
    if ($paymentIntentId === '' || !stripe_configured()) {
        return ['ok' => false, 'error' => 'Stripe is not configured or payment reference is missing.'];
    }

    $params = ['payment_intent' => $paymentIntentId];
    if ($amountPence > 0) {
        $params['amount'] = $amountPence;
    }

    $result = stripe_api_request('POST', '/refunds', $params);
    if (!($result['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Stripe refund failed.')];
    }

    $refundId = (string) ($result['data']['id'] ?? '');
    if ($refundId === '') {
        return ['ok' => false, 'error' => 'Stripe returned a refund without an ID.'];
    }

    return ['ok' => true, 'refund_id' => $refundId];
}

/**
 * Sum the express delivery surcharge across all line items in an order.
 * Returns 0.0 if no express items or surcharges are present.
 */
function order_express_fee_total(array $order): float
{
    $total = 0.0;
    foreach ((array) ($order['items'] ?? []) as $item) {
        if (strtolower(trim((string) ($item['delivery_option'] ?? ''))) !== 'express') {
            continue;
        }
        $surcharge = trim((string) ($item['delivery_surcharge'] ?? ''));
        if ($surcharge === '' || $surcharge === 'Free') {
            continue;
        }
        $total += money_value($surcharge);
    }
    return round($total, 2);
}

/**
 * Calculate how much to refund for a cancel or return request.
 *
 * Rules:
 *   - Cancel, not yet shipped (no tracking ID): full refund.
 *   - Cancel, already shipped (tracking ID present): total minus express fee.
 *   - Return (always post-delivery): total minus express fee.
 *
 * @return array{
 *   total: float,
 *   express_fee: float,
 *   refund_amount: float,
 *   refund_pence: int,
 *   deducted_express: bool,
 *   total_label: string,
 *   express_fee_label: string,
 *   refund_amount_label: string,
 * }
 */
function order_calculate_refund(array $order): array
{
    $total       = money_value((string) ($order['total'] ?? ''));
    $expressFee  = order_express_fee_total($order);
    $requestType = strtolower((string) ($order['customer_request_type'] ?? ''));
    $wasShipped  = trim((string) ($order['tracking_id'] ?? '')) !== '';

    // Deduct express fee if shipped (cancel) or always for returns
    $deductExpress = $expressFee > 0 && ($requestType === 'return' || $wasShipped);
    $refundAmount  = $deductExpress ? max(0.0, round($total - $expressFee, 2)) : $total;

    return [
        'total'               => $total,
        'express_fee'         => $expressFee,
        'refund_amount'       => $refundAmount,
        'refund_pence'        => (int) round($refundAmount * 100),
        'deducted_express'    => $deductExpress,
        'total_label'         => money_format($total),
        'express_fee_label'   => money_format($expressFee),
        'refund_amount_label' => money_format($refundAmount),
    ];
}

// ── Order payment confirmation (shared by webhook + success page) ─────────────

/**
 * Idempotently mark an order as paid/processing.
 * Returns ['ok' => true] if the order was flipped (or already paid),
 * ['ok' => false] if the order was not found.
 */
function stripe_confirm_order_payment(string $orderId, string $paymentIntentId = ''): array
{
    if ($orderId === '') {
        return ['ok' => false, 'message' => 'Missing order ID.'];
    }

    $result = with_order_update($orderId, static function (array $order) use ($paymentIntentId): ?array {
        $updates = [
            'payment_status' => 'paid',
            'status'         => 'processing',
        ];
        if ($paymentIntentId !== '') {
            $updates['payment_reference'] = $paymentIntentId;
            $updates['stripe_payment_intent_id'] = $paymentIntentId;
        }

        return $updates;
    });

    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $confirmedOrder = is_array($result['order'] ?? null)
        ? $result['order']
        : supabase_get_order($orderId);

    if ($confirmedOrder === null
        || strtolower((string) ($confirmedOrder['payment_status'] ?? '')) !== 'paid'
    ) {
        return ['ok' => false, 'message' => 'Paid order could not be verified after saving.'];
    }

    $result['stats_synced'] = customer_refresh_order_metrics($confirmedOrder);

    return $result;
}
