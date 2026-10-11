<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;

/**
 * Password hashing and brute-force protection.
 *
 * Uses password_hash()/password_verify() so the stored format tracks PHP's
 * default algorithm, and rate-limits repeated failures per username+IP.
 */
final class AuthService
{
    public function __construct(
        private Database $db,
        private int $maxAttempts = 5,
        private int $lockoutSeconds = 900
    ) {
    }

    /**
     * @return array{ok:bool, user?:array, error?:string, retry_after?:int}
     */
    public function attempt(string $username, string $password, string $ip): array
    {
        $username = trim($username);
        $this->recordAttempt($username, $ip);

        if ($this->isLockedOut($username)) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again later.', 'retry_after' => $this->lockoutSeconds];
        }

        $user = $db = $this->db->fetchOne(
            'SELECT id, username, password_hash, role, active FROM users WHERE username = ?',
            [$username]
        );

        if (!$user || !$this->verify($password, (string) $user['password_hash'])) {
            $this->recordFailure($username);
            // Uniform message: don't reveal whether the username exists.
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        if ((int) $user['active'] !== 1) {
            return ['ok' => false, 'error' => 'This account has been deactivated.'];
        }

        $this->recordSuccess($username, (int) $user['id']);
        return ['ok' => true, 'user' => $user];
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Enforce a minimum policy. Returns null when acceptable, else the reason.
     */
    public static function passwordProblem(string $password, int $minLength = 10): ?string
    {
        if (strlen($password) < $minLength) {
            return "Password must be at least {$minLength} characters.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain both letters and numbers.';
        }
        return null;
    }

    public function isLockedOut(string $username): bool
    {
        $row = $this->db->fetchOne(
            'SELECT failed_attempts, locked_until FROM users WHERE username = ?',
            [trim($username)]
        );
        if (!$row) {
            return false;
        }
        return $this->isLockedRow($row);
    }

    private function isLockedRow(array $row): bool
    {
        if ((int) ($row['failed_attempts'] ?? 0) < $this->maxAttempts) {
            return false;
        }
        $until = $row['locked_until'] ?? null;
        if ($until === null) {
            return false;
        }
        $ts = strtotime((string) $until);
        return $ts !== false && $ts > time();
    }

    private function recordAttempt(string $username, string $ip): void
    {
        $this->db->run(
            'INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)',
            [trim($username), $ip, gmdate('Y-m-d H:i:s')]
        );
        // Keep the table bounded.
        $this->db->run(
            'DELETE FROM login_attempts WHERE attempted_at < ?',
            [gmdate('Y-m-d H:i:s', time() - 86400)]
        );
    }

    private function recordFailure(string $username): void
    {
        // Increment and evaluate the lockout in PHP rather than in a SQL CASE.
        // MySQL and SQLite disagree about whether a CASE in an UPDATE sees
        // pre- or post-update column values, which made the lock silently
        // never engage. Reading-then-writing is identical on both.
        $row = $this->db->fetchOne(
            'SELECT id, failed_attempts FROM users WHERE username = ?',
            [trim($username)]
        );
        if (!$row) {
            return;
        }

        $attempts = (int) $row['failed_attempts'] + 1;
        $lockUntil = $attempts >= $this->maxAttempts
            ? gmdate('Y-m-d H:i:s', time() + $this->lockoutSeconds)
            : null;

        if ($lockUntil === null) {
            $this->db->run(
                'UPDATE users SET failed_attempts = ? WHERE id = ?',
                [$attempts, (int) $row['id']]
            );
        } else {
            $this->db->run(
                'UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?',
                [$attempts, $lockUntil, (int) $row['id']]
            );
        }
    }

    private function recordSuccess(string $username, int $userId): void
    {
        $this->db->run(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s'), $userId]
        );
        $this->db->run('DELETE FROM login_attempts WHERE username = ?', [trim($username)]);
    }

    public function createUser(string $username, string $password, string $role = 'viewer', ?string $email = null, ?int $departmentId = null): int
    {
        $this->db->run(
            'INSERT INTO users (username, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, ?)',
            [trim($username), $email, $this->hash($password), $role, $departmentId]
        );
        return $this->db->lastInsertId();
    }
}