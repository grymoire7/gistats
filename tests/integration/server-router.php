<?php
// Routes all non-file requests to index.php, mirroring the .htaccess rule.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/../../' . ltrim($path, '/');
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/../../index.php';
