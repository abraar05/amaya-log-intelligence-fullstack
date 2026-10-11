<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Schema definition expressed as portable column specs, rendered per-driver.
 * Keeping one definition avoids drift between MySQL and SQLite test runs.
 */
final class Schema
{
    /**
     * @return array<string, array{cols: string, indexes: string[]}>
     */
    public static function tables(): array
    {
        return [
            'departments' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'name VARCHAR(120) NOT NULL UNIQUE',
                    'owner_name VARCHAR(120) NULL',
                    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [],
            ],

            'users' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'username VARCHAR(80) NOT NULL UNIQUE',
                    'email VARCHAR(190) NULL UNIQUE',
                    'password_hash VARCHAR(255) NOT NULL',
                    'role VARCHAR(30) NOT NULL DEFAULT \'viewer\'',
                    'department_id INTEGER NULL',
                    'active TINYINT NOT NULL DEFAULT 1',
                    'failed_attempts INTEGER NOT NULL DEFAULT 0',
                    'locked_until DATETIME NULL',
                    'last_login_at DATETIME NULL',
                    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [
                    'CREATE INDEX idx_users_department ON users (department_id)',
                ],
            ],

            'data_sources' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'department_id INTEGER NULL',
                    'name VARCHAR(180) NOT NULL UNIQUE',
                    'source_type VARCHAR(50) NOT NULL',
                    'source_url TEXT NULL',
                    'sheet_gid VARCHAR(40) NULL',
                    'last_synced_at DATETIME NULL',
                    'last_status VARCHAR(30) NOT NULL DEFAULT \'pending\'',
                    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [
                    'CREATE INDEX idx_sources_department ON data_sources (department_id)',
                ],
            ],

            'import_batches' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'source_id INTEGER NULL',
                    'filename VARCHAR(255) NOT NULL',
                    'rows_total INTEGER NOT NULL DEFAULT 0',
                    'rows_inserted INTEGER NOT NULL DEFAULT 0',
                    'rows_skipped INTEGER NOT NULL DEFAULT 0',
                    'headers_json TEXT NULL',
                    'status VARCHAR(30) NOT NULL DEFAULT \'completed\'',
                    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [
                    'CREATE INDEX idx_batches_source ON import_batches (source_id)',
                ],
            ],

            'records' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'batch_id INTEGER NULL',
                    'source_id INTEGER NULL',
                    'imei VARCHAR(64) NOT NULL',
                    'imei_norm VARCHAR(64) NOT NULL',
                    'serial_number VARCHAR(96) NULL',
                    'device_model VARCHAR(190) NULL',
                    'invoice_number VARCHAR(190) NULL',
                    'department VARCHAR(120) NULL',
                    'record_date VARCHAR(40) NULL',
                    'movement_type VARCHAR(60) NULL',
                    'quantity VARCHAR(40) NULL',
                    'warehouse VARCHAR(120) NULL',
                    'status VARCHAR(60) NULL',
                    'notes TEXT NULL',
                    'match_status VARCHAR(20) NOT NULL DEFAULT \'unreviewed\'',
                    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [
                    'CREATE INDEX idx_records_imei ON records (imei_norm)',
                    'CREATE INDEX idx_records_batch ON records (batch_id)',
                    'CREATE INDEX idx_records_match ON records (match_status)',
                ],
            ],

            'source_links' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'label VARCHAR(190) NOT NULL',
                    'url TEXT NOT NULL',
                    'category VARCHAR(80) NULL',
                    'sort_order INTEGER NOT NULL DEFAULT 0',
                    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [],
            ],

            'audit_log' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'actor VARCHAR(120) NOT NULL',
                    'action VARCHAR(120) NOT NULL',
                    'details TEXT NULL',
                    'result VARCHAR(40) NOT NULL DEFAULT \'Success\'',
                    'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [
                    'CREATE INDEX idx_audit_created ON audit_log (created_at)',
                ],
            ],

            'login_attempts' => [
                'cols' => [
                    'id INTEGER PRIMARY KEY AUTOINCREMENT',
                    'username VARCHAR(80) NOT NULL',
                    'ip VARCHAR(64) NOT NULL',
                    'attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'indexes' => [
                    'CREATE INDEX idx_attempts_username ON login_attempts (username, attempted_at)',
                ],
            ],
        ];
    }

    /** Departments seeded on install. */
    public static function defaultDepartments(): array
    {
        return ['Logistics', 'Sales', 'Finance', 'Human resources', 'Customer service', 'Operations'];
    }

    /**
     * Render DDL for the active driver.
     *
     * Tables use CREATE TABLE IF NOT EXISTS. Index statements are appended
     * separately by indexDdl() so the Migrator can create them idempotently.
     *
     * @return string[]
     */
    public static function ddl(string $driver): array
    {
        $statements = [];
        foreach (self::tables() as $table => $spec) {
            $columns = [];
            foreach ($spec['cols'] as $col) {
                $columns[] = self::renderColumn($col, $driver);
            }
            $statements[] = sprintf(
                'CREATE TABLE IF NOT EXISTS %s (%s)',
                $table,
                implode(', ', $columns)
            );
        }
        return $statements;
    }

    /**
     * Standalone index DDL, rendered idempotently per driver.
     *
     * @return string[]
     */
    public static function indexDdl(string $driver): array
    {
        $out = [];
        foreach (self::tables() as $spec) {
            foreach ($spec['indexes'] as $indexSql) {
                if ($driver === 'sqlite') {
                    $out[] = preg_replace('/^CREATE INDEX\b/i', 'CREATE INDEX IF NOT EXISTS', $indexSql) ?? $indexSql;
                } else {
                    // MySQL: guarded by the Migrator, which tolerates duplicates.
                    $out[] = $indexSql;
                }
            }
        }
        return $out;
    }

    /**
     * Translate a portable column spec into driver-specific SQL.
     */
    private static function renderColumn(string $col, string $driver): string
    {
        // SQLite uses AUTOINCREMENT only with INTEGER PRIMARY KEY.
        if ($driver === 'mysql') {
            $col = preg_replace('/\bAUTOINCREMENT\b/i', 'AUTO_INCREMENT', $col);
            $col = str_replace('INTEGER PRIMARY KEY AUTO_INCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $col);
            $col = preg_replace('/^(\w+) INTEGER PRIMARY KEY AUTO_INCREMENT/i', '$1 INT AUTO_INCREMENT PRIMARY KEY', $col);
            // MySQL needs explicit index length for TEXT/unique; TINYINT is fine.
            $col = str_replace('TINYINT', 'TINYINT(1)', $col);
            return $col;
        }

        return $col;
    }
}