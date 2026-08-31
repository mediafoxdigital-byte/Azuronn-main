<?php
$gallery = site_content()['social_gallery'] ?? [];
$items = is_array($gallery['items'] ?? null) ? $gallery['items'] : [];

if (empty($items)) {
    return;
}
?>

<section class="social-gallery-section" style="padding: 60px 0; background-color: transparent;">
    <div class="container">
        <div class="section-header-row" style="margin-bottom: 20px;">
            <div class="section-header-left" style="text-align: center;">
                <div class="section-title">
                    <h2><?= h($gallery['title'] ?? 'Say "Yes" with Azuronn') ?></h2>
                </div>
            </div>
            <div class="section-nav-arrows">
                <button class="section-nav-btn" type="button" data-marquee-prev aria-label="Scroll left"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
                <button class="section-nav-btn" type="button" data-marquee-next aria-label="Scroll right"><svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></svg></button>
            </div>
        </div>
    </div>
    
    <div class="social-gallery-marquee-container">
        <div class="social-gallery-marquee-track">
            <?php
            // Duplicate the items so the scrolling animation can loop seamlessly
            $marqueeItems = array_merge($items, $items);
            foreach ($marqueeItems as $item): 
                $image = $item['image'] ?? '';
                if (empty($image)) continue;
                $username = $item['username'] ?? '';
                $alt = $item['alt'] ?? '';
            ?>
                <div class="social-gallery-item">
                    <img src="<?= h($image) ?>" alt="<?= h($alt) ?>" loading="lazy" decoding="async">
                    <?php if ($username): ?>
                        <div class="social-gallery-username">
                            <i class="fab fa-instagram"></i> <?= h($username) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
