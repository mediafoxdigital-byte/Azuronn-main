<?php
/**
 * functions.php
 * Shared utility helpers for safe output, CSRF tokens, content rendering, etc.
 */
declare(strict_types=1);

function e(string $value): void
{
    echo htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_root_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $directory = ($directory === '.' || $directory === '/') ? '' : $directory;

    $scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $basePath = defined('BASE_PATH') ? str_replace('\\', '/', BASE_PATH) : '';

    if ($scriptFile !== '' && $basePath !== '' && str_starts_with($scriptFile, $basePath)) {
        $relativeDir = trim(substr(str_replace('\\', '/', dirname($scriptFile)), strlen($basePath)), '/');
        if ($relativeDir !== '') {
            $suffix = '/' . $relativeDir;
            if ($directory !== '' && str_ends_with($directory, $suffix)) {
                $directory = substr($directory, 0, -strlen($suffix));
            } else {
                $segments = explode('/', trim($directory, '/'));
                $relativeSegments = explode('/', $relativeDir);
                while ($relativeSegments !== [] && $segments !== [] && end($segments) === end($relativeSegments)) {
                    array_pop($segments);
                    array_pop($relativeSegments);
                }
                $directory = $segments === [] ? '' : '/' . implode('/', $segments);
            }
        }
    }

    return $directory === '/' ? '' : $directory;
}

function app_path_url(string $path): string
{
    $root = app_root_path();
    $cleanPath = ltrim($path, '/');

    if ($cleanPath === '') {
        return $root === '' ? '/' : $root . '/';
    }

    return ($root === '' ? '' : $root) . '/' . $cleanPath;
}

function url(string $path): string
{
    return app_path_url($path);
}

function asset_url(string $path): string
{
    $url = app_path_url($path);
    $cleanPath = ltrim($path, '/');
    $absolutePath = defined('BASE_PATH') ? BASE_PATH . '/' . $cleanPath : '';

    if ($absolutePath !== '' && is_file($absolutePath)) {
        $version = (string) filemtime($absolutePath);
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'v=' . rawurlencode($version);
    }

    return $url;
}

/**
 * Return a stable local image for homepage category/style cards.
 *
 * Admin content may contain an old demo URL or a hosting upload that is no
 * longer present after a deployment. The homepage should still render a real
 * image in either case, so the fallback is selected from the card's category.
 */
function homepage_image_fallback(string $kind = '', string $label = '', string $type = ''): string
{
    $haystack = strtolower(trim($kind . ' ' . $label . ' ' . $type));
    $path = match (true) {
        str_contains($haystack, 'earring') => 'assets/uploads/earring_collection_bg.png',
        str_contains($haystack, 'pendant') => 'assets/uploads/pendant_collection_bg.png',
        str_contains($haystack, 'bracelet'), str_contains($haystack, 'bangle') => 'assets/uploads/bracelet_collection_bg.png',
        str_contains($haystack, 'necklace') => 'assets/uploads/necklace_collection_bg.png',
        str_contains($haystack, 'mangalsutra') => 'assets/uploads/mangalsutra_collection_bg.png',
        str_contains($haystack, 'ring') => 'assets/uploads/ring_collection_bg.png',
        default => 'assets/uploads/shop_collection_bg.png',
    };

    return asset_url($path);
}

/**
 * Resolve a homepage card image without rewriting a valid stored URL.
 *
 * The hosting filesystem is not always the same filesystem that serves a
 * public URL (CDN, mounted upload volume, or a different document root), so a
 * server-side is_file() check can incorrectly replace a real image. Only an
 * empty value gets a server fallback; browser load errors are handled by the
 * data-image-fallback handler in main.js.
 */
function homepage_image_source(mixed $value, string $kind = '', string $label = '', string $type = ''): string
{
    $source = clean_image((string) $value);
    return $source !== '' ? $source : homepage_image_fallback($kind, $label, $type);
}

function media_asset_type(string $path): string
{
    $cleanPath = strtolower(parse_url(trim($path), PHP_URL_PATH) ?? trim($path));
    $extension = pathinfo($cleanPath, PATHINFO_EXTENSION);

    return in_array($extension, ['mp4', 'webm', 'ogv', 'mov', 'm4v'], true) ? 'video' : 'image';
}

function media_asset_mime(string $path): string
{
    $cleanPath = strtolower(parse_url(trim($path), PHP_URL_PATH) ?? trim($path));
    $extension = pathinfo($cleanPath, PATHINFO_EXTENSION);

    return match ($extension) {
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
        default => 'video/mp4',
    };
}

function store_media_markup(string $path, string $alt, string $className = '', bool $controls = false): string
{
    $resolvedPath = clean_image($path);
    if ($resolvedPath === '') {
        return '';
    }

    $classAttr = $className !== '' ? ' class="' . h($className) . '"' : '';
    if (media_asset_type($resolvedPath) === 'video') {
        $videoAttrs = $controls ? ' controls' : ' muted autoplay loop aria-hidden="true"';
        return '<video' . $classAttr . $videoAttrs . ' playsinline preload="metadata"><source src="' . h($resolvedPath) . '" type="' . h(media_asset_mime($resolvedPath)) . '"></video>';
    }

    return '<img' . $classAttr . ' src="' . h($resolvedPath) . '" alt="' . h($alt) . '">';
}

function resolve_link(string $path): string
{
    if ($path === '' || $path === '#') {
        return '#';
    }

    if (preg_match('~^(?:https?:)?//~i', $path) || preg_match('~^(mailto:|tel:)~i', $path)) {
        return $path;
    }

    return app_path_url($path);
}

function news_items(): array
{
    return array_values(array_filter(site_content()['news']['items'] ?? [], 'is_array'));
}

function news_article_url(array $story): string
{
    $id = clean_string((string) ($story['id'] ?? ''), 80);
    if ($id === '') {
        $id = content_id('news', $story, 0, 'title');
    }

    return resolve_link('/news/?article=' . rawurlencode($id));
}

function find_news_article(string $articleId): ?array
{
    $articleId = clean_string($articleId, 80);
    if ($articleId === '') {
        return null;
    }

    foreach (news_items() as $story) {
        if ((string) ($story['id'] ?? '') === $articleId) {
            return $story;
        }
    }

    return null;
}

function news_article_body(array $story): string
{
    $body = trim((string) ($story['body'] ?? ''));
    if ($body !== '') {
        return $body;
    }

    $excerpt = clean_string((string) ($story['excerpt'] ?? ''), 12000);
    return $excerpt !== '' ? clean_rich_text($excerpt, 12000) : '';
}

function news_article_text(array $story): string
{
    return rich_text_plain_text(news_article_body($story));
}

function news_article_read_time(array $story): int
{
    $body = trim((string) ($story['title'] ?? '') . ' ' . news_article_text($story));
    if ($body === '') {
        return 2;
    }

    $wordCount = str_word_count(strip_tags($body));
    return max(2, (int) ceil($wordCount / 180));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token'])
        && is_string($submitted)
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

function sanitize_text(string $input): string
{
    return trim(strip_tags($input));
}

function sanitize_email(string $email): string
{
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function catalog_product_map(): array
{
    $items = site_content()['products']['items'] ?? [];
    $map = [];
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }
        $map[(string) $item['id']] = $item;
    }
    return $map;
}

