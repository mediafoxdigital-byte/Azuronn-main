<?php
// router.php - Local dev server router to simulate .htaccess rules
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

    // Legacy ring collection routes
    if (preg_match('#^/engagement-rings/shape/([a-zA-Z0-9-]+)/?$#', $path, $matches)) {
        $_GET['type'] = 'Ring';
        $_GET['ring_category'] = 'engagement';
        $_GET['shape'] = $matches[1];
        require __DIR__ . '/shop/index.php';
        return true;
    }

    if (preg_match('#^/engagement-rings/style/([a-zA-Z0-9-]+)/?$#', $path, $matches)) {
        $_GET['type'] = 'Ring';
        $_GET['ring_category'] = 'engagement';
        $_GET['style'] = $matches[1];
        require __DIR__ . '/shop/index.php';
        return true;
    }

    // Ring section routes
    if (preg_match('#^/engagement-rings/?$#', $path)) {
        $_GET['type'] = 'Ring';
        $_GET['ring_category'] = 'engagement';
        require __DIR__ . '/shop/index.php';
        return true;
    }

    if (preg_match('#^/wedding-rings/mens/?$#', $path)) {
        $_GET['type'] = 'Ring';
        $_GET['ring_category'] = 'wedding';
        $_GET['gender'] = 'mens';
        require __DIR__ . '/shop/index.php';
        return true;
    }

    if (preg_match('#^/wedding-rings/womens/?$#', $path)) {
        $_GET['type'] = 'Ring';
        $_GET['ring_category'] = 'wedding';
        $_GET['gender'] = 'womens';
        require __DIR__ . '/shop/index.php';
        return true;
    }

    if (preg_match('#^/wedding-rings/style/([a-zA-Z0-9-]+)/?$#', $path, $matches)) {
        $_GET['type'] = 'Ring';
        $_GET['ring_category'] = 'wedding';
        $_GET['style'] = $matches[1];
        require __DIR__ . '/shop/index.php';
        return true;
    }

    if (preg_match('#^/wedding-rings/?$#', $path)) {
        $_GET['type'] = 'Ring';
        $_GET['ring_category'] = 'wedding';
        require __DIR__ . '/shop/index.php';
        return true;
    }

    // Uploaded media is stored outside the deployed checkout by default.
    if (preg_match('#^/assets/uploads/admin/([A-Za-z0-9][A-Za-z0-9._-]*)$#', $path, $matches)) {
        $_GET['file'] = $matches[1];
        require __DIR__ . '/media.php';
        return true;
    }

    // Serve static files as-is
    if (is_file(__DIR__ . $path)) {
        return false;
    }
    
    // Serve directory indexes
    if (is_dir(__DIR__ . $path)) {
        $indexPath = rtrim(__DIR__ . $path, '/') . '/index.php';
        if (is_file($indexPath)) {
            require $indexPath;
            return true;
        }
    }
    
    return false;
}
