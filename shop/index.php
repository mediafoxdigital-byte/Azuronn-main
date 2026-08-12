<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$content = site_content();
$products = catalog_expanded_products();
$productTypes = $content['catalog_meta']['product_types'] ?? [];

// Wishlist toggle posted from a listing card's heart button. Returns the visitor
// to the exact filtered listing they were on (current_internal_url keeps the query).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'toggle-wishlist') {
    if (!csrf_verify()) {
        site_flash_set('error', 'Session expired. Please try again.');
    } else {
        $wishCustomer = current_customer();
        if ($wishCustomer === null) {
            site_flash_set('error', 'Sign in to save products to your wishlist.');
            redirect(resolve_link('/account/login/?next=' . urlencode(current_internal_url('/shop/'))));
        }
        $wishId = clean_string((string) ($_POST['product_id'] ?? ''), 80);
        if ($wishId !== '') {
            $wishResult = customer_toggle_wishlist($wishId);
            site_flash_set(($wishResult['ok'] ?? false) ? 'success' : 'error', (string) ($wishResult['message'] ?? 'Unable to update wishlist.'));
        }
    }
    redirect(current_internal_url('/shop/'));
}


$filters = [
    'q' => sanitize_text((string) ($_GET['q'] ?? '')),
    'type' => sanitize_text((string) ($_GET['type'] ?? '')),
    'ring_category' => sanitize_text((string) ($_GET['ring_category'] ?? '')),
    'gender' => sanitize_text((string) ($_GET['gender'] ?? '')),
    'color' => sanitize_text((string) ($_GET['color'] ?? '')),
    'category' => sanitize_text((string) ($_GET['category'] ?? '')),
    'shape' => sanitize_text((string) ($_GET['shape'] ?? '')),
    'style' => sanitize_text((string) ($_GET['style'] ?? '')),
    'facet' => sanitize_text((string) ($_GET['facet'] ?? '')),
    'sort' => sanitize_text((string) ($_GET['sort'] ?? 'featured')),
    'price' => sanitize_text((string) ($_GET['price'] ?? '')),
];

$categoryTypeAliases = [
    'ring' => 'Ring',
    'rings' => 'Ring',
    'earring' => 'Earring',
    'earrings' => 'Earring',
    'pendant' => 'Pendant',
    'pendants' => 'Pendant',
    'bracelet' => 'Bracelet',
    'bracelets' => 'Bracelet',
    'bangles' => 'Bracelet',
    'bangles & bracelets' => 'Bracelet',
    'necklace' => 'Necklace',
    'necklaces' => 'Necklace',
    'neckless' => 'Necklace',
    'necklesses' => 'Necklace',
];

$collectionMeta = [
    'Ring' => [
        'title' => 'Rings',
        'description' => 'Explore every ring design in one place, from solitaire and halo to vintage, toi et moi, sidestones, and modern signature styles.',
    ],
    'Earring' => [
        'title' => 'Earrings',
        'description' => 'Discover refined everyday studs, drops, and statement earring silhouettes curated for gifting and every occasion wear.',
    ],
    'Pendant' => [
        'title' => 'Pendants',
        'description' => 'Browse pendant styles designed for layering, gifting, and effortless daily elegance.',
    ],
    'Bracelet' => [
        'title' => 'Bangles & Bracelets',
        'description' => 'Explore bracelet and bangle designs with clean lines, polished finishes, and timeless styling.',
    ],
    'Necklace' => [
        'title' => 'Necklaces',
        'description' => 'View necklace styles crafted to add statement, sparkle, and soft layering across every occasion.',
    ],
    'Mangalsutra' => [
        'title' => 'Mangalsutra',
        'description' => 'Explore mangalsutra-inspired designs with a refined jewellery presentation and a dedicated collection landing experience.',
    ],
];

$namedQueryCollections = [];

$collectionTypeGroups = [
    'Ring'     => ['Ring', 'Rings'],
    'Rings'    => ['Ring', 'Rings'],
    'Earring'  => ['Earring', 'Earrings'],
    'Earrings' => ['Earring', 'Earrings'],
    'Bracelet'         => ['Bracelet', 'Bangles & Bracelets'],
    'Bangles & Bracelets' => ['Bracelet', 'Bangles & Bracelets'],
    'Pendant'  => ['Pendant', 'Pendants', 'Jewellery Set', 'Brooch'],
    'Pendants' => ['Pendant', 'Pendants', 'Jewellery Set', 'Brooch'],
    'Necklace' => ['Necklace', 'Necklaces'],
    'Necklaces'=> ['Necklace', 'Necklaces'],
];

$normalizedQuery = strtolower($filters['q']);
if ($filters['type'] === '' && isset($categoryTypeAliases[$normalizedQuery])) {
    $filters['type'] = $categoryTypeAliases[$normalizedQuery];
    $filters['q'] = '';
}

$normalizedType = strtolower($filters['type']);
if ($normalizedType !== '' && isset($categoryTypeAliases[$normalizedType])) {
    $filters['type'] = $categoryTypeAliases[$normalizedType];
}

// Ring section aliases: "engagement rings" / "wedding rings" as a type value (or
// search query) resolve to type=Ring + the matching ring_category, so links like
// /shop/?type=Engagement+Rings work alongside the canonical query-param scheme.
$ringSectionTypeAliases = [
    'engagement ring' => 'engagement',
    'engagement rings' => 'engagement',
    'wedding ring' => 'wedding',
    'wedding rings' => 'wedding',
];
if (isset($ringSectionTypeAliases[$normalizedType])) {
    $filters['type'] = 'Ring';
    if ($filters['ring_category'] === '') {
        $filters['ring_category'] = $ringSectionTypeAliases[$normalizedType];
    }
} elseif ($filters['type'] === '' && $filters['ring_category'] === '' && isset($ringSectionTypeAliases[$normalizedQuery])) {
    $filters['type'] = 'Ring';
    $filters['ring_category'] = $ringSectionTypeAliases[$normalizedQuery];
    $filters['q'] = '';
}

// Whitelist the ring taxonomy params so only known values reach filtering/URLs.
$filters['ring_category'] = in_array(strtolower($filters['ring_category']), ['engagement', 'wedding'], true)
    ? strtolower($filters['ring_category'])
    : '';
$filters['gender'] = in_array(strtolower($filters['gender']), ['mens', 'womens'], true)
    ? strtolower($filters['gender'])
    : '';
// Gender only has meaning inside the wedding section.
if ($filters['ring_category'] !== 'wedding') {
    $filters['gender'] = '';
}

$ringStyles = available_ring_styles($filters['ring_category']);
$ringStyleCards = available_ring_style_cards($filters['ring_category']);
$allowedTypes = $filters['type'] !== '' ? ($collectionTypeGroups[$filters['type']] ?? [$filters['type']]) : [];
if ($filters['type'] === 'Ring') {
    $normalizedStyle = strtolower($filters['style']);
    foreach ($ringStyles as $styleKey => $styleLabel) {
        if ($normalizedStyle === $styleKey || $normalizedStyle === strtolower($styleLabel)) {
            $filters['style'] = $styleKey;
            break;
        }
    }

    if ($filters['style'] === '' && $filters['q'] !== '') {
        foreach ($ringStyles as $styleKey => $styleLabel) {
            if ($normalizedQuery === $styleKey || $normalizedQuery === strtolower($styleLabel)) {
                $filters['style'] = $styleKey;
                $filters['q'] = '';
                break;
            }
        }
    }
}

