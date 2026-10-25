<?php
// php -S 127.0.0.1:8099 tools/dev-router.php   (local testing only; production uses .htaccess)
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($p, '/api/')) { require __DIR__ . '/../api/index.php'; return true; }
return false;
