<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

if (customer_is_logged_in()) {
    redirect(resolve_link('/account/'));
}

$next = safe_internal_path((string) ($_GET['next'] ?? '/account/'), '/account/');
$pageFlash = site_flash_pull();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $pageFlash = ['type' => 'error', 'message' => 'Session expired. Please try again.'];
    } else {
        $next = safe_internal_path((string) ($_POST['next'] ?? '/account/'), '/account/');
        $result = customer_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($result['ok'] ?? false) {
            site_flash_set('success', 'Welcome back. You are now signed in.');
            redirect(resolve_link($next));
        }
        $pageFlash = ['type' => 'error', 'message' => (string) ($result['message'] ?? 'Unable to sign in.')];
    }
}

$pageTitle = 'Sign In - ' . SITE_NAME;
$bodyClass = 'auth-page';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<section class="premium-auth-section reveal-in">
  <div class="premium-auth-layout container">
    
    <!-- LEFT SIDE BRANDING -->
    <div class="premium-auth-brand" style="background-image: url('<?= h(asset_url('assets/uploads/premium_auth_bg.png')) ?>');">
      <div class="premium-auth-brand-content">
        <div class="premium-auth-logo">
          <i class="far fa-gem"></i><br>
          AZURONN
        </div>
        <div class="premium-auth-subtitle">
          <span>Fine Jewellery</span>
        </div>
        
        <div class="auth-ornament-header">
          <i class="fas fa-gem"></i>
        </div>
        <h1>Timeless Elegance,<br>Crafted for You</h1>
        <p>Sign in to your account and explore our<br>exclusive collections, offers, and more.</p>
        
        <div class="premium-auth-features">
          <div class="premium-feature">
            <i class="fas fa-shield-alt"></i>
            <span>Secure &<br>Trusted</span>
          </div>
          <div class="premium-feature">
            <i class="far fa-gem"></i>
            <span>Premium<br>Quality</span>
          </div>
          <div class="premium-feature">
            <i class="fas fa-gift"></i>
            <span>Exclusive<br>Benefits</span>
          </div>
          <div class="premium-feature">
            <i class="fas fa-headset"></i>
            <span>24/7<br>Support</span>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT SIDE FORMS -->
    <div class="premium-auth-form-side">
      <div class="premium-auth-box">
        
        <!-- TABS -->
        <div class="premium-auth-tabs">
          <button class="premium-auth-tab active" data-target="#loginView">Login</button>
          <button class="premium-auth-tab" data-target="#registerView">Create Account</button>
          <div class="premium-auth-tab-indicator"></div>
        </div>

        <div class="premium-auth-forms-container">
          
          <!-- LOGIN VIEW -->
          <div id="loginView" class="premium-auth-view active">
            <div class="premium-auth-header">
              <div class="ornament">━━ <i class="fas fa-gem" style="font-size:8px;"></i> ━━</div>
              <h2>Welcome Back</h2>
              <p>Sign in to continue to your account</p>
            </div>

            <?php if ($pageFlash !== null): ?>
              <div class="store-flash <?= h($pageFlash['type']) ?>"><?= h($pageFlash['message']) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= h(resolve_link('/account/login/')) ?>" class="premium-form">
              <?php csrf_field(); ?>
              <input type="hidden" name="next" value="<?= h($next) ?>">

              <div class="premium-field">
                <label>Email Address</label>
                <div class="premium-input-wrap">
                  <i class="far fa-user"></i>
                  <input type="email" name="email" required autocomplete="email" placeholder="Enter your email address">
                </div>
              </div>

              <div class="premium-field">
                <label>Password</label>
                <div class="premium-input-wrap">
                  <i class="fas fa-lock"></i>
                  <input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                  <button type="button" class="toggle-password" onclick="togglePwd(this)"><i class="far fa-eye"></i></button>
                </div>
              </div>

              <div class="premium-form-actions">
                <label class="premium-checkbox">
                  <input type="checkbox" name="remember" checked>
                  Remember Me
                </label>
                <a href="#" class="premium-forgot">Forgot Password?</a>
              </div>

              <button type="submit" class="premium-btn-submit">SIGN IN <i class="fas fa-arrow-right"></i></button>
              
              <div class="premium-form-switch">
                Don't have an account? <a href="javascript:void(0)" onclick="switchAuthTab('#registerView')">Create Account <i class="fas fa-arrow-right"></i></a>
              </div>
            </form>
          </div>

          <!-- REGISTER VIEW -->
          <div id="registerView" class="premium-auth-view" style="display: none;">
            <div class="premium-auth-header">
              <div class="ornament">━━ <i class="fas fa-gem" style="font-size:8px;"></i> ━━</div>
              <h2>Create Account</h2>
              <p>Join Azuronn for a premium experience</p>
            </div>

            <form method="post" action="<?= h(resolve_link('/account/register/')) ?>" class="premium-form">
              <?php csrf_field(); ?>
              <input type="hidden" name="next" value="<?= h($next) ?>">

              <div class="premium-field-row">
                <div class="premium-field">
                  <label>Full Name</label>
                  <div class="premium-input-wrap">
                    <i class="far fa-id-badge"></i>
                    <input type="text" name="name" required autocomplete="name" placeholder="Enter full name">
                  </div>
                </div>
                <div class="premium-field">
                  <label>Phone</label>
                  <div class="premium-input-wrap">
                    <i class="fas fa-phone"></i>
                    <input type="text" name="phone" required autocomplete="tel" placeholder="Enter phone">
                  </div>
                </div>
              </div>

              <div class="premium-field-row">
                <div class="premium-field">
                  <label>Email Address</label>
                  <div class="premium-input-wrap">
                    <i class="far fa-envelope"></i>
                    <input type="email" name="email" required autocomplete="email" placeholder="Enter email address">
                  </div>
                </div>
                <div class="premium-field">
                  <label>City</label>
                  <div class="premium-input-wrap">
                    <i class="fas fa-map-marker-alt"></i>
                    <input type="text" name="city" required placeholder="Enter city">
                  </div>
                </div>
              </div>

              <div class="premium-field-row">
                <div class="premium-field">
                  <label>Password</label>
                  <div class="premium-input-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" required autocomplete="new-password" placeholder="Create password">
                  </div>
                </div>
                <div class="premium-field">
                  <label>Confirm</label>
                  <div class="premium-input-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="Confirm">
                  </div>
                </div>
              </div>
              
              

              <button type="submit" class="premium-btn-submit">CREATE ACCOUNT <i class="fas fa-arrow-right"></i></button>
              
              <div class="premium-form-switch">
                Already have an account? <a href="javascript:void(0)" onclick="switchAuthTab('#loginView')">Sign In <i class="fas fa-arrow-right"></i></a>
              </div>
            </form>
          </div>

        </div>
      </div>
    </div>

  </div>
