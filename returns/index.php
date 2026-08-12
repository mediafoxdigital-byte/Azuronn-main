<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pageTitle = 'Returns & Refunds | ' . SITE_NAME;
$bodyClass = 'legal-page';
$company = site_company_details();
$returnDays = order_return_window_days();
$returnAddress = $company['trading_address'] !== '' ? $company['trading_address'] : $company['registered_address'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<main class="legal-shell">
  <section class="legal-hero">
    <p class="legal-kicker">Returns</p>
    <h1>Returns &amp; Refunds</h1>
    <p class="legal-intro">
      Changed your mind? You have <?= (int) $returnDays ?> days from delivery to return an unworn piece for a full refund.
      This page explains how, what is excluded, and how long a refund takes. It sits alongside your legal rights, which we
      never restrict.
    </p>
    <div class="legal-meta">
      <span><i class="fas fa-rotate-left" aria-hidden="true"></i> <?= (int) $returnDays ?>-day return window</span>
      <span><i class="fas fa-scale-balanced" aria-hidden="true"></i> 14-day statutory right, extended by us</span>
      <span><i class="far fa-calendar-alt" aria-hidden="true"></i> Last updated: 7 August 2026</span>
    </div>
  </section>

  <div class="legal-grid">
    <section class="legal-card">
      <h2>Your return window</h2>
      <p>
        Under the Consumer Contracts (Information, Cancellation and Additional Charges) Regulations 2013 you have a legal
        right to cancel an online order without giving a reason, running until 14 days after the day you receive the goods.
      </p>
      <p>
        We voluntarily extend this to <strong><?= (int) $returnDays ?> days from the day your order is marked as
        delivered</strong>. Once that window closes, the return option on the order is no longer available, though your
        rights over a faulty or misdescribed item are separate and last much longer — see below.
      </p>
    </section>

    <section class="legal-card">
      <h2>How to return an item</h2>
      <ol style="margin:0; padding-left:18px; color:#54645c; line-height:1.8; font-size:15px;">
        <li><strong>Request the return.</strong> Sign in and open the order in
          <a href="<?= h(resolve_link('/account/order/')) ?>">your account</a>, then use the return option and tell us
          briefly why. If you would rather not use the website, email
          <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> with your order reference — any
          clear statement that you are cancelling is enough.</li>
        <li><strong>Wait for confirmation.</strong> We review the request and confirm it, along with the return address
          and anything specific to your piece.</li>
        <li><strong>Repack the piece.</strong> Include the original gift box, care card, and any certification or
          documentation that came with it.</li>
        <li><strong>Send it back.</strong> Use a tracked and insured service and keep your proof of postage. Jewellery is
          valuable, and until it reaches us it remains your responsibility.</li>
        <li><strong>Receive your refund.</strong> We inspect the piece on arrival and refund to your original payment
          method.</li>
      </ol>
      <?php if ($returnAddress !== ''): ?>
        <h3>Return address</h3>
        <p><?= nl2br(h($returnAddress)) ?></p>
        <p>Please request your return before posting, so we can match the parcel to your order.</p>
      <?php endif; ?>
    </section>

    <section class="legal-card">
      <h2>Condition of returned items</h2>
      <p>To qualify for a full refund, the piece must come back:</p>
      <ul>
        <li>unworn, and free from scratches, sizing marks, perfume, make-up or damage;</li>
        <li>with all security tags and labels still attached and unbroken;</li>
        <li>in its original gift box and outer packaging;</li>
        <li>with any certificate, valuation or care documentation supplied with it.</li>
      </ul>
      <p>
        You are entitled to examine a piece as you would in a shop. Trying a ring on to check the fit is fine. Wearing it
        out, resizing it elsewhere, or altering it is not. If the value of the goods has been reduced because you handled
        them more than was necessary, we may deduct a proportionate amount from your refund and we will always explain the
        deduction before making it.
      </p>
      <p>
        A missing certificate for a certificated diamond may reduce the refund by the cost of replacing it.
      </p>
    </section>

    <section class="legal-card">
      <h2>What cannot be returned</h2>
      <p>The cancellation right does not apply, and we cannot accept a change-of-mind return, for:</p>
      <ul>
        <li><strong>Bespoke and made-to-order pieces</strong> made to your specification;</li>
        <li><strong>Personalised items</strong>, including anything engraved with names, dates or initials;</li>
        <li><strong>Pieces altered at your request</strong>, such as a ring resized to a non-standard size or a chain
          shortened;</li>
        <li><strong>Earrings for pierced ears once the hygiene seal is broken</strong>, on health grounds;</li>
        <li><strong>Gift cards</strong>.</li>
      </ul>
      <p>
        These exclusions never apply to a faulty item. If a bespoke or personalised piece arrives faulty or is not what was
        agreed, you have the full statutory remedies described below.
      </p>
    </section>

    <section class="legal-card">
      <h2>Who pays return postage</h2>
      <table>
        <thead>
          <tr>
            <th>Reason for return</th>
            <th>Return postage</th>
            <th>Original delivery charge</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>You changed your mind</td>
            <td>Paid by you</td>
            <td>Refunded at our basic rate. Basic delivery is free, so nothing was charged. An Express surcharge you chose
              is not refunded, because the faster service was provided.</td>
          </tr>
          <tr>
            <td>The item is faulty, damaged in transit, or not as described</td>
            <td>Paid by us — we arrange collection or reimburse your postage</td>
            <td>Refunded in full, including any Express surcharge</td>
          </tr>
          <tr>
            <td>We sent the wrong item</td>
            <td>Paid by us</td>
            <td>Refunded in full, including any Express surcharge</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="legal-card">
      <h2>Refunds — how and when</h2>
      <ul>
        <li>Refunds go back to the original payment method. We cannot refund to a different card or account.</li>
        <li>We process the refund within <strong>14 days</strong> of receiving the goods back, or of you giving us proof
          that you have sent them, whichever is earlier.</li>
        <li>Once we issue it, your bank or card provider usually shows the money within 3 to 5 working days.</li>
        <li>Where a discount code was applied, we refund the amount you actually paid.</li>
        <li>If you return part of an order and that takes it below a discount code's minimum spend, we may adjust the
          refund to reflect the discount you were no longer entitled to.</li>
      </ul>
    </section>

    <section class="legal-card">
      <h2>Exchanges and resizing</h2>
      <p>
        Prefer a different size, metal or piece? Tell us when you request the return and we will arrange an exchange
        instead of a refund. If the new piece costs more, we will send a payment link for the difference; if it costs less,
        we refund the difference.
      </p>
      <p>
        We also offer one complimentary ring resizing within 60 days of purchase on standard designs. Some settings —
        eternity bands and certain channel or tension settings in particular — cannot be safely resized, and we will tell
        you before starting work if that applies to your ring.
      </p>
    </section>

    <section class="legal-card">
      <h2>Faulty or misdescribed items</h2>
      <p>
        This is a separate right from changing your mind, and it lasts far longer. Under the Consumer Rights Act 2015 goods
        must be as described, fit for purpose and of satisfactory quality. If yours are not:
      </p>
      <ul>
        <li>within <strong>30 days</strong> of delivery you can reject them and get a full refund;</li>
        <li>up to <strong>6 months</strong> you can ask for a repair or replacement, and a refund if that does not
          resolve it;</li>
        <li>up to <strong>6 years</strong> you may still have a claim, though you may need to show the fault was present
          at delivery.</li>
      </ul>
      <p>
        Fair wear and tear from normal use is not a fault. Please email
        <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> with your order reference and
        photographs, and we will sort it out.
      </p>
    </section>

    <section class="legal-card">
      <h2>Cancelling before dispatch</h2>
      <p>
        If your order has not yet shipped, you can request a cancellation from the order in
        <a href="<?= h(resolve_link('/account/order/')) ?>">your account</a>. We stop the order and refund you in full — no
        need to wait for delivery and return it.
      </p>
    </section>

    <section class="legal-card">
      <h2>Gifts</h2>
      <p>
        If you received a piece as a gift, the return right belongs to the person who bought it, so please ask them to
        contact us. Any refund goes back to their original payment method. Where possible we will offer an exchange or
        credit instead, so the gift can be discreetly resolved.
      </p>
    </section>

    <section class="legal-card">
      <h2>Questions</h2>
      <p>
        Email <a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a> or call
        <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $company['phone'])) ?>"><?= h($company['phone']) ?></a><?php if ($company['support_hours'] !== ''): ?>,
        <?= h($company['support_hours']) ?><?php endif; ?>. See also our
        <a href="<?= h(resolve_link('/delivery/')) ?>">Delivery Information</a> and
        <a href="<?= h(resolve_link('/terms/')) ?>">Terms &amp; Conditions</a>.
      </p>
    </section>
  </div>
</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
