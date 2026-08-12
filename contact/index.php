<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'Contact Us | ' . SITE_NAME;
$bodyClass = 'legal-page';
$company = site_company_details();
$returnDays = order_return_window_days();
$phoneHref = preg_replace('/[^0-9+]/', '', $company['phone']);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="legal-shell">
  <section class="legal-hero">
    <p class="legal-kicker">Contact</p>
    <h1>Contact Us</h1>
    <p class="legal-intro">
      Whether you have a question about an order, need sizing advice, or want to talk through a bespoke commission, we are
      here to help. Real people, based in the UK.
    </p>
    <div class="legal-meta">
      <span><i class="far fa-envelope" aria-hidden="true"></i> <a href="mailto:<?= h($company['email']) ?>" style="color:inherit;"><?= h($company['email']) ?></a></span>
      <span><i class="fas fa-phone-alt" aria-hidden="true"></i> <a href="tel:<?= h($phoneHref) ?>" style="color:inherit;"><?= h($company['phone']) ?></a></span>
      <?php if ($company['support_hours'] !== ''): ?>
        <span><i class="far fa-clock" aria-hidden="true"></i> <?= h($company['support_hours']) ?></span>
      <?php endif; ?>
    </div>
  </section>

  <div class="legal-grid">
    <section class="legal-card">
      <h2>How to reach us</h2>
      <table>
        <thead>
          <tr>
            <th>What you need</th>
            <th>Best way</th>
            <th>Response time</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>An existing order, delivery or tracking query</td>
            <td>Email <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> with your order reference</td>
            <td>Within 1 working day</td>
          </tr>
          <tr>
            <td>A return, cancellation or exchange</td>
            <td>Use the order in <a href="<?= h(resolve_link('/account/order/')) ?>">your account</a>, or email us</td>
            <td>Within 1 working day</td>
          </tr>
          <tr>
            <td>Sizing, styling or product advice</td>
            <td><a href="<?= h(resolve_link('/appointment/')) ?>">Book a free consultation</a> or call us</td>
            <td>Appointments confirmed by email</td>
          </tr>
          <tr>
            <td>A bespoke or made-to-order commission</td>
            <td><a href="<?= h(resolve_link('/appointment/')) ?>">Book a consultation</a></td>
            <td>We will discuss timescales with you directly</td>
          </tr>
          <tr>
            <td>A privacy or data protection request</td>
            <td>Email <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a></td>
            <td>Within 1 month, as UK GDPR requires</td>
          </tr>
          <tr>
            <td>A complaint</td>
            <td>Email us and mark it as a complaint</td>
            <td>Acknowledged within 2 working days</td>
          </tr>
        </tbody>
      </table>
    </section>

    <?php require dirname(__DIR__) . '/includes/partials/legal-identity.php'; ?>

    <section class="legal-card">
      <h2>Book a consultation</h2>
      <p>
        The best way to choose a significant piece is to talk it through. Our consultations are free, with no obligation to
        buy, and cover diamond selection, setting styles, sizing and budget.
      </p>
      <p>
        <a href="<?= h(resolve_link('/appointment/')) ?>">Book an appointment</a> and pick a date and time that suits you.
        You will receive a confirmation by email with everything you need.
      </p>
    </section>

    <section class="legal-card">
      <h2>Before you write to us</h2>
      <p>
        These pages answer the questions we are asked most, and may save you a wait:
      </p>
      <ul>
        <li><a href="<?= h(resolve_link('/faq/')) ?>">Frequently asked questions</a> — sizing, care, certification and more.</li>
        <li><a href="<?= h(resolve_link('/delivery/')) ?>">Delivery Information</a> — timings, tracking, insurance and what happens if a parcel is late.</li>
        <li><a href="<?= h(resolve_link('/returns/')) ?>">Returns &amp; Refunds</a> — the <?= (int) $returnDays ?>-day window, exclusions and how refunds are paid.</li>
        <li><a href="<?= h(resolve_link('/account/order/')) ?>">Your orders</a> — live status and tracking reference for anything you have bought.</li>
        <li><a href="<?= h(resolve_link('/terms/')) ?>">Terms &amp; Conditions</a> — the contract terms and your statutory rights.</li>
      </ul>
    </section>

    <section class="legal-card">
      <h2>Complaints</h2>
      <p>
        If we have got something wrong, tell us and we will put it right. Email
        <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> with your order reference and what has
        happened. We acknowledge complaints within 2 working days and aim to resolve them within 14 days.
      </p>
      <p>
        If we cannot resolve it between us, you may be able to use an alternative dispute resolution scheme, and you keep
        the right to take court proceedings. For a data protection concern you can also complain to the
        <a href="https://ico.org.uk/make-a-complaint/" target="_blank" rel="noopener noreferrer">Information Commissioner's
        Office</a>.
      </p>
    </section>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