$catalogFilters = $filters;
$catalogFilters['type'] = '';
$catalogFilters['facet'] = '';
$catalogFilters['style'] = '';
$catalogFilters['ring_category'] = '';
$catalogFilters['gender'] = '';
// Color is applied inline below (after the metal option list is built from the
// section-filtered products) so the dropdown keeps offering every metal present
// in the current section even while one is selected.
$catalogFilters['color'] = '';
$filteredProducts = filter_catalog_products($products, $catalogFilters);
if ($allowedTypes !== []) {
    $allowedTypesLower = array_map('strtolower', $allowedTypes);
    $filteredProducts = array_values(array_filter($filteredProducts, static function (array $product) use ($allowedTypesLower): bool {
        return in_array(strtolower((string) ($product['product_type'] ?? '')), $allowedTypesLower, true);
    }));
}
$showRingJourney = $filters['type'] === 'Ring';

// Ring section passes: narrow the ring listing to Engagement / Wedding and,
// inside Wedding, to Men's / Women's. Legacy products without explicit fields
// are classified by product_ring_taxonomy() inference.
if ($showRingJourney && $filters['ring_category'] !== '') {
    $requestedSection = $filters['ring_category'];
    $filteredProducts = array_values(array_filter($filteredProducts, static function (array $product) use ($requestedSection): bool {
        return product_ring_taxonomy($product)['category'] === $requestedSection;
    }));
}
if ($showRingJourney && $filters['gender'] !== '') {
    $requestedGender = $filters['gender'];
    $filteredProducts = array_values(array_filter($filteredProducts, static function (array $product) use ($requestedGender): bool {
        // Strict match: untagged wedding rings (gender '') belong to "All Wedding
        // Rings" only, never to the Men's / Women's views.
        return product_ring_taxonomy($product)['gender'] === $requestedGender;
    }));
}
$isSearch = $filters['q'] !== '';
$showPremiumHero = true;
$premiumHeroCategory = $filters['type'] !== '' ? $filters['type'] : ($isSearch ? 'Search' : 'Shop');

// The category card behind this listing, when the merchant created one. Its
// title, "Other Text" and image drive the hero so a new category gets a real
// hero instead of the generic "Shop Jewellery" one. Matched on the canonical
// type so /shop/?type=Earring finds a card titled "earrings".
$heroCategoryCard = null;
if ($filters['type'] !== '' && !$showRingJourney) {
    foreach (site_content()['category_cards'] as $shopCard) {
        $shopCardTitle = trim((string) ($shopCard['title'] ?? ''));
        if ($shopCardTitle === '' || catalog_category_ring_section($shopCardTitle) !== '') {
            continue;
        }
        if (strcasecmp(catalog_canonical_type($shopCardTitle), $filters['type']) === 0) {
            $heroCategoryCard = $shopCard;
            break;
        }
    }
}

$premiumHeroBgs = [
    'Ring' => '/assets/uploads/ring_collection_bg.png',
    'Earring' => '/assets/uploads/earring_collection_bg.png',
    'Pendant' => '/assets/uploads/pendant_collection_bg.png',
    'Bracelet' => '/assets/uploads/bracelet_collection_bg.png',
    'Necklace' => '/assets/uploads/necklace_collection_bg.png',
    'Mangalsutra' => '/assets/uploads/mangalsutra_collection_bg.png',
    'Search' => '/assets/uploads/shop_collection_bg.png',
    'Shop' => '/assets/uploads/shop_collection_bg.png',
];
$premiumBgUrl = $premiumHeroBgs[$premiumHeroCategory] ?? $premiumHeroBgs['Shop'];
// The category's own hero image wins outright. Without one the built-in
// collection background stands, falling back to the generic shop image. The
// card image is deliberately not used here — it is the small square thumbnail
// for the homepage strip, not a wide hero backdrop.
$heroCardImage = clean_image((string) ($heroCategoryCard['hero_image'] ?? ''));
if ($heroCardImage !== '') {
    $premiumBgUrl = $heroCardImage;
}

if ($showRingJourney && $filters['style'] !== '') {
    $filteredProducts = array_values(array_filter($filteredProducts, static function (array $product) use ($filters): bool {
        return in_array($filters['style'], (array) ($product['styles'] ?? []), true);
    }));
}

$selectorItems = !$showRingJourney && $filters['type'] !== '' ? available_collection_selector_cards($filters['type']) : [];
if (!$showRingJourney && $filters['facet'] !== '' && isset($selectorItems[$filters['facet']])) {
    $facetProductIds = $selectorItems[$filters['facet']]['product_ids'] ?? [];
    $facetNeedle = strtolower($filters['facet']);
    $facetLabel = strtolower((string) ($selectorItems[$filters['facet']]['label'] ?? ''));
    $filteredProducts = array_values(array_filter($filteredProducts, static function (array $product) use ($facetProductIds, $facetNeedle, $facetLabel): bool {
        if (in_array((string) ($product['id'] ?? ''), $facetProductIds, true)) {
            return true;
        }

        $productTags = array_map(static function (mixed $tag): string {
            return strtolower(trim((string) $tag));
        }, (array) ($product['subcategories'] ?? []));

        return in_array($facetNeedle, $productTags, true) || in_array($facetLabel, $productTags, true);
    }));
}

// Metal filter options scoped to the current listing (type + ring section +
// gender), so narrow views like Men's Wedding only offer metals that actually
// have results. The options are the metals each product is genuinely available
// in — the SAME set the product-card hover dots and the product-page selector
// show (matrix metals, else colour choices, else the base colour) — so the
// dropdown lists every real metal on the page and selecting one always returns
// the pieces offered in it. Building from the single base `color` field (as
// before) listed labels like "Yellow Gold" that no ring is filtered by, and a
// real metal such as "Gold" matched nothing, so the filter appeared broken.
$sectionProductsForMetals = $filteredProducts;
if ($showRingJourney && $filters['style'] !== '') {
    $sectionProductsForMetals = array_values(array_filter($sectionProductsForMetals, static function (array $product) use ($filters): bool {
        return in_array($filters['style'], (array) ($product['styles'] ?? []), true);
    }));
}

$productMetalLabels = static function (array $product): array {
    if (!function_exists('product_option_data')) {
        $base = (string) ($product['color'] ?? '');
        return $base !== '' ? [$base] : [];
    }
    $opt = product_option_data($product);
    if (!empty($opt['is_matrix_product']) && !empty($opt['metal_options'])) {
        $labels = array_map(static fn ($m): string => (string) ($m['label'] ?? ''), (array) $opt['metal_options']);
    } elseif (!empty($opt['color_choices'])) {
        $labels = array_map(static fn ($c): string => (string) ($c['label'] ?? ''), (array) $opt['color_choices']);
    } else {
        $base = (string) ($product['color'] ?? '');
        $labels = $base !== '' ? [$base] : [];
    }
    return array_values(array_filter($labels, static fn ($l): bool => $l !== ''));
};

$metalLabelMemo = [];
$productColorSet = [];
foreach ($sectionProductsForMetals as $sectionProduct) {
    $sectionProductId = (string) ($sectionProduct['id'] ?? '');
    $sectionLabels = $productMetalLabels($sectionProduct);
    if ($sectionProductId !== '') {
        $metalLabelMemo[$sectionProductId] = $sectionLabels;
    }
    foreach ($sectionLabels as $sectionLabel) {
        $productColorSet[$sectionLabel] = true;
    }
}
$productColors = array_keys($productColorSet);
sort($productColors);

