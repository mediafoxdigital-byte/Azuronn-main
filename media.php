<?php
/**
 * Serve an uploaded media file from the persistent upload directory.
 *
 * The public URL stays /assets/uploads/admin/<filename> so existing content
 * does not need to be rewritten. Apache and the local router send requests for
 * files that are outside the deployed checkout through this endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/supabase.php';

$configuredUploadsRoot = app_runtime_config_value('uploads_root_path') ?: getenv('AZURONN_UPLOADS_ROOT');
if ($configuredUploadsRoot === false || trim((string) $configuredUploadsRoot) === '') {
    $configuredUploadsRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'azuronn-media';
}

$fileName = rawurldecode((string) ($_GET['file'] ?? ''));
if ($fileName === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $fileName)) {
    http_response_code(404);
    exit;
}

$fileCandidates = [
    rtrim((string) $configuredUploadsRoot, '/\\') . DIRECTORY_SEPARATOR . $fileName,
    __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . $fileName,
];
$filePath = '';
foreach (array_values(array_unique($fileCandidates)) as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        $filePath = $candidate;
        break;
    }
}
if ($filePath === '') {
    http_response_code(404);
    exit;
}

$mime = '';
if (class_exists('finfo')) {
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
}
if ($mime === '' || $mime === 'application/octet-stream') {
    $mime = function_exists('mime_content_type') ? (string) mime_content_type($filePath) : '';
}
if ($mime === '') {
    $mime = 'application/octet-stream';
}

$size = (int) filesize($filePath);
$modified = (int) filemtime($filePath);
$etag = '"' . md5($fileName . ':' . $size . ':' . $modified) . '"';
$lastModified = gmdate('D, d M Y H:i:s', $modified) . ' GMT';

header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

$ifNoneMatch = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
if (
    ($ifNoneMatch !== '' && str_contains($ifNoneMatch, $etag))
    || ($ifNoneMatch === '' && $ifModifiedSince !== false && $ifModifiedSince >= $modified)
) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}

$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if (preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
    if ($matches[1] === '') {
        $suffixLength = max(0, (int) $matches[2]);
        $start = max(0, $size - $suffixLength);
        $end = $size - 1;
    } else {
        $start = (int) $matches[1];
        $end = $matches[2] !== '' ? (int) $matches[2] : $size - 1;
    }
    $start = max(0, $start);
    $end = min($size - 1, $end);

    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Length: ' . $length);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Accept-Ranges: bytes');
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        http_response_code(404);
        exit;
    }
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

readfile($filePath);
