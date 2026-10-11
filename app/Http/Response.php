<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;

/**
 * Security headers applied to every response.
 */
final class Response
{
    public static function json($data, int $status = 200, Config $config = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Emit a JSON error and terminate the request.
     *
     * Exiting is essential: without it a rejected request (e.g. failed CSRF)
     * would fall through into the handler and perform the side effect anyway.
     */
    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
        exit;
    }

    public static function redirect(string $location): void
    {
        header('Location: ' . $location, true, 302);
    }
}