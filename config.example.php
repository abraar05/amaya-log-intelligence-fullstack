<?php
/**
 * Amaya Log Intelligence — configuration.
 *
 * Copy to config.php and fill in your database credentials. config.php is
 * git-ignored; never commit real passwords. Environment variables
 * (DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_BASE_URL, …) override anything set
 * here, which is how most shared hosts inject secrets.
 */

return [
    'app' => [
        'name'     => 'Amaya Log Intelligence',
        'env'      => 'production',
        'timezone' => 'Asia/Dhaka',
        'base_url' => '',        // e.g. https://your-domain.tld  (leave blank if at doc root)
        'debug'    => false,
    ],

    'db' => [
        'driver'  => 'mysql',
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name'       => 'amayali_sid',
        'session_lifetime'   => 7200,   // idle timeout, seconds
        'cookie_secure'      => true,   // requires HTTPS
        'cookie_samesite'    => 'Lax',
        'max_login_attempts' => 5,
        'lockout_seconds'    => 900,
        'password_min'       => 10,
        'trust_proxy'        => false,  // only enable behind a trusted reverse proxy
    ],

    // Service-account OAuth token for reading PRIVATE Google Sheets.
    // Leave empty for public sheets. Never commit a real token.
    'google' => [
        'access_token' => '',
    ],
];