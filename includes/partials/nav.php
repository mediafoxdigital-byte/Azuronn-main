<?php $navItems = site_content()['navigation']['items']; ?>
<?php
  // The burger and the scrim sit OUTSIDE <nav> on purpose: below 1025px the nav
  // itself becomes an off-canvas panel that is translated off-screen, so a
  // toggle nested inside it would be unreachable while closed. Both are
  // display:none at desktop widths (see responsive.css) and therefore have no
  // effect on the laptop/desktop header.
?>
<button class="mnav-toggle" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="primary-nav" data-mobile-nav-toggle>
  <span></span>
  <span></span>
  <span></span>
</button>
<div class="mnav-scrim" data-mobile-nav-scrim></div>
<nav id="primary-nav" class="mnav luxury-mnav" data-mobile-nav style="background:transparent; border:none; padding:0; width:100%;">
  <div class="mnav-drawer-head" aria-hidden="true">
    <img src="<?php e(resolve_link(SITE_LOGO_PATH)); ?>" alt="<?php e(SITE_NAME . ' Logo'); ?>" loading="lazy" decoding="async">
  </div>
  <form class="mnav-drawer-search" action="<?php e(resolve_link('/shop/')); ?>" method="get" role="search">
    <input type="search" name="q" placeholder="Search jewellery..." autocomplete="off" aria-label="Search catalogue">
    <button type="submit" aria-label="Search">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
    </button>
  </form>
  <div class="d-flex-nav" style="width:100%; display:flex; justify-content:center;">
    <div class="luxury-mnav-pill" style="box-shadow:none; background:transparent; padding:0; border:none;">


      <?php
      foreach ($navItems as $index => $item):
        $navLabelUpper = strtoupper((string) ($item['label'] ?? ''));
        $isRingMega = in_array($navLabelUpper, ['ENGAGEMENT RINGS', 'WEDDING RINGS', 'RINGS'], true);
        $navRingSection = $navLabelUpper === 'WEDDING RINGS' ? 'wedding' : 'engagement';
      ?>

        <div class="mnav-item has-mega">
          <a href="<?php e(resolve_link($item['url'])); ?>" class="" data-mobile-nav-link><?php e($item['label']); ?> <?php if (!empty($item['columns']) || $isRingMega): ?><i class="fas fa-chevron-down" style="font-size:0.7em; color:#c9a96e; margin-left:4px;"></i><?php endif; ?><span class="nav-underline"></span></a>
          <button class="mnav-item-toggle" type="button" aria-label="Toggle <?php e($item['label']); ?> menu" aria-expanded="false" data-mobile-submenu-toggle>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
          </button>
          <?php if ($isRingMega): ?>
            <?php
              $styles = array_values(available_ring_style_cards($navRingSection));
              $shapes = site_content()['diamond_shapes']['items'] ?? [];
              $ringSectionQuery = ['type' => 'Ring', 'ring_category' => $navRingSection];
              // "Shop by Metal" shows the metals the admin created for rings (the
              // Ring attribute profile — the same source the product page and the
              // shop metal filter use), NOT the products' base colour field, which
              // produced a fixed list (e.g. "Yellow Gold") unrelated to the metals
              // actually offered. Capped at 4 like the other mega columns. Each
              // chip's circle uses the colour the admin picked (color_hex); when no
              // colour is set it falls back to a metallic tone by name so the
              // circle is never blank.
              $ringProfile = catalog_attribute_profile(ring_section_profile_type($navRingSection));
              $ringMetalOptions = $ringProfile['option_metal_options'] ?? [];
              $navMetalTone = static function (string $name): string {
                  $n = strtolower($name);
                  if (str_contains($n, 'rose')) return '#d8a48f';
                  if (str_contains($n, 'white') || str_contains($n, 'platinum') || str_contains($n, 'silver')) return '#cfcfcf';
                  if (str_contains($n, 'yellow') || str_contains($n, 'gold')) return '#cda434';
                  return '#c9a96e';
              };
              $navRingMetals = [];
              foreach (array_slice(array_values($ringMetalOptions), 0, 4) as $navMetalOpt) {
                  $navMetalLabel = clean_string((string) ($navMetalOpt['label'] ?? ''), 120);
                  if ($navMetalLabel === '') {
                      continue;
                  }
                  $navMetalHex = clean_string((string) ($navMetalOpt['color_hex'] ?? ''), 7);
                  $navRingMetals[] = [
                      'label' => $navMetalLabel,
                      'hex' => preg_match('/^#[0-9a-fA-F]{6}$/', $navMetalHex) ? $navMetalHex : $navMetalTone($navMetalLabel),
                  ];
              }
              // Build each mega-menu column once, then compose per section so the
              // admin-driven Style / Metal columns stay single-source. Wedding leads
              // with two large gender hero boxes (no Shape, no feature card); the
              // engagement menu keeps Style + Shape + Metal + feature card.
              ob_start();
            ?>
                <div class="mega-col mega-col-style">
                  <div class="mega-col-title">SHOP BY STYLE</div>
                  <?php foreach (array_slice($styles, 0, 4) as $style): ?>
                    <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery + ['style' => $style['value']]))); ?>" class="mega-link-with-image">
                      <div class="img-wrap"><img src="<?php e($style['image']); ?>" alt="<?php e($style['label']); ?>" loading="lazy" decoding="async"></div>
                      <span><?php e($style['label']); ?></span>
                    </a>
                  <?php endforeach; ?>
                  <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery))); ?>" class="mega-show-all-btn">Show All Styles</a>
                </div>
            <?php $megaStyleCol = ob_get_clean(); ob_start(); ?>
                <div class="mega-col mega-col-collections mega-col-metal">
                  <div class="mega-col-title">SHOP BY METAL</div>
                  <?php foreach ($navRingMetals as $navMetal): ?>
                    <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery + ['color' => $navMetal['label']]))); ?>" class="mega-metal-link">
                      <span class="mega-metal-disc" style="background-color: <?= h($navMetal['hex']); ?>;"></span>
                      <span class="mega-metal-text">
                        <span class="mega-metal-name"><?php e($navMetal['label']); ?></span>
                      </span>
                      <i class="fas fa-chevron-right mega-metal-chevron" aria-hidden="true"></i>
                    </a>
                  <?php endforeach; ?>
                  <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery))); ?>" class="mega-show-all-btn">Show All Metals</a>
                </div>
            <?php $megaMetalCol = ob_get_clean(); ob_start(); ?>
                <div class="mega-col mega-col-shape">
                  <div class="mega-col-title">SHOP BY SHAPE</div>
                  <?php foreach (array_slice($shapes, 0, 4) as $navShape): ?>
                    <?php
                      // Scope each shape chip to this ring section (engagement) so the
                      // mega-menu lands on the right filtered collection, not all rings.
                      parse_str((string) parse_url((string) ($navShape['url'] ?? ''), PHP_URL_QUERY), $navShapeQuery);
                      $navShapeSlug = (string) ($navShapeQuery['shape'] ?? '');
                      if ($navShapeSlug === '') {
                          $navShapeSlug = content_slug((string) ($navShape['name'] ?? ''), '');
                      }
                    ?>
                    <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery + ['shape' => $navShapeSlug]))); ?>" class="mega-link-with-image">
                      <div class="img-wrap shape-img-wrap"><img src="<?php e($navShape['icon_image'] ?: $navShape['image']); ?>" alt="<?php e($navShape['name']); ?>" loading="lazy" decoding="async"></div>
                      <span><?php e($navShape['name']); ?></span>
                    </a>
                  <?php endforeach; ?>
                  <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery))); ?>" class="mega-show-all-btn">Show All Shapes</a>
                </div>
            <?php $megaShapeCol = ob_get_clean(); ob_start(); ?>
                <div class="mega-col mega-col-feature-card">
                  <div class="feature-card-inner">
                    <div class="feature-img">
                      <img src="/assets/uploads/featured-diamond-ring.jpg" alt="Diamond Rings" loading="lazy" decoding="async">
                    </div>
                    <div class="feature-content">
                      <h3>Diamond Rings</h3>
                      <p><?php e($item['feature']['subtitle'] ?? 'Discover ring styles, shapes, and custom design options in one beautiful collection.'); ?></p>
                      <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery))); ?>" class="btn-explore"><span>EXPLORE COLLECTION</span> <span class="btn-explore-arrow">&rarr;</span></a>
                    </div>
                  </div>
                </div>
            <?php $megaFeatureCol = ob_get_clean(); ob_start(); ?>
                <?php foreach (ring_gender_box_cards() as $navGenderCard): ?>
                <div class="wedding-mega-boxcol">
                  <a href="<?php e(resolve_link('/shop/?' . http_build_query($ringSectionQuery + ['gender' => $navGenderCard['key']]))); ?>" class="wedding-mega-box">
                    <div class="wedding-mega-box-img"><img src="<?php e($navGenderCard['image']); ?>" alt="<?php e($navGenderCard['label']); ?>" loading="lazy" decoding="async"></div>
                    <div class="wedding-mega-box-title"><?php e($navGenderCard['label']); ?></div>
                  </a>
                </div>
                <?php endforeach; ?>
            <?php $megaBoxCols = ob_get_clean(); ?>
            <div class="mega-drop mega-drop-wide">
              <div class="mega-inner luxury-mega-menu <?= $navRingSection === 'wedding' ? 'wedding-mega-menu' : ''; ?>"<?= $navRingSection === 'wedding' ? '' : ' style="grid-template-columns: 1fr 1fr 1fr 1.4fr;"'; ?>>
                <?php if ($navRingSection === 'wedding'): ?>
                  <?= $megaBoxCols ?>
                  <?= $megaStyleCol ?>
                  <?= $megaMetalCol ?>
                <?php else: ?>
                  <?= $megaStyleCol ?>
                  <?= $megaShapeCol ?>
                  <?= $megaMetalCol ?>
                  <?= $megaFeatureCol ?>
                <?php endif; ?>
              </div>
            </div>
          <?php elseif (strtoupper($item['label']) === 'JEWELLERY' || strtoupper($item['label']) === 'JEWELRY'): ?>
            <?php
              // The Jewellery menu is every category the merchant actually has
              // except the two protected ring categories, which have their own
              // nav items. Built from category_cards so a newly created category
              // appears here with no code change.
              $jewelleryFallbackImages = [
                'necklace' => '/assets/uploads/necklace_collection_bg.png',
                'pendant' => '/assets/uploads/pendant_collection_bg.png',
                'earring' => '/assets/uploads/earring_collection_bg.png',
                'bracelet' => '/assets/uploads/bracelet_collection_bg.png',
                'mangalsutra' => '/assets/uploads/mangalsutra_collection_bg.png',
              ];
              $jewelleryCategories = [];
              foreach (site_content()['category_cards'] as $jCard) {
                  $jTitle = trim((string) ($jCard['title'] ?? ''));
                  if ($jTitle === '' || catalog_category_ring_section($jTitle) !== '') {
                      continue;
                  }

                  $jType = catalog_canonical_type($jTitle);
                  // Merchant-created categories are stored with '#' because the
                  // Categories admin has no URL field, so route those to their
                  // own shop listing.
                  $jUrl = trim((string) ($jCard['url'] ?? ''));
                  if ($jUrl === '' || $jUrl === '#') {
                      $jUrl = $jType !== '' ? '/shop/?type=' . urlencode($jType) : '/shop/';
                  }

                  $jImage = clean_image((string) ($jCard['image'] ?? ''));
                  if ($jImage === '') {
                      $jImage = $jewelleryFallbackImages[strtolower($jType)] ?? '/assets/uploads/shop_collection_bg.png';
                  }

                  $jewelleryCategories[] = [
                    'label' => strtoupper($jTitle),
                    'url' => $jUrl,
                    'image' => $jImage,
                  ];
              }
            ?>
            <?php if ($jewelleryCategories !== []): ?>
            <div class="mega-drop mega-drop-wide">
              <div class="mega-inner luxury-mega-menu jewellery-grid-menu" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 24px; padding: 32px 36px;">
                <?php foreach ($jewelleryCategories as $jcat): ?>
                  <a href="<?php e(resolve_link($jcat['url'])); ?>" class="jewellery-card" style="text-decoration: none; display: flex; flex-direction: column;">
                    <div class="jewellery-card-img">
                      <img src="<?php e($jcat['image']); ?>" alt="<?php e($jcat['label']); ?>" loading="lazy" decoding="async">
                    </div>
                    <div class="jewellery-card-title"><?php e($jcat['label']); ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>
          <?php elseif (!empty($item['columns']) || !empty($item['feature']['title'])): ?>
            <div class="mega-drop <?= !empty($item['compact']) ? 'mega-drop-sm' : '' ?> mega-drop-wide">
              <?php 
                $colCount = count($item['columns'] ?? []);
                $gridCols = str_repeat('1fr ', $colCount) . (!empty($item['feature']['title']) ? ' 1.6fr' : '');
              ?>
              <div class="mega-inner luxury-mega-menu" style="grid-template-columns: <?= h(trim($gridCols)) ?>;">
                <?php foreach ($item['columns'] ?? [] as $column): ?>
                  <div class="mega-col">
                    <div class="mega-col-title"><?php e(strtoupper($column['title'] ?? '')); ?></div>
                    <?php foreach (array_slice($column['links'] ?? [], 0, 4) as $link): 
                      $lbl = strtolower($link['label'] ?? '');
                      $icon = 'far fa-gem';
                      if (strpos($lbl, 'band') !== false || strpos($lbl, 'ring') !== false || strpos($lbl, 'solitaire') !== false || strpos($lbl, 'sizing') !== false) {
                        $icon = 'fas fa-ring';
                      } elseif (strpos($lbl, 'necklace') !== false || strpos($lbl, 'pendant') !== false || strpos($lbl, 'chain') !== false) {
                        $icon = 'fas fa-award';
                      } elseif (strpos($lbl, 'earring') !== false || strpos($lbl, 'stud') !== false || strpos($lbl, 'hoop') !== false) {
                        $icon = 'far fa-dot-circle';
                      } elseif (strpos($lbl, 'bracelet') !== false || strpos($lbl, 'bangle') !== false || strpos($lbl, 'cuff') !== false) {
                        $icon = 'fas fa-circle-notch';
                      } elseif (strpos($lbl, 'gift') !== false || strpos($lbl, 'anniversary') !== false || strpos($lbl, 'birthday') !== false || strpos($lbl, 'bestsell') !== false || strpos($lbl, 'new') !== false) {
                        $icon = 'fas fa-gift';
                      } elseif (strpos($lbl, 'custom') !== false || strpos($lbl, 'bespoke') !== false || strpos($lbl, 'design') !== false) {
                        $icon = 'fas fa-magic';
                      } elseif (strpos($lbl, 'story') !== false || strpos($lbl, 'about') !== false || strpos($lbl, 'craft') !== false) {
                        $icon = 'fas fa-book-open';
                      } elseif (strpos($lbl, 'ethical') !== false || strpos($lbl, 'sourcing') !== false) {
                        $icon = 'fas fa-leaf';
                      } elseif (strpos($lbl, 'clean') !== false || strpos($lbl, 'maint') !== false || strpos($lbl, 'warranty') !== false) {
                        $icon = 'fas fa-shield-alt';
                      } elseif (strpos($lbl, 'contact') !== false || strpos($lbl, 'consult') !== false || strpos($lbl, 'appoint') !== false) {
                        $icon = 'far fa-calendar-check';
                      } elseif (strpos($lbl, 'faq') !== false || strpos($lbl, 'question') !== false) {
                        $icon = 'far fa-question-circle';
                      } elseif (strpos($lbl, 'ship') !== false || strpos($lbl, 'return') !== false) {
                        $icon = 'fas fa-shipping-fast';
                      }
                    ?>
                      <a href="<?php e(resolve_link($link['url'])); ?>" class="mega-link-with-image">
                        <div class="img-wrap"><i class="<?= $icon ?>" style="font-size:1.35rem; color:#c9a96e; transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);"></i></div>
                        <span><?php e($link['label']); ?></span>
                      </a>
                    <?php endforeach; ?>
                    <a href="<?php e(resolve_link($item['url'] ?? '/shop/')); ?>" class="mega-show-all-btn">Show All <?php e(str_ireplace(["Women's ", "Men's ", "Diamond ", "Fine ", "Our "], "", $column['title'])); ?></a>
                  </div>
                <?php endforeach; ?>
                <?php if (!empty($item['feature']['title'])): ?>
                  <div class="mega-col mega-col-feature-card">
                    <div class="feature-card-inner">
                      <div class="feature-img">
                        <img src="<?php e($item['feature']['image'] ?? ''); ?>" alt="<?php e($item['feature']['alt'] ?? ''); ?>" loading="lazy" decoding="async">
                      </div>
                      <div class="feature-content">
                        <i class="far fa-gem"></i>
                        <h3><?php e($item['feature']['title']); ?></h3>
                        <p><?php e($item['feature']['subtitle'] ?? ''); ?></p>
                        <a href="<?php e(resolve_link($item['url'] ?? '/shop/')); ?>" class="btn-explore"><span>EXPLORE COLLECTION</span> <span class="btn-explore-arrow">&rarr;</span></a>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
    // Links the compact tablet/phone header no longer has room for. Hidden at
    // desktop widths, so this adds nothing to the laptop layout.
  ?>
  <div class="mnav-drawer-foot">
    <a href="<?php e(resolve_link('/appointment/')); ?>"><i class="far fa-calendar-alt" aria-hidden="true"></i><span>Request an appointment</span></a>
    <a href="<?php e(resolve_link('/wishlist/')); ?>"><i class="far fa-heart" aria-hidden="true"></i><span>Wishlist</span></a>
    <a href="<?php e(resolve_link('/account/')); ?>"><i class="far fa-user" aria-hidden="true"></i><span>My account</span></a>
  </div>
</nav>
