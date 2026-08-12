<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Style and shape collections are served by the shop listing, which reads the
// merchant's real attribute profiles. This route only forwards the old URLs.
$type = sanitize_text((string) ($_GET['type'] ?? ''));
$slug = sanitize_text((string) ($_GET['slug'] ?? ''));

if (!in_array($type, ['shape', 'style'], true) || $slug === '') {
    redirect(resolve_link('/shop/'));
}

redirect(resolve_link('/shop/?' . http_build_query([
    'type' => 'Ring',
    'ring_category' => 'engagement',
    $type => $slug,
])));
