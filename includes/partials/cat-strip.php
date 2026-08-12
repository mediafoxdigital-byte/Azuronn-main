<?php $categoryCards = site_content()['category_cards']; ?>
<section class="category-showcase-section" data-category-carousel data-reveal>
  <div class="container">
    <div class="section-header-row category-showcase-header">
      <div class="category-showcase-heading section-header-left" data-reveal>
        <span class="category-showcase-kicker">Shop by Category</span>
        <div class="category-showcase-title-row">
          <span aria-hidden="true"></span>
          <h2>Explore Signature Categories</h2>
          <span aria-hidden="true"></span>
        </div>
        <p>Explore signature collections with image-led category cards designed to feel editorial, refined, and easy to browse on every screen.</p>
      </div>
      <div class="section-nav-arrows">
        <button class="section-nav-btn" type="button" data-category-prev aria-label="Previous category"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
        <button class="section-nav-btn" type="button" data-category-next aria-label="Next category"><svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></svg></button>
      </div>
    </div>

    <div class="category-showcase-shell">
      <div class="category-showcase-viewport">
        <div class="category-showcase-track" data-category-track>
          <?php foreach ($categoryCards as $index => $card): ?>
            <?php
              // Prefer the card's stored URL so cards like "Engagement Rings" can
              // link to a ring section. Merchant-created categories are stored with
              // '#' because the Categories admin has no URL field, so treat that as
              // "no link" too and route them to their own shop listing.
              $cardHref = trim((string) ($card['url'] ?? ''));
              if ($cardHref === '' || $cardHref === '#') {
                  $cardType = catalog_canonical_type(trim((string) ($card['title'] ?? '')));
                  $cardHref = $cardType !== '' ? '/shop/?type=' . urlencode($cardType) : '/shop/';
              }
            ?>
            <a
              class="category-showcase-card"
              href="<?= h(resolve_link($cardHref)) ?>"
              style="--category-index: <?= (int) $index ?>;"
            >
              <div class="category-showcase-media">
                <img src="<?= h($card['image']) ?>" alt="<?= h($card['alt']) ?>" loading="lazy">
              </div>
              <div class="category-showcase-copy">
                <span class="category-showcase-name"><?= h($card['title']) ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>