function catalog_expand_array(array $products): array
{
    $expanded = [];
    foreach ($products as $p) {
        $hasVariations = false;
        if (!empty($p['metal_variations'])) {
            foreach ($p['metal_variations'] as $mv) {
                if ($mv['active'] ?? false) {
                    $hasVariations = true;
                    break;
                }
            }
        }

        if ($hasVariations) {
            $hasActiveMetal = false;
            foreach ($p['metal_variations'] as $idx => $mv) {
                if ($mv['active'] ?? false) {
                    $hasActiveMetal = true;
                    $clone = $p;
                    $clone['original_id'] = $p['id'];
                    $clone['id'] = $p['id'] . '__metal_' . content_slug($mv['metal'], 'm'.$idx);
                    $clone['name'] = $p['name'] . ' - ' . $mv['metal'];
                    $clone['new_price'] = money_format((float)($mv['price'] ?? 0));
                    $clone['old_price'] = $mv['old_price'] ?? '';
                    if (!empty($mv['description'])) {
                        $clone['description'] = $mv['description'];
                    }
                    if (!empty($mv['features']) && is_array($mv['features'])) {
                        $clone['features'] = $mv['features'];
                    }
                    if (!empty($mv['gallery']) && is_array($mv['gallery'])) {
                        $clone['default_image'] = $mv['gallery'][0];
                        $clone['popup_image'] = $mv['gallery'][0];
                        $clone['gallery'] = $mv['gallery'];
                        if (isset($mv['gallery'][1]) && $mv['gallery'][1] !== '') {
                            $clone['hover_image'] = $mv['gallery'][1];
                        }
                    } elseif (!empty($mv['image'])) {
                        $clone['default_image'] = $mv['image'];
                        $clone['popup_image'] = $mv['image'];
                        $clone['gallery'] = [$mv['image']];
                    } else {
                        // Fallback to base product images if metal has no specific images
                        $clone['default_image'] = $p['default_image'] ?? '';
                        $clone['popup_image'] = $p['popup_image'] ?? ($p['default_image'] ?? '');
                        $clone['hover_image'] = $p['hover_image'] ?? ($p['default_image'] ?? '');
                        $clone['gallery'] = $p['gallery'] ?? [];
                    }

                    $clone['url_metal_param'] = content_slug($mv['metal'], 'metal');
                    $clone['color'] = $mv['metal'];
                    $expanded[] = $clone;
                }
            }
            if (!$hasActiveMetal) {
                $expanded[] = $p;
            }
        } else {
            $expanded[] = $p;
        }
    }
    return $expanded;
}

function products_by_ids(array $ids): array
{
    $map = catalog_product_map();
    $products = [];
    foreach ($ids as $id) {
        $key = (string) $id;
        if (isset($map[$key])) {
            $products[] = $map[$key];
        }
    }
    return catalog_sort_by_inventory(catalog_expand_array($products));
}

function catalog_sort_by_inventory(array $products): array
{
    $decorated = [];
    foreach (array_values($products) as $index => $product) {
        if (!is_array($product)) {
            continue;
        }

        $status = function_exists('product_inventory_status')
            ? product_inventory_status($product, ['metal' => (string) ($product['color'] ?? '')])
            : ['out_of_stock' => false];

        $decorated[] = [
            'index' => $index,
            'priority' => !empty($status['out_of_stock']) ? 1 : 0,
            'product' => $product,
        ];
    }

    usort($decorated, static function (array $left, array $right): int {
        if ($left['priority'] !== $right['priority']) {
            return $left['priority'] <=> $right['priority'];
        }

        return $left['index'] <=> $right['index'];
    });

    return array_values(array_map(static fn (array $item): array => $item['product'], $decorated));
}

function catalog_products(bool $activeOnly = true): array
{
    $items = site_content()['products']['items'] ?? [];
    if (!$activeOnly) {
        return catalog_sort_by_inventory(array_values(array_filter($items, 'is_array')));
    }

    return catalog_sort_by_inventory(array_values(array_filter($items, static function (mixed $item): bool {
        return is_array($item) && strtolower((string) ($item['status'] ?? 'active')) === 'active';
    })));
}

function catalog_expanded_products(bool $activeOnly = true): array
{
    return catalog_expand_array(catalog_products($activeOnly));
}


/**
 * The attribute-profile type backing a ring section. Engagement Rings and
 * Wedding Rings are separate categories with separate profiles, so each section
 * resolves to its own — that is what lets them offer different metals.
 */
function ring_section_profile_type(string $ringCategory): string
{
    $protected = catalog_protected_categories();
    $section = strtolower(trim($ringCategory)) === 'wedding' ? 'wedding' : 'engagement';

    return $protected[$section]['title'];
}

/**
 * The attribute-profile type a stored product reads its options from.
 *
 * Ring products all store product_type='Ring' (every /shop/ URL keys off it) and
 * carry their real category in ring_category, so resolving a profile from
 * product_type alone collapses every ring — wedding bands included — onto the
 * Engagement Rings profile. Always resolve through here instead.
 */
function product_attribute_profile_type(array $product): string
{
    $ringCategory = product_ring_taxonomy($product)['category'];
    if ($ringCategory !== '') {
        return ring_section_profile_type($ringCategory);
    }

    return catalog_canonical_type((string) ($product['product_type'] ?? ''));
}

/**
 * Style cards for one ring section. Each section reads its OWN profile
 * (Engagement Rings / Wedding Rings) and only that profile's own section list,
 * so wedding styles can never surface under engagement or vice versa.
 *
 * With no section the caller is section-agnostic (search index, keyword
 * lookups), so both sections are merged instead of silently defaulting to
 * engagement's list.
 */
function available_ring_style_cards(string $ringCategory = ''): array
{
    $ringCategory = strtolower(trim($ringCategory));
    if (!in_array($ringCategory, ['engagement', 'wedding'], true)) {
        return available_ring_style_cards('engagement') + available_ring_style_cards('wedding');
    }

    $profile = catalog_attribute_profile(ring_section_profile_type($ringCategory));

    // The section's own list wins; the flat style_cards list is the fallback for
    // a profile saved before the per-section split.
    $profileCards = array_values((array) ($profile['style_cards_sections'][$ringCategory] ?? []));
    if ($profileCards === []) {
        $profileCards = array_values((array) ($profile['style_cards'] ?? []));
    }

    $cards = [];
    foreach ($profileCards as $card) {
        if (!is_array($card)) {
            continue;
        }

        $value = clean_string((string) ($card['value'] ?? ''), 80);
        if ($value === '') {
            continue;
        }

        $cards[$value] = [
            'value' => $value,
            'label' => clean_string((string) ($card['label'] ?? $value), 120),
            'image' => clean_image((string) ($card['image'] ?? '')),
        ];
    }

    return $cards;
}

