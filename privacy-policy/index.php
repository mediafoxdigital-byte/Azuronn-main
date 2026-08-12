<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'Privacy Policy | ' . SITE_NAME;
$bodyClass = 'legal-page';
$company = site_company_details();
$returnDays = order_return_window_days();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="legal-shell">
  <section class="legal-hero">
    <p class="legal-kicker">Privacy</p>
    <h1>Privacy Policy</h1>
    <p class="legal-intro">
      This policy explains what personal data <?= h(SITE_NAME) ?> collects, why we collect it, how long we keep it, and
      the rights you have over it under the UK General Data Protection Regulation (UK GDPR) and the Data Protection
      Act 2018.
    </p>
    <div class="legal-meta">
      <span><i class="far fa-calendar-alt" aria-hidden="true"></i> Last updated: 7 August 2026</span>
      <span><i class="fas fa-shield-halved" aria-hidden="true"></i> We never sell your data</span>
      <span><i class="fas fa-lock" aria-hidden="true"></i> Card details never reach our servers</span>
    </div>
  </section>

  <div class="legal-grid">
    <?php require dirname(__DIR__) . '/includes/partials/legal-identity.php'; ?>

    <section class="legal-card">
      <h2>Data controller</h2>
      <p>
        <?= h($company['legal_name']) ?> is the data controller for the personal data described in this policy. That
        means we decide what data is collected and why. If you have any question about this policy, or want to exercise
        any of the rights set out below, email <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a>.
      </p>
    </section>

    <section class="legal-card">
      <h2>What we collect and why</h2>
      <p>
        We only collect what we need to run the shop. The table below lists every category of personal data this
        website holds, the lawful basis we rely on, and how long we keep it.
      </p>
      <table>
        <thead>
          <tr>
            <th>What we collect</th>
            <th>When</th>
            <th>Why</th>
            <th>Lawful basis</th>
            <th>Retention</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Name, email address, telephone number, town or city, and a securely hashed password</td>
            <td>When you create an account</td>
            <td>To identify you, let you sign in, and contact you about your account</td>
            <td>Performance of a contract</td>
            <td>Until you ask us to close your account</td>
          </tr>
          <tr>
            <td>Delivery address (address lines, town or city, county, postcode, country) and saved addresses you choose to store</td>
            <td>At checkout, or when you add an address in your account</td>
            <td>To deliver your order and to prefill future checkouts</td>
            <td>Performance of a contract</td>
            <td>Order addresses are kept for 6 years (see below); saved addresses until you delete them</td>
          </tr>
          <tr>
            <td>Order details: items, options, quantities, prices, delivery choice, discount codes, order status, tracking reference, and any delivery or gifting note you write</td>
            <td>When you place an order</td>
            <td>To fulfil, deliver and support your order, and to handle returns</td>
            <td>Performance of a contract, and legal obligation for the accounting record</td>
            <td>6 years from the end of the tax year, as UK tax law requires</td>
          </tr>
          <tr>
            <td>Return or cancellation requests, including the reason you give</td>
            <td>When you request a return or cancellation</td>
            <td>To assess and process your request</td>
            <td>Legal obligation and performance of a contract</td>
            <td>Held with the order record</td>
          </tr>
          <tr>
            <td>Your wishlist</td>
            <td>When you save a product while signed in</td>
            <td>To show you the items you saved</td>
            <td>Performance of a contract</td>
            <td>Until you remove the item or close your account</td>
          </tr>
          <tr>
            <td>Basket contents and a random basket token stored in a cookie</td>
            <td>When you add something to your basket</td>
            <td>So your basket survives between visits</td>
            <td>Strictly necessary for a service you requested</td>
            <td>Up to 12 months from last use</td>
          </tr>
          <tr>
            <td>Email address for the newsletter, plus the date you subscribed and whether you subscribed as a guest or account holder</td>
            <td>When you submit the newsletter form</td>
            <td>To send you marketing emails about new pieces and private sales</td>
            <td>Consent</td>
            <td>Until you withdraw consent</td>
          </tr>
          <tr>
            <td>Appointment bookings: first and last name, email, mobile number with country code, chosen service, date and time, your notes, and your marketing preferences</td>
            <td>When you book a consultation</td>
            <td>To confirm and hold your appointment and to prepare for it</td>
            <td>Performance of a contract, plus consent for any marketing you opt into</td>
            <td>24 months from the appointment date</td>
          </tr>
          <tr>
            <td>Technical session data, including a hashed combination of your IP address and browser identifier</td>
            <td>Whenever you use the site</td>
            <td>To keep your session secure and detect session hijacking</td>
            <td>Legitimate interests — securing the website</td>
            <td>Deleted when your session ends</td>
          </tr>
          <tr>
            <td>IP address of failed sign-in attempts to our staff areas</td>
            <td>On a failed staff sign-in</td>
            <td>To rate-limit and block brute-force attacks</td>
            <td>Legitimate interests — securing the website</td>
            <td>Cleared once the lockout period expires</td>
          </tr>
        </tbody>
      </table>
      <p>
        We do not collect special category data (such as health, ethnicity, or religious belief), and we do not carry
        out profiling or automated decision-making that has a legal or similarly significant effect on you.
      </p>
    </section>

    <section class="legal-card">
      <h2>Payments — we never see your card</h2>
      <p>
        Card payments are handled entirely by <strong>Stripe</strong>. When you pay, you are taken to Stripe's own
        secure checkout page and you enter your card details there, on Stripe's systems. Your card number, expiry date
        and security code are never sent to, processed by, or stored on our servers.
      </p>
      <p>
        We send Stripe only the order total, a short description of the purchase, your email address, and our internal
        order reference. Stripe returns a payment reference which we store against your order so we can confirm the
        payment and issue refunds. Stripe acts as an independent controller for the payment data it collects; see the
        <a href="https://stripe.com/gb/privacy" target="_blank" rel="noopener noreferrer">Stripe Privacy Policy</a>.
      </p>
    </section>

    <section class="legal-card">
      <h2>Who we share data with</h2>
      <p>We do not sell your personal data, and we do not share it for anyone else's marketing. We share it only with:</p>
      <ul>
        <li><strong>Stripe Payments Europe, Ltd.</strong> — to take payment and process refunds.</li>
        <li><strong>Our delivery partners</strong> — the recipient name, delivery address and contact number needed to get your parcel to you.</li>
        <li><strong>Our hosting and database provider</strong> — the servers that store this website's data, acting as a processor under contract.</li>
        <li><strong>Professional advisers and authorities</strong> — our accountants, and law enforcement or regulators where we are legally required to disclose.</li>
      </ul>
      <p>
        Some pages load fonts and icons from Google Fonts and Cloudflare. When your browser requests those files, your
        IP address and browser details are visible to those providers. This happens for presentation only; no account
        or order data is shared with them.
      </p>
    </section>

    <section class="legal-card">
      <h2>International transfers</h2>
      <p>
        Our data is stored on servers within the UK or the European Economic Area wherever possible. Where a provider
        such as Stripe processes data outside the UK, that transfer is protected by an adequacy decision or by the UK
        International Data Transfer Agreement or Addendum, together with appropriate technical safeguards.
      </p>
    </section>

    <section class="legal-card">
      <h2>Marketing and how to opt out</h2>
      <p>
        We only send marketing email if you asked us to — by subscribing to the newsletter or by ticking the marketing
        box when you book an appointment. Consent is never bundled into placing an order.
      </p>
      <p>
        You can withdraw consent at any time, and it is free. Use the unsubscribe link in any marketing email, or email
        <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> asking to be removed. We will stop
        sending marketing promptly. Withdrawing marketing consent does not affect emails we must send about an order you
        have placed, such as a dispatch or refund confirmation.
      </p>
    </section>

    <section class="legal-card">
      <h2>Cookies</h2>
      <p>
        We use strictly necessary cookies for security, sign-in, your basket, checkout, and to remember your cookie
        choice. Non-essential cookies stay switched off unless you opt in. The full list of cookies, their purposes and
        durations is in our <a href="<?= h(resolve_link('/cookie-policy/')) ?>">Cookie Policy</a>, and you can change
        your choices at any time using the Cookie Settings button in the footer.
      </p>
    </section>

    <section class="legal-card">
      <h2>How we protect your data</h2>
      <ul>
        <li>Passwords are stored as one-way hashes, never as readable text — not even we can read your password.</li>
        <li>The site is served over HTTPS with security headers and a content security policy.</li>
        <li>Forms are protected against cross-site request forgery, and sessions are bound to a hashed device fingerprint.</li>
        <li>Staff areas are separately authenticated and rate-limited against brute-force attempts.</li>
        <li>Data files are held outside the publicly reachable part of the website.</li>
      </ul>
      <p>
        No system is perfectly secure. If a breach occurs that risks your rights and freedoms, we will report it to the
        Information Commissioner's Office within 72 hours and tell you where we are required to.
      </p>
    </section>

    <section class="legal-card">
      <h2>How long we keep your data</h2>
      <p>
        Retention periods are listed per category in the table above. In summary: order and payment records are kept for
        <strong>6 years</strong> after the end of the relevant tax year because UK tax law requires it; account details
        are kept until you ask us to close your account; marketing consent records are kept until you withdraw consent.
        When a retention period ends, data is deleted or irreversibly anonymised.
      </p>
      <p>
        If you ask us to delete your account, we will remove your profile, saved addresses and wishlist. We must keep the
        underlying invoice and order records for the statutory 6-year period, so those are retained and restricted from
        further use rather than erased.
      </p>
    </section>

    <section class="legal-card">
      <h2>Your rights</h2>
      <p>Under UK GDPR you have the right to:</p>
      <ul>
        <li><strong>Be informed</strong> — which is what this policy is for.</li>
        <li><strong>Access</strong> a copy of the personal data we hold about you.</li>
        <li><strong>Rectification</strong> — have inaccurate data corrected. You can edit most of it yourself in
          <a href="<?= h(resolve_link('/account/profile/')) ?>">your account</a>.</li>
        <li><strong>Erasure</strong> — ask us to delete your data where we have no overriding legal reason to keep it.</li>
        <li><strong>Restrict processing</strong> — ask us to pause how we use your data while a concern is resolved.</li>
        <li><strong>Data portability</strong> — receive the data you gave us in a structured, machine-readable format.</li>
        <li><strong>Object</strong> — to processing based on legitimate interests, and to direct marketing at any time.</li>
        <li><strong>Withdraw consent</strong> — where we rely on consent, you can withdraw it at any time.</li>
      </ul>
      <p>
        To exercise any right, email <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a>. We
        respond within one month. There is no charge, and we may ask you to confirm your identity first so we do not
        disclose your data to someone else.
      </p>
    </section>

    <section class="legal-card">
      <h2>Complaints</h2>
      <p>
        If you are unhappy with how we have handled your data, please tell us first at
        <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> so we can put it right. You also
        have the right to complain to the UK supervisory authority:
      </p>
      <p>
        <strong>Information Commissioner's Office</strong><br>
        Wycliffe House, Water Lane, Wilmslow, Cheshire, SK9 5AF<br>
        Helpline: 0303 123 1113 &middot;
        <a href="https://ico.org.uk/make-a-complaint/" target="_blank" rel="noopener noreferrer">ico.org.uk/make-a-complaint</a>
      </p>
    </section>

    <section class="legal-card">
      <h2>Children</h2>
      <p>
        This website is intended for customers aged 18 or over. We do not knowingly collect personal data from children.
        If you believe a child has given us their data, contact us and we will delete it.
      </p>
    </section>

    <section class="legal-card">
      <h2>Changes to this policy</h2>
      <p>
        We may update this policy as the shop changes or the law changes. The date at the top always shows when it was
        last revised. If a change materially affects how we use your data, we will make that clear before it takes
        effect. Related pages:
        <a href="<?= h(resolve_link('/terms/')) ?>">Terms &amp; Conditions</a>,
        <a href="<?= h(resolve_link('/returns/')) ?>">Returns &amp; Refunds</a> (<?= (int) $returnDays ?>-day window), and
        <a href="<?= h(resolve_link('/cookie-policy/')) ?>">Cookie Policy</a>.
      </p>
    </section>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
