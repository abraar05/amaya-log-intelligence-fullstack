<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;

/**
 * Persistence for departments, sources, batches, records and audit entries.
 */
final class Repository
{
    public function __construct(private Database $db)
    {
    }

    // ---------- departments ----------

    public function departments(): array
    {
        return $this->db->fetchAll('SELECT id, name, owner_name FROM departments ORDER BY name');
    }

    public function departmentIdByName(string $name): ?int
    {
        $id = $this->db->fetchValue('SELECT id FROM departments WHERE name = ?', [trim($name)]);
        return $id === null ? null : (int) $id;
    }

    // ---------- sources ----------

    public function sources(): array
    {
        return $this->db->fetchAll(
            'SELECT ds.*, d.name AS department_name,
                    (SELECT COUNT(*) FROM records r WHERE r.source_id = ds.id) AS record_count,
                    (SELECT COUNT(*) FROM import_batches b WHERE b.source_id = ds.id) AS batch_count
             FROM data_sources ds
             LEFT JOIN departments d ON d.id = ds.department_id
             ORDER BY ds.created_at DESC, ds.id DESC'
        );
    }

    public function source(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT ds.*, d.name AS department_name FROM data_sources ds
             LEFT JOIN departments d ON d.id = ds.department_id WHERE ds.id = ?',
            [$id]
        );
    }

    public function createSource(array $data): int
    {
        $this->db->run(
            'INSERT INTO data_sources (department_id, name, source_type, source_url, sheet_gid, last_synced_at, last_status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['department_id'] ?? null,
                trim((string) ($data['name'] ?? '')),
                (string) ($data['source_type'] ?? 'Manual CSV upload'),
                $data['source_url'] ?? null,
                $data['sheet_gid'] ?? null,
                $data['last_synced_at'] ?? null,
                $data['last_status'] ?? 'pending',
            ]
        );
        return $this->db->lastInsertId();
    }

    public function touchSource(int $id, string $status = 'connected'): void
    {
        $this->db->run(
            'UPDATE data_sources SET last_synced_at = ?, last_status = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s'), $status, $id]
        );
    }

    public function deleteSource(int $id): void
    {
        $this->db->run('DELETE FROM data_sources WHERE id = ?', [$id]);
    }

    // ---------- batches ----------

    public function createBatch(?int $sourceId, string $filename, array $headers, int $total): int
    {
        $this->db->run(
            'INSERT INTO import_batches (source_id, filename, rows_total, headers_json) VALUES (?, ?, ?, ?)',
            [$sourceId, $filename, $total, json_encode($headers)]
        );
        return $this->db->lastInsertId();
    }

    public function finishBatch(int $batchId, int $inserted, int $skipped, string $status = 'completed'): void
    {
        $this->db->run(
            'UPDATE import_batches SET rows_inserted = ?, rows_skipped = ?, status = ? WHERE id = ?',
            [$inserted, $skipped, $status, $batchId]
        );
    }

    // ---------- records ----------

    /**
     * Bulk insert. Chunked to stay inside max_allowed_packet on shared hosts.
     */
    public function insertRecords(int $batchId, ?int $sourceId, array $records): int
    {
        if (!$records) {
            return 0;
        }
        $inserted = 0;
        $chunks   = array_chunk($records, 200);

        foreach ($chunks as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $r) {
                $placeholders[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $array = $params;
                $params = array_merge($params, [
                    $batchId,
                    $sourceId,
                    (string) ($r['imei'] ?? ''),
                    (string) ($r['imei_norm'] ?? ''),
                    $r['serial_number'] ?? null,
                    $r['device_model'] ?? null,
                    $r['invoice_number'] ?? null,
                    $r['department'] ?? null,
                    $r['record_date'] ?? null,
                    $r['movement_type'] ?? null,
                    $r['quantity'] ?? null,
                    $r['warehouse'] ?? null,
                    $r['status'] ?? null,
                    $r['notes'] ?? null,
                ]);
            }
            $sql = 'INSERT INTO records
                (batch_id, source_id, imei, imei_norm, serial_number, device_model, invoice_number,
                 department, record_date, movement_type, quantity, warehouse, status, notes)
                VALUES ' . implode(',', $placeholders);
            $this->db->run($sql, $params);
            $inserted += count($chunk);
        }

        return $inserted;
    }

    public function recordsForReconciliation(): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.imei, r.imei_norm, r.serial_number, r.device_model, r.invoice_number,
                    r.department, r.record_date, r.source_id, r.match_status, r.batch_id,
                    COALESCE(ds.name, NULL) AS source_name
             FROM records r
             LEFT JOIN data_sources ds ON ds.id = r.source_id'
        );
    }

    /**
     * Persist computed match statuses so the UI can filter without recomputing.
     */
    public function applyMatchStatuses(array $statuses): void
    {
        if (!$statuses) {
            return;
        }
        $this->db->run('UPDATE records SET match_status = ? WHERE 1 = 0', [Imei::STATUS_UNREVIEWED]);
        $this->db->transaction(function (Database $db) use ($statuses): void {
            foreach (array_chunk($statuses, 200, true) as $chunk) {
                $cases = [];
                $params = [];
                $ids   = [];
                foreach ($chunk as $imeiNorm => $status) {
                    $cases[] = 'WHEN ? THEN ?';
                    $params[] = $imeiNorm;
                    $params[] = $status;
                }
                $where = [];
                foreach (array_keys($chunk) as $imeiNorm) {
                    $where[] = '?';
                    $params[] = $imeiNorm;
                }
                $sql = 'UPDATE records SET match_status = CASE imei_norm ' . implode(' ', $cases)
                     . ' END WHERE imei_norm IN (' . implode(',', $where) . ')';
                $db->run($sql, $params);
            }
        });
    }

    public function recordCount(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM records');
    }

    public function batchCount(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM import_batches');
    }

    // ---------- audit ----------

    public function audit(string $actor, string $action, ?string $details = null, string $result = 'Success'): void
    {
        $this->db->run(
            'INSERT INTO audit_log (actor, action, details, result) VALUES (?, ?, ?, ?)',
            [$actor, $action, $details, $result]
        );
    }

    public function auditLog(int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT actor, action, details, result, created_at FROM audit_log ORDER BY id DESC LIMIT ?',
            [$limit]
        );
    }
}