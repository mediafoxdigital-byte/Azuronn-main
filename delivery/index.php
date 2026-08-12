<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'Delivery Information | ' . SITE_NAME;
$bodyClass = 'legal-page';
$company = site_company_details();
$returnDays = order_return_window_days();
$deliveryOptions = site_delivery_options();
$expressOption = null;
foreach ($deliveryOptions as $option) {
    if (($option['value'] ?? '') === 'express') {
        $expressOption = $option;
        break;
    }
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="legal-shell">
  <section class="legal-hero">
    <p class="legal-kicker">Delivery</p>
    <h1>Delivery Information</h1>
    <p class="legal-intro">
      Every order ships free, fully insured, in signature <?= h(SITE_NAME) ?> packaging. Choose Express at checkout if you
      need it sooner. Everything you need to know about timings, tracking and signature on delivery is below.
    </p>
    <div class="legal-meta">
      <span><i class="fas fa-truck" aria-hidden="true"></i> Free delivery on all orders, no minimum</span>
      <span><i class="fas fa-shield-halved" aria-hidden="true"></i> Fully insured in transit</span>
      <span><i class="fas fa-location-dot" aria-hidden="true"></i> United Kingdom only</span>
    </div>
  </section>

  <div class="legal-grid">
    <section class="legal-card">
      <h2>Delivery options and costs</h2>
      <table>
        <thead>
          <tr>
            <th>Option</th>
            <th>Cost</th>
            <th>Estimated arrival</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deliveryOptions as $option): ?>
            <tr>
              <td><strong><?= h((string) ($option['label'] ?? '')) ?></strong></td>
              <td><?= h((string) ($option['price_label'] ?? '')) ?></td>
              <td><?= h((string) ($option['description'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p>
        You choose your delivery option on the product page before adding to your basket, and the choice is shown against
        each line in your basket so you can check it before paying.
      </p>
      <?php if ($expressOption !== null): ?>
        <h3>How the Express charge is calculated</h3>
        <p>
          Express Delivery is charged <strong>per item</strong>, not once per order. The surcharge is
          <strong><?= h(money_format((float) ($expressOption['price'] ?? 0))) ?></strong> for each unit, so a quantity of
          three pays it three times. Basic delivery is always free, whatever the order value or quantity. The exact total
          is always shown in your basket and at checkout before you confirm payment.
        </p>
      <?php endif; ?>
    </section>

    <section class="legal-card">
      <h2>Where we deliver</h2>
      <p>
        We currently deliver to addresses in the <strong>United Kingdom</strong> only, including England, Scotland, Wales
        and Northern Ireland. We are unable to ship to addresses outside the UK, to PO boxes, or to forwarding services.
      </p>
      <p>
        We can deliver to a work address or an alternative address if that is easier — enter it as the delivery address at
        checkout. Because every parcel needs a signature, please choose somewhere that will be attended.
      </p>
    </section>

    <section class="legal-card">
      <h2>Dispatch and processing</h2>
      <ul>
        <li>Orders placed before 2pm on a working day are normally prepared for dispatch the same day.</li>
        <li>Orders placed after 2pm, at weekends, or on UK public holidays are prepared the next working day.</li>
        <li>Working days are Monday to Friday, excluding UK public holidays.</li>
        <li>Pieces that need resizing, engraving or bespoke work take longer to prepare; we will confirm the expected
          date with you directly.</li>
        <li>Delivery estimates run from dispatch, not from when you order.</li>
      </ul>
      <p>
        Timings are estimates rather than guarantees. Where no delivery period has been agreed, we will deliver within 30
        days of the contract, as the Consumer Rights Act 2015 requires.
      </p>
    </section>

    <section class="legal-card">
      <h2>Tracking your order</h2>
      <p>
        You can follow your order at any time in <a href="<?= h(resolve_link('/account/order/')) ?>">your account</a>.
        The status moves through Processing, Shipped and Delivered. A tracking reference appears against the order once it
        has been shipped — it is not issued while the order is still being prepared, because the parcel has not yet been
        handed to the courier.
      </p>
    </section>

    <section class="legal-card">
      <h2>Insurance, signature and packaging</h2>
      <ul>
        <li>Every parcel is fully insured for its full value while in transit, at no extra cost to you.</li>
        <li>A signature is required on delivery. Parcels are not left unattended or in a safe place.</li>
        <li>Packaging is deliberately discreet on the outside — nothing on the label indicates jewellery.</li>
        <li>Inside, each piece arrives in our signature gift box with its care card and any certification.</li>
        <li>Risk in the goods passes to you once they are delivered to the address you gave us.</li>
      </ul>
    </section>

    <section class="legal-card">
      <h2>If something goes wrong</h2>
      <h3>Nobody was home</h3>
      <p>
        The courier will leave a card and attempt redelivery or hold the parcel at a local depot. Follow the instructions
        on the card or use your tracking reference to rearrange.
      </p>
      <h3>Your parcel is late</h3>
      <p>
        If your order has not arrived within 5 working days of the estimated date, email
        <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> with your order reference and we will
        investigate with the courier. If delivery is essential by a particular date and we miss it, you have the right to
        cancel and receive a refund.
      </p>
      <h3>Your parcel arrived damaged</h3>
      <p>
        Please do not sign for a parcel that is visibly damaged if you can avoid it. Either way, contact us within 48
        hours with photographs and we will arrange a replacement or a full refund.
      </p>
      <h3>Wrong item received</h3>
      <p>
        Contact us and we will arrange collection and send the correct piece at our cost. You will never be out of pocket
        for our mistake.
      </p>
    </section>

    <section class="legal-card">
      <h2>Changing your delivery address</h2>
      <p>
        If you need to change the delivery address, contact us as soon as possible with your order reference. We can
        usually amend it before dispatch. Once a parcel has been handed to the courier we cannot redirect it, and you
        would need to rearrange delivery with the courier directly using your tracking reference.
      </p>
    </section>

    <section class="legal-card">
      <h2>Returns</h2>
      <p>
        You have <strong><?= (int) $returnDays ?> days</strong> from delivery to return an unworn piece. Full instructions,
        exclusions and refund timescales are on our
        <a href="<?= h(resolve_link('/returns/')) ?>">Returns &amp; Refunds</a> page, and your statutory rights are set out
        in our <a href="<?= h(resolve_link('/terms/')) ?>">Terms &amp; Conditions</a>.
      </p>
    </section>

    <section class="legal-card">
      <h2>Still need help?</h2>
      <p>
        Email <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> or call
        <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $company['phone'])) ?>"><?= h($company['phone']) ?></a><?php if ($company['support_hours'] !== ''): ?>,
        <?= h($company['support_hours']) ?><?php endif; ?>. You can also see all the ways to reach us on our
        <a href="<?= h(resolve_link('/contact/')) ?>">Contact</a> page.
      </p>
    </section>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
