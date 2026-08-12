<?php $hero = site_content()['hero']; ?>
<section class="hero">
  <?php if (media_asset_type($hero['image']) === 'video'): ?>
    <video class="hero-video" src="<?php e($hero['image']); ?>" autoplay loop muted playsinline></video>
  <?php else: ?>
    <div class="hero-bg" style="background-image: url('<?php e($hero['image']); ?>');"></div>
  <?php endif; ?>
  <div class="hero-overlay"></div>
  <div class="container hero-container">
    <div class="hero-content">
      <p class="hero-offer"><?php e($hero['offer']); ?></p>
      <h1 class="hero-title"><?php e($hero['title']); ?></h1>
      <p class="hero-price"><?php e($hero['price_prefix']); ?> <span class="hero-amt"><?php e($hero['price_value']); ?></span></p>
    </div>
  </div>
</section>
