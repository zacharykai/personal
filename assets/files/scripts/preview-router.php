<?php
/**
 * preview-router.php
 * Router for publish.php's local preview (`php -S`): serves clean URLs like
 * /notes/tend from notes/tend.html, the way the live server does.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = $_SERVER['DOCUMENT_ROOT'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (is_file($root . $path)) {
    return false;
}
foreach ([$path . '.html', rtrim($path, '/') . '/index.html'] as $candidate) {
    if (is_file($root . $candidate)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($root . $candidate);
        return true;
    }
}
http_response_code(404);
readfile($root . '/404.html');
return true;
