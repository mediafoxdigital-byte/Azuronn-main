<?php
$diamondSection = site_content()['diamond_shapes'];
$diamondShapes = $diamondSection['items'];
$firstShape = $diamondShapes[0] ?? null;

$shapeCollectionUrl = static function (array $shape): string {
    $sourceUrl = (string) ($shape['url'] ?? '');
    $queryString = (string) parse_url($sourceUrl, PHP_URL_QUERY);
    $shapeQuery = [];
    parse_str($queryString, $shapeQuery);

    $slug = trim((string) ($shapeQuery['shape'] ?? ''), '/');
    if ($slug === '') {
        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $slug = trim((string) basename($path), '/');
    }
    if ($slug === '') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) ($shape['name'] ?? 'diamond')) ?? 'diamond');
        $slug = trim($slug, '-');
    }

    return resolve_link('/shop/?type=Ring&ring_category=engagement&shape=' . rawurlencode($slug));
};
?>
<?php if ($firstShape !== null): ?>
<?php $firstShapeDisplay = str_contains((string) ($firstShape['image'] ?? ''), '/assets/uploads/diamond-shapes/') ? 'lifestyle' : 'gem'; ?>
<section class="container diamond-shape-section" style="margin-top: 60px; margin-bottom: 60px;" data-reveal>
    <div class="sec-hdr-premium" data-reveal>
        <span class="shop-style-kicker">Start With a Shape</span>
        <div class="sec-hdr-title-row">
            <span class="sec-line"></span>
            <h2><?php e($diamondSection['title']); ?></h2>
            <span class="sec-line"></span>
        </div>
    </div>
    <div class="diamond-shape-layout minimal-layout">
        <div class="diamond-shape-controls minimal-controls" data-reveal>
            <?php foreach ($diamondShapes as $index => $shape): ?>
                <a
                    href="<?= h($shapeCollectionUrl($shape)) ?>"
                    class="minimal-chip"
                >
                    <span class="minimal-gem">
                        <img src="<?= h($shape['icon_image'] ?: $shape['image']) ?>" alt="<?= h($shape['name']) ?> icon" loading="lazy">
                    </span>
                    <span class="minimal-text">
                        <?= h($shape['name']) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
