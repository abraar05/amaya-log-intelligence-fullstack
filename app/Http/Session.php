<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Core\Database;
use App\Domain\AuthService;

/**
 * Session bootstrap with hardened cookie defaults.
 */
final class Session
{
    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name((string) $config->get('security.session_name', 'amayali_sid'));
        session_set_cookie_params([
            'lifetime' => (int) $config->get('security.session_lifetime', 7200),
            'path'     => '/',
            'domain'   => '',
            // Secure cookies require HTTPS; enabling them on plain HTTP would
            // silently break sessions on local/dev installs.
            'secure'   => $https || (bool) $config->get('security.cookie_secure', false),
            'httponly' => true,
            'samesite' => (string) $config->get('security.cookie_samesite', 'Lax'),
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();

        $now = time();
        $idleLimit = (int) $config->get('security.session_lifetime', 7200);
        if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $idleLimit) {
            self::destroy();
            session_start();
            $_SESSION['flash'] = 'Session expired. Please sign in again.';
        }
        $_SESSION['last_activity'] = $now;
    }

    public static function user(Database $db): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $user = $db->fetchOne(
            'SELECT id, username, email, role, department_id, active FROM users WHERE id = ? AND active = 1',
            [(int) $_SESSION['user_id']]
        );
        return $user ?: null;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = time();
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
    }

    public static function requireLogin(Config $config): ?array
    {
        $db = App::db();
        $user = self::user($db);
        if ($user === null) {
            if (self::wantsJson()) {
                Response::error('Authentication required', 401);
            } else {
                Response::redirect(self::baseUrl($config) . '/login.php');
            }
            exit;
        }
        return $user;
    }

    public static function requireRole(Config $config, array $roles): ?array
    {
        $user = self::requireLogin($config);
        if ($user !== null && !in_array($user['role'], $roles, true)) {
            Response::error('Insufficient permissions for this action', 403);
            exit;
        }
        return $user;
    }

    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr    = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        return $xhr || stripos($accept, 'application/json') !== false;
    }

    public static function baseUrl(Config $config): string
    {
        return (string) $config->get('app.base_url', '');
    }

    public static function flash(): ?string
    {
        if (!empty($_SESSION['flash'])) {
            $m = (string) $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $m;
        }
        return null;
    }
}