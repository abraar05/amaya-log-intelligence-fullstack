<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;

/**
 * Orchestrates CSV ingestion: parse -> map -> normalize -> persist -> reconcile.
 */
final class ImportService
{
    public function __construct(private Database $db, private Repository $repo)
    {
    }

    /**
     * @return array{
     *   ok:bool, error?:string, total?:int, inserted?:int, skipped?:int,
     *   batch_id?:int, headers?:array, mapped?:array, reconciliation?:array
     * }
     */
    public function importCsv(string $contents, string $filename, ?int $sourceId = null, int $targetPercent = 98): array
    {
        if (trim($contents) === '') {
            return ['ok' => false, 'error' => 'The CSV file is empty.'];
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = CsvReader::detectDelimiter($firstLine);
        $rows      = CsvReader::parse($contents, $delimiter);

        if (count($rows) < 2) {
            return ['ok' => false, 'error' => 'The CSV needs a header row and at least one data row.'];
        }

        $headers = array_map(static fn($h) => trim((string) $h), $rows[0]);
        $map     = HeaderMapper::map($headers);

        if (!isset($map['imei'])) {
            return [
                'ok'      => false,
                'error'   => 'No IMEI or serial column was found. Expected a header such as "IMEI" or "Serial Number".',
                'headers' => $headers,
                'mapped'  => $map,
            ];
        }

        $body     = array_slice($rows, 1);
        $prepared = [];
        $skipped  = 0;

        foreach ($body as $row) {
            $extracted = HeaderMapper::extract($row, $map);
            $imeiRaw   = trim($extracted['imei']);

            if ($imeiRaw === '') {
                // A row with no identifier can't be reconciled; count and drop it.
                $skipped++;
                continue;
            }

            $norm = Imei::normalize($imeiRaw);
            if ($norm === '') {
                $skipped++;
                continue;
            }

            // Preserve the original string exactly; never cast to int.
            $extracted['imei']      = $imeiRaw;
            $extracted['imei_norm'] = $norm;
            $prepared[] = $extracted;
        }

        if (!$prepared) {
            return [
                'ok'      => false,
                'error'   => 'No rows with a usable IMEI were found.',
                'skipped' => $skipped,
            ];
        }

        $batchId = $this->repo->createBatch($sourceId, $filename, $headers, count($body));

        $inserted = $this->repo->insertRecords($batchId, $sourceId, $prepared);
        $this->repo->finishBatch($batchId, $inserted, $skipped);

        if ($sourceId !== null) {
            $this->repo->touchSource($sourceId, 'connected');
        }

        $reconciliation = Reconciler::run($this->repo->recordsForReconciliation(), $targetPercent);
        $this->repo->applyMatchStatuses($reconciliation['statuses']);

        return [
            'ok'            => true,
            'total'         => count($body),
            'inserted'      => $inserted,
            'skipped'       => $skipped,
            'batch_id'      => $batchId,
            'headers'       => $headers,
            'mapped'        => $map,
            'reconciliation'=> $reconciliation['kpis'],
        ];
    }
}