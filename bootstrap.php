<?php
declare(strict_types=1);

/**
 * Shared entry point. Each public script includes this file first.
 *
 * The project root is resolved from this file's own location, not from the
 * including script, so bin/ and public/ callers both resolve correctly.
 */

define('APP_START', microtime(true));

// __DIR__ is already this file's directory (the project root).
$appRoot = __DIR__;

require $appRoot . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($appRoot . '/app');

use App\Core\Config;
use App\Http\App;

$config = Config::load($appRoot . '/public/config.php');

date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

// Hard requirements. Fail loudly and clearly rather than emitting a fatal
// error deep in request handling, which is harder to diagnose on shared hosts.
$requiredExtensions = ['pdo', 'json', 'mbstring', 'session'];
$missing = array_values(array_filter(
    $requiredExtensions,
    static fn(string $ext): bool => !extension_loaded($ext)
));
if ($missing) {
    http_response_code(500);
    $detail = 'Missing required PHP extension(s): ' . implode(', ', $missing);
    error_log('[amaya] ' . $detail);
    header('Content-Type: text/plain; charset=utf-8');
    echo $config->isDebug()
        ? $detail . "\nEnable them in your host's PHP configuration.\n"
        : "Service temporarily unavailable.\n";
    exit;
}

if ($config->isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Convert warnings/notices into exceptions so nothing fails silently.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    App::boot($config);
} catch (Throwable $e) {
    http_response_code(500);
    if ($config->isDebug()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Database connection failed.\n\n" . $e->getMessage();
    } else {
        error_log('[amaya] boot failure: ' . $e->getMessage());
        echo 'Service temporarily unavailable.';
    }
    exit;
}

return $config;