function available_ring_styles(string $ringCategory = ''): array
{
    $styles = [];
    foreach (available_ring_style_cards($ringCategory) as $styleKey => $card) {
        $label = clean_string((string) ($card['label'] ?? ''), 120);
        if ($label === '') {
            $label = ucwords(str_replace('-', ' ', $styleKey));
        }
        $styles[$styleKey] = $label;
    }
    return $styles;
}

/**
 * Ring taxonomy for a product: which ring section it belongs to and who it is for.
 * Legacy products without explicit fields are inferred so un-migrated data still
 * sorts sensibly: rings with diamond shapes read as engagement; plain rings read
 * as wedding (the classic band case). Non-ring products return empty values.
 */
function product_ring_taxonomy(array $product): array
{
    $typeKey = strtolower((string) ($product['product_type'] ?? ''));
    if (!str_starts_with($typeKey, 'ring')) {
        return ['category' => '', 'gender' => ''];
    }

    $category = strtolower(clean_string((string) ($product['ring_category'] ?? ''), 40));
    if (!in_array($category, ['engagement', 'wedding'], true)) {
        $category = (array) ($product['diamondShapes'] ?? []) !== [] ? 'engagement' : 'wedding';
    }

    $gender = strtolower(clean_string((string) ($product['ring_gender'] ?? ''), 40));
    if (!in_array($gender, ['mens', 'womens'], true)) {
        $gender = '';
    }

    return ['category' => $category, 'gender' => $gender];
}

function ring_section_label(string $ringCategory, string $gender = ''): string
{
    $ringCategory = strtolower($ringCategory);
    $gender = strtolower($gender);

    if ($ringCategory === 'engagement') {
        return 'Engagement Rings';
    }
    if ($ringCategory === 'wedding') {
        if ($gender === 'mens') {
            return "Men's Wedding Rings";
        }
        if ($gender === 'womens') {
            return "Women's Wedding Rings";
        }
        return 'Wedding Rings';
    }

    return 'Rings';
}

/**
 * The two gender entry cards ("Men's Wedding Rings" / "Women's Wedding Rings")
 * rendered as jewellery-style image boxes in the Wedding Rings mega-menu and as
 * the filter box row on the wedding listing. Single source of truth so the nav
 * and the shop page stay in lockstep; callers build the URLs (nav uses its
 * section query, the shop page its $buildShopUrl) and the active state.
 */
function ring_gender_box_cards(): array
{
    return [
        'womens' => ['key' => 'womens', 'label' => "Women's Wedding Rings", 'image' => '/assets/uploads/ring_collection_bg.png'],
        'mens' => ['key' => 'mens', 'label' => "Men's Wedding Rings", 'image' => '/assets/uploads/proposal-3.webp'],
    ];
}

/**
 * Storefront category label for a product: "Engagement Rings",
 * "Wedding Rings — Men's", "Earrings", etc. Used across the admin UI so every
 * product list shows a clean category instead of raw type strings.
 */
function product_category_label(array $product): string
{
    $taxonomy = product_ring_taxonomy($product);
    if ($taxonomy['category'] !== '') {
        $label = ring_section_label($taxonomy['category']);
        if ($taxonomy['category'] === 'wedding' && $taxonomy['gender'] !== '') {
            $label .= ' — ' . ($taxonomy['gender'] === 'mens' ? "Men's" : "Women's");
        }
        return $label;
    }

    $type = clean_string((string) ($product['product_type'] ?? ''), 80);
    return $type !== '' ? homepage_style_type_label($type) : 'Uncategorized';
}

/**
 * Singular/plural spellings that mean the same catalogue type, the ring-section
 * title list, and the real-type derivation all live in content.php, because
 * config.php calls site_content() before this file is loaded.
 * @see catalog_type_aliases(), catalog_canonical_type(),
 *      catalog_ring_section_titles(), catalog_active_product_types()
 */

/**
 * The unified category choices offered on the product form, built from the real
 * categories the merchant created. Engagement Rings and Wedding Rings are two
 * separate entries; a wedding product's men's/women's split is a distinct
 * Gender field, not extra category rows. Ring entries keep product_type='Rings'
 * plus ring_category so existing products and every /shop/ URL keep working.
 */
function product_category_taxonomy_options(): array
{
    $content = site_content();
    $options = [];

    foreach (catalog_protected_categories() as $section => $definition) {
        // Only the ring sections share product_type='Rings' + ring_category.
        // Non-ring protected categories (Pendant, Earrings) carry their own
        // canonical type, so their profile and metal matrix are their own.
        $options[$section] = [
            'label' => $definition['title'],
            'product_type' => $definition['ring_category'] !== '' ? 'Rings' : catalog_canonical_type($definition['title']),
            'ring_category' => $definition['ring_category'],
            'ring_gender' => '',
        ];
    }

    foreach (catalog_active_product_types($content) as $type) {
        // Skip anything a protected entry already covers, otherwise Pendant and
        // Earrings would each appear twice in the dropdown.
        if (catalog_protected_category_key($type) !== '') {
            continue;
        }
        $options['custom-' . strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $type))] = [
            'label' => $type,
            'product_type' => $type,
            'ring_category' => '',
            'ring_gender' => '',
        ];
    }

    return $options;
}

/**
 * The taxonomy option key a product currently maps to (for preselecting the
 * Category dropdown). Unknown/custom types fall back to a canonical key
 * derived from their product_type, which is stable across admin loads.
 */
function product_category_taxonomy_key(array $product): string
{
    $taxonomy = product_ring_taxonomy($product);
    // Gender is its own field now, so a wedding ring maps to one 'wedding' key
    // whether it is a men's or women's band.
    if (in_array($taxonomy['category'], ['engagement', 'wedding'], true)) {
        return $taxonomy['category'];
    }

    $type = clean_string((string) ($product['product_type'] ?? ''), 80);
    if ($type === '') {
        return '';
    }

    // Non-ring protected categories have their own option key, not a 'custom-'
    // one, so a stored Pendant/Earring product preselects the right entry.
    $protectedKey = catalog_protected_category_key($type);
    if ($protectedKey !== '') {
        return $protectedKey;
    }

    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $type));
    return 'custom-' . $slug;
}


/**
 * Per-category "Shop by Style" selector cards. Source of truth is the real
 * profile the merchant configured in Attributes — never mix in the hardcoded
 * demo cards once a real profile is stored. Demo cards are only used as an
 * empty-install seed when no profile exists yet, and must not carry bogus
 * demo product IDs (they would make every facet link report "0 products").
 */
function available_collection_selector_cards(string $type): array
{
    $profile = catalog_attribute_profile($type);
    $profileCards = array_values((array) ($profile['selector_cards'] ?? []));

    $cards = [];
    foreach ($profileCards as $card) {
        if (!is_array($card)) {
            continue;
        }
        $value = clean_string((string) ($card['value'] ?? ''), 80);
        if ($value === '') {
            continue;
        }
        $cards[$value] = [
            'value' => $value,
            'label' => clean_string((string) ($card['label'] ?? $value), 120),
            'image' => clean_image((string) ($card['image'] ?? '')),
            'product_ids' => array_values((array) ($card['product_ids'] ?? [])),
        ];
    }

    // No demo seed. The merchant's Attributes studio is the only source of
    // selector cards, so an unconfigured category shows an empty state that
    // links to the editor rather than fake styles.
    return $cards;
}

