<?php
/**
 * SupaBein configuration — never web-served.
 * Copy this file to config/secrets.php and fill in real values.
 */
return [
    // Database
    'DB_DSN'  => 'mysql:host=localhost;dbname=supabein;charset=utf8mb4',
    'DB_USER' => 'supabein_user',
    'DB_PASS' => 'CHANGE_ME',

    // JWT
    'JWT_SECRET' => 'CHANGE_ME_USE_256_BIT_RANDOM_HEX',
    'JWT_ALGO'   => 'HS256',
    'JWT_TTL'    => 3600,

    // Filesystem paths (absolute)
    'STORAGE_PATH' => dirname(__DIR__) . '/storage',
    'SITES_PATH'   => dirname(__DIR__) . '/sites',

    // Deploy limits
    'MAX_DEPLOY_BYTES' => 52428800, // 50 MB

    // API base URL (no trailing slash)
    'API_BASE_URL' => 'http://localhost/api',

    // CORS allowed origin (* for dev)
    'CORS_ORIGIN' => '*',
];
