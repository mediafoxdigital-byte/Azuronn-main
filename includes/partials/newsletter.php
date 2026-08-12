<?php
$newsletter = site_content()['newsletter'] ?? [];
$image = !empty($newsletter['image']) ? h($newsletter['image']) : asset_url('assets/uploads/premium_ring_box_transparent.png');
?>
<div class="custom-banner-section" id="our-newsletter" style="margin: 60px 0;">
  <div class="container" style="max-width: 1500px;">
    <div class="banner-container" style="width: 100%; height: 550px; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08); background: #f6f3ed; position: relative;">
      <?php if (function_exists('media_asset_type') && media_asset_type($image) === 'video'): ?>
          <video src="<?= $image ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; display: block;" autoplay loop muted playsinline></video>
      <?php else: ?>
          <img src="<?= $image ?>" alt="Banner" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; display: block;">
      <?php endif; ?>
    </div>
  </div>
</div>