// Apply the selected metal now that the option list is built. A product matches
// when the chosen metal is one it is available in (same resolution as above).
// The product's base colour is also accepted so existing metal links that carry
// the base colour — e.g. the navigation "Shop by Metal" chips — keep returning
// results instead of an empty page.
if ($filters['color'] !== '') {
    $requestedMetal = $filters['color'];
    $filteredProducts = array_values(array_filter($filteredProducts, static function (array $product) use ($requestedMetal, $productMetalLabels, &$metalLabelMemo): bool {
        $productId = (string) ($product['id'] ?? '');
        $labels = $metalLabelMemo[$productId] ?? ($metalLabelMemo[$productId] = $productMetalLabels($product));
        foreach ($labels as $label) {
            if (strcasecmp($label, $requestedMetal) === 0) {
                return true;
            }
        }
        return strcasecmp((string) ($product['color'] ?? ''), $requestedMetal) === 0;
    }));
}

// Price bucket filter. Ranges are fixed, store-appropriate bands; only bands that
// actually contain products in the current type/section (plus the currently
// selected band) are offered, so the dropdown never lists empty ranges. The base
// set here has every filter applied except price, so choosing a band cannot hide
// the other bands from the dropdown.
$allPriceBuckets = [
    '0-75' => ['label' => 'Under £75', 'min' => 0.0, 'max' => 75.0],
    '75-150' => ['label' => '£75 – £150', 'min' => 75.0, 'max' => 150.0],
    '150-300' => ['label' => '£150 – £300', 'min' => 150.0, 'max' => 300.0],
    '300-600' => ['label' => '£300 – £600', 'min' => 300.0, 'max' => 600.0],
    '600+' => ['label' => '£600 & above', 'min' => 600.0, 'max' => null],
];
$shopPriceValue = static function (array $product): ?float {
    $raw = preg_replace('/[^0-9.]/', '', (string) ($product['new_price'] ?? $product['old_price'] ?? '')) ?? '';
    return $raw !== '' ? (float) $raw : null;
};
$priceBucketOptions = [];
foreach ($allPriceBuckets as $pbKey => $pb) {
    $hasProduct = false;
    foreach ($filteredProducts as $pbProduct) {
        $pv = $shopPriceValue($pbProduct);
        if ($pv === null) {
            continue;
        }
        if ($pv >= $pb['min'] && ($pb['max'] === null || $pv < $pb['max'])) {
            $hasProduct = true;
            break;
        }
    }
    if ($hasProduct || $filters['price'] === $pbKey) {
        $priceBucketOptions[$pbKey] = $pb;
    }
}
if ($filters['price'] !== '' && isset($allPriceBuckets[$filters['price']])) {
    $pbSel = $allPriceBuckets[$filters['price']];
    $filteredProducts = array_values(array_filter($filteredProducts, static function (array $product) use ($pbSel, $shopPriceValue): bool {
        $pv = $shopPriceValue($product);
        if ($pv === null) {
            return false;
        }
        return $pv >= $pbSel['min'] && ($pbSel['max'] === null || $pv < $pbSel['max']);
    }));
}

$heroTitle = 'Shop Jewellery';
$heroDescription = 'Browse the current collection and refine by type, metal colour, style, and shape.';
$breadcrumbLabel = 'Shop';

if ($showRingJourney) {
    $ringSectionLabel = ring_section_label($filters['ring_category'], $filters['gender']);
    $ringSectionDescriptions = [
        '' => $collectionMeta['Ring']['description'] ?? $heroDescription,
        'engagement' => 'Begin with the design they will wear forever — solitaire, halo, hidden halo, three stone, vintage, toi et moi, and sidestone engagement rings, each crafted to order.',
        'wedding' => 'Wedding rings for every love story — explore women\'s and men\'s bands in gold and platinum, from classic court rings to diamond eternity designs.',
        'wedding:womens' => 'Women\'s wedding rings designed to sit perfectly alongside an engagement ring — classic court bands, diamond eternity, and curved contour styles.',
        'wedding:mens' => 'Men\'s wedding bands with substance and comfort — classic, brushed, diamond-set, and two-tone rings in gold and platinum.',
    ];
    $ringDescriptionKey = $filters['ring_category'] !== ''
        ? ($filters['gender'] !== '' ? $filters['ring_category'] . ':' . $filters['gender'] : $filters['ring_category'])
        : '';

    if ($filters['style'] !== '') {
        $styleLabel = $ringStyles[$filters['style']] ?? 'Ring Style';
        $heroTitle = $styleLabel . ' ' . $ringSectionLabel;
        $heroDescription = 'Explore ' . strtolower($styleLabel) . ' designs within the ' . strtolower($ringSectionLabel) . ' collection and compare silhouettes, metals, and finishes.';
    } else {
        $heroTitle = $ringSectionLabel;
        $heroDescription = $ringSectionDescriptions[$ringDescriptionKey] ?? $heroDescription;
    }
    $breadcrumbLabel = $heroTitle;
} elseif (isset($collectionMeta[$filters['type']])) {
    $heroTitle = $collectionMeta[$filters['type']]['title'];
    $heroDescription = $collectionMeta[$filters['type']]['description'];
    $breadcrumbLabel = $heroTitle;
} elseif ($heroCategoryCard !== null) {
    // A merchant-created category with no built-in copy: its own name is the
    // hero title, so the page stops reading "Shop Jewellery".
    $heroCardTitle = trim((string) ($heroCategoryCard['title'] ?? ''));
    $heroTitle = $heroCardTitle === strtolower($heroCardTitle) ? ucwords($heroCardTitle) : $heroCardTitle;
    $breadcrumbLabel = $heroTitle;
} elseif ($filters['q'] !== '' && isset($namedQueryCollections[$normalizedQuery])) {
    $heroTitle = $namedQueryCollections[$normalizedQuery]['title'];
    $heroDescription = $namedQueryCollections[$normalizedQuery]['description'];
    $breadcrumbLabel = $heroTitle;
} elseif ($filters['q'] !== '') {
    $heroTitle = 'Search Results for "' . $filters['q'] . '"';
    $heroDescription = 'Explore the pieces matching your search and refine the results with the available filters.';
    $breadcrumbLabel = 'Search';
}

// The category's "Other Text" is the merchant's own hero copy, so it overrides
// whatever default description the branches above picked.
$heroCardSub = clean_string((string) ($heroCategoryCard['sub'] ?? ''), 400);
if ($heroCardSub !== '') {
    $heroDescription = $heroCardSub;
}

$pageTitle = $heroTitle . ' - ' . SITE_NAME;
$bodyClass = 'shop-page';

$buildShopUrl = static function (array $changes = []) use ($filters): string {
    $query = array_merge($filters, $changes);
    $query = array_filter($query, static fn (mixed $value): bool => is_string($value) && $value !== '');
    return empty($query) ? resolve_link('/shop/') : resolve_link('/shop/?' . http_build_query($query));
};

