<?php
/**
 * config.php
 * Site-wide configuration constants.
 */
declare(strict_types=1);

require_once __DIR__ . '/content.php';

$siteContent = site_content();
$settings = $siteContent['settings'];

// Site identity
define('SITE_NAME', $settings['site_name']);
define('SITE_TAGLINE', $settings['site_tagline']);
// Auto-detect real host when site_url is localhost or empty (dev config on live server)
$_azSiteUrl = rtrim((string) ($settings['site_url'] ?? ''), '/');
if ($_azSiteUrl === ''
    || str_contains($_azSiteUrl, 'localhost')
    || str_contains($_azSiteUrl, '127.0.0.1')
) {
    $_azProto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_azHost    = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($_azHost !== '') {
        $_azSiteUrl = $_azProto . '://' . $_azHost;
    }
}
define('SITE_URL', $_azSiteUrl);
unset($_azSiteUrl, $_azProto, $_azHost);

// Contact
define('STORE_ADDRESS', $settings['store_address']);
define('STORE_PHONE', $settings['store_phone']);
define('STORE_EMAIL', $settings['store_email']);

// Social links
define('SOCIAL_FACEBOOK', $settings['social']['facebook']);
define('SOCIAL_TWITTER', $settings['social']['twitter']);
define('SOCIAL_RSS', $settings['social']['rss']);
define('SOCIAL_GOOGLEPLUS', $settings['social']['googleplus']);
define('SOCIAL_YOUTUBE', $settings['social']['youtube']);

// Announcement bar
define('ANN_TEXT', $settings['announcement_text']);
define('ANN_CODE', $settings['announcement_code']);
define('ANN_CODE_URL', $settings['announcement_url']);

// Cart placeholder
define('CART_COUNT', $settings['cart_count']);
define('CART_TOTAL', $settings['cart_total']);

// Paths
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', SITE_URL);
define('SITE_LOGO_PATH', $settings['logo_path']);
define('SUPABASE_URL', supabase_project_url());
define('SUPABASE_PUBLISHABLE_KEY', supabase_publishable_key());
define('SUPABASE_ENABLED', supabase_enabled());
// Keep new uploads outside the deployed checkout by default. Git-based deploys
// can replace everything under BASE_PATH, while a sibling directory survives
// those deploys. An explicit runtime/env path remains authoritative for hosts
// that already provide persistent storage elsewhere.
$configuredUploadsRoot = app_runtime_config_value('uploads_root_path') ?: getenv('AZURONN_UPLOADS_ROOT');
if ($configuredUploadsRoot === false || trim((string) $configuredUploadsRoot) === '') {
    $persistentUploadsRoot = dirname(BASE_PATH) . DIRECTORY_SEPARATOR . 'azuronn-media';
    $persistentParent = dirname($persistentUploadsRoot);
    $persistentWritable = is_dir($persistentUploadsRoot)
        ? is_writable($persistentUploadsRoot)
        : (is_dir($persistentParent) && is_writable($persistentParent));
    $configuredUploadsRoot = $persistentWritable
        ? $persistentUploadsRoot
        : BASE_PATH . '/assets/uploads/admin';
}
define('UPLOADS_ROOT_PATH', (string) $configuredUploadsRoot);
define('UPLOADS_PUBLIC_BASE_URL', app_runtime_config_value('uploads_public_base_url') ?: (getenv('AZURONN_UPLOADS_PUBLIC_BASE_URL') ?: '/assets/uploads/admin'));

// Admin auth
// ADMIN_USERNAME / ADMIN_PASSWORD_HASH / EMPLOYEE_ADMIN_USERNAME /
// EMPLOYEE_ADMIN_PASSWORD_HASH are kept as constants for legacy callers,
// but their values are no longer consulted at login time — login uses the
// admin_users table in Supabase only. Login will be refused if no matching
// row is found there.
$envAdminUser = getenv('AZURONN_ADMIN_USERNAME');
$envAdminHash = getenv('AZURONN_ADMIN_PASSWORD_HASH');
$envEmployeeAdminUser = getenv('AZURONN_EMPLOYEE_ADMIN_USERNAME');
$envEmployeeAdminHash = getenv('AZURONN_EMPLOYEE_ADMIN_PASSWORD_HASH');

define('ADMIN_USERNAME', $envAdminUser !== false && $envAdminUser !== '' ? $envAdminUser : 'admin');
define('ADMIN_PASSWORD_HASH', $envAdminHash !== false && $envAdminHash !== '' ? $envAdminHash : '');
define('EMPLOYEE_ADMIN_USERNAME', $envEmployeeAdminUser !== false && $envEmployeeAdminUser !== '' ? $envEmployeeAdminUser : 'employee');
define('EMPLOYEE_ADMIN_PASSWORD_HASH', $envEmployeeAdminHash !== false && $envEmployeeAdminHash !== '' ? $envEmployeeAdminHash : '');
define('ADMIN_IDLE_TIMEOUT', 1800);
define('ADMIN_MAX_LOGIN_ATTEMPTS', 5);
define('ADMIN_LOCKOUT_MINUTES', 15);
