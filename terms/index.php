<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'Terms & Conditions | ' . SITE_NAME;
$bodyClass = 'legal-page';
$company = site_company_details();
$returnDays = order_return_window_days();
$deliveryOptions = site_delivery_options();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="legal-shell">
  <section class="legal-hero">
    <p class="legal-kicker">Legal</p>
    <h1>Terms &amp; Conditions</h1>
    <p class="legal-intro">
      These terms govern your use of this website and every order you place with <?= h(SITE_NAME) ?>. Please read them
      before ordering. By placing an order you accept these terms. Nothing here removes or limits your legal rights as a
      consumer under UK law.
    </p>
    <div class="legal-meta">
      <span><i class="far fa-calendar-alt" aria-hidden="true"></i> Last updated: 7 August 2026</span>
      <span><i class="fas fa-scale-balanced" aria-hidden="true"></i> Governed by the law of England &amp; Wales</span>
      <span><i class="fas fa-rotate-left" aria-hidden="true"></i> <?= (int) $returnDays ?>-day returns</span>
    </div>
  </section>

  <div class="legal-grid">
    <?php require dirname(__DIR__) . '/includes/partials/legal-identity.php'; ?>

    <section class="legal-card">
      <h2>1. About these terms</h2>
      <p>
        These are the terms on which we sell products to you. They apply to consumer purchases made through this
        website. We may change these terms from time to time; the version that applies to your order is the version
        published on this page when you placed it.
      </p>
      <p>
        You must be at least 18 years old and able to enter a legally binding contract to order from us.
      </p>
    </section>

    <section class="legal-card">
      <h2>2. How a contract is formed</h2>
      <ul>
        <li>Placing an order is an offer to buy. It does not create a contract straight away.</li>
        <li>We accept your order when we confirm it and take payment. That is the point the contract is formed.</li>
        <li>If we cannot accept your order — because an item is out of stock, a price or description was wrong, or we
          cannot verify the payment — we will tell you and refund any amount taken in full.</li>
        <li>Each order receives a reference number. Please quote it when you contact us.</li>
      </ul>
    </section>

    <section class="legal-card">
      <h2>3. Products, options and descriptions</h2>
      <p>
        We describe each piece as accurately as we can, including metal, carat weight, chain length and size options.
        Photographs are illustrative: screens render colour differently, and images are often enlarged to show detail, so
        the piece you receive may look slightly different from the image.
      </p>
      <p>
        Lab-grown diamonds are chemically, physically and optically identical to mined diamonds, and we describe them as
        lab-grown wherever they are used. Because fine jewellery is made from natural and hand-finished materials, small
        variations between pieces are normal and are not defects.
      </p>
      <p>
        Where a product has options such as metal, size, carat weight or chain length, the price shown updates to reflect
        the options you choose. The price you pay is the total shown at checkout before you confirm.
      </p>
    </section>

    <section class="legal-card">
      <h2>4. Price and payment</h2>
      <ul>
        <li>All prices are in pounds sterling (GBP) and include UK VAT where applicable.</li>
        <li>Standard delivery is <strong>free on every order</strong>, with no minimum spend.</li>
        <li>Express Delivery is an optional per-item surcharge. If you select it, the surcharge applies to each unit —
          ordering three of an item means the surcharge is charged three times. The full amount is shown in your basket
          and at checkout before you pay.</li>
        <li>Payment is taken through Stripe's secure hosted checkout. We never receive or store your card details.</li>
        <li>If a price is obviously wrong through a genuine error, we may cancel the order and refund you in full rather
          than supply at that price. We will always contact you first.</li>
        <li>Discount codes are subject to their own conditions, including any minimum spend and expiry date, and cannot
          be combined unless we say so.</li>
      </ul>
    </section>

    <section class="legal-card">
      <h2>5. Delivery</h2>
      <p>
        We deliver to addresses in the United Kingdom only. Delivery options at checkout:
      </p>
      <table>
        <thead>
          <tr>
            <th>Option</th>
            <th>Cost</th>
            <th>Details</th>
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
        Delivery estimates are working days from dispatch and are estimates, not guarantees. Where no period is agreed,
        we will deliver within 30 days of the contract, as the Consumer Rights Act 2015 requires. The goods become your
        responsibility once they are delivered to the address you gave us. Full detail is on our
        <a href="<?= h(resolve_link('/delivery/')) ?>">Delivery Information</a> page.
      </p>
    </section>

    <section class="legal-card">
      <h2>6. Your right to cancel</h2>
      <p>
        Under the Consumer Contracts (Information, Cancellation and Additional Charges) Regulations 2013 you may cancel
        an online order without giving a reason, from the moment you place it until 14 days after the day you receive the
        goods. We voluntarily extend this to <strong><?= (int) $returnDays ?> days</strong> from delivery.
      </p>
      <p>
        To cancel, tell us clearly — the quickest way is the return option on the order in
        <a href="<?= h(resolve_link('/account/order/')) ?>">your account</a>, or email
        <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a>. Then return the goods to us. We
        refund within 14 days of receiving them back, or of your proof of return. Full instructions, exclusions and who
        pays return postage are set out on our <a href="<?= h(resolve_link('/returns/')) ?>">Returns &amp; Refunds</a>
        page.
      </p>
      <p>
        The cancellation right does not apply to goods made to your specification or clearly personalised, such as
        engraved or bespoke-made pieces.
      </p>
    </section>

    <section class="legal-card">
      <h2>7. Faulty or misdescribed goods</h2>
      <p>
        The Consumer Rights Act 2015 requires goods to be as described, fit for purpose and of satisfactory quality.
        If what you received is faulty or not as described:
      </p>
      <ul>
        <li>Within <strong>30 days</strong> you can reject the goods for a full refund.</li>
        <li>Up to <strong>6 months</strong> you can ask for a repair or replacement; if that fails, a refund.</li>
        <li>Up to <strong>6 years</strong> you may still have a claim, though you may need to show the fault was present
          at delivery.</li>
      </ul>
      <p>
        This is in addition to any warranty we offer voluntarily, and nothing in these terms affects it.
      </p>
    </section>

    <section class="legal-card">
      <h2>8. Accounts and acceptable use</h2>
      <ul>
        <li>Keep your account password confidential and tell us if you think someone else has used it.</li>
        <li>Give accurate account and delivery details, and keep them up to date.</li>
        <li>Do not attempt to gain unauthorised access to the website, disrupt it, scrape it at scale, or use it for any
          unlawful or fraudulent purpose.</li>
        <li>We may suspend or close an account that breaches these terms, or where we reasonably suspect fraud.</li>
      </ul>
    </section>

    <section class="legal-card">
      <h2>9. Intellectual property</h2>
      <p>
        All content on this website — including the <?= h(SITE_NAME) ?> name and logo, product photography, design
        drawings, text and page design — belongs to us or our licensors and is protected by copyright and trade mark law.
        You may view and print pages for your own personal, non-commercial use. You may not reproduce, republish or use
        our content commercially without our written permission.
      </p>
    </section>

    <section class="legal-card">
      <h2>10. Our liability</h2>
      <p>
        We are responsible for loss or damage you suffer that is a foreseeable result of our breaking these terms or
        failing to use reasonable care and skill.
      </p>
      <p>
        We do not exclude or limit our liability in any way where it would be unlawful to do so. That includes liability
        for death or personal injury caused by our negligence, for fraud or fraudulent misrepresentation, for breach of
        your legal rights in relation to the products, and for defective products under the Consumer Protection Act 1987.
      </p>
      <p>
        We are not liable for losses that were not foreseeable, for losses caused by an event outside our reasonable
        control, or — because we supply products for domestic and private use — for any loss of profit, loss of business,
        business interruption or loss of business opportunity.
      </p>
    </section>

    <section class="legal-card">
      <h2>11. Website availability</h2>
      <p>
        We aim to keep the website available but we do not guarantee uninterrupted access. We may suspend, withdraw or
        restrict all or part of it for business or operational reasons, and we will give reasonable notice where we can.
      </p>
    </section>

    <section class="legal-card">
      <h2>12. Privacy and cookies</h2>
      <p>
        How we handle your personal data is explained in our
        <a href="<?= h(resolve_link('/privacy-policy/')) ?>">Privacy Policy</a>, and the cookies we use are listed in our
        <a href="<?= h(resolve_link('/cookie-policy/')) ?>">Cookie Policy</a>.
      </p>
    </section>

    <section class="legal-card">
      <h2>13. Complaints and dispute resolution</h2>
      <p>
        If something has gone wrong, contact us at
        <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> and we will try to resolve it
        promptly. If we cannot resolve it between us, you may be able to use an alternative dispute resolution scheme,
        and you retain the right to take court proceedings.
      </p>
    </section>

    <section class="legal-card">
      <h2>14. Other important terms</h2>
      <ul>
        <li>We may transfer our rights and obligations under these terms to another organisation; we will tell you if we
          do, and it will not affect your rights.</li>
        <li>This contract is between you and us. No other person has any right to enforce it.</li>
        <li>If a court finds part of these terms unlawful, the remaining paragraphs continue in force.</li>
        <li>If we delay in enforcing these terms, that does not prevent us from enforcing them later.</li>
        <li>These terms are governed by the law of England and Wales, and you may bring proceedings in the courts of
          England and Wales. If you live in Scotland or Northern Ireland, you may also bring proceedings there.</li>
      </ul>
    </section>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