$activeFilterPills = array_filter([
    $filters['type'] !== '' ? ['label' => 'Type', 'value' => $filters['type'], 'clear' => $buildShopUrl(['type' => '', 'ring_category' => '', 'gender' => '', 'style' => ''])] : null,
    $filters['ring_category'] !== '' ? ['label' => 'Section', 'value' => ring_section_label($filters['ring_category']), 'clear' => $buildShopUrl(['ring_category' => '', 'gender' => '', 'style' => ''])] : null,
    $filters['gender'] !== '' ? ['label' => 'For', 'value' => $filters['gender'] === 'mens' ? "Men's" : "Women's", 'clear' => $buildShopUrl(['gender' => ''])] : null,
    $filters['color'] !== '' ? ['label' => 'Metal', 'value' => $filters['color'], 'clear' => $buildShopUrl(['color' => ''])] : null,
    $filters['category'] !== '' ? ['label' => 'Collection', 'value' => $filters['category'], 'clear' => $buildShopUrl(['category' => ''])] : null,
    $filters['shape'] !== '' ? ['label' => 'Shape', 'value' => $filters['shape'], 'clear' => $buildShopUrl(['shape' => ''])] : null,
    $filters['style'] !== '' ? ['label' => 'Style', 'value' => $ringStyles[$filters['style']] ?? $filters['style'], 'clear' => $buildShopUrl(['style' => ''])] : null,
    $filters['facet'] !== '' && isset($selectorItems[$filters['facet']]) ? ['label' => 'Edit', 'value' => $selectorItems[$filters['facet']]['label'], 'clear' => $buildShopUrl(['facet' => ''])] : null,
    $filters['price'] !== '' && isset($allPriceBuckets[$filters['price']]) ? ['label' => 'Price', 'value' => $allPriceBuckets[$filters['price']]['label'], 'clear' => $buildShopUrl(['price' => ''])] : null,
    $filters['q'] !== '' ? ['label' => 'Search', 'value' => $filters['q'], 'clear' => $buildShopUrl(['q' => ''])] : null,
]);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
  body.shop-page {
    background: #ffffff;
  }

  .hero-breadcrumbs {
    display: inline-flex;
    align-items: center;
    margin-bottom: 20px;
    color: #c18b35;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.2em;
    text-transform: uppercase;
  }
  .hero-breadcrumbs a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s;
    white-space: nowrap;
  }
  .hero-breadcrumbs a:hover {
    color: #a4762c;
  }
  .hero-breadcrumbs .sep {
    margin: 0 12px;
    opacity: 0.6;
    flex-shrink: 0;
  }
  .hero-breadcrumbs strong {
    font-weight: 600;
    white-space: nowrap;
  }

  .collection-hero {
    background: transparent;
    padding: 54px 20px 40px;
    text-align: center;
  }
  .collection-hero.ring-journey-hero {
    padding: 90px 20px 220px;
    background: url('/assets/uploads/luxurious_ring_bg.png') no-repeat center center;
    background-size: cover;
    border-bottom: none;
    position: relative;
    text-align: left;
  }
  .collection-hero.ring-journey-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, #fdfaf5 0%, rgba(253, 250, 245, 0.8) 40%, rgba(253, 250, 245, 0) 100%);
    pointer-events: none;
  }
  .collection-hero.earring-collection-hero {
    padding: 60px 20px 40px;
    background-position: center right;
    background-repeat: no-repeat;
    background-size: cover;
    text-align: left;
    position: relative;
    overflow: hidden;
  }
  .collection-hero.earring-collection-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, rgba(253, 250, 245, 0.95) 0%, rgba(253, 250, 245, 0.8) 35%, rgba(253, 250, 245, 0) 100%);
    pointer-events: none;
  }
  .collection-hero.earring-collection-hero .container {
    position: relative;
    z-index: 1;
    max-width: 1460px;
  }
  .earring-hero-shell {
    position: relative;
    padding: 20px 10px 40px;
  }
  .earring-hero-grid {
    display: flex;
    align-items: center;
    min-height: 400px;
  }
  .earring-hero-copy {
    max-width: 500px;
    padding: 20px 0 20px 5%;
  }
  .earring-hero-kicker {
    display: inline-block;
    margin-bottom: 20px;
    color: #c18b35;
    font-size: 0.85rem;
    font-weight: 600;
    letter-spacing: 0.25em;
    text-transform: uppercase;
  }
  .earring-hero-copy h1 {
    margin: 0;
    color: #143b32;
    font-family: "Playfair Display", serif;
    font-size: clamp(3.2rem, 6.2vw, 6.5rem);
    font-weight: 400;
    line-height: 1;
    letter-spacing: -0.02em;
    white-space: nowrap;
  }
  .earring-hero-ornament {
    display: flex;
    align-items: center;
    gap: 15px;
    margin: 25px 0 25px;
    color: #c18b35;
  }
  .earring-hero-ornament::before,
  .earring-hero-ornament::after {
    content: "";
    width: 90px;
    height: 1px;
    background: #c18b35;
  }
  .earring-hero-ornament i {
    font-size: 0.85rem;
  }
  .earring-hero-copy p {
    max-width: 440px;
    margin: 0;
    color: #5a5a5a;
    font-size: 1.05rem;
    line-height: 1.6;
    font-weight: 400;
  }
  .style-selector-row.earring-selector-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
    max-width: none;
    margin: 30px auto 0;
    gap: 24px 20px;
    padding: 0;
  }
  .style-selector-item.earring-selector-item {
    width: 96px;
    color: #262626;
    flex-shrink: 0;
  }
  .style-selector-item.earring-selector-item img {
    width: 82px;
    height: 82px;
    padding: 12px;
    border-radius: 50%;
    background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(252,248,241,0.94) 100%);
    box-shadow:
      0 18px 34px rgba(173, 151, 123, 0.11),
      inset 0 0 0 1px rgba(227, 217, 201, 0.9);
  }
  .style-selector-item.earring-selector-item span {
    margin-top: 10px;
    color: #2d2d2d;
    font-size: 0.70rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    line-height: 1.35;
  }
  .earring-selector-item:hover img,
  .earring-selector-item.is-active img {
    border-color: transparent;
    box-shadow:
      0 20px 38px rgba(193, 139, 53, 0.16),
      inset 0 0 0 2px rgba(193, 139, 53, 0.48);
  }
  .earring-selector-item:hover,
  .earring-selector-item.is-active {
    color: #143b32;
    transform: translateY(-4px);
  }
  .ring-hero-heading {
    display: inline-flex;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 20px;
    max-width: 100%;
  }
  .collection-hero h1 {
    font-family: var(--serif, serif);
    font-size: clamp(3.2rem, 6.2vw, 6.5rem);
    color: #1a1a1a;
    margin-bottom: 20px;
    font-weight: 400;
    line-height: 0.95;
    letter-spacing: -0.02em;
    display: inline-flex;
    align-items: flex-start;
    max-width: 100%;
    white-space: nowrap;
  }
  .collection-hero.ring-journey-hero h1 {
    margin-bottom: 0;
  }
  .collection-hero h1 span {
    font-size: 0.3em;
    color: #c9a96e;
    margin-left: 10px;
    margin-top: 15px;
  }
  .collection-hero p {
    max-width: 480px;
    margin: 0;
    color: #5a5a5a;
    font-size: 1.05rem;
    line-height: 1.6;
    font-weight: 400;
  }

  /* Custom badge in hero */
  .premium-hero-badge {
    position: relative;
    width: 140px;
    height: 140px;
    background: transparent;
    border-radius: 50%;
    border: 1px solid rgba(201, 169, 110, 0.3);
    display: flex;
    justify-content: center;
    align-items: center;
  }
  .premium-hero-badge::before {
    content: "";
    position: absolute;
    inset: 5px;
    border: 1px dashed rgba(201, 169, 110, 0.5);
    border-radius: 50%;
  }
  .premium-hero-badge svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    animation: rotate-slow 20s linear infinite;
  }
  .premium-hero-badge .center-icon {
    position: absolute;
    color: #c9a96e;
    font-size: 1.8rem;
    animation: none;
  }
  @keyframes rotate-slow {
    100% { transform: rotate(360deg); }
  }

  .ring-journey-overview {
    background: transparent;
    border-bottom: none;
    margin-top: -30px;
    position: relative;
    z-index: 5;
  }
  .premium-step-banner {
    background: #fcfcf9;
    width: 90%;
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 80px;
    font-family: var(--sans, sans-serif);
    border-radius: 8px 8px 0 0;
    border-top: 1px solid #eae1d0;
    box-shadow: 0 -10px 30px rgba(0,0,0,0.02);
  }
  .step-banner-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 40px;
    font-size: 0.8rem;
    font-weight: 500;
    letter-spacing: 0.12em;
    color: #a39f98;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .step-banner-item.is-active {
    color: #1a1a1a;
    font-weight: 600;
  }
  .step-banner-item.start-text {
    font-style: italic;
    font-family: var(--serif, serif);
    font-size: 1.4rem;
    color: #1a1a1a;
    padding-right: 40px;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
  }
  .step-banner-item.start-text::after {
    content: "\2726";
    color: #c9a96e;
    font-size: 0.6em;
    margin-left: 8px;
    vertical-align: super;
    font-style: normal;
  }
  .step-banner-item span {
    font-size: 1.2rem;
    font-family: var(--serif, serif);
    color: inherit;
    font-weight: 400;
  }
  .step-banner-item.is-active span {
    color: #c9a96e;
  }
  .step-separator {
    width: 1px;
    height: 30px;
    background: #eae1d0;
    transform: none;
  }

  .ring-style-showcase {
    padding: 40px 0 50px;
    background: #fdfaf5;
  }
  .style-selector-row {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-bottom: 0;
    flex-wrap: wrap;
    max-width: 1100px;
    margin-left: auto;
    margin-right: auto;
    position: relative;
    z-index: 2;
  }
  .ring-style-selector-row {
    row-gap: 30px;
  }
  .style-selector-form {
    margin: 0;
  }
  .style-selector-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-decoration: none;
    color: #8c8577;
    opacity: 1;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    width: 90px;
    position: relative;
    z-index: 3;
    cursor: pointer;
    border: 0;
    background: transparent;
    font: inherit;
    padding: 0;
  }
  .style-selector-item:hover, .style-selector-item.is-active {
    transform: translateY(-5px);
    color: #1a1a1a;
  }
  .style-selector-item img {
    width: 84px;
    height: 84px;
    object-fit: contain;
    border-radius: 50%;
    margin-bottom: 12px;
    border: 2px solid transparent;
    background: #fff;
    padding: 12px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.04);
    transition: all 0.3s ease;
  }
  .style-selector-item:hover img, .style-selector-item.is-active img {
    border-color: #c9a96e;
    box-shadow: 0 12px 25px rgba(201, 169, 110, 0.15);
  }
  .style-selector-item span {
    font-size: 0.65rem;
    font-weight: 600;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    line-height: 1.45;
    transition: color 0.3s ease;
  }

  .collection-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 0 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    margin-bottom: 30px;
    background: transparent;
  }
  .filter-group {
    display: flex;
    gap: 20px;
    align-items: center;
  }
  .filter-label {
    font-weight: 500;
    color: #e5dac4;
    margin-right: 5px;
    font-size: 0.9rem;
  }
  .filter-dropdown {
    background: transparent;
    border: none;
    font-size: 0.9rem;
    color: #ffffff;
    font-weight: 400;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    outline: none;
  }
  .filter-dropdown option {
    background: #192c25;
    color: #fff;
  }
  .filter-dropdown i {
    font-size: 0.7rem;
    color: #c9a96e;
  }

  .shop-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 0 20px;
    width: 100%;
  }
  .shop-shell {
    background: #192c25;
    border-radius: 40px;
    padding: 40px 60px 80px;
    margin: 0 20px 60px;
    position: relative;
    box-shadow: 0 20px 40px rgba(25, 44, 37, 0.15);
  }
  .shop-shell::before {
    content: "";
    position: absolute;
    top: 0; left: 40px; right: 40px;
    height: 1px;
    background: transparent;
  }
  
  .shop-layout {
    display: block; 
  }
  .shop-sidebar { display: none; }
  .shop-results { width: 100%; }
  .shop-results-bar { display: none; }
  
  .shop-product-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 25px;
  }
  .shop-results-header {
      font-size: 1.4rem;
      color: #ffffff;
      font-family: var(--serif, serif);
      margin-bottom: 0;
      display: flex;
      align-items: center;
      padding-top: 0 !important;
  }
  .shop-results-header::after {
      content: "";
      display: inline-block;
      width: 40px;
      height: 1px;
      background: #c9a96e;
      margin-left: 20px;
  }

  /* PREMIUM PRODUCT CARDS CSS */
  .shop-page .prod-card {
    background: #fdfcf8;
    border-radius: 16px;
    padding: 20px;
    text-align: center;
    position: relative;
    border: 1px solid #eae1d0;
    transition: transform 0.3s, box-shadow 0.3s;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 15px rgba(0,0,0,0.02);
  }
  .shop-page .prod-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.05);
  }
  .shop-page .prod-img-box {
    order: 1;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 15px;
    background: transparent; 
    position: relative;
  }
  .shop-page .prod-img-box img {
    mix-blend-mode: multiply;
  }
  .shop-page .prod-cat {
    order: 2;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    color: #8c8577;
    margin-bottom: 8px;
    font-weight: 600;
  }
  .shop-page .prod-name {
    order: 3;
    font-family: var(--serif, serif);
    font-size: 1.3rem;
    color: #1a1a1a;
    margin-bottom: 10px;
    line-height: 1.2;
  }
  .shop-page .prod-name a {
    color: inherit;
    text-decoration: none;
  }
  .shop-page .prod-prices {
    order: 4;
    width: 100%;
    margin: 8px 0 0;
    padding: 13px 18px 14px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(249,246,239,0.98) 0%, rgba(255,253,248,0.98) 60%, rgba(245,240,228,0.98) 100%);
    border: 1px solid rgba(201, 169, 110, 0.2);
    box-shadow: 0 8px 18px rgba(18, 43, 35, 0.06), inset 0 1px 0 rgba(255,255,255,0.9);
  }
  .shop-page .prod-card:hover .img-default {
    opacity: 0 !important;
  }
  .shop-page .prod-card:hover .img-hover {
    opacity: 1 !important;
  }
  .shop-page .price-prefix {
    font-size: 0.65rem;
    color: #8c8577;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 600;
    margin-right: 5px;
    font-family: var(--sans, sans-serif);
  }
  .shop-page .prod-prices .new {
    font-size: 1.05rem;
  }
  .shop-page .prod-footer-decor {
    order: 5;
    display: flex !important;
    margin-top: 16px;
  }
  .shop-page .prod-stars,
  .shop-page .prod-craft-row,
  .shop-page .prod-ornament {
    display: flex !important;
  }
  .shop-page .prod-ornament-close {
    display: none !important;
  }
  
  .shop-page .qv-popup {
    left: 14px;
    right: 14px;
    bottom: 14px;
    top: calc(var(--prod-card-pad) + var(--prod-media-height) + 10px);
    transform: translateY(16px);
  }
  .shop-page .prod-card:hover .qv-popup {
    transform: translateY(0);
  }
  .shop-page .qv-popup-body {
    min-height: 255px;
    padding: 16px 16px 14px;
    border-radius: 28px;
    background: linear-gradient(180deg, rgba(255,255,252,0.98) 0%, rgba(251,247,239,0.99) 100%);
    box-shadow: 0 18px 40px rgba(18, 43, 35, 0.14), 0 8px 22px rgba(201, 169, 110, 0.12);
  }
  .shop-page .qv-popup-name {
    font-size: 1.05rem;
    line-height: 1.18;
    color: #243a32;
  }
  .shop-page .qv-desc {
    max-width: 250px;
    color: #6d756d;
  }
  .shop-page .qv-actions {
    gap: 10px;
    padding-top: 12px;
    margin-top: 12px;
  }
  .shop-page .qv-wishlist-form {
    margin: 0;
  }
  .shop-page .qv-icon-btn {
    width: 40px;
    height: 40px;
    border-radius: 14px;
    background: linear-gradient(180deg, #ffffff 0%, #f7f3ea 100%);
    border: 1px solid rgba(18, 43, 35, 0.1);
    color: #5d6a64;
    box-shadow: 0 4px 12px rgba(18, 43, 35, 0.07);
  }
  .shop-page .qv-icon-btn:hover,
  .shop-page .qv-icon-btn.is-active {
    border-color: rgba(200, 157, 88, 0.44);
    background: linear-gradient(135deg, #fffaf1 0%, #ead4ab 46%, #c89d58 100%);
    color: #17372c;
    box-shadow: 0 8px 18px rgba(132, 96, 44, 0.22);
  }
  .shop-page .qv-add-btn {
    min-height: 40px;
    border-radius: 14px;
    background: linear-gradient(135deg, #17372c 0%, #284d3f 60%, #9a7445 140%);
    color: #fff !important;
    font-size: 0.7rem !important;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    box-shadow: 0 8px 20px rgba(18, 43, 35, 0.2);
  }
  .shop-page .qv-add-btn:hover {
    filter: brightness(1.08);
    box-shadow: 0 14px 28px rgba(18, 43, 35, 0.28);
  }
  
  /* Filter Pills styling override */
  .shop-page .shop-active-filters span {
      background: transparent !important;
      border: 1px solid rgba(255,255,255,0.2) !important;
      color: #e5dac4 !important;
  }
  .shop-page .shop-active-filters strong {
      color: #fff !important;
  }
  .shop-page .shop-active-filters a {
      color: #c9a96e !important;
  }

  @media (max-width: 1024px) {
    .shop-product-grid { grid-template-columns: repeat(3, 1fr); }
    .style-selector-row { gap: 22px; }
    .shop-shell { padding: 30px 40px 60px; }
    .premium-hero-badge { width: 124px; height: 124px; }
    .earring-hero-grid { min-height: 350px; }
    .earring-hero-copy { max-width: none; padding: 20px 10px 0; }
  }
  @media (max-width: 768px) {
    .shop-product-grid { grid-template-columns: repeat(2, 1fr); }
    .collection-hero.ring-journey-hero { padding: 60px 20px 100px; }
    .collection-hero h1 { font-size: clamp(2.2rem, 11vw, 3rem); }
    .ring-hero-heading { display: block; }
    .premium-hero-badge { display: none; }
    .premium-step-banner { min-height: 58px; overflow-x: auto; justify-content: flex-start; padding: 0 18px; width: 100%; border:none; border-bottom:1px solid #eae1d0;}
    .step-banner-item.start-text { display: none; }
    .step-banner-item { padding: 0 18px; font-size: 0.78rem; }
    .shop-shell { padding: 25px 20px 40px; margin: 0 10px 40px; border-radius: 20px; }
    .shop-results-header { font-size: 1.1rem; }
    .collection-filter-bar { flex-direction: column; align-items: flex-start; gap: 15px; }
    .collection-hero.earring-collection-hero { padding: 28px 12px 18px; background-position: 70% center; }
    .earring-hero-shell { padding: 4px 0 14px; }

    /* `.earring-hero-grid` is a flex row and `.earring-hero-copy` had
       `max-width: none` from the 1024 block, so the copy sized to its widest
       child. With `white-space: nowrap` on the h1, "Women's Wedding Rings" made
       that child 452px — 62px past a 390px viewport, which is why the heading
       ran off the screen. Cap the flex item and let the heading wrap. */
    .earring-hero-grid { min-height: 0; display: block; }
    .earring-hero-copy { max-width: 100%; width: 100%; min-width: 0; padding: 0; }
    .earring-hero-copy h1 {
      white-space: normal;
      font-size: clamp(1.85rem, 8.6vw, 2.6rem);
      line-height: 1.12;
      overflow-wrap: break-word;
    }
    .earring-hero-kicker { font-size: 0.7rem; letter-spacing: 0.18em; margin-bottom: 12px; }
    .earring-hero-copy p { max-width: 100%; font-size: 0.92rem; line-height: 1.55; }
    .earring-hero-ornament { margin: 16px 0; gap: 10px; }
    .earring-hero-ornament::before { width: 52px; }
    .earring-hero-ornament::after { width: 52px; }

    /* Breadcrumb trail is nowrap per-crumb and runs 5 levels deep on ring
       pages; let it scroll sideways instead of pushing the hero wide. */
    .hero-breadcrumbs {
      display: flex;
      max-width: 100%;
      overflow-x: auto;
      scrollbar-width: none;
      -webkit-overflow-scrolling: touch;
      margin-bottom: 14px;
      font-size: 0.66rem;
      letter-spacing: 0.14em;
    }
    .hero-breadcrumbs::-webkit-scrollbar { display: none; }
    .hero-breadcrumbs .sep { margin: 0 7px; }

    .style-selector-row.earring-selector-row { gap: 12px; margin-top: 12px; }
    .style-selector-item.earring-selector-item { width: 82px; }
    .style-selector-item.earring-selector-item img { width: 68px; height: 68px; padding: 10px; }
    .style-selector-item.earring-selector-item span { font-size: 0.62rem; letter-spacing: 0.1em; }
  }
  @media (max-width: 480px) {
    .shop-product-grid { grid-template-columns: 1fr; }
    .earring-hero-copy { padding: 10px 6px 0; }
    .style-selector-item.earring-selector-item { width: 74px; }
  }

  /* ── Wedding gender filter: jewellery-style box row + toolbar toggle ── */
  .wedding-gender-row {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 48px;
    max-width: 980px;
    margin: 34px auto 0;
    padding: 0 20px 54px;
    position: relative;
    z-index: 4;
  }
  .wedding-gender-card {
    display: flex;
    flex-direction: column;
    width: 430px;
    max-width: 46%;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .wedding-gender-card-img {
    width: 100%;
    aspect-ratio: 4 / 3;
    border-radius: 6px;
    overflow: hidden;
    position: relative;
    background: #f3ece0;
    border: 1px solid rgba(200, 169, 110, 0.18);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
    transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .wedding-gender-card-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .wedding-gender-card-title {
    margin-top: 16px;
    font-family: "Playfair Display", var(--serif, serif);
    font-size: 1.3rem;
    font-weight: 500;
    color: #1c1c1c;
    text-align: center;
    letter-spacing: 0.01em;
    line-height: 1.2;
    transition: color 0.3s ease;
  }
  .wedding-gender-card:hover .wedding-gender-card-img,
  .wedding-gender-card.is-active .wedding-gender-card-img {
    border-color: #c9a96e;
    box-shadow: 0 12px 28px rgba(201, 169, 110, 0.2);
    transform: translateY(-4px);
  }
  .wedding-gender-card:hover .wedding-gender-card-img img,
  .wedding-gender-card.is-active .wedding-gender-card-img img {
    transform: scale(1.05);
  }
  .wedding-gender-card:hover .wedding-gender-card-title,
  .wedding-gender-card.is-active .wedding-gender-card-title {
    color: #c9a96e;
  }
  .wedding-gender-card.is-active .wedding-gender-card-img::after {
    content: "\f00c";
    font-family: "Font Awesome 5 Free", "Font Awesome 6 Free", FontAwesome;
    font-weight: 900;
    position: absolute;
    top: 10px;
    right: 10px;
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #c9a96e;
    color: #fff;
    font-size: 0.7rem;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.18);
  }

  .sl-gender-toggle {
    display: inline-flex;
    align-items: center;
    gap: 12px;
  }
  .sl-gender-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: #6f6f68;
  }
  .sl-gender-pills {
    display: inline-flex;
    border: 1px solid #e3ddd0;
    border-radius: 999px;
    overflow: hidden;
    background: #faf8f3;
  }
  .sl-gender-pill {
    padding: 8px 20px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #7c766a;
    text-decoration: none;
    transition: all 0.2s ease;
  }
  .sl-gender-pill + .sl-gender-pill {
    border-left: 1px solid #e3ddd0;
  }
  .sl-gender-pill:hover {
    color: #1c1c1c;
    background: #f1ebdd;
  }
  .sl-gender-pill.is-active {
    background: linear-gradient(135deg, #ead4ab 0%, #c9a96e 100%);
    color: #17372c;
  }
  @media (max-width: 768px) {
    .wedding-gender-row { gap: 18px; padding: 0 14px 34px; }
    .wedding-gender-card { width: 44%; min-width: 150px; }
    .wedding-gender-card-title { font-size: 1.05rem; }
    .sl-gender-toggle { width: 100%; justify-content: space-between; }
  }
</style>

<?php ob_start(); ?>
<div class="hero-breadcrumbs">
  <a href="<?= h(resolve_link('/')) ?>"><i class="fas fa-home" style="margin-right:6px;"></i> HOME</a>
  <?php if ($showRingJourney): ?>
    <span class="sep">/</span>
    <?php if ($filters['ring_category'] !== '' || $filters['style'] !== ''): ?>
      <a href="<?= h($buildShopUrl(['ring_category' => '', 'gender' => '', 'style' => '', 'q' => '', 'category' => '', 'shape' => ''])) ?>">RINGS</a>
    <?php else: ?>
      <strong>RINGS</strong>
    <?php endif; ?>
    <?php if ($filters['ring_category'] !== ''): ?>
      <span class="sep">/</span>
      <?php if ($filters['gender'] !== '' || $filters['style'] !== ''): ?>
        <a href="<?= h($buildShopUrl(['gender' => '', 'style' => '', 'q' => '', 'category' => '', 'shape' => ''])) ?>"><?= h(strtoupper(ring_section_label($filters['ring_category']))) ?></a>
      <?php else: ?>
        <strong><?= h(strtoupper(ring_section_label($filters['ring_category']))) ?></strong>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($filters['gender'] !== ''): ?>
      <span class="sep">/</span>
      <?php if ($filters['style'] !== ''): ?>
        <a href="<?= h($buildShopUrl(['style' => '', 'q' => '', 'category' => '', 'shape' => ''])) ?>"><?= h($filters['gender'] === 'mens' ? "MEN'S" : "WOMEN'S") ?></a>
      <?php else: ?>
        <strong><?= h($filters['gender'] === 'mens' ? "MEN'S" : "WOMEN'S") ?></strong>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($filters['style'] !== ''): ?>
      <span class="sep">/</span>
      <strong><?= h($breadcrumbLabel) ?></strong>
    <?php endif; ?>
  <?php else: ?>
    <span class="sep">/</span>
    <strong><?= h($breadcrumbLabel) ?></strong>
  <?php endif; ?>
</div>
<?php $heroBreadcrumbsHtml = ob_get_clean(); ?>

<section class="collection-hero reveal-in <?= $showPremiumHero ? 'earring-collection-hero' : '' ?>"<?= $showPremiumHero ? ' style="background-image: url(\'' . h($premiumBgUrl) . '\');"' : ' style="padding-bottom: 0;"' ?>>
  <div class="container">
    <?php if ($showPremiumHero): ?>
    <div class="earring-hero-shell">
      <div class="earring-hero-grid">
        <div class="earring-hero-copy">
          <?= $heroBreadcrumbsHtml ?>
          <h1><?= h($heroTitle) ?></h1>
          <div class="earring-hero-ornament"><i class="far fa-gem" aria-hidden="true"></i></div>
          <p><?= h($heroDescription) ?></p>
        </div>
      </div>
    </div>
    <?php else: ?>
    <?= $heroBreadcrumbsHtml ?>
    <h1><?= h($heroTitle) ?></h1>
    <?php endif; ?>
    <?php if (!$showPremiumHero): ?>
      <p><?= h($heroDescription) ?></p>
    <?php endif; ?>
  </div>
</section>

<?php
// Wedding section only: the Men's / Women's entry boxes. Clicking a box filters
// the listing to that gender; clicking the active box clears back to all.
$weddingGenderCards = ($showRingJourney && $filters['ring_category'] === 'wedding') ? ring_gender_box_cards() : [];
?>
<?php if ($weddingGenderCards !== []): ?>
  <div class="wedding-gender-row">
    <?php foreach ($weddingGenderCards as $genderCard): ?>
      <?php
        $genderIsActive = $filters['gender'] === $genderCard['key'];
        $genderLink = $buildShopUrl(['gender' => $genderIsActive ? '' : $genderCard['key']]);
      ?>
      <a href="<?= h($genderLink) ?>" class="wedding-gender-card <?= $genderIsActive ? 'is-active' : '' ?>" aria-pressed="<?= $genderIsActive ? 'true' : 'false' ?>">
        <div class="wedding-gender-card-img">
          <img src="<?= h($genderCard['image']) ?>" alt="<?= h($genderCard['label']) ?>" loading="lazy">
        </div>
        <div class="wedding-gender-card-title"><?= h($genderCard['label']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
// "Choose your style" carousel below the hero — the single, consistent style
// selector for EVERY category (rings use ring styles; every other type uses its
// admin-configurable selector/facet cards). The old in-hero chip row is gone so
// all categories share the ring layout; a brand-new category shows the carousel
// automatically once it has selector cards configured in the Attributes studio.
$slStyleItems = [];
if ($showRingJourney) {
    foreach ($ringStyleCards as $sKey => $sCard) {
        $slStyleItems[] = [
            'key' => $sKey,
            'param' => 'style',
            'label' => (string) ($sCard['label'] ?? $sKey),
            'image' => (string) ($sCard['image'] ?? ''),
            'active' => $filters['style'] === $sKey,
        ];
    }
} else {
    foreach ($selectorItems as $sItem) {
        $sValue = (string) ($sItem['value'] ?? '');
        if ($sValue === '') {
            continue;
        }
        $slStyleItems[] = [
            'key' => $sValue,
            'param' => 'facet',
            'label' => (string) ($sItem['label'] ?? $sValue),
            'image' => (string) ($sItem['image'] ?? ''),
            'active' => $filters['facet'] === $sValue,
        ];
    }
}
$slShapeOptions = $showRingJourney ? available_diamond_shapes() : [];
$slFlash = function_exists('site_flash_pull') ? site_flash_pull() : null;
?>

<div class="sl-wrap">
  <div class="sl-container">

    <?php if ($slFlash !== null): ?>
      <div class="sl-flash sl-flash--<?= h((string) ($slFlash['type'] ?? 'success')) ?>"><?= h((string) ($slFlash['message'] ?? '')) ?></div>
    <?php endif; ?>

    <?php if ($slStyleItems !== []): ?>
    <div class="sl-stylebar">
      <span class="sl-stylebar-label">Choose your style:</span>
      <div class="sl-stylebar-carousel" data-sl-carousel>
        <button type="button" class="sl-stylebar-arrow" data-sl-prev aria-label="Previous styles"><i class="fas fa-chevron-left"></i></button>
        <div class="sl-stylebar-track" data-sl-track>
          <?php foreach ($slStyleItems as $slItem):
            $slLink = $buildShopUrl([$slItem['param'] => $slItem['active'] ? '' : $slItem['key']]);
          ?>
            <a href="<?= h($slLink) ?>" class="sl-stylebar-item <?= $slItem['active'] ? 'is-active' : '' ?>" data-sl-item>
              <span class="sl-stylebar-thumb"><?php if ($slItem['image'] !== ''): ?><img src="<?= h($slItem['image']) ?>" alt="<?= h($slItem['label']) ?>" loading="lazy"><?php endif; ?></span>
              <span class="sl-stylebar-name"><?= h($slItem['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <button type="button" class="sl-stylebar-arrow" data-sl-next aria-label="Next styles"><i class="fas fa-chevron-right"></i></button>
      </div>
    </div>
    <?php endif; ?>

    <div class="sl-toolbar">
      <form method="get" action="<?= h(resolve_link('/shop/')) ?>" class="sl-filters">
        <?php foreach ($filters as $k => $v): if (!in_array($k, ['sort', 'color', 'shape', 'price'], true) && $v !== ''): ?>
          <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
        <?php endif; endforeach; ?>

        <span class="sl-filters-label">Filter:</span>

        <?php if ($slShapeOptions !== []): ?>
        <label class="sl-select-wrap">
          <select name="shape" class="sl-select" onchange="this.form.submit()" aria-label="Filter by diamond shape">
            <option value="">Shape</option>
            <?php foreach ($slShapeOptions as $shKey => $shLabel): ?>
              <option value="<?= h($shKey) ?>" <?= $filters['shape'] === $shKey ? 'selected' : '' ?>><?= h($shLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fas fa-chevron-down sl-select-caret" aria-hidden="true"></i>
        </label>
        <?php endif; ?>

        <label class="sl-select-wrap">
          <select name="color" class="sl-select" onchange="this.form.submit()" aria-label="Filter by metal">
            <option value="">Metal</option>
            <?php foreach ($productColors as $color): ?>
              <option value="<?= h($color) ?>" <?= $filters['color'] === $color ? 'selected' : '' ?>><?= h($color) ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fas fa-chevron-down sl-select-caret" aria-hidden="true"></i>
        </label>

        <label class="sl-select-wrap">
          <select name="price" class="sl-select" onchange="this.form.submit()" aria-label="Filter by price">
            <option value="">Price</option>
            <?php foreach ($priceBucketOptions as $pbKey => $pb): ?>
              <option value="<?= h($pbKey) ?>" <?= $filters['price'] === $pbKey ? 'selected' : '' ?>><?= h($pb['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fas fa-chevron-down sl-select-caret" aria-hidden="true"></i>
        </label>
      </form>

      <div class="sl-toolbar-right">
        <?php if ($weddingGenderCards !== []): ?>
        <div class="sl-gender-toggle" role="group" aria-label="Filter wedding rings by gender">
          <span class="sl-gender-label">For:</span>
          <div class="sl-gender-pills">
            <a href="<?= h($buildShopUrl(['gender' => ''])) ?>" class="sl-gender-pill <?= $filters['gender'] === '' ? 'is-active' : '' ?>">All</a>
            <a href="<?= h($buildShopUrl(['gender' => 'mens'])) ?>" class="sl-gender-pill <?= $filters['gender'] === 'mens' ? 'is-active' : '' ?>">Men's</a>
            <a href="<?= h($buildShopUrl(['gender' => 'womens'])) ?>" class="sl-gender-pill <?= $filters['gender'] === 'womens' ? 'is-active' : '' ?>">Women's</a>
          </div>
        </div>
        <?php endif; ?>
        <span class="sl-count"><?= count($filteredProducts) ?> Products</span>
        <form method="get" action="<?= h(resolve_link('/shop/')) ?>" class="sl-sort">
          <?php foreach ($filters as $k => $v): if ($k !== 'sort' && $v !== ''): ?>
            <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
          <?php endif; endforeach; ?>
          <span class="sl-sort-label">Sort:</span>
          <label class="sl-select-wrap">
            <select name="sort" class="sl-select" onchange="this.form.submit()" aria-label="Sort products">
              <option value="featured" <?= $filters['sort'] === 'featured' ? 'selected' : '' ?>>Recommended</option>
              <option value="price-low" <?= $filters['sort'] === 'price-low' ? 'selected' : '' ?>>Price: Low to High</option>
              <option value="price-high" <?= $filters['sort'] === 'price-high' ? 'selected' : '' ?>>Price: High to Low</option>
              <option value="name-asc" <?= $filters['sort'] === 'name-asc' ? 'selected' : '' ?>>Name: A – Z</option>
              <option value="name-desc" <?= $filters['sort'] === 'name-desc' ? 'selected' : '' ?>>Name: Z – A</option>
            </select>
            <i class="fas fa-chevron-down sl-select-caret" aria-hidden="true"></i>
          </label>
        </form>
      </div>
    </div>

    <?php if ($activeFilterPills !== []): ?>
      <div class="sl-pills">
        <?php foreach ($activeFilterPills as $pill): ?>
          <span class="sl-pill"><strong><?= h($pill['label']) ?>:</strong> <?= h($pill['value']) ?><a href="<?= h($pill['clear']) ?>" aria-label="Clear <?= h($pill['label']) ?> filter"><i class="fas fa-times"></i></a></span>
        <?php endforeach; ?>
        <a href="<?= h($buildShopUrl(['q' => '', 'type' => '', 'ring_category' => '', 'gender' => '', 'color' => '', 'category' => '', 'shape' => '', 'style' => '', 'facet' => '', 'price' => '', 'sort' => 'featured'])) ?>" class="sl-pills-clear">Clear All</a>
      </div>
    <?php endif; ?>

    <?php if ($filteredProducts === []): ?>
      <div class="sl-empty">
        <h3>No products matched these filters</h3>
        <p>Try clearing a filter or widening your price range to see more pieces.</p>
        <a class="sl-empty-btn" href="<?= h($buildShopUrl(['q' => '', 'type' => '', 'ring_category' => '', 'gender' => '', 'color' => '', 'category' => '', 'shape' => '', 'style' => '', 'facet' => '', 'price' => '', 'sort' => 'featured'])) ?>">View All Products</a>
      </div>
    <?php else: ?>
      <div class="sl-grid">
        <?php foreach ($filteredProducts as $product): ?>
          <?php render_shop_listing_card($product); ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-sl-carousel]').forEach(function (car) {
    var track = car.querySelector('[data-sl-track]');
    var prev = car.querySelector('[data-sl-prev]');
    var next = car.querySelector('[data-sl-next]');
    if (!track || !prev || !next) return;
    var step = function () {
      var item = track.querySelector('[data-sl-item]');
      var gap = parseFloat(window.getComputedStyle(track).gap || '0') || 0;
      return item ? item.getBoundingClientRect().width + gap : track.clientWidth * 0.8;
    };
    var move = function (dir) {
      var max = track.scrollWidth - track.clientWidth;
      if (dir > 0 && track.scrollLeft >= max - 4) { track.scrollTo({ left: 0, behavior: 'smooth' }); return; }
      if (dir < 0 && track.scrollLeft <= 4) { track.scrollTo({ left: max, behavior: 'smooth' }); return; }
      track.scrollBy({ left: dir * step(), behavior: 'smooth' });
    };
    prev.addEventListener('click', function () { move(-1); });
    next.addEventListener('click', function () { move(1); });
  });
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
