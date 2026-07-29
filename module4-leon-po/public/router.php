<?php
/** Dev-only router for: php -S localhost:8000 -t public public/router.php
 *  Serves real files as-is, routes everything else to the front controller. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) return false;   // let the built-in server serve static files
require __DIR__ . '/index.php';
