<?php
$shopStyleConfig = (array) (site_content()['shop_by_style'] ?? []);
$shopStyleItems = homepage_style_showcase_cards();
?>

<section class="shop-style-section" data-style-carousel data-reveal>
    <div class="container shop-style-container">
        <div class="section-header-row shop-style-header">
            <div class="shop-style-heading section-header-left" data-reveal>
                <span class="shop-style-kicker">Signature Settings</span>
                <div class="shop-style-title-row">
                    <span aria-hidden="true"></span>
                    <h2><?= h((string) ($shopStyleConfig['title'] ?? 'Shop by Style')) ?></h2>
                    <span aria-hidden="true"></span>
                </div>
            </div>
            <div class="section-nav-arrows">
                <button class="section-nav-btn" type="button" data-style-prev aria-label="Previous style"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
                <button class="section-nav-btn" type="button" data-style-next aria-label="Next style"><svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></svg></button>
            </div>
        </div>

        <div class="shop-style-shell">
            <div class="shop-style-viewport">
                <div class="shop-style-track" data-style-track>
                    <?php foreach ($shopStyleItems as $index => $item): ?>
                        <?php
                        $itemName = (string) ($item['label'] ?? 'Style');
                        $itemTypeLabel = (string) ($item['type_label'] ?? 'Jewellery');
                        $itemUrl = (string) ($item['url'] ?? '#');
                        $itemFallback = homepage_image_fallback('style', $itemName, $itemTypeLabel);
                        $itemImage = homepage_image_source($item['image'] ?? '', 'style', $itemName, $itemTypeLabel);
                        ?>
                        <a class="shop-style-card" href="<?= h($itemUrl) ?>" style="--style-index: <?= (int) $index ?>;">
                            <span class="shop-style-image">
                                <img src="<?= h($itemImage !== '' ? $itemImage : $itemFallback) ?>" data-image-fallback="<?= h($itemFallback) ?>" alt="<?= h($itemName) ?> style" loading="lazy" decoding="async">
                            </span>
                            <span class="shop-style-copy">
                                <span class="shop-style-name"><?= h($itemName) ?></span>
                                <span class="shop-style-tagline"><?= h($itemTypeLabel) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
