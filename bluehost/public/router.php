<?php
// Dev router for PHP's built-in server (local testing only):
//   php -S 0.0.0.0:8080 -t public public/router.php
// Production on Bluehost uses Apache + .htaccess instead.

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (strpos($uri, '/api/v1') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // let the built-in server serve the static asset
}

// SPA fallback.
readfile(__DIR__ . '/index.html');
return true;
