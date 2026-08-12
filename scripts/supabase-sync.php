#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * supabase-sync.php
 *
 * Push the public site_content blob into Supabase so a fresh install can come
 * up reading from the database. Private data (customers, orders, newsletter,
 * appointments, admin_users, admin_requests) lives in dedicated tables and is
 * seeded by scripts/supabase-private-migrate.php instead.
 */

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin-auth.php';

if (!supabase_enabled()) {
    fwrite(STDERR, "Supabase is not configured.\n");
    exit(1);
}

$defaults = default_site_content();
$localContent = local_site_content_candidate($defaults);
$normalizedContent = normalize_site_content($localContent);

$contentSaved = supabase_write_state('site_content', $normalizedContent);
$attemptsSaved = supabase_write_state('admin_login_attempts', admin_load_attempts());

fwrite(STDOUT, 'site_content: ' . ($contentSaved ? 'synced' : 'failed') . PHP_EOL);
fwrite(STDOUT, 'admin_login_attempts: ' . ($attemptsSaved ? 'synced' : 'failed') . PHP_EOL);

exit(($contentSaved && $attemptsSaved) ? 0 : 2);
