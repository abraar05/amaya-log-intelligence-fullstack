<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. Supports MySQL (production) and SQLite (local/CI testing)
 * so the same domain logic can be verified without a database server.
 */
final class Database
{
    private PDO $pdo;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function connect(Config $config): self
    {
        $driver = $config->dbDriver();

        if ($driver === 'sqlite') {
            $path = (string) $config->get('db.path');
            $dir  = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new PDOException('Unable to create SQLite directory: ' . $dir);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            return new self($pdo);
        }

        $host    = (string) $config->get('db.host');
        $port    = (int)    $config->get('db.port', 3306);
        $name    = (string) $config->get('db.name');
        $charset = (string) $config->get('db.charset', 'utf8mb4');

        if ($name === '') {
            throw new PDOException('Database name is not configured.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        $pdo = new PDO($dsn, (string) $config->get('db.user'), (string) $config->get('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return new self($pdo);
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = [])
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn)
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn($this);
            if ($own) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}