</section>

<script>
function switchAuthTab(targetId) {
  document.querySelectorAll('.premium-auth-view').forEach(view => {
    view.style.display = 'none';
    view.classList.remove('active');
  });
  const target = document.querySelector(targetId);
  if(target) {
    target.style.display = 'block';
    // Small delay to allow display block to render before adding active class for animation
    setTimeout(() => target.classList.add('active'), 10);
  }
  
  document.querySelectorAll('.premium-auth-tab').forEach(tab => {
    tab.classList.remove('active');
    if(tab.getAttribute('data-target') === targetId) {
      tab.classList.add('active');
      const indicator = document.querySelector('.premium-auth-tab-indicator');
      if (targetId === '#loginView') {
        indicator.style.transform = 'translateX(0)';
      } else {
        indicator.style.transform = 'translateX(100%)';
      }
    }
  });
}

document.querySelectorAll('.premium-auth-tab').forEach(tab => {
  tab.addEventListener('click', function() {
    switchAuthTab(this.getAttribute('data-target'));
  });
});

function togglePwd(btn) {
  const input = btn.previousElementSibling;
  const icon = btn.querySelector('i');
  if(input.type === 'password') {
    input.type = 'text';
    icon.classList.remove('fa-eye');
    icon.classList.add('fa-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.remove('fa-eye-slash');
    icon.classList.add('fa-eye');
  }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
