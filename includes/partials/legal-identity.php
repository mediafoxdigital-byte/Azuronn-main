<?php
// Trader identity block shared by every legal page so the details are stated
// once. Registration numbers render only when set — see site_company_details().
$company = site_company_details();
?>
<section class="legal-card">
  <h2>Who we are</h2>
  <p>
    This website is operated by <strong><?= h($company['legal_name']) ?></strong>, trading as <?= h(SITE_NAME) ?>.
  </p>
  <table>
    <tbody>
      <tr>
        <th scope="row">Trading name</th>
        <td><?= h(SITE_NAME) ?></td>
      </tr>
      <tr>
        <th scope="row">Registered name</th>
        <td><?= h($company['legal_name']) ?></td>
      </tr>
      <?php if ($company['company_number'] !== ''): ?>
        <tr>
          <th scope="row">Company number</th>
          <td><?= h($company['company_number']) ?> (registered in England &amp; Wales)</td>
        </tr>
      <?php endif; ?>
      <?php if ($company['vat_number'] !== ''): ?>
        <tr>
          <th scope="row">VAT number</th>
          <td><?= h($company['vat_number']) ?></td>
        </tr>
      <?php endif; ?>
      <tr>
        <th scope="row">Registered address</th>
        <td><?= nl2br(h($company['registered_address'])) ?></td>
      </tr>
      <?php if ($company['trading_address'] !== ''): ?>
        <tr>
          <th scope="row">Trading address</th>
          <td><?= nl2br(h($company['trading_address'])) ?></td>
        </tr>
      <?php endif; ?>
      <tr>
        <th scope="row">Email</th>
        <td><a href="mailto:<?= h($company['email']) ?>"><?= h($company['email']) ?></a></td>
      </tr>
      <tr>
        <th scope="row">Telephone</th>
        <td><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $company['phone'])) ?>"><?= h($company['phone']) ?></a></td>
      </tr>
      <?php if ($company['support_hours'] !== ''): ?>
        <tr>
          <th scope="row">Support hours</th>
          <td><?= h($company['support_hours']) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>