function homepage_style_type_label(string $type): string
{
    return match (strtolower(trim($type))) {
        'ring', 'rings' => 'Rings',
        'earring', 'earrings' => 'Earrings',
        'bracelet', 'bracelets', 'bangles', 'bangles & bracelets' => 'Bangles & Bracelets',
        'necklace', 'necklaces' => 'Necklaces',
        'pendant', 'pendants' => 'Pendants',
        'brooch', 'brooches' => 'Brooches',
        'jewellery set', 'jewellery sets' => 'Jewellery Sets',
        default => $type,
    };
}

function homepage_style_showcase_options(): array
{
    $content = site_content();
    $types = catalog_active_product_types($content);

    $options = [];
    foreach ($types as $type) {
        if ($type === '') {
            continue;
        }

        $ringSection = catalog_category_ring_section($type);

        if ($ringSection !== '') {
            // Ring categories keep the 'ring::<section>::<style>' key shape so
            // homepage Shop-by-Style assignments saved before the split still
            // resolve after Engagement/Wedding became separate categories.
            foreach (available_ring_style_cards($ringSection) as $styleValue => $card) {
                $label = clean_string((string) ($card['label'] ?? $styleValue), 120);
                if ($label === '') {
                    continue;
                }
                $key = 'ring::' . $ringSection . '::' . clean_string((string) $styleValue, 80);
                $options[$key] = [
                    'id' => $key,
                    'type' => 'Ring',
                    'type_label' => $type,
                    'value' => clean_string((string) $styleValue, 80),
                    'label' => $label,
                    'image' => clean_image((string) ($card['image'] ?? '')),
                    'url' => resolve_link('/shop/?' . http_build_query(['type' => 'Ring', 'ring_category' => $ringSection, 'style' => $styleValue])),
                ];
            }
            continue;
        }

        foreach (available_collection_selector_cards($type) as $styleValue => $card) {
            $label = clean_string((string) ($card['label'] ?? $styleValue), 120);
            if ($label === '') {
                continue;
            }
            $key = strtolower($type) . '::' . clean_string((string) $styleValue, 80);
            $options[$key] = [
                'id' => $key,
                'type' => $type,
                'type_label' => homepage_style_type_label($type),
                'value' => clean_string((string) $styleValue, 80),
                'label' => $label,
                'image' => clean_image((string) ($card['image'] ?? '')),
                'url' => resolve_link('/shop/?' . http_build_query(['type' => $type, 'facet' => $styleValue])),
            ];
        }
    }

    return $options;
}

function homepage_style_showcase_cards(): array
{
    $options = homepage_style_showcase_options();
    $selectedIds = array_values(array_filter(array_map(
        static fn (mixed $value): string => clean_string((string) $value, 120),
        (array) (site_content()['shop_by_style']['style_ids'] ?? [])
    ), static fn (string $value): bool => $value !== ''));

    $cards = [];
    foreach ($selectedIds as $id) {
        if (isset($options[$id])) {
            $cards[] = $options[$id];
        }
    }

    return $cards;
}

/**
 * Diamond shapes keyed by slug. Built from the real diamond_shapes items the
 * merchant manages (Homepage Content → Diamond Shapes), with the legacy 10 as
 * fallback only when no items have been created yet — a fresh-install seed.
 */
