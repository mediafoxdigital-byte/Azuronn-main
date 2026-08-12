#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$result = supabase_health_check();

fwrite(STDOUT, ($result['message'] ?? 'Health check complete.') . PHP_EOL);

foreach (($result['checks'] ?? []) as $check) {
    $status = ($check['ok'] ?? false) ? 'OK' : 'FAIL';
    fwrite(STDOUT, sprintf("[%s] %s - %s\n", $status, (string) ($check['name'] ?? 'check'), (string) ($check['detail'] ?? '')));
}

exit(($result['ok'] ?? false) ? 0 : 2);
