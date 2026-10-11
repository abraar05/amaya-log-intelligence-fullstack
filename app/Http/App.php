<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Database;

/**
 * Per-process container. Simple singletons wired once at bootstrap.
 */
final class App
{
    private static ?Config $config = null;
    private static ?Database $db = null;

    public static function boot(Config $config): void
    {
        self::$config = $config;
        self::$db = Database::connect($config);
    }

    public static function bootWith(Config $config, Database $db): void
    {
        self::$config = $config;
        self::$db = $db;
    }

    public static function config(): Config
    {
        if (self::$config === null) {
            throw new \RuntimeException('Application not booted.');
        }
        return self::$config;
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            throw new \RuntimeException('Application not booted.');
        }
        return self::$db;
    }

    /**
     * Client IP, honouring a proxy header only when explicitly trusted.
     */
    public static function clientIp(Config $config): string
    {
        $trusted = (bool) $config->get('security.trust_proxy', false);
        if ($trusted && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}