function available_diamond_shapes(): array
{
    $shapes = [];
    foreach ((array) (site_content()['diamond_shapes']['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        if ($slug === '') {
            continue;
        }
        // The shape's own name ("Round", "Emerald") is what every caller means by
        // a shape label. `label` is the marketing tagline shown on the homepage
        // card ("Classic Brilliance"), so using it here renamed every shape in
        // the admin selects, the shop filter and the search index.
        $shapes[$slug] = $name;
    }

    // Seed defaults only when nothing real exists yet.
    if ($shapes === []) {
        return [
            'round' => 'Round',
            'oval' => 'Oval',
            'cushion' => 'Cushion',
            'princess' => 'Princess',
            'emerald' => 'Emerald',
            'pear' => 'Pear',
            'marquise' => 'Marquise',
            'radiant' => 'Radiant',
            'asscher' => 'Asscher',
            'heart' => 'Heart'
        ];
    }

    return $shapes;
}

function header_search_normalize_term(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? '';
    return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
}

function header_search_normalize_catalog_type(string $type): string
{
    return match (header_search_normalize_term($type)) {
        'ring', 'rings' => 'Ring',
        'earring', 'earrings' => 'Earring',
        'bracelet', 'bracelets', 'bangles', 'bangles bracelets', 'bangles bracelet' => 'Bracelet',
        'necklace', 'necklaces', 'neckless', 'necklesses' => 'Necklace',
        'pendant', 'pendants' => 'Pendant',
        'mangalsutra', 'mangalsutras' => 'Mangalsutra',
        default => clean_string($type, 80),
    };
}

function header_search_collection_meta(): array
{
    return [
        'Ring' => [
            'label' => 'Rings',
            'subtitle' => 'Shop Collection',
            'aliases' => ['ring', 'rings', 'engagement ring', 'engagement rings'],
        ],
        'Earring' => [
            'label' => 'Earrings',
            'subtitle' => 'Shop Collection',
            'aliases' => ['earring', 'earrings', 'stud', 'studs', 'hoop', 'hoops', 'drop earring', 'drop earrings'],
        ],
        'Pendant' => [
            'label' => 'Pendants',
            'subtitle' => 'Shop Collection',
            'aliases' => ['pendant', 'pendants'],
        ],
        'Bracelet' => [
            'label' => 'Bangles & Bracelets',
            'subtitle' => 'Shop Collection',
            'aliases' => ['bracelet', 'bracelets', 'bangle', 'bangles'],
        ],
        'Necklace' => [
            'label' => 'Necklaces',
            'subtitle' => 'Shop Collection',
            'aliases' => ['necklace', 'necklaces'],
        ],
        'Mangalsutra' => [
            'label' => 'Mangalsutra',
            'subtitle' => 'Shop Collection',
            'aliases' => ['mangalsutra', 'mangalsutras'],
        ],
    ];
}

function header_search_index(): array
{
    static $index = null;
    if (is_array($index)) {
        return $index;
    }

    $content = site_content();
    $collectionMeta = header_search_collection_meta();
    $products = catalog_products();
    $categoryImages = [];
    foreach ((array) ($content['category_cards'] ?? []) as $card) {
        if (!is_array($card)) {
            continue;
        }

        $title = clean_string((string) ($card['title'] ?? ''), 80);
        if ($title === '') {
            continue;
        }

        $categoryImages[header_search_normalize_term($title)] = clean_image((string) ($card['image'] ?? ''));
    }

    $diamondShapeImages = [];
    foreach ((array) ($content['diamond_shapes']['items'] ?? []) as $shapeItem) {
        if (!is_array($shapeItem)) {
            continue;
        }

        $shapeName = clean_string((string) ($shapeItem['name'] ?? ''), 80);
        if ($shapeName === '') {
            continue;
        }

        $diamondShapeImages[header_search_normalize_term($shapeName)] = clean_image((string) ($shapeItem['icon_image'] ?? $shapeItem['image'] ?? ''));
    }

    $index = [];
    $seen = [];
    $addSuggestion = static function (array $suggestion) use (&$index, &$seen): void {
        $label = clean_string((string) ($suggestion['label'] ?? ''), 140);
        $url = clean_string((string) ($suggestion['url'] ?? ''), 500);
        if ($label === '' || $url === '') {
            return;
        }

        $key = header_search_normalize_term($label) . '|' . $url;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $searchTextParts = [];
        foreach ((array) ($suggestion['search_text'] ?? []) as $part) {
            $cleanPart = clean_string((string) $part, 240);
            if ($cleanPart !== '') {
                $searchTextParts[] = $cleanPart;
            }
        }

        $index[] = [
            'label' => $label,
            'subtitle' => clean_string((string) ($suggestion['subtitle'] ?? ''), 120),
            'url' => $url,
            'kind' => clean_string((string) ($suggestion['kind'] ?? 'search'), 40),
            'image' => clean_image((string) ($suggestion['image'] ?? '')),
            'search_text' => header_search_normalize_term(implode(' ', array_merge([$label], $searchTextParts))),
        ];
    };

    $firstProductImageByType = [];
    $productColorsByType = [];
    foreach ($products as $product) {
        $normalizedType = header_search_normalize_catalog_type((string) ($product['product_type'] ?? ''));
        if (!isset($collectionMeta[$normalizedType])) {
            continue;
        }

        $image = clean_image((string) ($product['default_image'] ?? $product['hover_image'] ?? $product['popup_image'] ?? ''));
        if ($image !== '' && !isset($firstProductImageByType[$normalizedType])) {
            $firstProductImageByType[$normalizedType] = $image;
        }

        $color = clean_string((string) ($product['color'] ?? ''), 80);
        if ($color !== '') {
            $productColorsByType[$normalizedType][] = $color;
        }
    }

    foreach ($collectionMeta as $typeKey => $meta) {
        $label = $meta['label'];
        $image = $categoryImages[header_search_normalize_term($label)] ?? ($firstProductImageByType[$typeKey] ?? '');
        $addSuggestion([
            'label' => $label,
            'subtitle' => $meta['subtitle'],
            'url' => resolve_link('/shop/?type=' . rawurlencode($typeKey)),
            'kind' => 'collection',
            'image' => $image,
            'search_text' => array_merge([$label], $meta['aliases']),
        ]);
    }

    // Each ring section contributes its own styles, and each suggestion links to
    // that section — a wedding style must not land on the engagement listing.
    foreach (['engagement', 'wedding'] as $searchRingSection) {
        $sectionLabel = ring_section_label($searchRingSection);
        foreach (available_ring_style_cards($searchRingSection) as $styleKey => $card) {
            $styleLabel = clean_string((string) ($card['label'] ?? ''), 120);
            if ($styleLabel === '') {
                $styleLabel = ucwords(str_replace('-', ' ', $styleKey));
            }
            $addSuggestion([
                'label' => $styleLabel . ' ' . $sectionLabel,
                'subtitle' => $sectionLabel . ' Style',
                'url' => resolve_link('/shop/?' . http_build_query([
                    'type' => 'Ring',
                    'ring_category' => $searchRingSection,
                    'style' => $styleKey,
                ])),
                'kind' => 'style',
                'image' => clean_image((string) ($card['image'] ?? '')),
                'search_text' => [$styleLabel, $styleKey, $sectionLabel, 'ring', 'rings', 'diamond ring'],
            ]);
        }
    }

    foreach (['Earring', 'Pendant', 'Bracelet', 'Necklace'] as $typeKey) {
        $meta = $collectionMeta[$typeKey] ?? null;
        if ($meta === null) {
            continue;
        }

        foreach (available_collection_selector_cards($typeKey) as $facetKey => $card) {
            $facetLabel = clean_string((string) ($card['label'] ?? $facetKey), 120);
            if ($facetLabel === '') {
                continue;
            }

            $addSuggestion([
                'label' => $facetLabel,
                'subtitle' => $meta['label'] . ' Category',
                'url' => resolve_link('/shop/?' . http_build_query(['type' => $typeKey, 'facet' => $facetKey])),
                'kind' => 'facet',
                'image' => clean_image((string) ($card['image'] ?? '')),
                'search_text' => array_merge([$facetLabel, $facetKey, $meta['label']], $meta['aliases']),
            ]);
        }
    }

    foreach (available_diamond_shapes() as $shapeKey => $shapeLabel) {
        $shapeImage = $diamondShapeImages[header_search_normalize_term($shapeLabel)] ?? ($firstProductImageByType['Ring'] ?? '');
        $addSuggestion([
            'label' => $shapeLabel . ' Diamond Rings',
            'subtitle' => 'Diamond Shape',
            'url' => resolve_link('/shop/?' . http_build_query(['type' => 'Ring', 'shape' => $shapeKey])),
            'kind' => 'shape',
            'image' => $shapeImage,
            'search_text' => [$shapeLabel, $shapeKey, $shapeLabel . ' diamond', $shapeLabel . ' diamonds', 'diamond ring', 'diamond rings', 'ring', 'rings'],
        ]);
    }

    $metalFamilies = [
        'gold' => 'Gold',
        'yellow gold' => 'Yellow Gold',
        'white gold' => 'White Gold',
        'rose gold' => 'Rose Gold',
        'silver' => 'Silver',
        'platinum' => 'Platinum',
    ];
    foreach ($collectionMeta as $typeKey => $meta) {
        $typeColors = array_map('header_search_normalize_term', array_values(array_unique($productColorsByType[$typeKey] ?? [])));
        $typeFacetCards = available_collection_selector_cards($typeKey);

        foreach ($metalFamilies as $queryValue => $displayLabel) {
            $matchFound = false;
            foreach ($typeColors as $colorValue) {
                if (($queryValue === 'gold' && str_contains($colorValue, 'gold')) || str_contains($colorValue, $queryValue)) {
                    $matchFound = true;
                    break;
                }
            }

            $matchedFacetKey = null;
            foreach ($typeFacetCards as $facetKey => $facetCard) {
                $facetLabel = header_search_normalize_term((string) ($facetCard['label'] ?? $facetKey));
                if ($facetLabel === '') {
                    continue;
                }

                if (($queryValue === 'gold' && str_contains($facetLabel, 'gold')) || str_contains($facetLabel, $queryValue)) {
                    if ($queryValue === 'gold' && $facetKey === 'yellow-gold') {
                        $matchedFacetKey = $facetKey;
                        break;
                    }
                    $matchedFacetKey ??= $facetKey;
                }
            }

            if (!$matchFound) {
                if ($matchedFacetKey === null) {
                    continue;
                }
            }

            $metalUrl = $matchFound
                ? resolve_link('/shop/?' . http_build_query(['type' => $typeKey, 'q' => $queryValue]))
                : resolve_link('/shop/?' . http_build_query(['type' => $typeKey, 'facet' => $matchedFacetKey]));

            $metalImage = $firstProductImageByType[$typeKey] ?? '';
            if ($metalImage === '' && $matchedFacetKey !== null) {
                $metalImage = clean_image((string) ($typeFacetCards[$matchedFacetKey]['image'] ?? ''));
            }

            $addSuggestion([
                'label' => $displayLabel . ' ' . $meta['label'],
                'subtitle' => 'Metal Match',
                'url' => $metalUrl,
                'kind' => 'metal',
                'image' => $metalImage,
                'search_text' => [$displayLabel, $queryValue, $meta['label'], $displayLabel . ' ' . $meta['label'], 'metal'],
            ]);
        }
    }

    foreach ($products as $product) {
        $productName = clean_string((string) ($product['name'] ?? ''), 140);
        if ($productName === '') {
            continue;
        }

        $normalizedType = header_search_normalize_catalog_type((string) ($product['product_type'] ?? ''));
        $productTypeLabel = $collectionMeta[$normalizedType]['label'] ?? clean_string((string) ($product['product_type'] ?? 'Jewellery'), 80);
        $keywords = [
            $productName,
            $productTypeLabel,
            (string) ($product['product_type'] ?? ''),
            (string) ($product['color'] ?? ''),
            (string) ($product['category'] ?? ''),
            (string) ($product['description'] ?? ''),
        ];

        foreach ((array) ($product['diamondShapes'] ?? []) as $shapeKey) {
            $shapeKey = clean_string((string) $shapeKey, 80);
            if ($shapeKey === '') {
                continue;
            }
            $keywords[] = $shapeKey;
            $keywords[] = available_diamond_shapes()[$shapeKey] ?? $shapeKey;
        }

        // Resolve each style label from the product's own ring section so a
        // wedding band's styles are named from the wedding list.
        $productStyleLabels = available_ring_styles(product_ring_taxonomy($product)['category']);
        foreach ((array) ($product['styles'] ?? []) as $styleKey) {
            $styleKey = clean_string((string) $styleKey, 80);
            if ($styleKey === '') {
                continue;
            }
            $keywords[] = $styleKey;
            $keywords[] = $productStyleLabels[$styleKey] ?? $styleKey;
        }

        $addSuggestion([
            'label' => $productName,
            'subtitle' => $productTypeLabel . ' Product',
            'url' => product_url($product),
            'kind' => 'product',
            'image' => clean_image((string) ($product['default_image'] ?? $product['hover_image'] ?? $product['popup_image'] ?? '')),
            'search_text' => $keywords,
        ]);
    }

    return $index;
}

function product_price_value(array $product): float
{
    $raw = (string) ($product['new_price'] ?? $product['old_price'] ?? '0');
    $normalized = preg_replace('/[^0-9.]/', '', $raw) ?? '0';
    return (float) $normalized;
}

/**
 * Diamond shapes a product actually offers, as stored.
 *
 * Products whose category prices per metal keep their shapes on each metal
 * variation rather than in the product-level list, so a filter that reads only
 * `diamondShapes` never matches them. Mirrors the product page's own order:
 * active metal variations first, product-level list as the fallback.
 *
 * @return list<string>
 */
function product_stored_diamond_shapes(array $product): array
{
    $shapes = [];
    $metalVariations = (array) ($product['metal_variations'] ?? []);
    $variantMetal = clean_string((string) ($product['url_metal_param'] ?? ''), 80);

    if ($variantMetal !== '') {
        $scopedVariations = array_values(array_filter($metalVariations, static function (mixed $variation) use ($variantMetal): bool {
            return is_array($variation)
                && !empty($variation['active'])
                && content_slug((string) ($variation['metal'] ?? ''), 'metal') === content_slug($variantMetal, 'metal');
        }));
        if ($scopedVariations !== []) {
            $metalVariations = $scopedVariations;
        }
    }

    foreach ($metalVariations as $variation) {
        if (!is_array($variation) || empty($variation['active']) || trim((string) ($variation['metal'] ?? '')) === '') {
            continue;
        }

        foreach ((array) ($variation['shapes'] ?? []) as $shape) {
            $shape = trim((string) $shape);
            if ($shape !== '' && !in_array($shape, $shapes, true)) {
                $shapes[] = $shape;
            }
        }
    }

    if ($shapes !== []) {
        return $shapes;
    }

    foreach ((array) ($product['diamondShapes'] ?? []) as $shape) {
        $shape = trim((string) $shape);
        if ($shape !== '' && !in_array($shape, $shapes, true)) {
            $shapes[] = $shape;
        }
    }

    return $shapes;
}

function filter_catalog_products(array $products, array $filters): array
{
    $type = sanitize_text((string) ($filters['type'] ?? ''));
    $color = sanitize_text((string) ($filters['color'] ?? ''));
    $category = sanitize_text((string) ($filters['category'] ?? ''));
    $query = sanitize_text((string) ($filters['q'] ?? ''));
    $shape = sanitize_text((string) ($filters['shape'] ?? ''));
    $sort = sanitize_text((string) ($filters['sort'] ?? 'featured'));
    $availableShapes = available_diamond_shapes();

    $filtered = array_values(array_filter($products, static function (array $product) use ($type, $color, $category, $query, $shape, $availableShapes): bool {
        if ($type !== '' && strcasecmp((string) ($product['product_type'] ?? ''), $type) !== 0) {
            return false;
        }
        if ($color !== '' && strcasecmp((string) ($product['color'] ?? ''), $color) !== 0) {
            return false;
        }
        if ($category !== '' && strcasecmp((string) ($product['category'] ?? ''), $category) !== 0) {
            return false;
        }

        $styleNames = [];
        $searchStyleLabels = available_ring_styles(product_ring_taxonomy($product)['category']);
        foreach ((array) ($product['styles'] ?? []) as $styleKey) {
            $styleKey = (string) $styleKey;
            $styleNames[] = $searchStyleLabels[$styleKey] ?? $styleKey;
        }

        $shapeNames = [];
        foreach (product_stored_diamond_shapes($product) as $shapeKey) {
            $shapeKey = (string) $shapeKey;
            $shapeNames[] = available_diamond_shapes()[$shapeKey] ?? $shapeKey;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) ($product['name'] ?? ''),
            (string) ($product['category'] ?? ''),
            (string) ($product['description'] ?? ''),
            (string) ($product['product_type'] ?? ''),
            (string) ($product['color'] ?? ''),
            implode(' ', (array) ($product['subcategories'] ?? [])),
            implode(' ', (array) ($product['features'] ?? [])),
            implode(' ', $styleNames),
            implode(' ', $shapeNames),
        ])));

        if ($query !== '' && !str_contains($haystack, strtolower($query))) {
            return false;
        }

        if ($shape !== '') {
            $requestedShape = strtolower(trim($shape));
            $shapeValues = [];

            // Shape matching uses ONLY shapes actually stored on the product. The
            // option-data fallback is intentionally NOT consulted here: it injects
            // default shapes that would make every ring match every shape filter.
            // Resolution order mirrors the product page: per-metal shapes from the
            // active metal variations first, then the product-level list.
            foreach (product_stored_diamond_shapes($product) as $shapeKey) {
                $normalizedShape = strtolower(trim((string) $shapeKey));
                if ($normalizedShape === '') {
                    continue;
                }

                $shapeValues[] = $normalizedShape;
                $shapeValues[] = content_slug($normalizedShape, 'shape');
                if (isset($availableShapes[$normalizedShape])) {
                    $shapeValues[] = strtolower($availableShapes[$normalizedShape]);
                }
            }

            $shapeValues = array_values(array_unique(array_filter($shapeValues, static fn (string $value): bool => $value !== '')));
            if (!in_array($requestedShape, $shapeValues, true)) {
                return false;
            }
        }

        return true;
    }));

    usort($filtered, static function (array $left, array $right) use ($sort): int {
        return match ($sort) {
            'price-low' => product_price_value($left) <=> product_price_value($right),
            'price-high' => product_price_value($right) <=> product_price_value($left),
            'name-asc' => strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')),
            'name-desc' => strcasecmp((string) ($right['name'] ?? ''), (string) ($left['name'] ?? '')),
            default => 0,
        };
    });

    return catalog_sort_by_inventory($filtered);
}

