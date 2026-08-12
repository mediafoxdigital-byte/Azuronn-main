<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

$returnTo = safe_internal_path((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? '/shop/'), '/shop/');
$productId = clean_string($_POST['product_id'] ?? $_GET['product_id'] ?? '', 80);

if ($productId === '') {
    site_flash_set('error', 'Product was not found.');
    redirect(resolve_link($returnTo));
}

if (!customer_is_logged_in()) {
    site_flash_set('error', 'Sign in to use your wishlist.');
    redirect(resolve_link('/account/login/?next=' . urlencode($returnTo)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    site_flash_set('error', 'Session expired. Please try again.');
    redirect(resolve_link($returnTo));
}

$result = customer_toggle_wishlist($productId);
site_flash_set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Unable to update wishlist.'));
redirect(resolve_link($returnTo));
