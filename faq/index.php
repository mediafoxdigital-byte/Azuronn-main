<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'Frequently Asked Questions | ' . SITE_NAME;
$bodyClass = 'legal-page';
$company = site_company_details();
$returnDays = order_return_window_days();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="legal-shell">
  <section class="legal-hero">
    <p class="legal-kicker">Help Centre</p>
    <h1>Frequently Asked Questions</h1>
    <p class="legal-intro">
      Answers to the questions we hear most — on lab-grown diamonds, sizing, care, delivery and returns. If yours is not
      here, <a href="<?= h(resolve_link('/contact/')) ?>">get in touch</a> and we will answer it personally.
    </p>
    <div class="legal-meta">
      <span><i class="fas fa-truck" aria-hidden="true"></i> Free UK delivery on all orders</span>
      <span><i class="fas fa-rotate-left" aria-hidden="true"></i> <?= (int) $returnDays ?>-day returns</span>
      <span><i class="far fa-gem" aria-hidden="true"></i> Lifetime warranty on fine jewellery</span>
    </div>
  </section>

  <div class="legal-grid">
    <section class="legal-card">
      <h2>Quick links</h2>
      <ul>
        <li><a href="<?= h(resolve_link('/delivery/')) ?>">Delivery Information</a> — timings, tracking, insurance and Express charges.</li>
        <li><a href="<?= h(resolve_link('/returns/')) ?>">Returns &amp; Refunds</a> — how to return a piece and when you get your money back.</li>
        <li><a href="<?= h(resolve_link('/account/order/')) ?>">Track an order</a> — live status and tracking reference.</li>
        <li><a href="<?= h(resolve_link('/appointment/')) ?>">Book a consultation</a> — free, no-obligation expert advice.</li>
        <li><a href="<?= h(resolve_link('/contact/')) ?>">Contact us</a> — every way to reach a real person.</li>
      </ul>
    </section>
  </div>
</main>

<?php require dirname(__DIR__) . '/includes/partials/faq.php'; ?>

<main class="legal-shell" style="padding-top:0;">
  <div class="legal-grid">
    <section class="legal-card">
      <h2>Still have a question?</h2>
      <p>
        Email <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> or call
        <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $company['phone'])) ?>"><?= h($company['phone']) ?></a><?php if ($company['support_hours'] !== ''): ?>,
        <?= h($company['support_hours']) ?><?php endif; ?>. We reply to email within one working day.
      </p>
    </section>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