function render_product_card(array $product, array $extraParams = []): void
{
    $defaultImage = $product['default_image'] ?? '';
    $hoverImage = $product['hover_image'] ?? $defaultImage;
    $popupImage = $product['popup_image'] ?? $defaultImage;
    $productUrl = product_url($product, $extraParams);
    $customer = current_customer();
    $isWishlisted = customer_has_wishlist_product($customer, (string) ($product['id'] ?? ''));
    $returnTo = current_internal_url($productUrl);
    $cardVideoMime = static function (string $path): string {
        $extension = strtolower(pathinfo((string) (parse_url($path, PHP_URL_PATH) ?? $path), PATHINFO_EXTENSION));

        return match ($extension) {
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            'mov' => 'video/quicktime',
            'm4v' => 'video/x-m4v',
            default => 'video/mp4',
        };
    };
    $renderCardMedia = static function (string $path, string $className, string $alt) use ($cardVideoMime): string {
        $resolvedPath = clean_image($path);
        if ($resolvedPath === '') {
            return '';
        }

        if (media_asset_type($resolvedPath) === 'video') {
            return '<video class="' . h($className) . '" muted autoplay loop playsinline preload="metadata"><source src="' . h($resolvedPath) . '" type="' . h($cardVideoMime($resolvedPath)) . '"></video>';
        }

        return '<img class="' . h($className) . '" src="' . h($resolvedPath) . '" alt="' . h($alt) . '" loading="lazy" decoding="async">';
    };
    $inventoryStatus = function_exists('product_inventory_status')
        ? product_inventory_status($product, ['metal' => (string) ($product['color'] ?? '')])
        : ['out_of_stock' => false, 'low_stock' => false];
    $cardIsOutOfStock = !empty($inventoryStatus['out_of_stock']);
    ?>
    <div class="prod-card">
      <div class="prod-img-box">
        <?php if ($cardIsOutOfStock): ?>
          <span class="prod-stock-badge">OUT OF STOCK</span>
        <?php endif; ?>
        
        <a href="<?= h($productUrl) ?>" class="prod-img-link" aria-label="<?= h('View ' . ($product['name'] ?? 'product')) ?>">
          <?= $renderCardMedia($defaultImage, 'img-default blend-darken', (string) ($product['name'] ?? 'Product image')) ?>
          <?= $renderCardMedia($hoverImage, 'img-hover blend-darken', (string) (($product['name'] ?? 'Product image') . ' alternate view')) ?>
        </a>
      </div>
      
      <div class="prod-card-body">
        <div class="prod-name"><a href="<?= h($productUrl) ?>"><?= h($product['name'] ?? '') ?></a></div>
        <div class="prod-desc"><?= h($product['description'] ?? '') ?></div>
        
        <div class="prod-prices-premium">
          <span class="price-prefix">FROM</span>
          <span class="price-value"><?= h($product['new_price'] ?? $product['old_price'] ?? '') ?></span>
        </div>
        <a href="<?= h($productUrl) ?>" class="prod-hover-btn">Shop Now</a>
      </div>
    </div>
    <?php
}

