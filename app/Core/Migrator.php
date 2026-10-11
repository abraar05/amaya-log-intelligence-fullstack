<?php
declare(strict_types=1);

namespace App\Core;

use App\Domain\Seed;
use PDOException;

/**
 * Idempotent installer. Safe to run repeatedly.
 */
final class Migrator
{
    public function __construct(private Database $db, private Config $config)
    {
    }

    public function migrate(): array
    {
        $driver = $this->db->driver();
        $log    = [];

        foreach (Schema::ddl($driver) as $sql) {
            $this->db->exec($sql);
            $log[] = 'ok   ' . substr($sql, 0, 60);
        }

        foreach (Schema::indexDdl($driver) as $sql) {
            try {
                $this->db->exec($sql);
                $log[] = 'ok   ' . substr($sql, 0, 60);
            } catch (PDOException $e) {
                // MySQL raises a duplicate-key error rather than a notice.
                if (stripos($e->getMessage(), 'exists') !== false
                    || stripos($e->getMessage(), 'duplicate key') !== false) {
                    $log[] = 'skip ' . substr($sql, 0, 60);
                    continue;
                }
                throw $e;
            }
        }

        Seed::departments($this->db, Schema::defaultDepartments());
        Seed::sourceLinks($this->db);
        Seed::spreadsheets($this->db);

        return $log;
    }
}