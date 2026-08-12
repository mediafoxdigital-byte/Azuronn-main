<?php $reviews = site_content()['reviews']; ?>
<section class="reviews-marquee-section">
  <div class="container">
    <div class="reviews-marquee-header" data-reveal style="text-align:center;">
      <span class="reviews-marquee-kicker"><?= h($reviews['eyebrow'] ?? 'Client Love') ?></span>
      <h2>Why Clients Choose <em>Azuronn</em></h2>
    </div>
  </div>

  <?php
    $allItems = $reviews['items'] ?? [];
    // Duplicate items for seamless infinite scroll
    $marqueeItems = array_merge($allItems, $allItems);
  ?>

  <!-- Row 1: scrolls left -->
  <div class="reviews-marquee-container">
    <div class="reviews-marquee-track reviews-marquee-left">
      <?php foreach ($marqueeItems as $item): ?>
        <div class="reviews-marquee-card">
          <div class="reviews-marquee-card-top">
            <div class="reviews-marquee-avatar">
              <?= strtoupper(mb_substr(h($item['author'] ?? 'A'), 0, 1)) ?>
            </div>
            <div class="reviews-marquee-meta">
              <strong><?= h($item['author'] ?? '') ?></strong>
              <span><?= h($item['meta'] ?? 'Verified Buyer') ?></span>
            </div>
          </div>
          <p class="reviews-marquee-text">"<?= h($item['excerpt'] ?? '') ?>"</p>
          <div class="reviews-marquee-stars">
            <?php for ($s = 1; $s <= ($item['rating'] ?? 5); $s++): ?>★<?php endfor; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Row 2: scrolls right -->
  <div class="reviews-marquee-container">
    <div class="reviews-marquee-track reviews-marquee-right">
      <?php foreach (array_reverse($marqueeItems) as $item): ?>
        <div class="reviews-marquee-card">
          <div class="reviews-marquee-card-top">
            <div class="reviews-marquee-avatar">
              <?= strtoupper(mb_substr(h($item['author'] ?? 'A'), 0, 1)) ?>
            </div>
            <div class="reviews-marquee-meta">
              <strong><?= h($item['author'] ?? '') ?></strong>
              <span><?= h($item['meta'] ?? 'Verified Buyer') ?></span>
            </div>
          </div>
          <p class="reviews-marquee-text">"<?= h($item['excerpt'] ?? '') ?>"</p>
          <div class="reviews-marquee-stars">
            <?php for ($s = 1; $s <= ($item['rating'] ?? 5); $s++): ?>★<?php endfor; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
