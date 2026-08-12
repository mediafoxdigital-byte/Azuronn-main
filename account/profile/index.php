<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

require_customer_auth('/account/profile/');
$customer = current_customer();
if ($customer === null) {
    redirect(resolve_link('/account/login/'));
}

$pageFlash = site_flash_pull();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        site_flash_set('error', 'Session expired. Please try again.');
    } else {
        $result = customer_update_profile($_POST);
        site_flash_set(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Unable to update your profile.'));
    }
    redirect(resolve_link('/account/profile/'));
}

$customer = current_customer();
if ($customer === null) {
    redirect(resolve_link('/account/login/'));
}

$profileAddress = trim(implode(', ', array_filter([
    (string) ($customer['address_line_1'] ?? ''),
    (string) ($customer['address_line_2'] ?? ''),
    trim((string) (($customer['city'] ?? '') . (($customer['state'] ?? '') !== '' ? ', ' . $customer['state'] : ''))),
    (string) ($customer['postal_code'] ?? ''),
    (string) ($customer['country'] ?? ''),
])));
$orderMetrics = customer_order_metrics($customer);

$pageTitle = 'Profile Settings - ' . SITE_NAME;
$bodyClass = 'account-page account-profile-page';
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<section class="premium-account-wrapper reveal-in">
  <div class="container-fluid" style="padding: 0 4%;">
    <div class="premium-profile-hero">
      <div class="hero-text-col">
        <div class="hero-kicker">PROFILE SETTINGS</div>
        <div class="hero-heading">Keep your account details<br>precise and ready for checkout.</div>
        <p>Update your contact profile, preferred delivery address,<br>and password from one polished settings page.</p>
      </div>
      <div class="hero-actions-col-row">
        <a class="hero-btn-dark-outline" href="<?= h(resolve_link('/account/')) ?>">
          <i class="fas fa-arrow-left"></i> BACK TO ACCOUNT
        </a>
      </div>
    </div>

    <?php if ($pageFlash !== null): ?>
      <div class="store-flash <?= h($pageFlash['type']) ?>"><?= h($pageFlash['message']) ?></div>
    <?php endif; ?>

    <div class="premium-profile-main">
      <!-- Left Column -->
      <div class="premium-profile-col-wide">
        <div class="premium-account-panel">
          <div class="panel-header-simple">
            <span class="auth-kicker">PERSONAL DETAILS</span>
            <h2>Edit Profile</h2>
          </div>

          <form method="post" action="<?= h(resolve_link('/account/profile/')) ?>" class="premium-address-form profile-form">
            <?php csrf_field(); ?>

            <div class="form-grid-2">
              <label class="premium-field">
                <span>FULL NAME</span>
                <div class="input-icon-wrap">
                  <i class="far fa-user"></i>
                  <input type="text" name="name" required autocomplete="name" value="<?= h((string) ($customer['name'] ?? '')) ?>" placeholder="abdul">
                </div>
              </label>
              <label class="premium-field">
                <span>PHONE</span>
                <div class="input-icon-wrap">
                  <i class="fas fa-phone-alt"></i>
                  <input type="text" name="phone" required autocomplete="tel" value="<?= h((string) ($customer['phone'] ?? '')) ?>" placeholder="1234567890">
                </div>
              </label>
            </div>

            <label class="premium-field field-locked">
              <span>EMAIL ADDRESS</span>
              <div class="input-icon-wrap">
                <i class="far fa-envelope"></i>
                <input type="email" value="<?= h((string) ($customer['email'] ?? '')) ?>" readonly>
              </div>
              <small>Email stays fixed so your existing orders remain connected to this account.</small>
            </label>

            <label class="premium-field">
              <span>ADDRESS LINE 1</span>
              <div class="input-icon-wrap">
                <i class="fas fa-map-marker-alt"></i>
                <input type="text" name="address_line_1" autocomplete="address-line1" value="<?= h((string) ($customer['address_line_1'] ?? '')) ?>" placeholder="House number and street">
              </div>
            </label>

            <label class="premium-field">
              <span>ADDRESS LINE 2</span>
              <div class="input-icon-wrap">
                <i class="fas fa-map-marker-alt"></i>
                <input type="text" name="address_line_2" autocomplete="address-line2" value="<?= h((string) ($customer['address_line_2'] ?? '')) ?>" placeholder="Flat, building, locality (optional)">
              </div>
            </label>

            <div class="form-grid-2">
              <label class="premium-field">
                <span>TOWN / CITY</span>
                <div class="input-icon-wrap">
                  <i class="far fa-building"></i>
                  <input type="text" name="city" autocomplete="address-level2" value="<?= h((string) ($customer['city'] ?? '')) ?>" placeholder="London">
                </div>
              </label>
              <label class="premium-field">
                <span>COUNTY</span>
                <div class="input-icon-wrap">
                  <i class="far fa-map"></i>
                  <input type="text" name="state" autocomplete="address-level1" value="<?= h((string) ($customer['state'] ?? '')) ?>" placeholder="Greater London (optional)">
                </div>
              </label>
            </div>

            <div class="form-grid-2">
              <label class="premium-field">
                <span>POSTCODE</span>
                <div class="input-icon-wrap">
                  <i class="fas fa-map-pin"></i>
                  <input type="text" name="postal_code" autocomplete="postal-code" value="<?= h((string) ($customer['postal_code'] ?? '')) ?>" placeholder="SW1A 1AA" maxlength="8" pattern="<?= h(uk_postcode_html_pattern()) ?>" title="Enter a valid UK postcode, for example SW1A 1AA">
                </div>
              </label>
              <label class="premium-field">
                <span>COUNTRY</span>
                <div class="input-icon-wrap">
                  <i class="fas fa-globe"></i>
                  <input type="text" value="<?= h(uk_country_name()) ?>" readonly disabled>
                </div>
              </label>
            </div>

            <div class="panel-header-simple mt-4">
              <span class="auth-kicker">SECURITY</span>
              <h2>Change Password</h2>
              <p class="section-desc">Leave these fields empty if you only want to update your profile details.</p>
            </div>

            <div class="form-grid-2">
              <label class="premium-field">
                <span>CURRENT PASSWORD</span>
                <div class="input-icon-wrap">
                  <i class="fas fa-lock"></i>
                  <input type="password" name="current_password" autocomplete="current-password" placeholder="Enter current password">
                </div>
              </label>
              <label class="premium-field">
                <span>NEW PASSWORD</span>
                <div class="input-icon-wrap">
                  <i class="fas fa-lock"></i>
                  <input type="password" name="new_password" autocomplete="new-password" placeholder="Minimum 8 characters">
                </div>
              </label>
            </div>

            <label class="premium-field">
              <span>CONFIRM NEW PASSWORD</span>
              <div class="input-icon-wrap">
                <i class="fas fa-lock"></i>
                <input type="password" name="confirm_password" autocomplete="new-password" placeholder="Repeat the new password">
              </div>
            </label>

            <button type="submit" class="btn-full-dark"><i class="fas fa-lock"></i> UPDATE PROFILE</button>
          </form>
        </div>
      </div>

      <!-- Right Column -->
      <div class="premium-profile-col-narrow">
        <div class="premium-account-panel panel-watermark-bottom">
          <div class="panel-header-simple">
            <span class="auth-kicker">ACCOUNT SNAPSHOT</span>
            <h2><?= h($customer['name'] ?? 'abdul') ?></h2>
          </div>
          <div class="snapshot-grid compact-snapshot">
            <div class="snapshot-item">
              <i class="fas fa-shopping-bag"></i>
              <strong><?= h((string) $orderMetrics['total_orders']) ?></strong>
              <span>Orders</span>
            </div>
            <div class="snapshot-item">
              <i class="fas fa-map-marker-alt"></i>
              <strong><?= h((string) count(customer_saved_addresses($customer))) ?></strong>
              <span>Saved Address</span>
            </div>
            <div class="snapshot-item">
              <i class="far fa-heart"></i>
              <strong><?= h((string) count(customer_wishlist_products($customer))) ?></strong>
              <span>Wishlist</span>
            </div>
          </div>
          <div class="snapshot-simple-details">
            <div class="simple-detail">
              <span>PRIMARY EMAIL</span>
              <strong><?= h((string) ($customer['email'] ?? '')) ?></strong>
            </div>
            <div class="simple-detail">
              <span>LAST ORDER</span>
              <strong><?= h((string) ($orderMetrics['last_order_at'] !== '' ? $orderMetrics['last_order_at'] : 'No orders yet')) ?></strong>
            </div>
            <div class="simple-detail">
              <span>SAVED ADDRESS</span>
              <strong><?= $profileAddress !== '' ? h($profileAddress) : 'No primary address saved yet' ?></strong>
            </div>
          </div>
        </div>

        <div class="premium-account-panel">
          <div class="shield-icon-circle">
            <i class="far fa-check-circle"></i>
          </div>
          <div class="panel-header-simple">
            <span class="auth-kicker">WHY EMAIL IS LOCKED</span>
            <h2>Order history stays attached to this identity.</h2>
          </div>
          <p class="panel-text">Your existing orders are matched against the email stored on the account. Keeping that field locked prevents accidental loss of order history from the dashboard.</p>
          <a href="<?= h(resolve_link('/account/')) ?>" class="footer-link bold-link mt-4">Return to order history <i class="fas fa-arrow-right"></i></a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
