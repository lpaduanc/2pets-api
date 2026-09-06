<?php

/**
 * OPcache reset endpoint for development.
 *
 * Since opcache.validate_timestamps=0, code changes require
 * an explicit cache clear. Call this from the host:
 *   curl http://localhost:8000/opcache-clear.php
 *
 * WARNING: This file MUST be removed or protected in production.
 */
if (PHP_SAPI === 'cli') {
    echo "This script must be run via the web server (PHP-FPM).\n";
    exit(1);
}

// Only allow in local/dev environments
$env = getenv('APP_ENV') ?: 'production';
if ($env === 'production') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden in production']);
    exit;
}

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo json_encode([
        'status' => 'ok',
        'message' => 'OPcache cleared successfully',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'OPcache extension not loaded']);
}
