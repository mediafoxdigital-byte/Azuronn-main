<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

customer_logout();
site_flash_set('success', 'You have been signed out.');
redirect(resolve_link('/'));
