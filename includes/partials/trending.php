<?php $trending = site_content()['trending']; ?>
<div class="premium-cta-banner">
  <div class="cta-overlay"></div>
  <div class="container cta-container">
    <div class="cta-content-box" data-reveal>
      <div class="cta-kicker"><?php e($trending['sale']); ?></div>
      <h2 class="cta-title"><?php e($trending['title']); ?></h2>
      <div class="cta-diamond-divider"><i class="far fa-gem"></i></div>
      <p class="cta-sub"><?php e($trending['subtitle']); ?></p>
      <a href="<?php e(resolve_link($trending['cta_url'])); ?>" class="btn-cta-gold">
        <?php e($trending['cta_label']); ?> <i class="fas fa-chevron-right" style="font-size: 0.8em; margin-left: 8px;"></i>
      </a>
    </div>
  </div>
</div>
