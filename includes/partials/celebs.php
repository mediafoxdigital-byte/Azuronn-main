<?php $celebs = site_content()['celebs']; ?>
<div class="section-wrap celebs-section">
  <div class="sec-hdr-premium" data-reveal>
    <span class="shop-style-kicker">Our Stars</span>
    <div class="sec-hdr-title-row">
        <span class="sec-line"></span>
        <h2><?php e($celebs['title']); ?></h2>
        <span class="sec-line"></span>
    </div>
  </div>

  <div class="celebs-carousel" data-celebs-carousel>
    <div class="celebs-track" data-celebs-track>
      <?php foreach ($celebs['items'] as $celeb): ?>
        <article class="celeb-card">
          <img src="<?php e($celeb['image']); ?>" alt="<?php e($celeb['name']); ?>" loading="lazy" decoding="async">
          <div class="celeb-name"><?php e($celeb['name']); ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</div>
