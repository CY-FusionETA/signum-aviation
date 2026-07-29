<?php
/**
 * Bootstrap — every entry point (public/index.php, cli/*) includes this first.
 * Loads config, registers the App\ autoloader, sets the timezone, and (for web
 * requests) starts the session. Ported from Starship, trimmed to Module 4.
 */
declare(strict_types=1);

if (!defined('SIGNUM_APP')) define('SIGNUM_APP', 1);

define('APP_ROOT', dirname(__DIR__));            // .../signum-leon-po
define('SRC_ROOT', APP_ROOT . '/src');
define('STORAGE_ROOT', APP_ROOT . '/storage');

// --- config ---------------------------------------------------------
$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration missing. Copy config/config.sample.php to config/config.php.');
}
$GLOBALS['config'] = require $configFile;

date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Asia/Kuala_Lumpur');

// --- autoloader (App\ -> src/) --------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = SRC_ROOT . '/' . $rel . '.php';
    if (is_file($file)) require $file;
});

// --- error handling -------------------------------------------------
error_reporting(E_ALL);
$isProd = (($GLOBALS['config']['app']['env'] ?? 'production') === 'production');
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
@mkdir(STORAGE_ROOT . '/logs', 0770, true);
ini_set('error_log', STORAGE_ROOT . '/logs/php-error.log');

/** Global config accessor (dotted path). */
function cfg(string $path, $default = null) {
    $parts = explode('.', $path);
    $node  = $GLOBALS['config'];
    foreach ($parts as $p) {
        if (!is_array($node) || !array_key_exists($p, $node)) return $default;
        $node = $node[$p];
    }
    return $node;
}

/** HTML-escape helper. */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
