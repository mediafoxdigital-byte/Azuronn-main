<?php
/**
 * index.php
 * Main entry point for the homepage.
 */

// 1. Include security checks and headers first
require_once __DIR__ . '/includes/security.php';

// 2. Include core configuration and utilities
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'newsletter-subscribe') {
    if (!csrf_verify()) {
        site_flash_set('error', 'Session expired. Please try again.');
        redirect(resolve_link('/#our-newsletter'));
    }

    $result = newsletter_subscribe((string) ($_POST['email'] ?? ''), current_customer());
    site_flash_set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Unable to save your subscription.'));
    redirect(resolve_link('/#our-newsletter'));
}

// 3. Render Header
require_once __DIR__ . '/includes/header.php';

// 4. Render Page Sections
require_once __DIR__ . '/includes/partials/hero.php';
require_once __DIR__ . '/includes/partials/journey-steps.php';
require_once __DIR__ . '/includes/partials/cat-strip.php';
require_once __DIR__ . '/includes/partials/shop-by-style.php';
require_once __DIR__ . '/includes/partials/diamond-shapes.php';
require_once __DIR__ . '/includes/partials/dual-cta.php';
require_once __DIR__ . '/includes/partials/products.php';
// Trending CTA section disabled
// require_once __DIR__ . '/includes/partials/trending.php';
require_once __DIR__ . '/includes/partials/bestselling.php';
// Celebs section intentionally disabled for now on the new website.
// Keep the partial in place so it can be restored later without rebuilding it.
// require_once __DIR__ . '/includes/partials/celebs.php';
require_once __DIR__ . '/includes/partials/reviews.php';
require_once __DIR__ . '/includes/partials/faq.php';
require_once __DIR__ . '/includes/partials/news.php';
require_once __DIR__ . '/includes/partials/discover-service.php';
require_once __DIR__ . '/includes/partials/newsletter.php';

require_once __DIR__ . '/includes/partials/social-gallery.php';

// 5. Render Footer
require_once __DIR__ . '/includes/footer.php';
