<?php
/**
 * security.php
 * Sends HTTP security headers on every request.
 * Include this as the VERY FIRST file in index.php / any entry point.
 */
declare(strict_types=1);

// Prevent clickjacking
header('X-Frame-Options: SAMEORIGIN');

// Prevent MIME-type sniffing
header('X-Content-Type-Options: nosniff');

// XSS protection for older browsers
header('X-XSS-Protection: 1; mode=block');

// Control referrer info sent with requests
header('Referrer-Policy: strict-origin-when-cross-origin');

// Restrict browser features
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Content Security Policy
// Adjust as you add more external resources
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline'; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
    "img-src 'self' data: blob: https:; " .
    "media-src 'self' blob: https:; " .
    "connect-src 'self';"
);

// Start session securely (needed for CSRF tokens)
if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = session_save_path();
    if ($sessionPath === '' || !is_dir($sessionPath) || !is_writable($sessionPath)) {
        session_save_path(sys_get_temp_dir());
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Coming Soon / Password Protection
// Disabled on production. The password gate was a dev-only lockout during
// build-out. Set $coming_soon_enabled = true and change
// $coming_soon_password to put the storefront behind a password wall again.
$coming_soon_enabled = false;
$coming_soon_password = 'azuronntest';

if ($coming_soon_enabled && isset($_POST['coming_soon_password'])) {
    if ($_POST['coming_soon_password'] === $coming_soon_password) {
        $_SESSION['site_unlocked'] = true;
        // Redirect to same page to prevent form resubmission on refresh
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $coming_soon_error = "Incorrect password";
    }
}

if ($coming_soon_enabled && empty($_SESSION['site_unlocked'])) {
    // Show the coming soon page and exit
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Coming Soon - Azuronn</title>
        <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: var(--sans), sans-serif;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                background: radial-gradient(circle at center, #1a1a1a 0%, #000000 100%);
                color: #ffffff;
                position: relative;
                overflow: hidden;
            }
            /* Subtle background glow */
            body::before {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                width: 600px;
                height: 600px;
                background: radial-gradient(circle, rgba(201,169,110,0.05) 0%, rgba(0,0,0,0) 70%);
                transform: translate(-50%, -50%);
                z-index: 0;
            }
            .container {
                position: relative;
                z-index: 1;
                text-align: center;
                background: rgba(255, 255, 255, 0.03);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                padding: 4rem 3rem;
                border-radius: 2px;
                border: 1px solid rgba(255, 255, 255, 0.08);
                box-shadow: 0 30px 60px rgba(0, 0, 0, 0.5);
                max-width: 450px;
                width: 90%;
                animation: fadeIn 1s ease-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .logo {
                max-width: 220px;
                margin-bottom: 2rem;
                filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));
            }
            h1 { 
                font-family: var(--serif), serif;
                margin-top: 0; 
                font-size: 2.2rem; 
                font-weight: 400; 
                letter-spacing: 2px;
                color: #c9a96e;
                text-transform: uppercase;
                margin-bottom: 0.5rem;
            }
            p { 
                color: #a0a0a0; 
                margin-bottom: 2.5rem; 
                font-size: 0.95rem;
                letter-spacing: 0.5px;
                line-height: 1.6;
            }
            form { 
                display: flex; 
                flex-direction: column; 
                gap: 1.5rem; 
                align-items: center; 
                width: 100%;
            }
            .input-group {
                width: 100%;
                position: relative;
            }
            input[type="password"] {
                padding: 1rem;
                border: none;
                border-bottom: 1px solid rgba(255,255,255,0.2);
                background: transparent;
                color: white;
                width: 100%;
                font-size: 1rem;
                outline: none;
                transition: all 0.3s ease;
                text-align: center;
                letter-spacing: 2px;
            }
            input[type="password"]::placeholder {
                color: rgba(255,255,255,0.3);
                letter-spacing: 1px;
                font-weight: 300;
            }
            input[type="password"]:focus {
                border-bottom-color: #c9a96e;
                background: rgba(255,255,255,0.02);
            }
            button {
                padding: 1rem 2.5rem;
                border-radius: 0;
                border: 1px solid #c9a96e;
                background: transparent;
                color: #c9a96e;
                font-family: var(--sans), sans-serif;
                font-size: 0.9rem;
                letter-spacing: 2px;
                text-transform: uppercase;
                cursor: pointer;
                transition: all 0.4s ease;
                width: 100%;
            }
            button:hover { 
                background: #c9a96e; 
                color: #000;
                box-shadow: 0 0 15px rgba(201,169,110,0.4);
            }
            .error { 
                color: #ff6b6b; 
                margin-bottom: 1.5rem; 
                font-size: 0.85rem; 
                padding: 0.8rem; 
                border: 1px solid rgba(255,107,107,0.3);
                background: rgba(255,107,107,0.05);
                width: 100%;
                letter-spacing: 0.5px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <img src="/assets/final_logo.png" alt="Azuronn Logo" class="logo">
            <h1>Coming Soon</h1>
            <p>We are curating something extraordinary. Please enter your exclusive access code to proceed.</p>
            <?php if (isset($coming_soon_error)): ?>
                <div class="error"><?= htmlspecialchars($coming_soon_error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="input-group">
                    <input type="password" name="coming_soon_password" placeholder="ENTER PASSWORD" required>
                </div>
                <button type="submit">Unlock Access</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}
