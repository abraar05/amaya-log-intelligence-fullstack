<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Configuration loader. Reads config.php if present, otherwise safe defaults.
 * Values may be overridden by environment variables (useful on hosts that
 * inject secrets, and for CI).
 */
final class Config
{
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        $values = [];

        if (is_file($path)) {
            /** @noinspection PhpIncludeInspection */
            $loaded = require $path;
            if (is_array($loaded)) {
                $values = $loaded;
            }
        }

        $env = static function (string $key, $default = null) {
            $v = getenv($key);
            return ($v === false || $v === '') ? $default : $v;
        };

        // Database connection. DB_DRIVER=sqlite enables local testing.
        $driver = (string) $env('DB_DRIVER', $values['db']['driver'] ?? 'mysql');

        if ($driver === 'sqlite') {
            $values['db'] = [
                'driver'   => 'sqlite',
                'path'     => (string) $env('DB_PATH', $values['db']['path'] ?? dirname(__DIR__, 2) . '/storage/app.sqlite'),
            ];
        } else {
            $values['db'] = [
                'driver'   => 'mysql',
                'host'     => (string) $env('DB_HOST', $values['db']['host'] ?? 'localhost'),
                'port'     => (int)    $env('DB_PORT', $values['db']['port'] ?? 3306),
                'name'     => (string) $env('DB_NAME', $values['db']['name'] ?? ''),
                'user'     => (string) $env('DB_USER', $values['db']['user'] ?? ''),
                'pass'     => (string) $env('DB_PASS', $values['db']['pass'] ?? ''),
                'charset'  => (string) $env('DB_CHARSET', $values['db']['charset'] ?? 'utf8mb4'),
            ];
        }

        $values['app'] = array_merge([
            'name'       => 'Amaya Log Intelligence',
            'env'        => (string) $env('APP_ENV', 'production'),
            'timezone'   => (string) $env('APP_TZ', 'Asia/Dhaka'),
            'base_url'   => rtrim((string) $env('APP_BASE_URL', ''), '/'),
            'debug'      => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
        ], $values['app'] ?? []);

        $values['security'] = array_merge([
            'session_name'     => (string) $env('SESSION_NAME', 'amayali_sid'),
            'session_lifetime' => (int)    $env('SESSION_LIFETIME', 7200),
            'cookie_secure'    => filter_var($env('COOKIE_SECURE', 'true'), FILTER_VALIDATE_BOOLEAN),
            'cookie_samesite'  => (string) $env('COOKIE_SAMESITE', 'Lax'),
            'max_login_attempts' => (int)  $env('MAX_LOGIN_ATTEMPTS', 5),
            'lockout_seconds'    => (int)  $env('LOCKOUT_SECONDS', 900),
            'password_min'     => (int)    $env('PASSWORD_MIN', 10),
        ], $values['security'] ?? []);

        return new self($values);
    }

    public function get(string $dotPath, $default = null)
    {
        $node = $this->values;
        foreach (explode('.', $dotPath) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public function dbDriver(): string
    {
        return (string) $this->get('db.driver', 'mysql');
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('app.debug', false);
    }
}