/**
 * Premium listing card used ONLY by the category/shop grid (shop/index.php).
 * Kept separate from render_product_card() so the homepage rails, cart, product
 * "related" and collection grids are never affected. Adds the reference look:
 * a hover wishlist heart (real toggle that returns to the listing), metal swatch
 * dots, a hover image swap, and a "from £X" line.
 */
function render_shop_listing_card(array $product, array $filterSelection = []): void
{
    $defaultImage = clean_image((string) ($product['default_image'] ?? ''));
    $hoverImage = clean_image((string) ($product['hover_image'] ?? $product['default_image'] ?? ''));
    $cardOptions = function_exists('product_option_data') ? product_option_data($product) : [];
    $productParams = [];
    $selectedShape = strtolower(clean_string((string) ($filterSelection['shape'] ?? $filterSelection['diamond_shape'] ?? ''), 40));
    if ($selectedShape !== '') {
        $productParams['shape'] = $selectedShape;
    }

    $selectedMetalLabel = clean_string((string) ($filterSelection['color'] ?? ''), 120);
    $selectedMetalValue = clean_string((string) ($product['url_metal_param'] ?? ''), 80);
    if ($selectedMetalLabel !== '' && $selectedMetalValue === '') {
        foreach ((array) ($cardOptions['metal_options'] ?? []) as $metalOption) {
            $optionValue = clean_string((string) ($metalOption['value'] ?? ''), 80);
            $optionLabel = clean_string((string) ($metalOption['label'] ?? ''), 120);
            if ($optionValue !== '' && (strcasecmp($optionLabel, $selectedMetalLabel) === 0 || strcasecmp($optionValue, $selectedMetalLabel) === 0)) {
                $selectedMetalValue = $optionValue;
                $productParams['metal'] = $optionValue;
                break;
            }
        }

        if ($selectedMetalValue === '') {
            foreach ((array) ($cardOptions['color_choices'] ?? []) as $colorChoice) {
                $optionValue = clean_string((string) ($colorChoice['value'] ?? ''), 80);
                $optionLabel = clean_string((string) ($colorChoice['label'] ?? ''), 120);
                if ($optionValue !== '' && (strcasecmp($optionLabel, $selectedMetalLabel) === 0 || strcasecmp($optionValue, $selectedMetalLabel) === 0)) {
                    $productParams['color'] = $optionValue;
                    break;
                }
            }
        }
    }

    if ($selectedShape !== '' && $selectedMetalValue !== '' && function_exists('product_metal_shape_galleries')) {
        foreach ((array) ($product['metal_variations'] ?? []) as $metalVariation) {
            if (!is_array($metalVariation) || empty($metalVariation['active'])) {
                continue;
            }
            if (content_slug((string) ($metalVariation['metal'] ?? ''), 'metal') !== content_slug($selectedMetalValue, 'metal')) {
                continue;
            }

            $shapeGalleries = product_metal_shape_galleries($metalVariation);
            $shapeGallery = $shapeGalleries[$selectedShape] ?? [];
            if ($shapeGallery !== []) {
                $defaultImage = clean_image((string) ($shapeGallery[0] ?? ''));
                $hoverImage = clean_image((string) ($shapeGallery[1] ?? $defaultImage));
            }
            break;
        }
    }

    $productUrl = product_url($product, $productParams);
    $customer = current_customer();
    $isWishlisted = customer_has_wishlist_product($customer, (string) ($product['id'] ?? ''));
    $listingReturn = current_internal_url('/shop/');
    $inventorySelection = $selectedMetalValue !== ''
        ? ['metal' => $selectedMetalValue]
        : ['color' => (string) ($product['color'] ?? '')];
    $inventoryStatus = function_exists('product_inventory_status')
        ? product_inventory_status($product, $inventorySelection)
        : ['out_of_stock' => false];
    $cardIsOutOfStock = !empty($inventoryStatus['out_of_stock']);
    $hasHoverMedia = $hoverImage !== '' && $hoverImage !== $defaultImage;

    $renderListingMedia = static function (string $path, string $className, string $alt = ''): string {
        $resolvedPath = clean_image($path);
        if ($resolvedPath === '') {
            return '';
        }
        if (media_asset_type($resolvedPath) === 'video') {
            return '<video class="' . h($className) . '" src="' . h($resolvedPath) . '" muted autoplay loop playsinline preload="metadata" aria-hidden="true"></video>';
        }
        return '<img class="' . h($className) . '" src="' . h($resolvedPath) . '" alt="' . h($alt) . '" loading="lazy" decoding="async">';
    };

    // Hover swatches mirror the purchase page exactly: the same set of metals /
    // colours that product renders, and the same colour each shows there. For a
    // matrix product that is the per-metal color_hex picked in Attributes (the orb
    // colour on the product page); for jewellery it is the colour choices shown in
    // the selector. When a metal has no picked hex the ball falls back to a metallic
    // tone gradient — precisely what the product-page orb does with an empty
    // color_hex — so the card never disagrees with the page it links to.
    $cardToneFor = static function (string $label, string $tone = ''): string {
        $byTone = ['rose' => 'rose', 'yellow' => 'gold', 'gold' => 'gold', 'white' => 'white', 'platinum' => 'platinum', 'silver' => 'silver', 'classic' => 'white'];
        $normalizedTone = strtolower($tone);
        if ($normalizedTone !== '' && isset($byTone[$normalizedTone])) {
            return $byTone[$normalizedTone];
        }
        $needle = strtolower($label);
        if (str_contains($needle, 'rose')) {
            return 'rose';
        }
        if (str_contains($needle, 'white') || str_contains($needle, 'diamond')) {
            return 'white';
        }
        if (str_contains($needle, 'platinum') || str_contains($needle, 'plat')) {
            return 'platinum';
        }
        if (str_contains($needle, 'silver')) {
            return 'silver';
        }
        if (str_contains($needle, 'gold') || str_contains($needle, 'yellow')) {
            return 'gold';
        }
        return 'white';
    };

    $swatchItems = [];
    $pushSwatch = static function (string $label, string $hex, string $tone) use (&$swatchItems, $cardToneFor): void {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '';
        }
        $swatchItems[] = ['tone' => $cardToneFor($label, $tone), 'hex' => $hex];
    };

    // A matrix product's dots are its ticked metals and nothing else. Falling
    // through to the colour list here showed every catalogue colour on a product
    // that had no metal ticked, contradicting its own product page.
    if (!empty($cardOptions['is_matrix_product'])) {
        foreach ((array) ($cardOptions['metal_options'] ?? []) as $metalOption) {
            $pushSwatch((string) ($metalOption['label'] ?? ''), (string) ($metalOption['color_hex'] ?? ''), '');
            if (count($swatchItems) >= 6) {
                break;
            }
        }
    } elseif (!empty($cardOptions['color_choices'])) {
        foreach ((array) $cardOptions['color_choices'] as $colorChoice) {
            $pushSwatch((string) ($colorChoice['label'] ?? ''), '', (string) ($colorChoice['tone'] ?? ''));
            if (count($swatchItems) >= 6) {
                break;
            }
        }
    }

    // Last resort: a non-matrix product with no colour choices still gets the
    // single ball it had before, coloured by its base colour.
    if ($swatchItems === [] && empty($cardOptions['is_matrix_product']) && (string) ($product['color'] ?? '') !== '') {
        $pushSwatch((string) $product['color'], '', '');
    }

    $cardPrice = (string) ($product['new_price'] ?? $product['old_price'] ?? '');
    ?>
    <div class="sl-card">
      <div class="sl-card-media">
        <?php if ($cardIsOutOfStock): ?><span class="sl-card-badge">Out of Stock</span><?php endif; ?>
        <form method="post" action="<?= h($listingReturn) ?>" class="sl-card-wish">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="toggle-wishlist">
          <input type="hidden" name="product_id" value="<?= h((string) ($product['id'] ?? '')) ?>">
          <button type="submit" class="sl-card-wish-btn <?= $isWishlisted ? 'is-active' : '' ?>" aria-label="<?= h($isWishlisted ? 'Remove from wishlist' : 'Save to wishlist') ?>">
            <i class="<?= $isWishlisted ? 'fas' : 'far' ?> fa-heart"></i>
          </button>
        </form>
        <a href="<?= h($productUrl) ?>" class="sl-card-link <?= $hasHoverMedia ? 'has-hover-media' : '' ?>" aria-label="<?= h('View ' . ($product['name'] ?? 'product')) ?>">
          <?= $renderListingMedia($defaultImage, 'sl-card-img', (string) ($product['name'] ?? 'Product')) ?>
          <?php if ($hasHoverMedia): ?><?= $renderListingMedia($hoverImage, 'sl-card-img sl-card-img--hover') ?><?php endif; ?>
        </a>
        <?php if ($swatchItems !== []): ?>
        <div class="sl-card-swatches" aria-hidden="true">
          <?php foreach ($swatchItems as $swatchItem): ?>
            <span class="sl-swatch sl-swatch--<?= h($swatchItem['tone']) ?>"<?= $swatchItem['hex'] !== '' ? ' style="background:' . h($swatchItem['hex']) . ';"' : '' ?>></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="sl-card-info">
        <a href="<?= h($productUrl) ?>" class="sl-card-name"><?= h((string) ($product['name'] ?? '')) ?></a>
        <div class="sl-card-price">from <strong><?= h($cardPrice) ?></strong></div>
      </div>
    </div>
    <?php
}

require_once __DIR__ . '/storefront.php';
