<?php $bestselling = site_content()['bestselling']; ?>
<section class="premium-popular-section" data-reveal>
  <div class="container-fluid popular-container">
    <div class="section-header-row" style="max-width: 1200px; margin: 0 auto; padding: 0 15px;">
      <div class="sec-hdr-premium section-header-left" data-reveal>
        <span class="shop-style-kicker">Most Popular</span>
        <div class="shop-style-title-row">
          <span class="sec-line"></span>
          <h2><?= h($bestselling['title']) ?></h2>
          <span class="sec-line"></span>
        </div>
      </div>
      <div class="section-nav-arrows">
        <button class="section-nav-btn" type="button" data-rail-prev aria-label="Previous"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
        <button class="section-nav-btn" type="button" data-rail-next aria-label="Next"><svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></svg></button>
      </div>
    </div>

    <div class="product-rail-shell" data-product-carousel>

      <div class="product-rail-viewport">
        <div class="best-grid" data-product-track>
          <?php foreach (products_by_ids($bestselling['product_ids'] ?? []) as $product): ?>
            <?php render_product_card($product); ?>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</section>
