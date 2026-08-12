<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'Cookie Policy | ' . SITE_NAME;
$bodyClass = 'legal-page';
$sessionCookieName = session_name();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="legal-shell">
  <section class="legal-hero">
    <p class="legal-kicker">Cookie Policy</p>
    <h1>Cookie Policy</h1>
    <p class="legal-intro">
      This policy explains how Azuronn uses cookies and similar technologies on the public storefront and the
      private admin workspace. It reflects the current website implementation as of 23 May 2026 and is intended
      to support compliance with applicable privacy and electronic communications laws.
    </p>
    <div class="legal-meta">
      <span><i class="far fa-calendar-alt" aria-hidden="true"></i> Last updated: 23 May 2026</span>
      <span><i class="fas fa-shield-halved" aria-hidden="true"></i> Non-essential cookies are off by default</span>
      <button type="button" class="cookie-btn cookie-btn-secondary" data-cookie-preferences-trigger>Change Cookie Choices</button>
    </div>
  </section>

  <div class="legal-grid">
    <section class="legal-card">
      <h2>How we use cookies</h2>
      <p>
        We use cookies and similar technologies to keep the website secure, maintain your session, remember your
        basket when you actively use shopping features, and store your cookie choice. We do not currently enable
        analytics, advertising, or social media tracking cookies on the public storefront unless you choose to allow
        them and we deploy them in a later update.
      </p>
      <p>
        If we introduce a new non-essential cookie or change the purpose for which optional technologies are used,
        we will ask for fresh consent before enabling them.
      </p>
    </section>

    <section class="legal-card">
      <h2>Cookies and Storage We Use</h2>
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Where</th>
            <th>Purpose</th>
            <th>Duration</th>
            <th>Type</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong><?= h($sessionCookieName) ?></strong></td>
            <td>Public storefront and account areas</td>
            <td>Maintains your secure browsing session, CSRF protection, sign-in state, and server-side cart/session data.</td>
            <td>Session only</td>
            <td>Strictly necessary</td>
          </tr>
          <tr>
            <td><strong>azuronn_cart</strong></td>
            <td>Public storefront</td>
            <td>Stores a persistent basket session key after you actively use the basket or checkout so your cart can be restored between visits.</td>
            <td>12 months from last update</td>
            <td>Strictly necessary</td>
          </tr>
          <tr>
            <td><strong>azuronn_cookie_consent</strong></td>
            <td>Public storefront</td>
            <td>Remembers whether you accepted or rejected non-essential cookies and stores your current consent choices.</td>
            <td>6 months</td>
            <td>Strictly necessary</td>
          </tr>
          <tr>
            <td><strong>azuronn-admin-attribute-profile:*<br>azuronn-admin-attribute-product:*</strong></td>
            <td>Private admin workspace only</td>
            <td>Local browser storage used to preserve unsaved admin editing drafts in the authenticated admin environment.</td>
            <td>Until cleared, replaced, or the draft is submitted</td>
            <td>Strictly necessary for the requested admin editing service</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="legal-card">
      <h2>Your choices</h2>
      <p>
        When you first visit the storefront, we present a cookie banner with equally visible options to accept all
        non-essential cookies, reject non-essential cookies, or choose settings. Unless you give a positive opt-in,
        non-essential categories remain off.
      </p>
      <ul>
        <li>Strictly necessary cookies remain active because the website cannot operate securely without them.</li>
        <li>Rejecting non-essential cookies does not block you from browsing, using your account, or placing orders.</li>
        <li>You can reopen cookie settings at any time using the Cookie Settings button in the footer or floating launcher.</li>
        <li>If you withdraw consent, we stop using optional categories and remove optional first-party cookies we can control.</li>
      </ul>
    </section>

    <section class="legal-card">
      <h2>Optional categories on this website</h2>
      <h3>Analytics</h3>
      <p>
        Analytics cookies are currently held off on this storefront. If we introduce analytics in future, they will
        stay disabled unless you opt in.
      </p>
      <h3>Marketing</h3>
      <p>
        Marketing and advertising cookies are currently held off on this storefront. If we introduce remarketing,
        advertising measurement, or social media tracking in future, they will remain disabled unless you opt in.
      </p>
    </section>

    <section class="legal-card">
      <h2>Third-party services and embedded resources</h2>
      <p>
        Parts of the website may load external style, font, or media resources. These connections can involve
        standard technical request data such as your IP address or browser details, but they are separate from the
        non-essential cookie categories described above. This cookie policy is focused on cookies and similar client
        storage technologies used by the Azuronn website.
      </p>
    </section>

    <section class="legal-card">
      <h2>Contact</h2>
      <p>
        If you have questions about this policy or how Azuronn uses cookies and similar technologies, contact us at
        <a href="mailto:<?= h(STORE_EMAIL) ?>"><?= h(STORE_EMAIL) ?></a>.
      </p>
    </section>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
