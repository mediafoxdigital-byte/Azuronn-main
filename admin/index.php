<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin-auth.php';
require_once dirname(__DIR__) . '/includes/admin-requests.php';
require_once dirname(__DIR__) . '/includes/admin-employees.php';
require_once dirname(__DIR__) . '/includes/appointments.php';
require_once dirname(__DIR__) . '/includes/appointment-mail.php';
require_once dirname(__DIR__) . '/includes/stripe.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function admin_set_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
}

function admin_pull_flash(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    return is_array($flash) ? $flash : null;
}

// Upload notices live in their own session list so they survive a save case that
// calls admin_set_flash('success', ...) AFTER the upload helper ran (which would
// otherwise overwrite an error flash and hide the failure). Pulled once at the
// single banner site and rendered alongside the normal flash.
function admin_add_upload_notice(string $message): void
{
    $list = $_SESSION['admin_upload_notices'] ?? [];
    if (!is_array($list)) {
        $list = [];
    }
    $message = trim($message);
    if ($message !== '' && !in_array($message, $list, true)) {
        $list[] = $message;
    }
    $_SESSION['admin_upload_notices'] = $list;
}

function admin_pull_upload_notices(): array
{
    $list = $_SESSION['admin_upload_notices'] ?? [];
    unset($_SESSION['admin_upload_notices']);
    return is_array($list) ? array_values($list) : [];
}

function admin_allowed_views(): array
{
    // Homepage Content is intentionally hidden for now.
    // Keep the underlying content workflow code below so it can be restored later.
    $views = ['dashboard', 'categories', 'catalog', 'inventory', 'attributes', 'diamonds', 'news', 'newsletter', 'customers', 'orders', 'appointments', 'coupons', 'site'];
    $views[] = 'requests';
    if (admin_is_super_portal()) {
        $views[] = 'employees';
    }
    return $views;
}

function admin_entry_url(array $params = []): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/admin/index.php';
    if (!str_contains($script, '/admin/') && !str_contains($script, '/employee-admin/')) {
        $script = '/admin/index.php';
    }

    return $params === [] ? $script : $script . '?' . http_build_query($params);
}

function admin_portal_heading(): string
{
    return admin_is_employee_portal() ? 'Employee Admin' : 'Super Admin';
}

function admin_request_actor_label(array $request): string
{
    $actorName = clean_string((string) ($request['actor_name'] ?? ''), 120);
    $actorUsername = clean_string((string) ($request['actor_username'] ?? ''), 120);
    if ($actorName !== '' && $actorUsername !== '') {
        return $actorName . ' (@' . $actorUsername . ')';
    }
    return $actorName !== '' ? $actorName : ($actorUsername !== '' ? $actorUsername : 'employee');
}

function admin_visible_requests(array $requests): array
{
    if (admin_is_super_portal()) {
        return $requests;
    }

    $username = clean_string((string) ($_SESSION[admin_session_key()]['username'] ?? ''), 120);
    if ($username === '') {
        return [];
    }

    return array_values(array_filter($requests, static function (array $request) use ($username): bool {
        return clean_string((string) ($request['actor_username'] ?? ''), 120) === $username;
    }));
}

function admin_requestable_actions(): array
{
    return [
        'save-hero', 'save-celebs', 'save-settings', 'save-categories', 'save-attribute-profile',
        'adjust-metal-prices', 'save-product-attributes', 'save-diamonds', 'create-diamond', 'update-diamond', 'delete-diamond',
        'save-navigation', 'save-footer', 'create-product', 'update-product', 'delete-product', 'save-inventory',
        'save-catalog-assignments', 'create-news', 'update-news', 'delete-news', 'create-shape',
        'update-shape', 'delete-shape', 'ban-customer', 'unban-customer', 'delete-customer',
        'mark-order-status', 'resolve-order-request', 'create-coupon', 'update-coupon',
        'toggle-coupon', 'delete-coupon', 'save-social-gallery', 'save-faq'
    ];
}

function admin_action_summary(string $action, array $preparedPayload): string
{
    $kind = ucfirst(clean_string((string) ($preparedPayload['kind'] ?? 'update'), 20));
    $entity = clean_string((string) ($preparedPayload['entity'] ?? 'Request'), 80);
    $label = clean_string((string) ($preparedPayload['label'] ?? ''), 140);
    return trim($kind . ' ' . $entity . ($label !== '' ? ' • ' . $label : ''));
}

function admin_current_view(): string
{
    $view = sanitize_text((string) ($_GET['view'] ?? 'dashboard'));
    return in_array($view, admin_allowed_views(), true) ? $view : 'dashboard';
}

function admin_url(string $view, array $params = []): string
{
    return admin_entry_url(array_merge(['view' => $view], $params));
}

function admin_redirect(string $view, array $params = []): void
{
    $url = admin_url($view, $params);
    if (admin_has_queued_media_assets() && function_exists('fastcgi_finish_request')) {
        header('Location: ' . $url);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        fastcgi_finish_request();
        admin_flush_queued_media_assets();
        exit;
    }

    admin_flush_queued_media_assets();
    redirect($url);
}

function admin_html(string $value): string
{
    return h($value);
}

function admin_options_from_list(array $items, string $placeholder = ''): array
{
    $options = [];
    if ($placeholder !== '') {
        $options[''] = $placeholder;
    }
    foreach ($items as $item) {
        $value = clean_string((string) $item, 80);
        if ($value !== '') {
            $options[$value] = $value;
        }
    }
    return $options;
}

function admin_parse_lines(string $text, int $maxLength = 120): array
{
    $rows = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $value = clean_string($row, $maxLength);
        if ($value !== '' && !in_array($value, $items, true)) {
            $items[] = $value;
        }
    }
    return $items;
}

function admin_export_lines(array $items): string
{
    $lines = [];
    foreach ($items as $item) {
        if (is_scalar($item)) {
            $value = trim((string) $item);
            if ($value !== '') {
                $lines[] = $value;
            }
        }
    }
    return implode("\n", $lines);
}

function admin_parse_option_rows(string $text, array $keys): array
{
    $rows = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $items = [];
    foreach ($rows as $row) {
        $row = trim($row);
        if ($row === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $row));
        $entry = [];
        foreach ($keys as $index => $key) {
            $entry[$key] = $parts[$index] ?? '';
        }
        $items[] = $entry;
    }
    return $items;
}

function admin_export_option_rows(array $items, array $keys): string
{
    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = trim((string) ($item[$key] ?? ''));
        }
        $line = rtrim(implode('|', $parts), '|');
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return implode("\n", $lines);
}

function admin_newsletter_subscribers(array $content): array
{
    $items = supabase_list_newsletter_subscribers();
    usort($items, static function (array $left, array $right): int {
        return strcmp((string) ($right['subscribed_at'] ?? ''), (string) ($left['subscribed_at'] ?? ''));
    });
    return $items;
}

function admin_newsletter_csv_format_options(): array
{
    return [
        'email-only' => 'Email only',
        'name-email' => 'Name and email',
    ];
}

function admin_newsletter_source_label(string $source): string
{
    return match (strtolower($source)) {
        'account' => 'Account',
        'matched-email' => 'Matched Email',
        default => 'Guest',
    };
}

function admin_csv_safe_value(mixed $value): string
{
    $value = trim((string) $value);
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}

function admin_stream_newsletter_csv(array $subscribers, string $format): void
{
    $format = array_key_exists($format, admin_newsletter_csv_format_options()) ? $format : 'name-email';
    $filename = 'azuronn-newsletter-' . $format . '-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $handle = fopen('php://output', 'wb');
    if ($handle === false) {
        http_response_code(500);
        exit;
    }

    fwrite($handle, "\xEF\xBB\xBF");

    if ($format === 'email-only') {
        fputcsv($handle, ['Email']);
        foreach ($subscribers as $subscriber) {
            fputcsv($handle, [
                admin_csv_safe_value((string) ($subscriber['subscribed_email'] ?? '')),
            ]);
        }
    } else {
        fputcsv($handle, ['Account Holder Name', 'Newsletter Email']);
        foreach ($subscribers as $subscriber) {
            $accountHolder = clean_string((string) ($subscriber['account_holder_name'] ?? ''), 120);
            if ($accountHolder === '') {
                $accountHolder = 'Guest visitor';
            }

            fputcsv($handle, [
                admin_csv_safe_value($accountHolder),
                admin_csv_safe_value((string) ($subscriber['subscribed_email'] ?? '')),
            ]);
        }
    }

    fclose($handle);
    exit;
}

function admin_editor_scalar(array $product, array $profile, string $key, string $fallback = ''): string
{
    $value = $product[$key] ?? null;
    if (is_scalar($value) && trim((string) $value) !== '') {
        return (string) $value;
    }

    $profileValue = $profile[$key] ?? null;
    if (is_scalar($profileValue) && trim((string) $profileValue) !== '') {
        return (string) $profileValue;
    }

    return $fallback;
}

function admin_editor_list(array $product, array $profile, string $key): array
{
    $value = $product[$key] ?? null;
    if (is_array($value) && $value !== []) {
        return array_values($value);
    }

    $profileValue = $profile[$key] ?? null;
    if (is_array($profileValue) && $profileValue !== []) {
        return array_values($profileValue);
    }

    return [];
}

function admin_product_type_is_ring(string $type): bool
{
    return catalog_category_ring_section(clean_string($type, 80)) !== '';
}

/**
 * A category uses the metal matrix once it actually has metals — per-metal
 * pricing, sizes, bands and shapes only make sense when metals exist. Previously
 * a hardcoded type list decided this, so a merchant-created category could never
 * offer metals no matter what they configured.
 */
function admin_product_type_is_matrix(string $type): bool
{
    $type = clean_string($type, 80);
    if ($type === '') {
        return false;
    }

    return (array) (catalog_attribute_profile($type)['option_metal_options'] ?? []) !== [];
}

/**
 * Canonical attribute-profile type for any product-type spelling, so the
 * Attributes view shows one pill per real category ("Earrings" card title and
 * "Earring" product type both resolve to the Earring profile).
 */
function admin_canonical_attribute_type(string $type): string
{
    return catalog_canonical_type(clean_string($type, 80));
}

/**
 * Migrate legacy 'ring::<style>' homepage style assignment ids (saved before
 * the engagement/wedding split) to the current 'ring::engagement::<style>'
 * format, then drop anything that is no longer a valid option.
 */
function admin_migrate_style_assignment_ids(array $ids): array
{
    $migrated = [];
    foreach ($ids as $id) {
        $id = clean_string((string) $id, 120);
        if ($id === '') {
            continue;
        }
        if (preg_match('/^(ring|rings)::(?!engagement::|wedding::)(.+)$/', $id, $matches)) {
            $id = 'ring::engagement::' . $matches[2];
        }
        $migrated[] = $id;
    }
    return array_values(array_unique($migrated));
}

function admin_money_number(mixed $value): float
{
    $normalized = preg_replace('/[^0-9.]/', '', (string) $value) ?? '0';
    return (float) $normalized;
}

function admin_metric_total(array $items, string $field, string $expected): int
{
    return count(array_filter($items, static function (array $item) use ($field, $expected): bool {
        return strtolower((string) ($item[$field] ?? '')) === strtolower($expected);
    }));
}

function admin_input(string $name, string $label, mixed $value, string $type = 'text', string $attributes = '', string $hint = ''): void
{
    ?>
    <label class="admin-field">
      <span><?= admin_html($label) ?></span>
      <input type="<?= admin_html($type) ?>" name="<?= admin_html($name) ?>" value="<?= admin_html((string) $value) ?>" <?= $attributes ?>>
      <?php if ($hint !== ''): ?><small><?= admin_html($hint) ?></small><?php endif; ?>
    </label>
    <?php
}

function admin_select(string $name, string $label, mixed $value, array $options, string $attributes = '', string $hint = ''): void
{
    ?>
    <label class="admin-field">
      <span><?= admin_html($label) ?></span>
      <select name="<?= admin_html($name) ?>" <?= $attributes ?>>
        <?php foreach ($options as $optionValue => $optionLabel): ?>
          <option value="<?= admin_html((string) $optionValue) ?>" <?= (string) $value === (string) $optionValue ? 'selected' : '' ?>><?= admin_html((string) $optionLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($hint !== ''): ?><small><?= admin_html($hint) ?></small><?php endif; ?>
    </label>
    <?php
}

function admin_textarea(string $name, string $label, mixed $value, int $rows = 4, string $hint = ''): void
{
    ?>
    <label class="admin-field admin-field-full">
      <span><?= admin_html($label) ?></span>
      <textarea name="<?= admin_html($name) ?>" rows="<?= $rows ?>"><?= admin_html((string) $value) ?></textarea>
      <?php if ($hint !== ''): ?><small><?= admin_html($hint) ?></small><?php endif; ?>
    </label>
    <?php
}

function admin_richtext(string $name, string $label, mixed $value, string $hint = ''): void
{
    ?>
    <label class="admin-field admin-field-full admin-richtext-field" data-richtext-field>
      <span><?= admin_html($label) ?></span>
      <div class="admin-richtext-shell">
        <div class="admin-richtext-toolbar" data-richtext-toolbar aria-label="<?= admin_html($label) ?> formatting toolbar">
          <button class="admin-richtext-btn" type="button" data-richtext-action="formatBlock" data-richtext-value="P">Paragraph</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="formatBlock" data-richtext-value="H2">H2</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="formatBlock" data-richtext-value="H3">H3</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="bold">Bold</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="italic">Italic</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="underline">Underline</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="insertUnorderedList">Bullets</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="insertOrderedList">Numbers</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="formatBlock" data-richtext-value="BLOCKQUOTE">Quote</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="createLink">Link</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="unlink">Unlink</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="insertHorizontalRule">Divider</button>
          <button class="admin-richtext-btn" type="button" data-richtext-action="removeFormat">Clear</button>
        </div>
        <div class="admin-richtext-editor" contenteditable="true" spellcheck="true" data-richtext-editor data-placeholder="Write the full story here. Use headings, emphasis, lists, quotes, and links to structure the article."></div>
        <textarea name="<?= admin_html($name) ?>" hidden data-richtext-input><?= admin_html((string) $value) ?></textarea>
      </div>
      <?php if ($hint !== ''): ?><small><?= admin_html($hint) ?></small><?php endif; ?>
    </label>
    <?php
}

function admin_form_open(string $view, string $action, bool $multipart = false): void
{
    // autocomplete="off" stops the browser restoring stale <select> values on a
    // soft reload / back-navigation. Restoration happens AFTER scripts run, so a
    // restored Category left the product form rendering its "choose a category"
    // state while a category was visibly selected.
    ?>
    <form method="post" action="<?= admin_html(admin_url($view)) ?>" autocomplete="off" <?= $multipart ? 'enctype="multipart/form-data"' : '' ?>>
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="<?= admin_html($action) ?>">
      <input type="hidden" name="return_view" value="<?= admin_html($view) ?>">
    <?php
}

function admin_form_close(): void
{
    echo '</form>';
}

function admin_metal_price_adjustment_controls(string|int $index): void
{
    $inputId = 'metal-price-percentage-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $index);
    ?>
    <div class="admin-metal-price-adjustment" data-metal-price-adjustment>
      <div class="admin-metal-price-adjustment-copy">
        <strong>Adjust all product prices</strong>
        <small>Uses each matching product's current price. The percentage clears after one adjustment.</small>
      </div>
      <label class="admin-metal-percentage-field" for="<?= admin_html($inputId) ?>">
        <span>Percentage</span>
        <span class="admin-metal-percentage-input">
          <input id="<?= admin_html($inputId) ?>" type="number" min="0.01" max="100" step="0.01" inputmode="decimal" placeholder="10" autocomplete="off" data-metal-price-percentage>
          <b aria-hidden="true">%</b>
        </span>
      </label>
      <div class="admin-metal-price-actions">
        <button class="admin-metal-price-btn increase" type="button" data-metal-price-adjustment-button data-direction="increase">
          <i class="fas fa-arrow-up" aria-hidden="true"></i><span>Increase by</span>
        </button>
        <button class="admin-metal-price-btn decrease" type="button" data-metal-price-adjustment-button data-direction="decrease">
          <i class="fas fa-arrow-down" aria-hidden="true"></i><span>Decrease by</span>
        </button>
      </div>
    </div>
    <?php
}

function admin_array_find_index(array $items, string $id): ?int
{
    foreach ($items as $index => $item) {
        if ((string) ($item['id'] ?? '') === $id) {
            return $index;
        }
    }
    return null;
}

function admin_upload_dir_abs(): string
{
    return UPLOADS_ROOT_PATH;
}

function admin_upload_dir_web(): string
{
    return UPLOADS_PUBLIC_BASE_URL;
}

function admin_queue_media_asset(array $asset): void
{
    if (!supabase_enabled()) {
        return;
    }
    $publicUrl = trim((string) ($asset['public_url'] ?? ''));
    if ($publicUrl === '') {
        return;
    }

    $queue = $GLOBALS['azuronn_admin_media_asset_queue'] ?? [];
    if (!is_array($queue)) {
        $queue = [];
    }
    $queue[$publicUrl] = $asset;
    $GLOBALS['azuronn_admin_media_asset_queue'] = $queue;
}

function admin_has_queued_media_assets(): bool
{
    return !empty($GLOBALS['azuronn_admin_media_asset_queue']);
}

function admin_flush_queued_media_assets(): void
{
    $queue = $GLOBALS['azuronn_admin_media_asset_queue'] ?? [];
    $GLOBALS['azuronn_admin_media_asset_queue'] = [];
    if (!is_array($queue) || $queue === [] || !supabase_enabled()) {
        return;
    }
    supabase_register_media_assets(array_values($queue));
}

function admin_allowed_media_types(): array
{
    return [
        'image/jpeg' => ['ext' => 'jpg', 'media_type' => 'image'],
        'image/png' => ['ext' => 'png', 'media_type' => 'image'],
        'image/gif' => ['ext' => 'gif', 'media_type' => 'image'],
        'image/webp' => ['ext' => 'webp', 'media_type' => 'image'],
        'video/mp4' => ['ext' => 'mp4', 'media_type' => 'video'],
        'video/webm' => ['ext' => 'webm', 'media_type' => 'video'],
        'video/ogg' => ['ext' => 'ogv', 'media_type' => 'video'],
        'video/quicktime' => ['ext' => 'mov', 'media_type' => 'video'],
    ];
}

// Parse a php.ini size shorthand ("2M", "512K", "1G") into bytes.
function admin_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $num = (int) $value;
    return match ($unit) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
}

// Video files are allowed a bigger ceiling than stills — a short background
// clip is legitimately several times the weight of a hero photo.
function admin_upload_cap_bytes(string $mediaType = 'video'): int
{
    return ($mediaType === 'video' ? 30 : 10) * 1024 * 1024;
}

// Effective upload ceiling = the handler's hard cap for this media type,
// lowered by whichever PHP ini limit (upload_max_filesize / post_max_size) is
// smaller. This is the real number a browser can actually send, so the form
// hint + error messages tell the truth instead of advertising "30MB" when the
// server caps at 2MB.
function admin_upload_max_bytes(string $mediaType = 'video'): int
{
    $cap = admin_upload_cap_bytes($mediaType);
    foreach (['upload_max_filesize', 'post_max_size'] as $iniKey) {
        $limit = admin_ini_bytes((string) ini_get($iniKey));
        if ($limit > 0 && $limit < $cap) {
            $cap = $limit;
        }
    }
    return $cap;
}

function admin_upload_max_label(string $mediaType = 'video'): string
{
    $bytes = admin_upload_max_bytes($mediaType);
    if ($bytes >= 1024 * 1024) {
        return ((int) round($bytes / (1024 * 1024))) . ' MB';
    }
    if ($bytes >= 1024) {
        return ((int) round($bytes / 1024)) . ' KB';
    }
    return $bytes . ' B';
}

// One phrase covering both ceilings for form hints. When the server ini clamps
// both to the same number there is nothing to distinguish, so it collapses to a
// single figure rather than repeating it.
function admin_upload_hint(): string
{
    $image = admin_upload_max_label('image');
    $video = admin_upload_max_label('video');
    return $image === $video ? 'max ' . $video : 'max ' . $video . ' video, ' . $image . ' image';
}

// Human reason for a PHP upload error code, so a rejected file reports honestly
// instead of being masked by a generic "saved" message.
function admin_upload_error_label(int $code): string
{
    $max = admin_upload_max_label('video');
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the ' . $max . ' this server accepts. Use a smaller image or video.',
        UPLOAD_ERR_PARTIAL => 'The upload didn\'t finish. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
        UPLOAD_ERR_CANT_WRITE => 'The server couldn\'t write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
        default => 'The upload failed. Please try again.',
    };
}

function admin_handle_image_upload(string $fieldName, string $current = ''): string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return $current;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $current;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        // PHP rejected the file (most often: bigger than upload_max_filesize /
        // post_max_size, which silently zeroes $_FILES). Report the real reason
        // instead of pretending the save succeeded with no change.
        admin_add_upload_notice(admin_upload_error_label((int) ($file['error'] ?? UPLOAD_ERR_OK)));
        return $current;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $mediaTypeMap = admin_allowed_media_types();
    $mediaType = $mediaTypeMap[$mime] ?? null;
    if (!is_array($mediaType)) {
        admin_add_upload_notice('That file type isn\'t supported here (use JPG, PNG, GIF, WebP, or MP4/WebM/MOV video).');
        return $current;
    }

    // Size is checked against the cap for the detected type, so a video gets the
    // full video allowance rather than the smaller image one.
    $kind = (string) $mediaType['media_type'];
    if (($file['size'] ?? 0) > admin_upload_max_bytes($kind)) {
        admin_add_upload_notice('This ' . $kind . ' is larger than the ' . admin_upload_max_label($kind) . ' this server accepts.');
        return $current;
    }

    $dir = admin_upload_dir_abs();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $name = 'admin-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $mediaType['ext'];
    $destination = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        admin_add_upload_notice('The uploaded file couldn\'t be saved to the server. Check the uploads folder is writable.');
        return $current;
    }

    $publicUrl = admin_upload_dir_web() . '/' . $name;
    if (supabase_enabled()) {
        admin_queue_media_asset([
            'public_url' => $publicUrl,
            'file_path' => $destination,
            'file_name' => $name,
            'mime_type' => $mime,
            'media_type' => $mediaType['media_type'],
            'file_size' => (int) ($file['size'] ?? 0),
            'source' => 'hosting',
        ]);
    }

    return $publicUrl;
}

function admin_select_image_or_url(string $urlField, string $fileField, string $current = ''): string
{
    $uploaded = admin_handle_image_upload($fileField, '');
    if ($uploaded !== '') {
        return $uploaded;
    }

    $url = clean_image($_POST[$urlField] ?? '');
    // CONTRACT (do not "fix"): clean_image() turns a bare '#' into '', so this
    // reads as "empty box => return $current". That is ONLY correct for forms
    // that PRE-FILL the url box with the current image (then an empty box means
    // the user deliberately cleared it => '' is right). Forms that render the
    // box EMPTY must instead pre-fill it (see the Site-Settings hero form); if
    // such a form leaves the box empty, the correct "unchanged" value is carried
    // by the pre-filled current image, not by this branch. Returning $current
    // for an empty box here would break clearing on the pre-filled forms
    // (product images), so keep this line as-is.
    return $url !== '#' ? $url : $current;
}

function admin_build_diamond_inventory_from_post(array $existing = []): array
{
    $rows = is_array($_POST['diamond_inventory'] ?? null) ? $_POST['diamond_inventory'] : [];
    $prepared = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $existingRow = is_array($existing[$index] ?? null) ? $existing[$index] : [];
        $uploaded = admin_handle_image_upload('diamond_image_file_' . $index, '');
        if ($uploaded !== '') {
            $row['image'] = $uploaded;
        } elseif (clean_string((string) ($row['image'] ?? ''), 2048) === '' && ($existingRow['image'] ?? '') !== '') {
            $row['image'] = $existingRow['image'];
        }

        $prepared[] = $row;
    }

    return clean_items($prepared, 'clean_product_diamond_inventory_item');
}

function admin_build_diamond_row_from_post(array $existing = [], int $index = 0): array
{
    $data = is_array($_POST['diamond_item'] ?? null) ? $_POST['diamond_item'] : [];
    $image = admin_select_image_or_url('diamond_image_url', 'diamond_image_file', $existing['image'] ?? '');

    return clean_product_diamond_inventory_item([
        'id' => $existing['id'] ?? ($data['id'] ?? ''),
        'shape' => $data['shape'] ?? 'round',
        'title' => $data['title'] ?? '',
        'carat' => $data['carat'] ?? '',
        'price' => $data['price'] ?? '0',
        'color' => $data['color'] ?? '',
        'clarity' => $data['clarity'] ?? '',
        'cut' => $data['cut'] ?? '',
        'ratio' => $data['ratio'] ?? '',
        'measurement' => $data['measurement'] ?? '',
        'ref' => $data['ref'] ?? '',
        'igi_certificate' => $data['igi_certificate'] ?? '',
        'badge' => $data['badge'] ?? 'Lab Selected',
        'image' => $image,
        'status' => $data['status'] ?? 'active',
        'description' => $data['description'] ?? '',
    ], $index);
}

function admin_diamond_profile_keys(array $content): array
{
    // The Diamonds page manages one shared loose-diamond inventory, but it lives
    // inside the ring attribute profiles. Since the Rings profile was split into
    // Engagement Rings and Wedding Rings, both are read and both are written, so
    // the two sections never drift apart. Legacy keys stay in the list only when
    // they are still present in stored data.
    $keys = [];
    $profiles = is_array($content['catalog_meta']['attribute_profiles'] ?? null) ? $content['catalog_meta']['attribute_profiles'] : [];

    foreach (catalog_protected_categories() as $definition) {
        $keys[] = $definition['title'];
    }
    foreach (['Rings', 'Ring'] as $legacyKey) {
        if (is_array($profiles[$legacyKey] ?? null)) {
            $keys[] = $legacyKey;
        }
    }

    return array_values(array_unique($keys));
}

function admin_diamond_profile(array $content): array
{
    foreach (admin_diamond_profile_keys($content) as $profileKey) {
        $profile = is_array($content['catalog_meta']['attribute_profiles'][$profileKey] ?? null)
            ? $content['catalog_meta']['attribute_profiles'][$profileKey]
            : catalog_attribute_profile($profileKey, $content);
        if (array_values((array) ($profile['diamond_inventory'] ?? [])) !== []) {
            return clean_attribute_profile_item($profile, $profileKey);
        }
    }

    $firstKey = admin_diamond_profile_keys($content)[0];
    $profile = is_array($content['catalog_meta']['attribute_profiles'][$firstKey] ?? null)
        ? $content['catalog_meta']['attribute_profiles'][$firstKey]
        : catalog_attribute_profile($firstKey, $content);

    return clean_attribute_profile_item($profile, $firstKey);
}

function admin_store_diamond_inventory(array &$content, array $diamondInventory): void
{
    foreach (admin_diamond_profile_keys($content) as $profileKey) {
        $existingProfile = is_array($content['catalog_meta']['attribute_profiles'][$profileKey] ?? null)
            ? $content['catalog_meta']['attribute_profiles'][$profileKey]
            : catalog_attribute_profile($profileKey, $content);
        $content['catalog_meta']['attribute_profiles'][$profileKey] = clean_attribute_profile_item(array_merge(
            $existingProfile,
            ['diamond_inventory' => $diamondInventory]
        ), $profileKey);
    }
}

function admin_product_options(array $products): string
{
    $html = '';
    foreach ($products as $product) {
        $label = trim(($product['name'] ?? 'Product') . ' - ' . product_category_label($product));
        $html .= '<option value="' . h((string) ($product['id'] ?? '')) . '">' . h($label) . '</option>';
    }
    return $html;
}

function admin_products_by_ids_for_table(array $ids, array $map): array
{
    $items = [];
    foreach ($ids as $id) {
        if (isset($map[$id])) {
            $items[] = $map[$id];
        }
    }
    return $items;
}

function admin_ensure_unique_item_id(array $items, array $candidate, ?string $currentId = null): array
{
    $id = (string) ($candidate['id'] ?? '');
    if ($id === '') {
        return $candidate;
    }

    $existingIds = [];
    foreach ($items as $item) {
        $itemId = (string) ($item['id'] ?? '');
        if ($itemId !== '' && $itemId !== $currentId) {
            $existingIds[$itemId] = true;
        }
    }

    if (!isset($existingIds[$id])) {
        return $candidate;
    }

    $suffix = 2;
    $base = $id;
    while (isset($existingIds[$base . '-' . $suffix])) {
        $suffix++;
    }
    $candidate['id'] = $base . '-' . $suffix;
    return $candidate;
}

function admin_choice_in_list(array $choice, array $list): bool
{
    $v = trim((string) ($choice['value'] ?? $choice['label'] ?? ''));
    $l = trim((string) ($choice['label'] ?? ''));
    foreach ($list as $item) {
        $iv = trim((string) ($item['value'] ?? $item['label'] ?? ''));
        $il = trim((string) ($item['label'] ?? ''));
        if (($v !== '' && $v === $iv) || ($l !== '' && $l === $il)) {
            return true;
        }
    }
    return false;
}

function admin_resolve_indices_from_profile(array $data, string $indexKey, array $profileChoices, array $fallback): array
{
    if (array_key_exists($indexKey, $data)) {
        $indices = is_array($data[$indexKey]) ? array_map('intval', $data[$indexKey]) : [];
        return array_values(array_filter(
            array_map(static fn (int $i): mixed => $profileChoices[$i] ?? null, $indices),
            static fn ($c): bool => $c !== null
        ));
    }
    return $fallback;
}

/**
 * The "from" listing face for a matrix product, taken from its cheapest priced
 * active metal variation. Shop grids and homepage rails read new_price/
 * default_image/description off the product itself, but merchants now only
 * enter those per metal, so they are derived here on every save. Each field is
 * '' when the matrix has nothing usable to offer, letting the caller keep the
 * stored value rather than blanking a live listing.
 *
 * A £0 metal counts as "not priced yet", not as free: several existing products
 * carry active metals with price 0 alongside a real stored new_price, and
 * treating 0 as the cheapest would overwrite that price with £0.00 on save.
 *
 * @return array{new_price:string,old_price:string,default_image:string,hover_image:string,description:string}
 */
function admin_listing_face_from_metals(array $metalVariations): array
{
    $face = ['new_price' => '', 'old_price' => '', 'default_image' => '', 'hover_image' => '', 'description' => ''];
    $best = null;
    $bestPrice = null;
    $fallback = null;

    foreach ($metalVariations as $variation) {
        if (!is_array($variation) || !clean_bool($variation['active'] ?? false)) {
            continue;
        }
        $fallback = $fallback ?? $variation;
        $price = round(max(0, (float) (preg_replace('/[^0-9.]/', '', (string) ($variation['price'] ?? '0')) ?: '0')), 2);
        if ($price <= 0) {
            continue;
        }
        if ($bestPrice === null || $price < $bestPrice) {
            $bestPrice = $price;
            $best = $variation;
        }
    }

    // Imagery and copy still come from an active metal even when none is priced.
    $best = $best ?? $fallback;
    if ($best === null) {
        return $face;
    }

    if ($bestPrice !== null) {
        $face['new_price'] = money_format((float) $bestPrice);
        $face['old_price'] = clean_string((string) ($best['old_price'] ?? ''), 50);
    }

    $gallery = [];
    foreach ((array) ($best['gallery'] ?? []) as $image) {
        $clean = clean_image((string) $image);
        if ($clean !== '') {
            $gallery[] = $clean;
        }
    }
    if ($gallery === [] && clean_image((string) ($best['image'] ?? '')) !== '') {
        $gallery[] = clean_image((string) ($best['image'] ?? ''));
    }

    $face['default_image'] = $gallery[0] ?? '';
    $face['hover_image'] = $gallery[1] ?? ($gallery[0] ?? '');
    $face['description'] = clean_multiline((string) ($best['description'] ?? ''), 1000);

    return $face;
}

function admin_metal_price_adjustment_label(float $percentage): string
{
    return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.');
}

function admin_metal_price_adjustment_message(array $result): string
{
    if (empty($result['ok'])) {
        return clean_string((string) ($result['message'] ?? 'The metal prices could not be adjusted.'), 240);
    }

    $verb = (string) ($result['direction'] ?? '') === 'decrease' ? 'Decreased' : 'Increased';
    $percentage = admin_metal_price_adjustment_label((float) ($result['percentage'] ?? 0));
    $metal = clean_string((string) ($result['metal'] ?? ''), 120);
    $category = clean_string((string) ($result['attribute_type'] ?? ''), 80);
    $updatedProducts = (int) ($result['updated_products'] ?? 0);
    $updatedPrices = (int) ($result['updated_prices'] ?? 0);
    $skipped = (int) ($result['unpriced_matches'] ?? 0);

    $message = $verb . ' ' . $metal . ' prices in ' . $category . ' by ' . $percentage . '%. '
        . 'Updated ' . $updatedPrices . ' metal price' . ($updatedPrices === 1 ? '' : 's')
        . ' across ' . $updatedProducts . ' product' . ($updatedProducts === 1 ? '' : 's') . '.';
    if ($skipped > 0) {
        $message .= ' Skipped ' . $skipped . ' matching variation' . ($skipped === 1 ? '' : 's') . ' without a price.';
    }

    return $message;
}

/**
 * Apply one compounding percentage adjustment to every matching metal
 * variation in one category. The caller owns persistence so this same function
 * can power employee-request previews without writing anything.
 */
function admin_adjust_metal_prices(
    array &$content,
    string $attributeType,
    string $metal,
    string $direction,
    float $percentage
): array {
    $attributeType = clean_string($attributeType, 80);
    $metal = clean_string($metal, 120);
    $direction = strtolower(clean_string($direction, 20));
    $percentage = round($percentage, 2);

    if ($attributeType === '' || $metal === '') {
        return ['ok' => false, 'message' => 'Choose a saved category metal before changing prices.'];
    }
    if (!in_array($direction, ['increase', 'decrease'], true)) {
        return ['ok' => false, 'message' => 'Choose whether to increase or decrease the prices.'];
    }
    if ($percentage <= 0 || $percentage > 100) {
        return ['ok' => false, 'message' => 'Enter a percentage greater than 0 and no more than 100.'];
    }

    $profile = is_array($content['catalog_meta']['attribute_profiles'][$attributeType] ?? null)
        ? $content['catalog_meta']['attribute_profiles'][$attributeType]
        : [];
    $savedMetal = '';
    foreach ((array) ($profile['option_metal_options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }
        $optionLabel = clean_string((string) ($option['label'] ?? ''), 120);
        if ($optionLabel !== '' && strcasecmp($optionLabel, $metal) === 0) {
            $savedMetal = $optionLabel;
            break;
        }
    }
    if ($savedMetal === '') {
        return ['ok' => false, 'message' => 'Save this metal in the category profile before changing its product prices.'];
    }

    $factor = $direction === 'increase'
        ? 1 + ($percentage / 100)
        : max(0, 1 - ($percentage / 100));
    $updatedProducts = 0;
    $updatedPrices = 0;
    $matchedVariations = 0;
    $unpricedMatches = 0;
    $beforePrices = [];
    $afterPrices = [];

    if (!isset($content['products']['items']) || !is_array($content['products']['items'])) {
        $content['products']['items'] = [];
    }

    foreach ($content['products']['items'] as $productIndex => &$product) {
        if (!is_array($product) || strcasecmp(product_attribute_profile_type($product), $attributeType) !== 0) {
            continue;
        }

        $productChanged = false;
        if (!is_array($product['metal_variations'] ?? null)) {
            continue;
        }
        foreach ($product['metal_variations'] as $variationIndex => &$variation) {
            if (!is_array($variation) || strcasecmp((string) ($variation['metal'] ?? ''), $savedMetal) !== 0) {
                continue;
            }

            $matchedVariations++;
            $currentPrice = round(max(0, (float) (preg_replace('/[^0-9.]/', '', (string) ($variation['price'] ?? '0')) ?: '0')), 2);
            if ($currentPrice <= 0) {
                $unpricedMatches++;
                continue;
            }

            $newPrice = round(max(0, $currentPrice * $factor), 2);
            $beforePrices[] = $currentPrice;
            $afterPrices[] = $newPrice;
            $variation['price'] = $newPrice;

            $oldPriceRaw = preg_replace('/[^0-9.]/', '', (string) ($variation['old_price'] ?? ''));
            if ($oldPriceRaw !== null && $oldPriceRaw !== '') {
                $variation['old_price'] = round(max(0, (float) $oldPriceRaw * $factor), 2);
            }

            $variation = clean_product_metal_variation_item($variation, (int) $variationIndex);
            $updatedPrices++;
            $productChanged = true;
        }
        unset($variation);

        if (!$productChanged) {
            continue;
        }

        $listing = admin_listing_face_from_metals((array) ($product['metal_variations'] ?? []));
        $product['new_price'] = $listing['new_price'];
        $product['old_price'] = $listing['old_price'];
        $product = clean_product_library_item($product, (int) $productIndex);
        $updatedProducts++;
    }
    unset($product);

    if ($matchedVariations === 0) {
        return ['ok' => false, 'message' => 'No products in this category currently contain the selected metal.'];
    }
    if ($updatedPrices === 0) {
        return ['ok' => false, 'message' => 'Matching products were found, but none has a current price to adjust.'];
    }

    return [
        'ok' => true,
        'attribute_type' => $attributeType,
        'metal' => $savedMetal,
        'direction' => $direction,
        'percentage' => $percentage,
        'matched_variations' => $matchedVariations,
        'updated_products' => $updatedProducts,
        'updated_prices' => $updatedPrices,
        'unpriced_matches' => $unpricedMatches,
        'before_min' => min($beforePrices),
        'before_max' => max($beforePrices),
        'after_min' => min($afterPrices),
        'after_max' => max($afterPrices),
    ];
}

function admin_apply_metal_price_adjustment_live(
    string $attributeType,
    string $metal,
    string $direction,
    float $percentage
): array {
    return site_content_with_lock(static function () use ($attributeType, $metal, $direction, $percentage): array {
        $latestContent = load_site_content(true);
        $result = admin_adjust_metal_prices($latestContent, $attributeType, $metal, $direction, $percentage);
        if (!empty($result['ok'])) {
            save_site_content($latestContent);
        }
        return $result;
    });
}

function admin_metal_price_adjustment_from_post(): array
{
    $data = is_array($_POST['metal_price_adjustment'] ?? null) ? $_POST['metal_price_adjustment'] : [];
    $percentageRaw = is_scalar($data['percentage'] ?? null) ? trim((string) $data['percentage']) : '';

    return [
        'attribute_type' => clean_string((string) ($data['attribute_type'] ?? $_POST['attribute_type'] ?? ''), 80),
        'metal' => clean_string((string) ($data['metal'] ?? ''), 120),
        'direction' => strtolower(clean_string((string) ($data['direction'] ?? ''), 20)),
        'percentage' => is_numeric($percentageRaw) ? (float) $percentageRaw : 0.0,
    ];
}

function admin_build_product_from_post(array $existing = [], int $index = 0): array
{
    $data = is_array($_POST['product'] ?? null) ? $_POST['product'] : [];
    // The unified Category dropdown decides product_type + ring taxonomy in one
    // choice; the advanced Product Type select is only a fallback when it is absent.
    $taxonomyKey = clean_string((string) ($data['category_taxonomy'] ?? ''), 80);
    $taxonomyOptions = product_category_taxonomy_options();
    if ($taxonomyKey !== '' && isset($taxonomyOptions[$taxonomyKey])) {
        $taxonomyChoice = $taxonomyOptions[$taxonomyKey];
        $productType = $taxonomyChoice['product_type'];
        $ringCategory = $taxonomyChoice['ring_category'];
        // Gender is its own field on the form and only means anything inside the
        // wedding section, mirroring the whitelist shop/index.php applies.
        $ringGender = $ringCategory === 'wedding'
            ? strtolower(clean_string((string) ($data['ring_gender'] ?? ''), 40))
            : '';
        if (!in_array($ringGender, ['mens', 'womens'], true)) {
            $ringGender = '';
        }
    } else {
        $productType = clean_string((string) ($data['product_type'] ?? ($existing['product_type'] ?? '')), 80);
        $ringCategory = array_key_exists('ring_category', $data) ? (string) ($data['ring_category'] ?? '') : ($existing['ring_category'] ?? '');
        $ringGender = array_key_exists('ring_gender', $data) ? (string) ($data['ring_gender'] ?? '') : ($existing['ring_gender'] ?? '');
    }
    // Two different type names are in play. The product STORES the structural
    // type ('Ring' for both ring sections) because every /shop/ URL and filter
    // keys off type=Ring + ring_category. The attribute PROFILE is keyed by the
    // category name ('Engagement Rings' / 'Wedding Rings') so each section can
    // carry its own metals, sizes and styles. Resolve both explicitly — using
    // the profile name as the stored type would break every ring shop link.
    $profileType = $ringCategory !== ''
        ? ring_section_profile_type($ringCategory)
        : admin_canonical_attribute_type($productType);
    $productType = $ringCategory !== '' ? 'Ring' : $profileType;
    $profile = $profileType !== '' ? catalog_attribute_profile($profileType, site_content()) : [];
    $isMatrixType = admin_product_type_is_matrix($profileType);
    // Resolve choices: prefer index-based selection from profile, fallback to repeater data, then existing/profile
    $colorChoices = admin_resolve_indices_from_profile(
        $data, 'selected_color_indices',
        $profile['option_color_choices'] ?? [],
        $existing['option_color_choices'] ?? ($profile['option_color_choices'] ?? [])
    );
    $sizeChoices = admin_resolve_indices_from_profile(
        $data, 'selected_size_indices',
        $profile['option_size_choices'] ?? [],
        $existing['option_size_choices'] ?? ($profile['option_size_choices'] ?? [])
    );
    $metalVariations = [];
    // Build the allowed metal names from the current type's attribute profile
    $profileMetalNames = array_map(
        static fn (array $m): string => (string) ($m['label'] ?? ''),
        array_filter($profile['option_metal_options'] ?? [], 'is_array')
    );
    
    // Try namespaced key first (new format), fallback to generic key (legacy format)
    $mvFieldKey = 'metal_variations_' . preg_replace('/[^a-z0-9]/', '_', strtolower($profileType));
    $rawMetalData = null;
    if (is_array($data[$mvFieldKey] ?? null)) {
        $rawMetalData = $data[$mvFieldKey];
    } elseif (is_array($data['metal_variations'] ?? null)) {
        // Legacy: filter to only metals belonging to this type's profile
        $rawMetalData = array_filter(
            $data['metal_variations'],
            static function (mixed $mv) use ($profileMetalNames): bool {
                if (!is_array($mv)) return false;
                $metalName = (string) ($mv['metal'] ?? '');
                return $metalName === '' || empty($profileMetalNames) || in_array($metalName, $profileMetalNames, true);
            }
        );
    }

    if ($isMatrixType && $rawMetalData !== null) {
        foreach ($rawMetalData as $idx => $mv) {
            if (!is_array($mv)) continue;
            $existingVar = null;
            foreach (($existing['metal_variations'] ?? []) as $ev) {
                if (($ev['metal'] ?? '') === ($mv['metal'] ?? '')) {
                    $existingVar = $ev;
                    break;
                }
            }
            $mv['image'] = admin_select_image_or_url('metal_image_url_' . $idx, 'metal_image_file_' . $idx, $existingVar['image'] ?? '');
            
            $gallery = [];
            for ($i = 0; $i < 6; $i++) {
                $img = admin_select_image_or_url("metal_gallery_{$idx}_{$i}_url", "metal_gallery_{$idx}_{$i}_file", $existingVar['gallery'][$i] ?? '');
                if ($img !== '') {
                    $gallery[] = $img;
                }
            }
            $mv['gallery'] = $gallery;

            $shapeGalleries = [];
            if (admin_product_type_is_ring($profileType)) {
                $profileFieldKey = content_slug($profileType, 'ring');
                foreach (array_keys(available_diamond_shapes()) as $shapeKey) {
                    $shapeFieldKey = content_slug((string) $shapeKey, 'shape');
                    $existingShapeGallery = is_array($existingVar['shape_galleries'][$shapeKey] ?? null)
                        ? array_values($existingVar['shape_galleries'][$shapeKey])
                        : [];
                    $shapeGallery = [];
                    for ($i = 0; $i < 6; $i++) {
                        $fieldPrefix = "metal_shape_gallery_{$profileFieldKey}_{$idx}_{$shapeFieldKey}_{$i}";
                        $media = admin_select_image_or_url(
                            $fieldPrefix . '_url',
                            $fieldPrefix . '_file',
                            $existingShapeGallery[$i] ?? ''
                        );
                        if ($media !== '') {
                            $shapeGallery[] = $media;
                        }
                    }
                    if ($shapeGallery !== []) {
                        $shapeGalleries[(string) $shapeKey] = $shapeGallery;
                    }
                }
            }
            $mv['shape_galleries'] = $shapeGalleries;
            
            $metalVariations[] = $mv;
        }
    } elseif ($isMatrixType) {
        $metalVariations = $existing['metal_variations'] ?? [];
    }

    // The Merchandising "Pricing and media" card is gone: price, imagery and copy
    // now live per metal in the Metal Matrix. The shop grid and homepage rails
    // still read new_price/default_image/description straight off the product, so
    // mirror the cheapest active metal onto the product as its "from" listing
    // face. Nothing is posted for these fields any more, so this is the only
    // writer — falling back to the stored values keeps non-matrix items intact.
    $listing = admin_listing_face_from_metals($metalVariations);
    $newPrice = $listing['new_price'] !== '' ? $listing['new_price'] : (string) ($existing['new_price'] ?? '');
    $oldPrice = $listing['new_price'] !== '' ? $listing['old_price'] : (string) ($existing['old_price'] ?? '');
    $defaultImage = $listing['default_image'] !== '' ? $listing['default_image'] : (string) ($existing['default_image'] ?? '');
    $hoverImage = $listing['hover_image'] !== '' ? $listing['hover_image'] : (string) ($existing['hover_image'] ?? $defaultImage);
    $description = $listing['description'] !== '' ? $listing['description'] : (string) ($existing['description'] ?? '');

    return clean_product_library_item([
        'id' => $existing['id'] ?? '',
        'name' => $data['name'] ?? '',
        'product_type' => $productType,
        'color' => $data['color'] ?? '',
        'category' => $data['category'] ?? '',
        'ring_category' => $ringCategory,
        'ring_gender' => $ringGender,
        'old_price' => $oldPrice,
        'new_price' => $newPrice,
        'default_image' => $defaultImage,
        'hover_image' => $hoverImage,
        'popup_image' => $defaultImage,
        'description' => $description,
        'status' => $data['status'] ?? ($existing['status'] ?? 'active'),
        'styles' => array_key_exists('styles', $data) && is_array($data['styles'])
            ? array_values(array_filter(array_map(static fn ($v) => clean_string((string) $v, 80), $data['styles']), static fn ($v) => $v !== ''))
            : ($existing['styles'] ?? []),
        'diamondShapes' => $isMatrixType
            ? (array_key_exists('diamondShapes', $data) && is_array($data['diamondShapes']) ? $data['diamondShapes'] : ($existing['diamondShapes'] ?? []))
            : [],
        'subcategories' => admin_parse_lines((string) ($data['subcategories_text'] ?? ''), 80),
        'features' => admin_parse_lines((string) ($data['features_text'] ?? ''), 160),
        'option_color_label' => array_key_exists('option_color_label', $data) ? (string) ($data['option_color_label'] ?? '') : ($existing['option_color_label'] ?? ($profile['option_color_label'] ?? '')),
        'option_size_label' => array_key_exists('option_size_label', $data) ? (string) ($data['option_size_label'] ?? '') : ($existing['option_size_label'] ?? ($profile['option_size_label'] ?? '')),
        'option_color_display' => $existing['option_color_display'] ?? ($profile['option_color_display'] ?? ''),
        'option_size_display' => $existing['option_size_display'] ?? ($profile['option_size_display'] ?? ''),
        'option_colors' => admin_choice_values_from_rows($colorChoices, 'choice-color'),
        'option_sizes' => admin_choice_values_from_rows($sizeChoices, 'choice-size'),
        'option_color_choices' => $colorChoices,
        'option_size_choices' => $sizeChoices,
        'metal_variations' => $metalVariations,
        'option_delivery_options' => $isMatrixType ? ($existing['option_delivery_options'] ?? ($profile['option_delivery_options'] ?? [])) : [],
    ], $index);
}

function admin_build_product_inventory_from_post(array $existing): array
{
    $data = is_array($_POST['inventory'] ?? null) ? $_POST['inventory'] : [];
    $updated = $existing;
    $updated['inventory_tracked'] = !empty($data['inventory_tracked']);
    $updated['inventory_quantity'] = $updated['inventory_tracked']
        ? clean_int($data['inventory_quantity'] ?? ($existing['inventory_quantity'] ?? 0), 0, 1000000)
        : 0;

    $metalVariations = array_values(array_filter((array) ($existing['metal_variations'] ?? []), 'is_array'));
    foreach ($metalVariations as $index => $variation) {
        $row = is_array($data['metal_variations'][$index] ?? null) ? $data['metal_variations'][$index] : [];
        $variation['inventory_tracked'] = !empty($row['inventory_tracked']);
        $variation['inventory_quantity'] = $variation['inventory_tracked']
            ? clean_int($row['inventory_quantity'] ?? ($variation['inventory_quantity'] ?? 0), 0, 1000000)
            : 0;
        $metalVariations[$index] = clean_product_metal_variation_item($variation, $index);
    }

    $updated['metal_variations'] = $metalVariations;
    return clean_product_library_item($updated, 0);
}

function admin_inventory_rows(array $product): array
{
    $rows = [];
    foreach ((array) ($product['metal_variations'] ?? []) as $variation) {
        if (!is_array($variation)) {
            continue;
        }

        $metal = clean_string((string) ($variation['metal'] ?? ''), 120);
        if ($metal === '') {
            continue;
        }

        $tracked = !empty($variation['inventory_tracked']);
        $quantity = clean_int($variation['inventory_quantity'] ?? 0, 0, 1000000);
        $rows[] = [
            'scope' => 'metal',
            'label' => $metal,
            'tracked' => $tracked,
            'quantity' => $tracked ? $quantity : null,
            'low' => $tracked && $quantity > 0 && $quantity <= inventory_low_stock_threshold(),
            'out' => $tracked && $quantity <= 0,
        ];
    }

    if ($rows !== []) {
        return $rows;
    }

    $tracked = !empty($product['inventory_tracked']);
    $quantity = clean_int($product['inventory_quantity'] ?? 0, 0, 1000000);

    return [[
        'scope' => 'product',
        'label' => 'Product stock',
        'tracked' => $tracked,
        'quantity' => $tracked ? $quantity : null,
        'low' => $tracked && $quantity > 0 && $quantity <= inventory_low_stock_threshold(),
        'out' => $tracked && $quantity <= 0,
    ]];
}

function admin_inventory_summary(array $product): array
{
    $rows = admin_inventory_rows($product);
    $trackedRows = array_values(array_filter($rows, static fn (array $row): bool => !empty($row['tracked'])));
    if ($trackedRows === []) {
        return [
            'status' => 'untracked',
            'label' => 'Not tracked',
            'tracked_count' => 0,
            'low_count' => 0,
            'out_count' => 0,
            'total_quantity' => null,
        ];
    }

    $lowCount = count(array_filter($trackedRows, static fn (array $row): bool => !empty($row['low'])));
    $outCount = count(array_filter($trackedRows, static fn (array $row): bool => !empty($row['out'])));
    $totalQuantity = array_sum(array_map(static fn (array $row): int => clean_int($row['quantity'] ?? 0, 0, 1000000), $trackedRows));

    if ($outCount === count($trackedRows) && $totalQuantity <= 0) {
        $status = 'out';
        $label = 'Out of stock';
    } elseif ($lowCount > 0 || $totalQuantity <= inventory_low_stock_threshold()) {
        $status = 'low';
        $label = 'Low stock';
    } else {
        $status = 'ok';
        $label = 'In stock';
    }

    return [
        'status' => $status,
        'label' => $label,
        'tracked_count' => count($trackedRows),
        'low_count' => $lowCount,
        'out_count' => $outCount,
        'total_quantity' => $totalQuantity,
    ];
}

// Resolve each posted band/claw palette option's swatch image: a freshly
// uploaded file wins, then a pasted URL, then the image already stored. The
// palette carries NO surcharge/price (those live per-product in the upload
// Metal Matrix) — only label, description and the display swatch image.
function admin_resolve_band_image_options(?array $posted): array
{
    if (!is_array($posted)) {
        return [];
    }
    $out = [];
    foreach (array_values($posted) as $index => $option) {
        if (!is_array($option)) {
            continue;
        }
        $option['image'] = admin_select_image_or_url(
            'band_image_url_' . $index,
            'band_image_file_' . $index,
            (string) ($option['current_image'] ?? '')
        );
        unset($option['current_image']);
        $out[] = $option;
    }
    return $out;
}

function admin_build_product_attribute_overrides_from_post(array $existing): array
{
    $data = is_array($_POST['product'] ?? null) ? $_POST['product'] : [];
    $colorChoices = is_array($data['option_color_choices'] ?? null) ? array_values($data['option_color_choices']) : ($existing['option_color_choices'] ?? []);
    $sizeChoices = is_array($data['option_size_choices'] ?? null) ? array_values($data['option_size_choices']) : ($existing['option_size_choices'] ?? []);
    $isMatrixType = admin_product_type_is_matrix(product_attribute_profile_type($existing));

    return clean_product_library_item(array_merge($existing, [
        'styles' => is_array($data['styles'] ?? null) ? $data['styles'] : ($existing['styles'] ?? []),
        'diamondShapes' => is_array($data['diamondShapes'] ?? null) ? $data['diamondShapes'] : ($existing['diamondShapes'] ?? []),
        'option_color_label' => array_key_exists('option_color_label', $data) ? (string) ($data['option_color_label'] ?? '') : ($existing['option_color_label'] ?? ''),
        'option_size_label' => array_key_exists('option_size_label', $data) ? (string) ($data['option_size_label'] ?? '') : ($existing['option_size_label'] ?? ''),
        'option_color_display' => $existing['option_color_display'] ?? '',
        'option_size_display' => $existing['option_size_display'] ?? '',
        'option_colors' => $isMatrixType ? [] : admin_choice_values_from_rows($colorChoices, 'choice-color'),
        'option_sizes' => admin_choice_values_from_rows($sizeChoices, 'choice-size'),
        'option_color_choices' => $isMatrixType ? [] : $colorChoices,
        'option_size_choices' => $sizeChoices,
        'option_metal_options' => is_array($data['option_metal_options'] ?? null) ? array_values($data['option_metal_options']) : ($existing['option_metal_options'] ?? []),
        'option_band_claw_metal_options' => admin_resolve_band_image_options(is_array($data['option_band_claw_metal_options'] ?? null) ? $data['option_band_claw_metal_options'] : null),
        'option_delivery_options' => $existing['option_delivery_options'] ?? [],
        'diamond_inventory' => is_array($data['diamond_inventory'] ?? null) ? array_values($data['diamond_inventory']) : ($existing['diamond_inventory'] ?? []),
    ]), 0);
}

function admin_build_attribute_profile_from_post(string $type, array $existing = []): array
{
    $data = is_array($_POST['product'] ?? null) ? $_POST['product'] : [];
    $isMatrixType = admin_product_type_is_matrix($type);
    $buildStyleCards = static function (?array $postedCards, array $existingCards, string $fieldPrefix): array {
        if ($postedCards === null) {
            return $existingCards;
        }
        $existingByValue = [];
        foreach ($existingCards as $existingCard) {
            if (!is_array($existingCard)) {
                continue;
            }
            $existingValue = clean_string((string) ($existingCard['value'] ?? ''), 80);
            if ($existingValue !== '') {
                $existingByValue[$existingValue] = $existingCard;
            }
        }
        $cards = [];
        foreach (array_values($postedCards) as $index => $card) {
            if (!is_array($card)) {
                continue;
            }

            $value = content_slug((string) ($card['value'] ?? ''), '');
            if ($value === '') {
                $value = content_slug((string) ($card['label'] ?? ''), 'style-' . ($index + 1));
            }
            $existingCard = $existingByValue[$value] ?? ($existingCards[$index] ?? []);
            $cards[] = [
                'value' => $value,
                'label' => $card['label'] ?? '',
                'image' => admin_select_image_or_url($fieldPrefix . 'image_url_' . $index, $fieldPrefix . 'image_file_' . $index, (string) ($card['current_image'] ?? ($existingCard['image'] ?? ''))),
            ];
        }
        return $cards;
    };

    // A repeater the merchant emptied submits NO array key at all, which is
    // indistinguishable from "this section wasn't on the form" unless the form
    // also posts a marker. product[sections_present][] lists every repeater
    // rendered, so an emptied one saves as [] instead of silently restoring.
    $sectionsPresent = array_map('strval', (array) ($data['sections_present'] ?? []));
    $postedList = static function (string $key) use ($data, $sectionsPresent): ?array {
        $posted = $data;
        foreach (explode('.', $key) as $segment) {
            $posted = is_array($posted[$segment] ?? null) ? $posted[$segment] : null;
            if ($posted === null) {
                break;
            }
        }
        if (is_array($posted)) {
            return $posted;
        }
        return in_array($key, $sectionsPresent, true) ? [] : null;
    };

    $colorChoices = $postedList('option_color_choices') ?? ($existing['option_color_choices'] ?? []);
    $sizeChoices = $postedList('option_size_choices') ?? ($existing['option_size_choices'] ?? []);

    $styleCards = $existing['style_cards'] ?? [];
    $styleCardsSections = $existing['style_cards_sections'] ?? ['engagement' => [], 'wedding' => []];
    if (admin_product_type_is_ring($type)) {
        // A ring profile owns exactly one style section — Engagement Rings owns
        // 'engagement', Wedding Rings owns 'wedding'. Writing both from either
        // page is what let a save on one wipe the other section's styles.
        $ownedSection = catalog_category_ring_section($type);
        if ($ownedSection === '') {
            $ownedSection = 'engagement';
        }
        $sectionExisting = is_array($styleCardsSections[$ownedSection] ?? null) ? $styleCardsSections[$ownedSection] : [];
        if ($sectionExisting === []) {
            $sectionExisting = (array) ($existing['style_cards'] ?? []);
        }
        $styleCardsSections[$ownedSection] = $buildStyleCards(
            $postedList('style_cards_sections.' . $ownedSection),
            $sectionExisting,
            'style_card_' . $ownedSection . '_'
        );
        // Each ring profile is section-specific now, so its legacy flat list is
        // simply a copy of its own section for older fallback reads.
        $styleCards = $styleCardsSections[$ownedSection];
    }

    $selectorCards = $existing['selector_cards'] ?? [];
    $postedSelectorCards = $postedList('selector_cards');
    if (!admin_product_type_is_ring($type) && $postedSelectorCards !== null) {
        $selectorCards = [];
        $existingSelectorCardsByValue = [];
        foreach ((array) ($existing['selector_cards'] ?? []) as $existingCard) {
            if (!is_array($existingCard)) {
                continue;
            }
            $existingValue = clean_string((string) ($existingCard['value'] ?? ''), 80);
            if ($existingValue !== '') {
                $existingSelectorCardsByValue[$existingValue] = $existingCard;
            }
        }
        foreach (array_values($postedSelectorCards) as $index => $card) {
            if (!is_array($card)) {
                continue;
            }

            $value = content_slug((string) ($card['value'] ?? ''), '');
            if ($value === '') {
                $value = content_slug((string) ($card['label'] ?? ''), 'selector-' . ($index + 1));
            }
            $existingCard = $existingSelectorCardsByValue[$value] ?? ($existing['selector_cards'][$index] ?? []);
            $selectorCards[] = [
                'value' => $value,
                'label' => $card['label'] ?? '',
                'image' => admin_select_image_or_url('selector_card_image_url_' . $index, 'selector_card_image_file_' . $index, (string) ($card['current_image'] ?? ($existingCard['image'] ?? ''))),
            ];
        }
    }

    return clean_attribute_profile_item([
        'type' => $type,
        'option_color_label' => array_key_exists('option_color_label', $data) ? (string) ($data['option_color_label'] ?? '') : ($existing['option_color_label'] ?? ''),
        'option_size_label' => array_key_exists('option_size_label', $data) ? (string) ($data['option_size_label'] ?? '') : ($existing['option_size_label'] ?? ''),
        'option_color_display' => $existing['option_color_display'] ?? '',
        'option_size_display' => $existing['option_size_display'] ?? '',
        'option_colors' => $isMatrixType ? [] : admin_choice_values_from_rows($colorChoices, 'choice-color'),
        'option_sizes' => admin_choice_values_from_rows($sizeChoices, 'choice-size'),
        'option_color_choices' => $isMatrixType ? [] : $colorChoices,
        'option_size_choices' => $sizeChoices,
        'option_metal_options' => $postedList('option_metal_options') ?? ($existing['option_metal_options'] ?? []),
        'option_band_claw_metal_options' => admin_resolve_band_image_options($postedList('option_band_claw_metal_options')),
        'option_addon_groups' => (static function (callable $postedList, array $existing): array {
            $groups = [];
            foreach (array_keys(catalog_addon_groups()) as $groupKey) {
                $groups[$groupKey] = $postedList('option_addon_groups.' . $groupKey)
                    ?? ($existing['option_addon_groups'][$groupKey] ?? []);
            }

            return $groups;
        })($postedList, $existing),
        'option_delivery_options' => $existing['option_delivery_options'] ?? [],
        'selector_cards' => $selectorCards,
        'style_cards' => $styleCards,
        'style_cards_sections' => $styleCardsSections,
        'diamond_inventory' => $postedList('diamond_inventory') ?? ($existing['diamond_inventory'] ?? []),
    ], $type);
}

function admin_choice_values_from_rows(array $rows, string $type): array
{
    $values = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $value = clean_string((string) ($row['value'] ?? ''), 80);
        if ($value === '') {
            $value = clean_string(product_choice_generated_value($row, $type, $index), 80);
        }

        if ($value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }

    return $values;
}

function admin_build_news_from_post(array $existing = [], int $index = 0): array
{
    $data = is_array($_POST['news_item'] ?? null) ? $_POST['news_item'] : [];
    $image = admin_select_image_or_url('news_image_url', 'news_image_file', $existing['image'] ?? '');
    return clean_news_item([
        'id' => $existing['id'] ?? ($data['id'] ?? ''),
        'title' => $data['title'] ?? '',
        'author' => $data['author'] ?? '',
        'date' => $data['date'] ?? '',
        'body' => $data['body'] ?? '',
        'url' => $existing['url'] ?? '#',
        'image' => $image,
        'alt' => $data['alt'] ?? '',
    ], $index);
}

function admin_build_coupon_from_post(array $existing = [], int $index = 0): array
{
    $data = is_array($_POST['coupon'] ?? null) ? $_POST['coupon'] : [];
    $type = clean_string($data['type'] ?? 'percent', 20);
    $value = clean_string($data['value'] ?? '', 20);
    $applyLabel = $type === 'fixed'
        ? '£' . $value . ' off'
        : $value . '% off';

    return clean_coupon_item([
        'id' => $existing['id'] ?? ($data['id'] ?? ''),
        'code' => $data['code'] ?? '',
        'type' => $type,
        'value' => $value,
        'min_order' => $data['min_order'] ?? '',
        'usage_limit' => $data['usage_limit'] ?? '',
        'expires_at' => $data['expires_at'] ?? '',
        'status' => $data['status'] ?? 'active',
        'description' => $data['description'] ?? '',
        'apply_label' => trim($applyLabel . (($data['min_order'] ?? '') !== '' ? ' above ' . clean_string($data['min_order'], 20) : '')),
    ], $index);
}

function admin_build_shape_from_post(array $existing = [], int $index = 0): array
{
    $data = is_array($_POST['shape'] ?? null) ? $_POST['shape'] : [];
    $image = admin_select_image_or_url('shape_image_url', 'shape_image_file', $existing['image'] ?? '');
    $shape = [
        'name' => clean_string($data['name'] ?? '', 60),
        'label' => clean_string($data['label'] ?? '', 120),
        'description' => clean_multiline($data['description'] ?? '', 360),
        'image' => $image,
        'url' => clean_link($data['url'] ?? '#'),
        'icon_image' => $image,
        'accent' => clean_color($existing['accent'] ?? '#b18861'),
        'tone' => clean_tone($existing['tone'] ?? 'classic'),
    ];

    return $shape;
}

function admin_table_button(string $label, string $action, array $hidden = [], string $class = 'admin-mini-btn'): void
{
    ?>
    <form method="post" action="<?= admin_html(admin_url(admin_current_view())) ?>" class="admin-inline-form">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="<?= admin_html($action) ?>">
      <input type="hidden" name="return_view" value="<?= admin_html(admin_current_view()) ?>">
      <?php foreach ($hidden as $name => $value): ?>
        <input type="hidden" name="<?= admin_html($name) ?>" value="<?= admin_html((string) $value) ?>">
      <?php endforeach; ?>
      <button type="submit" class="<?= admin_html($class) ?>"><?= admin_html($label) ?></button>
    </form>
    <?php
}

function admin_request_remove_empty(array $data): array
{
    $clean = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $nested = admin_request_remove_empty($value);
            if ($nested !== []) {
                $clean[$key] = $nested;
            }
            continue;
        }

        if ($value === null) {
            continue;
        }

        $stringValue = is_string($value) ? trim($value) : $value;
        if ($stringValue === '' || $stringValue === [] || $stringValue === false) {
            continue;
        }

        $clean[$key] = $value;
    }
    return $clean;
}

function admin_request_snapshot_choice_labels(array $rows): array
{
    $labels = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = clean_string((string) ($row['label'] ?? $row['value'] ?? ''), 120);
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    return array_values(array_unique($labels));
}

function admin_request_snapshot_delivery_options(array $options): array
{
    $snapshot = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $label = clean_string((string) ($option['badge'] ?? $option['label'] ?? $option['value'] ?? 'Delivery option'), 120);
        $snapshot[] = admin_request_remove_empty([
            'Option' => $label,
            'Label' => clean_string((string) ($option['label'] ?? ''), 120),
            'Charge' => clean_string((string) ($option['price_label'] ?? $option['price'] ?? ''), 40),
            'Description' => clean_multiline((string) ($option['description'] ?? ''), 220),
        ]);
    }
    return $snapshot;
}

function admin_request_snapshot_band_options(array $options): array
{
    $snapshot = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }
        $snapshot[] = admin_request_remove_empty([
            'Option' => clean_string((string) ($option['label'] ?? $option['value'] ?? ''), 120),
            'Extra Charge' => clean_string((string) ($option['price_label'] ?? $option['surcharge'] ?? $option['price'] ?? ''), 40),
            'Shapes' => array_values((array) ($option['shapes'] ?? [])),
            'Sizes' => array_values((array) ($option['sizes'] ?? [])),
        ]);
    }
    return $snapshot;
}

function admin_request_snapshot_option_details(array $options, string $fallbackLabel = 'Option'): array
{
    $snapshot = [];
    foreach ($options as $option) {
        if (!is_array($option)) {
            continue;
        }

        $entry = admin_request_remove_empty([
            'Label' => clean_string((string) ($option['label'] ?? $option['value'] ?? $fallbackLabel), 120),
            'Value' => clean_string((string) ($option['value'] ?? ''), 120),
            'Description' => clean_multiline((string) ($option['description'] ?? ''), 240),
            'Charge' => clean_string((string) ($option['price_label'] ?? $option['surcharge'] ?? $option['price'] ?? ''), 40),
            'Badge' => clean_string((string) ($option['badge'] ?? ''), 80),
        ]);

        if ($entry !== []) {
            $snapshot[] = $entry;
        }
    }
    return $snapshot;
}

function admin_request_media_label(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '#') {
        return '';
    }

    $path = parse_url($value, PHP_URL_PATH);
    $candidate = is_string($path) && $path !== '' ? basename($path) : basename($value);
    $candidate = clean_string($candidate, 160);

    return $candidate !== '' ? $candidate : clean_string($value, 160);
}

function admin_request_snapshot_product(array $product): array
{
    $snapshot = [
        'Product ID' => clean_string((string) ($product['id'] ?? ''), 80),
        'Name' => clean_string((string) ($product['name'] ?? ''), 120),
        'Product Type' => clean_string((string) ($product['product_type'] ?? ''), 80),
        'Ring Section' => match (strtolower(clean_string((string) ($product['ring_category'] ?? ''), 40))) {
            'engagement' => 'Engagement Rings',
            'wedding' => 'Wedding Rings',
            default => '',
        },
        'Ring Gender' => match (strtolower(clean_string((string) ($product['ring_gender'] ?? ''), 40))) {
            'mens' => "Men's",
            'womens' => "Women's",
            default => '',
        },
        'Status' => clean_string((string) ($product['status'] ?? ''), 40),
        'Old Price' => clean_string((string) ($product['old_price'] ?? ''), 40),
        'New Price' => clean_string((string) ($product['new_price'] ?? ''), 40),
        'Primary Image' => admin_request_media_label((string) ($product['default_image'] ?? '')),
        'Hover Image' => admin_request_media_label((string) ($product['hover_image'] ?? '')),
        'Description' => clean_multiline((string) ($product['description'] ?? ''), 320),
        'Features' => array_values((array) ($product['features'] ?? [])),
        'Subcategories' => array_values((array) ($product['subcategories'] ?? [])),
        'Styles Supported' => array_values((array) ($product['styles'] ?? [])),
        'Diamond Shapes' => array_values((array) ($product['diamondShapes'] ?? [])),
        'Size Options' => admin_request_snapshot_choice_labels((array) ($product['option_size_choices'] ?? [])),
        'Delivery Options' => admin_request_snapshot_delivery_options((array) ($product['option_delivery_options'] ?? [])),
    ];

    $metalSummaries = [];
    foreach ((array) ($product['metal_variations'] ?? []) as $variation) {
        if (!is_array($variation)) {
            continue;
        }
        $metalSummaries[] = admin_request_remove_empty(array_merge([
            'Metal' => clean_string((string) ($variation['metal'] ?? ''), 120),
            'Price' => clean_string((string) ($variation['price'] ?? ''), 40),
            'Old Price' => clean_string((string) ($variation['old_price'] ?? ''), 40),
            'Image' => admin_request_media_label((string) ($variation['image'] ?? '')),
            'Gallery' => array_values(array_filter(array_map(
                static fn (mixed $item): string => admin_request_media_label((string) $item),
                (array) ($variation['gallery'] ?? [])
            ))),
            'Description' => clean_multiline((string) ($variation['description'] ?? ''), 180),
            'Highlights' => array_values((array) ($variation['features'] ?? [])),
            'Diamond Shapes' => array_values((array) ($variation['shapes'] ?? [])),
            'Sizes' => array_values((array) ($variation['sizes'] ?? [])),
            'Band / Claw Options' => admin_request_snapshot_band_options((array) ($variation['band_options'] ?? [])),
        ], (static function (array $variation): array {
            $rows = [];
            foreach (catalog_addon_groups() as $groupKey => $groupMeta) {
                $rows[$groupMeta['label'] . ' Options'] = admin_request_snapshot_band_options((array) ($variation['addon_groups'][$groupKey] ?? []));
            }

            return $rows;
        })($variation)));
    }
    if ($metalSummaries !== []) {
        $snapshot['Metal Variations'] = $metalSummaries;
    }

    return admin_request_remove_empty($snapshot);
}

function admin_request_snapshot_inventory_product(array $product): array
{
    $rows = admin_inventory_rows($product);
    $inventoryRows = [];
    foreach ($rows as $row) {
        $inventoryRows[] = admin_request_remove_empty([
            'Scope' => clean_string((string) ($row['label'] ?? ''), 120),
            'Tracking' => !empty($row['tracked']) ? 'Tracked' : 'Not tracked',
            'Available Quantity' => !empty($row['tracked']) ? (string) clean_int($row['quantity'] ?? 0, 0, 1000000) : 'Untracked',
            'Status' => !empty($row['out']) ? 'Out of stock' : (!empty($row['low']) ? 'Low stock' : (!empty($row['tracked']) ? 'In stock' : 'Not tracked')),
        ]);
    }

    return admin_request_remove_empty([
        'Product ID' => clean_string((string) ($product['id'] ?? ''), 80),
        'Name' => clean_string((string) ($product['name'] ?? ''), 120),
        'Product Type' => clean_string((string) ($product['product_type'] ?? ''), 80),
        'Inventory' => $inventoryRows,
    ]);
}

function admin_request_snapshot_news(array $news): array
{
    return admin_request_remove_empty([
        'Post ID' => clean_string((string) ($news['id'] ?? ''), 80),
        'Title' => clean_string((string) ($news['title'] ?? ''), 140),
        'Author' => clean_string((string) ($news['author'] ?? ''), 120),
        'Date' => clean_string((string) ($news['date'] ?? ''), 40),
        'Cover Image' => admin_request_media_label((string) ($news['image'] ?? '')),
        'Image Alt' => clean_string((string) ($news['alt'] ?? ''), 140),
        'Content' => rich_text_plain_text((string) ($news['body'] ?? '')),
    ]);
}

function admin_request_snapshot_coupon(array $coupon): array
{
    return admin_request_remove_empty([
        'Coupon ID' => clean_string((string) ($coupon['id'] ?? ''), 80),
        'Code' => clean_string((string) ($coupon['code'] ?? ''), 80),
        'Type' => clean_string((string) ($coupon['type'] ?? ''), 40),
        'Value' => clean_string((string) ($coupon['value'] ?? ''), 40),
        'Minimum Order' => clean_string((string) ($coupon['min_order'] ?? ''), 40),
        'Usage Limit' => clean_string((string) ($coupon['usage_limit'] ?? ''), 40),
        'Expires' => clean_string((string) ($coupon['expires_at'] ?? ''), 40),
        'Status' => clean_string((string) ($coupon['status'] ?? ''), 40),
        'Description' => clean_multiline((string) ($coupon['description'] ?? ''), 240),
    ]);
}

function admin_request_snapshot_diamond(array $diamond): array
{
    return admin_request_remove_empty([
        'Diamond ID' => clean_string((string) ($diamond['id'] ?? ''), 80),
        'Title' => clean_string((string) ($diamond['title'] ?? ''), 140),
        'Shape' => clean_string((string) ($diamond['shape'] ?? ''), 40),
        'Price' => clean_string((string) ($diamond['price'] ?? ''), 40),
        'Carat' => clean_string((string) ($diamond['carat'] ?? ''), 20),
        'Color' => clean_string((string) ($diamond['color'] ?? ''), 20),
        'Clarity' => clean_string((string) ($diamond['clarity'] ?? ''), 20),
        'Cut' => clean_string((string) ($diamond['cut'] ?? ''), 40),
        'Image' => admin_request_media_label((string) ($diamond['image'] ?? '')),
        'Description' => clean_multiline((string) ($diamond['description'] ?? ''), 240),
        'Status' => clean_string((string) ($diamond['status'] ?? ''), 40),
    ]);
}

function admin_request_snapshot_shape(array $shape): array
{
    return admin_request_remove_empty([
        'Title' => clean_string((string) ($shape['title'] ?? ''), 120),
        'Subtitle' => clean_string((string) ($shape['subtitle'] ?? ''), 160),
        'Image' => admin_request_media_label((string) ($shape['image'] ?? '')),
        'URL' => clean_string((string) ($shape['url'] ?? ''), 240),
        'Accent' => clean_string((string) ($shape['accent'] ?? ''), 32),
        'Tone' => clean_string((string) ($shape['tone'] ?? ''), 40),
    ]);
}

function admin_request_snapshot_customer(array $customer): array
{
    return admin_request_remove_empty([
        'Customer ID' => clean_string((string) ($customer['id'] ?? ''), 80),
        'Name' => clean_string((string) ($customer['name'] ?? ''), 120),
        'Email' => clean_string((string) ($customer['email'] ?? ''), 120),
        'Phone' => clean_string((string) ($customer['phone'] ?? ''), 40),
        'City' => clean_string((string) ($customer['city'] ?? ''), 80),
        'Status' => clean_string((string) ($customer['status'] ?? ''), 40),
        'Joined' => clean_string((string) ($customer['joined_at'] ?? ''), 40),
        'Total Orders' => clean_string((string) ($customer['total_orders'] ?? ''), 20),
        'Total Spent' => clean_string((string) ($customer['total_spent'] ?? ''), 40),
    ]);
}

/**
 * Apply a fulfilment status to an order, keeping the tracking ID consistent.
 * Shared by the direct POST handler and the request-approval apply path so the
 * two can't drift.
 */
function admin_order_apply_status(array $order, string $status, string $trackingId): array
{
    $status = order_status_normalize($status);
    if (!isset(order_status_options()[$status])) {
        return $order;
    }
    $order['status'] = $status;

    if (in_array($status, order_tracking_statuses(), true)) {
        // A blank submit keeps whatever number is already on the order — the
        // field is only rendered for these statuses, so an empty post means
        // "unchanged", not "clear it".
        if ($trackingId !== '') {
            $order['tracking_id'] = $trackingId;
        }
    } elseif ($status === 'cancelled') {
        $order['tracking_id'] = '';
    }

    if ($status === 'delivered') {
        if (strtolower((string) ($order['payment_status'] ?? '')) === 'awaiting') {
            $order['payment_status'] = 'paid';
        }
        // The return window is measured from this stamp, so re-saving a delivered
        // order must not restart the clock.
        if (clean_string((string) ($order['delivered_at'] ?? ''), 40) === '') {
            $order['delivered_at'] = date('Y-m-d H:i:s');
        }
    } else {
        $order['delivered_at'] = '';
    }

    return $order;
}

function admin_request_snapshot_order(array $order): array
{
    return admin_request_remove_empty([
        'Order ID' => clean_string((string) ($order['id'] ?? ''), 80),
        'Customer' => clean_string((string) ($order['customer_name'] ?? ''), 120),
        'Email' => clean_string((string) ($order['customer_email'] ?? ''), 120),
        'Status' => order_status_label((string) ($order['status'] ?? '')),
        'Tracking ID' => clean_string((string) ($order['tracking_id'] ?? ''), 120),
        'Payment Status' => clean_string((string) ($order['payment_status'] ?? ''), 40),
        'Total' => clean_string((string) ($order['total'] ?? ''), 40),
        'Placed At' => clean_string((string) ($order['placed_at'] ?? ''), 40),
        'Customer Request Type' => clean_string((string) ($order['customer_request_type'] ?? ''), 40),
        'Customer Request Status' => clean_string((string) ($order['customer_request_status'] ?? ''), 40),
    ]);
}

function admin_request_snapshot_attribute_profile(string $type, array $profile): array
{
    $addonSnapshots = [];
    foreach (catalog_addon_groups() as $groupKey => $groupMeta) {
        $addonSnapshots[$groupMeta['label']] = admin_request_snapshot_option_details((array) ($profile['option_addon_groups'][$groupKey] ?? []), $groupMeta['label']);
    }

    return admin_request_remove_empty(array_merge([
        'Category' => $type,
        'Size Label' => clean_string((string) ($profile['option_size_label'] ?? ''), 80),
        'Size Choices' => array_values(array_map(static fn (array $item): string => clean_string((string) ($item['label'] ?? ''), 120), array_filter((array) ($profile['option_size_choices'] ?? []), 'is_array'))),
        'Metal Options' => admin_request_snapshot_option_details((array) ($profile['option_metal_options'] ?? []), 'Metal'),
        'Band / Claw Options' => admin_request_snapshot_option_details((array) ($profile['option_band_claw_metal_options'] ?? []), 'Band / Claw'),
    ], $addonSnapshots, [
        'Delivery Options' => admin_request_snapshot_delivery_options((array) ($profile['option_delivery_options'] ?? [])),
        'Style Showcase' => admin_request_snapshot_category_cards(array_map(static fn (array $item): array => [
            'title' => $item['label'] ?? '',
            'image' => $item['image'] ?? '',
        ], array_filter((array) ($profile['style_cards'] ?? []), 'is_array'))),
        'Selector Showcase' => admin_request_snapshot_category_cards(array_map(static fn (array $item): array => [
            'title' => $item['label'] ?? '',
            'image' => $item['image'] ?? '',
        ], array_filter((array) ($profile['selector_cards'] ?? []), 'is_array'))),
    ]));
}

function admin_request_snapshot_category_cards(array $cards): array
{
    return array_values(array_map(static fn (array $card): array => admin_request_remove_empty([
        'Title' => clean_string((string) ($card['title'] ?? ''), 120),
        'Subtext' => clean_string((string) ($card['sub'] ?? ''), 160),
        'Image' => admin_request_media_label((string) ($card['image'] ?? '')),
        'Hero Image' => admin_request_media_label((string) ($card['hero_image'] ?? '')),
        'URL' => clean_string((string) ($card['url'] ?? ''), 240),
    ]), array_filter($cards, 'is_array')));
}

function admin_request_snapshot_assignments(array $content, array $assigned): array
{
    $snapshot = [];
    foreach ((array) ($content['product_tabs']['tabs'] ?? []) as $tab) {
        $key = (string) ($tab['key'] ?? '');
        if ($key === '' || $key !== 'featured') {
            continue;
        }
        $snapshot[$key] = array_values((array) ($assigned[$key] ?? ($tab['product_ids'] ?? [])));
    }
    $snapshot['bestselling'] = array_values((array) ($assigned['bestselling'] ?? ($content['bestselling']['product_ids'] ?? [])));
    $snapshot['shop_by_style'] = array_values((array) ($assigned['shop_by_style'] ?? ($content['shop_by_style']['style_ids'] ?? [])));
    return admin_request_remove_empty($snapshot);
}

function admin_request_pack(string $area, string $kind, string $entity, string $label, array $options = []): array
{
    return admin_request_remove_empty([
        'area' => $area,
        'kind' => $kind,
        'entity' => $entity,
        'label' => $label,
        'target_id' => $options['target_id'] ?? '',
        'context' => is_array($options['context'] ?? null) ? $options['context'] : [],
        'before' => $options['before'] ?? null,
        'after' => $options['after'] ?? null,
        'raw_before' => $options['raw_before'] ?? ($options['before'] ?? null),
        'raw_after' => $options['raw_after'] ?? ($options['after'] ?? null),
    ]);
}

function admin_render_request_value(mixed $value, int $depth = 0): void
{
    if (is_array($value)) {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc): ?>
          <div class="admin-request-grid<?= $depth > 0 ? ' is-nested' : '' ?>">
            <?php foreach ($value as $key => $item): ?>
              <article class="admin-request-field">
                <span><?= admin_html((string) $key) ?></span>
                <div class="admin-request-field-body"><?php admin_render_request_value($item, $depth + 1); ?></div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else:
            $hasNested = false;
            foreach ($value as $item) {
                if (is_array($item)) {
                    $hasNested = true;
                    break;
                }
            }
            if (!$hasNested):
                $shouldStack = false;
                foreach ($value as $item) {
                    $itemText = trim((string) $item);
                    $itemLength = function_exists('mb_strlen') ? mb_strlen($itemText) : strlen($itemText);
                    if ($itemLength > 28) {
                        $shouldStack = true;
                        break;
                    }
                }
                ?>
              <div class="<?= $shouldStack ? 'admin-request-stack-list' : 'admin-request-chip-list' ?>">
                <?php foreach ($value as $item): ?>
                  <small><?= admin_html((string) $item) ?></small>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="admin-request-card-grid<?= $depth > 0 ? ' is-nested' : '' ?>">
                <?php foreach ($value as $index => $item): ?>
                  <article class="admin-request-card">
                    <?php if (is_array($item)): ?>
                      <?php
                      $cardTitle = (string) ($item['Metal'] ?? $item['Option'] ?? $item['Title'] ?? $item['Name'] ?? $item['Label'] ?? ('Item ' . ($index + 1)));
                      $cardBody = $item;
                      foreach (['Metal', 'Option', 'Title', 'Name', 'Label'] as $primaryKey) {
                          unset($cardBody[$primaryKey]);
                      }
                      ?>
                      <strong><?= admin_html($cardTitle) ?></strong>
                      <?php if ($cardBody !== []): ?>
                        <div class="admin-request-card-body"><?php admin_render_request_value($cardBody, $depth + 1); ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <strong><?= admin_html((string) $item) ?></strong>
                    <?php endif; ?>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif;
        endif;
        return;
    }

    ?><small><?= admin_html(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value) ?></small><?php
}

function admin_request_assoc(array $value): bool
{
    return array_keys($value) !== range(0, count($value) - 1);
}

function admin_request_values_differ(mixed $before, mixed $after): bool
{
    if (is_array($before) || is_array($after)) {
        $beforeJson = json_encode(admin_clean_request_payload($before), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $afterJson = json_encode(admin_clean_request_payload($after), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $beforeJson !== $afterJson;
    }

    return (string) $before !== (string) $after;
}

function admin_render_request_comparison(mixed $before, mixed $after, int $depth = 0): void
{
    $beforeArray = is_array($before) ? $before : [];
    $afterArray = is_array($after) ? $after : [];

    $keys = [];
    foreach (array_keys($beforeArray) as $key) {
        $keys[(string) $key] = true;
    }
    foreach (array_keys($afterArray) as $key) {
        $keys[(string) $key] = true;
    }

    if ($keys === []) {
        $isChanged = admin_request_values_differ($before, $after);
        ?>
        <div class="admin-request-diff">
          <article class="admin-request-diff-row<?= $isChanged ? ' is-changed' : '' ?>">
            <div class="admin-request-diff-values">
              <div class="admin-request-diff-cell<?= $isChanged ? ' is-changed' : '' ?>">
                <span class="admin-request-diff-head">Before</span>
                <?php admin_render_request_value($before, $depth + 1); ?>
              </div>
              <div class="admin-request-diff-cell<?= $isChanged ? ' is-changed' : '' ?>">
                <span class="admin-request-diff-head">After</span>
                <?php admin_render_request_value($after, $depth + 1); ?>
              </div>
            </div>
          </article>
        </div>
        <?php
        return;
    }

    ?>
    <div class="admin-request-diff<?= $depth > 0 ? ' is-nested' : '' ?>">
      <?php foreach (array_keys($keys) as $key): ?>
        <?php
        $beforeValue = $beforeArray[$key] ?? null;
        $afterValue = $afterArray[$key] ?? null;
        $hasNested = is_array($beforeValue) || is_array($afterValue);
        $hasAssocNested = (is_array($beforeValue) && admin_request_assoc($beforeValue)) || (is_array($afterValue) && admin_request_assoc($afterValue));
        $isChanged = admin_request_values_differ($beforeValue, $afterValue);
        ?>
        <article class="admin-request-diff-row<?= $hasNested ? ' is-group' : '' ?><?= $isChanged ? ' is-changed' : '' ?>">
          <div class="admin-request-diff-label">
            <strong><?= admin_html((string) $key) ?></strong>
          </div>

          <?php if ($hasAssocNested): ?>
            <div class="admin-request-diff-values">
              <div class="admin-request-diff-cell<?= $isChanged ? ' is-changed' : '' ?>">
                <span class="admin-request-diff-head">Before</span>
                <?php if ($beforeValue !== null): ?>
                  <?php admin_render_request_comparison($beforeValue, [], $depth + 1); ?>
                <?php else: ?>
                  <small class="admin-muted">No value</small>
                <?php endif; ?>
              </div>
              <div class="admin-request-diff-cell<?= $isChanged ? ' is-changed' : '' ?>">
                <span class="admin-request-diff-head">After</span>
                <?php if ($afterValue !== null): ?>
                  <?php admin_render_request_comparison([], $afterValue, $depth + 1); ?>
                <?php else: ?>
                  <small class="admin-muted">No value</small>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="admin-request-diff-values">
              <div class="admin-request-diff-cell<?= $isChanged ? ' is-changed' : '' ?>">
                <span class="admin-request-diff-head">Before</span>
                <?php if ($beforeValue !== null): ?>
                  <?php admin_render_request_value($beforeValue, $depth + 1); ?>
                <?php else: ?>
                  <small class="admin-muted">No value</small>
                <?php endif; ?>
              </div>
              <div class="admin-request-diff-cell<?= $isChanged ? ' is-changed' : '' ?>">
                <span class="admin-request-diff-head">After</span>
                <?php if ($afterValue !== null): ?>
                  <?php admin_render_request_value($afterValue, $depth + 1); ?>
                <?php else: ?>
                  <small class="admin-muted">No value</small>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
}

function admin_prepare_request_payload(string $action, array $content): ?array
{
    return match ($action) {
        'save-hero' => admin_request_pack('Homepage Content', 'update', 'Hero Section', 'Homepage hero', [
            'before' => admin_request_remove_empty((array) ($content['hero'] ?? [])),
            'after' => admin_request_remove_empty([
                'offer' => clean_string($_POST['hero']['offer'] ?? '', 160),
                'title' => clean_string($_POST['hero']['title'] ?? '', 160),
                'price_prefix' => clean_string($_POST['hero']['price_prefix'] ?? '', 80),
                'price_value' => clean_string($_POST['hero']['price_value'] ?? '', 60),
                'cta_label' => clean_string($_POST['hero']['cta_label'] ?? '', 60),
                'cta_url' => clean_link($_POST['hero']['cta_url'] ?? '#'),
                'image' => admin_select_image_or_url('hero_image_url', 'hero_image_file', $content['hero']['image'] ?? ''),
            ]),
        ]),
        'save-celebs' => admin_request_pack('Homepage Content', 'update', 'Celeb Section', clean_string((string) ($content['celebs']['title'] ?? 'Celeb section'), 120), [
            'before' => admin_request_remove_empty((array) ($content['celebs'] ?? [])),
            'after' => admin_request_remove_empty([
                'title' => clean_string($_POST['celebs']['title'] ?? '', 120),
                'items' => is_array($_POST['celebs']['items'] ?? null) ? $_POST['celebs']['items'] : [],
            ]),
        ]),
        'save-social-gallery' => admin_request_pack('Homepage Content', 'update', 'Social Gallery', clean_string((string) ($content['social_gallery']['title'] ?? 'Social Gallery section'), 120), [
            'before' => admin_request_remove_empty((array) ($content['social_gallery'] ?? [])),
            'after' => (function () {
                $items = is_array($_POST['social_gallery']['items'] ?? null) ? $_POST['social_gallery']['items'] : [];
                foreach ($items as $idx => &$item) {
                    $uploaded = admin_handle_image_upload('social_gallery_image_file_' . $idx, clean_image($item['image'] ?? ''));
                    $item['image'] = $uploaded !== '' ? $uploaded : clean_image($item['image'] ?? '');
                }
                return admin_request_remove_empty([
                    'title' => clean_string($_POST['social_gallery']['title'] ?? '', 120),
                    'items' => $items,
                ]);
            })(),
        ]),
        'save-settings' => admin_request_pack('Settings', 'update', 'Site Settings', 'Global site settings', [
            'before' => admin_request_remove_empty((array) ($content['settings'] ?? [])),
            'after' => admin_request_remove_empty(is_array($_POST['settings'] ?? null) ? $_POST['settings'] : ($content['settings'] ?? [])),
        ]),
        'save-categories' => (function () use ($content) {
            $cards = is_array($_POST['category_cards'] ?? null) ? $_POST['category_cards'] : [];
            foreach ($cards as $idx => &$card) {
                $uploaded = admin_handle_image_upload('category_image_file_' . $idx, clean_image($card['image'] ?? ''));
                $card['image'] = $uploaded !== '' ? $uploaded : clean_image($card['image'] ?? '');
                $uploadedHero = admin_handle_image_upload('category_hero_image_file_' . $idx, clean_image($card['hero_image'] ?? ''));
                $card['hero_image'] = $uploadedHero !== '' ? $uploadedHero : clean_image($card['hero_image'] ?? '');
            }
            unset($card);
            return admin_request_pack('Categories', 'update', 'Homepage Categories', 'Homepage category cards', [
                'before' => admin_request_snapshot_category_cards((array) ($content['category_cards'] ?? [])),
                'after' => admin_request_snapshot_category_cards($cards),
                'raw_before' => array_values((array) ($content['category_cards'] ?? [])),
                'raw_after' => $cards,
            ]);
        })(),
        'save-attribute-profile' => (function () use ($content) {
            $attributeType = clean_string($_POST['attribute_type'] ?? '', 80);
            $existingProfile = is_array($content['catalog_meta']['attribute_profiles'][$attributeType] ?? null)
                ? $content['catalog_meta']['attribute_profiles'][$attributeType]
                : [];
            $profile = admin_build_attribute_profile_from_post($attributeType, $existingProfile);
            return admin_request_pack('Attributes', 'update', 'Attribute Profile', $attributeType . ' profile', [
                'target_id' => $attributeType,
                'before' => admin_request_snapshot_attribute_profile($attributeType, $existingProfile),
                'after' => admin_request_snapshot_attribute_profile($attributeType, $profile),
                'raw_before' => $existingProfile,
                'raw_after' => $profile,
                'context' => ['Category' => $attributeType],
            ]);
        })(),
        'adjust-metal-prices' => (function () use ($content) {
            $adjustment = admin_metal_price_adjustment_from_post();
            $previewContent = $content;
            $preview = admin_adjust_metal_prices(
                $previewContent,
                (string) $adjustment['attribute_type'],
                (string) $adjustment['metal'],
                (string) $adjustment['direction'],
                (float) $adjustment['percentage']
            );
            if (empty($preview['ok'])) {
                return null;
            }

            $percentageLabel = admin_metal_price_adjustment_label((float) $preview['percentage']);
            $directionLabel = (string) $preview['direction'] === 'decrease' ? 'Decrease' : 'Increase';
            return admin_request_pack('Attributes', 'update', 'Metal Prices', (string) $preview['metal'] . ' in ' . (string) $preview['attribute_type'], [
                'target_id' => (string) $preview['attribute_type'],
                'before' => [
                    'Current Price Range' => money_format((float) $preview['before_min']) . ' - ' . money_format((float) $preview['before_max']),
                    'Priced Metal Variations' => (int) $preview['updated_prices'],
                ],
                'after' => [
                    'Adjustment' => $directionLabel . ' by ' . $percentageLabel . '%',
                    'Expected Price Range' => money_format((float) $preview['after_min']) . ' - ' . money_format((float) $preview['after_max']),
                    'Products Affected' => (int) $preview['updated_products'],
                ],
                'raw_after' => $adjustment,
                'context' => [
                    'Category' => (string) $preview['attribute_type'],
                    'Metal' => (string) $preview['metal'],
                    'Adjustment' => $directionLabel . ' ' . $percentageLabel . '%',
                ],
            ]);
        })(),
        'save-product-attributes' => (function () use ($content) {
            $productId = clean_string($_POST['product_id'] ?? '', 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index === null) {
                return null;
            }
            $product = admin_ensure_unique_item_id(
                    $content['products']['items'],
                    admin_build_product_attribute_overrides_from_post($content['products']['items'][$index]),
                    $productId
                );
            return admin_request_pack('Attributes', 'update', 'Product Attributes', clean_string((string) ($product['name'] ?? $productId), 120), [
                'target_id' => $productId,
                'before' => admin_request_snapshot_product($content['products']['items'][$index]),
                'after' => admin_request_snapshot_product($product),
                'raw_before' => $content['products']['items'][$index],
                'raw_after' => $product,
                'context' => ['Product Type' => clean_string($_POST['attribute_type'] ?? '', 80)],
            ]);
        })(),
        'save-diamonds' => admin_request_pack('Diamonds', 'update', 'Diamond Inventory', 'Default diamond inventory', [
            'before' => ['Count' => count(array_values(admin_diamond_profile($content)['diamond_inventory'] ?? []))],
            'after' => ['Count' => count(admin_build_diamond_inventory_from_post(admin_diamond_profile($content)['diamond_inventory'] ?? []))],
            'raw_before' => array_values(admin_diamond_profile($content)['diamond_inventory'] ?? []),
            'raw_after' => admin_build_diamond_inventory_from_post(admin_diamond_profile($content)['diamond_inventory'] ?? []),
        ]),
        'create-diamond' => (function () use ($content) {
            $diamond = admin_build_diamond_row_from_post([], count(array_values(admin_diamond_profile($content)['diamond_inventory'] ?? [])));
            return admin_request_pack('Diamonds', 'create', 'Diamond', clean_string((string) ($diamond['title'] ?? $diamond['shape'] ?? 'Diamond'), 140), [
                'target_id' => clean_string((string) ($diamond['id'] ?? ''), 80),
                'after' => admin_request_snapshot_diamond($diamond),
                'raw_after' => $diamond,
                'context' => ['Shape' => clean_string((string) ($diamond['shape'] ?? ''), 40)],
            ]);
        })(),
        'update-diamond' => (function () use ($content) {
            $diamondId = clean_string($_POST['diamond_id'] ?? '', 80);
            $rows = array_values(admin_diamond_profile($content)['diamond_inventory'] ?? []);
            $diamondIndex = admin_array_find_index($rows, $diamondId);
            if ($diamondIndex === null) {
                return null;
            }
            $diamond = admin_build_diamond_row_from_post($rows[$diamondIndex], $diamondIndex);
            return admin_request_pack('Diamonds', 'update', 'Diamond', clean_string((string) ($diamond['title'] ?? $diamondId), 140), [
                'target_id' => $diamondId,
                'before' => admin_request_snapshot_diamond($rows[$diamondIndex]),
                'after' => admin_request_snapshot_diamond($diamond),
                'raw_before' => $rows[$diamondIndex],
                'raw_after' => $diamond,
                'context' => ['Shape' => clean_string((string) ($diamond['shape'] ?? ''), 40)],
            ]);
        })(),
        'delete-diamond' => (function () use ($content) {
            $diamondId = clean_string($_POST['diamond_id'] ?? '', 80);
            $rows = array_values(admin_diamond_profile($content)['diamond_inventory'] ?? []);
            $diamondIndex = admin_array_find_index($rows, $diamondId);
            if ($diamondIndex === null) {
                return null;
            }
            return admin_request_pack('Diamonds', 'delete', 'Diamond', clean_string((string) ($rows[$diamondIndex]['title'] ?? $diamondId), 140), [
                'target_id' => $diamondId,
                'before' => admin_request_snapshot_diamond($rows[$diamondIndex]),
                'raw_before' => $rows[$diamondIndex],
                'context' => ['Shape' => clean_string((string) ($rows[$diamondIndex]['shape'] ?? ''), 40)],
            ]);
        })(),
        'save-navigation' => admin_request_pack('Settings', 'update', 'Navigation', 'Site navigation', [
            'before' => ['Menu Items' => count((array) ($content['navigation']['items'] ?? []))],
            'after' => ['Menu Items' => count((array) ((is_array($_POST['navigation'] ?? null) ? $_POST['navigation'] : ($content['navigation'] ?? []))['items'] ?? []))],
            'raw_before' => (array) ($content['navigation'] ?? []),
            'raw_after' => is_array($_POST['navigation'] ?? null) ? $_POST['navigation'] : ($content['navigation'] ?? []),
        ]),
        'save-footer' => admin_request_pack('Settings', 'update', 'Footer', 'Footer content', [
            'before' => admin_request_remove_empty((array) ($content['footer'] ?? [])),
            'after' => admin_request_remove_empty(is_array($_POST['footer'] ?? null) ? $_POST['footer'] : ($content['footer'] ?? [])),
            'raw_before' => (array) ($content['footer'] ?? []),
            'raw_after' => is_array($_POST['footer'] ?? null) ? $_POST['footer'] : ($content['footer'] ?? []),
        ]),
        'create-product' => (function () use ($content) {
            $product = admin_ensure_unique_item_id(
                $content['products']['items'],
                admin_build_product_from_post([], count($content['products']['items']))
            );
            return admin_request_pack('Catalog', 'create', 'Product', clean_string((string) ($product['name'] ?? 'Product'), 120), [
                'target_id' => clean_string((string) ($product['id'] ?? ''), 80),
                'after' => admin_request_snapshot_product($product),
                'raw_after' => $product,
                'context' => [
                    'Product Type' => clean_string((string) ($product['product_type'] ?? ''), 80),
                ],
            ]);
        })(),
        'update-product' => (function () use ($content) {
            $productId = clean_string($_POST['product_id'] ?? '', 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index === null) {
                return null;
            }
            $product = admin_ensure_unique_item_id(
                    $content['products']['items'],
                    admin_build_product_from_post($content['products']['items'][$index], $index),
                    $productId
                );
            return admin_request_pack('Catalog', 'update', 'Product', clean_string((string) ($product['name'] ?? $productId), 120), [
                'target_id' => $productId,
                'before' => admin_request_snapshot_product($content['products']['items'][$index]),
                'after' => admin_request_snapshot_product($product),
                'raw_before' => $content['products']['items'][$index],
                'raw_after' => $product,
                'context' => [
                    'Product Type' => clean_string((string) ($product['product_type'] ?? ''), 80),
                ],
            ]);
        })(),
        'save-inventory' => (function () use ($content) {
            $productId = clean_string($_POST['inventory_product_id'] ?? '', 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index === null) {
                return null;
            }
            $beforeProduct = $content['products']['items'][$index];
            $afterProduct = admin_build_product_inventory_from_post($beforeProduct);
            return admin_request_pack('Inventory', 'update', 'Inventory', clean_string((string) ($afterProduct['name'] ?? $productId), 120), [
                'target_id' => $productId,
                'before' => admin_request_snapshot_inventory_product($beforeProduct),
                'after' => admin_request_snapshot_inventory_product($afterProduct),
                'raw_before' => $beforeProduct,
                'raw_after' => $afterProduct,
                'context' => [
                    'Product Type' => clean_string((string) ($afterProduct['product_type'] ?? ''), 80),
                ],
            ]);
        })(),
        'delete-product' => (function () use ($content) {
            $productId = clean_string($_POST['product_id'] ?? '', 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index === null) {
                return null;
            }
            $product = $content['products']['items'][$index];
            return admin_request_pack('Catalog', 'delete', 'Product', clean_string((string) ($product['name'] ?? $productId), 120), [
                'target_id' => $productId,
                'before' => admin_request_snapshot_product($product),
                'raw_before' => $product,
                'context' => [
                    'Product Type' => clean_string((string) ($product['product_type'] ?? ''), 80),
                ],
            ]);
        })(),
        'save-catalog-assignments' => admin_request_pack('Catalog', 'update', 'Assignments', 'Storefront product assignments', [
            'before' => admin_request_snapshot_assignments($content, []),
            'after' => admin_request_snapshot_assignments($content, is_array($_POST['assignments'] ?? null) ? $_POST['assignments'] : []),
            'raw_after' => is_array($_POST['assignments'] ?? null) ? $_POST['assignments'] : [],
        ]),
        'create-news' => (function () use ($content) {
            $news = admin_ensure_unique_item_id(
                $content['news']['items'],
                admin_build_news_from_post([], count($content['news']['items']))
            );
            return admin_request_pack('Azuronn News', 'create', 'News Post', clean_string((string) ($news['title'] ?? 'News post'), 140), [
                'target_id' => clean_string((string) ($news['id'] ?? ''), 80),
                'after' => admin_request_snapshot_news($news),
                'raw_after' => $news,
            ]);
        })(),
        'update-news' => (function () use ($content) {
            $newsId = clean_string($_POST['news_id'] ?? '', 80);
            $index = admin_array_find_index($content['news']['items'], $newsId);
            if ($index === null) {
                return null;
            }
            $news = admin_ensure_unique_item_id(
                    $content['news']['items'],
                    admin_build_news_from_post($content['news']['items'][$index], $index),
                    $newsId
                );
            return admin_request_pack('Azuronn News', 'update', 'News Post', clean_string((string) ($news['title'] ?? $newsId), 140), [
                'target_id' => $newsId,
                'before' => admin_request_snapshot_news($content['news']['items'][$index]),
                'after' => admin_request_snapshot_news($news),
                'raw_before' => $content['news']['items'][$index],
                'raw_after' => $news,
            ]);
        })(),
        'delete-news' => (function () use ($content) {
            $newsId = clean_string($_POST['news_id'] ?? '', 80);
            $index = admin_array_find_index($content['news']['items'], $newsId);
            if ($index === null) {
                return null;
            }
            return admin_request_pack('Azuronn News', 'delete', 'News Post', clean_string((string) ($content['news']['items'][$index]['title'] ?? $newsId), 140), [
                'target_id' => $newsId,
                'before' => admin_request_snapshot_news($content['news']['items'][$index]),
                'raw_before' => $content['news']['items'][$index],
            ]);
        })(),
        'create-shape' => (function () use ($content) {
            $shape = admin_build_shape_from_post([], count($content['diamond_shapes']['items'] ?? []));
            return admin_request_pack('Homepage Content', 'create', 'Diamond Shape', clean_string((string) ($shape['title'] ?? 'Shape'), 120), [
                'after' => admin_request_snapshot_shape($shape),
                'raw_after' => $shape,
            ]);
        })(),
        'update-shape' => (function () use ($content) {
            $shapeIndex = clean_int($_POST['shape_index'] ?? -1, -1, 9999);
            if (!isset($content['diamond_shapes']['items'][$shapeIndex])) {
                return null;
            }
            $shape = admin_build_shape_from_post($content['diamond_shapes']['items'][$shapeIndex], $shapeIndex);
            return admin_request_pack('Homepage Content', 'update', 'Diamond Shape', clean_string((string) ($shape['title'] ?? 'Shape'), 120), [
                'before' => admin_request_snapshot_shape($content['diamond_shapes']['items'][$shapeIndex]),
                'after' => admin_request_snapshot_shape($shape),
                'raw_before' => $content['diamond_shapes']['items'][$shapeIndex],
                'raw_after' => $shape,
            ]);
        })(),
        'delete-shape' => (function () use ($content) {
            $shapeIndex = clean_int($_POST['shape_index'] ?? -1, -1, 9999);
            if (!isset($content['diamond_shapes']['items'][$shapeIndex])) {
                return null;
            }
            return admin_request_pack('Homepage Content', 'delete', 'Diamond Shape', clean_string((string) ($content['diamond_shapes']['items'][$shapeIndex]['title'] ?? 'Shape'), 120), [
                'before' => admin_request_snapshot_shape($content['diamond_shapes']['items'][$shapeIndex]),
                'raw_before' => $content['diamond_shapes']['items'][$shapeIndex],
            ]);
        })(),
        'ban-customer', 'unban-customer', 'delete-customer' => (function () use ($action) {
            $customerId = clean_string($_POST['customer_id'] ?? '', 80);
            $before = supabase_get_customer($customerId);
            if ($before === null) {
                return null;
            }
            $after = $before;
            if ($action === 'ban-customer') {
                $after['status'] = 'banned';
            } elseif ($action === 'unban-customer') {
                $after['status'] = 'active';
            }
            return admin_request_pack('Customers', $action === 'delete-customer' ? 'delete' : 'update', 'Customer Account', clean_string((string) ($before['name'] ?? $customerId), 120), [
                'target_id' => $customerId,
                'before' => admin_request_snapshot_customer($before),
                'after' => $action === 'delete-customer' ? null : admin_request_snapshot_customer($after),
                'raw_before' => $before,
                'raw_after' => $action === 'delete-customer' ? null : $after,
            ]);
        })(),
        'mark-order-status' => (function () {
            $orderId = clean_string($_POST['order_id'] ?? '', 80);
            $status = order_status_normalize(clean_string($_POST['status'] ?? 'received', 40));
            $before = supabase_get_order($orderId);
            if ($before === null) {
                return null;
            }
            $after = admin_order_apply_status($before, $status, clean_string($_POST['tracking_id'] ?? '', 120));
            return admin_request_pack('Orders', 'update', 'Order', $orderId, [
                'target_id' => $orderId,
                'before' => admin_request_snapshot_order($before),
                'after' => admin_request_snapshot_order($after),
                'raw_before' => $before,
                'raw_after' => $after,
            ]);
        })(),
        'resolve-order-request' => (function () {
            $orderId = clean_string($_POST['order_id'] ?? '', 80);
            $resolution = clean_string($_POST['resolution'] ?? '', 20);
            $before = supabase_get_order($orderId);
            if ($before === null) {
                return null;
            }
            return admin_request_pack('Orders', 'update', 'Customer Request', $orderId, [
                'target_id' => $orderId,
                'before' => admin_request_snapshot_order($before),
                'after' => ['Requested Resolution' => $resolution],
                'raw_before' => $before,
                'raw_after' => ['resolution' => $resolution],
            ]);
        })(),
        'create-coupon' => (function () use ($content) {
            $coupon = admin_ensure_unique_item_id(
                $content['coupons']['items'],
                admin_build_coupon_from_post([], count($content['coupons']['items']))
            );
            return admin_request_pack('Coupons', 'create', 'Coupon', clean_string((string) ($coupon['code'] ?? 'Coupon'), 120), [
                'target_id' => clean_string((string) ($coupon['id'] ?? ''), 80),
                'after' => admin_request_snapshot_coupon($coupon),
                'raw_after' => $coupon,
            ]);
        })(),
        'update-coupon' => (function () use ($content) {
            $couponId = clean_string($_POST['coupon_id'] ?? '', 80);
            $index = admin_array_find_index($content['coupons']['items'], $couponId);
            if ($index === null) {
                return null;
            }
            $coupon = admin_ensure_unique_item_id(
                    $content['coupons']['items'],
                    admin_build_coupon_from_post($content['coupons']['items'][$index], $index),
                    $couponId
                );
            return admin_request_pack('Coupons', 'update', 'Coupon', clean_string((string) ($coupon['code'] ?? $couponId), 120), [
                'target_id' => $couponId,
                'before' => admin_request_snapshot_coupon($content['coupons']['items'][$index]),
                'after' => admin_request_snapshot_coupon($coupon),
                'raw_before' => $content['coupons']['items'][$index],
                'raw_after' => $coupon,
            ]);
        })(),
        'toggle-coupon', 'delete-coupon' => (function () use ($content, $action) {
            $couponId = clean_string($_POST['coupon_id'] ?? '', 80);
            $index = admin_array_find_index($content['coupons']['items'], $couponId);
            if ($index === null) {
                return null;
            }
            $before = $content['coupons']['items'][$index];
            $after = $before;
            if ($action === 'toggle-coupon') {
                $current = strtolower((string) ($before['status'] ?? 'active'));
                $after['status'] = $current === 'active' ? 'inactive' : 'active';
            }
            return admin_request_pack('Coupons', $action === 'delete-coupon' ? 'delete' : 'update', 'Coupon', clean_string((string) ($before['code'] ?? $couponId), 120), [
                'target_id' => $couponId,
                'before' => admin_request_snapshot_coupon($before),
                'after' => $action === 'delete-coupon' ? null : admin_request_snapshot_coupon($after),
                'raw_before' => $before,
                'raw_after' => $action === 'delete-coupon' ? null : $after,
            ]);
        })(),
        default => null,
    };
}

function admin_apply_request_payload(array &$content, array $request): string
{
    $action = clean_string((string) ($request['action'] ?? ''), 80);
    $payload = admin_request_legacy_payload_for_display($request, $content);
    $rawBefore = $payload['raw_before'] ?? ($payload['before'] ?? null);
    $rawAfter = $payload['raw_after'] ?? ($payload['after'] ?? null);

    switch ($action) {
        case 'save-hero':
            $content['hero'] = array_merge($content['hero'] ?? [], is_array($rawAfter) ? $rawAfter : []);
            save_site_content($content);
            return 'Hero updated.';
        case 'save-celebs':
            $content['celebs'] = is_array($rawAfter) ? $rawAfter : (array) ($content['celebs'] ?? []);
            save_site_content($content);
            return 'Celeb section updated.';
        case 'save-social-gallery':
            $content['social_gallery'] = is_array($rawAfter) ? $rawAfter : (array) ($content['social_gallery'] ?? []);
            save_site_content($content);
            return 'Social Gallery updated.';
        case 'save-settings':
            $content['settings'] = is_array($rawAfter) ? $rawAfter : (array) ($content['settings'] ?? []);
            save_site_content($content);
            return 'Site settings updated.';
        case 'save-categories':
            $content['category_cards'] = array_values(is_array($rawAfter) ? $rawAfter : []);
            save_site_content($content);
            return 'Categories updated.';
        case 'save-attribute-profile':
            $attributeType = clean_string((string) ($payload['target_id'] ?? ($payload['context']['Category'] ?? '')), 80);
            if ($attributeType === '') {
                return 'Request payload was invalid.';
            }
            $existingProfile = is_array($content['catalog_meta']['attribute_profiles'][$attributeType] ?? null)
                ? $content['catalog_meta']['attribute_profiles'][$attributeType]
                : [];
            $oldOptions = array_values($existingProfile['option_metal_options'] ?? []);
            $newProfile = clean_attribute_profile_item(is_array($rawAfter) ? $rawAfter : [], $attributeType);
            $content['catalog_meta']['attribute_profiles'][$attributeType] = $newProfile;
            $newOptions = array_values($newProfile['option_metal_options'] ?? []);
            $renameMap = [];
            foreach ($newOptions as $idx => $newOpt) {
                $oldOpt = $oldOptions[$idx] ?? null;
                if ($oldOpt === null) {
                    continue;
                }
                $oldLabel = trim((string) ($oldOpt['label'] ?? ''));
                $newLabel = trim((string) ($newOpt['label'] ?? ''));
                if ($oldLabel !== '' && $newLabel !== '' && $oldLabel !== $newLabel) {
                    $renameMap[$oldLabel] = $newLabel;
                }
            }
            if ($renameMap !== []) {
                $attrTypeLower = strtolower(trim($attributeType));
                foreach ($content['products']['items'] as &$productItem) {
                    // Ring products store product_type='Ring' and carry their real
                    // category in ring_category, so comparing product_type against
                    // the profile title would never match a ring and metal renames
                    // would silently skip every ring product.
                    $productTypeLower = strtolower(trim(product_attribute_profile_type($productItem)));
                    if ($productTypeLower !== $attrTypeLower || empty($productItem['metal_variations'])) {
                        continue;
                    }
                    foreach ($productItem['metal_variations'] as &$mv) {
                        $currentLabel = (string) ($mv['metal'] ?? '');
                        if (isset($renameMap[$currentLabel])) {
                            $mv['metal'] = $renameMap[$currentLabel];
                        }
                    }
                    unset($mv);
                }
                unset($productItem);
            }
            save_site_content($content);
            return $attributeType . ' attribute profile updated.';
        case 'adjust-metal-prices':
            $adjustment = is_array($rawAfter) ? $rawAfter : [];
            $result = admin_apply_metal_price_adjustment_live(
                clean_string((string) ($adjustment['attribute_type'] ?? ($payload['target_id'] ?? '')), 80),
                clean_string((string) ($adjustment['metal'] ?? ''), 120),
                strtolower(clean_string((string) ($adjustment['direction'] ?? ''), 20)),
                is_numeric($adjustment['percentage'] ?? null) ? (float) $adjustment['percentage'] : 0.0
            );
            return admin_metal_price_adjustment_message($result);
        case 'save-product-attributes':
            $productId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index === null) {
                return 'Product not found.';
            }
            $content['products']['items'][$index] = is_array($rawAfter) ? $rawAfter : $content['products']['items'][$index];
            save_site_content($content);
            return 'Product attributes updated.';
        case 'save-diamonds':
            admin_store_diamond_inventory($content, array_values(is_array($rawAfter) ? $rawAfter : []));
            save_site_content($content);
            return 'Diamond inventory updated.';
        case 'create-diamond':
            $existingDiamondProfile = admin_diamond_profile($content);
            $rows = array_values($existingDiamondProfile['diamond_inventory'] ?? []);
            $rows[] = is_array($rawAfter) ? $rawAfter : [];
            admin_store_diamond_inventory($content, $rows);
            save_site_content($content);
            return 'Diamond added.';
        case 'update-diamond':
            $diamondId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $rows = array_values(admin_diamond_profile($content)['diamond_inventory'] ?? []);
            $diamondIndex = admin_array_find_index($rows, $diamondId);
            if ($diamondIndex === null) {
                return 'Diamond not found.';
            }
            $rows[$diamondIndex] = is_array($rawAfter) ? $rawAfter : $rows[$diamondIndex];
            admin_store_diamond_inventory($content, $rows);
            save_site_content($content);
            return 'Diamond updated.';
        case 'delete-diamond':
            $diamondId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $rows = array_values(array_filter(
                array_values(admin_diamond_profile($content)['diamond_inventory'] ?? []),
                static fn (array $item): bool => (string) ($item['id'] ?? '') !== $diamondId
            ));
            admin_store_diamond_inventory($content, $rows);
            save_site_content($content);
            return 'Diamond removed.';
        case 'save-navigation':
            $content['navigation'] = is_array($rawAfter) ? $rawAfter : (array) ($content['navigation'] ?? []);
            save_site_content($content);
            return 'Navigation updated.';
        case 'save-footer':
            // The footer form posts only titles and the copyright line. Replacing
            // the array outright would wipe the link lists it does not render.
            $content['footer'] = array_replace_recursive(
                (array) ($content['footer'] ?? []),
                is_array($rawAfter) ? $rawAfter : []
            );
            save_site_content($content);
            return 'Footer updated.';
        case 'create-product':
            $content['products']['items'][] = is_array($rawAfter) ? $rawAfter : [];
            save_site_content($content);
            return 'Product uploaded to library.';
        case 'update-product':
            $productId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index === null) {
                return 'Product not found.';
            }
            $content['products']['items'][$index] = is_array($rawAfter) ? $rawAfter : $content['products']['items'][$index];
            save_site_content($content);
            return 'Product updated.';
        case 'save-inventory':
            $productId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index === null) {
                return 'Product not found.';
            }
            if (is_array($rawAfter)) {
                $content['products']['items'][$index]['inventory_tracked'] = !empty($rawAfter['inventory_tracked']);
                $content['products']['items'][$index]['inventory_quantity'] = !empty($rawAfter['inventory_tracked'])
                    ? clean_int($rawAfter['inventory_quantity'] ?? 0, 0, 1000000)
                    : 0;

                $requestedVariations = array_values(array_filter((array) ($rawAfter['metal_variations'] ?? []), 'is_array'));
                foreach ($content['products']['items'][$index]['metal_variations'] as &$variation) {
                    $metalLabel = clean_string((string) ($variation['metal'] ?? ''), 120);
                    if ($metalLabel === '') {
                        continue;
                    }
                    foreach ($requestedVariations as $requestedVariation) {
                        if (strcasecmp($metalLabel, clean_string((string) ($requestedVariation['metal'] ?? ''), 120)) !== 0) {
                            continue;
                        }
                        $variation['inventory_tracked'] = !empty($requestedVariation['inventory_tracked']);
                        $variation['inventory_quantity'] = !empty($requestedVariation['inventory_tracked'])
                            ? clean_int($requestedVariation['inventory_quantity'] ?? 0, 0, 1000000)
                            : 0;
                        break;
                    }
                }
                unset($variation);
            }
            save_site_content($content);
            return 'Inventory updated.';
        case 'delete-product':
            $productId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $content['products']['items'] = array_values(array_filter($content['products']['items'], static fn (array $item): bool => (string) ($item['id'] ?? '') !== $productId));
            foreach ($content['product_tabs']['tabs'] as &$tab) {
                $tab['product_ids'] = array_values(array_filter($tab['product_ids'] ?? [], static fn (string $id): bool => $id !== $productId));
            }
            unset($tab);
            $content['bestselling']['product_ids'] = array_values(array_filter($content['bestselling']['product_ids'] ?? [], static fn (string $id): bool => $id !== $productId));
            save_site_content($content);
            return 'Product deleted.';
        case 'save-catalog-assignments':
            $assigned = is_array($rawAfter) ? $rawAfter : [];
            $validIds = array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $content['products']['items']);
            foreach ($content['product_tabs']['tabs'] as &$tab) {
                $key = (string) ($tab['key'] ?? '');
                if ($key !== 'featured') {
                    continue;
                }
                $tab['product_ids'] = clean_select_ids(is_array($assigned[$key] ?? null) ? $assigned[$key] : [], $validIds);
            }
            unset($tab);
            $content['bestselling']['product_ids'] = clean_select_ids(is_array($assigned['bestselling'] ?? null) ? $assigned['bestselling'] : [], $validIds);
            $styleOptionIds = array_keys(homepage_style_showcase_options());
            $content['shop_by_style']['style_ids'] = clean_select_ids(admin_migrate_style_assignment_ids(is_array($assigned['shop_by_style'] ?? null) ? $assigned['shop_by_style'] : []), $styleOptionIds);
            save_site_content($content);
            return 'Catalog assignments updated.';
        case 'create-news':
            $content['news']['items'][] = is_array($rawAfter) ? $rawAfter : [];
            save_site_content($content);
            return 'News post created.';
        case 'update-news':
            $newsId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $index = admin_array_find_index($content['news']['items'], $newsId);
            if ($index === null) {
                return 'News post not found.';
            }
            $content['news']['items'][$index] = is_array($rawAfter) ? $rawAfter : $content['news']['items'][$index];
            save_site_content($content);
            return 'News post updated.';
        case 'delete-news':
            $newsId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $content['news']['items'] = array_values(array_filter($content['news']['items'], static fn (array $item): bool => (string) ($item['id'] ?? '') !== $newsId));
            save_site_content($content);
            return 'News post deleted.';
        case 'create-shape':
            $content['diamond_shapes']['items'][] = is_array($rawAfter) ? $rawAfter : [];
            save_site_content($content);
            return 'Diamond shape created.';
        case 'update-shape':
            $shapeTitle = clean_string((string) (($rawBefore['title'] ?? $payload['label'] ?? '')), 120);
            $shapeIndex = -1;
            foreach ($content['diamond_shapes']['items'] as $idx => $shapeItem) {
                if (clean_string((string) ($shapeItem['title'] ?? ''), 120) === $shapeTitle) {
                    $shapeIndex = $idx;
                    break;
                }
            }
            if (!isset($content['diamond_shapes']['items'][$shapeIndex])) {
                return 'Diamond shape not found.';
            }
            $content['diamond_shapes']['items'][$shapeIndex] = is_array($rawAfter) ? $rawAfter : $content['diamond_shapes']['items'][$shapeIndex];
            save_site_content($content);
            return 'Diamond shape updated.';
        case 'delete-shape':
            $shapeTitle = clean_string((string) (($rawBefore['title'] ?? $payload['label'] ?? '')), 120);
            $shapeIndex = -1;
            foreach ($content['diamond_shapes']['items'] as $idx => $shapeItem) {
                if (clean_string((string) ($shapeItem['title'] ?? ''), 120) === $shapeTitle) {
                    $shapeIndex = $idx;
                    break;
                }
            }
            if (!isset($content['diamond_shapes']['items'][$shapeIndex])) {
                return 'Diamond shape not found.';
            }
            unset($content['diamond_shapes']['items'][$shapeIndex]);
            $content['diamond_shapes']['items'] = array_values($content['diamond_shapes']['items']);
            save_site_content($content);
            return 'Diamond shape deleted.';
        case 'ban-customer':
        case 'unban-customer':
            $customerId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $customer = supabase_get_customer($customerId);
            if ($customer === null) {
                return 'Customer not found.';
            }
            $customer['status'] = $action === 'ban-customer' ? 'banned' : 'active';
            supabase_upsert_customer($customer);
            return $action === 'ban-customer' ? 'User banned.' : 'User restored.';
        case 'delete-customer':
            $customerId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            if (!supabase_delete_customer($customerId)) {
                return 'User account could not be deleted.';
            }
            return 'User account deleted.';
        case 'mark-order-status':
            $orderId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $status = clean_string((string) (($rawAfter['status'] ?? 'received')), 40);
            $order = supabase_get_order($orderId);
            if ($order === null) {
                return 'Order not found.';
            }
            $order = admin_order_apply_status(
                $order,
                $status,
                clean_string((string) (($rawAfter['tracking_id'] ?? '')), 120)
            );
            supabase_upsert_order($order);
            return 'Order status updated.';
        case 'resolve-order-request':
            $orderId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $resolution = clean_string((string) (($rawAfter['resolution'] ?? '')), 20);
            $order = supabase_get_order($orderId);
            if ($order === null) {
                return 'Order not found.';
            }
            $requestType = strtolower((string) ($order['customer_request_type'] ?? ''));
            $requestStatus = strtolower((string) ($order['customer_request_status'] ?? 'pending'));
            if (!in_array($requestType, ['cancel', 'return'], true)) {
                return 'Customer request not found.';
            }
            $now = gmdate('c');
            $paymentStatus = strtolower((string) ($order['payment_status'] ?? 'awaiting'));
            if ($resolution === 'approve' && $requestStatus === 'pending') {
                $order['customer_request_status'] = 'approved';
                $order['customer_request_resolved_at'] = $now;
                $order['status'] = $requestType === 'cancel' ? 'cancel-approved' : 'return-approved';
                if ($requestType === 'cancel' && $paymentStatus === 'paid') {
                    $order['payment_status'] = 'refund-pending';
                }
                supabase_upsert_order($order);
                return 'Customer request approved.';
            }
            if ($resolution === 'reject' && in_array($requestStatus, ['pending', 'approved'], true)) {
                $order['customer_request_status'] = 'rejected';
                $order['customer_request_resolved_at'] = $now;
                supabase_upsert_order($order);
                return 'Customer request rejected.';
            }
            if ($resolution === 'complete' && $requestStatus === 'approved') {
                $order['customer_request_status'] = 'completed';
                $order['customer_request_resolved_at'] = $now;
                $order['status'] = $requestType === 'cancel' ? 'cancelled' : 'returned';
                $order['payment_status'] = $paymentStatus === 'awaiting' ? ($requestType === 'cancel' ? 'cancelled' : 'returned') : 'refunded';
                supabase_upsert_order($order);
                return 'Customer request completed.';
            }
            return 'No order request change was applied.';
        case 'create-coupon':
            $content['coupons']['items'][] = is_array($rawAfter) ? $rawAfter : [];
            save_site_content($content);
            return 'Coupon created.';
        case 'update-coupon':
            $couponId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $index = admin_array_find_index($content['coupons']['items'], $couponId);
            if ($index === null) {
                return 'Coupon not found.';
            }
            $content['coupons']['items'][$index] = is_array($rawAfter) ? $rawAfter : $content['coupons']['items'][$index];
            save_site_content($content);
            return 'Coupon updated.';
        case 'toggle-coupon':
            $couponId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $index = admin_array_find_index($content['coupons']['items'], $couponId);
            if ($index === null) {
                return 'Coupon not found.';
            }
            $content['coupons']['items'][$index] = is_array($rawAfter) ? $rawAfter : $content['coupons']['items'][$index];
            save_site_content($content);
            return 'Coupon status updated.';
        case 'delete-coupon':
            $couponId = clean_string((string) ($payload['target_id'] ?? ''), 80);
            $content['coupons']['items'] = array_values(array_filter($content['coupons']['items'], static fn (array $item): bool => (string) ($item['id'] ?? '') !== $couponId));
            save_site_content($content);
            return 'Coupon deleted.';
        default:
            return 'Request action was not recognized.';
    }
}

function admin_request_kind_from_action(string $action): string
{
    $action = strtolower(trim($action));
    if (str_starts_with($action, 'create-')) {
        return 'create';
    }
    if (str_starts_with($action, 'delete-')) {
        return 'delete';
    }
    return 'update';
}

function admin_request_entity_from_action(string $action): string
{
    return match ($action) {
        'save-hero' => 'Hero Section',
        'save-celebs' => 'Celeb Section',
        'save-social-gallery' => 'Social Gallery',
        'save-faq' => 'FAQ Section',
        'save-settings' => 'Site Settings',
        'save-categories' => 'Homepage Categories',
        'save-attribute-profile' => 'Attribute Profile',
        'adjust-metal-prices' => 'Metal Prices',
        'save-product-attributes' => 'Product Attributes',
        'save-diamonds' => 'Diamond Inventory',
        'create-diamond', 'update-diamond', 'delete-diamond' => 'Diamond',
        'save-navigation' => 'Navigation',
        'save-footer' => 'Footer',
        'create-product', 'update-product', 'delete-product' => 'Product',
        'save-catalog-assignments' => 'Catalog Assignments',
        'create-news', 'update-news', 'delete-news' => 'News Post',
        'create-shape', 'update-shape', 'delete-shape' => 'Diamond Shape',
        'ban-customer', 'unban-customer', 'delete-customer' => 'Customer Account',
        'mark-order-status' => 'Order',
        'resolve-order-request' => 'Customer Request',
        'create-coupon', 'update-coupon', 'toggle-coupon', 'delete-coupon' => 'Coupon',
        default => 'Admin Request',
    };
}

function admin_request_area_from_action(string $action): string
{
    return match ($action) {
        'save-hero', 'save-celebs', 'save-social-gallery', 'save-faq', 'create-shape', 'update-shape', 'delete-shape' => 'Homepage Content',
        'save-settings', 'save-navigation', 'save-footer' => 'Settings',
        'save-categories' => 'Categories',
        'save-attribute-profile', 'adjust-metal-prices', 'save-product-attributes' => 'Attributes',
        'save-diamonds', 'create-diamond', 'update-diamond', 'delete-diamond' => 'Diamonds',
        'create-product', 'update-product', 'delete-product', 'save-catalog-assignments' => 'Catalog',
        'create-news', 'update-news', 'delete-news' => 'Azuronn News',
        'ban-customer', 'unban-customer', 'delete-customer' => 'Customers',
        'mark-order-status', 'resolve-order-request' => 'Orders',
        'create-coupon', 'update-coupon', 'toggle-coupon', 'delete-coupon' => 'Coupons',
        default => 'Admin Requests',
    };
}

function admin_request_legacy_payload_for_display(array $request, array $content): array
{
    $action = clean_string((string) ($request['action'] ?? ''), 80);
    $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
    if ($payload === []) {
        return [];
    }

    if (
        array_key_exists('before', $payload) ||
        array_key_exists('after', $payload) ||
        array_key_exists('entity', $payload) ||
        array_key_exists('kind', $payload)
    ) {
        return $payload;
    }

    $kind = admin_request_kind_from_action($action);
    $area = admin_request_area_from_action($action);
    $entity = admin_request_entity_from_action($action);

    switch ($action) {
        case 'create-product':
        case 'update-product':
        case 'delete-product':
        case 'save-product-attributes':
            $productId = clean_string((string) ($payload['product_id'] ?? ''), 80);
            $requestedProduct = is_array($payload['product'] ?? null) ? $payload['product'] : [];
            $productIndex = $productId !== '' ? admin_array_find_index((array) ($content['products']['items'] ?? []), $productId) : null;
            $currentProduct = $productIndex !== null ? (array) ($content['products']['items'][$productIndex] ?? []) : [];
            $label = clean_string((string) ($requestedProduct['name'] ?? $currentProduct['name'] ?? $productId), 120);
            return admin_request_pack($area, $kind, $entity, $label !== '' ? $label : 'Product', [
                'target_id' => $productId,
                'before' => ($kind !== 'create' && $currentProduct !== []) ? admin_request_snapshot_product($currentProduct) : null,
                'after' => ($kind !== 'delete' && $requestedProduct !== []) ? admin_request_snapshot_product($requestedProduct) : null,
                'raw_before' => $kind !== 'create' ? $currentProduct : null,
                'raw_after' => $kind !== 'delete' ? $requestedProduct : null,
                'context' => admin_request_remove_empty([
                    'Product Type' => clean_string((string) ($requestedProduct['product_type'] ?? $currentProduct['product_type'] ?? ''), 80),
                ]),
            ]);

        case 'create-news':
        case 'update-news':
        case 'delete-news':
            $newsId = clean_string((string) ($payload['news_id'] ?? ''), 80);
            $requestedNews = is_array($payload['news'] ?? null) ? $payload['news'] : [];
            $newsIndex = $newsId !== '' ? admin_array_find_index((array) ($content['news']['items'] ?? []), $newsId) : null;
            $currentNews = $newsIndex !== null ? (array) ($content['news']['items'][$newsIndex] ?? []) : [];
            $label = clean_string((string) ($requestedNews['title'] ?? $currentNews['title'] ?? $newsId), 140);
            return admin_request_pack($area, $kind, $entity, $label !== '' ? $label : 'News post', [
                'target_id' => $newsId,
                'before' => ($kind !== 'create' && $currentNews !== []) ? admin_request_snapshot_news($currentNews) : null,
                'after' => ($kind !== 'delete' && $requestedNews !== []) ? admin_request_snapshot_news($requestedNews) : null,
                'raw_before' => $kind !== 'create' ? $currentNews : null,
                'raw_after' => $kind !== 'delete' ? $requestedNews : null,
            ]);

        case 'create-diamond':
        case 'update-diamond':
        case 'delete-diamond':
            $diamondId = clean_string((string) ($payload['diamond_id'] ?? ''), 80);
            $requestedDiamond = is_array($payload['diamond'] ?? null) ? $payload['diamond'] : [];
            $diamondRows = array_values(admin_diamond_profile($content)['diamond_inventory'] ?? []);
            $diamondIndex = $diamondId !== '' ? admin_array_find_index($diamondRows, $diamondId) : null;
            $currentDiamond = $diamondIndex !== null ? (array) ($diamondRows[$diamondIndex] ?? []) : [];
            $label = clean_string((string) ($requestedDiamond['title'] ?? $currentDiamond['title'] ?? $diamondId), 140);
            return admin_request_pack($area, $kind, $entity, $label !== '' ? $label : 'Diamond', [
                'target_id' => $diamondId,
                'before' => ($kind !== 'create' && $currentDiamond !== []) ? admin_request_snapshot_diamond($currentDiamond) : null,
                'after' => ($kind !== 'delete' && $requestedDiamond !== []) ? admin_request_snapshot_diamond($requestedDiamond) : null,
                'raw_before' => $kind !== 'create' ? $currentDiamond : null,
                'raw_after' => $kind !== 'delete' ? $requestedDiamond : null,
                'context' => admin_request_remove_empty([
                    'Shape' => clean_string((string) ($requestedDiamond['shape'] ?? $currentDiamond['shape'] ?? ''), 40),
                ]),
            ]);

        case 'create-coupon':
        case 'update-coupon':
        case 'toggle-coupon':
        case 'delete-coupon':
            $couponId = clean_string((string) ($payload['coupon_id'] ?? ''), 80);
            $requestedCoupon = is_array($payload['coupon'] ?? null) ? $payload['coupon'] : [];
            $couponIndex = $couponId !== '' ? admin_array_find_index((array) ($content['coupons']['items'] ?? []), $couponId) : null;
            $currentCoupon = $couponIndex !== null ? (array) ($content['coupons']['items'][$couponIndex] ?? []) : [];
            $label = clean_string((string) ($requestedCoupon['code'] ?? $currentCoupon['code'] ?? $couponId), 120);
            return admin_request_pack($area, $kind, $entity, $label !== '' ? $label : 'Coupon', [
                'target_id' => $couponId,
                'before' => ($kind !== 'create' && $currentCoupon !== []) ? admin_request_snapshot_coupon($currentCoupon) : null,
                'after' => ($kind !== 'delete' && $requestedCoupon !== []) ? admin_request_snapshot_coupon($requestedCoupon) : null,
                'raw_before' => $kind !== 'create' ? $currentCoupon : null,
                'raw_after' => $kind !== 'delete' ? $requestedCoupon : null,
            ]);

        case 'save-attribute-profile':
            $attributeType = clean_string((string) ($payload['attribute_type'] ?? ''), 80);
            $requestedProfile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
            $currentProfile = is_array($content['catalog_meta']['attribute_profiles'][$attributeType] ?? null)
                ? $content['catalog_meta']['attribute_profiles'][$attributeType]
                : [];
            return admin_request_pack($area, 'update', $entity, $attributeType !== '' ? ($attributeType . ' profile') : 'Attribute profile', [
                'target_id' => $attributeType,
                'before' => $currentProfile !== [] ? admin_request_snapshot_attribute_profile($attributeType, $currentProfile) : null,
                'after' => $requestedProfile !== [] ? admin_request_snapshot_attribute_profile($attributeType, $requestedProfile) : null,
                'raw_before' => $currentProfile,
                'raw_after' => $requestedProfile,
                'context' => admin_request_remove_empty(['Category' => $attributeType]),
            ]);

        case 'save-categories':
            $requestedCards = is_array($payload['category_cards'] ?? null) ? $payload['category_cards'] : [];
            return admin_request_pack($area, 'update', $entity, 'Homepage category cards', [
                'before' => admin_request_snapshot_category_cards((array) ($content['category_cards'] ?? [])),
                'after' => admin_request_snapshot_category_cards($requestedCards),
                'raw_before' => array_values((array) ($content['category_cards'] ?? [])),
                'raw_after' => $requestedCards,
            ]);

        case 'save-settings':
            $requestedSettings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
            return admin_request_pack($area, 'update', $entity, 'Global site settings', [
                'before' => admin_request_remove_empty((array) ($content['settings'] ?? [])),
                'after' => admin_request_remove_empty($requestedSettings),
                'raw_before' => (array) ($content['settings'] ?? []),
                'raw_after' => $requestedSettings,
            ]);

        case 'save-navigation':
            $requestedNavigation = is_array($payload['navigation'] ?? null) ? $payload['navigation'] : [];
            return admin_request_pack($area, 'update', $entity, 'Site navigation', [
                'before' => ['Menu Items' => count((array) ($content['navigation']['items'] ?? []))],
                'after' => ['Menu Items' => count((array) ($requestedNavigation['items'] ?? []))],
                'raw_before' => (array) ($content['navigation'] ?? []),
                'raw_after' => $requestedNavigation,
            ]);

        case 'save-footer':
            $requestedFooter = is_array($payload['footer'] ?? null) ? $payload['footer'] : [];
            return admin_request_pack($area, 'update', $entity, 'Footer content', [
                'before' => admin_request_remove_empty((array) ($content['footer'] ?? [])),
                'after' => admin_request_remove_empty($requestedFooter),
                'raw_before' => (array) ($content['footer'] ?? []),
                'raw_after' => $requestedFooter,
            ]);

        case 'save-hero':
            $requestedHero = is_array($payload['hero'] ?? null) ? $payload['hero'] : [];
            return admin_request_pack($area, 'update', $entity, 'Homepage hero', [
                'before' => admin_request_remove_empty((array) ($content['hero'] ?? [])),
                'after' => admin_request_remove_empty($requestedHero),
                'raw_before' => (array) ($content['hero'] ?? []),
                'raw_after' => $requestedHero,
            ]);

        case 'save-celebs':
            $requestedCelebs = is_array($payload['celebs'] ?? null) ? $payload['celebs'] : [];
            return admin_request_pack($area, 'update', $entity, clean_string((string) ($requestedCelebs['title'] ?? 'Celeb section'), 120), [
                'before' => admin_request_remove_empty((array) ($content['celebs'] ?? [])),
                'after' => admin_request_remove_empty($requestedCelebs),
                'raw_before' => (array) ($content['celebs'] ?? []),
                'raw_after' => $requestedCelebs,
            ]);

        case 'create-shape':
        case 'update-shape':
        case 'delete-shape':
            $requestedShape = is_array($payload['shape'] ?? null) ? $payload['shape'] : [];
            $shapeIndex = clean_int($payload['shape_index'] ?? -1, -1, 9999);
            $currentShape = $shapeIndex >= 0 && isset($content['diamond_shapes']['items'][$shapeIndex]) && is_array($content['diamond_shapes']['items'][$shapeIndex])
                ? $content['diamond_shapes']['items'][$shapeIndex]
                : [];
            $label = clean_string((string) ($requestedShape['title'] ?? $currentShape['title'] ?? 'Shape'), 120);
            return admin_request_pack($area, $kind, $entity, $label, [
                'before' => ($kind !== 'create' && $currentShape !== []) ? admin_request_snapshot_shape($currentShape) : null,
                'after' => ($kind !== 'delete' && $requestedShape !== []) ? admin_request_snapshot_shape($requestedShape) : null,
                'raw_before' => $kind !== 'create' ? $currentShape : null,
                'raw_after' => $kind !== 'delete' ? $requestedShape : null,
            ]);

        case 'save-catalog-assignments':
            $requestedAssigned = is_array($payload['assigned'] ?? null) ? $payload['assigned'] : [];
            return admin_request_pack($area, 'update', $entity, 'Homepage catalog assignments', [
                'before' => admin_request_snapshot_assignments($content, []),
                'after' => admin_request_snapshot_assignments($content, $requestedAssigned),
                'raw_after' => $requestedAssigned,
            ]);

        default:
            $label = clean_string((string) ($payload['label'] ?? $request['summary'] ?? 'Admin request'), 160);
            return admin_request_pack($area, $kind, $entity, $label !== '' ? $label : 'Admin request', [
                'target_id' => clean_string((string) ($payload['target_id'] ?? ''), 80),
                'after' => admin_request_remove_empty($payload),
                'raw_after' => $payload,
            ]);
    }
}

function admin_request_prepare_for_display(array $request, array $content): array
{
    $request['payload'] = admin_request_legacy_payload_for_display($request, $content);
    if (is_array($request['payload'] ?? null)) {
        $request['payload']['_action'] = clean_string((string) ($request['action'] ?? ''), 80);
    }

    if (is_array($request['payload'] ?? null) && $request['payload'] !== []) {
        $request['summary'] = admin_action_summary((string) ($request['action'] ?? ''), $request['payload']);
    }

    if (empty($request['details']) || !is_array($request['details'])) {
        $request['details'] = admin_request_detail_lines(
            clean_string((string) ($request['action'] ?? ''), 80),
            is_array($request['payload'] ?? null) ? $request['payload'] : []
        );
    }

    return $request;
}

function admin_request_detail_value(array $payload, string $side): mixed
{
    $raw = $payload['raw_' . $side] ?? null;
    $fallback = $payload[$side] ?? null;
    $action = clean_string((string) ($payload['_action'] ?? ''), 80);
    $targetId = clean_string((string) ($payload['target_id'] ?? ($payload['context']['Category'] ?? '')), 80);

    if (!is_array($raw)) {
        return $fallback;
    }

    return match ($action) {
        'save-attribute-profile' => admin_request_snapshot_attribute_profile($targetId, $raw),
        'save-product-attributes', 'create-product', 'update-product', 'delete-product' => admin_request_snapshot_product($raw),
        'save-inventory' => admin_request_snapshot_inventory_product($raw),
        'create-news', 'update-news', 'delete-news' => admin_request_snapshot_news($raw),
        'create-diamond', 'update-diamond', 'delete-diamond' => admin_request_snapshot_diamond($raw),
        'create-coupon', 'update-coupon', 'toggle-coupon', 'delete-coupon' => admin_request_snapshot_coupon($raw),
        'create-shape', 'update-shape', 'delete-shape' => admin_request_snapshot_shape($raw),
        'save-categories' => admin_request_snapshot_category_cards($raw),
        'save-footer', 'save-settings', 'save-navigation', 'save-hero', 'save-newsletter', 'save-celebs', 'save-catalog-assignments', 'save-diamonds' => admin_request_remove_empty($raw),
        default => $fallback ?? admin_request_remove_empty($raw),
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // A body larger than post_max_size makes PHP discard $_POST and $_FILES
    // entirely — including the CSRF token. Without this check that surfaces as
    // a bogus "security token is invalid" instead of the real cause, so detect
    // it from CONTENT_LENGTH before the token is examined.
    $postMax = admin_ini_bytes((string) ini_get('post_max_size'));
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($postMax > 0 && $contentLength > $postMax && $_POST === []) {
        admin_set_flash('error', 'That upload was too large for the server to accept (the whole form must stay under ' . admin_upload_max_label('video') . '). Nothing was saved — use a smaller file or paste a URL.');
        redirect(admin_entry_url());
    }

    if (!csrf_verify()) {
        admin_set_flash('error', 'The security token is invalid. Refresh and try again.');
        redirect(admin_entry_url());
    }

    $action = sanitize_text((string) ($_POST['action'] ?? ''));

    if ($action === 'login') {
        if (admin_is_locked()) {
            admin_set_flash('error', 'Too many failed logins. Try again later.');
            redirect(admin_entry_url());
        }

        $username = sanitize_text((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (admin_login($username, $password)) {
            admin_set_flash('success', admin_portal_heading() . ' access granted.');
        } else {
            admin_register_failed_login();
            admin_set_flash('error', 'Invalid credentials.');
        }
        redirect(admin_entry_url());
    }

    if ($action === 'logout') {
        admin_logout();
        admin_set_flash('success', 'You have been signed out.');
        redirect(admin_entry_url());
    }

    if (!admin_is_authenticated()) {
        admin_set_flash('error', 'Please sign in again.');
        redirect(admin_entry_url());
    }

    $returnView = sanitize_text((string) ($_POST['return_view'] ?? 'dashboard'));
    if (!in_array($returnView, admin_allowed_views(), true)) {
        $returnView = 'dashboard';
    }

    $content = site_content();

    if (admin_is_super_portal() && $action === 'save-employee-admin') {
        $employeeId = clean_string((string) ($_POST['employee_id'] ?? ''), 80);
        $name = clean_string((string) ($_POST['employee']['name'] ?? ''), 120);
        $username = clean_string((string) ($_POST['employee']['username'] ?? ''), 120);
        $status = clean_string((string) ($_POST['employee']['status'] ?? 'active'), 20);
        $password = (string) ($_POST['employee']['password'] ?? '');

        if ($name === '' || $username === '') {
            admin_set_flash('error', 'Employee name and login ID are required.');
            admin_redirect('employees', $employeeId !== '' ? ['employee_id' => $employeeId] : []);
        }

        foreach (admin_employee_accounts_with_fallback() as $existingEmployee) {
            if (
                strcasecmp((string) ($existingEmployee['username'] ?? ''), $username) === 0 &&
                (string) ($existingEmployee['id'] ?? '') !== $employeeId
            ) {
                admin_set_flash('error', 'That employee login ID is already in use.');
                admin_redirect('employees', $employeeId !== '' ? ['employee_id' => $employeeId] : []);
            }
        }

        if ($employeeId === '' && $password === '') {
            admin_set_flash('error', 'A password is required when creating a new employee admin.');
            admin_redirect('employees');
        }

        $savedEmployee = admin_upsert_employee_account([
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'status' => $status,
        ], $employeeId !== '' ? $employeeId : null);

        admin_set_flash('success', $employeeId !== '' ? 'Employee admin updated.' : 'Employee admin created.');
        admin_redirect('employees', ['employee_id' => (string) ($savedEmployee['id'] ?? '')]);
    }

    if (admin_is_super_portal() && $action === 'delete-employee-admin') {
        $employeeId = clean_string((string) ($_POST['employee_id'] ?? ''), 80);
        if ($employeeId !== '' && admin_delete_employee_account($employeeId)) {
            admin_set_flash('success', 'Employee admin removed.');
        } else {
            admin_set_flash('error', 'Employee admin could not be removed.');
        }
        admin_redirect('employees');
    }

    if (admin_is_super_portal() && $action === 'approve-admin-request') {
        $requestId = clean_string((string) ($_POST['request_id'] ?? ''), 80);
        $request = admin_find_request(admin_load_requests(), $requestId);
        if ($request !== null && (string) ($request['status'] ?? '') === 'pending') {
            $message = admin_apply_request_payload($content, $request);
            admin_update_request_status($requestId, 'approved', (string) ($_SESSION[admin_session_key()]['username'] ?? ADMIN_USERNAME), $message);
            admin_set_flash('success', 'Request approved. ' . $message);
        } else {
            admin_set_flash('error', 'Request could not be approved.');
        }
        admin_redirect('requests');
    }

    if (admin_is_super_portal() && $action === 'reject-admin-request') {
        $requestId = clean_string((string) ($_POST['request_id'] ?? ''), 80);
        $request = admin_find_request(admin_load_requests(), $requestId);
        if ($request !== null && (string) ($request['status'] ?? '') === 'pending') {
            admin_update_request_status($requestId, 'rejected', (string) ($_SESSION[admin_session_key()]['username'] ?? ADMIN_USERNAME), 'Rejected by Super Admin');
            admin_set_flash('success', 'Request rejected.');
        } else {
            admin_set_flash('error', 'Request could not be rejected.');
        }
        admin_redirect('requests');
    }

    if (admin_is_super_portal() && $action === 'process-order-refund') {
        require_once dirname(__DIR__) . '/includes/stripe.php';
        $orderId = clean_string((string) ($_POST['order_id'] ?? ''), 80);
        $refundResult = (static function () use ($orderId): array {
            $order = supabase_get_order($orderId);
            if ($order === null) {
                return ['ok' => false, 'message' => 'Order not found.'];
            }

            $requestType   = strtolower((string) ($order['customer_request_type'] ?? ''));
            $requestStatus = strtolower((string) ($order['customer_request_status'] ?? ''));
            $paymentStatus = strtolower((string) ($order['payment_status'] ?? ''));
            $paymentIntentId = trim((string) ($order['stripe_payment_intent_id'] ?? ''));

            $eligibleCancel = $requestType === 'cancel'
                && $requestStatus === 'approved'
                && $paymentStatus === 'refund-pending';
            $eligibleReturn = $requestType === 'return'
                && $requestStatus === 'completed'
                && in_array($paymentStatus, ['paid', 'refund-pending'], true);

            if (!$eligibleCancel && !$eligibleReturn) {
                return ['ok' => false, 'message' => 'This order is not eligible for a refund at this stage.'];
            }
            if ($paymentStatus === 'refunded') {
                return ['ok' => false, 'message' => 'This order has already been refunded.'];
            }
            if ($paymentIntentId === '') {
                return ['ok' => false, 'message' => 'No Stripe payment reference found on this order.'];
            }

            $breakdown = order_calculate_refund($order);
            $result = stripe_create_refund($paymentIntentId, $breakdown['refund_pence']);
            if (!($result['ok'] ?? false)) {
                return ['ok' => false, 'message' => 'Stripe refund failed: ' . (string) ($result['error'] ?? 'Unknown error.')];
            }

            $order['payment_status'] = 'refunded';
            $order['refund_id'] = (string) ($result['refund_id'] ?? '');
            $order['refunded_amount'] = $breakdown['refund_amount_label'];
            $order['refunded_at'] = gmdate('c');
            if ($requestType === 'cancel') {
                $order['status'] = 'cancelled';
                $order['customer_request_status'] = 'completed';
                $order['customer_request_resolved_at'] = gmdate('c');
            }
            supabase_upsert_order($order);

            return ['ok' => true, 'amount' => $breakdown['refund_amount_label']];
        })();

        if ($refundResult['ok'] ?? false) {
            admin_set_flash('success', 'Refund of ' . (string) ($refundResult['amount'] ?? '') . ' processed successfully via Stripe.');
        } else {
            admin_set_flash('error', (string) ($refundResult['message'] ?? 'Refund could not be processed.'));
        }
        admin_redirect('orders');
    }

    if (admin_is_employee_portal() && in_array($action, admin_requestable_actions(), true)) {
        $requestReturnParams = [];
        if ($action === 'adjust-metal-prices') {
            $adjustment = admin_metal_price_adjustment_from_post();
            if ((string) $adjustment['attribute_type'] !== '') {
                $requestReturnParams['type'] = (string) $adjustment['attribute_type'];
            }
        }
        $preparedPayload = admin_prepare_request_payload($action, $content);
        if ($preparedPayload === null) {
            $message = 'The request could not be prepared.';
            if ($action === 'adjust-metal-prices') {
                $previewContent = $content;
                $validation = admin_adjust_metal_prices(
                    $previewContent,
                    (string) $adjustment['attribute_type'],
                    (string) $adjustment['metal'],
                    (string) $adjustment['direction'],
                    (float) $adjustment['percentage']
                );
                $message = admin_metal_price_adjustment_message($validation);
            }
            admin_set_flash('error', $message);
            admin_redirect($returnView, $requestReturnParams);
        }
        $request = admin_create_request(
            $action,
            $returnView,
            admin_action_summary($action, $preparedPayload),
            $preparedPayload,
            (string) ($_SESSION[admin_session_key()]['username'] ?? EMPLOYEE_ADMIN_USERNAME),
            clean_string((string) ($_SESSION[admin_session_key()]['name'] ?? ''), 120)
        );
        admin_set_flash('success', !empty($request['_created']) ? 'Request has been sent to Super Admin.' : 'An identical pending request already exists for this change.');
        admin_redirect($returnView, $requestReturnParams);
    }

    switch ($action) {
        case 'save-hero':
            $content['hero']['offer'] = clean_string($_POST['hero']['offer'] ?? '', 160);
            $content['hero']['title'] = clean_string($_POST['hero']['title'] ?? '', 160);
            $content['hero']['price_prefix'] = clean_string($_POST['hero']['price_prefix'] ?? '', 80);
            $content['hero']['price_value'] = clean_string($_POST['hero']['price_value'] ?? '', 60);
            $content['hero']['cta_label'] = clean_string($_POST['hero']['cta_label'] ?? '', 60);
            $content['hero']['cta_url'] = clean_link($_POST['hero']['cta_url'] ?? '#');
            $content['hero']['image'] = admin_select_image_or_url('hero_image_url', 'hero_image_file', $content['hero']['image'] ?? '');
            save_site_content($content);
            admin_set_flash('success', 'Hero updated.');
            admin_redirect($returnView);
            break;

        case 'save-newsletter':
            if (!empty($_POST['newsletter_image_delete'])) {
                $content['newsletter']['image'] = '';
            } else {
                $content['newsletter']['image'] = admin_select_image_or_url('newsletter_image_url', 'newsletter_image_file', $content['newsletter']['image'] ?? '');
            }
            save_site_content($content);
            admin_set_flash('success', 'Banner section updated.');
            admin_redirect($returnView);
            break;

        case 'save-celebs':
            $content['celebs']['title'] = clean_string($_POST['celebs']['title'] ?? '', 120);
            $content['celebs']['items'] = is_array($_POST['celebs']['items'] ?? null) ? $_POST['celebs']['items'] : [];
            save_site_content($content);
            admin_set_flash('success', 'Celeb section updated.');
            admin_redirect($returnView);
            break;

        case 'save-social-gallery':
            $items = is_array($_POST['social_gallery']['items'] ?? null) ? $_POST['social_gallery']['items'] : [];
            foreach ($items as $idx => &$item) {
                $uploaded = admin_handle_image_upload('social_gallery_image_file_' . $idx, clean_image($item['image'] ?? ''));
                $item['image'] = $uploaded !== '' ? $uploaded : clean_image($item['image'] ?? '');
            }
            $content['social_gallery']['title'] = clean_string($_POST['social_gallery']['title'] ?? '', 120);
            $content['social_gallery']['items'] = $items;
            save_site_content($content);
            admin_set_flash('success', 'Social Gallery updated.');
            admin_redirect($returnView);
            break;

        case 'save-faq':
            $content['faq']['kicker'] = clean_string($_POST['faq']['kicker'] ?? '', 120);
            $content['faq']['title'] = clean_string($_POST['faq']['title'] ?? '', 120);
            $content['faq']['support_image'] = admin_select_image_or_url('faq_support_image_url', 'faq_support_image_file', $content['faq']['support_image'] ?? '');
            $content['faq']['support_title'] = clean_string($_POST['faq']['support_title'] ?? '', 120);
            $content['faq']['support_text'] = clean_string($_POST['faq']['support_text'] ?? '', 200);
            $content['faq']['support_btn_label'] = clean_string($_POST['faq']['support_btn_label'] ?? '', 60);
            $content['faq']['support_btn_url'] = clean_link($_POST['faq']['support_btn_url'] ?? '#');
            $content['faq']['items'] = is_array($_POST['faq']['items'] ?? null) ? $_POST['faq']['items'] : [];
            save_site_content($content);
            admin_set_flash('success', 'FAQ Section updated.');
            admin_redirect($returnView);
            break;

        case 'save-settings':
            // Settings are split across several forms, so each one posts only its
            // own fields. Replacing the array outright would reset every key the
            // submitting form does not render.
            $content['settings'] = array_replace_recursive(
                (array) $content['settings'],
                is_array($_POST['settings'] ?? null) ? $_POST['settings'] : []
            );
            save_site_content($content);
            admin_set_flash('success', 'Site settings updated.');
            admin_redirect($returnView);
            break;

        case 'save-categories':
            $cards = is_array($_POST['category_cards'] ?? null) ? $_POST['category_cards'] : [];
            foreach ($cards as $idx => &$card) {
                $fileField = "category_image_file_" . $idx;
                $uploaded = admin_handle_image_upload($fileField, '');
                if ($uploaded !== '') {
                    $card['image'] = $uploaded;
                } else {
                    $card['image'] = clean_image($card['image'] ?? '');
                }

                $uploadedHero = admin_handle_image_upload("category_hero_image_file_" . $idx, '');
                if ($uploadedHero !== '') {
                    $card['hero_image'] = $uploadedHero;
                } else {
                    $card['hero_image'] = clean_image($card['hero_image'] ?? '');
                }
            }
            $content['category_cards'] = $cards;
            save_site_content($content);
            admin_set_flash('success', 'Categories updated.');
            admin_redirect('categories');
            break;

        case 'adjust-metal-prices':
            $adjustment = admin_metal_price_adjustment_from_post();
            $result = admin_apply_metal_price_adjustment_live(
                (string) $adjustment['attribute_type'],
                (string) $adjustment['metal'],
                (string) $adjustment['direction'],
                (float) $adjustment['percentage']
            );
            admin_set_flash(!empty($result['ok']) ? 'success' : 'error', admin_metal_price_adjustment_message($result));
            admin_redirect('attributes', [
                'type' => (string) $adjustment['attribute_type'],
            ]);
            break;

        case 'save-attribute-profile':
            $attributeType = clean_string($_POST['attribute_type'] ?? '', 80);
            if ($attributeType !== '') {
                $existingProfile = is_array($content['catalog_meta']['attribute_profiles'][$attributeType] ?? null)
                    ? $content['catalog_meta']['attribute_profiles'][$attributeType]
                    : [];

                // Capture old metal labels BEFORE overwriting the profile
                $oldOptions = array_values($existingProfile['option_metal_options'] ?? []);

                // Build the new profile
                $newProfile = admin_build_attribute_profile_from_post($attributeType, $existingProfile);
                $content['catalog_meta']['attribute_profiles'][$attributeType] = $newProfile;

                $newOptions = array_values($newProfile['option_metal_options'] ?? []);

                // Build old→new rename map using positional index (same position = same metal slot)
                // This works even when the value/slug field isn't submitted by the form
                $renameMap = [];
                foreach ($newOptions as $idx => $newOpt) {
                    $oldOpt = $oldOptions[$idx] ?? null;
                    if ($oldOpt === null) continue;
                    $oldLabel = trim((string)($oldOpt['label'] ?? ''));
                    $newLabel = trim((string)($newOpt['label'] ?? ''));
                    if ($oldLabel !== '' && $newLabel !== '' && $oldLabel !== $newLabel) {
                        $renameMap[$oldLabel] = $newLabel;
                    }
                }

                // Propagate renames to every product whose type matches this attribute profile
                // Only products of THIS type are touched — other types' metals are untouched
                if (!empty($renameMap)) {
                    $attrTypeLower = strtolower(trim($attributeType));
                    foreach ($content['products']['items'] as &$productItem) {
                        // Resolve through the product: rings store product_type='Ring'
                        // with their section in ring_category, so a raw product_type
                        // compare never matches 'Engagement Rings' / 'Wedding Rings'.
                        $productTypeLower = strtolower(trim(product_attribute_profile_type($productItem)));
                        if ($productTypeLower !== $attrTypeLower) {
                            continue; // Different type — skip entirely
                        }
                        if (empty($productItem['metal_variations'])) {
                            continue;
                        }
                        $changed = false;
                        foreach ($productItem['metal_variations'] as &$mv) {
                            $currentLabel = (string)($mv['metal'] ?? '');
                            if (isset($renameMap[$currentLabel])) {
                                $mv['metal'] = $renameMap[$currentLabel];
                                $changed = true;
                            }
                        }
                        unset($mv);
                        // If product name contains the old metal suffix, update it too
                        // e.g. "Pearl Teardrop - Gold 9k" → "Pearl Teardrop - Gold 12k"
                        if ($changed && isset($productItem['name'])) {
                            foreach ($renameMap as $oldLabel => $newLabel) {
                                $suffix = ' - ' . $oldLabel;
                                if (str_ends_with($productItem['name'], $suffix)) {
                                    $productItem['name'] = substr($productItem['name'], 0, -strlen($suffix)) . ' - ' . $newLabel;
                                }
                            }
                        }
                    }
                    unset($productItem);
                }

                save_site_content($content);
                $renameCount = count($renameMap);
                $msg = $attributeType . ' attribute profile updated.';
                if ($renameCount > 0) {
                    $msg .= ' ' . $renameCount . ' metal name(s) renamed and propagated to all ' . $attributeType . ' products.';
                }
                admin_set_flash('success', $msg);
            }
            admin_redirect('attributes', ['type' => $attributeType !== '' ? $attributeType : clean_string($_GET['type'] ?? 'Ring', 80)]);
            break;


        case 'save-product-attributes':
            $productId = clean_string($_POST['product_id'] ?? '', 80);
            $attributeType = clean_string($_POST['attribute_type'] ?? '', 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index !== null) {
                $content['products']['items'][$index] = admin_ensure_unique_item_id(
                    $content['products']['items'],
                    admin_build_product_attribute_overrides_from_post($content['products']['items'][$index]),
                    $productId
                );
                save_site_content($content);
                admin_set_flash('success', 'Product attributes updated.');
            }
            admin_redirect('attributes', array_filter([
                'type' => $attributeType,
                'attribute_product' => $productId,
            ], static fn (string $value): bool => $value !== ''));
            break;

        case 'save-diamonds':
            $existingDiamondProfile = admin_diamond_profile($content);
            admin_store_diamond_inventory($content, admin_build_diamond_inventory_from_post($existingDiamondProfile['diamond_inventory'] ?? []));
            save_site_content($content);
            admin_set_flash('success', 'Diamond inventory updated.');
            admin_redirect('diamonds');
            break;

        case 'create-diamond':
            $diamondShapeFilterPost = clean_string($_POST['diamond_shape_filter'] ?? '', 40);
            $existingDiamondProfile = admin_diamond_profile($content);
            $diamondRows = array_values($existingDiamondProfile['diamond_inventory'] ?? []);
            $diamondRows[] = admin_build_diamond_row_from_post([], count($diamondRows));
            admin_store_diamond_inventory($content, $diamondRows);
            save_site_content($content);
            admin_set_flash('success', 'Diamond added.');
            admin_redirect('diamonds', $diamondShapeFilterPost !== '' ? ['diamond_shape' => $diamondShapeFilterPost] : []);
            break;

        case 'update-diamond':
            $diamondId = clean_string($_POST['diamond_id'] ?? '', 80);
            $diamondShapeFilterPost = clean_string($_POST['diamond_shape_filter'] ?? '', 40);
            $existingDiamondProfile = admin_diamond_profile($content);
            $diamondRows = array_values($existingDiamondProfile['diamond_inventory'] ?? []);
            $diamondIndex = admin_array_find_index($diamondRows, $diamondId);
            if ($diamondIndex !== null) {
                $diamondRows[$diamondIndex] = admin_build_diamond_row_from_post($diamondRows[$diamondIndex], $diamondIndex);
                admin_store_diamond_inventory($content, $diamondRows);
                save_site_content($content);
                admin_set_flash('success', 'Diamond updated.');
            }
            admin_redirect('diamonds', $diamondShapeFilterPost !== '' ? ['diamond_shape' => $diamondShapeFilterPost] : []);
            break;

        case 'delete-diamond':
            $diamondId = clean_string($_POST['diamond_id'] ?? '', 80);
            $diamondShapeFilterPost = clean_string($_POST['diamond_shape_filter'] ?? '', 40);
            $existingDiamondProfile = admin_diamond_profile($content);
            $diamondRows = array_values(array_filter(
                array_values($existingDiamondProfile['diamond_inventory'] ?? []),
                static fn (array $item): bool => (string) ($item['id'] ?? '') !== $diamondId
            ));
            admin_store_diamond_inventory($content, $diamondRows);
            save_site_content($content);
            admin_set_flash('success', 'Diamond removed.');
            admin_redirect('diamonds', $diamondShapeFilterPost !== '' ? ['diamond_shape' => $diamondShapeFilterPost] : []);
            break;

        case 'save-navigation':
            $content['navigation'] = $_POST['navigation'] ?? $content['navigation'];
            save_site_content($content);
            admin_set_flash('success', 'Navigation updated.');
            admin_redirect($returnView);
            break;

        case 'save-footer':
            // The footer form posts only titles and the copyright line. Replacing
            // the array outright would wipe the link lists it does not render.
            $content['footer'] = array_replace_recursive(
                (array) ($content['footer'] ?? []),
                is_array($_POST['footer'] ?? null) ? $_POST['footer'] : []
            );
            save_site_content($content);
            admin_set_flash('success', 'Footer updated.');
            admin_redirect($returnView);
            break;

        case 'create-product':
            // A product must be filed under a real category. Without this a POST
            // with no category_taxonomy fell through to a guessed type, which is
            // how products silently landed under Engagement Rings.
            $newProductTaxonomy = clean_string((string) (($_POST['product']['category_taxonomy'] ?? '')), 80);
            if ($newProductTaxonomy === '' || !isset(product_category_taxonomy_options()[$newProductTaxonomy])) {
                admin_set_flash('error', 'Choose a category before uploading the product.');
                admin_redirect('catalog', ['product_form' => 'create']);
                break;
            }
            $content['products']['items'][] = admin_ensure_unique_item_id(
                $content['products']['items'],
                admin_build_product_from_post([], count($content['products']['items']))
            );
            save_site_content($content);
            admin_set_flash('success', 'Product uploaded to library.');
            admin_redirect('catalog');
            break;

        case 'update-product':
            $productId = clean_string($_POST['product_id'] ?? '', 80);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index !== null) {
                $content['products']['items'][$index] = admin_ensure_unique_item_id(
                    $content['products']['items'],
                    admin_build_product_from_post($content['products']['items'][$index], $index),
                    $productId
                );
                save_site_content($content);
                admin_set_flash('success', 'Product updated.');
            }
            admin_redirect('catalog');
            break;

        case 'save-inventory':
            $productId = clean_string($_POST['inventory_product_id'] ?? '', 80);
            $inventoryType = clean_string($_POST['inventory_type'] ?? '', 80);
            $inventoryStatus = clean_string($_POST['inventory_status'] ?? '', 40);
            $index = admin_array_find_index($content['products']['items'], $productId);
            if ($index !== null) {
                $content['products']['items'][$index] = admin_build_product_inventory_from_post($content['products']['items'][$index]);
                save_site_content($content);
                admin_set_flash('success', 'Inventory updated.');
            }
            admin_redirect('inventory', array_filter([
                'inventory_product' => $productId,
                'inventory_type' => $inventoryType,
                'inventory_status' => $inventoryStatus,
            ], static fn (string $value): bool => $value !== ''));
            break;

        case 'delete-product':
            $productId = clean_string($_POST['product_id'] ?? '', 80);
            $content['products']['items'] = array_values(array_filter($content['products']['items'], static fn (array $item): bool => (string) ($item['id'] ?? '') !== $productId));
            foreach ($content['product_tabs']['tabs'] as &$tab) {
                $tab['product_ids'] = array_values(array_filter($tab['product_ids'] ?? [], static fn (string $id): bool => $id !== $productId));
            }
            unset($tab);
            $content['bestselling']['product_ids'] = array_values(array_filter($content['bestselling']['product_ids'] ?? [], static fn (string $id): bool => $id !== $productId));
            save_site_content($content);
            admin_set_flash('success', 'Product deleted.');
            admin_redirect('catalog');
            break;

        case 'save-catalog-assignments':
            $assigned = is_array($_POST['assignments'] ?? null) ? $_POST['assignments'] : [];
            $validIds = array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $content['products']['items']);
            foreach ($content['product_tabs']['tabs'] as &$tab) {
                $key = (string) ($tab['key'] ?? '');
                if ($key !== 'featured') {
                    continue;
                }
                $tab['product_ids'] = clean_select_ids(is_array($assigned[$key] ?? null) ? $assigned[$key] : [], $validIds);
            }
            unset($tab);
            $content['bestselling']['product_ids'] = clean_select_ids(is_array($assigned['bestselling'] ?? null) ? $assigned['bestselling'] : [], $validIds);
            $styleOptionIds = array_keys(homepage_style_showcase_options());
            $content['shop_by_style']['style_ids'] = clean_select_ids(admin_migrate_style_assignment_ids(is_array($assigned['shop_by_style'] ?? null) ? $assigned['shop_by_style'] : []), $styleOptionIds);
            save_site_content($content);
            admin_set_flash('success', 'Catalog assignments updated.');
            admin_redirect('catalog');
            break;

        case 'create-news':
            $content['news']['items'][] = admin_ensure_unique_item_id(
                $content['news']['items'],
                admin_build_news_from_post([], count($content['news']['items']))
            );
            save_site_content($content);
            admin_set_flash('success', 'News post created.');
            admin_redirect('news');
            break;

        case 'update-news':
            $newsId = clean_string($_POST['news_id'] ?? '', 80);
            $index = admin_array_find_index($content['news']['items'], $newsId);
            if ($index !== null) {
                $content['news']['items'][$index] = admin_ensure_unique_item_id(
                    $content['news']['items'],
                    admin_build_news_from_post($content['news']['items'][$index], $index),
                    $newsId
                );
                save_site_content($content);
                admin_set_flash('success', 'News post updated.');
            }
            admin_redirect('news');
            break;

        case 'delete-news':
            $newsId = clean_string($_POST['news_id'] ?? '', 80);
            $content['news']['items'] = array_values(array_filter($content['news']['items'], static fn (array $item): bool => (string) ($item['id'] ?? '') !== $newsId));
            save_site_content($content);
            admin_set_flash('success', 'News post deleted.');
            admin_redirect('news');
            break;

        case 'create-shape':
            $content['diamond_shapes']['items'][] = admin_build_shape_from_post([], count($content['diamond_shapes']['items']));
            save_site_content($content);
            admin_set_flash('success', 'Diamond shape created.');
            admin_redirect('content');
            break;

        case 'update-shape':
            $shapeIndex = clean_int($_POST['shape_index'] ?? -1, -1, 9999);
            if (isset($content['diamond_shapes']['items'][$shapeIndex])) {
                $content['diamond_shapes']['items'][$shapeIndex] = admin_build_shape_from_post($content['diamond_shapes']['items'][$shapeIndex], $shapeIndex);
                save_site_content($content);
                admin_set_flash('success', 'Diamond shape updated.');
            }
            admin_redirect('content');
            break;

        case 'delete-shape':
            $shapeIndex = clean_int($_POST['shape_index'] ?? -1, -1, 9999);
            if (isset($content['diamond_shapes']['items'][$shapeIndex])) {
                unset($content['diamond_shapes']['items'][$shapeIndex]);
                $content['diamond_shapes']['items'] = array_values($content['diamond_shapes']['items']);
                save_site_content($content);
                admin_set_flash('success', 'Diamond shape deleted.');
            }
            admin_redirect('content');
            break;

        case 'ban-customer':
        case 'unban-customer':
            $customerId = clean_string($_POST['customer_id'] ?? '', 80);
            $customer = supabase_get_customer($customerId);
            if ($customer !== null) {
                $customer['status'] = $action === 'ban-customer' ? 'banned' : 'active';
                supabase_upsert_customer($customer);
                admin_set_flash('success', $action === 'ban-customer' ? 'User banned.' : 'User restored.');
            }
            admin_redirect('customers');
            break;

        case 'delete-customer':
            $customerId = clean_string($_POST['customer_id'] ?? '', 80);
            if (supabase_delete_customer($customerId)) {
                admin_set_flash('success', 'User account deleted.');
            } else {
                admin_set_flash('error', 'User account could not be deleted.');
            }
            admin_redirect('customers');
            break;

        case 'mark-order-status':
            $orderId = clean_string($_POST['order_id'] ?? '', 80);
            $status = clean_string($_POST['status'] ?? 'received', 40);
            $order = supabase_get_order($orderId);
            if ($order !== null) {
                $order = admin_order_apply_status(
                    $order,
                    $status,
                    clean_string($_POST['tracking_id'] ?? '', 120)
                );
                supabase_upsert_order($order);
                admin_set_flash('success', 'Order status updated.');
            }
            admin_redirect('orders');
            break;

        case 'resolve-order-request':
            $orderId = clean_string($_POST['order_id'] ?? '', 80);
            $resolution = clean_string($_POST['resolution'] ?? '', 20);
            $order = supabase_get_order($orderId);
            if ($order !== null) {
                $requestType = strtolower((string) ($order['customer_request_type'] ?? ''));
                $requestStatus = strtolower((string) ($order['customer_request_status'] ?? 'pending'));
                if (in_array($requestType, ['cancel', 'return'], true)) {
                    $now = gmdate('c');
                    $paymentStatus = strtolower((string) ($order['payment_status'] ?? 'awaiting'));

                    if ($resolution === 'approve' && $requestStatus === 'pending') {
                        $order['customer_request_status'] = 'approved';
                        $order['customer_request_resolved_at'] = $now;
                        if ($requestType === 'cancel') {
                            $order['status'] = 'cancel-approved';
                            if ($paymentStatus === 'paid') {
                                $order['payment_status'] = 'refund-pending';
                            }
                        } else {
                            $order['status'] = 'return-approved';
                        }
                        admin_set_flash('success', 'Customer request approved.');
                    } elseif ($resolution === 'reject' && in_array($requestStatus, ['pending', 'approved'], true)) {
                        $order['customer_request_status'] = 'rejected';
                        $order['customer_request_resolved_at'] = $now;
                        admin_set_flash('success', 'Customer request rejected.');
                    } elseif ($resolution === 'complete' && $requestStatus === 'approved') {
                        $order['customer_request_status'] = 'completed';
                        $order['customer_request_resolved_at'] = $now;
                        $order['status'] = $requestType === 'cancel' ? 'cancelled' : 'returned';
                        $order['payment_status'] = $paymentStatus === 'awaiting' ? ($requestType === 'cancel' ? 'cancelled' : 'returned') : 'refunded';
                        admin_set_flash('success', 'Customer request completed.');
                    }
                    supabase_upsert_order($order);
                }
            }
            admin_redirect('orders');
            break;

        case 'create-coupon':
            $content['coupons']['items'][] = admin_ensure_unique_item_id(
                $content['coupons']['items'],
                admin_build_coupon_from_post([], count($content['coupons']['items']))
            );
            save_site_content($content);
            admin_set_flash('success', 'Coupon created.');
            admin_redirect('coupons');
            break;

        case 'update-coupon':
            $couponId = clean_string($_POST['coupon_id'] ?? '', 80);
            $index = admin_array_find_index($content['coupons']['items'], $couponId);
            if ($index !== null) {
                $content['coupons']['items'][$index] = admin_ensure_unique_item_id(
                    $content['coupons']['items'],
                    admin_build_coupon_from_post($content['coupons']['items'][$index], $index),
                    $couponId
                );
                save_site_content($content);
                admin_set_flash('success', 'Coupon updated.');
            }
            admin_redirect('coupons');
            break;

        case 'toggle-coupon':
            $couponId = clean_string($_POST['coupon_id'] ?? '', 80);
            $index = admin_array_find_index($content['coupons']['items'], $couponId);
            if ($index !== null) {
                $current = strtolower((string) ($content['coupons']['items'][$index]['status'] ?? 'active'));
                $content['coupons']['items'][$index]['status'] = $current === 'active' ? 'inactive' : 'active';
                save_site_content($content);
                admin_set_flash('success', 'Coupon status updated.');
            }
            admin_redirect('coupons');
            break;

        case 'delete-coupon':
            $couponId = clean_string($_POST['coupon_id'] ?? '', 80);
            $content['coupons']['items'] = array_values(array_filter($content['coupons']['items'], static fn (array $item): bool => (string) ($item['id'] ?? '') !== $couponId));
            save_site_content($content);
            admin_set_flash('success', 'Coupon deleted.');
            admin_redirect('coupons');
            break;

        case 'save-appointment-settings':
            $apStore = appointments_load();
            $pc = is_array($_POST['ap_config'] ?? null) ? $_POST['ap_config'] : [];
            $apWeekdays = [];
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $apDk) {
                $wd = is_array($pc['weekdays'][$apDk] ?? null) ? $pc['weekdays'][$apDk] : [];
                $apWeekdays[$apDk] = [
                    'open' => (string) ($wd['open'] ?? '10:00'),
                    'close' => (string) ($wd['close'] ?? '18:00'),
                    'closed' => !empty($wd['closed']),
                ];
            }
            // Closed dates now come from the calendar picker as an array of YYYY-MM-DD.
            $apBlackoutDates = array_values(array_filter(array_map(
                static fn (mixed $d): string => appointment_date((string) $d),
                (array) ($pc['blackout_dates'] ?? [])
            ), static fn (string $d): bool => $d !== ''));
            // Slot interval, capacity, per-service durations and time-blocks are no
            // longer exposed in the UI; preserve whatever is already stored so the
            // availability engine keeps honouring them without surprising changes.
            appointments_save([
                'config' => [
                    'default_duration' => $pc['default_duration'] ?? 60,
                    'slot_interval' => $apStore['config']['slot_interval'] ?? 15,
                    'lead_time_hours' => $pc['lead_time_hours'] ?? 2,
                    'max_advance_days' => $pc['max_advance_days'] ?? 90,
                    'capacity' => $apStore['config']['capacity'] ?? 1,
                    'weekdays' => $apWeekdays,
                    'blackout_dates' => $apBlackoutDates,
                    'blackout_ranges' => (array) ($apStore['config']['blackout_ranges'] ?? []),
                    'service_durations' => (array) ($apStore['config']['service_durations'] ?? []),
                ],
                'bookings' => $apStore['bookings'],
            ]);
            admin_set_flash('success', 'Appointment availability settings saved.');
            admin_redirect('appointments', ['ap_tab' => 'settings']);
            break;

        case 'appointment-confirm':
        case 'appointment-cancel':
        case 'appointment-complete':
            $apId = clean_string((string) ($_POST['appointment_id'] ?? ''), 80);
            $apNewStatus = $action === 'appointment-confirm' ? 'confirmed' : ($action === 'appointment-cancel' ? 'cancelled' : 'completed');
            if ($apId !== '') {
                appointments_with_lock(static function () use ($apId, $apNewStatus): void {
                    $s = appointments_load();
                    foreach ($s['bookings'] as &$b) {
                        if ((string) ($b['id'] ?? '') === $apId) {
                            $b['status'] = $apNewStatus;
                            break;
                        }
                    }
                    unset($b);
                    appointments_save($s);
                });
                admin_set_flash('success', 'Appointment marked ' . $apNewStatus . '.');
            }
            admin_redirect('appointments');
            break;

        case 'appointment-resend-email':
            $apId = clean_string((string) ($_POST['appointment_id'] ?? ''), 80);
            $apTarget = null;
            if ($apId !== '') {
                foreach (appointments_load()['bookings'] as $apB) {
                    if ((string) ($apB['id'] ?? '') === $apId) {
                        $apTarget = $apB;
                        break;
                    }
                }
            }
            $apSent = $apTarget !== null && appointment_send_confirmation($apTarget);
            if ($apSent) {
                appointments_with_lock(static function () use ($apId): void {
                    $s = appointments_load();
                    foreach ($s['bookings'] as &$b) {
                        if ((string) ($b['id'] ?? '') === $apId) {
                            $b['confirmation_email_sent'] = true;
                            $b['confirmation_email_at'] = gmdate('c');
                            break;
                        }
                    }
                    unset($b);
                    appointments_save($s);
                });
                admin_set_flash('success', 'Confirmation email re-sent.');
            } else {
                admin_set_flash('error', 'Confirmation email could not be sent. Check the booking has a valid email and the server mail settings.');
            }
            admin_redirect('appointments');
            break;

        case 'appointment-delete':
            $apId = clean_string((string) ($_POST['appointment_id'] ?? ''), 80);
            if ($apId !== '') {
                appointments_with_lock(static function () use ($apId): void {
                    $s = appointments_load();
                    $s['bookings'] = array_values(array_filter($s['bookings'], static fn (array $b): bool => (string) ($b['id'] ?? '') !== $apId));
                    appointments_save($s);
                });
                admin_set_flash('success', 'Appointment deleted.');
            }
            admin_redirect('appointments');
            break;
    }
}

if (isset($_GET['download']) && $_GET['download'] === 'content' && admin_is_authenticated() && admin_is_super_portal()) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="azuronn-site-content.json"');
    echo json_encode(site_content(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$content = site_content();
$flash = admin_pull_flash();
$uploadNotices = admin_pull_upload_notices();
$authenticated = admin_is_authenticated();
$view = admin_current_view();
$viewMeta = [
    'dashboard' => ['eyebrow' => 'Command Center', 'title' => 'Admin dashboard', 'summary' => 'Monitor catalog health, sales flow, content freshness, and operational tasks from one workspace.'],
    'catalog' => ['eyebrow' => 'Merchandising', 'title' => 'Catalog workflow', 'summary' => 'Manage the product library, refine taxonomies, and control which products feed the storefront sections.'],
    'inventory' => ['eyebrow' => 'Stock Control', 'title' => 'Inventory manager', 'summary' => 'Track product stock cleanly across simple products and metal-based variants, and keep availability aligned with live orders.'],
    'attributes' => ['eyebrow' => 'Attribute Studio', 'title' => 'Category and product attributes', 'summary' => 'Configure clean option flows by category, then fine-tune the products that need their own metal, size, or diamond setup.'],
    'news' => ['eyebrow' => 'Publishing', 'title' => 'News workflow', 'summary' => 'Create, revise, and retire editorial stories with a cleaner publishing workflow.'],
    'newsletter' => ['eyebrow' => 'Subscribers', 'title' => 'Newsletter subscribers', 'summary' => 'Review newsletter signups, confirm which subscriber emails are linked to customer accounts, and export a clean CSV.'],
    'customers' => ['eyebrow' => 'CRM', 'title' => 'Customer accounts', 'summary' => 'Review customer health, moderate account access, and keep the user base clean and searchable.'],
    'orders' => ['eyebrow' => 'Fulfilment', 'title' => 'Order operations', 'summary' => 'Process orders, resolve customer requests, and keep payment and delivery statuses aligned.'],
    'appointments' => ['eyebrow' => 'Bookings', 'title' => 'Appointments', 'summary' => 'Manage consultation bookings, control availability, and keep the appointment calendar aligned with showroom hours.'],
    // 'content' view is intentionally hidden for now.
    // Keep this metadata and the full content view block in place for future reuse.
    'content' => ['eyebrow' => 'Homepage Builder', 'title' => 'Content workflow', 'summary' => 'Update the homepage storytelling modules, diamond shapes, and supporting sections without editing raw content files.'],
    'coupons' => ['eyebrow' => 'Promotions', 'title' => 'Coupon manager', 'summary' => 'Launch offers, control expiry and usage, and maintain a clean promotions workflow.'],
    'requests' => ['eyebrow' => 'Request History', 'title' => 'Admin requests', 'summary' => 'Review employee changes before they affect the live store. Approve clean updates and reject anything that should not go live.'],
    'employees' => ['eyebrow' => 'Employee Access', 'title' => 'Employee admin accounts', 'summary' => 'Create and manage employee admin logins that can sign into the employee admin portal and submit requests.'],
    'site' => ['eyebrow' => 'Brand System', 'title' => 'Site settings', 'summary' => 'Control brand settings, mega menu content, and footer structure from one advanced settings area.'],
];
$allAdminRequests = array_values(array_map(
    static fn (array $request): array => admin_request_prepare_for_display($request, $content),
    admin_load_requests()
));
$adminRequests = admin_visible_requests($allAdminRequests);
$pendingAdminRequests = array_values(array_filter($adminRequests, static fn (array $request): bool => (string) ($request['status'] ?? '') === 'pending'));
$selectedRequestId = clean_string((string) ($_GET['request_id'] ?? ''), 80);
$selectedAdminRequest = $selectedRequestId !== '' ? admin_find_request($adminRequests, $selectedRequestId) : null;
$employeeAdminAccounts = admin_employee_accounts_with_fallback();
$selectedEmployeeAdminId = clean_string((string) ($_GET['employee_id'] ?? ''), 80);
$selectedEmployeeAdmin = $selectedEmployeeAdminId !== '' ? admin_find_employee_account($employeeAdminAccounts, $selectedEmployeeAdminId) : null;
$productMap = catalog_product_map();
$products = $content['products']['items'] ?? [];
// Product types come from category-card titles PLUS any type already used by a
// product, so removing/renaming a card never orphans existing products in the
// editor. Ring section labels are excluded — they are sections, not types.
$productTypes = catalog_active_product_types($content);
// Metal filter spans every category's profile — a Necklace-only metal must be
// selectable, not just whatever the Ring profile happens to define.
$productMetals = [];
foreach ((array) ($content['catalog_meta']['attribute_profiles'] ?? []) as $metalProfile) {
    foreach ((array) ($metalProfile['option_metal_options'] ?? []) as $metalOption) {
        $metalLabel = clean_string((string) ($metalOption['label'] ?? ''), 120);
        if ($metalLabel !== '' && !in_array($metalLabel, $productMetals, true)) {
            $productMetals[] = $metalLabel;
        }
    }
}
$catalogStatusFilter = clean_string($_GET['status'] ?? '', 80);
$catalogQuery = clean_string($_GET['q'] ?? '', 120);
$inventoryTypeFilter = clean_string($_GET['inventory_type'] ?? '', 80);
$inventoryStatusFilter = clean_string($_GET['inventory_status'] ?? '', 40);
$selectedInventoryProductId = clean_string((string) ($_GET['inventory_product'] ?? ''), 80);
$catalogTypeFilter = clean_string($_GET['type'] ?? '', 80);
$catalogMetalFilter = clean_string($_GET['metal'] ?? '', 80);
$filteredProducts = array_values(array_filter($products, static function (array $product) use ($catalogTypeFilter, $catalogMetalFilter, $catalogStatusFilter, $catalogQuery): bool {
    if ($catalogTypeFilter !== '' && (string) ($product['product_type'] ?? '') !== $catalogTypeFilter) {
        return false;
    }
    if ($catalogMetalFilter !== '') {
        $hasMetal = false;
        if (str_starts_with(strtolower((string) ($product['product_type'] ?? '')), 'ring') && !empty($product['metal_variations'])) {
            foreach ($product['metal_variations'] as $mv) {
                if (($mv['active'] ?? false) && strcasecmp((string)($mv['metal'] ?? ''), $catalogMetalFilter) === 0) {
                    $hasMetal = true;
                    break;
                }
            }
        } else {
            if (strcasecmp((string) ($product['color'] ?? ''), $catalogMetalFilter) === 0) {
                $hasMetal = true;
            }
        }
        if (!$hasMetal) {
            return false;
        }
    }
    if ($catalogStatusFilter !== '' && strtolower((string) ($product['status'] ?? '')) !== strtolower($catalogStatusFilter)) {
        return false;
    }
    if ($catalogQuery !== '') {
        $haystack = strtolower(implode(' ', [
            (string) ($product['id'] ?? ''),
            (string) ($product['name'] ?? ''),
            (string) ($product['product_type'] ?? ''),
            (string) ($product['color'] ?? ''),
            (string) ($product['category'] ?? ''),
            implode(' ', (array) ($product['subcategories'] ?? [])),
            implode(' ', (array) ($product['styles'] ?? [])),
        ]));
        if (!str_contains($haystack, strtolower($catalogQuery))) {
            return false;
        }
    }
    return true;
}));
$inventoryProductTypes = array_values(array_filter(array_unique(array_map(static fn (array $product): string => clean_string((string) ($product['product_type'] ?? ''), 80), $products))));
$inventoryProducts = array_values(array_filter($products, static function (array $product) use ($inventoryStatusFilter, $inventoryTypeFilter): bool {
    if ($inventoryTypeFilter !== '' && (string) ($product['product_type'] ?? '') !== $inventoryTypeFilter) {
        return false;
    }

    $summary = admin_inventory_summary($product);
    return match ($inventoryStatusFilter) {
        'tracked' => $summary['tracked_count'] > 0,
        'low' => $summary['status'] === 'low',
        'out' => $summary['status'] === 'out',
        'untracked' => $summary['status'] === 'untracked',
        default => true,
    };
}));
$selectedInventoryProduct = $selectedInventoryProductId !== '' ? ($productMap[$selectedInventoryProductId] ?? null) : null;
$inventoryProductIds = array_map(static fn (array $product): string => (string) ($product['id'] ?? ''), $inventoryProducts);
if ($selectedInventoryProduct === null || !in_array((string) ($selectedInventoryProduct['id'] ?? ''), $inventoryProductIds, true)) {
    $selectedInventoryProduct = $inventoryProducts[0] ?? null;
}
$inventoryTrackedCount = count(array_filter($products, static fn (array $product): bool => admin_inventory_summary($product)['tracked_count'] > 0));
$inventoryLowCount = count(array_filter($products, static fn (array $product): bool => admin_inventory_summary($product)['status'] === 'low'));
$inventoryOutCount = count(array_filter($products, static fn (array $product): bool => admin_inventory_summary($product)['status'] === 'out'));
// One canonical pill per real category: card titles like "Earrings" and product
// types like "Earring" collapse into a single "Earrings" (Earring) profile pill.
$rawAttributeTypes = $productTypes;
$attributeTypes = [];
$attributeTypeLabels = [];
foreach ($rawAttributeTypes as $rawAttributeType) {
    $canonicalAttributeType = admin_canonical_attribute_type((string) $rawAttributeType);
    if ($canonicalAttributeType === '' || isset($attributeTypeLabels[$canonicalAttributeType])) {
        continue;
    }
    $attributeTypes[] = $canonicalAttributeType;
    $attributeTypeLabels[$canonicalAttributeType] = homepage_style_type_label($canonicalAttributeType);
}
// Default editor context stays a ring type (as it was when the "Rings" card
// led the list) so the Ring attribute profile opens by default.
$defaultAttributeType = $attributeTypes[0] ?? 'Ring';
foreach ($attributeTypes as $candidateType) {
    if (admin_product_type_is_ring((string) $candidateType)) {
        $defaultAttributeType = (string) $candidateType;
        break;
    }
}
$attributeTypeFilter = admin_canonical_attribute_type(clean_string($_GET['type'] ?? $defaultAttributeType, 80));
if (!in_array($attributeTypeFilter, $attributeTypes, true)) {
    $attributeTypeFilter = $defaultAttributeType;
}
$attributeTypeIsRing = admin_product_type_is_ring($attributeTypeFilter);
$attributeTypeIsMatrix = admin_product_type_is_matrix($attributeTypeFilter);
$catalogAttributeProfilesForJs = [];
foreach ($attributeTypes as $attributeType) {
    $profileForJs = catalog_attribute_profile((string) $attributeType, $content);
    unset($profileForJs['diamond_intro_kicker'], $profileForJs['diamond_intro_text']);
    $catalogAttributeProfilesForJs[$attributeType] = $profileForJs;
}
$attributeProfile = catalog_attribute_profile($attributeTypeFilter, $content);
$attributeProducts = array_values(array_filter($products, static function (array $product) use ($attributeTypeFilter): bool {
    // Ring products all store product_type='Ring', so resolve the profile through
    // the product — otherwise every ring lists under Engagement Rings.
    return product_attribute_profile_type($product) === $attributeTypeFilter;
}));
$diamondAdminProfile = admin_diamond_profile($content);
$diamondAdminInventory = array_values($diamondAdminProfile['diamond_inventory'] ?? []);
$diamondShapeFilter = clean_string($_GET['diamond_shape'] ?? '', 40);
if ($diamondShapeFilter !== '' && !array_key_exists($diamondShapeFilter, available_diamond_shapes())) {
    $diamondShapeFilter = '';
}
$diamondCreate = ($_GET['diamond_form'] ?? '') === 'create';
$diamondEditId = clean_string($_GET['diamond_edit'] ?? '', 80);
$diamondEditIndex = $diamondEditId !== '' ? admin_array_find_index($diamondAdminInventory, $diamondEditId) : null;
$editingDiamond = $diamondEditIndex !== null ? $diamondAdminInventory[$diamondEditIndex] : null;
$filteredDiamondAdminInventory = array_values(array_filter($diamondAdminInventory, static function (array $row) use ($diamondShapeFilter): bool {
    if ($diamondShapeFilter === '') {
        return true;
    }
    return strtolower((string) ($row['shape'] ?? '')) === strtolower($diamondShapeFilter);
}));
$diamondAdminShapeStats = [];
foreach (available_diamond_shapes() as $diamondShapeKey => $diamondShapeLabel) {
    $diamondAdminShapeStats[$diamondShapeKey] = [
        'label' => $diamondShapeLabel,
        'count' => count(array_filter($diamondAdminInventory, static function (array $row) use ($diamondShapeKey): bool {
            return strtolower((string) ($row['shape'] ?? '')) === strtolower($diamondShapeKey);
        })),
    ];
}
$attributeProductId = clean_string($_GET['attribute_product'] ?? '', 80);
$attributeEditingProduct = $attributeProductId !== '' ? ($productMap[$attributeProductId] ?? null) : null;
if ($attributeEditingProduct !== null && product_attribute_profile_type($attributeEditingProduct) !== $attributeTypeFilter) {
    $attributeEditingProduct = null;
}
$newsQuery = clean_string($_GET['news_q'] ?? '', 120);
$filteredNews = array_values(array_filter($content['news']['items'] ?? [], static function (array $post) use ($newsQuery): bool {
    if ($newsQuery === '') {
        return true;
    }
    $haystack = strtolower(implode(' ', [
        (string) ($post['title'] ?? ''),
        (string) ($post['author'] ?? ''),
        (string) ($post['excerpt'] ?? ''),
    ]));
    return str_contains($haystack, strtolower($newsQuery));
}));
$newsletterQuery = clean_string($_GET['newsletter_q'] ?? '', 120);
$newsletterExportFormat = clean_string($_GET['newsletter_export'] ?? 'name-email', 40);
if (!array_key_exists($newsletterExportFormat, admin_newsletter_csv_format_options())) {
    $newsletterExportFormat = 'name-email';
}
$newsletterSubscribers = admin_newsletter_subscribers($content);
$filteredNewsletterSubscribers = array_values(array_filter($newsletterSubscribers, static function (array $subscriber) use ($newsletterQuery): bool {
    if ($newsletterQuery === '') {
        return true;
    }

    $haystack = strtolower(implode(' ', [
        (string) ($subscriber['account_holder_name'] ?? ''),
        (string) ($subscriber['account_holder_email'] ?? ''),
        (string) ($subscriber['subscribed_email'] ?? ''),
        (string) ($subscriber['source'] ?? ''),
    ]));

    return str_contains($haystack, strtolower($newsletterQuery));
}));
$newsletterLinkedCount = count(array_filter($newsletterSubscribers, static function (array $subscriber): bool {
    return clean_string((string) ($subscriber['account_holder_name'] ?? ''), 120) !== '';
}));
$newsletterGuestCount = count($newsletterSubscribers) - $newsletterLinkedCount;
$customerStatusFilter = clean_string($_GET['customer_status'] ?? '', 80);
$customerQuery = clean_string($_GET['customer_q'] ?? '', 120);
$allCustomers = supabase_list_customers();
$filteredCustomers = array_values(array_filter($allCustomers, static function (array $user) use ($customerStatusFilter, $customerQuery): bool {
    if ($customerStatusFilter !== '' && strtolower((string) ($user['status'] ?? '')) !== strtolower($customerStatusFilter)) {
        return false;
    }
    if ($customerQuery !== '') {
        $haystack = strtolower(implode(' ', [
            (string) ($user['id'] ?? ''),
            (string) ($user['name'] ?? ''),
            (string) ($user['email'] ?? ''),
            (string) ($user['city'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($customerQuery))) {
            return false;
        }
    }
    return true;
}));
$orderStatusFilter = clean_string($_GET['order_status'] ?? '', 80);
$orderRequestFilter = clean_string($_GET['request_status'] ?? '', 80);
$orderRequestTypeFilter = clean_string($_GET['request_type'] ?? '', 40);
$orderQuery = clean_string($_GET['order_q'] ?? '', 120);
$allOrders = supabase_list_orders();
$filteredOrders = array_values(array_filter($allOrders, static function (array $order) use ($orderStatusFilter, $orderRequestFilter, $orderRequestTypeFilter, $orderQuery): bool {
    if ($orderStatusFilter !== '' && order_status_normalize((string) ($order['status'] ?? '')) !== order_status_normalize($orderStatusFilter)) {
        return false;
    }
    if ($orderRequestFilter !== '') {
        $requestStatus = strtolower((string) ($order['customer_request_status'] ?? ''));
        if ($requestStatus !== strtolower($orderRequestFilter)) {
            return false;
        }
    }
    if ($orderRequestTypeFilter !== '' && strtolower((string) ($order['customer_request_type'] ?? '')) !== strtolower($orderRequestTypeFilter)) {
        return false;
    }
    if ($orderQuery !== '') {
        $haystack = strtolower(implode(' ', [
            (string) ($order['id'] ?? ''),
            (string) ($order['customer_name'] ?? ''),
            (string) ($order['customer_email'] ?? ''),
            (string) ($order['status'] ?? ''),
            (string) ($order['payment_status'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($orderQuery))) {
            return false;
        }
    }
    return true;
}));

// Cancellations and returns get their own board: an order belongs here if the
// customer raised a request of either kind, or if it already landed in a
// cancelled/returned state through an admin status change.
$cancelReturnOrders = array_values(array_filter($allOrders, static function (array $order): bool {
    if (in_array(strtolower((string) ($order['customer_request_type'] ?? '')), ['cancel', 'return'], true)) {
        return true;
    }

    return in_array(order_status_normalize((string) ($order['status'] ?? '')), ['cancelled', 'returned'], true);
}));
$cancelReturnOpenCount = count(array_filter($cancelReturnOrders, static fn (array $order): bool => in_array(strtolower((string) ($order['customer_request_status'] ?? '')), ['pending', 'approved'], true)));
// ── Appointments (bookings + availability) ────────────────────────────────
$appointmentsStore = appointments_load();
$apConfig = $appointmentsStore['config'];
$apBookings = $appointmentsStore['bookings'];
$apServices = appointment_services($apConfig);
$apTabRaw = (string) ($_GET['ap_tab'] ?? '');
$apTab = in_array($apTabRaw, ['bookings', 'settings'], true) ? $apTabRaw : 'bookings';
$apStatusFilter = clean_string((string) ($_GET['ap_status'] ?? ''), 40);
$apServiceFilter = clean_string((string) ($_GET['ap_service'] ?? ''), 80);
$apQuery = clean_string((string) ($_GET['ap_q'] ?? ''), 120);
$apToday = date('Y-m-d');
$apUpcoming = array_values(array_filter($apBookings, static fn (array $b): bool => appointment_is_live($b) && (string) ($b['date'] ?? '') >= $apToday));
$apTodayCount = count(array_filter($apBookings, static fn (array $b): bool => appointment_is_live($b) && (string) ($b['date'] ?? '') === $apToday));
$apCancelledCount = count(array_filter($apBookings, static fn (array $b): bool => strcasecmp((string) ($b['status'] ?? ''), 'cancelled') === 0));
$filteredBookings = array_values(array_filter($apBookings, static function (array $b) use ($apStatusFilter, $apServiceFilter, $apQuery): bool {
    if ($apStatusFilter !== '' && strcasecmp((string) ($b['status'] ?? ''), $apStatusFilter) !== 0) {
        return false;
    }
    if ($apServiceFilter !== '' && (string) ($b['service'] ?? '') !== $apServiceFilter) {
        return false;
    }
    if ($apQuery !== '') {
        $haystack = strtolower(implode(' ', [
            (string) ($b['ref'] ?? ''), (string) ($b['first_name'] ?? ''), (string) ($b['last_name'] ?? ''),
            (string) ($b['email'] ?? ''), (string) ($b['mobile'] ?? ''), (string) ($b['service_label'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($apQuery))) {
            return false;
        }
    }
    return true;
}));
usort($filteredBookings, static function (array $a, array $b): int {
    $da = (string) ($b['date'] ?? '') . ' ' . (string) ($b['time'] ?? '');
    $db = (string) ($a['date'] ?? '') . ' ' . (string) ($a['time'] ?? '');
    return strcmp($da, $db);
});

$couponStatusFilter = clean_string($_GET['coupon_status'] ?? '', 80);
$couponQuery = clean_string($_GET['coupon_q'] ?? '', 120);
$filteredCoupons = array_values(array_filter($content['coupons']['items'] ?? [], static function (array $coupon) use ($couponStatusFilter, $couponQuery): bool {
    if ($couponStatusFilter !== '' && strtolower((string) ($coupon['status'] ?? '')) !== strtolower($couponStatusFilter)) {
        return false;
    }
    if ($couponQuery !== '') {
        $haystack = strtolower(implode(' ', [
            (string) ($coupon['code'] ?? ''),
            (string) ($coupon['description'] ?? ''),
            (string) ($coupon['apply_label'] ?? ''),
        ]));
        if (!str_contains($haystack, strtolower($couponQuery))) {
            return false;
        }
    }
    return true;
}));
$productEditId = clean_string($_GET['product_edit'] ?? '', 80);
$productCreate = ($_GET['product_form'] ?? '') === 'create';
$editingProduct = $productEditId !== '' ? ($productMap[$productEditId] ?? null) : null;
$newsEditId = clean_string($_GET['news_edit'] ?? '', 80);
$newsCreate = ($_GET['news_form'] ?? '') === 'create';
$newsEditIndex = $newsEditId !== '' ? admin_array_find_index($content['news']['items'], $newsEditId) : null;
$editingNews = $newsEditIndex !== null ? $content['news']['items'][$newsEditIndex] : null;
$couponEditId = clean_string($_GET['coupon_edit'] ?? '', 80);
$couponCreate = ($_GET['coupon_form'] ?? '') === 'create';
$couponEditIndex = $couponEditId !== '' ? admin_array_find_index($content['coupons']['items'], $couponEditId) : null;
$editingCoupon = $couponEditIndex !== null ? $content['coupons']['items'][$couponEditIndex] : null;
$shapeEditIndex = isset($_GET['shape_edit']) ? clean_int($_GET['shape_edit'], -1, 9999) : -1;
$shapeCreate = ($_GET['shape_form'] ?? '') === 'create';
$editingShape = $shapeEditIndex >= 0 && isset($content['diamond_shapes']['items'][$shapeEditIndex]) ? $content['diamond_shapes']['items'][$shapeEditIndex] : null;
$totalRevenue = array_reduce($allOrders, static function (float $carry, array $order): float {
    return $carry + admin_money_number($order['total'] ?? 0);
}, 0.0);

if (isset($_GET['download']) && $_GET['download'] === 'newsletter-subscribers' && admin_is_authenticated()) {
    admin_stream_newsletter_csv($filteredNewsletterSubscribers, $newsletterExportFormat);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Azuronn <?= admin_html(admin_portal_heading()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(BASE_PATH . '/assets/css/admin.css') ?>">
</head>
<body class="admin-body">
<?php if (!$authenticated): ?>
  <main class="admin-login-shell">
    <section class="admin-login-card">
      <div class="admin-login-brand">
        <p class="admin-kicker"><?= admin_html(admin_is_employee_portal() ? 'Employee Workspace' : 'Private Control Room') ?></p>
        <h1>Azuronn <?= admin_html(admin_portal_heading()) ?></h1>
        <p><?= admin_html(admin_is_employee_portal() ? 'Catalog, content, and store changes are submitted as approval requests for the super admin.' : 'Catalog, news, orders, customers, coupons, and storefront content controls.') ?></p>
      </div>
      <?php if ($flash !== null): ?><div class="admin-flash <?= admin_html($flash['type']) ?>"><?= admin_html($flash['message']) ?></div><?php endif; ?>
      <?php foreach ($uploadNotices as $uploadNotice): ?><div class="admin-flash error"><?= admin_html($uploadNotice) ?></div><?php endforeach; ?>
      <form method="post" action="<?= admin_html(admin_entry_url()) ?>" class="admin-login-form">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="login">
        <?php admin_input('username', 'Username', '', 'text', 'autocomplete="username" required'); ?>
        <?php admin_input('password', 'Password', '', 'password', 'autocomplete="current-password" required'); ?>
        <button class="admin-primary" type="submit">Enter <?= admin_html(admin_portal_heading()) ?></button>
      </form>
    </section>
  </main>
<?php else: ?>
  <div class="admin-shell admin-shell-v2">
    <aside class="admin-sidebar admin-sidebar-v2">
      <div class="admin-brand-block">
        <p class="admin-kicker">Azuronn</p>
        <h1><?= admin_html(admin_portal_heading()) ?></h1>
      </div>
      <nav class="admin-side-nav">
        <a href="<?= admin_html(admin_url('dashboard')) ?>" class="<?= $view === 'dashboard' ? 'is-active' : '' ?>"><i class="fas fa-chart-line"></i><span>Dashboard</span></a>
        <a href="<?= admin_html(admin_url('categories')) ?>" class="<?= $view === 'categories' ? 'is-active' : '' ?>"><i class="fas fa-layer-group"></i><span>Categories</span></a>
        <a href="<?= admin_html(admin_url('catalog')) ?>" class="<?= $view === 'catalog' ? 'is-active' : '' ?>"><i class="fas fa-gem"></i><span>Catalog</span></a>
        <a href="<?= admin_html(admin_url('inventory')) ?>" class="<?= $view === 'inventory' ? 'is-active' : '' ?>"><i class="fas fa-boxes"></i><span>Inventory</span></a>
        <a href="<?= admin_html(admin_url('attributes')) ?>" class="<?= $view === 'attributes' ? 'is-active' : '' ?>"><i class="fas fa-sliders"></i><span>Attributes</span></a>
        <a href="<?= admin_html(admin_url('diamonds')) ?>" class="<?= $view === 'diamonds' ? 'is-active' : '' ?>"><i class="fas fa-diamond"></i><span>Diamonds</span></a>
        <a href="<?= admin_html(admin_url('news')) ?>" class="<?= $view === 'news' ? 'is-active' : '' ?>"><i class="fas fa-newspaper"></i><span>News & Media</span></a>
        <a href="<?= admin_html(admin_url('newsletter')) ?>" class="<?= $view === 'newsletter' ? 'is-active' : '' ?>"><i class="fas fa-image"></i><span>Banner / Newsletter</span></a>
        <a href="<?= admin_html(admin_url('orders')) ?>" class="<?= $view === 'orders' ? 'is-active' : '' ?>"><i class="fas fa-bag-shopping"></i><span>Orders</span></a>
        <a href="<?= admin_html(admin_url('appointments')) ?>" class="<?= $view === 'appointments' ? 'is-active' : '' ?>"><i class="fas fa-calendar-check"></i><span>Appointments</span></a>
        <a href="<?= admin_html(admin_url('customers')) ?>" class="<?= $view === 'customers' ? 'is-active' : '' ?>"><i class="fas fa-users"></i><span>Registered Users</span></a>
        <?php // Homepage Content nav item intentionally hidden for now. Keep the content workflow code below for future reuse. ?>
        <a href="<?= admin_html(admin_url('site')) ?>" class="<?= $view === 'site' ? 'is-active' : '' ?>"><i class="fas fa-cogs"></i><span>Site Settings</span></a>
        <a href="<?= admin_html(admin_url('coupons')) ?>" class="<?= $view === 'coupons' ? 'is-active' : '' ?>"><i class="fas fa-ticket"></i><span>Coupons</span></a>
        <a href="<?= admin_html(admin_url('requests')) ?>" class="<?= $view === 'requests' ? 'is-active' : '' ?>"><i class="fas fa-shield-halved"></i><span><?= admin_html(admin_is_super_portal() ? 'Admin Requests' : 'My Requests') ?></span></a>
        <?php if (admin_is_super_portal()): ?><a href="<?= admin_html(admin_url('employees')) ?>" class="<?= $view === 'employees' ? 'is-active' : '' ?>"><i class="fas fa-user-shield"></i><span>Employee Admins</span></a><?php endif; ?>
      </nav>
    </aside>

    <main class="admin-main admin-main-v2">
      <header class="admin-topbar admin-topbar-v2">
        <div>
          <p class="admin-kicker"><?= admin_html($viewMeta[$view]['eyebrow'] ?? 'Admin') ?></p>
          <h2><?= admin_html($viewMeta[$view]['title'] ?? admin_portal_heading()) ?></h2>
          <p class="admin-topbar-summary"><?= admin_html($viewMeta[$view]['summary'] ?? '') ?></p>
        </div>
        <div class="admin-top-actions">
          <a href="../index.php" class="admin-ghost">View Site</a>
          <?php if (admin_is_super_portal()): ?><a href="<?= admin_html(admin_entry_url(['download' => 'content'])) ?>" class="admin-ghost">Backup</a><?php endif; ?>
          <form method="post" action="<?= admin_html(admin_url($view)) ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="logout">
            <button class="admin-ghost danger" type="submit">Log Out</button>
          </form>
        </div>
      </header>

      <?php if ($flash !== null): ?><div class="admin-flash <?= admin_html($flash['type']) ?>"><?= admin_html($flash['message']) ?></div><?php endif; ?>
      <?php foreach ($uploadNotices as $uploadNotice): ?><div class="admin-flash error"><?= admin_html($uploadNotice) ?></div><?php endforeach; ?>

      <?php if ($view === 'dashboard'): ?>
        <section class="admin-page-hero admin-page-hero-dashboard">
          <div>
            <p class="admin-kicker">Overview</p>
            <h2><?= admin_html(admin_is_employee_portal() ? 'Employee request workspace' : 'Store operations at a glance') ?></h2>
            <p><?= admin_html(admin_is_employee_portal() ? 'Use this workspace to prepare catalog, content, and support changes. Every save, add, update, or delete becomes a request for super admin approval.' : 'Use this workspace to move between merchandising, publishing, customer support, and brand updates without hunting through long forms.') ?></p>
          </div>
          <div class="admin-mini-stats">
            <article><span>Products</span><strong><?= count($products) ?></strong></article>
            <article><span>Revenue</span><strong>£<?= number_format($totalRevenue, 2) ?></strong></article>
            <article><span>Orders</span><strong><?= count($allOrders) ?></strong></article>
            <article><span>Upcoming Appts</span><strong><?= count($apUpcoming ?? []) ?></strong></article>
            <article><span><?= admin_html(admin_is_employee_portal() ? 'My Pending Requests' : 'Open Requests') ?></span><strong><?= admin_html((string) (admin_is_employee_portal() ? count($pendingAdminRequests) : count(array_filter($allOrders, static fn (array $item): bool => in_array(strtolower((string) ($item['customer_request_status'] ?? '')), ['pending', 'approved'], true))))) ?></strong></article>
          </div>
        </section>

        <section class="admin-workflow-grid">
          <article class="admin-module-card admin-module-card-feature">
            <p class="admin-kicker">Quick Actions</p>
            <h3><?= admin_html(admin_is_employee_portal() ? 'Send daily change requests' : 'Run the daily admin workflow') ?></h3>
            <p><?= admin_html(admin_is_employee_portal() ? 'Open any workflow below. Your changes will be packaged as requests and sent to the super admin.' : 'Jump straight into the tasks that change the storefront fastest.') ?></p>
            <div class="admin-quick-links">
              <a class="admin-ghost" href="<?= admin_html(admin_url('catalog', ['product_form' => 'create'])) ?>">Add Product</a>
              <a class="admin-ghost" href="<?= admin_html(admin_url('news', ['news_form' => 'create'])) ?>">Create Post</a>
              <a class="admin-ghost" href="<?= admin_html(admin_url('coupons', ['coupon_form' => 'create'])) ?>">Create Coupon</a>
              <?php if (admin_is_employee_portal()): ?><a class="admin-ghost" href="<?= admin_html(admin_url('requests')) ?>">View My Requests</a><?php endif; ?>
              <?php // Homepage Content quick action intentionally disabled for now. ?>
            </div>
          </article>
          <?php if (admin_is_super_portal()): ?>
          <article class="admin-module-card">
            <h3>Admin Requests</h3>
            <p><?= count($pendingAdminRequests) ?> employee request<?= count($pendingAdminRequests) === 1 ? '' : 's' ?> are waiting for approval.</p>
            <a class="admin-text-link" href="<?= admin_html(admin_url('requests')) ?>">Review Requests</a>
          </article>
          <?php endif; ?>
          <article class="admin-module-card">
            <h3>Catalog</h3>
            <p><?= admin_metric_total($products, 'status', 'active') ?> active products, <?= admin_metric_total($products, 'status', 'hidden') ?> hidden, and <?= count(array_filter($products, static fn (array $item): bool => str_starts_with(strtolower((string) ($item['product_type'] ?? '')), 'ring'))) ?> ring items ready for merchandising.</p>
            <a class="admin-text-link" href="<?= admin_html(admin_url('catalog')) ?>">Open Catalog</a>
          </article>
          <article class="admin-module-card">
            <h3>Orders</h3>
            <p><?= admin_metric_total($allOrders, 'status', 'pending') ?> pending orders and <?= count(array_filter($allOrders, static fn (array $item): bool => in_array(strtolower((string) ($item['customer_request_status'] ?? '')), ['pending', 'approved'], true))) ?> customer requests need attention.</p>
            <a class="admin-text-link" href="<?= admin_html(admin_url('orders')) ?>">Open Orders</a>
          </article>
          <?php // Homepage Content dashboard card intentionally hidden for now. ?>
        </section>

        <section class="admin-dashboard-grid">
          <section class="admin-panel">
            <div class="admin-panel-head"><div><p class="admin-kicker">Recent Orders</p><h3>Latest fulfilment activity</h3></div><a class="admin-text-link" href="<?= admin_html(admin_url('orders')) ?>">See all</a></div>
            <div class="admin-list-stack">
              <?php foreach (array_slice($allOrders, 0, 5) as $order): ?>
                <article class="admin-list-card">
                  <div>
                    <strong><?= admin_html((string) ($order['id'] ?? '')) ?></strong>
                    <small><?= admin_html((string) ($order['customer_name'] ?? '')) ?> • <?= admin_html((string) ($order['placed_at'] ?? '')) ?></small>
                  </div>
                  <div class="admin-list-meta">
                    <span class="status-pill"><?= admin_html(order_status_label((string) ($order['status'] ?? ''))) ?></span>
                    <strong><?= admin_html((string) ($order['total'] ?? '£0.00')) ?></strong>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
          <section class="admin-panel">
            <div class="admin-panel-head"><div><p class="admin-kicker">Recent Customers</p><h3>Newest account activity</h3></div><a class="admin-text-link" href="<?= admin_html(admin_url('customers')) ?>">See all</a></div>
            <div class="admin-list-stack">
              <?php foreach (array_slice($allCustomers, 0, 5) as $customer): ?>
                <article class="admin-list-card">
                  <div>
                    <strong><?= admin_html((string) ($customer['name'] ?? '')) ?></strong>
                    <small><?= admin_html((string) ($customer['email'] ?? '')) ?> • <?= admin_html((string) ($customer['city'] ?? '')) ?></small>
                  </div>
                  <div class="admin-list-meta">
                    <span class="status-pill"><?= admin_html((string) ($customer['status'] ?? 'active')) ?></span>
                    <strong><?= admin_html((string) ($customer['total_spent'] ?? '£0.00')) ?></strong>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        </section>
      <?php elseif ($view === 'requests'): ?>
        <section class="admin-page-hero">
          <div>
            <p class="admin-kicker">Approval Queue</p>
            <h2><?= admin_html(admin_is_super_portal() ? 'Admin requests' : 'My requests') ?></h2>
            <p><?= admin_html(admin_is_super_portal() ? 'Review requests submitted from the employee admin panel. Approving a request applies it to the live super admin data immediately.' : 'Review every request you have sent from employee admin. Track whether it is pending, approved, or rejected.') ?></p>
          </div>
          <div class="admin-top-actions admin-page-hero-actions">
            <?php if (admin_is_super_portal()): ?><a class="admin-primary" href="<?= admin_html(admin_url('employees')) ?>">Create Employee Admin</a><?php endif; ?>
          </div>
          <div class="admin-mini-stats">
            <article><span>Pending</span><strong><?= count($pendingAdminRequests) ?></strong></article>
            <article><span>Approved</span><strong><?= count(array_filter($adminRequests, static fn (array $request): bool => (string) ($request['status'] ?? '') === 'approved')) ?></strong></article>
            <article><span>Rejected</span><strong><?= count(array_filter($adminRequests, static fn (array $request): bool => (string) ($request['status'] ?? '') === 'rejected')) ?></strong></article>
          </div>
        </section>
        <section class="admin-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">Pending First</p><h3><?= admin_html(admin_is_super_portal() ? 'Employee change requests' : 'Your request history') ?></h3></div></div>
          <div class="admin-list-stack">
            <?php foreach ($adminRequests as $request): ?>
              <article class="admin-list-card">
                <div>
                  <strong><a class="admin-text-link" href="<?= admin_html(admin_url('requests', ['request_id' => (string) ($request['id'] ?? '')])) ?>"><?= admin_html((string) ($request['summary'] ?? 'Admin request')) ?></a></strong>
                  <small><?= admin_html(admin_request_actor_label($request)) ?> • <?= admin_html((string) ($request['created_at'] ?? '')) ?></small>
                  <?php if (!empty($request['details']) && is_array($request['details'])): ?><small><?= admin_html((string) ($request['details'][0] ?? '')) ?></small><?php endif; ?>
                  <?php if ((string) ($request['note'] ?? '') !== ''): ?><small><?= admin_html((string) ($request['note'] ?? '')) ?></small><?php endif; ?>
                </div>
                <div class="admin-list-meta">
                  <span class="status-pill"><?= admin_html(ucfirst((string) ($request['status'] ?? 'pending'))) ?></span>
                  <?php if (admin_is_super_portal() && (string) ($request['status'] ?? '') === 'pending'): ?>
                    <div class="admin-action-row">
                      <?php admin_table_button('Approve', 'approve-admin-request', ['request_id' => $request['id']]); ?>
                      <?php admin_table_button('Reject', 'reject-admin-request', ['request_id' => $request['id']], 'admin-mini-btn warn'); ?>
                    </div>
                  <?php else: ?>
                    <small><?= admin_html((string) ($request['resolved_by'] ?? '')) ?><?= (string) ($request['resolved_at'] ?? '') !== '' ? ' • ' . admin_html((string) ($request['resolved_at'] ?? '')) : '' ?></small>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
            <?php if ($adminRequests === []): ?><p class="admin-table-note"><?= admin_html(admin_is_super_portal() ? 'No admin requests have been submitted yet.' : 'You have not submitted any requests yet.') ?></p><?php endif; ?>
          </div>
        </section>
        <?php if ($selectedAdminRequest !== null): ?>
        <section class="admin-panel admin-editor-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">Request Detail</p><h3><?= admin_html((string) ($selectedAdminRequest['summary'] ?? 'Admin request')) ?></h3></div></div>
          <div class="admin-product-editor admin-request-detail-editor">
            <div class="admin-product-main">
              <section class="admin-product-card">
                <div class="admin-product-card-head"><div><p class="admin-kicker">Overview</p><h4>What this request does</h4></div></div>
                <div class="admin-product-side-stats">
                  <article><span>Requested By</span><strong><?= admin_html(admin_request_actor_label($selectedAdminRequest)) ?></strong></article>
                  <article><span>Created</span><strong><?= admin_html((string) ($selectedAdminRequest['created_at'] ?? '')) ?></strong></article>
                  <article><span>Status</span><strong><?= admin_html(ucfirst((string) ($selectedAdminRequest['status'] ?? 'pending'))) ?></strong></article>
                  <article><span>Action</span><strong><?= admin_html((string) ($selectedAdminRequest['action'] ?? '')) ?></strong></article>
                </div>
                <?php if (!empty($selectedAdminRequest['details']) && is_array($selectedAdminRequest['details'])): ?>
                  <div class="admin-request-stack">
                    <?php foreach ($selectedAdminRequest['details'] as $detailLine): ?>
                      <small><?= admin_html((string) $detailLine) ?></small>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <div class="admin-request-decision-bar">
                  <div>
                    <p class="admin-kicker">Actions</p>
                    <h4><?= admin_html(admin_is_super_portal() ? 'Review decision' : 'Request status') ?></h4>
                  </div>
                  <?php if (admin_is_super_portal() && (string) ($selectedAdminRequest['status'] ?? '') === 'pending'): ?>
                    <div class="admin-action-row admin-action-wrap">
                      <?php admin_table_button('Approve Request', 'approve-admin-request', ['request_id' => $selectedAdminRequest['id']]); ?>
                      <?php admin_table_button('Reject Request', 'reject-admin-request', ['request_id' => $selectedAdminRequest['id']], 'admin-mini-btn warn'); ?>
                    </div>
                  <?php else: ?>
                    <p class="admin-table-note">
                      <?= admin_html((string) ($selectedAdminRequest['status'] ?? 'pending') === 'pending' ? 'This request is still waiting for super admin review.' : 'Resolved by ' . (string) ($selectedAdminRequest['resolved_by'] ?? '') . ((string) ($selectedAdminRequest['resolved_at'] ?? '') !== '' ? ' on ' . (string) ($selectedAdminRequest['resolved_at'] ?? '') : '.')) ?>
                    </p>
                  <?php endif; ?>
                </div>
              </section>

              <?php $requestPayload = is_array($selectedAdminRequest['payload'] ?? null) ? $selectedAdminRequest['payload'] : []; ?>
              <?php $detailBefore = admin_request_detail_value($requestPayload, 'before'); ?>
              <?php $detailAfter = admin_request_detail_value($requestPayload, 'after'); ?>
              <?php $hasBefore = $detailBefore !== null && $detailBefore !== []; ?>
              <?php $hasAfter = $detailAfter !== null && $detailAfter !== []; ?>
              <?php if ($hasBefore && $hasAfter): ?>
              <section class="admin-product-card">
                <div class="admin-product-card-head"><div><p class="admin-kicker">Comparison</p><h4>Before and after request detail</h4></div></div>
                <?php admin_render_request_comparison($detailBefore, $detailAfter); ?>
              </section>
              <?php elseif ($hasBefore || $hasAfter): ?>
              <section class="admin-product-card">
                <div class="admin-product-card-head"><div><p class="admin-kicker"><?= $hasBefore ? 'Current Data' : 'Requested Data' ?></p><h4><?= $hasBefore ? 'Existing live item' : 'Submitted change' ?></h4></div></div>
                <?php admin_render_request_value($hasBefore ? $detailBefore : $detailAfter); ?>
              </section>
              <?php endif; ?>
            </div>
          </div>
        </section>
        <?php endif; ?>
      <?php elseif ($view === 'employees' && admin_is_super_portal()): ?>
        <section class="admin-page-hero">
          <div>
            <p class="admin-kicker">Employee Access</p>
            <h2>Employee admin accounts</h2>
            <p>Create employee login IDs and passwords for the employee admin portal. Requests sent from employee admin will show exactly which employee account submitted them.</p>
          </div>
          <div class="admin-mini-stats">
            <article><span>Total Accounts</span><strong><?= count($employeeAdminAccounts) ?></strong></article>
            <article><span>Active</span><strong><?= count(array_filter($employeeAdminAccounts, static fn (array $item): bool => strtolower((string) ($item['status'] ?? 'active')) === 'active')) ?></strong></article>
          </div>
        </section>

        <section class="admin-panel admin-editor-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">Employee Admins</p><h3>Manage login IDs and passwords</h3></div></div>
          <div class="admin-product-editor">
            <div class="admin-product-main">
              <section class="admin-product-card">
                <div class="admin-product-card-head"><div><p class="admin-kicker">All Accounts</p><h4>Employee admin directory</h4></div></div>
                <div class="admin-list-stack">
                  <?php foreach ($employeeAdminAccounts as $employeeAdmin): ?>
                    <?php $isDefaultEmployeeAdmin = (string) ($employeeAdmin['id'] ?? '') === 'emp-admin-default'; ?>
                    <article class="admin-list-card">
                      <div>
                        <strong><a class="admin-text-link" href="<?= admin_html(admin_url('employees', ['employee_id' => (string) ($employeeAdmin['id'] ?? '')])) ?>"><?= admin_html((string) ($employeeAdmin['name'] ?? 'Employee Admin')) ?></a></strong>
                        <small><?= admin_html((string) ($employeeAdmin['username'] ?? '')) ?></small>
                        <small><?= admin_html($isDefaultEmployeeAdmin ? 'Fallback employee login from config' : ('Created ' . (string) ($employeeAdmin['created_at'] ?? ''))) ?></small>
                      </div>
                      <div class="admin-list-meta">
                        <span class="status-pill"><?= admin_html(ucfirst((string) ($employeeAdmin['status'] ?? 'active'))) ?></span>
                        <?php if (!$isDefaultEmployeeAdmin): ?><small><?= admin_html((string) ($employeeAdmin['updated_at'] ?? '')) ?></small><?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              </section>
            </div>

            <aside class="admin-product-sidebar">
              <div class="admin-product-side-card">
                <p class="admin-kicker"><?= $selectedEmployeeAdmin !== null ? 'Edit Account' : 'Create Account' ?></p>
                <h4><?= $selectedEmployeeAdmin !== null ? 'Update employee admin' : 'New employee admin' ?></h4>
                <?php $selectedIsFallback = $selectedEmployeeAdmin !== null && (string) ($selectedEmployeeAdmin['id'] ?? '') === 'emp-admin-default'; ?>
                <?php if ($selectedIsFallback): ?>
                  <p class="admin-table-note">This fallback employee login comes from server config. Create a new employee admin below to use managed accounts instead.</p>
                <?php endif; ?>
                <?php admin_form_open('employees', 'save-employee-admin'); ?>
                <?php if ($selectedEmployeeAdmin !== null && !$selectedIsFallback): ?>
                  <input type="hidden" name="employee_id" value="<?= admin_html((string) ($selectedEmployeeAdmin['id'] ?? '')) ?>">
                <?php endif; ?>
                <div class="admin-grid two-up">
                  <?php admin_input('employee[name]', 'Employee Name', $selectedEmployeeAdmin['name'] ?? ''); ?>
                  <?php admin_input('employee[username]', 'Login ID', $selectedEmployeeAdmin['username'] ?? ''); ?>
                  <?php admin_input('employee[password]', $selectedEmployeeAdmin !== null && !$selectedIsFallback ? 'New Password' : 'Password', '', 'password', '', $selectedEmployeeAdmin !== null && !$selectedIsFallback ? 'Leave blank to keep the current password.' : 'Set the employee login password.'); ?>
                  <?php admin_select('employee[status]', 'Status', $selectedEmployeeAdmin['status'] ?? 'active', ['active' => 'Active', 'inactive' => 'Inactive']); ?>
                </div>
                <div class="admin-actions">
                  <button class="admin-primary" type="submit"><?= $selectedEmployeeAdmin !== null && !$selectedIsFallback ? 'Update Employee Admin' : 'Create Employee Admin' ?></button>
                  <?php if ($selectedEmployeeAdmin !== null): ?><a class="admin-mini-btn" href="<?= admin_html(admin_url('employees')) ?>">New Account</a><?php endif; ?>
                </div>
                <?php admin_form_close(); ?>

                <?php if ($selectedEmployeeAdmin !== null && !$selectedIsFallback): ?>
                  <div class="admin-actions" style="margin-top:12px;">
                    <?php admin_table_button('Delete Employee Admin', 'delete-employee-admin', ['employee_id' => (string) ($selectedEmployeeAdmin['id'] ?? ''), 'return_view' => 'employees'], 'admin-mini-btn danger'); ?>
                  </div>
                <?php endif; ?>
                <p class="admin-table-note">Employee admin login page: `/employee-admin/`</p>
              </div>
            </aside>
          </div>
        </section>
      <?php elseif ($view === 'categories'): ?>
        <section class="admin-page-hero"><div><p class="admin-kicker">Taxonomies</p><h2>Categories</h2><p>Manage your product categories. Categories added here will appear in the shop by category section and be available for product assignment.</p></div></section>
        
        <section class="admin-panel" id="catalog-taxonomies">
          <?php admin_form_open('categories', 'save-categories', true); ?>
          
          <div class="admin-repeater" data-repeater data-index-token="__CARD_INDEX__" data-next-index="<?= count($content['category_cards']) ?>" style="margin-bottom: 24px;">
            <div class="admin-repeater-list">
              <?php foreach ($content['category_cards'] as $index => $card): ?>
                <?php $cardIsProtected = catalog_category_is_protected((string) ($card['title'] ?? '')); ?>
                <div class="admin-repeater-item compact-item">
                  <div class="admin-item-head">
                    <h4>Category<?= $cardIsProtected ? ' <span class="status-pill">Required</span>' : '' ?></h4>
                    <?php if ($cardIsProtected): ?>
                      <button class="admin-remove" type="button" disabled title="This category powers the main navigation and cannot be deleted.">Delete</button>
                    <?php else: ?>
                      <button class="admin-remove" type="button" data-remove-item>Delete</button>
                    <?php endif; ?>
                  </div>
                  <?php if ($cardIsProtected): ?>
                    <p class="admin-table-note">Required category — the main navigation links to it. The name is fixed, but you can still change its image, icon and text.</p>
                  <?php endif; ?>
                  <div class="admin-grid three-up">
                    <?php admin_input('category_cards[' . $index . '][title]', 'Category Name (Title)', $card['title'], 'text', $cardIsProtected ? 'readonly' : ''); ?>
                    <?php admin_input('category_cards[' . $index . '][header_icon]', 'Icon Classes', $card['header_icon']); ?>
                    <?php admin_input('category_cards[' . $index . '][sub]', 'Other Text', $card['sub']); ?>
                    <?php admin_input('category_cards[' . $index . '][image]', 'Image URL', $card['image'], 'text', '', 'Paste URL or upload below'); ?>
                    <?php admin_input('category_image_file_' . $index, 'Upload Image', '', 'file', 'accept="image/*"'); ?>
                    <?php admin_input('category_cards[' . $index . '][alt]', 'Image Alt', $card['alt']); ?>
                    <?php admin_input('category_cards[' . $index . '][hero_image]', 'Hero Image URL', $card['hero_image'], 'text', '', 'Used on the category page hero'); ?>
                    <?php admin_input('category_hero_image_file_' . $index, 'Upload Hero Image', '', 'file', 'accept="image/*"'); ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button class="admin-add" type="button" data-add-item data-template="tpl-category-card">Add Category</button>
          </div>

          <div class="admin-actions"><button class="admin-primary" type="submit">Save Categories</button></div>
          <?php admin_form_close(); ?>
        </section>

      <?php elseif ($view === 'catalog'): ?>
        <?php $catalogEditorOpen = $productCreate || $editingProduct !== null; ?>
        <?php if (!$catalogEditorOpen): ?>
          <section class="admin-page-hero">
            <div><p class="admin-kicker">Catalog</p><h2>Product library</h2><p>Manage the ring and jewellery catalog in a single flow: create items, refine taxonomies, clean the library, then assign products to storefront surfaces.</p></div>
            <div class="admin-mini-stats">
              <article><span>Total Products</span><strong><?= count($products) ?></strong></article>
              <article><span>Active</span><strong><?= admin_metric_total($products, 'status', 'active') ?></strong></article>
              <article><span>Hidden</span><strong><?= admin_metric_total($products, 'status', 'hidden') ?></strong></article>
              <article><span>Ring Products</span><strong><?= count(array_filter($products, static fn (array $item): bool => str_starts_with(strtolower((string) ($item['product_type'] ?? '')), 'ring'))) ?></strong></article>
            </div>
          </section>
          <section class="admin-anchor-nav">
            <a href="#catalog-taxonomies">Taxonomies</a>
            <a href="#catalog-library">Library</a>
            <a href="#catalog-assignments">Assignments</a>
          </section>
          <section class="admin-page-hero admin-page-hero-actions">
            <div><p class="admin-kicker">Catalog Actions</p><h2>Build and organise products</h2></div>
            <div class="admin-top-actions"><a class="admin-primary" href="<?= admin_html(admin_url('catalog', ['product_form' => 'create'])) ?>">Upload Product</a><a class="admin-ghost" href="<?= admin_html(admin_url('attributes')) ?>">Open Attributes</a></div>
          </section>
        <?php endif; ?>

        <?php if ($productCreate || $editingProduct !== null): ?>
          <?php
          // The Metal Matrix + attribute profile are keyed by the CANONICAL type
          // (Engagement Rings / Wedding Rings / Earring, …). A stored ring product
          // keeps product_type='Ring' and carries its section in ring_category, so
          // resolve through the product itself — otherwise a wedding band opens the
          // Engagement Rings metals. The Category dropdown maps to the same values,
          // so the editor context, the visible matrix block, and the
          // metal_variations_<type> POST key all agree.
          //
          // A NEW product deliberately starts with NO editor category. Falling back
          // to the first/ring type here is what made every category show Engagement
          // Rings' metals: the Category dropdown rendered blank while the server had
          // already picked a type and marked its matrix block visible.
          if ($editingProduct !== null) {
              $catalogEditorType = product_attribute_profile_type($editingProduct);
          } else {
              $catalogEditorType = admin_canonical_attribute_type(
                  clean_string((string) ($_GET['type'] ?? ''), 80)
              );
          }
          $catalogEditorHasType = $catalogEditorType !== '';
          $catalogEditorProfile = catalog_attribute_profile($catalogEditorType, $content);
          $catalogEditorSource = is_array($editingProduct) ? $editingProduct : [];
          $catalogEditorColorLabel = admin_editor_scalar($catalogEditorSource, $catalogEditorProfile, 'option_color_label');
          $catalogEditorSizeLabel = admin_editor_scalar($catalogEditorSource, $catalogEditorProfile, 'option_size_label');
          $catalogEditorSizeChoices = admin_editor_list($catalogEditorSource, $catalogEditorProfile, 'option_size_choices');
          $catalogEditorMetalVariations = admin_editor_list($catalogEditorSource, [], 'metal_variations');
          ?>
          <section class="admin-panel admin-editor-panel" id="catalog-editor">
            <div class="admin-panel-head"><div><p class="admin-kicker">Product Form</p><h3><?= $editingProduct ? 'Edit product' : 'Upload product' ?></h3></div></div>
            <?php admin_form_open('catalog', $editingProduct ? 'update-product' : 'create-product', true); ?>
            <?php if ($editingProduct): ?><input type="hidden" name="product_id" value="<?= admin_html($editingProduct['id']) ?>"><?php endif; ?>
            <div class="admin-product-editor<?= $catalogEditorHasType ? '' : ' has-no-category' ?>" data-catalog-profile-editor data-category-state-root>
              <div class="admin-product-main">
                <section class="admin-product-card">
                  <div class="admin-product-card-head">
                    <div>
                      <p class="admin-kicker">Core Details</p>
                      <h4>Product identity</h4>
                    </div>
                    <span class="status-pill"><?= $editingProduct ? 'Editing Existing' : 'New Product' ?></span>
                  </div>
                  <?php
                  $categoryTaxonomyOptions = ['' => '— Select a category —'];
                  foreach (product_category_taxonomy_options() as $taxonomyKey => $taxonomyOption) {
                      $categoryTaxonomyOptions[$taxonomyKey] = $taxonomyOption['label'];
                  }
                  $categoryTaxonomySelected = $editingProduct !== null ? product_category_taxonomy_key($editingProduct) : '';
                  // The Category dropdown is the single visible control; it maps to the
                  // canonical product type so the Metal Matrix + attribute profile follow
                  // it. Emitted as a JSON map for the admin JS that mirrors the chosen
                  // Category into the hidden product_type field (the matrix keys off it).
                  $categoryTypeMap = [];
                  foreach (product_category_taxonomy_options() as $mapKey => $mapOption) {
                      // Ring entries resolve to their section's own profile type
                      // (Engagement Rings / Wedding Rings) so the metal matrix and
                      // attribute options follow the chosen section, not a shared one.
                      $mapSection = (string) ($mapOption['ring_category'] ?? '');
                      $categoryTypeMap[$mapKey] = $mapSection !== ''
                          ? ring_section_profile_type($mapSection)
                          : admin_canonical_attribute_type((string) ($mapOption['product_type'] ?? ''));
                  }
                  // Category key -> ring section (engagement / wedding / '') so the
                  // Style picker below can show the matching attribute styles as the
                  // chosen category changes (mirrors how the Metal Matrix swaps per
                  // category). Non-ring categories map to '' and the picker hides.
                  $categoryStyleMap = [];
                  foreach (product_category_taxonomy_options() as $mapKey => $mapOption) {
                      $categoryStyleMap[$mapKey] = (string) ($mapOption['ring_category'] ?? '');
                  }
                  // Non-ring categories that get a flat style grid below. Emitted to
                  // the Category select so admin.js picks the right grid from the
                  // merchant's real categories instead of a hardcoded list.
                  $flatStyleTypes = array_values(array_filter(
                      array_map('admin_canonical_attribute_type', $attributeTypes),
                      static fn (string $t): bool => $t !== '' && !admin_product_type_is_ring($t)
                  ));
                  // data-product-scope values for "simple" (non-metal-matrix) cards, so a
                  // merchant-created category always sees price/media/tags even though the
                  // same form is reused for matrix types. Both lists come from the real
                  // categories, so switching Category re-scopes the form without a reload.
                  $nonMatrixScopeValues = [];
                  foreach (product_category_taxonomy_options() as $taxKey => $taxOption) {
                      $ptCanon = (string) ($categoryTypeMap[$taxKey] ?? '');
                      if ($ptCanon === '' || admin_product_type_is_matrix($ptCanon)) {
                          continue;
                      }
                      $nonMatrixScopeValues[] = strtolower($ptCanon);
                  }
                  $nonMatrixScope = implode(', ', array_unique(array_filter($nonMatrixScopeValues)));
                  // Cards that only apply to non-matrix categories. '__none__' matches
                  // no canonical type, so they stay hidden when every category is a
                  // matrix category rather than falling back to "always visible".
                  $matrixPseudoScope = $nonMatrixScope !== '' ? $nonMatrixScope : '__none__';
                  ?>
                  <div class="admin-grid two-up">
                    <?php admin_input('product[name]', 'Product Name', $editingProduct['name'] ?? '', 'text', 'required', 'Use the exact storefront title.'); ?>
                    <?php admin_select('product[category_taxonomy]', 'Category', $categoryTaxonomySelected, $categoryTaxonomyOptions, 'required data-category-type-map="' . htmlspecialchars((string) json_encode($categoryTypeMap, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '" data-category-style-map="' . htmlspecialchars((string) json_encode($categoryStyleMap, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '" data-category-flat-types="' . htmlspecialchars((string) json_encode($flatStyleTypes, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"', 'Where this product appears on the storefront. Choosing a category sets this product\'s type and shows the matching metal / size / band options from that category\'s attributes below.'); ?>
                    <?php admin_input('product[category]', 'Collection Label (optional)', $editingProduct['category'] ?? '', 'text', '', 'Optional merchandising tag, e.g. Bridal, Everyday, Statement.'); ?>
                    <?php
                      // Gender only applies to wedding rings. Rendered always and
                      // revealed by admin.js when the chosen Category is the wedding
                      // section, so switching Category doesn't need a page reload.
                      $editingRingSection = $editingProduct !== null ? product_ring_taxonomy($editingProduct)['category'] : '';
                    ?>
                    <div data-ring-section-scope="wedding"<?= $editingRingSection === 'wedding' ? '' : ' hidden style="display:none;"' ?>>
                      <?php admin_select('product[ring_gender]', 'Gender', $editingProduct['ring_gender'] ?? '', ['' => '— Any —', 'womens' => "Women's", 'mens' => "Men's"], '', 'Which wedding ring collection this band belongs to.'); ?>
                    </div>
                    <?php // Base Color applies to non-matrix categories only. Rendered
                          // always and scoped client-side so switching Category reveals it
                          // without a reload (server-side hiding froze it to the initial type). ?>
                    <div data-product-scope="<?= admin_html($nonMatrixScope !== '' ? $nonMatrixScope : '__none__') ?>"<?= (!$catalogEditorHasType || admin_product_type_is_matrix($catalogEditorType)) ? ' hidden style="display:none;"' : '' ?>>
                      <?php admin_select('product[color]', 'Base Color', $editingProduct['color'] ?? '', admin_options_from_list((array) ($content['catalog_meta']['colors'] ?? [])), '', 'Default colour before customer selection.'); ?>
                    </div>
                    <?php admin_select('product[status]', 'Visibility', $editingProduct['status'] ?? 'active', ['active' => 'Active', 'hidden' => 'Hidden'], '', 'Hidden products are in admin only.'); ?>
                    <?php
                      // The internal product type is no longer a manual "Advanced" field.
                      // The visible Category dropdown above is the single source of truth;
                      // admin.js mirrors the chosen category's canonical type into this
                      // hidden <select> on change, and the Metal Matrix + attribute profile
                      // switch to match. It stays a <select> (not an<input>) so the
                      // existing matrix-scope sync (which queries select[name=product_type])
                      // keeps working. Canonical values (Ring, not Rings) make the matrix
                      // POST key and the save handler key agree.
                      // Blank for a new product until a Category is chosen — the JS
                      // reads this select as its fallback, so pre-selecting a type
                      // here is what pinned every category to Engagement Rings.
                      $initialProductType = $catalogEditorHasType ? $catalogEditorType : '';
                      // Options = every canonical type the Category dropdown can map to
                      // (so a category with no products yet still switches the matrix),
                      // unioned with any canonical types already present as products.
                      $productTypeOptions = array_values(array_unique(array_merge(
                          array_map('admin_canonical_attribute_type', $productTypes),
                          array_values($categoryTypeMap)
                      )));
                    ?>
                    <select name="product[product_type]" data-driven-by-category hidden aria-hidden="true" tabindex="-1">
                      <?php // Empty first option so a new product really has no type until a
                            // Category is chosen — without it the browser auto-selects the
                            // first real option and the old leak comes straight back. ?>
                      <option value="" <?= $initialProductType === '' ? 'selected' : '' ?>></option>
                      <?php foreach ($productTypeOptions as $ptOption): ?>
                        <option value="<?= admin_html($ptOption) ?>" <?= $ptOption === $initialProductType ? 'selected' : '' ?>><?= admin_html($ptOption) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </section>

                <?php // Price, imagery and copy are entered per metal in the Metal Matrix
                      // below; admin_listing_face_from_metals() mirrors the cheapest active
                      // metal onto the product as its "from" listing face on save. ?>
                <section class="admin-product-card" data-product-scope="<?= admin_html($matrixPseudoScope) ?>">
                  <div class="admin-product-card-head">
                    <div>
                      <p class="admin-kicker">Merchandising Tags</p>
                      <h4>Subcategories and highlights</h4>
                    </div>
                  </div>
                  <div class="admin-grid two-up">
                    <?php admin_textarea('product[subcategories_text]', 'Subcategories / Tags', admin_export_lines($editingProduct['subcategories'] ?? []), 6, 'One tag per line. Example: studs, hoops, tennis, bezel, solitaire, halo, vintage.'); ?>
                    <?php admin_textarea('product[features_text]', 'Highlights / Features', admin_export_lines($editingProduct['features'] ?? []), 6, 'One line per storefront highlight. Leave blank to use automatic defaults.'); ?>
                  </div>
                </section>


                <?php // Matrix categories carry their sizes per metal inside the Metal
                      // Matrix, so this card only applies to the flat categories. One
                      // block per flat category, toggled by data-matrix-profile-type:
                      // switching Category swaps in that category's own size list
                      // instead of leaving the first category's sizes on screen.
                      // admin.js disables the inputs in every hidden block, so only
                      // the active category's choices are ever submitted. ?>
                <section class="admin-product-card" data-product-scope="<?= admin_html($matrixPseudoScope) ?>">
                  <div class="admin-product-card-head">
                    <div>
                      <p class="admin-kicker">Customer Options</p>
                      <h4>Select available choices for this product</h4>
                    </div>
                  </div>
                  <?php // Visibility driven by .has-no-category on the editor root. ?>
                  <p class="admin-empty-note" data-needs-category>Choose a <strong>Category</strong> above to load its colour and size choices.</p>
                  <?php foreach ($catalogAttributeProfilesForJs as $optType => $optProfile):
                      if (admin_product_type_is_matrix((string) $optType)) { continue; }
                      $optIsCurrent = strtolower((string) $optType) === strtolower($catalogEditorType);
                      $optSizeChoices = array_values((array) ($optProfile['option_size_choices'] ?? []));
                      $optColorLabel = $optIsCurrent ? $catalogEditorColorLabel : (string) ($optProfile['option_color_label'] ?? '');
                      $optSizeLabel = $optIsCurrent ? $catalogEditorSizeLabel : (string) ($optProfile['option_size_label'] ?? '');
                      $optAttrUrl = admin_url('attributes', ['type' => $optType]);
                  ?>
                  <div data-matrix-profile-type="<?= admin_html(strtolower((string) $optType)) ?>" style="display: <?= $optIsCurrent ? 'block' : 'none' ?>;">
                    <p class="admin-table-note">Tick the options from the <strong><?= admin_html((string) $optType) ?></strong> attribute profile that apply to this product. <a href="<?= admin_html($optAttrUrl) ?>">Manage the master list in Attributes →</a></p>
                    <div class="admin-grid two-up" style="margin-bottom:18px">
                      <?php admin_input('product[option_color_label]', 'Color Section Label', $optColorLabel, 'text', '', 'e.g. Metal, Color'); ?>
                      <?php admin_input('product[option_size_label]', 'Size Section Label', $optSizeLabel, 'text', '', 'e.g. Size, Carat Weight'); ?>
                    </div>

                    <div class="admin-grid">
                      <div class="admin-profile-selector-col">
                        <div class="admin-profile-selector-head">
                          <span class="admin-profile-selector-label"><?= admin_html($optSizeLabel !== '' ? $optSizeLabel : 'Size') ?> Options</span>
                          <small><?= count($optSizeChoices) ?> available</small>
                        </div>
                        <?php if ($optSizeChoices !== []): ?>
                        <div class="admin-profile-chips">
                          <?php foreach ($optSizeChoices as $sIdx => $choice):
                            $isSel = $productCreate ? true : ($optIsCurrent && admin_choice_in_list($choice, $catalogEditorSizeChoices));
                          ?>
                          <label class="admin-prof-chip<?= $isSel ? ' is-active' : '' ?>">
                            <input type="checkbox" name="product[selected_size_indices][]" value="<?= $sIdx ?>" <?= $isSel ? 'checked' : '' ?>>
                            <span class="prof-chip-body">
                              <strong><?= admin_html($choice['label'] ?? '') ?></strong>
                              <?php if (trim($choice['caption'] ?? '') !== ''): ?><small><?= admin_html($choice['caption']) ?></small><?php endif; ?>
                            </span>
                            <i class="fas fa-check prof-chip-check"></i>
                          </label>
                          <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="admin-empty-note">No size options in <strong><?= admin_html((string) $optType) ?></strong> profile yet. <a href="<?= admin_html($optAttrUrl) ?>">Add in Attributes →</a></p>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </section>

                <?php // Always rendered: every category needs the Style picker, and a
                      // category with no metals yet must still show the entry point to
                      // add them. Each per-category block inside is toggled by
                      // data-matrix-profile-type as the Category dropdown changes. ?>
                <section class="admin-product-card" data-metal-matrix-card>
                  <div class="admin-product-card-head">
                    <div>
                      <p class="admin-kicker">Metal Matrix</p>
                      <h4>Metal-based Pricing & Options</h4>
                    </div>
                  </div>
                  <p class="admin-table-note" style="margin-bottom:12px;">Enable the metals this product is cast in. For each metal, set its base price, sizes, and shape options.</p>
                  <?php // Visibility is driven purely by the .has-no-category class on the
                        // editor root (see the CSS below), so the placeholder can never get
                        // stuck visible if a JS pass is missed. ?>
                  <p class="admin-empty-note" data-needs-category>Choose a <strong>Category</strong> above to load its metals and options.</p>

                  <?php
                    // ── Style picker (all categories) ─────────────────────────
                    // Ring categories: engagement/wedding section grids (unchanged).
                    // Non-ring categories: flat grid from available_collection_selector_cards()
                    // so every category gets a style picker like rings always had.
                    // JS syncProductStyleSection() shows only the matching grid and
                    // disables the rest so hidden grids never submit.
                    $editingTaxonomy = $editingProduct !== null ? product_ring_taxonomy($editingProduct) : ['category' => '', 'gender' => ''];
                    // No category chosen yet ⇒ no style grid is pre-visible. Defaulting
                    // to 'engagement' here showed engagement styles under every category.
                    $initialStyleSection = $editingTaxonomy['category'];
                    $savedProductStyles = array_values((array) ($editingProduct['styles'] ?? []));
                    $styleSectionsForPicker = ['engagement', 'wedding'];
                    $initialFlatType = '';
                    if ($editingProduct !== null) {
                        $editType = product_attribute_profile_type($editingProduct);
                        if (in_array($editType, $flatStyleTypes, true)) {
                            $initialFlatType = $editType;
                        }
                    } elseif (in_array($catalogEditorType, $flatStyleTypes, true)) {
                        $initialFlatType = $catalogEditorType;
                    }
                  ?>
                  <div class="admin-field admin-field-full" data-style-picker-wrap style="margin-bottom:18px;">
                    <span style="font-weight:600; font-size:0.95em; margin-bottom:4px; display:block;">Style</span>
                    <p class="admin-table-note" style="margin:0 0 10px;">Select the style(s) this product belongs to. The options come from this category's Attributes and update when you change the Category above.</p>
                    <?php foreach ($styleSectionsForPicker as $pickerSection):
                        $pickerVisible = ($pickerSection === $initialStyleSection) && $initialFlatType === '';
                    ?>
                      <div class="admin-choice-grid" data-style-section="<?= admin_html($pickerSection) ?>" style="<?= $pickerVisible ? '' : 'display:none;' ?>">
                        <input type="hidden" name="product[styles][]" value="" <?= $pickerVisible ? '' : 'disabled' ?>>
                        <?php $pickerStyles = available_ring_styles($pickerSection); ?>
                        <?php if ($pickerStyles === []): ?>
                          <p class="admin-empty-note" style="margin:0;">No styles defined for <?= admin_html(ring_section_profile_type($pickerSection)) ?> yet. <a href="<?= admin_html(admin_url('attributes', ['type' => ring_section_profile_type($pickerSection)])) ?>">Add in Attributes →</a></p>
                        <?php endif; ?>
                        <?php foreach ($pickerStyles as $styleKey => $styleLabel): ?>
                          <label class="admin-choice-chip" style="padding:6px 10px;">
                            <input type="checkbox" name="product[styles][]" value="<?= admin_html($styleKey) ?>" <?= in_array($styleKey, $savedProductStyles, true) ? 'checked' : '' ?> <?= $pickerVisible ? '' : 'disabled' ?>>
                            <span style="font-size:0.9em;"><?= admin_html($styleLabel) ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    <?php endforeach; ?>
                    <?php foreach ($flatStyleTypes as $flatType):
                        $flatCards = available_collection_selector_cards($flatType);
                        $flatVisible = ($flatType === $initialFlatType);
                    ?>
                      <div class="admin-choice-grid" data-style-section="flat-<?= admin_html($flatType) ?>" style="<?= $flatVisible ? '' : 'display:none;' ?>">
                        <input type="hidden" name="product[styles][]" value="" <?= $flatVisible ? '' : 'disabled' ?>>
                        <?php if ($flatCards === []): ?>
                          <p class="admin-empty-note" style="margin:0;">No styles defined for <?= admin_html($flatType) ?> yet. <a href="<?= admin_html(admin_url('attributes', ['type' => $flatType])) ?>">Add in Attributes →</a></p>
                        <?php else: ?>
                          <?php foreach ($flatCards as $styleKey => $styleCard): ?>
                            <label class="admin-choice-chip" style="padding:6px 10px;">
                              <input type="checkbox" name="product[styles][]" value="<?= admin_html($styleKey) ?>" <?= in_array($styleKey, $savedProductStyles, true) ? 'checked' : '' ?> <?= $flatVisible ? '' : 'disabled' ?>>
                              <span style="font-size:0.9em;"><?= admin_html($styleCard['label'] ?? $styleKey) ?></span>
                            </label>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="admin-metal-matrix">
                    <?php
                      // One block per real category — including categories with no
                      // metals yet, which render the "Add Metals" link so the merchant
                      // has a way in. admin.js reveals the block matching the chosen
                      // Category and disables the inputs in the rest.
                      foreach ($catalogAttributeProfilesForJs as $pType => $pProfile):
                          $pTypeIsRing = admin_product_type_is_ring((string) $pType);
                          $globalMetals = $pProfile['option_metal_options'] ?? [];
                          $globalBands = $pTypeIsRing ? ($pProfile['option_band_claw_metal_options'] ?? []) : [];
                          $globalAddonGroups = [];
                          foreach (array_keys(catalog_addon_groups()) as $addonGroupKey) {
                              $globalAddonGroups[$addonGroupKey] = array_values((array) ($pProfile['option_addon_groups'][$addonGroupKey] ?? []));
                          }
                          $globalShapes = $pTypeIsRing ? available_diamond_shapes() : [];
                          $isCurrentType = strtolower((string) $pType) === strtolower($catalogEditorType);
                          // Use a profile-type-specific key so multiple profile blocks never collide in POST
                          $mvFieldKey = 'metal_variations_' . preg_replace('/[^a-z0-9]/', '_', strtolower((string) $pType));
                    ?>
                    <div data-matrix-profile-type="<?= admin_html(strtolower((string) $pType)) ?>" style="display: <?= $isCurrentType ? 'block' : 'none' ?>;">
                      <?php if (empty($globalMetals)): ?>
                        <p class="admin-empty-note">No metal options defined in attributes. <a href="<?= admin_html(admin_url('attributes', ['type' => $pType])) ?>">Add Metals →</a></p>
                      <?php else: ?>
                      <?php foreach ($globalMetals as $mIdx => $metalOpt): 
                          $metalLabel = $metalOpt['label'] ?? '';
                          $metalSlug = content_slug($metalLabel, 'm'.$mIdx);
                          
                          // Find existing variation data for this metal if it exists
                          $existingVar = null;
                          foreach ($catalogEditorMetalVariations as $var) {
                              if (($var['metal'] ?? '') === $metalLabel) {
                                  $existingVar = $var;
                                  break;
                              }
                          }
                          $isActive = $existingVar['active'] ?? false;
                      ?>
                      <div class="admin-metal-matrix-block" style="padding:16px; border:1px solid #e0e6eb; border-radius:8px; margin-bottom:20px;">
                        <div class="admin-metal-matrix-head" style="display:flex; justify-content:space-between; align-items:center; border-bottom: <?= $isActive ? '1px solid #e0e6eb' : 'none' ?>; padding-bottom: <?= $isActive ? '12px' : '0' ?>; background:transparent; transition: all 0.3s ease;">
                          <label class="admin-checkbox" style="font-size:1.1em; font-weight:600; margin:0;">
                            <input type="checkbox" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][active]" value="1" <?= $isActive ? 'checked' : '' ?> onchange="adminToggleMetalDetails(this)">
                            <span><?= admin_html($metalLabel) ?></span>
                          </label>
                          <button type="button" class="admin-ghost admin-metal-apply-btn" style="padding:4px 10px; font-size:0.85em; display: <?= $isActive ? 'block' : 'none' ?>;" onclick="adminApplyMetalToAll(this, <?= $mIdx ?>)">Apply Pricing & Media to All</button>
                        </div>
                        <input type="hidden" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][metal]" value="<?= admin_html($metalLabel) ?>">
                        
                        <div class="admin-metal-details-wrap" style="display: <?= $isActive ? 'block' : 'none' ?>; opacity: <?= $isActive ? '1' : '0' ?>; transform: translateY(<?= $isActive ? '0' : '-10px' ?>); transition: all 0.3s ease; padding-top: 16px;">
                        <div class="admin-grid two-up" style="margin-bottom:16px;">
                          <div class="admin-field">
                            <span>Current Selling Price (£):</span>
                            <input type="number" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][price]" value="<?= admin_html(preg_replace('/[^0-9.]/', '', (string) ($existingVar['price'] ?? '0'))) ?>" step="0.01" min="0">
                          </div>
                          <div class="admin-field">
                            <span>Compare At Price (£):</span>
                            <input type="number" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][old_price]" value="<?= admin_html(preg_replace('/[^0-9.]/', '', (string) ($existingVar['old_price'] ?? ''))) ?>" step="0.01" min="0">
                          </div>
                        </div>

                        <div class="admin-grid two-up" style="margin-bottom:16px;">
                            <div class="admin-field">
                                <span>Description for <?= admin_html($metalLabel) ?>:</span>
                                <textarea name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][description]" rows="4" placeholder="Enter product description..."><?= admin_html($existingVar['description'] ?? '') ?></textarea>
                            </div>
                            <div class="admin-field">
                                <span>Highlights / Features for <?= admin_html($metalLabel) ?>:</span>
                                <textarea name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][features_text]" rows="4" placeholder="One line per storefront highlight."><?= admin_html(admin_export_lines($existingVar['features'] ?? [])) ?></textarea>
                            </div>
                        </div>

                        <div class="admin-field" style="margin-bottom:16px;">
                            <span style="font-weight:600; display:block; margin-bottom:8px;">Gallery Images & Video (Up to 6)</span>
                            <div class="admin-grid three-up">
                            <?php for ($i = 0; $i < 6; $i++): 
                                $isPrimary = $i === 0;
                                $isHover = $i === 1;
                                $slotLabel = $isPrimary ? 'Primary Image (Mandatory)' : ($isHover ? 'Hover Image (Mandatory)' : 'Gallery ' . ($i + 1) . ' (Image/Video)');
                                $acceptStr = ($isPrimary || $isHover) ? 'image/*' : 'image/*,video/*';
                            ?>
                                <div style="padding:10px; border:1px solid #edf0ed; border-radius:6px; background:#f9fbfd;">
                                    <span style="font-size:0.8em; color:#6a7c73; display:block; margin-bottom:4px; font-weight:<?= ($isPrimary || $isHover) ? '600' : 'normal' ?>;"><?= $slotLabel ?></span>
                                    <input type="text" name="metal_gallery_<?= $mIdx ?>_<?= $i ?>_url" value="<?= admin_html($existingVar['gallery'][$i] ?? '') ?>" placeholder="https://..." style="width:100%; margin-bottom:6px; font-size:0.85em; padding:4px;">
                                    <input type="file" name="metal_gallery_<?= $mIdx ?>_<?= $i ?>_file" accept="<?= $acceptStr ?>" style="width:100%; font-size:0.8em;">
                                </div>
                            <?php endfor; ?>
                            </div>
                        </div>
                        
                        <?php if ($globalShapes !== []): ?>
                          <?php
                            $savedShapes = array_values((array) ($existingVar['shapes'] ?? []));
                            $savedShapeGalleries = is_array($existingVar['shape_galleries'] ?? null)
                                ? $existingVar['shape_galleries']
                                : [];
                            $initialShapeMedia = '';
                            foreach ($savedShapes as $savedShape) {
                                if (array_values((array) ($savedShapeGalleries[$savedShape] ?? [])) !== []) {
                                    $initialShapeMedia = (string) $savedShape;
                                    break;
                                }
                            }
                            if ($initialShapeMedia === '' && $savedShapes !== []) {
                                $initialShapeMedia = (string) $savedShapes[0];
                            }
                            $shapeMediaProfileKey = content_slug((string) $pType, 'ring');
                          ?>
                          <div class="admin-field admin-shape-media-picker" data-metal-shape-media-picker data-active-shape="<?= admin_html($initialShapeMedia) ?>">
                            <span class="admin-shape-media-label">Supported Diamond Shapes</span>
                            <div class="admin-shape-media-options">
                              <?php foreach ($globalShapes as $sKey => $sLabel): ?>
                                <?php $shapeChecked = in_array($sKey, $savedShapes, true); ?>
                                <div class="admin-shape-media-option" data-shape-media-option="<?= admin_html((string) $sKey) ?>">
                                  <label class="admin-choice-chip">
                                    <input type="checkbox" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][shapes][]" value="<?= admin_html((string) $sKey) ?>" data-shape-media-toggle <?= $shapeChecked ? 'checked' : '' ?>>
                                    <span><?= admin_html((string) $sLabel) ?></span>
                                  </label>
                                  <button class="admin-shape-media-open" type="button" data-shape-media-open="<?= admin_html((string) $sKey) ?>" title="Edit <?= admin_html((string) $sLabel) ?> media" aria-label="Edit <?= admin_html((string) $sLabel) ?> media" <?= $shapeChecked ? '' : 'hidden' ?>>
                                    <i class="fas fa-images" aria-hidden="true"></i>
                                  </button>
                                </div>
                              <?php endforeach; ?>
                            </div>

                            <div class="admin-shape-media-panels">
                              <?php foreach ($globalShapes as $sKey => $sLabel): ?>
                                <?php
                                  $shapeGallery = array_values((array) ($savedShapeGalleries[$sKey] ?? []));
                                  $shapeFieldKey = content_slug((string) $sKey, 'shape');
                                  $shapePanelOpen = $initialShapeMedia === (string) $sKey && in_array($sKey, $savedShapes, true);
                                ?>
                                <section class="admin-shape-media-panel" data-shape-media-panel="<?= admin_html((string) $sKey) ?>" <?= $shapePanelOpen ? '' : 'hidden' ?>>
                                  <div class="admin-shape-media-panel-head">
                                    <div>
                                      <span>Diamond Media</span>
                                      <h5><?= admin_html((string) $sLabel) ?> / <?= admin_html((string) $metalLabel) ?></h5>
                                    </div>
                                    <strong data-shape-media-count><?= count($shapeGallery) ?> / 6</strong>
                                  </div>
                                  <div class="admin-shape-media-grid">
                                    <?php for ($i = 0; $i < 6; $i++): ?>
                                      <?php
                                        $currentShapeMedia = clean_image((string) ($shapeGallery[$i] ?? ''));
                                        $fieldPrefix = 'metal_shape_gallery_' . $shapeMediaProfileKey . '_' . $mIdx . '_' . $shapeFieldKey . '_' . $i;
                                        $slotLabel = $i === 0 ? 'Primary' : 'Media ' . ($i + 1);
                                      ?>
                                      <div class="admin-shape-media-slot" data-shape-media-slot>
                                        <div class="admin-shape-media-preview" data-shape-media-preview>
                                          <?php if ($currentShapeMedia !== '' && media_asset_type($currentShapeMedia) === 'video'): ?>
                                            <video src="<?= admin_html($currentShapeMedia) ?>" muted playsinline preload="metadata"></video>
                                          <?php elseif ($currentShapeMedia !== ''): ?>
                                            <img src="<?= admin_html($currentShapeMedia) ?>" alt="">
                                          <?php else: ?>
                                            <i class="far fa-image" aria-hidden="true"></i>
                                          <?php endif; ?>
                                        </div>
                                        <div class="admin-shape-media-slot-head">
                                          <span><?= admin_html($slotLabel) ?></span>
                                          <small>Image / video</small>
                                        </div>
                                        <input type="hidden" name="<?= admin_html($fieldPrefix) ?>_url" value="<?= admin_html($currentShapeMedia) ?>" data-shape-media-current>
                                        <div class="admin-shape-media-slot-actions">
                                          <label class="admin-shape-media-upload" title="Upload <?= admin_html((string) $sLabel) ?> media">
                                            <i class="fas fa-upload" aria-hidden="true"></i>
                                            <span data-shape-media-upload-label><?= $currentShapeMedia !== '' ? 'Replace' : 'Upload' ?></span>
                                            <input class="admin-shape-media-file" type="file" name="<?= admin_html($fieldPrefix) ?>_file" accept="image/*,video/*" data-shape-media-file>
                                          </label>
                                          <button class="admin-shape-media-remove" type="button" data-shape-media-remove title="Remove media" aria-label="Remove media" <?= $currentShapeMedia !== '' ? '' : 'hidden' ?>>
                                            <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                          </button>
                                        </div>
                                      </div>
                                    <?php endfor; ?>
                                  </div>
                                </section>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        <?php endif; ?>

                        <div class="admin-grid" style="margin-top:16px;">
                          <div class="admin-field">
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                              <span style="font-weight:600; font-size:0.9em;">Supported Sizes</span>
                              <button type="button" class="admin-ghost" style="padding:2px 8px; font-size:0.75em;" onclick="adminSelectAllSizes(this)">Select All</button>
                            </div>
                            <div class="admin-choice-grid" data-matrix-sizes="<?= $mIdx ?>">
                              <?php 
                                $globalSizesList = $pProfile['option_size_choices'] ?? [];
                                $savedSizes = $existingVar['sizes'] ?? []; 
                              ?>
                              <?php if (empty($globalSizesList)): ?>
                                <span class="admin-empty-note" style="font-size:0.8em;">No sizes in attribute profile.</span>
                              <?php else: ?>
                                <?php foreach ($globalSizesList as $sz): ?>
                                  <label class="admin-choice-chip" style="padding:6px 10px;">
                                    <input type="checkbox" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][sizes][]" value="<?= admin_html($sz['value']) ?>" <?= in_array($sz['value'], $savedSizes, true) ? 'checked' : '' ?>>
                                    <span style="font-size:0.9em;"><?= admin_html($sz['label']) ?></span>
                                  </label>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>

                        <?php if ($pTypeIsRing): ?>
                        <div class="admin-grid" style="margin-top:16px;">
                          <div class="admin-field">
                            <span style="font-weight:600; font-size:0.9em; margin-bottom:8px; display:block;">Band / Claw Add-ons</span>
                            <?php if (empty($globalBands)): ?>
                              <span class="admin-empty-note">No band options defined.</span>
                            <?php else: ?>
                              <div class="admin-band-addons-list" style="display:flex; flex-direction:column; gap:8px;" data-matrix-bands="<?= $mIdx ?>">
                                <?php foreach ($globalBands as $bIdx => $bandOpt): 
                                    $bandLabel = $bandOpt['label'] ?? '';
                                    
                                    $savedBand = null;
                                    foreach (($existingVar['band_options'] ?? []) as $b) {
                                        if (($b['label'] ?? '') === $bandLabel) {
                                            $savedBand = $b;
                                            break;
                                        }
                                    }
                                    $bActive = $savedBand['active'] ?? false;
                                ?>
                                  <div class="admin-band-addon-row" style="display:flex; align-items:center; justify-content:space-between; background:#f9fafb; padding:8px 12px; border-radius:6px; border:1px solid #e5e7eb;">
                                    <label class="admin-checkbox" style="margin:0;">
                                      <input type="checkbox" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][band_options][<?= $bIdx ?>][active]" value="1" <?= $bActive ? 'checked' : '' ?>>
                                      <span style="font-size:0.9em;"><?= admin_html($bandLabel) ?></span>
                                      <input type="hidden" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][band_options][<?= $bIdx ?>][label]" value="<?= admin_html($bandLabel) ?>">
                                    </label>
                                    <div class="admin-field-inline" style="margin:0;">
                                      <span style="font-size:0.85em; color:#6b7280; margin-right:6px;">Add-on:</span>
                                      <div class="admin-input-wrap">
                                        <span class="admin-input-prefix">£</span>
                                        <input type="number" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][band_options][<?= $bIdx ?>][surcharge]" value="<?= admin_html((string) ($savedBand['surcharge'] ?? '0')) ?>" step="0.01" min="0" style="width:80px; padding:4px 8px 4px 28px;">
                                      </div>
                                    </div>
                                  </div>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>
                        <?php endif; ?>

                        <?php // Priced add-on groups, ungated: a group with no labels
                              // in the category's Attributes profile renders nothing. ?>
                        <?php foreach ($globalAddonGroups as $addonKey => $addonRows): ?>
                          <?php if ($addonRows === []) { continue; } ?>
                        <div class="admin-grid" style="margin-top:16px;">
                          <div class="admin-field">
                            <span style="font-weight:600; font-size:0.9em; margin-bottom:8px; display:block;"><?= admin_html(catalog_addon_groups()[$addonKey]['label']) ?></span>
                            <div class="admin-band-addons-list" style="display:flex; flex-direction:column; gap:8px;" data-matrix-addons="<?= admin_html($addonKey) ?>-<?= $mIdx ?>">
                              <?php foreach ($addonRows as $aIdx => $addonOpt):
                                  $addonLabel = $addonOpt['label'] ?? '';

                                  $savedAddon = null;
                                  foreach (($existingVar['addon_groups'][$addonKey] ?? []) as $a) {
                                      if (($a['label'] ?? '') === $addonLabel) {
                                          $savedAddon = $a;
                                          break;
                                      }
                                  }
                                  $aActive = $savedAddon['active'] ?? false;
                              ?>
                                <div class="admin-band-addon-row" style="display:flex; align-items:center; justify-content:space-between; background:#f9fafb; padding:8px 12px; border-radius:6px; border:1px solid #e5e7eb;">
                                  <label class="admin-checkbox" style="margin:0;">
                                    <input type="checkbox" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][addon_groups][<?= admin_html($addonKey) ?>][<?= $aIdx ?>][active]" value="1" <?= $aActive ? 'checked' : '' ?>>
                                    <span style="font-size:0.9em;"><?= admin_html($addonLabel) ?></span>
                                    <input type="hidden" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][addon_groups][<?= admin_html($addonKey) ?>][<?= $aIdx ?>][label]" value="<?= admin_html($addonLabel) ?>">
                                  </label>
                                  <div class="admin-field-inline" style="margin:0;">
                                    <span style="font-size:0.85em; color:#6b7280; margin-right:6px;">Add-on:</span>
                                    <div class="admin-input-wrap">
                                      <span class="admin-input-prefix">£</span>
                                      <input type="number" name="product[<?= admin_html($mvFieldKey) ?>][<?= $mIdx ?>][addon_groups][<?= admin_html($addonKey) ?>][<?= $aIdx ?>][surcharge]" value="<?= admin_html((string) ($savedAddon['surcharge'] ?? '0')) ?>" step="0.01" min="0" style="width:80px; padding:4px 8px 4px 28px;">
                                    </div>
                                  </div>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                        <?php endforeach; ?>

                        </div> <!-- End admin-metal-details-wrap -->
                      </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    </div> <!-- End data-matrix-profile-type -->
                    <?php endforeach; ?>
                  </div>
                </section>
                  </div>
                </section>

              </div>
            </div>

            <div class="admin-actions admin-actions-sticky">
              <button class="admin-primary" type="submit"><?= $editingProduct ? 'Update Product' : 'Upload Product' ?></button>
              <a class="admin-ghost" href="<?= admin_html(admin_url('catalog')) ?>">Close</a>
            </div>
            <?php admin_form_close(); ?>
          </section>
        <?php endif; ?>

        <script type="application/json" id="catalog-attribute-profiles"><?= json_encode($catalogAttributeProfilesForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

        <?php if (!$catalogEditorOpen): ?>


        <section class="admin-panel" id="catalog-library">
          <div class="admin-panel-head"><div><p class="admin-kicker">Library</p><h3>All products</h3></div></div>
          <form method="get" action="<?= admin_html(admin_url('catalog')) ?>" class="admin-filter-bar">
            <input type="hidden" name="view" value="catalog">
            <?php admin_input('q', 'Search', $catalogQuery, 'text', 'placeholder="Search by name, id, type, category"'); ?>
            <?php admin_select('type', 'Filter by Category', $catalogTypeFilter, admin_options_from_list($productTypes, 'All Categories')); ?>
            <?php admin_select('metal', 'Filter by Metal', $catalogMetalFilter, admin_options_from_list($productMetals, 'All Metals')); ?>
            <?php admin_select('status', 'Filter by Status', $catalogStatusFilter, ['' => 'All Statuses', 'active' => 'Active', 'hidden' => 'Hidden']); ?>
            <div class="admin-filter-summary">
              <span><?= count($filteredProducts) ?> products shown</span>
              <small>Use the category and metal filters to narrow down the library before assigning items to homepage sections.</small>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit">Apply Filters</button><a class="admin-ghost" href="<?= admin_html(admin_url('catalog')) ?>">Reset</a></div>
          </form>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Product</th><th>Category</th><th>Metal</th><th>Label</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($filteredProducts as $product): ?>
                  <tr>
                    <td><div class="admin-table-media"><?php if (($product['default_image'] ?? '') !== ''): ?><img src="<?= admin_html($product['default_image']) ?>" alt="<?= admin_html($product['name']) ?>"><?php endif; ?><div><strong><?= admin_html($product['name']) ?></strong><?php $productMetaLine = trim(implode(' • ', array_filter([implode(', ', array_slice((array) ($product['subcategories'] ?? []), 0, 3)), implode(', ', array_slice((array) ($product['styles'] ?? []), 0, 3))]))); ?><?php if ($productMetaLine !== ''): ?><small class="admin-table-note"><?= admin_html($productMetaLine) ?></small><?php endif; ?></div></div></td>
                    <td><?= admin_html(product_category_label($product)) ?></td>
                    <?php
                      $activeMetals = [];
                      $minPrice = null;
                      // Check if this product uses the metal variation matrix (any type, not just Rings)
                      $isMatrixProduct = !empty($product['metal_variations']) && is_array($product['metal_variations']);
                      if ($isMatrixProduct) {
                          foreach ($product['metal_variations'] as $mv) {
                              if ($mv['active'] ?? false) {
                                  $activeMetals[] = $mv['metal'];
                                  $p = (float)($mv['price'] ?? 0);
                                  if ($p > 0 && ($minPrice === null || $p < $minPrice)) {
                                      $minPrice = $p;
                                  }
                              }
                          }
                          // If no active metals found, treat as non-matrix for price display
                          if (empty($activeMetals)) {
                              $isMatrixProduct = false;
                          }
                      }

                      $rawPrice = (string)($product['new_price'] ?? '');
                      if ($isMatrixProduct && $minPrice !== null && $minPrice > 0) {
                          $displayPrice = 'From £' . number_format($minPrice, 2);
                      } else {
                          $displayPrice = $rawPrice !== '' ? (str_contains($rawPrice, '£') ? $rawPrice : '£' . number_format((float)preg_replace('/[^0-9.]/', '', $rawPrice), 2)) : '';
                      }
                      if ($displayPrice === '' || $displayPrice === '£0.00') {
                          $displayPrice = '<span style="color:var(--muted); font-style:italic;">No price</span>';
                      }
                    ?>
                    <td>
                      <?php if (!empty($activeMetals)): ?>
                        <small style="color:var(--muted); font-weight:600; line-height:1.4; display:block;"><?= admin_html(implode(', ', $activeMetals)) ?></small>
                      <?php else: ?>
                        <span style="color:var(--muted); font-style:italic; font-size:0.85em;">—</span>
                      <?php endif; ?>
                    </td>
                    <td><?= admin_html($product['category'] ?? '') ?></td>
                    <td><?= $displayPrice ?></td>
                    <td><span class="status-pill"><?= admin_html($product['status']) ?></span></td>
                    <td>
                      <div class="admin-action-row">
                        <a class="admin-icon-link" href="<?= admin_html(admin_url('catalog', ['product_edit' => $product['id']])) ?>"><i class="fas fa-pen"></i></a>
                        <?php admin_table_button('Delete', 'delete-product', ['product_id' => $product['id']], 'admin-mini-btn danger'); ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>

              </tbody>
            </table>
          </div>
        </section>

        <section class="admin-panel" id="catalog-assignments">
          <div class="admin-panel-head"><div><p class="admin-kicker">Assignments</p><h3>Choose what shows in each homepage section</h3></div></div>
          <?php
          $catalogHomepageTabs = array_values(array_filter(
              (array) ($content['product_tabs']['tabs'] ?? []),
              static fn (array $tab): bool => (string) ($tab['key'] ?? '') === 'featured'
          ));
          $homepageStyleOptions = array_values(homepage_style_showcase_options());
          $selectedHomepageStyleIds = array_values((array) ($content['shop_by_style']['style_ids'] ?? []));
          ?>
          <?php admin_form_open('catalog', 'save-catalog-assignments'); ?>
          <div class="admin-assignment-grid">
            <?php foreach ($catalogHomepageTabs as $tab): ?>
              <section class="admin-assignment-card">
                <div class="admin-assignment-head">
                  <div>
                    <h4><?= admin_html($tab['label']) ?></h4>
                    <p>Select products for this section.</p>
                  </div>
                  <span class="status-pill"><?= count($tab['product_ids'] ?? []) ?> selected</span>
                </div>
                <div class="admin-check-grid">
                  <?php foreach ($products as $product): ?>
                    <label class="admin-check-card">
                      <input type="checkbox" name="assignments[<?= admin_html($tab['key']) ?>][]" value="<?= admin_html($product['id']) ?>" <?= in_array($product['id'], $tab['product_ids'] ?? [], true) ? 'checked' : '' ?>>
                      <span class="admin-check-media">
                        <?php if (($product['default_image'] ?? '') !== ''): ?><img src="<?= admin_html($product['default_image']) ?>" alt="<?= admin_html($product['name']) ?>"><?php endif; ?>
                        <span class="admin-check-copy"><strong><?= admin_html($product['name']) ?></strong><small><?= admin_html(product_category_label($product) . (($product['color'] ?? '') !== '' ? ' • ' . $product['color'] : '')) ?></small><em><?= admin_html($product['new_price']) ?></em></span>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endforeach; ?>
            <section class="admin-assignment-card">
              <div class="admin-assignment-head">
                <div>
                  <h4><?= admin_html($content['shop_by_style']['title'] ?? 'Shop by Style') ?></h4>
                  <p>Select which category styles should appear on the homepage showcase.</p>
                </div>
                <span class="status-pill"><?= count($selectedHomepageStyleIds) ?> selected</span>
              </div>
              <div class="admin-check-grid">
                <?php foreach ($homepageStyleOptions as $styleOption): ?>
                  <label class="admin-check-card">
                    <input type="checkbox" name="assignments[shop_by_style][]" value="<?= admin_html((string) ($styleOption['id'] ?? '')) ?>" <?= in_array((string) ($styleOption['id'] ?? ''), $selectedHomepageStyleIds, true) ? 'checked' : '' ?>>
                    <span class="admin-check-media">
                      <?php if (($styleOption['image'] ?? '') !== ''): ?><img src="<?= admin_html((string) $styleOption['image']) ?>" alt="<?= admin_html((string) ($styleOption['label'] ?? 'Style')) ?>"><?php endif; ?>
                      <span class="admin-check-copy">
                        <strong><?= admin_html((string) ($styleOption['label'] ?? 'Style')) ?></strong>
                        <small><?= admin_html(trim(((string) ($styleOption['type_label'] ?? '')) . ' • Style')) ?></small>
                      </span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </section>
            <section class="admin-assignment-card">
              <div class="admin-assignment-head">
                <div>
                  <h4><?= admin_html($content['bestselling']['title']) ?></h4>
                  <p>Select bestselling products to feature.</p>
                </div>
                <span class="status-pill"><?= count($content['bestselling']['product_ids'] ?? []) ?> selected</span>
              </div>
              <div class="admin-check-grid">
                <?php foreach ($products as $product): ?>
                  <label class="admin-check-card">
                    <input type="checkbox" name="assignments[bestselling][]" value="<?= admin_html($product['id']) ?>" <?= in_array($product['id'], $content['bestselling']['product_ids'] ?? [], true) ? 'checked' : '' ?>>
                    <span class="admin-check-media">
                      <?php if (($product['default_image'] ?? '') !== ''): ?><img src="<?= admin_html($product['default_image']) ?>" alt="<?= admin_html($product['name']) ?>"><?php endif; ?>
                      <span class="admin-check-copy"><strong><?= admin_html($product['name']) ?></strong><small><?= admin_html(product_category_label($product) . (($product['color'] ?? '') !== '' ? ' • ' . $product['color'] : '')) ?></small><em><?= admin_html($product['new_price']) ?></em></span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </section>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Assignments</button></div>
          <?php admin_form_close(); ?>
        </section>
        <?php endif; ?>
      <?php elseif ($view === 'inventory'): ?>
        <section class="admin-page-hero">
          <div>
            <p class="admin-kicker">Stock Control</p>
            <h2>Inventory</h2>
            <p>Manage live stock by product or by metal variation. Checkout now deducts stock automatically and blocks overselling when stock is low.</p>
          </div>
          <div class="admin-mini-stats">
            <article><span>Tracked Products</span><strong><?= admin_html((string) $inventoryTrackedCount) ?></strong></article>
            <article><span>Low Stock</span><strong><?= admin_html((string) $inventoryLowCount) ?></strong></article>
            <article><span>Out of Stock</span><strong><?= admin_html((string) $inventoryOutCount) ?></strong></article>
          </div>
        </section>

        <section class="admin-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">Filters</p><h3>Find the stock record you want</h3></div></div>
          <form method="get" action="<?= admin_html(admin_url('inventory')) ?>" class="admin-filter-bar">
            <input type="hidden" name="view" value="inventory">
            <?php admin_select('inventory_type', 'Filter by Product Type', $inventoryTypeFilter, admin_options_from_list($inventoryProductTypes, 'All Product Types')); ?>
            <?php admin_select('inventory_status', 'Filter by Stock Status', $inventoryStatusFilter, ['' => 'All Stock States', 'tracked' => 'Tracked', 'low' => 'Low Stock', 'out' => 'Out of Stock', 'untracked' => 'Not Tracked']); ?>
            <div class="admin-filter-summary">
              <span><?= count($inventoryProducts) ?> products shown</span>
              <small>Metal-based products can hold separate stock for each metal. Simple products can use one stock quantity.</small>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit">Apply Filters</button><a class="admin-ghost" href="<?= admin_html(admin_url('inventory')) ?>">Reset</a></div>
          </form>
        </section>

        <section class="admin-panel admin-editor-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">Inventory Workspace</p><h3>Review stock and update quantities</h3></div></div>
          <div class="admin-product-editor">
            <div class="admin-product-main">
              <section class="admin-product-card">
                <div class="admin-product-card-head"><div><p class="admin-kicker">Inventory List</p><h4>All matching products</h4></div></div>
                <div class="admin-list-stack">
                  <?php foreach ($inventoryProducts as $inventoryProduct): ?>
                    <?php $inventorySummary = admin_inventory_summary($inventoryProduct); ?>
                    <article class="admin-list-card">
                      <div>
                        <strong><a class="admin-text-link" href="<?= admin_html(admin_url('inventory', array_filter(['inventory_product' => (string) ($inventoryProduct['id'] ?? ''), 'inventory_type' => $inventoryTypeFilter, 'inventory_status' => $inventoryStatusFilter], static fn (string $value): bool => $value !== ''))) ?>"><?= admin_html((string) ($inventoryProduct['name'] ?? 'Product')) ?></a></strong>
                        <small><?= admin_html((string) ($inventoryProduct['product_type'] ?? '')) ?></small>
                        <small><?= admin_html($inventorySummary['label']) ?><?= $inventorySummary['total_quantity'] !== null ? ' • ' . admin_html((string) $inventorySummary['total_quantity']) . ' total units' : '' ?></small>
                      </div>
                      <div class="admin-list-meta">
                        <span class="status-pill"><?= admin_html($inventorySummary['label']) ?></span>
                        <?php if ($inventorySummary['tracked_count'] > 0): ?><small><?= admin_html((string) $inventorySummary['tracked_count']) ?> tracked record<?= $inventorySummary['tracked_count'] === 1 ? '' : 's' ?></small><?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                  <?php if ($inventoryProducts === []): ?><p class="admin-table-note">No products match the current inventory filters.</p><?php endif; ?>
                </div>
              </section>
            </div>

            <aside class="admin-product-sidebar">
              <div class="admin-product-side-card">
                <?php if ($selectedInventoryProduct !== null): ?>
                  <?php $selectedInventorySummary = admin_inventory_summary($selectedInventoryProduct); ?>
                  <p class="admin-kicker">Inventory Editor</p>
                  <h4><?= admin_html((string) ($selectedInventoryProduct['name'] ?? 'Product')) ?></h4>
                  <p class="admin-table-note"><?= admin_html((string) ($selectedInventoryProduct['product_type'] ?? '')) ?> • <?= admin_html($selectedInventorySummary['label']) ?></p>
                  <?php admin_form_open('inventory', 'save-inventory'); ?>
                  <input type="hidden" name="return_view" value="inventory">
                  <input type="hidden" name="inventory_product_id" value="<?= admin_html((string) ($selectedInventoryProduct['id'] ?? '')) ?>">
                  <input type="hidden" name="inventory_type" value="<?= admin_html($inventoryTypeFilter) ?>">
                  <input type="hidden" name="inventory_status" value="<?= admin_html($inventoryStatusFilter) ?>">

                  <?php $inventoryRows = array_values(array_filter((array) ($selectedInventoryProduct['metal_variations'] ?? []), 'is_array')); ?>
                  <?php if ($inventoryRows !== []): ?>
                    <div class="admin-inventory-stack">
                      <?php foreach ($inventoryRows as $inventoryIndex => $inventoryVariation): ?>
                        <?php
                          $tracked = !empty($inventoryVariation['inventory_tracked']);
                          $quantity = clean_int($inventoryVariation['inventory_quantity'] ?? 0, 0, 1000000);
                        ?>
                        <section class="admin-inventory-row-card">
                          <div class="admin-inventory-row-head">
                            <strong><?= admin_html((string) ($inventoryVariation['metal'] ?? 'Metal')) ?></strong>
                            <span class="status-pill"><?= admin_html(!$tracked ? 'Not tracked' : ($quantity <= 0 ? 'Out of stock' : ($quantity <= inventory_low_stock_threshold() ? 'Low stock' : 'In stock'))) ?></span>
                          </div>
                          <label class="admin-checkbox">
                            <input type="checkbox" name="inventory[metal_variations][<?= $inventoryIndex ?>][inventory_tracked]" value="1" <?= $tracked ? 'checked' : '' ?>>
                            <span>Track stock for this metal</span>
                          </label>
                          <?php admin_input('inventory[metal_variations][' . $inventoryIndex . '][inventory_quantity]', 'Available Quantity', (string) $quantity, 'number', 'min="0" step="1"'); ?>
                        </section>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <?php $baseTracked = !empty($selectedInventoryProduct['inventory_tracked']); ?>
                    <?php $baseQuantity = clean_int($selectedInventoryProduct['inventory_quantity'] ?? 0, 0, 1000000); ?>
                    <label class="admin-checkbox">
                      <input type="checkbox" name="inventory[inventory_tracked]" value="1" <?= $baseTracked ? 'checked' : '' ?>>
                      <span>Track stock for this product</span>
                    </label>
                    <?php admin_input('inventory[inventory_quantity]', 'Available Quantity', (string) $baseQuantity, 'number', 'min="0" step="1"', 'Used when this product does not have separate metal stock rows.'); ?>
                  <?php endif; ?>

                  <div class="admin-actions">
                    <button class="admin-primary" type="submit"><?= admin_html(admin_is_employee_portal() ? 'Send Inventory Request' : 'Save Inventory') ?></button>
                  </div>
                  <?php admin_form_close(); ?>
                <?php else: ?>
                  <p class="admin-kicker">Inventory Editor</p>
                  <h4>Select a product</h4>
                  <p class="admin-table-note">Choose a product from the inventory list to set stock by product or by metal.</p>
                <?php endif; ?>
              </div>
            </aside>
          </div>
        </section>
      <?php elseif ($view === 'attributes'): ?>
        <section class="admin-page-hero">
          <div><p class="admin-kicker">Attribute Studio</p><h2>Category-driven option flows</h2><p>Build the real selector workflow by category, then override only the products that need their own metals, weights, or diamond inventory.</p></div>
          <div class="admin-mini-stats">
            <article><span>Categories</span><strong><?= count($attributeTypes) ?></strong></article>
            <article><span><?= admin_html($attributeTypeFilter) ?> Products</span><strong><?= count($attributeProducts) ?></strong></article>
            <?php if ($attributeTypeIsRing): ?>
            <article><span>Ring Metals</span><strong><?= count($attributeProfile['option_metal_options'] ?? []) ?></strong></article>
            <?php else: ?>
            <article><span>Profile Colors</span><strong><?= count($attributeProfile['option_color_choices'] ?? []) ?></strong></article>
            <?php endif; ?>
            <article><span>Profile Sizes</span><strong><?= count($attributeProfile['option_size_choices'] ?? []) ?></strong></article>
          </div>
        </section>
        <section class="admin-anchor-nav">
          <a href="#attribute-profile">Category Profile</a>
          <?php if (!$attributeTypeIsMatrix): ?><a href="#attribute-products">Products</a><?php endif; ?>
          <?php if ($attributeEditingProduct !== null): ?><a href="#attribute-editor">Product Editor</a><?php endif; ?>
        </section>
        <section class="admin-page-hero admin-page-hero-actions">
          <div><p class="admin-kicker">Categories</p><h2><?= admin_html($attributeTypeFilter) ?> attributes</h2></div>
          <div class="admin-pill-row">
            <?php foreach ($attributeTypes as $attributeType): ?>
              <a class="admin-pill <?= $attributeType === $attributeTypeFilter ? 'is-active' : '' ?>" href="<?= admin_html(admin_url('attributes', ['type' => $attributeType])) ?>"><?= admin_html($attributeTypeLabels[$attributeType] ?? $attributeType) ?></a>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="admin-panel admin-editor-panel" id="attribute-profile">
          <div class="admin-panel-head"><div><p class="admin-kicker">Category Profile</p><h3><?= admin_html($attributeTypeFilter) ?> default flow</h3></div></div>
          <?php admin_form_open('attributes', 'save-attribute-profile', true); ?>
          <input type="hidden" name="attribute_type" value="<?= admin_html($attributeTypeFilter) ?>">
          <div class="admin-product-editor<?= $attributeTypeIsMatrix ? ' admin-product-editor--single' : '' ?>">
            <div class="admin-product-main">
              <section class="admin-product-card">
                <?php if ($attributeTypeIsMatrix): ?>
                  <div class="admin-product-card-head"><div><p class="admin-kicker">Matrix Defaults</p><h4>Size and metal options</h4></div></div>
                <?php else: ?>
                  <div class="admin-product-card-head"><div><p class="admin-kicker">Visible Selectors</p><h4>Customer-facing choice cards</h4></div></div>
                <?php endif; ?>
                <div class="admin-grid two-up">
                  <?php $adminAttrGroupNum = 1; ?>
                  <section class="admin-field admin-field-full admin-attr-group">
                    <span><span class="admin-attr-group-num"><?= str_pad((string) $adminAttrGroupNum++, 2, '0', STR_PAD_LEFT) ?></span>Size / Weight Choice Cards</span>
                    <?php $profileSizeChoices = array_values($attributeProfile['option_size_choices'] ?? []); ?>
                    <input type="hidden" name="product[sections_present][]" value="option_size_choices">
                      <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_SIZE_INDEX__" data-next-index="<?= count($profileSizeChoices) ?>">
                      <div class="admin-repeater-list">
                        <?php foreach ($profileSizeChoices as $choiceIndex => $choice): ?>
                          <div class="admin-repeater-item compact-item">
                            <div class="admin-item-head"><h4>Size Choice</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                            <div class="admin-grid two-up">
                              <?php admin_input('product[option_size_choices][' . $choiceIndex . '][label]', 'Label', $choice['label'] ?? ''); ?>
                              <?php admin_input('product[option_size_choices][' . $choiceIndex . '][caption]', 'Caption', $choice['caption'] ?? '', 'text', '', 'Examples: 165mm, Standard'); ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                      <button class="admin-add" type="button" data-add-item data-template="tpl-product-size-choice">Add Size Choice</button>
                    </div>
                  </section>
                  <?php // Metal Options is available to every category — it is the
                        // bootstrap that turns a category into a metal-matrix one,
                        // so it must render even when no metals exist yet. ?>
                    <section class="admin-field admin-field-full admin-attr-group">
                      <span><span class="admin-attr-group-num"><?= str_pad((string) $adminAttrGroupNum++, 2, '0', STR_PAD_LEFT) ?></span>Metal Options</span>
                      <p class="admin-table-note">Add the metals this category is offered in. Once a category has metals, products in it get per-metal pricing, sizes and shapes instead of a single base colour.</p>
                      <?php $profileMetalOptions = array_values($attributeProfile['option_metal_options'] ?? []); ?>
                      <input type="hidden" name="product[sections_present][]" value="option_metal_options">
                      <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_METAL_INDEX__" data-next-index="<?= count($profileMetalOptions) ?>">
                        <div class="admin-repeater-list">
                          <?php foreach ($profileMetalOptions as $optionIndex => $option): ?>
                            <div class="admin-repeater-item compact-item">
                              <div class="admin-item-head"><h4>Metal Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                              <div class="admin-grid two-up">
                                <?php admin_input('product[option_metal_options][' . $optionIndex . '][label]', 'Label', $option['label'] ?? ''); ?>
                                <?php admin_input('product[option_metal_options][' . $optionIndex . '][color_hex]', 'Metal Color *', $option['color_hex'] ?? '#c9a96e', 'color', 'required', 'Pick the display color for this metal (shown on product page & navigation).'); ?>
                              </div>
                              <?php admin_textarea('product[option_metal_options][' . $optionIndex . '][description]', 'Description', $option['description'] ?? '', 3); ?>
                              <input type="hidden" name="product[option_metal_options][<?= $optionIndex ?>][value]" value="<?= admin_html($option['value'] ?? '') ?>">
                              <?php admin_metal_price_adjustment_controls($optionIndex); ?>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button class="admin-add" type="button" data-add-item data-template="tpl-category-metal-option">Add Metal Option</button>
                      </div>
                    </section>
                  <?php // Priced add-on groups (carat weight, chain length, …). Not
                        // ring-gated: every category can offer any of them, and a
                        // group the merchant leaves empty renders nothing anywhere. ?>
                  <?php foreach (catalog_addon_groups() as $addonKey => $addonMeta): ?>
                    <?php $profileAddonRows = array_values($attributeProfile['option_addon_groups'][$addonKey] ?? []); ?>
                    <section class="admin-field admin-field-full admin-attr-group">
                      <span><span class="admin-attr-group-num"><?= str_pad((string) $adminAttrGroupNum++, 2, '0', STR_PAD_LEFT) ?></span><?= admin_html($addonMeta['label']) ?></span>
                      <p class="admin-table-note">Name the choices here, then tick the ones each metal offers — with a surcharge — in the product editor. Leave empty to hide this section from <?= admin_html($attributeTypeFilter) ?> products.</p>
                      <input type="hidden" name="product[sections_present][]" value="option_addon_groups.<?= admin_html($addonKey) ?>">
                      <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_ADDON_INDEX__" data-next-index="<?= count($profileAddonRows) ?>">
                        <div class="admin-repeater-list">
                          <?php foreach ($profileAddonRows as $optionIndex => $option): ?>
                            <div class="admin-repeater-item compact-item">
                              <div class="admin-item-head"><h4><?= admin_html($addonMeta['label']) ?> Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                              <div class="admin-grid one-up">
                                <?php admin_input('product[option_addon_groups][' . $addonKey . '][' . $optionIndex . '][label]', 'Label', $option['label'] ?? ''); ?>
                              </div>
                              <input type="hidden" name="product[option_addon_groups][<?= admin_html($addonKey) ?>][<?= $optionIndex ?>][value]" value="<?= admin_html($option['value'] ?? '') ?>">
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button class="admin-add" type="button" data-add-item data-template="tpl-product-addon-<?= admin_html($addonKey) ?>">Add <?= admin_html($addonMeta['label']) ?> Option</button>
                      </div>
                    </section>
                  <?php endforeach; ?>
                  <?php if ($attributeTypeIsRing): ?>
                    <section class="admin-field admin-field-full admin-attr-group">
                      <span><span class="admin-attr-group-num"><?= str_pad((string) $adminAttrGroupNum++, 2, '0', STR_PAD_LEFT) ?></span>Band / Claw Metal Options</span>
                      <?php $profileBandOptions = array_values($attributeProfile['option_band_claw_metal_options'] ?? []); ?>
                      <input type="hidden" name="product[sections_present][]" value="option_band_claw_metal_options">
                      <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_BAND_INDEX__" data-next-index="<?= count($profileBandOptions) ?>">
                        <div class="admin-repeater-list">
                          <?php foreach ($profileBandOptions as $optionIndex => $option): ?>
                            <div class="admin-repeater-item compact-item">
                              <div class="admin-item-head"><h4>Band / Claw Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                              <div class="admin-grid one-up">
                                <?php admin_input('product[option_band_claw_metal_options][' . $optionIndex . '][label]', 'Label', $option['label'] ?? ''); ?>
                              </div>
                              <input type="hidden" name="product[option_band_claw_metal_options][<?= $optionIndex ?>][current_image]" value="<?= admin_html($option['image'] ?? '') ?>">
                              <div class="admin-grid two-up">
                                <?php admin_input('band_image_url_' . $optionIndex, 'Swatch Image URL', $option['image'] ?? '', 'text', '', 'Paste an image URL or upload one below. Shown on the product page when the customer selects this band option.'); ?>
                                <label class="admin-field">
                                  <span>Upload Image</span>
                                  <input type="file" name="band_image_file_<?= $optionIndex ?>" accept="image/*">
                                </label>
                              </div>
                              <?php admin_textarea('product[option_band_claw_metal_options][' . $optionIndex . '][description]', 'Description', $option['description'] ?? '', 3); ?>
                              <input type="hidden" name="product[option_band_claw_metal_options][<?= $optionIndex ?>][value]" value="<?= admin_html($option['value'] ?? '') ?>">
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button class="admin-add" type="button" data-add-item data-template="tpl-product-band-option">Add Band / Claw Option</button>
                      </div>
                    </section>
                    <?php
                      // A ring profile owns exactly ONE style section: Engagement Rings
                      // owns 'engagement', Wedding Rings owns 'wedding'. Rendering both
                      // here is what put a Wedding list on the Engagement page and vice
                      // versa, and let a save to one page overwrite the other's styles.
                      $attributeRingSection = catalog_category_ring_section($attributeTypeFilter);
                      if ($attributeRingSection === '') {
                          $attributeRingSection = 'engagement';
                      }
                      $ringSectionCards = array_values($attributeProfile['style_cards_sections'][$attributeRingSection] ?? []);
                      if ($ringSectionCards === []) {
                          // Legacy profiles kept this profile's list in the flat field.
                          $ringSectionCards = array_values($attributeProfile['style_cards'] ?? []);
                      }
                      $ringSectionHeading = $attributeRingSection === 'wedding'
                          ? 'Wedding Rings — Shop by Style'
                          : 'Ring Style Showcase';
                      $ringSectionNote = $attributeRingSection === 'wedding'
                          ? "Styles shown for wedding rings (both men's and women's bands). Engagement styles are managed on the Engagement Rings attribute page."
                          : 'The design bubbles shown across the live engagement ring pages. Wedding band styles are managed on the Wedding Rings attribute page.';
                      $ringSectionPlaceholder = $attributeRingSection === 'wedding'
                          ? 'e.g. Classic Band, Eternity, Pavé.'
                          : 'e.g. Solitaire, Halo, Toi et Moi.';
                      $ringSectionAddLabel = $attributeRingSection === 'wedding' ? 'Add Wedding Style' : 'Add Style';
                      $ringSectionTemplate = 'tpl-product-style-card-' . $attributeRingSection;
                      $ringSectionToken = $attributeRingSection === 'wedding' ? '__WED_STYLE_INDEX__' : '__ENG_STYLE_INDEX__';
                    ?>
                    <section class="admin-field admin-field-full admin-attr-group" id="attribute-ring-styles">
                      <span><span class="admin-attr-group-num"><?= str_pad((string) $adminAttrGroupNum++, 2, '0', STR_PAD_LEFT) ?></span><?= admin_html($ringSectionHeading) ?></span>
                      <p class="admin-table-note"><?= admin_html($ringSectionNote) ?></p>
                      <input type="hidden" name="product[sections_present][]" value="style_cards_sections.<?= admin_html($attributeRingSection) ?>">
                      <div class="admin-repeater" data-repeater data-index-token="<?= admin_html($ringSectionToken) ?>" data-next-index="<?= count($ringSectionCards) ?>">
                        <div class="admin-repeater-list">
                          <?php foreach ($ringSectionCards as $ringStyleIndex => $ringStyleCard): ?>
                            <?php
                              $ringStyleLabel = clean_string((string) ($ringStyleCard['label'] ?? ''), 120);
                              $ringStyleImage = clean_image((string) ($ringStyleCard['image'] ?? ''));
                            ?>
                            <div class="admin-repeater-item compact-item">
                              <div class="admin-item-head"><h4><?= admin_html($ringStyleLabel !== '' ? $ringStyleLabel : 'Style ' . ($ringStyleIndex + 1)) ?></h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                              <input type="hidden" name="product[style_cards_sections][<?= admin_html($attributeRingSection) ?>][<?= $ringStyleIndex ?>][current_image]" value="<?= admin_html($ringStyleImage) ?>">
                              <div class="admin-grid one-up">
                                <?php admin_input('product[style_cards_sections][' . $attributeRingSection . '][' . $ringStyleIndex . '][label]', 'Style Name', $ringStyleLabel, 'text', '', $ringSectionPlaceholder); ?>
                              </div>
                              <div class="admin-grid two-up">
                                <?php admin_input('style_card_' . $attributeRingSection . '_image_url_' . $ringStyleIndex, 'Image URL', $ringStyleImage, 'text', '', 'Paste an image URL or upload one below.'); ?>
                                <label class="admin-field">
                                  <span>Upload Image</span>
                                  <input type="file" name="style_card_<?= admin_html($attributeRingSection) ?>_image_file_<?= $ringStyleIndex ?>" accept="image/*">
                                </label>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button class="admin-add" type="button" data-add-item data-template="<?= admin_html($ringSectionTemplate) ?>"><?= admin_html($ringSectionAddLabel) ?></button>
                      </div>
                    </section>
                  <?php else: ?>
                    <section class="admin-field admin-field-full admin-attr-group" id="attribute-collection-showcase">
                      <span><span class="admin-attr-group-num"><?= str_pad((string) $adminAttrGroupNum++, 2, '0', STR_PAD_LEFT) ?></span>Style Showcase</span>
                      <p class="admin-table-note">Control the round style bubbles shown on the live collection page for this category. You can rename each style, change its image, or add new styles. This works for current categories and future categories too.</p>
                      <?php
                        $profileSelectorCards = array_values($attributeProfile['selector_cards'] ?? []);
                        if ($profileSelectorCards === []) {
                            $profileSelectorCards = array_values(available_collection_selector_cards($attributeTypeFilter));
                        }
                      ?>
                      <input type="hidden" name="product[sections_present][]" value="selector_cards">
                      <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_SELECTOR_INDEX__" data-next-index="<?= count($profileSelectorCards) ?>">
                        <div class="admin-repeater-list">
                          <?php foreach ($profileSelectorCards as $selectorIndex => $selectorCard): ?>
                            <?php
                              $selectorValue = clean_string((string) ($selectorCard['value'] ?? ''), 80);
                              $selectorLabel = clean_string((string) ($selectorCard['label'] ?? ''), 120);
                              $selectorImage = clean_image((string) ($selectorCard['image'] ?? ''));
                            ?>
                            <div class="admin-repeater-item compact-item">
                              <div class="admin-item-head"><h4><?= admin_html($selectorLabel !== '' ? $selectorLabel : 'Style ' . ($selectorIndex + 1)) ?></h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                              <input type="hidden" name="product[selector_cards][<?= $selectorIndex ?>][current_image]" value="<?= admin_html($selectorImage) ?>">
                              <div class="admin-grid one-up">
                                <?php admin_input('product[selector_cards][' . $selectorIndex . '][label]', 'Style Name', $selectorLabel, 'text', '', 'Used for display, and the URL/filter value is created from this name automatically.'); ?>
                              </div>
                              <div class="admin-grid two-up">
                                <?php admin_input('selector_card_image_url_' . $selectorIndex, 'Image URL', $selectorImage, 'text', '', 'Paste an image URL or upload one below.'); ?>
                                <label class="admin-field">
                                  <span>Upload Image</span>
                                  <input type="file" name="selector_card_image_file_<?= $selectorIndex ?>" accept="image/*">
                                </label>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button class="admin-add" type="button" data-add-item data-template="tpl-product-selector-card">Add Style</button>
                      </div>
                    </section>
                  <?php endif; ?>
                </div>
              </section>
            </div>
            <?php if (!$attributeTypeIsMatrix): ?>
            <aside class="admin-product-sidebar">
              <div class="admin-product-side-card">
                <p class="admin-kicker">Profile Summary</p>
                <h4><?= admin_html($attributeTypeFilter) ?></h4>
                <div class="admin-product-side-stats">
                  <article><span>Products</span><strong><?= count($attributeProducts) ?></strong></article>
                  <article><span>Colors</span><strong><?= count($attributeProfile['option_color_choices'] ?? []) ?></strong></article>
                  <article><span>Sizes</span><strong><?= count($attributeProfile['option_size_choices'] ?? []) ?></strong></article>
                  <article><span>Diamond Rows</span><strong><?= count($attributeProfile['diamond_inventory'] ?? []) ?></strong></article>
                </div>
                <p class="admin-table-note">This profile becomes the default option flow for every <?= admin_html(strtolower($attributeTypeFilter)) ?> product unless a specific item is overridden below.</p>
              </div>
            </aside>
            <?php endif; ?>
          </div>
          <div class="admin-actions admin-actions-sticky"><button class="admin-primary" type="submit">Save <?= admin_html($attributeTypeFilter) ?> Profile</button></div>
          <?php admin_form_close(); ?>
        </section>

        <?php if (!$attributeTypeIsMatrix): ?>
        <section class="admin-panel" id="attribute-products">
          <div class="admin-panel-head"><div><p class="admin-kicker">Products</p><h3><?= admin_html($attributeTypeFilter) ?> items</h3></div></div>
          <div class="admin-attribute-list">
            <?php foreach ($attributeProducts as $product): ?>
              <article class="admin-list-card">
                <div class="admin-table-media">
                  <?php if (($product['default_image'] ?? '') !== ''): ?><img src="<?= admin_html($product['default_image']) ?>" alt="<?= admin_html($product['name']) ?>"><?php endif; ?>
                  <div>
                    <strong><?= admin_html($product['name']) ?></strong>
                    <small><?= admin_html(product_category_label($product)) ?> • <?= admin_html((string) ($product['new_price'] ?? '')) ?></small>
                  </div>
                </div>
                <div class="admin-list-meta">
                  <span class="status-pill"><?= admin_html((string) ($product['status'] ?? 'active')) ?></span>
                  <a class="admin-text-link" href="<?= admin_html(admin_url('attributes', ['type' => $attributeTypeFilter, 'attribute_product' => $product['id']])) ?>">Edit Attributes</a>
                </div>
              </article>
            <?php endforeach; ?>
            <?php if ($attributeProducts === []): ?><p class="admin-table-note">No <?= admin_html(strtolower($attributeTypeFilter)) ?> products are in the library yet.</p><?php endif; ?>
          </div>
        </section>
        <?php endif; ?>

        <?php if ($attributeEditingProduct !== null): ?>
          <section class="admin-panel admin-editor-panel" id="attribute-editor">
            <div class="admin-panel-head"><div><p class="admin-kicker">Product Override</p><h3><?= admin_html($attributeEditingProduct['name']) ?></h3></div></div>
            <?php admin_form_open('attributes', 'save-product-attributes'); ?>
            <input type="hidden" name="product_id" value="<?= admin_html($attributeEditingProduct['id']) ?>">
            <input type="hidden" name="attribute_type" value="<?= admin_html($attributeTypeFilter) ?>">
            <div class="admin-product-editor">
              <div class="admin-product-main">
                <?php if ($attributeTypeIsRing): ?>
                  <section class="admin-product-card">
                    <div class="admin-product-card-head"><div><p class="admin-kicker">Ring Builder</p><h4>Styles and supported shapes</h4></div></div>
                    <p class="admin-table-note">Style card images and style display names are managed above in the <a href="#attribute-ring-styles">Ring Style Showcase</a> section.</p>
                    <div class="admin-grid two-up">
                      <div class="admin-field admin-field-full">
                        <span>Styles Supported</span>
                        <?php
                          // Offer the styles of the product's own section (engagement
                          // or wedding) so a wedding band is never offered solitaire.
                          $overrideTaxonomy = product_ring_taxonomy($attributeEditingProduct);
                          $overrideStyleSection = in_array($overrideTaxonomy['category'], ['engagement', 'wedding'], true) ? $overrideTaxonomy['category'] : '';
                        ?>
                        <div class="admin-choice-grid">
                          <?php $productStyles = $attributeEditingProduct['styles'] ?? []; ?>
                          <?php foreach (available_ring_styles($overrideStyleSection) as $styleKey => $styleLabel): ?>
                            <label class="admin-choice-chip">
                              <input type="checkbox" name="product[styles][]" value="<?= admin_html($styleKey) ?>" <?= in_array($styleKey, $productStyles, true) ? 'checked' : '' ?>>
                              <span><?= admin_html($styleLabel) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="admin-field admin-field-full">
                        <span>Diamond Shapes Supported</span>
                        <div class="admin-choice-grid">
                          <?php $productShapes = $attributeEditingProduct['diamondShapes'] ?? []; ?>
                          <?php foreach (available_diamond_shapes() as $shapeKey => $shapeLabel): ?>
                            <label class="admin-choice-chip">
                              <input type="checkbox" name="product[diamondShapes][]" value="<?= admin_html($shapeKey) ?>" <?= in_array($shapeKey, $productShapes, true) ? 'checked' : '' ?>>
                              <span><?= admin_html($shapeLabel) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                  </section>
                <?php endif; ?>

                <section class="admin-product-card">
                  <?php if ($attributeTypeIsRing): ?>
                    <div class="admin-product-card-head"><div><p class="admin-kicker">Product Overrides</p><h4>Size, metal, and band options for this ring</h4></div></div>
                  <?php else: ?>
                    <div class="admin-product-card-head"><div><p class="admin-kicker">Product Overrides</p><h4>Custom option flow for this item</h4></div></div>
                  <?php endif; ?>
                  <p class="admin-table-note">Only add rows here when this product needs a different setup than the <?= admin_html($attributeTypeFilter) ?> category profile above.</p>
                  <div class="admin-grid two-up">
                    <div class="admin-field admin-field-full">
                      <span>Size / Weight Choice Cards</span>
                      <?php $productSizeChoices = array_values($attributeEditingProduct['option_size_choices'] ?? []); ?>
                      <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_SIZE_INDEX__" data-next-index="<?= count($productSizeChoices) ?>">
                        <div class="admin-repeater-list">
                          <?php foreach ($productSizeChoices as $choiceIndex => $choice): ?>
                            <div class="admin-repeater-item compact-item">
                              <div class="admin-item-head"><h4>Size Choice</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                              <div class="admin-grid two-up">
                                <?php admin_input('product[option_size_choices][' . $choiceIndex . '][label]', 'Label', $choice['label'] ?? ''); ?>
                                <?php admin_input('product[option_size_choices][' . $choiceIndex . '][caption]', 'Caption', $choice['caption'] ?? '', 'text', '', 'Examples: 165mm, Standard'); ?>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <button class="admin-add" type="button" data-add-item data-template="tpl-product-size-choice">Add Size Choice</button>
                      </div>
                    </div>
                    <?php if ($attributeTypeIsRing): ?>
                      <div class="admin-field admin-field-full">
                        <span>Ring Metal Options</span>
                        <?php $productMetalOptions = array_values($attributeEditingProduct['option_metal_options'] ?? []); ?>
                        <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_METAL_INDEX__" data-next-index="<?= count($productMetalOptions) ?>">
                          <div class="admin-repeater-list">
                            <?php foreach ($productMetalOptions as $optionIndex => $option): ?>
                              <div class="admin-repeater-item compact-item">
                                <div class="admin-item-head"><h4>Metal Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                                <div class="admin-grid two-up">
                                  <?php admin_input('product[option_metal_options][' . $optionIndex . '][label]', 'Label', $option['label'] ?? ''); ?>
                                  <?php admin_input('product[option_metal_options][' . $optionIndex . '][color_hex]', 'Metal Color *', $option['color_hex'] ?? '#c9a96e', 'color', 'required', 'Pick the display color for this metal.'); ?>
                                </div>
                                <?php admin_textarea('product[option_metal_options][' . $optionIndex . '][description]', 'Description', $option['description'] ?? '', 3); ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                          <button class="admin-add" type="button" data-add-item data-template="tpl-product-detail-option">Add Metal Option</button>
                        </div>
                      </div>
                      <div class="admin-field admin-field-full">
                        <span>Band / Claw Metal Options</span>
                        <?php $productBandOptions = array_values($attributeEditingProduct['option_band_claw_metal_options'] ?? []); ?>
                        <div class="admin-repeater" data-repeater data-index-token="__PRODUCT_BAND_INDEX__" data-next-index="<?= count($productBandOptions) ?>">
                          <div class="admin-repeater-list">
                            <?php foreach ($productBandOptions as $optionIndex => $option): ?>
                              <div class="admin-repeater-item compact-item">
                                <div class="admin-item-head"><h4>Band / Claw Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                                <div class="admin-grid one-up">
                                  <?php admin_input('product[option_band_claw_metal_options][' . $optionIndex . '][label]', 'Label', $option['label'] ?? ''); ?>
                                </div>
                                <input type="hidden" name="product[option_band_claw_metal_options][<?= $optionIndex ?>][current_image]" value="<?= admin_html($option['image'] ?? '') ?>">
                                <div class="admin-grid two-up">
                                  <?php admin_input('band_image_url_' . $optionIndex, 'Image URL', $option['image'] ?? '', 'text', '', 'Paste an image URL or upload one below.'); ?>
                                  <label class="admin-field">
                                    <span>Upload Image</span>
                                    <input type="file" name="band_image_file_<?= $optionIndex ?>" accept="image/*">
                                  </label>
                                </div>
                                <?php admin_textarea('product[option_band_claw_metal_options][' . $optionIndex . '][description]', 'Description', $option['description'] ?? '', 3); ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                          <button class="admin-add" type="button" data-add-item data-template="tpl-product-band-option">Add Band / Claw Option</button>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                </section>
              </div>
              <?php if (!$attributeTypeIsRing): ?>
              <aside class="admin-product-sidebar">
                <div class="admin-product-side-card">
                  <p class="admin-kicker">Override Summary</p>
                  <h4><?= admin_html($attributeEditingProduct['name']) ?></h4>
                  <div class="admin-product-side-stats">
                    <article><span>Type</span><strong><?= admin_html($attributeEditingProduct['product_type'] ?? '') ?></strong></article>
                    <article><span>Status</span><strong><?= admin_html($attributeEditingProduct['status'] ?? '') ?></strong></article>
                    <article><span>Styles</span><strong><?= count($attributeEditingProduct['styles'] ?? []) ?></strong></article>
                    <article><span>Shapes</span><strong><?= count($attributeEditingProduct['diamondShapes'] ?? []) ?></strong></article>
                    <article><span>Color Cards</span><strong><?= count($attributeEditingProduct['option_color_choices'] ?? []) ?></strong></article>
                    <article><span>Size Cards</span><strong><?= count($attributeEditingProduct['option_size_choices'] ?? []) ?></strong></article>
                  </div>
                  <?php if (($attributeEditingProduct['default_image'] ?? '') !== ''): ?><img class="admin-product-side-image" src="<?= admin_html($attributeEditingProduct['default_image']) ?>" alt="<?= admin_html($attributeEditingProduct['name']) ?>"><?php endif; ?>
                </div>
              </aside>
              <?php endif; ?>
            </div>
            <div class="admin-actions admin-actions-sticky"><button class="admin-primary" type="submit">Save Product Attributes</button><a class="admin-ghost" href="<?= admin_html(admin_url('attributes', ['type' => $attributeTypeFilter])) ?>">Close</a></div>
            <?php admin_form_close(); ?>
          </section>
        <?php endif; ?>
      <?php elseif ($view === 'diamonds'): ?>
        <section class="admin-page-hero">
          <div>
            <p class="admin-kicker">Diamond Library</p>
            <h2>Select-diamond inventory</h2>
            <p>Manage the diamonds shown after a ring is selected. This feeds the live select-diamond page while preserving the current ring-to-diamond flow.</p>
          </div>
          <div class="admin-mini-stats">
            <article><span>Total Rows</span><strong><?= count($diamondAdminInventory) ?></strong></article>
            <article><span>Showing</span><strong><?= count($filteredDiamondAdminInventory) ?></strong></article>
          </div>
        </section>

        <section class="admin-page-hero admin-page-hero-actions">
          <div><p class="admin-kicker">Inventory</p><h2>Manage diamonds</h2></div>
          <div class="admin-top-actions"><a class="admin-primary" href="<?= admin_html(admin_url('diamonds', array_filter(['diamond_form' => 'create', 'diamond_shape' => $diamondShapeFilter], static fn (string $value): bool => $value !== '')) . '#diamond-editor') ?>">Add Diamond</a></div>
        </section>

        <section class="admin-panel" id="diamond-library">
          <div class="admin-product-editor">
            <div class="admin-product-main">
              <section class="admin-product-card">
                <div class="admin-product-card-head">
                  <div>
                    <p class="admin-kicker">Available Diamonds</p>
                    <h4><?= $diamondShapeFilter !== '' ? admin_html(available_diamond_shapes()[$diamondShapeFilter] ?? $diamondShapeFilter) . ' diamonds' : 'All diamond rows' ?></h4>
                  </div>
                </div>
                <p class="admin-table-note">Each row is a card on the select-diamond page. Use Edit to update that specific diamond only.</p>

                <div class="admin-list-stack">
                  <?php foreach ($filteredDiamondAdminInventory as $diamondRow): ?>
                    <?php
                      $shapeLabel = available_diamond_shapes()[strtolower((string) ($diamondRow['shape'] ?? ''))] ?? admin_html((string) ($diamondRow['shape'] ?? ''));
                      $diamondCardTitle = clean_string((string) ($diamondRow['title'] ?? ''), 140);
                      if ($diamondCardTitle === '') {
                          $diamondCardTitle = trim((string) ($diamondRow['carat'] ?? '') . 'ct ' . (string) ($diamondRow['color'] ?? '') . ' / ' . (string) ($diamondRow['clarity'] ?? '') . ' ' . $shapeLabel);
                      }
                    ?>
                    <article class="admin-list-card">
                      <div class="admin-table-media">
                        <?php if (($diamondRow['image'] ?? '') !== ''): ?><img src="<?= admin_html((string) $diamondRow['image']) ?>" alt="<?= admin_html($diamondCardTitle) ?>"><?php endif; ?>
                        <div>
                          <strong><?= admin_html($diamondCardTitle) ?></strong>
                          <small><?= admin_html($shapeLabel) ?> • £<?= admin_html(number_format((float) ($diamondRow['price'] ?? 0), 2)) ?> • <?= admin_html((string) ($diamondRow['cut'] ?? '')) ?></small>
                          <small><?= admin_html((string) ($diamondRow['description'] ?? '')) ?></small>
                        </div>
                      </div>
                      <div class="admin-list-meta">
                        <span class="status-pill"><?= admin_html((string) ($diamondRow['status'] ?? 'active')) ?></span>
                        <div class="admin-action-row">
                          <a class="admin-icon-link" href="<?= admin_html(admin_url('diamonds', array_filter(['diamond_edit' => $diamondRow['id'], 'diamond_shape' => $diamondShapeFilter], static fn (string $value): bool => $value !== '')) . '#diamond-editor') ?>"><i class="fas fa-pen"></i></a>
                          <?php admin_table_button('Delete', 'delete-diamond', ['diamond_id' => $diamondRow['id'], 'diamond_shape_filter' => $diamondShapeFilter], 'admin-mini-btn danger'); ?>
                        </div>
                      </div>
                    </article>
                  <?php endforeach; ?>
                  <?php if ($filteredDiamondAdminInventory === []): ?><p class="admin-table-note">No diamonds match this filter yet.</p><?php endif; ?>
                </div>
              </section>
            </div>

            <aside class="admin-product-sidebar">
              <div class="admin-product-side-card">
                <p class="admin-kicker">Shape Counts</p>
                <h4>Filter by shape</h4>
                <div class="admin-list-stack">
                  <a class="admin-list-card" href="<?= admin_html(admin_url('diamonds')) ?>">
                    <div>
                      <strong>All Diamonds</strong>
                      <small>Show every diamond row in the library.</small>
                    </div>
                    <div class="admin-list-meta"><strong><?= admin_html((string) count($diamondAdminInventory)) ?></strong></div>
                  </a>
                  <?php foreach ($diamondAdminShapeStats as $shapeKey => $shapeStat): ?>
                    <a class="admin-list-card" href="<?= admin_html(admin_url('diamonds', ['diamond_shape' => $shapeKey])) ?>">
                      <div>
                        <strong><?= admin_html($shapeStat['label']) ?></strong>
                        <small>Click to show only this shape.</small>
                      </div>
                      <div class="admin-list-meta"><strong><?= admin_html((string) $shapeStat['count']) ?></strong></div>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="admin-product-side-card">
                <p class="admin-kicker">Compatibility</p>
                <h4>Current flow preserved</h4>
                <p class="admin-table-note">This updates the default ring diamond inventory used by the current select-diamond page. Supported shapes still come from the selected ring, and product-specific overrides remain available in <a class="admin-text-link" href="<?= admin_html(admin_url('attributes', ['type' => 'Ring'])) ?>">Attributes</a>.</p>
              </div>
            </aside>
          </div>
        </section>

        <?php if ($diamondCreate || $editingDiamond !== null): ?>
          <section class="admin-panel admin-editor-panel" id="diamond-editor">
            <div class="admin-panel-head"><div><p class="admin-kicker">Editor</p><h3><?= $editingDiamond !== null ? 'Edit diamond' : 'Add diamond' ?></h3></div></div>
            <?php admin_form_open('diamonds', $editingDiamond !== null ? 'update-diamond' : 'create-diamond', true); ?>
            <?php if ($editingDiamond !== null): ?><input type="hidden" name="diamond_id" value="<?= admin_html((string) ($editingDiamond['id'] ?? '')) ?>"><?php endif; ?>
            <div class="admin-product-editor">
              <input type="hidden" name="diamond_shape_filter" value="<?= admin_html($diamondShapeFilter) ?>">
              <div class="admin-product-main">
                <section class="admin-product-card">
                  <div class="admin-product-card-head"><div><p class="admin-kicker">Card Content</p><h4>Image, title, description, and price</h4></div></div>
                  <div class="admin-grid four-up">
                    <?php admin_select('diamond_item[shape]', 'Shape', $editingDiamond['shape'] ?? 'round', available_diamond_shapes()); ?>
                    <?php admin_input('diamond_item[title]', 'Card Title', $editingDiamond['title'] ?? '', 'text', 'placeholder="Optional custom card title"'); ?>
                    <?php admin_input('diamond_item[carat]', 'Carat', $editingDiamond['carat'] ?? ''); ?>
                    <?php admin_input('diamond_item[price]', 'Diamond Price', (string) ($editingDiamond['price'] ?? '0'), 'number', 'step="0.01" min="0"'); ?>
                  </div>
                  <?php admin_textarea('diamond_item[description]', 'Diamond Description', $editingDiamond['description'] ?? '', 4); ?>
                </section>

                <section class="admin-product-card">
                  <div class="admin-product-card-head"><div><p class="admin-kicker">Specifications</p><h4>Grading and certificate details</h4></div></div>
                  <div class="admin-grid four-up">
                    <?php admin_input('diamond_item[color]', 'Color Grade', $editingDiamond['color'] ?? ''); ?>
                    <?php admin_input('diamond_item[clarity]', 'Clarity', $editingDiamond['clarity'] ?? ''); ?>
                    <?php admin_input('diamond_item[cut]', 'Cut', $editingDiamond['cut'] ?? ''); ?>
                    <?php admin_input('diamond_item[badge]', 'Badge', $editingDiamond['badge'] ?? 'Lab Selected'); ?>
                  </div>
                  <div class="admin-grid four-up">
                    <?php admin_input('diamond_item[ratio]', 'Ratio', $editingDiamond['ratio'] ?? ''); ?>
                    <?php admin_input('diamond_item[measurement]', 'Measurement', $editingDiamond['measurement'] ?? '', 'text', 'placeholder="Example: 8.20 x 5.80 x 3.55 mm"'); ?>
                    <?php admin_input('diamond_item[ref]', 'REF', $editingDiamond['ref'] ?? ''); ?>
                    <?php admin_input('diamond_item[igi_certificate]', 'IGI Certificate', $editingDiamond['igi_certificate'] ?? '', 'text', 'placeholder="Certificate number or URL"'); ?>
                  </div>
                </section>
              </div>

              <aside class="admin-product-sidebar">
                <div class="admin-product-side-card">
                  <p class="admin-kicker">Media & Status</p>
                  <h4>Diamond card image</h4>
                  <div class="admin-grid one-up">
                    <?php admin_input('diamond_image_url', 'Diamond Image URL', $editingDiamond['image'] ?? '', 'text', '', 'Paste a direct image URL or upload below.'); ?>
                    <?php admin_input('diamond_image_file', 'Upload Image', '', 'file', 'accept="image/*"'); ?>
                    <?php admin_select('diamond_item[status]', 'Stock Status', $editingDiamond['status'] ?? 'active', ['active' => 'Available', 'sold-out' => 'Sold Out']); ?>
                  </div>
                </div>
              </aside>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit"><?= $editingDiamond !== null ? 'Update Diamond' : 'Create Diamond' ?></button><a class="admin-ghost" href="<?= admin_html(admin_url('diamonds', $diamondShapeFilter !== '' ? ['diamond_shape' => $diamondShapeFilter] : [])) ?>">Close</a></div>
            <?php admin_form_close(); ?>
          </section>
        <?php endif; ?>

      <?php elseif ($view === 'news'): ?>
        <section class="admin-page-hero"><div><p class="admin-kicker">Azuronn News</p><h2>Posts</h2><p>Keep the editorial feed clean with a publish-first workflow: filter, review, then open a focused editor only when needed.</p></div><div class="admin-mini-stats"><article><span>Total Posts</span><strong><?= count($content['news']['items']) ?></strong></article><article><span>Filtered</span><strong><?= count($filteredNews) ?></strong></article></div></section>
        <section class="admin-anchor-nav">
          <a href="#news-library">Library</a>
          <a href="#news-editor">Editor</a>
        </section>
        <section class="admin-page-hero admin-page-hero-actions"><div><p class="admin-kicker">Publishing</p><h2>Manage posts</h2></div><div class="admin-top-actions"><a class="admin-primary" href="<?= admin_html(admin_url('news', ['news_form' => 'create'])) ?>">Upload Post</a></div></section>
        <section class="admin-panel" id="news-library">
          <form method="get" action="<?= admin_html(admin_url('news')) ?>" class="admin-filter-bar admin-filter-bar-simple">
            <input type="hidden" name="view" value="news">
            <?php admin_input('news_q', 'Search Posts', $newsQuery, 'text', 'placeholder="Search title, author, excerpt"'); ?>
            <div class="admin-filter-summary">
              <span><?= count($filteredNews) ?> posts shown</span>
              <small>Use search to move quickly through the editorial library before opening the editor.</small>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit">Apply</button><a class="admin-ghost" href="<?= admin_html(admin_url('news')) ?>">Reset</a></div>
          </form>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Post</th><th>Author</th><th>Date</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($filteredNews as $post): ?>
                  <tr>
                    <td><strong><?= admin_html($post['title']) ?></strong></td>
                    <td><?= admin_html($post['author']) ?></td>
                    <td><?= admin_html($post['date']) ?></td>
                    <td><div class="admin-action-row"><a class="admin-icon-link" href="<?= admin_html(admin_url('news', ['news_edit' => $post['id']])) ?>"><i class="fas fa-pen"></i></a><?php admin_table_button('Delete', 'delete-news', ['news_id' => $post['id']], 'admin-mini-btn danger'); ?></div></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
        <?php if ($newsCreate || $editingNews !== null): ?>
          <section class="admin-panel admin-editor-panel" id="news-editor">
            <div class="admin-panel-head"><div><p class="admin-kicker">Editor</p><h3><?= $editingNews ? 'Edit post' : 'Create post' ?></h3></div></div>
            <?php admin_form_open('news', $editingNews ? 'update-news' : 'create-news', true); ?>
            <?php if ($editingNews): ?><input type="hidden" name="news_id" value="<?= admin_html($editingNews['id']) ?>"><?php endif; ?>
            <div class="admin-product-editor admin-news-editor">
              <div class="admin-product-main">
                <div class="admin-product-card">
                  <div class="admin-product-card-head">
                    <div>
                      <p class="admin-kicker">Story Composition</p>
                      <h4>Headline and article body</h4>
                    </div>
                  </div>
                  <?php admin_input('news_item[title]', 'News Title', $editingNews['title'] ?? '', 'text', 'placeholder="Write the headline readers will see on the card and article page"'); ?>
                  <?php admin_richtext('news_item[body]', 'Article Content', $editingNews['body'] ?? '', 'Card summary text is generated automatically from the article content. Use the toolbar for headings, bold text, lists, quotes, links, and separators.'); ?>
                </div>
              </div>
              <aside class="admin-product-sidebar admin-news-sidebar">
                <div class="admin-product-side-card">
                  <h4>Publishing Details</h4>
                  <div class="admin-grid one-up">
                    <?php admin_input('news_item[author]', 'Author', $editingNews['author'] ?? '', 'text', 'placeholder="Editorial team or writer name"'); ?>
                    <?php admin_input('news_item[date]', 'Date', $editingNews['date'] ?? '', 'text', 'placeholder="18 May 2026"'); ?>
                    <?php admin_input('news_item[alt]', 'Image Alt', $editingNews['alt'] ?? '', 'text', 'placeholder="Describe the cover image for accessibility"'); ?>
                    <?php admin_input('news_image_url', 'Cover Image URL', $editingNews['image'] ?? '', 'text', '', 'Upload a premium editorial image or paste a direct URL.'); ?>
                    <?php admin_input('news_image_file', 'Upload Cover Image', '', 'file', 'accept="image/*"'); ?>
                  </div>
                </div>
                <div class="admin-product-side-card">
                  <h4>Editor Guide</h4>
                  <p class="admin-table-note">Structure each story with a strong headline, a short opening paragraph, clear section headings, and supporting lists or quotes where needed. The homepage summary is generated automatically from your article body.</p>
                  <div class="admin-product-side-stats">
                    <article><span>Formatting</span><strong>Rich text</strong></article>
                    <article><span>Card Summary</span><strong>Auto</strong></article>
                  </div>
                </div>
              </aside>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit"><?= $editingNews ? 'Update Post' : 'Create Post' ?></button><a class="admin-ghost" href="<?= admin_html(admin_url('news')) ?>">Close</a></div>
            <?php admin_form_close(); ?>
          </section>
        <?php endif; ?>
      <?php elseif ($view === 'newsletter'): ?>
        <section class="admin-page-hero">
          <div>
            <p class="admin-kicker">Our Newsletter</p>
            <h2>Subscriber records</h2>
            <p>Track every newsletter signup with the linked account holder when available, then export a clean CSV in the format your team needs.</p>
          </div>
          <div class="admin-mini-stats">
            <article><span>Total Subscribers</span><strong><?= count($newsletterSubscribers) ?></strong></article>
            <article><span>Account Linked</span><strong><?= $newsletterLinkedCount ?></strong></article>
            <article><span>Guest Emails</span><strong><?= $newsletterGuestCount ?></strong></article>
            <article><span>Filtered</span><strong><?= count($filteredNewsletterSubscribers) ?></strong></article>
          </div>
        </section>
        <section class="admin-panel" id="site-newsletter">
          <div class="admin-panel-head"><div><p class="admin-kicker">Homepage</p><h3>Banner Image Section</h3></div></div>
          <?php admin_form_open('newsletter', 'save-newsletter', true); ?>

          <div class="admin-grid two-up">
            <?php admin_input('newsletter_image_url', 'Background Media URL', '', 'text', '', 'Paste a URL or upload a file (' . admin_upload_hint() . ').'); ?>
            <label class="admin-field">
              <span>Upload Media (replaces current)</span>
              <input type="file" name="newsletter_image_file" accept="image/*,video/mp4,video/webm,video/ogg,video/quicktime">
            </label>
          </div>
          <?php if (!empty($content['newsletter']['image'])): ?>
            <div class="admin-field" style="margin-top:20px;">
              <span>Current Media</span>
              <div style="margin-top:8px; margin-bottom:12px;">
                <?php if (media_asset_type($content['newsletter']['image']) === 'video'): ?>
                  <video src="<?= h($content['newsletter']['image']) ?>" style="max-height:150px; border-radius:6px; border:1px solid #ddd;" muted playsinline></video>
                <?php else: ?>
                  <img src="<?= h($content['newsletter']['image']) ?>" style="max-height:150px; border-radius:6px; border:1px solid #ddd;" alt="">
                <?php endif; ?>
              </div>
              <label class="admin-check">
                <input type="checkbox" name="newsletter_image_delete" value="1">
                <span>Delete current media</span>
              </label>
            </div>
          <?php endif; ?>

          <div class="admin-actions"><button class="admin-primary" type="submit">Save Banner</button></div>
          <?php admin_form_close(); ?>
        </section>

        <section class="admin-panel">
          <form method="get" action="<?= admin_html(admin_url('newsletter')) ?>" class="admin-filter-bar">
            <input type="hidden" name="view" value="newsletter">
            <?php admin_input('newsletter_q', 'Search Subscribers', $newsletterQuery, 'text', 'placeholder="Search name or email"'); ?>
            <?php admin_select('newsletter_export', 'CSV Format', $newsletterExportFormat, admin_newsletter_csv_format_options(), '', 'Choose whether the CSV should contain only newsletter emails or both account holder names and newsletter emails.'); ?>
            <div class="admin-filter-summary">
              <span><?= count($filteredNewsletterSubscribers) ?> subscriber<?= count($filteredNewsletterSubscribers) === 1 ? '' : 's' ?> ready</span>
              <small>Download uses the current filtered result set, so you can narrow the export before generating the CSV.</small>
            </div>
            <div class="admin-actions">
              <button class="admin-primary" type="submit">Apply</button>
              <button class="admin-ghost" type="submit" name="download" value="newsletter-subscribers">Download CSV</button>
              <a class="admin-ghost" href="<?= admin_html(admin_url('newsletter')) ?>">Reset</a>
            </div>
          </form>

          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Account Holder</th>
                  <th>Account Email</th>
                  <th>Newsletter Email</th>
                  <th>Source</th>
                  <th>Subscribed On</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($filteredNewsletterSubscribers as $subscriber): ?>
                  <?php
                    $accountHolderName = clean_string((string) ($subscriber['account_holder_name'] ?? ''), 120);
                    if ($accountHolderName === '') {
                        $accountHolderName = 'Guest visitor';
                    }
                    $accountHolderEmail = clean_string((string) ($subscriber['account_holder_email'] ?? ''), 120);
                  ?>
                  <tr>
                    <td><strong><?= admin_html($accountHolderName) ?></strong></td>
                    <td><?= admin_html($accountHolderEmail !== '' ? $accountHolderEmail : '—') ?></td>
                    <td><?= admin_html((string) ($subscriber['subscribed_email'] ?? '')) ?></td>
                    <td><span class="status-pill"><?= admin_html(admin_newsletter_source_label((string) ($subscriber['source'] ?? 'guest'))) ?></span></td>
                    <td><?= admin_html((string) ($subscriber['subscribed_at'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if ($filteredNewsletterSubscribers === []): ?>
                  <tr>
                    <td colspan="5">No newsletter subscribers matched this filter.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php elseif ($view === 'customers'): ?>
        <section class="admin-page-hero"><div><p class="admin-kicker">Registered Users</p><h2>Accounts</h2><p>Search and moderate customers from one queue instead of scanning the full account table.</p></div><div class="admin-mini-stats"><article><span>Total Users</span><strong><?= count($allCustomers) ?></strong></article><article><span>Banned</span><strong><?= count(array_filter($allCustomers, static fn (array $item): bool => strtolower((string) ($item['status'] ?? '')) === 'banned')) ?></strong></article><article><span>Filtered</span><strong><?= count($filteredCustomers) ?></strong></article></div></section>
        <section class="admin-panel">
          <form method="get" action="<?= admin_html(admin_url('customers')) ?>" class="admin-filter-bar admin-filter-bar-simple">
            <input type="hidden" name="view" value="customers">
            <?php admin_input('customer_q', 'Search Users', $customerQuery, 'text', 'placeholder="Search name, email, city"'); ?>
            <?php admin_select('customer_status', 'Status', $customerStatusFilter, ['' => 'All Statuses', 'active' => 'Active', 'paused' => 'Paused', 'banned' => 'Banned']); ?>
            <div class="admin-filter-summary">
              <span><?= count($filteredCustomers) ?> users shown</span>
              <small>Filter the account list before taking moderation actions.</small>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit">Apply</button><a class="admin-ghost" href="<?= admin_html(admin_url('customers')) ?>">Reset</a></div>
          </form>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>User</th><th>Email</th><th>City</th><th>Status</th><th>Orders</th><th>Spent</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($filteredCustomers as $user): ?>
                  <tr>
                    <td><strong><?= admin_html($user['name']) ?></strong></td>
                    <td><?= admin_html($user['email']) ?></td>
                    <td><?= admin_html($user['city']) ?></td>
                    <td><span class="status-pill"><?= admin_html($user['status']) ?></span></td>
                    <td><?= admin_html($user['total_orders']) ?></td>
                    <td><?= admin_html($user['total_spent']) ?></td>
                    <td>
                      <div class="admin-action-row">
                        <?php if (strtolower((string) $user['status']) === 'banned'): ?>
                          <?php admin_table_button('Unban', 'unban-customer', ['customer_id' => $user['id']]); ?>
                        <?php else: ?>
                          <?php admin_table_button('Ban', 'ban-customer', ['customer_id' => $user['id']], 'admin-mini-btn warn'); ?>
                        <?php endif; ?>
                        <?php admin_table_button('Delete', 'delete-customer', ['customer_id' => $user['id']], 'admin-mini-btn danger'); ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php elseif ($view === 'orders'): ?>
        <section class="admin-page-hero"><div><p class="admin-kicker">Orders</p><h2>Order operations</h2><p>Filter the fulfilment queue by order state or request state, then process the exact records that need action.</p></div><div class="admin-mini-stats"><article><span>Total Orders</span><strong><?= count($allOrders) ?></strong></article><article><span>New</span><strong><?= count(array_filter($allOrders, static fn (array $item): bool => order_status_normalize((string) ($item['status'] ?? '')) === 'received')) ?></strong></article><article><span>Open Requests</span><strong><?= count(array_filter($allOrders, static fn (array $item): bool => in_array(strtolower((string) ($item['customer_request_status'] ?? '')), ['pending', 'approved'], true))) ?></strong></article><article><span>Filtered</span><strong><?= count($filteredOrders) ?></strong></article></div></section>
        <section class="admin-panel">
          <form method="get" action="<?= admin_html(admin_url('orders')) ?>" class="admin-filter-bar">
            <input type="hidden" name="view" value="orders">
            <?php admin_input('order_q', 'Search Orders', $orderQuery, 'text', 'placeholder="Search order id, customer, email"'); ?>
            <?php admin_select('order_status', 'Order Status', $orderStatusFilter, ['' => 'All Statuses'] + order_status_options() + ['returned' => 'Returned']); ?>
            <?php admin_select('request_status', 'Request Status', $orderRequestFilter, ['' => 'All Requests', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'completed' => 'Completed']); ?>
            <?php admin_select('request_type', 'Request Type', $orderRequestTypeFilter, ['' => 'All Types', 'cancel' => 'Cancellation', 'return' => 'Return']); ?>
            <div class="admin-filter-summary">
              <span><?= count($filteredOrders) ?> orders shown</span>
              <small>Use filters to isolate fulfilment work, refunds, cancellations, and return requests.</small>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit">Apply Filters</button><a class="admin-ghost" href="<?= admin_html(admin_url('orders')) ?>">Reset</a></div>
          </form>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Order</th><th>Customer</th><th>Order Status</th><th>Request</th><th>Tracking ID</th><th>Payment Status</th><th>Total</th><th>Placed</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($filteredOrders as $order): ?>
                  <?php $requestSummary = order_customer_request_summary($order); ?>
                  <tr>
                    <td><strong><?= admin_html($order['id']) ?></strong></td>
                    <td><?= admin_html($order['customer_name']) ?></td>
                    <td><span class="status-pill"><?= admin_html(order_status_label((string) ($order['status'] ?? ''))) ?></span></td>
                    <td>
                      <?php if (is_array($requestSummary)): ?>
                        <div class="admin-request-stack">
                          <span class="status-pill"><?= admin_html($requestSummary['label']) ?></span>
                          <?php if (($requestSummary['reason'] ?? '') !== ''): ?><small><?= admin_html(clean_string($requestSummary['reason'], 120)) ?></small><?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="admin-muted">No request</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ((string) ($order['tracking_id'] ?? '') !== ''): ?>
                        <strong><?= admin_html((string) $order['tracking_id']) ?></strong>
                      <?php else: ?>
                        <span class="admin-muted">Not issued</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="status-pill"><?= admin_html($order['payment_status']) ?></span></td>
                    <td><?= admin_html($order['total']) ?></td>
                    <td><?= admin_html($order['placed_at']) ?></td>
                    <td>
                      <div class="admin-action-row admin-action-wrap">
                        <?php if (is_array($requestSummary) && ($requestSummary['status'] ?? '') === 'pending'): ?>
                          <?php admin_table_button('Approve Request', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'approve']); ?>
                          <?php admin_table_button('Reject Request', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'reject'], 'admin-mini-btn warn'); ?>
                        <?php elseif (is_array($requestSummary) && ($requestSummary['status'] ?? '') === 'approved'): ?>
                          <?php admin_table_button('Complete Request', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'complete']); ?>
                          <?php admin_table_button('Reject Request', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'reject'], 'admin-mini-btn warn'); ?>
                        <?php else: ?>
                          <?php $orderStatusValue = order_status_normalize((string) ($order['status'] ?? '')); ?>
                          <form method="post" action="<?= admin_html(admin_url('orders')) ?>" class="admin-status-form" data-order-status-form>
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="mark-order-status">
                            <input type="hidden" name="return_view" value="orders">
                            <input type="hidden" name="order_id" value="<?= admin_html((string) $order['id']) ?>">
                            <select name="status" class="admin-status-select" data-order-status-select aria-label="Order status for <?= admin_html((string) $order['id']) ?>">
                              <?php foreach (order_status_options() as $statusValue => $statusLabel): ?>
                                <option value="<?= admin_html($statusValue) ?>" <?= $orderStatusValue === $statusValue ? 'selected' : '' ?>><?= admin_html($statusLabel) ?></option>
                              <?php endforeach; ?>
                              <?php if (!isset(order_status_options()[$orderStatusValue])): ?>
                                <option value="<?= admin_html($orderStatusValue) ?>" selected><?= admin_html(order_status_label($orderStatusValue)) ?></option>
                              <?php endif; ?>
                            </select>
                            <label class="admin-status-tracking" data-order-tracking-field <?= in_array($orderStatusValue, order_tracking_statuses(), true) ? '' : 'hidden' ?>>
                              <span>Tracking ID</span>
                              <input type="text" name="tracking_id" value="<?= admin_html((string) ($order['tracking_id'] ?? '')) ?>" placeholder="e.g. AZ-TRK-24001" maxlength="120">
                            </label>
                            <button type="submit" class="admin-mini-btn">Update</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
        <section class="admin-panel" id="cancellations-returns">
          <div class="admin-panel-head">
            <p class="admin-kicker">Aftersales</p>
            <h3>Cancellations &amp; Returns</h3>
            <p class="admin-table-note"><?= (int) $cancelReturnOpenCount ?> open request<?= $cancelReturnOpenCount === 1 ? '' : 's' ?> awaiting a decision. Completed and rejected records stay listed for reference.</p>
          </div>
          <?php if ($cancelReturnOrders === []): ?>
            <p class="admin-muted">No cancellation or return activity yet.</p>
          <?php else: ?>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Type</th><th>Request Status</th><th>Reason</th><th>Requested</th><th>Resolved</th><th>Order Status</th><th>Refund</th><th>Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($cancelReturnOrders as $order): ?>
                    <?php
                      $aftersaleSummary  = order_customer_request_summary($order);
                      $aftersaleStatus   = is_array($aftersaleSummary) ? (string) ($aftersaleSummary['status'] ?? '') : '';
                      $aftersaleType     = is_array($aftersaleSummary)
                          ? (string) ($aftersaleSummary['type'] ?? '')
                          : (order_status_normalize((string) ($order['status'] ?? '')) === 'returned' ? 'return' : 'cancel');
                      $aftersalePayStat  = strtolower((string) ($order['payment_status'] ?? ''));
                      $alreadyRefunded   = $aftersalePayStat === 'refunded';

                      // Eligibility for the Process Refund button (super admin only)
                      $refundEligibleCancel = $aftersaleType === 'cancel'
                          && $aftersaleStatus === 'approved'
                          && $aftersalePayStat === 'refund-pending';
                      $refundEligibleReturn = $aftersaleType === 'return'
                          && $aftersaleStatus === 'completed'
                          && in_array($aftersalePayStat, ['paid', 'refund-pending'], true)
                          && !$alreadyRefunded;
                      $showRefundButton  = admin_is_super_portal()
                          && ($refundEligibleCancel || $refundEligibleReturn);

                      $refundBreakdown   = ($showRefundButton || $alreadyRefunded)
                          ? order_calculate_refund($order)
                          : null;
                    ?>
                    <tr>
                      <td><strong><?= admin_html((string) $order['id']) ?></strong></td>
                      <td>
                        <?= admin_html((string) ($order['customer_name'] ?? '')) ?>
                        <?php if (($order['customer_email'] ?? '') !== ''): ?><br><small><?= admin_html((string) $order['customer_email']) ?></small><?php endif; ?>
                      </td>
                      <td><span class="status-pill"><?= $aftersaleType === 'return' ? 'Return' : 'Cancellation' ?></span></td>
                      <td>
                        <?php if (is_array($aftersaleSummary)): ?>
                          <span class="status-pill"><?= admin_html((string) $aftersaleSummary['label']) ?></span>
                        <?php else: ?>
                          <span class="admin-muted">Admin action</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (is_array($aftersaleSummary) && ($aftersaleSummary['reason'] ?? '') !== ''): ?>
                          <?= admin_html(clean_string((string) $aftersaleSummary['reason'], 200)) ?>
                        <?php else: ?>
                          <span class="admin-muted">Not provided</span>
                        <?php endif; ?>
                      </td>
                      <td><?= admin_html(is_array($aftersaleSummary) ? (string) ($aftersaleSummary['requested_at_formatted'] ?? '') : '') ?></td>
                      <td>
                        <?php $resolvedLabel = is_array($aftersaleSummary) ? (string) ($aftersaleSummary['resolved_at_formatted'] ?? '') : ''; ?>
                        <?php if ($resolvedLabel !== ''): ?>
                          <?= admin_html($resolvedLabel) ?>
                        <?php else: ?>
                          <span class="admin-muted">Pending</span>
                        <?php endif; ?>
                      </td>
                      <td><span class="status-pill"><?= admin_html(order_status_label((string) ($order['status'] ?? ''))) ?></span></td>
                      <td>
                        <?php if ($alreadyRefunded): ?>
                          <div style="line-height:1.5;">
                            <span class="status-pill" style="background:#d4edda;color:#1a5c30;border-color:#b8dfc4;">Refunded <?= admin_html((string) ($order['refunded_amount'] ?? '')) ?></span>
                            <?php if ((string) ($order['refunded_at'] ?? '') !== ''): ?>
                              <br><small style="color:#6b6b6b;"><?= admin_html((string) $order['refunded_at']) ?></small>
                            <?php endif; ?>
                            <?php if ((string) ($order['refund_id'] ?? '') !== ''): ?>
                              <br><small style="color:#9a948a;font-size:0.7rem;"><?= admin_html((string) $order['refund_id']) ?></small>
                            <?php endif; ?>
                          </div>
                        <?php elseif ($refundBreakdown !== null): ?>
                          <div style="line-height:1.6;">
                            <strong><?= admin_html($refundBreakdown['refund_amount_label']) ?></strong>
                            <?php if ($refundBreakdown['deducted_express']): ?>
                              <br><small style="color:#9a948a;"><?= admin_html($refundBreakdown['total_label']) ?> &minus; <?= admin_html($refundBreakdown['express_fee_label']) ?> express fee</small>
                            <?php else: ?>
                              <br><small style="color:#9a948a;">Full refund</small>
                            <?php endif; ?>
                          </div>
                        <?php else: ?>
                          <span class="status-pill"><?= admin_html($aftersalePayStat) ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="admin-action-row admin-action-wrap">
                          <?php if ($aftersaleStatus === 'pending'): ?>
                            <?php admin_table_button('Approve', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'approve']); ?>
                            <?php admin_table_button('Reject', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'reject'], 'admin-mini-btn warn'); ?>
                          <?php elseif ($aftersaleStatus === 'approved'): ?>
                            <?php if ($showRefundButton && $aftersaleType === 'cancel'): ?>
                              <?php /* Cancel approved + paid: refund button replaces Complete */ ?>
                              <form method="post" action="<?= admin_html(admin_url('orders')) ?>" style="display:inline;" onsubmit="return confirm('Issue a Stripe refund of <?= admin_html($refundBreakdown['refund_amount_label'] ?? '') ?> to the customer? This cannot be undone.');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="process-order-refund">
                                <input type="hidden" name="return_view" value="orders">
                                <input type="hidden" name="order_id" value="<?= admin_html((string) $order['id']) ?>">
                                <button type="submit" class="admin-mini-btn" style="background:#1a5c30;border-color:#1a5c30;color:#fff;">Refund <?= admin_html($refundBreakdown['refund_amount_label'] ?? '') ?></button>
                              </form>
                            <?php else: ?>
                              <?php /* Cancel approved + not paid (awaiting), or return approved: Complete button */ ?>
                              <?php admin_table_button('Complete', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'complete']); ?>
                            <?php endif; ?>
                            <?php admin_table_button('Reject', 'resolve-order-request', ['order_id' => $order['id'], 'resolution' => 'reject'], 'admin-mini-btn warn'); ?>
                          <?php elseif ($aftersaleStatus === 'completed' && $showRefundButton): ?>
                            <?php /* Return completed (pickup verified): show refund button */ ?>
                            <form method="post" action="<?= admin_html(admin_url('orders')) ?>" style="display:inline;" onsubmit="return confirm('Issue a Stripe refund of <?= admin_html($refundBreakdown['refund_amount_label'] ?? '') ?> to the customer? This cannot be undone.');">
                              <?php csrf_field(); ?>
                              <input type="hidden" name="action" value="process-order-refund">
                              <input type="hidden" name="return_view" value="orders">
                              <input type="hidden" name="order_id" value="<?= admin_html((string) $order['id']) ?>">
                              <button type="submit" class="admin-mini-btn" style="background:#1a5c30;border-color:#1a5c30;color:#fff;">Refund <?= admin_html($refundBreakdown['refund_amount_label'] ?? '') ?></button>
                            </form>
                          <?php else: ?>
                            <span class="admin-muted">Closed</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php elseif ($view === 'appointments'): ?>
        <div class="ap-adm">
        <style>
          .ap-adm { --ap-gold:#c9a96e; --ap-gold-deep:#b08a4f; --ap-green:#143b32; --ap-ink:#22302b; --ap-muted:#7c766b; --ap-line:#e7e1d6; --ap-bg:#fbf9f4; --ap-serif:'Playfair Display',Georgia,serif; --ap-sans:'Jost','Montserrat','Outfit',system-ui,sans-serif; }
          .ap-adm .admin-page-hero { background:linear-gradient(120deg,#fdfbf7 0%,#f4eee2 100%); border:1px solid var(--ap-line); border-radius:18px; }
          .ap-adm .admin-page-hero h2 { font-family:var(--ap-serif); color:var(--ap-green); font-weight:500; }
          .ap-adm .admin-page-hero > div > p { color:var(--ap-muted); font-family:var(--ap-sans); }
          .ap-adm .admin-mini-stats article { border-radius:14px; }
          .ap-adm .admin-mini-stats span { font-family:var(--ap-sans); letter-spacing:.08em; }
          .ap-adm .admin-mini-stats strong { font-family:var(--ap-serif); color:var(--ap-green); }
          .ap-adm .admin-anchor-nav { border:none; gap:6px; margin:0 0 24px; }
          .ap-adm .admin-anchor-nav a { border-radius:9px; padding:9px 22px; font-family:var(--ap-sans); font-weight:600; letter-spacing:.04em; color:var(--ap-muted); text-decoration:none; transition:.2s; }
          .ap-adm .admin-anchor-nav a:hover { color:var(--ap-ink); background:var(--ap-bg); }
          .ap-adm .admin-anchor-nav a.is-active { background:linear-gradient(135deg,var(--ap-gold),var(--ap-gold-deep)); color:#fff; box-shadow:0 4px 12px rgba(176,138,79,.22); }
          .ap-adm .admin-panel { border-radius:18px; border:1px solid var(--ap-line); }
          .ap-adm .admin-panel-head .admin-kicker { color:var(--ap-gold-deep); }
          .ap-adm .admin-panel-head h3 { font-family:var(--ap-serif); color:var(--ap-green); font-weight:500; }
          .ap-adm .admin-table-note { font-family:var(--ap-sans); }
          /* every control inside the view is styled so nothing renders as a bare browser default */
          .ap-adm input[type="text"], .ap-adm input[type="number"], .ap-adm input[type="email"], .ap-adm input[type="time"], .ap-adm input[type="date"], .ap-adm select, .ap-adm textarea {
            font-family:var(--ap-sans); font-size:.92rem; color:var(--ap-ink); padding:10px 12px; border:1px solid var(--ap-line); border-radius:9px; background:#fff; transition:border-color .2s, box-shadow .2s; width:100%; box-sizing:border-box;
          }
          .ap-adm input:focus, .ap-adm select:focus, .ap-adm textarea:focus { outline:none; border-color:var(--ap-gold); box-shadow:0 0 0 3px rgba(201,169,110,.15); }
          .ap-adm input[type="time"], .ap-adm input[type="date"] { width:auto; min-width:140px; }
          .ap-adm input[type="checkbox"] { width:18px; height:18px; accent-color:var(--ap-gold); cursor:pointer; }
          .ap-adm .admin-table th { font-family:var(--ap-sans); font-size:.7rem; letter-spacing:.12em; text-transform:uppercase; color:var(--ap-muted); }
          .ap-adm .admin-table td { font-family:var(--ap-sans); color:var(--ap-ink); }
          .ap-adm .admin-table td strong { color:var(--ap-ink); }
          .ap-adm .status-pill { font-family:var(--ap-sans); }
          .ap-adm .ap-adm-emailsent { background:#e7f4ec; color:#1e6b45; }
          .ap-adm .admin-action-row { gap:6px; }
          /* closed-dates calendar picker */
          .ap-adm-closed-add { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
          .ap-adm-closed-add input[type="date"] { min-width:180px; }
          .ap-adm-addbtn { font-family:var(--ap-sans); font-size:.76rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; padding:11px 20px; border:none; border-radius:9px; background:linear-gradient(135deg,var(--ap-gold),var(--ap-gold-deep)); color:#fff; cursor:pointer; transition:.2s; box-shadow:0 4px 12px rgba(176,138,79,.22); }
          .ap-adm-addbtn:hover { filter:brightness(1.06); }
          .ap-adm-chips { display:flex; flex-wrap:wrap; gap:10px; }
          .ap-adm-chip { display:inline-flex; align-items:center; gap:9px; padding:8px 8px 8px 15px; border-radius:999px; background:var(--ap-bg); border:1px solid var(--ap-line); font-family:var(--ap-sans); font-size:.85rem; color:var(--ap-ink); }
          .ap-adm-chip button { border:none; background:transparent; color:var(--ap-muted); cursor:pointer; font-size:1.05rem; line-height:1; padding:0 3px; border-radius:50%; transition:.2s; }
          .ap-adm-chip button:hover { color:#c0392b; }
          .ap-adm-empty { color:var(--ap-muted); font-family:var(--ap-sans); font-size:.88rem; font-style:italic; }
          .ap-adm .admin-actions .admin-primary { background:linear-gradient(135deg,var(--ap-green),#1f4d40); border:none; padding:13px 30px; border-radius:11px; font-family:var(--ap-sans); font-weight:700; letter-spacing:.06em; box-shadow:0 6px 16px rgba(20,59,50,.18); }
          .ap-adm .admin-actions .admin-primary:hover { filter:brightness(1.12); }
        </style>
        <section class="admin-page-hero">
          <div><p class="admin-kicker">Appointments</p><h2>Bookings &amp; availability</h2><p>Review consultation bookings, change their status, resend confirmations, and control which days and times customers can reserve.</p></div>
          <div class="admin-mini-stats">
            <article><span>Total Bookings</span><strong><?= count($apBookings) ?></strong></article>
            <article><span>Today</span><strong><?= $apTodayCount ?></strong></article>
            <article><span>Upcoming</span><strong><?= count($apUpcoming) ?></strong></article>
            <article><span>Cancelled</span><strong><?= $apCancelledCount ?></strong></article>
          </div>
        </section>
        <section class="admin-anchor-nav">
          <a href="<?= admin_html(admin_url('appointments', ['ap_tab' => 'bookings'])) ?>" class="<?= $apTab === 'bookings' ? 'is-active' : '' ?>">Bookings</a>
          <a href="<?= admin_html(admin_url('appointments', ['ap_tab' => 'settings'])) ?>" class="<?= $apTab === 'settings' ? 'is-active' : '' ?>">Availability Settings</a>
        </section>

        <?php if ($apTab === 'bookings'): ?>
        <section class="admin-panel">
          <form method="get" action="<?= admin_html(admin_url('appointments')) ?>" class="admin-filter-bar">
            <input type="hidden" name="view" value="appointments">
            <input type="hidden" name="ap_tab" value="bookings">
            <?php admin_input('ap_q', 'Search Bookings', $apQuery, 'text', 'placeholder="Search ref, name, email, mobile"'); ?>
            <?php admin_select('ap_status', 'Status', $apStatusFilter, ['' => 'All Statuses', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled']); ?>
            <?php
              $apServiceOptions = ['' => 'All Services'];
              foreach ($apServices as $apSvc) { $apServiceOptions[$apSvc['key']] = (string) $apSvc['label']; }
            ?>
            <?php admin_select('ap_service', 'Service', $apServiceFilter, $apServiceOptions); ?>
            <div class="admin-filter-summary">
              <span><?= count($filteredBookings) ?> bookings shown</span>
              <small>Bookings are sorted newest first. Use the actions to confirm, complete, cancel, or resend the confirmation email.</small>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit">Apply Filters</button><a class="admin-ghost" href="<?= admin_html(admin_url('appointments', ['ap_tab' => 'bookings'])) ?>">Reset</a></div>
          </form>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Reference</th><th>Service</th><th>Date &amp; Time</th><th>Customer</th><th>Contact</th><th>Status</th><th>Email</th><th>Actions</th></tr></thead>
              <tbody>
                <?php if ($filteredBookings === []): ?>
                  <tr><td colspan="8" class="admin-muted" style="padding:24px; text-align:center;">No bookings match these filters yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($filteredBookings as $apB):
                  $apBStatus = strtolower((string) ($apB['status'] ?? 'confirmed'));
                  $apBEmailSent = !empty($apB['confirmation_email_sent']);
                ?>
                  <tr>
                    <td><strong><?= admin_html((string) ($apB['ref'] ?? '')) ?></strong></td>
                    <td><?= admin_html((string) ($apB['service_label'] ?? '')) ?></td>
                    <td><?= admin_html(($apB['date'] ?? '') !== '' ? appointment_format_date_long((string) $apB['date']) : '') ?><br><small><?= admin_html(($apB['time'] ?? '') !== '' ? appointment_format_time_12((string) $apB['time']) . ' · ' . (int) ($apB['duration'] ?? 60) . ' min' : '') ?></small></td>
                    <td><?= admin_html(trim((string) ($apB['first_name'] ?? '') . ' ' . (string) ($apB['last_name'] ?? ''))) ?><?php if (($apB['notes'] ?? '') !== ''): ?><br><small class="admin-muted"><?= admin_html(clean_string((string) $apB['notes'], 80)) ?></small><?php endif; ?></td>
                    <td><?= admin_html((string) ($apB['email'] ?? '')) ?><br><small><?= admin_html(trim((string) ($apB['country_code'] ?? '') . ' ' . (string) ($apB['mobile'] ?? ''))) ?></small></td>
                    <td><span class="status-pill"><?= admin_html($apBStatus) ?></span></td>
                    <td><?php if ($apBEmailSent): ?><span class="status-pill">sent ✓</span><?php else: ?><span class="admin-muted">—</span><?php endif; ?></td>
                    <td>
                      <div class="admin-action-row admin-action-wrap">
                        <?php if ($apBStatus === 'confirmed'): ?>
                          <?php admin_table_button('Complete', 'appointment-complete', ['appointment_id' => $apB['id']]); ?>
                          <?php admin_table_button('Cancel', 'appointment-cancel', ['appointment_id' => $apB['id']], 'admin-mini-btn warn'); ?>
                        <?php elseif ($apBStatus === 'cancelled'): ?>
                          <?php admin_table_button('Re-confirm', 'appointment-confirm', ['appointment_id' => $apB['id']]); ?>
                        <?php else: ?>
                         <span class="admin-muted">Closed</span>
                        <?php endif; ?>
                        <?php admin_table_button('Resend Email', 'appointment-resend-email', ['appointment_id' => $apB['id']]); ?>
                        <?php admin_table_button('Delete', 'appointment-delete', ['appointment_id' => $apB['id']], 'admin-mini-btn danger'); ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <?php else: ?>
        <?php admin_form_open('appointments', 'save-appointment-settings'); ?>
        <section class="admin-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">General</p><h3>Booking rules</h3></div></div>
          <p class="admin-table-note">The essentials that control when and how far ahead customers can book. Slot granularity and capacity use sensible defaults (15‑minute slots, one booking at a time).</p>
          <div class="admin-grid three-up">
            <?php admin_input('ap_config[default_duration]', 'Default Duration (min)', (string) $apConfig['default_duration'], 'number', 'min="15"', 'Length of a consultation when no per‑service override exists.'); ?>
            <?php admin_input('ap_config[lead_time_hours]', 'Lead Time (hours)', (string) $apConfig['lead_time_hours'], 'number', 'min="0"', 'Customers cannot book within this many hours of now.'); ?>
            <?php admin_input('ap_config[max_advance_days]', 'Bookable Window (days)', (string) $apConfig['max_advance_days'], 'number', 'min="1"', 'How far into the future the calendar opens.'); ?>
          </div>
        </section>

        <section class="admin-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">Opening Hours</p><h3>Weekly schedule</h3></div></div>
          <p class="admin-table-note">Set open and close times per day, or tick "Closed" to make a day completely unavailable in the booking calendar.</p>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Day</th><th>Closed</th><th>Opens</th><th>Closes</th></tr></thead>
              <tbody>
                <?php foreach (['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'] as $apDk => $apDLabel):
                  $apWd = (array) ($apConfig['weekdays'][$apDk] ?? []);
                ?>
                  <tr>
                    <td><strong><?= admin_html($apDLabel) ?></strong></td>
                    <td><label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;"><input type="hidden" name="ap_config[weekdays][<?= $apDk ?>][closed]" value="0"><input type="checkbox" name="ap_config[weekdays][<?= $apDk ?>][closed]" value="1" <?= !empty($apWd['closed']) ? 'checked' : '' ?>> <span style="font-size:.82rem;color:var(--ap-muted);">Closed</span></label></td>
                    <td><input type="time" name="ap_config[weekdays][<?= $apDk ?>][open]" value="<?= admin_html((string) ($apWd['open'] ?? '10:00')) ?>"></td>
                    <td><input type="time" name="ap_config[weekdays][<?= $apDk ?>][close]" value="<?= admin_html((string) ($apWd['close'] ?? '18:00')) ?>"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="admin-panel">
          <div class="admin-panel-head"><div><p class="admin-kicker">Closed Dates</p><h3>Holidays &amp; special closures</h3></div></div>
          <p class="admin-table-note">Pick individual dates from the calendar to mark them fully closed (e.g. bank holidays, showroom events). Customers will see these days as unavailable.</p>
          <?php $apClosedDates = array_values((array) ($apConfig['blackout_dates'] ?? [])); sort($apClosedDates); ?>
          <div class="ap-adm-closed-add">
            <input type="date" id="ap-adm-new-date" min="<?= date('Y-m-d') ?>">
            <button type="button" class="ap-adm-addbtn" id="ap-adm-add-date"><i class="fas fa-plus" style="margin-right:6px;"></i>Add Closed Date</button>
          </div>
          <div class="ap-adm-chips" id="ap-adm-closed-chips">
            <?php if ($apClosedDates === []): ?>
              <span class="ap-adm-empty" id="ap-adm-no-dates">No closed dates set — all open days are bookable.</span>
            <?php else: ?>
              <?php foreach ($apClosedDates as $apCd): ?>
                <span class="ap-adm-chip" data-date="<?= admin_html($apCd) ?>">
                  <input type="hidden" name="ap_config[blackout_dates][]" value="<?= admin_html($apCd) ?>">
                  <?= admin_html($apCd !== '' ? appointment_format_date_long($apCd) : $apCd) ?>
                  <button type="button" aria-label="Remove <?= admin_html($apCd) ?>">&times;</button>
                </span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

        <div class="admin-actions"><button class="admin-primary" type="submit">Save Availability Settings</button></div>
        <?php admin_form_close(); ?>

        <script>
        (function () {
          var chips = document.getElementById('ap-adm-closed-chips');
          var addBtn = document.getElementById('ap-adm-add-date');
          var dateInput = document.getElementById('ap-adm-new-date');
          if (!chips || !addBtn || !dateInput) return;
          var noDates = document.getElementById('ap-adm-no-dates');

          function fmtDate(iso) {
            var d = new Date(iso + 'T12:00:00');
            if (isNaN(d)) return iso;
            var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            var day = d.getDate();
            var suffix = (day % 10 === 1 && day !== 11) ? 'st' : (day % 10 === 2 && day !== 12) ? 'nd' : (day % 10 === 3 && day !== 13) ? 'rd' : 'th';
            return days[d.getDay()] + ', ' + day + suffix + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
          }

          function addChip(iso) {
            if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return;
            if (chips.querySelector('[data-date="' + iso + '"]')) { dateInput.value = ''; return; }
            if (noDates) { noDates.remove(); noDates = null; }
            var span = document.createElement('span');
            span.className = 'ap-adm-chip';
            span.dataset.date = iso;
            span.innerHTML = '<input type="hidden" name="ap_config[blackout_dates][]" value="' + iso + '">' + fmtDate(iso) + ' <button type="button" aria-label="Remove ' + iso + '">&times;</button>';
            span.querySelector('button').addEventListener('click', function () { removeChip(span); });
            chips.appendChild(span);
            dateInput.value = '';
          }

          function removeChip(span) {
            span.remove();
            if (chips.querySelectorAll('.ap-adm-chip').length === 0) {
              var empty = document.createElement('span');
              empty.className = 'ap-adm-empty';
              empty.id = 'ap-adm-no-dates';
              empty.textContent = 'No closed dates set — all open days are bookable.';
              chips.appendChild(empty);
              noDates = empty;
            }
          }

          addBtn.addEventListener('click', function () { addChip(dateInput.value); });
          dateInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addChip(dateInput.value); } });
          chips.querySelectorAll('.ap-adm-chip button').forEach(function (btn) {
            btn.addEventListener('click', function () { removeChip(btn.closest('.ap-adm-chip')); });
          });
        })();
        </script>
        <?php endif; ?>
        </div>

      <?php elseif ($view === 'content'): ?>
        <section class="admin-page-hero"><div><p class="admin-kicker">Homepage Content</p><h2>Hero, cards, shapes, celebs</h2><p>Work through the homepage builder top to bottom so the storefront stays consistent: hero, content cards, shape navigation, then supporting social proof.</p></div><div class="admin-mini-stats"><article><span>Category Cards</span><strong><?= count($content['category_cards'] ?? []) ?></strong></article><article><span>Diamond Shapes</span><strong><?= count($content['diamond_shapes']['items'] ?? []) ?></strong></article><article><span>Celebs</span><strong><?= count($content['celebs']['items'] ?? []) ?></strong></article></div></section>
        <section class="admin-anchor-nav">
          <a href="#content-hero">Hero</a>
          <a href="#content-shapes">Shapes</a>
          <a href="#content-celebs">Celebs</a>
        </section>
        <section class="admin-panel" id="content-hero">
          <div class="admin-panel-head"><div><p class="admin-kicker">Hero</p><h3>Main hero</h3></div></div>
          <?php admin_form_open('content', 'save-hero', true); ?>
          <div class="admin-grid three-up">
            <?php admin_input('hero[offer]', 'Offer', $content['hero']['offer']); ?>
            <?php admin_input('hero[title]', 'Title', $content['hero']['title']); ?>
            <?php admin_input('hero[price_prefix]', 'Price Prefix', $content['hero']['price_prefix']); ?>
            <?php admin_input('hero[price_value]', 'Price Value', $content['hero']['price_value']); ?>
            <?php admin_input('hero[cta_label]', 'CTA Label', $content['hero']['cta_label']); ?>
            <?php admin_input('hero[cta_url]', 'CTA URL', $content['hero']['cta_url']); ?>
            <?php admin_input('hero_image_url', 'Hero Image URL', $content['hero']['image'], 'text', '', 'Upload or paste URL'); ?>
            <?php admin_input('hero_image_file', 'Hero Image File', '', 'file', 'accept="image/*"'); ?>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Hero</button></div>
          <?php admin_form_close(); ?>
        </section>



        <section class="admin-panel" id="content-shapes">
          <div class="admin-panel-head"><div><p class="admin-kicker">Diamond Shapes</p><h3>Shape list</h3></div><a class="admin-primary" href="<?= admin_html(admin_url('content', ['shape_form' => 'create'])) ?>">Add Shape</a></div>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Name</th><th>Label</th><th>URL</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($content['diamond_shapes']['items'] as $index => $shape): ?>
                  <tr>
                    <td><strong><?= admin_html($shape['name']) ?></strong></td>
                    <td><?= admin_html($shape['label']) ?></td>
                    <td><?= admin_html($shape['url']) ?></td>
                    <td><div class="admin-action-row"><a class="admin-icon-link" href="<?= admin_html(admin_url('content', ['shape_edit' => $index])) ?>"><i class="fas fa-pen"></i></a><?php admin_table_button('Delete', 'delete-shape', ['shape_index' => $index], 'admin-mini-btn danger'); ?></div></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <?php if ($shapeCreate || $editingShape !== null): ?>
          <section class="admin-panel" id="content-shape-editor">
            <div class="admin-panel-head"><div><p class="admin-kicker">Shape Editor</p><h3><?= $editingShape ? 'Edit shape' : 'Create shape' ?></h3></div></div>
            <?php admin_form_open('content', $editingShape ? 'update-shape' : 'create-shape', true); ?>
            <?php if ($editingShape): ?><input type="hidden" name="shape_index" value="<?= $shapeEditIndex ?>"><?php endif; ?>
            <div class="admin-grid three-up">
              <?php admin_input('shape[name]', 'Name', $editingShape['name'] ?? ''); ?>
              <?php admin_input('shape[label]', 'Label', $editingShape['label'] ?? ''); ?>
              <?php admin_input('shape[url]', 'URL', $editingShape['url'] ?? '#'); ?>
              <?php admin_input('shape_image_url', 'Image URL', $editingShape['image'] ?? '', 'text', '', 'Upload or paste URL'); ?>
              <?php admin_input('shape_image_file', 'Image File', '', 'file', 'accept="image/*"'); ?>
            </div>
            <?php admin_textarea('shape[description]', 'Description', $editingShape['description'] ?? '', 4); ?>
            <div class="admin-actions"><button class="admin-primary" type="submit"><?= $editingShape ? 'Update Shape' : 'Create Shape' ?></button><a class="admin-ghost" href="<?= admin_html(admin_url('content')) ?>">Close</a></div>
            <?php admin_form_close(); ?>
          </section>
        <?php endif; ?>

        <section class="admin-panel" id="content-celebs">
          <div class="admin-panel-head"><div><p class="admin-kicker">Celebs</p><h3>Celeb list</h3></div></div>
          <?php admin_form_open('content', 'save-celebs'); ?>
          <div class="admin-grid two-up"><?php admin_input('celebs[title]', 'Section Title', $content['celebs']['title']); ?></div>
          <div class="admin-repeater" data-repeater data-index-token="__CELEB_INDEX__" data-next-index="<?= count($content['celebs']['items']) ?>">
            <div class="admin-repeater-list">
              <?php foreach ($content['celebs']['items'] as $index => $item): ?>
                <div class="admin-repeater-item compact-item">
                  <div class="admin-item-head"><h4>Celeb</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                  <div class="admin-grid two-up">
                    <?php admin_input('celebs[items][' . $index . '][name]', 'Name', $item['name']); ?>
                    <?php admin_input('celebs[items][' . $index . '][image]', 'Image URL', $item['image']); ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button class="admin-add" type="button" data-add-item data-template="tpl-celeb-item">Add Celeb</button>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Celebs</button></div>
          <?php admin_form_close(); ?>
        </section>
      <?php elseif ($view === 'coupons'): ?>
        <section class="admin-page-hero"><div><p class="admin-kicker">Coupons</p><h2>Coupon manager</h2><p>Build offers with cleaner controls, then filter the coupon list by status or code before making changes.</p></div><div class="admin-mini-stats"><article><span>Total Coupons</span><strong><?= count($content['coupons']['items']) ?></strong></article><article><span>Active</span><strong><?= admin_metric_total($content['coupons']['items'], 'status', 'active') ?></strong></article><article><span>Filtered</span><strong><?= count($filteredCoupons) ?></strong></article></div></section>
        <section class="admin-anchor-nav">
          <a href="#coupon-library">Library</a>
          <a href="#coupon-editor">Editor</a>
        </section>
        <section class="admin-page-hero admin-page-hero-actions"><div><p class="admin-kicker">Promotions</p><h2>Manage offers</h2></div><div class="admin-top-actions"><a class="admin-primary" href="<?= admin_html(admin_url('coupons', ['coupon_form' => 'create'])) ?>">Create Coupon</a></div></section>
        <?php if ($couponCreate || $editingCoupon !== null): ?>
          <section class="admin-panel admin-editor-panel" id="coupon-editor">
            <div class="admin-panel-head"><div><p class="admin-kicker">Coupon Form</p><h3><?= $editingCoupon ? 'Edit coupon' : 'Create coupon' ?></h3></div></div>
            <?php admin_form_open('coupons', $editingCoupon ? 'update-coupon' : 'create-coupon'); ?>
            <?php if ($editingCoupon): ?><input type="hidden" name="coupon_id" value="<?= admin_html($editingCoupon['id']) ?>"><?php endif; ?>
            <div class="admin-grid three-up">
              <?php admin_input('coupon[code]', 'Coupon Code', $editingCoupon['code'] ?? ''); ?>
              <?php admin_select('coupon[type]', 'Discount Type', $editingCoupon['type'] ?? 'percent', ['percent' => 'Percentage Discount', 'fixed' => 'Fixed Discount'], 'data-coupon-type'); ?>
              <?php admin_input('coupon[value]', (($editingCoupon['type'] ?? 'percent') === 'fixed' ? 'Fixed Amount' : 'Percentage Value'), $editingCoupon['value'] ?? '', 'number', 'step="0.01" min="0" data-coupon-value', (($editingCoupon['type'] ?? 'percent') === 'fixed' ? 'Enter the discount amount to subtract from the order total.' : 'Enter the percentage to apply. Standard ecommerce range is 5 to 50.')); ?>
              <?php admin_input('coupon[min_order]', 'Minimum Order', $editingCoupon['min_order'] ?? '', 'text', '', 'Example: £500'); ?>
              <?php admin_input('coupon[usage_limit]', 'Usage Limit', $editingCoupon['usage_limit'] ?? '', 'number', 'min="1"'); ?>
              <?php admin_input('coupon[expires_at]', 'Expiry Date', $editingCoupon['expires_at'] ?? '', 'date'); ?>
              <?php admin_select('coupon[status]', 'Status', $editingCoupon['status'] ?? 'active', ['active' => 'Active', 'inactive' => 'Inactive']); ?>
            </div>
            <?php admin_textarea('coupon[description]', 'Description', $editingCoupon['description'] ?? '', 3); ?>
            <div class="admin-actions"><button class="admin-primary" type="submit"><?= $editingCoupon ? 'Update Coupon' : 'Create Coupon' ?></button><a class="admin-ghost" href="<?= admin_html(admin_url('coupons')) ?>">Close</a></div>
            <?php admin_form_close(); ?>
          </section>
        <?php endif; ?>
        <section class="admin-panel" id="coupon-library">
          <form method="get" action="<?= admin_html(admin_url('coupons')) ?>" class="admin-filter-bar admin-filter-bar-simple">
            <input type="hidden" name="view" value="coupons">
            <?php admin_input('coupon_q', 'Search Coupons', $couponQuery, 'text', 'placeholder="Search code or description"'); ?>
            <?php admin_select('coupon_status', 'Status', $couponStatusFilter, ['' => 'All Statuses', 'active' => 'Active', 'inactive' => 'Inactive']); ?>
            <div class="admin-filter-summary">
              <span><?= count($filteredCoupons) ?> coupons shown</span>
              <small>Filter before editing to keep the promotions list fast and manageable.</small>
            </div>
            <div class="admin-actions"><button class="admin-primary" type="submit">Apply</button><a class="admin-ghost" href="<?= admin_html(admin_url('coupons')) ?>">Reset</a></div>
          </form>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Code</th><th>Offer</th><th>Minimum</th><th>Usage Limit</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($filteredCoupons as $coupon): ?>
                  <tr>
                    <td><strong><?= admin_html($coupon['code']) ?></strong></td>
                    <td><?= admin_html($coupon['apply_label'] ?: (($coupon['type'] === 'fixed' ? '£' : '') . $coupon['value'] . ($coupon['type'] === 'percent' ? '% off' : ' off'))) ?></td>
                    <td><?= admin_html($coupon['min_order']) ?></td>
                    <td><?= admin_html($coupon['usage_limit']) ?></td>
                    <td><?= admin_html($coupon['expires_at']) ?></td>
                    <td><span class="status-pill"><?= admin_html($coupon['status']) ?></span></td>
                    <td><div class="admin-action-row"><a class="admin-icon-link" href="<?= admin_html(admin_url('coupons', ['coupon_edit' => $coupon['id']])) ?>"><i class="fas fa-pen"></i></a><?php admin_table_button('Toggle', 'toggle-coupon', ['coupon_id' => $coupon['id']], 'admin-mini-btn warn'); ?><?php admin_table_button('Delete', 'delete-coupon', ['coupon_id' => $coupon['id']], 'admin-mini-btn danger'); ?></div></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php elseif ($view === 'site'): ?>
        <section class="admin-page-hero"><div><p class="admin-kicker">Advanced Site</p><h2>Brand, navigation, footer</h2><p>Keep the brand system structured by editing settings, navigation, and footer content in separate blocks.</p></div><div class="admin-mini-stats"><article><span>Nav Items</span><strong><?= count($content['navigation']['items'] ?? []) ?></strong></article><article><span>Footer Links</span><strong><?= count((array) ($content['footer']['information_links'] ?? [])) + count((array) ($content['footer']['account_links'] ?? [])) + count((array) ($content['footer']['bottom_links'] ?? [])) ?></strong></article></div></section>
        <section class="admin-anchor-nav">
          <a href="#site-brand">Brand</a>
          <a href="#site-company">Trader Identity</a>
          <a href="#site-social">Social Profiles</a>
          <a href="#site-hero">Hero Section</a>
          <a href="#site-delivery">Delivery Timeline</a>
          <a href="#site-social-gallery">Social Gallery</a>
          <a href="#site-faq">FAQ</a>
          <a href="#site-navigation">Navigation</a>
          <a href="#site-footer">Footer</a>
        </section>
        <section class="admin-panel" id="site-brand"><div class="admin-panel-head"><div><p class="admin-kicker">Brand</p><h3>Site settings</h3></div></div>
          <?php admin_form_open('site', 'save-settings'); ?>
          <div class="admin-grid three-up">
            <?php admin_input('settings[site_name]', 'Site Name', $content['settings']['site_name']); ?>
            <?php admin_input('settings[site_tagline]', 'Tagline', $content['settings']['site_tagline']); ?>
            <?php admin_input('settings[logo_path]', 'Logo URL', $content['settings']['logo_path']); ?>
            <?php admin_input('settings[store_phone]', 'Phone', $content['settings']['store_phone'] ?? ''); ?>
            <?php admin_input('settings[store_email]', 'Email', $content['settings']['store_email'] ?? ''); ?>
            <?php admin_input('settings[top_bar_text]', 'Top Bar Text', $content['settings']['top_bar_text'] ?? ''); ?>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Settings</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-company"><div class="admin-panel-head"><div><p class="admin-kicker">Legal</p><h3>Trader Identity</h3><p>UK law requires an online trader to publish these. They appear on the Privacy Policy, Terms, Delivery, Returns and Contact pages. Leave a registration number blank and the line is omitted rather than shown empty.</p></div></div>
          <?php admin_form_open('site', 'save-settings'); ?>
          <div class="admin-grid three-up">
            <?php admin_input('settings[company][legal_name]', 'Registered Legal Name', $content['settings']['company']['legal_name'] ?? '', 'text', 'maxlength="120"', 'e.g. Azuronn Ltd'); ?>
            <?php admin_input('settings[company][company_number]', 'Companies House Number', $content['settings']['company']['company_number'] ?? '', 'text', 'maxlength="40"', 'Required if you are a registered company.'); ?>
            <?php admin_input('settings[company][vat_number]', 'VAT Registration Number', $content['settings']['company']['vat_number'] ?? '', 'text', 'maxlength="40"', 'Required if VAT registered.'); ?>
          </div>
          <div class="admin-grid two-up">
            <?php admin_textarea('settings[company][registered_address]', 'Registered Office Address', $content['settings']['company']['registered_address'] ?? '', 3, 'The address on your Companies House record.'); ?>
            <?php admin_textarea('settings[company][trading_address]', 'Trading / Returns Address', $content['settings']['company']['trading_address'] ?? '', 3, 'Leave blank if it is the same as the registered office.'); ?>
          </div>
          <div class="admin-grid two-up">
            <?php admin_input('settings[company][support_hours]', 'Customer Support Hours', $content['settings']['company']['support_hours'] ?? '', 'text', 'maxlength="120"'); ?>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Trader Identity</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-social"><div class="admin-panel-head"><div><p class="admin-kicker">Footer</p><h3>Social Profiles</h3><p>Paste the full URL of each profile. Leave one blank and its icon is hidden from the footer rather than linking nowhere.</p></div></div>
          <?php admin_form_open('site', 'save-settings'); ?>
          <div class="admin-grid three-up">
            <?php admin_input('settings[social][facebook]', 'Facebook URL', $content['settings']['social']['facebook'] ?? '', 'url', 'placeholder="https://www.facebook.com/yourpage"'); ?>
            <?php admin_input('settings[social][instagram]', 'Instagram URL', $content['settings']['social']['instagram'] ?? '', 'url', 'placeholder="https://www.instagram.com/yourpage"'); ?>
            <?php admin_input('settings[social][pinterest]', 'Pinterest URL', $content['settings']['social']['pinterest'] ?? '', 'url', 'placeholder="https://www.pinterest.co.uk/yourpage"'); ?>
            <?php admin_input('settings[social][twitter]', 'X (Twitter) URL', $content['settings']['social']['twitter'] ?? '', 'url', 'placeholder="https://x.com/yourpage"'); ?>
            <?php admin_input('settings[social][youtube]', 'YouTube URL', $content['settings']['social']['youtube'] ?? '', 'url', 'placeholder="https://www.youtube.com/@yourchannel"'); ?>
            <?php admin_input('settings[social][tiktok]', 'TikTok URL', $content['settings']['social']['tiktok'] ?? '', 'url', 'placeholder="https://www.tiktok.com/@yourpage"'); ?>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Social Profiles</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-delivery"><div class="admin-panel-head"><div><p class="admin-kicker">Storefront</p><h3>Delivery Timeline</h3></div></div>
          <?php admin_form_open('site', 'save-settings'); ?>
          <div class="admin-grid two-up">
            <?php admin_input('settings[delivery][basic_label]', 'Basic Option Label', $content['settings']['delivery']['basic_label'] ?? '', 'text', 'maxlength="80"'); ?>
            <?php admin_input('settings[delivery][express_label]', 'Express Option Label', $content['settings']['delivery']['express_label'] ?? '', 'text', 'maxlength="80"'); ?>
          </div>
          <div class="admin-grid two-up">
            <?php admin_textarea('settings[delivery][basic_description]', 'Basic Option Description', $content['settings']['delivery']['basic_description'] ?? '', 3, 'Shown under the Basic option on every product page.'); ?>
            <?php admin_textarea('settings[delivery][express_description]', 'Express Option Description', $content['settings']['delivery']['express_description'] ?? '', 3, 'Shown under the Express option on every product page.'); ?>
          </div>
          <div class="admin-grid three-up">
            <?php admin_input('settings[delivery][express_price]', 'Express Charge (£)', $content['settings']['delivery']['express_price'] ?? '', 'text', 'inputmode="decimal"', 'Charged per item, so quantity 3 pays this three times. Basic delivery is always free.'); ?>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Delivery Timeline</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-hero"><div class="admin-panel-head"><div><p class="admin-kicker">Homepage</p><h3>Hero Section</h3></div></div>
          <?php admin_form_open('site', 'save-hero', true); ?>
          <div class="admin-grid two-up">
            <?php admin_input('hero[title]', 'Hero Title', $content['hero']['title']); ?>
            <?php admin_input('hero[offer]', 'Offer / Subtitle', $content['hero']['offer']); ?>
            <?php admin_input('hero[price_prefix]', 'Price Prefix', $content['hero']['price_prefix']); ?>
            <?php admin_input('hero[price_value]', 'Price Value', $content['hero']['price_value']); ?>
            <?php admin_input('hero[cta_label]', 'Button Label', $content['hero']['cta_label']); ?>
            <?php admin_input('hero[cta_url]', 'Button URL', $content['hero']['cta_url']); ?>
          </div>
          <div class="admin-grid two-up">
            <?php admin_input('hero_image_url', 'Background Media URL', $content['hero']['image'] ?? '', 'text', '', 'Shows the current media. Paste a new URL or upload a file (' . admin_upload_hint() . ') to replace it; leave it as-is to keep the current one. If an upload "succeeds" but the image doesn\'t change, the file is over this server limit — use a smaller one or paste a URL.'); ?>
            <label class="admin-field">
              <span>Upload Media (replaces current)</span>
              <input type="file" name="hero_image_file" accept="image/*,video/mp4,video/webm,video/ogg,video/quicktime">
            </label>
          </div>
          <?php if (!empty($content['hero']['image'])): ?>
            <div class="admin-field" style="margin-top:20px;">
              <span>Current Media</span>
              <div style="margin-top:8px;">
                <?php if (media_asset_type($content['hero']['image']) === 'video'): ?>
                  <video src="<?= h($content['hero']['image']) ?>" style="max-height:150px; border-radius:6px; border:1px solid #ddd;" muted playsinline></video>
                <?php else: ?>
                  <img src="<?= h($content['hero']['image']) ?>" style="max-height:150px; border-radius:6px; border:1px solid #ddd;" alt="">
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Hero</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-social-gallery"><div class="admin-panel-head"><div><p class="admin-kicker">Homepage</p><h3>Say "Yes" Social Gallery</h3></div></div>
          <?php admin_form_open('site', 'save-social-gallery', true); ?>
          <div class="admin-grid">
            <?php admin_input('social_gallery[title]', 'Section Title', $content['social_gallery']['title'] ?? 'Say "Yes" with Azuronn'); ?>
          </div>
          <div class="admin-repeater" data-repeater data-index-token="__GALLERY_INDEX__" data-next-index="<?= count($content['social_gallery']['items'] ?? []) ?>">
            <div class="admin-repeater-list">
              <?php foreach ($content['social_gallery']['items'] ?? [] as $idx => $item): ?>
                <div class="admin-repeater-item compact-item">
                  <div class="admin-item-head"><h4>Image</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                  <div class="admin-grid three-up">
                    <?php admin_input('social_gallery[items][' . $idx . '][username]', 'Username', $item['username']); ?>
                    <?php admin_input('social_gallery[items][' . $idx . '][alt]', 'Alt Text', $item['alt']); ?>
                    <label class="admin-field">
                      <span>Image File</span>
                      <input type="file" name="social_gallery_image_file_<?= $idx ?>" accept="image/*">
                      <?php if (!empty($item['image'])): ?>
                        <div style="margin-top: 5px;"><img src="<?= h($item['image']) ?>" style="max-height: 40px; border-radius: 4px; border: 1px solid #ccc;"></div>
                      <?php endif; ?>
                      <input type="hidden" name="social_gallery[items][<?= $idx ?>][image]" value="<?= h($item['image'] ?? '') ?>">
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button class="admin-add" type="button" data-add-item data-template="tpl-social-gallery-item">Add Image</button>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Social Gallery</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-faq"><div class="admin-panel-head"><div><p class="admin-kicker">Homepage</p><h3>FAQ Section</h3></div></div>
          <?php admin_form_open('site', 'save-faq', true); ?>
          <div class="admin-grid two-up">
            <?php admin_input('faq[kicker]', 'Kicker', $content['faq']['kicker'] ?? 'FREQUENTLY ASKED QUESTIONS'); ?>
            <?php admin_input('faq[title]', 'Section Title', $content['faq']['title'] ?? 'Everything you need to know before getting started'); ?>
          </div>
          <div class="admin-grid two-up">
            <?php admin_input('faq_support_image_url', 'Support Image URL', '', 'text', '', 'Paste URL or upload a file'); ?>
            <label class="admin-field">
              <span>Upload Support Image</span>
              <input type="file" name="faq_support_image_file" accept="image/*">
            </label>
          </div>
          <?php if (!empty($content['faq']['support_image'])): ?>
            <div class="admin-field" style="margin-top: 5px; margin-bottom: 20px;">
              <span>Current Support Image</span>
              <div style="margin-top: 5px;"><img src="<?= h($content['faq']['support_image']) ?>" style="max-height: 80px; border-radius: 4px; border: 1px solid #ccc;"></div>
              <input type="hidden" name="faq[support_image]" value="<?= h($content['faq']['support_image'] ?? '') ?>">
            </div>
          <?php endif; ?>
          <div class="admin-grid two-up">
            <?php admin_input('faq[support_title]', 'Support Title', $content['faq']['support_title'] ?? 'Customer Support'); ?>
            <?php admin_input('faq[support_text]', 'Support Text', $content['faq']['support_text'] ?? 'Do you have additional questions?'); ?>
            <?php admin_input('faq[support_btn_label]', 'Support Button Label', $content['faq']['support_btn_label'] ?? 'BOOK ONLINE'); ?>
            <?php admin_input('faq[support_btn_url]', 'Support Button URL', $content['faq']['support_btn_url'] ?? '#'); ?>
          </div>
          <div class="admin-repeater" data-repeater data-index-token="__FAQ_INDEX__" data-next-index="<?= count($content['faq']['items'] ?? []) ?>">
            <div class="admin-repeater-list">
              <?php foreach ($content['faq']['items'] ?? [] as $idx => $item): ?>
                <div class="admin-repeater-item compact-item">
                  <div class="admin-item-head"><h4>Question</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                  <div class="admin-grid one-up">
                    <?php admin_input('faq[items][' . $idx . '][question]', 'Question', $item['question']); ?>
                    <?php admin_textarea('faq[items][' . $idx . '][answer]', 'Answer', $item['answer'], 3); ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button class="admin-add" type="button" data-add-item data-template="tpl-faq-item">Add Question</button>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save FAQ</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-navigation"><div class="admin-panel-head"><div><p class="admin-kicker">Navigation</p><h3>Mega menu</h3></div></div>
          <?php admin_form_open('site', 'save-navigation'); ?>
          <div class="admin-repeater" data-repeater data-index-token="__NAV_INDEX__" data-next-index="<?= count($content['navigation']['items']) ?>">
            <div class="admin-repeater-list">
              <?php foreach ($content['navigation']['items'] as $index => $item): ?>
                <div class="admin-repeater-item">
                  <div class="admin-item-head"><h4>Nav Item</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
                  <div class="admin-grid three-up">
                    <?php admin_input('navigation[items][' . $index . '][label]', 'Label', $item['label']); ?>
                    <?php admin_input('navigation[items][' . $index . '][url]', 'URL', $item['url']); ?>
                    <?php admin_input('navigation[items][' . $index . '][feature][title]', 'Feature Title', $item['feature']['title']); ?>
                    <?php admin_input('navigation[items][' . $index . '][feature][subtitle]', 'Feature Subtitle', $item['feature']['subtitle']); ?>
                    <?php admin_input('navigation[items][' . $index . '][feature][image]', 'Feature Image', $item['feature']['image']); ?>
                    <?php admin_input('navigation[items][' . $index . '][feature][alt]', 'Feature Alt', $item['feature']['alt']); ?>
                  </div>
                  <input type="hidden" name="navigation[items][<?= $index ?>][active]" value="<?= !empty($item['active']) ? '1' : '0' ?>">
                  <input type="hidden" name="navigation[items][<?= $index ?>][compact]" value="<?= !empty($item['compact']) ? '1' : '0' ?>">
                  <?php foreach ($item['columns'] as $colIndex => $column): ?>
                    <div class="admin-subsection">
                      <div class="admin-grid two-up">
                        <?php admin_input('navigation[items][' . $index . '][columns][' . $colIndex . '][title]', 'Column Title', $column['title']); ?>
                      </div>
                      <?php foreach ($column['links'] as $linkIndex => $link): ?>
                        <div class="admin-grid one-up">
                          <?php admin_input('navigation[items][' . $index . '][columns][' . $colIndex . '][links][' . $linkIndex . '][label]', 'Link Label', $link['label']); ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Navigation</button></div>
          <?php admin_form_close(); ?>
        </section>
        <section class="admin-panel" id="site-footer"><div class="admin-panel-head"><div><p class="admin-kicker">Footer</p><h3>Footer content</h3><p>Column headings and the copyright line. The trader details beneath the copyright come from Trader Identity above, and the required legal links are always shown.</p></div></div>
          <?php admin_form_open('site', 'save-footer'); ?>
          <div class="admin-grid three-up">
            <?php admin_input('footer[information_title]', 'Information Title', $content['footer']['information_title']); ?>
            <?php admin_input('footer[account_title]', 'Account Title', $content['footer']['account_title']); ?>
            <?php admin_input('footer[copyright_year]', 'Copyright Year', $content['footer']['copyright_year']); ?>
            <?php admin_input('footer[copyright_brand]', 'Copyright Brand', $content['footer']['copyright_brand']); ?>
            <?php admin_input('footer[payment_image]', 'Payment Image', $content['footer']['payment_image'], 'text', '', 'Optional card-logo strip. Leave blank to show only the secure-payment note.'); ?>
            <?php admin_input('footer[payment_alt]', 'Payment Alt', $content['footer']['payment_alt']); ?>
          </div>
          <div class="admin-actions"><button class="admin-primary" type="submit">Save Footer</button></div>
          <?php admin_form_close(); ?>
        </section>
      <?php endif; ?>
    </main>
  </div>

  <template id="tpl-product-size-choice">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Size Choice</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid two-up">
        <?php admin_input('product[option_size_choices][__PRODUCT_SIZE_INDEX__][label]', 'Label', ''); ?>
        <?php admin_input('product[option_size_choices][__PRODUCT_SIZE_INDEX__][caption]', 'Caption', ''); ?>
      </div>
    </div>
  </template>

  <template id="tpl-category-metal-option">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Metal Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid two-up">
        <?php admin_input('product[option_metal_options][__PRODUCT_METAL_INDEX__][label]', 'Label', ''); ?>
        <?php admin_input('product[option_metal_options][__PRODUCT_METAL_INDEX__][color_hex]', 'Metal Color *', '#c9a96e', 'color', 'required', 'Pick the display color for this metal.'); ?>
      </div>
      <?php admin_textarea('product[option_metal_options][__PRODUCT_METAL_INDEX__][description]', 'Description', '', 3); ?>
      <?php admin_metal_price_adjustment_controls('__PRODUCT_METAL_INDEX__'); ?>
    </div>
  </template>

  <template id="tpl-product-detail-option">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Metal Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid two-up">
        <?php admin_input('product[option_metal_options][__PRODUCT_METAL_INDEX__][label]', 'Label', ''); ?>
        <?php admin_input('product[option_metal_options][__PRODUCT_METAL_INDEX__][color_hex]', 'Metal Color *', '#c9a96e', 'color', 'required', 'Pick the display color for this metal.'); ?>
      </div>
      <?php admin_textarea('product[option_metal_options][__PRODUCT_METAL_INDEX__][description]', 'Description', '', 3); ?>
    </div>
  </template>

  <?php foreach (catalog_addon_groups() as $tplAddonKey => $tplAddonMeta): ?>
  <template id="tpl-product-addon-<?= admin_html($tplAddonKey) ?>">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4><?= admin_html($tplAddonMeta['label']) ?> Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid one-up">
        <?php admin_input('product[option_addon_groups][' . $tplAddonKey . '][__PRODUCT_ADDON_INDEX__][label]', 'Label', ''); ?>
      </div>
    </div>
  </template>
  <?php endforeach; ?>

  <template id="tpl-product-band-option">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Band / Claw Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid one-up">
        <?php admin_input('product[option_band_claw_metal_options][__PRODUCT_BAND_INDEX__][label]', 'Label', ''); ?>
      </div>
      <input type="hidden" name="product[option_band_claw_metal_options][__PRODUCT_BAND_INDEX__][current_image]" value="">
      <div class="admin-grid two-up">
        <?php admin_input('band_image_url___PRODUCT_BAND_INDEX__', 'Image URL', '', 'text', '', 'Paste an image URL or upload one below.'); ?>
        <label class="admin-field">
          <span>Upload Image</span>
          <input type="file" name="band_image_file___PRODUCT_BAND_INDEX__" accept="image/*">
        </label>
      </div>
      <?php admin_textarea('product[option_band_claw_metal_options][__PRODUCT_BAND_INDEX__][description]', 'Description', '', 3); ?>
    </div>
  </template>

  <template id="tpl-product-style-card">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>New Style</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <input type="hidden" name="product[style_cards][__PRODUCT_STYLE_INDEX__][current_image]" value="">
      <div class="admin-grid one-up">
        <?php admin_input('product[style_cards][__PRODUCT_STYLE_INDEX__][label]', 'Style Name', '', 'text', '', 'Used for display, and the URL/filter value is created from this name automatically.'); ?>
      </div>
      <div class="admin-grid two-up">
        <?php admin_input('style_card_image_url___PRODUCT_STYLE_INDEX__', 'Image URL', '', 'text', '', 'Paste an image URL or upload one below.'); ?>
        <label class="admin-field">
          <span>Upload Image</span>
          <input type="file" name="style_card_image_file___PRODUCT_STYLE_INDEX__" accept="image/*">
        </label>
      </div>
    </div>
  </template>

  <template id="tpl-product-style-card-engagement">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>New Style</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <input type="hidden" name="product[style_cards_sections][engagement][__ENG_STYLE_INDEX__][current_image]" value="">
      <div class="admin-grid one-up">
        <?php admin_input('product[style_cards_sections][engagement][__ENG_STYLE_INDEX__][label]', 'Style Name', '', 'text', '', 'Used for display, and the URL/filter value is created from this name automatically.'); ?>
      </div>
      <div class="admin-grid two-up">
        <?php admin_input('style_card_engagement_image_url___ENG_STYLE_INDEX__', 'Image URL', '', 'text', '', 'Paste an image URL or upload one below.'); ?>
        <label class="admin-field">
          <span>Upload Image</span>
          <input type="file" name="style_card_engagement_image_file___ENG_STYLE_INDEX__" accept="image/*">
        </label>
      </div>
    </div>
  </template>

  <template id="tpl-product-style-card-wedding">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>New Style</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <input type="hidden" name="product[style_cards_sections][wedding][__WED_STYLE_INDEX__][current_image]" value="">
      <div class="admin-grid one-up">
        <?php admin_input('product[style_cards_sections][wedding][__WED_STYLE_INDEX__][label]', 'Style Name', '', 'text', '', 'Used for display, and the URL/filter value is created from this name automatically.'); ?>
      </div>
      <div class="admin-grid two-up">
        <?php admin_input('style_card_wedding_image_url___WED_STYLE_INDEX__', 'Image URL', '', 'text', '', 'Paste an image URL or upload one below.'); ?>
        <label class="admin-field">
          <span>Upload Image</span>
          <input type="file" name="style_card_wedding_image_file___WED_STYLE_INDEX__" accept="image/*">
        </label>
      </div>
    </div>
  </template>

  <template id="tpl-product-selector-card">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>New Style</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <input type="hidden" name="product[selector_cards][__PRODUCT_SELECTOR_INDEX__][current_image]" value="">
      <div class="admin-grid one-up">
        <?php admin_input('product[selector_cards][__PRODUCT_SELECTOR_INDEX__][label]', 'Style Name', '', 'text', '', 'Used for display, and the URL/filter value is created from this name automatically.'); ?>
      </div>
      <div class="admin-grid two-up">
        <?php admin_input('selector_card_image_url___PRODUCT_SELECTOR_INDEX__', 'Image URL', '', 'text', '', 'Paste an image URL or upload one below.'); ?>
        <label class="admin-field">
          <span>Upload Image</span>
          <input type="file" name="selector_card_image_file___PRODUCT_SELECTOR_INDEX__" accept="image/*">
        </label>
      </div>
    </div>
  </template>

  <template id="tpl-product-setting-variation">
    <div class="admin-repeater-item">
      <div class="admin-item-head"><h4>Setting Variation</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid three-up">
        <?php admin_select('product[setting_variations][__PRODUCT_VAR_INDEX__][shape]', 'Supported Shape', 'all', ['all' => 'Any Supported Shape'] + available_diamond_shapes()); ?>
        <?php admin_select('product[setting_variations][__PRODUCT_VAR_INDEX__][metal]', 'Metal Option', '', admin_options_from_list(array_map(static fn($m) => $m['label'], $catalogEditorProfile['option_metal_options'] ?? []))); ?>
        <?php admin_select('product[setting_variations][__PRODUCT_VAR_INDEX__][band]', 'Band / Claw Option', '', admin_options_from_list(array_map(static fn($b) => $b['label'], $catalogEditorProfile['option_band_claw_metal_options'] ?? []))); ?>
      </div>
      <div class="admin-grid two-up">
        <?php admin_input('product[setting_variations][__PRODUCT_VAR_INDEX__][price]', 'Setting Price', '0', 'number', 'step="0.01" min="0"'); ?>
        <?php admin_select('product[setting_variations][__PRODUCT_VAR_INDEX__][status]', 'Stock Status', 'active', ['active' => 'Available', 'sold-out' => 'Sold Out']); ?>
      </div>
    </div>
  </template>

  <template id="tpl-product-delivery-option">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Delivery Option</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid three-up">
        <?php admin_input('product[option_delivery_options][__PRODUCT_DELIVERY_INDEX__][label]', 'Label', ''); ?>
        <?php admin_input('product[option_delivery_options][__PRODUCT_DELIVERY_INDEX__][price]', 'Surcharge', '0', 'number', 'step="0.01" min="0"'); ?>
        <?php admin_input('product[option_delivery_options][__PRODUCT_DELIVERY_INDEX__][badge]', 'Badge', ''); ?>
      </div>
      <div class="admin-grid two-up">
        <?php admin_input('product[option_delivery_options][__PRODUCT_DELIVERY_INDEX__][price_label]', 'Price Label', ''); ?>
      </div>
      <?php admin_textarea('product[option_delivery_options][__PRODUCT_DELIVERY_INDEX__][description]', 'Description', '', 3); ?>
    </div>
  </template>

  <template id="tpl-product-diamond-row">
    <div class="admin-repeater-item">
      <div class="admin-item-head"><h4>Diamond Row</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <input type="hidden" name="product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][id]" value="">
      <div class="admin-grid four-up">
        <?php admin_select('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][shape]', 'Applies To Shape', 'all', ['all' => 'Any Supported Shape'] + available_diamond_shapes()); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][title]', 'Card Title', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][carat]', 'Carat', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][price]', 'Diamond Price', '0', 'number', 'step="0.01" min="0"'); ?>
      </div>
      <div class="admin-grid four-up">
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][color]', 'Color Grade', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][clarity]', 'Clarity', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][cut]', 'Cut', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][badge]', 'Badge', 'Lab Selected'); ?>
      </div>
      <div class="admin-grid four-up">
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][ratio]', 'Ratio', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][measurement]', 'Measurement', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][ref]', 'REF', ''); ?>
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][igi_certificate]', 'IGI Certificate', ''); ?>
      </div>
      <div class="admin-grid three-up">
        <?php admin_input('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][image]', 'Diamond Image URL', ''); ?>
        <?php admin_input('product_diamond_image_file___PRODUCT_DIAMOND_INDEX__', 'Upload Image', '', 'file', 'accept="image/*"'); ?>
        <?php admin_select('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][status]', 'Stock Status', 'active', ['active' => 'Available', 'sold-out' => 'Sold Out']); ?>
      </div>
      <?php admin_textarea('product[diamond_inventory][__PRODUCT_DIAMOND_INDEX__][description]', 'Diamond Description', '', 4); ?>
    </div>
  </template>

  <template id="tpl-admin-diamond-row">
    <div class="admin-repeater-item">
      <div class="admin-item-head"><h4>Diamond Row</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <input type="hidden" name="diamond_inventory[__PRODUCT_DIAMOND_INDEX__][id]" value="">
      <div class="admin-grid four-up">
        <?php admin_select('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][shape]', 'Applies To Shape', 'round', available_diamond_shapes()); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][title]', 'Card Title', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][carat]', 'Carat', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][price]', 'Diamond Price', '0', 'number', 'step="0.01" min="0"'); ?>
      </div>
      <div class="admin-grid four-up">
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][color]', 'Color Grade', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][clarity]', 'Clarity', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][cut]', 'Cut', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][badge]', 'Badge', 'Lab Selected'); ?>
      </div>
      <div class="admin-grid four-up">
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][ratio]', 'Ratio', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][measurement]', 'Measurement', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][ref]', 'REF', ''); ?>
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][igi_certificate]', 'IGI Certificate', ''); ?>
      </div>
      <div class="admin-grid three-up">
        <?php admin_input('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][image]', 'Diamond Image URL', ''); ?>
        <?php admin_input('diamond_image_file___PRODUCT_DIAMOND_INDEX__', 'Upload Image', '', 'file', 'accept="image/*"'); ?>
        <?php admin_select('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][status]', 'Stock Status', 'active', ['active' => 'Available', 'sold-out' => 'Sold Out']); ?>
      </div>
      <?php admin_textarea('diamond_inventory[__PRODUCT_DIAMOND_INDEX__][description]', 'Diamond Description', '', 4); ?>
    </div>
  </template>

  <template id="tpl-category-card">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Card</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid three-up">
        <?php admin_input('category_cards[__CARD_INDEX__][header_icon]', 'Icon Classes', 'fas fa-gem'); ?>
        <?php admin_input('category_cards[__CARD_INDEX__][sub]', 'Other Text', ''); ?>
        <?php admin_input('category_cards[__CARD_INDEX__][title]', 'Category Name (Title)', ''); ?>
        <?php admin_input('category_cards[__CARD_INDEX__][image]', 'Image URL', '', 'text', '', 'Paste URL or upload below'); ?>
        <?php admin_input('category_image_file___CARD_INDEX__', 'Upload Image', '', 'file', 'accept="image/*"'); ?>
        <?php admin_input('category_cards[__CARD_INDEX__][alt]', 'Image Alt', ''); ?>
        <?php admin_input('category_cards[__CARD_INDEX__][hero_image]', 'Hero Image URL', '', 'text', '', 'Used on the category page hero'); ?>
        <?php admin_input('category_hero_image_file___CARD_INDEX__', 'Upload Hero Image', '', 'file', 'accept="image/*"'); ?>
      </div>
    </div>
  </template>

  <template id="tpl-celeb-item">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Celeb</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid two-up">
        <?php admin_input('celebs[items][__CELEB_INDEX__][name]', 'Name', ''); ?>
        <?php admin_input('celebs[items][__CELEB_INDEX__][image]', 'Image URL', ''); ?>
      </div>
    </div>
  </template>

  <template id="tpl-social-gallery-item">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Image</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid three-up">
        <label class="admin-field"><span>Username</span><input type="text" name="social_gallery[items][__GALLERY_INDEX__][username]"></label>
        <label class="admin-field"><span>Alt Text</span><input type="text" name="social_gallery[items][__GALLERY_INDEX__][alt]"></label>
        <label class="admin-field"><span>Image File</span><input type="file" name="social_gallery_image_file___GALLERY_INDEX__" accept="image/*"></label>
      </div>
    </div>
  </template>

  <template id="tpl-faq-item">
    <div class="admin-repeater-item compact-item">
      <div class="admin-item-head"><h4>Question</h4><button class="admin-remove" type="button" data-remove-item>Delete</button></div>
      <div class="admin-grid one-up">
        <label class="admin-field"><span>Question</span><input type="text" name="faq[items][__FAQ_INDEX__][question]"></label>
        <label class="admin-field"><span>Answer</span><textarea name="faq[items][__FAQ_INDEX__][answer]" rows="3"></textarea></label>
      </div>
    </div>
  </template>

  <script src="../assets/js/admin.js?v=<?= filemtime(BASE_PATH . '/assets/js/admin.js') ?>"></script>
  <script>
    function adminSyncMetalMatrix() {
        // Sync Sizes
        const sizeLabels = Array.from(document.querySelectorAll('input[name^="product[option_size_choices]"][name$="[label]"]')).map(el => el.value.trim()).filter(v => v);
        document.querySelectorAll('[data-matrix-sizes]').forEach(grid => {
            const mIdx = grid.getAttribute('data-matrix-sizes');
            const currentChecked = Array.from(grid.querySelectorAll('input:checked')).map(el => el.value);
            if (sizeLabels.length === 0) {
                grid.innerHTML = '<span class="admin-empty-note" style="font-size:0.8em;">No sizes defined.</span>';
            } else {
                grid.innerHTML = sizeLabels.map(sz => `
                    <label class="admin-choice-chip" style="padding:6px 10px;">
                        <input type="checkbox" name="product[metal_variations][${mIdx}][sizes][]" value="${sz}" ${currentChecked.includes(sz) ? 'checked' : ''}>
                        <span style="font-size:0.9em;">${sz}</span>
                    </label>
                `).join('');
            }
        });

        // Sync Bands
        const bandLabels = Array.from(document.querySelectorAll('input[name^="product[option_band_claw_metal_options]"][name$="[label]"]')).map(el => el.value.trim()).filter(v => v);
        document.querySelectorAll('[data-matrix-bands]').forEach(list => {
            const mIdx = list.getAttribute('data-matrix-bands');
            // Save state
            const state = {};
            list.querySelectorAll('.admin-band-addon-row').forEach(row => {
                const label = row.querySelector('input[type="hidden"]').value;
                const checked = row.querySelector('input[type="checkbox"]').checked;
                const surcharge = row.querySelector('input[type="number"]').value;
                state[label] = { checked, surcharge };
            });

            if (bandLabels.length === 0) {
                list.innerHTML = '<span class="admin-empty-note">No band options defined.</span>';
                const parent = list.closest('.admin-field');
                if (parent) {
                    const emptyNote = parent.querySelector('> span.admin-empty-note');
                    if (emptyNote) emptyNote.style.display = 'block';
                    list.style.display = 'none';
                }
            } else {
                const parent = list.closest('.admin-field');
                if (parent) {
                    const emptyNote = parent.querySelector('> span.admin-empty-note');
                    if (emptyNote) emptyNote.style.display = 'none';
                    list.style.display = 'flex';
                }
                list.innerHTML = bandLabels.map((lbl, bIdx) => {
                    const saved = state[lbl] || { checked: false, surcharge: '0' };
                    return `
                        <div class="admin-band-addon-row" style="display:flex; align-items:center; justify-content:space-between; background:#f9fafb; padding:8px 12px; border-radius:6px; border:1px solid #e5e7eb;">
                            <label class="admin-checkbox" style="margin:0;">
                                <input type="checkbox" name="product[metal_variations][${mIdx}][band_options][${bIdx}][active]" value="1" ${saved.checked ? 'checked' : ''}>
                                <span style="font-size:0.9em;">${lbl}</span>
                                <input type="hidden" name="product[metal_variations][${mIdx}][band_options][${bIdx}][label]" value="${lbl}">
                            </label>
                            <div class="admin-field-inline" style="margin:0;">
                                <span style="font-size:0.85em; color:#6b7280; margin-right:6px;">Add-on:</span>
                                <div class="admin-input-wrap">
                                    <span class="admin-input-prefix">£</span>
                                    <input type="number" name="product[metal_variations][${mIdx}][band_options][${bIdx}][surcharge]" value="${saved.surcharge}" step="0.01" min="0" style="width:80px; padding:4px 8px 4px 28px;">
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        });

        // Sync priced add-on groups. The per-metal field prefix differs per
        // category (product[metal_variations_<type>][...]), so read it off the
        // metal block's own "active" checkbox instead of assuming a name.
        document.querySelectorAll('[data-matrix-addons]').forEach(list => {
            const token = list.getAttribute('data-matrix-addons') || '';
            const sep = token.lastIndexOf('-');
            const groupKey = token.slice(0, sep);
            const mIdx = token.slice(sep + 1);
            const block = list.closest('.admin-metal-matrix-block');
            const activeInput = block ? block.querySelector('input[name$="][active]"][type="checkbox"]') : null;
            const prefixMatch = activeInput ? activeInput.name.match(/^product\[([^\]]+)\]/) : null;
            const fieldKey = prefixMatch ? prefixMatch[1] : 'metal_variations';

            const labels = Array.from(document.querySelectorAll(`input[name^="product[option_addon_groups][${groupKey}]"][name$="[label]"]`)).map(el => el.value.trim()).filter(v => v);

            const state = {};
            list.querySelectorAll('.admin-band-addon-row').forEach(row => {
                const label = row.querySelector('input[type="hidden"]').value;
                state[label] = {
                    checked: row.querySelector('input[type="checkbox"]').checked,
                    surcharge: row.querySelector('input[type="number"]').value,
                };
            });

            list.innerHTML = labels.map((lbl, aIdx) => {
                const saved = state[lbl] || { checked: false, surcharge: '0' };
                return `
                    <div class="admin-band-addon-row" style="display:flex; align-items:center; justify-content:space-between; background:#f9fafb; padding:8px 12px; border-radius:6px; border:1px solid #e5e7eb;">
                        <label class="admin-checkbox" style="margin:0;">
                            <input type="checkbox" name="product[${fieldKey}][${mIdx}][addon_groups][${groupKey}][${aIdx}][active]" value="1" ${saved.checked ? 'checked' : ''}>
                            <span style="font-size:0.9em;">${lbl}</span>
                            <input type="hidden" name="product[${fieldKey}][${mIdx}][addon_groups][${groupKey}][${aIdx}][label]" value="${lbl}">
                        </label>
                        <div class="admin-field-inline" style="margin:0;">
                            <span style="font-size:0.85em; color:#6b7280; margin-right:6px;">Add-on:</span>
                            <div class="admin-input-wrap">
                                <span class="admin-input-prefix">£</span>
                                <input type="number" name="product[${fieldKey}][${mIdx}][addon_groups][${groupKey}][${aIdx}][surcharge]" value="${saved.surcharge}" step="0.01" min="0" style="width:80px; padding:4px 8px 4px 28px;">
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        });
    }

    document.addEventListener('input', (e) => {
        if (e.target.matches('input[name^="product[option_size_choices]"], input[name^="product[option_band_claw_metal_options]"], input[name^="product[option_addon_groups]"]')) {
            adminSyncMetalMatrix();
        }
    });

    document.addEventListener('click', (e) => {
        if (e.target.matches('.admin-add, .admin-remove')) {
            setTimeout(adminSyncMetalMatrix, 50); // wait for DOM update
        }
    });
    function adminToggleMetalDetails(checkbox) {
        const block = checkbox.closest('.admin-metal-matrix-block');
        const head = block.querySelector('.admin-metal-matrix-head');
        const details = block.querySelector('.admin-metal-details-wrap');
        const applyBtn = block.querySelector('.admin-metal-apply-btn');
        
        if (checkbox.checked) {
            head.style.borderBottom = '1px solid #e0e6eb';
            head.style.paddingBottom = '12px';
            applyBtn.style.display = 'block';
            details.style.display = 'block';
            // Trigger reflow
            details.offsetHeight;
            details.style.opacity = '1';
            details.style.transform = 'translateY(0)';
        } else {
            head.style.borderBottom = 'none';
            head.style.paddingBottom = '0';
            applyBtn.style.display = 'none';
            details.style.opacity = '0';
            details.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                if (!checkbox.checked) details.style.display = 'none';
            }, 300);
        }
    }

    function adminSelectAllSizes(btn) {
        const grid = btn.closest('.admin-field').querySelector('.admin-choice-grid');
        if (grid) {
            const boxes = grid.querySelectorAll('input[type="checkbox"]');
            const allChecked = Array.from(boxes).every(b => b.checked);
            boxes.forEach(b => b.checked = !allChecked);
        }
    }

    function adminApplyMetalToAll(btn, srcIdx) {
        if (!confirm('This will copy the pricing, description, sizes, and gallery from this metal to ALL other metals. Are you sure?')) return;
        
        const blocks = document.querySelectorAll('.admin-metal-matrix-block');
        const srcPrice = document.querySelector(`input[name="product[metal_variations][${srcIdx}][price]"]`)?.value || '';
        const srcOldPrice = document.querySelector(`input[name="product[metal_variations][${srcIdx}][old_price]"]`)?.value || '';
        const srcDesc = document.querySelector(`textarea[name="product[metal_variations][${srcIdx}][description]"]`)?.value || '';
        const srcFeat = document.querySelector(`textarea[name="product[metal_variations][${srcIdx}][features_text]"]`)?.value || '';
        
        // Collect checked sizes
        const srcSizesBoxes = document.querySelectorAll(`input[name="product[metal_variations][${srcIdx}][sizes][]"]`);
        const srcSizes = Array.from(srcSizesBoxes).map(b => ({ val: b.value, checked: b.checked }));
        
        let srcGallery = [];
        for (let i = 0; i < 6; i++) {
            srcGallery.push(document.querySelector(`input[name="metal_gallery_${srcIdx}_${i}_url"]`)?.value || '');
        }
        
        blocks.forEach((block, idx) => {
            if (idx === srcIdx) return;
            
            const trgPrice = document.querySelector(`input[name="product[metal_variations][${idx}][price]"]`);
            if (trgPrice) trgPrice.value = srcPrice;
            
            const trgOld = document.querySelector(`input[name="product[metal_variations][${idx}][old_price]"]`);
            if (trgOld) trgOld.value = srcOldPrice;
            
            const trgDesc = document.querySelector(`textarea[name="product[metal_variations][${idx}][description]"]`);
            if (trgDesc) trgDesc.value = srcDesc;
            
            const trgFeat = document.querySelector(`textarea[name="product[metal_variations][${idx}][features_text]"]`);
            if (trgFeat) trgFeat.value = srcFeat;
            
            const trgSizesBoxes = document.querySelectorAll(`input[name="product[metal_variations][${idx}][sizes][]"]`);
            trgSizesBoxes.forEach(b => {
                const match = srcSizes.find(s => s.val === b.value);
                if (match) b.checked = match.checked;
            });
            
            for (let j = 0; j < 6; j++) {
                const trgGal = document.querySelector(`input[name="metal_gallery_${idx}_${j}_url"]`);
                if (trgGal) trgGal.value = srcGallery[j];
            }
        });
        
        alert('Copied to all metals successfully.');
    }
  </script>
<?php endif; ?>
</body>
</html>
