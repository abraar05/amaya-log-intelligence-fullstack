<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Reconciliation engine.
 *
 * Operates on a flat list of records and returns match status plus KPIs.
 * Kept free of database access so the rules can be unit tested directly and
 * reused by both the CLI seeder and the HTTP API.
 */
final class Reconciler
{
    /**
     * @param array<int,array<string,mixed>> $records
     * @return array{
     *   statuses: array<string,string>, kpis: array<string,mixed>,
     *   duplicates: array<int,array<string,mixed>>,
     *   unmatched: array<int,string>, matched: array<int,string>
     * }
     */
    public static function run(array $records, int $targetPercent = 98): array
    {
        $groups = [];
        foreach ($records as $record) {
            $key = Imei::normalize($record['imei_norm'] ?? $record['imei'] ?? '');
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $record;
        }

        $statuses = [];
        $duplicates = [];
        $unmatched = [];
        $matched = [];

        foreach ($groups as $key => $rows) {
            $hasReference = false;
            foreach ($rows as $row) {
                if (Imei::hasReference($row['invoice_number'] ?? null)) {
                    $hasReference = true;
                    break;
                }
            }

            $occurrences = count($rows);
            $status = Imei::classify($occurrences, $hasReference);
            $statuses[$key] = $status;

            if ($status === Imei::STATUS_DUPLICATE) {
                $duplicates[] = [
                    'imei'     => $key,
                    'count'    => $occurrences,
                    'excess'   => $occurrences - 1,
                    'sources'  => self::sourceLabels($rows),
                    'has_reference' => $hasReference,
                ];
            } elseif ($status === Imei::STATUS_MATCHED) {
                $matched[] = $key;
            } else {
                $unmatched[] = $key;
            }
        }

        // Eligible = identifiers seen exactly once. Duplicates are reported
        // separately and must not silently deflate the match rate.
        $eligible = count($matched) + count($unmatched);
        $rate = $eligible > 0 ? round((count($matched) / $eligible) * 100, 1) : 0.0;

        $totalRows     = count($records);
        $totalUnique   = count($groups);
        $duplicateKeys = count($duplicates);

        return [
            'statuses'   => $statuses,
            'duplicates' => $duplicates,
            'unmatched'  => $unmatched,
            'matched'    => $matched,
            'kpis'       => [
                'total_rows'          => $totalRows,
                'total_unique_imeis'  => $totalUnique,
                'eligible_imeis'      => $eligible,
                'matched_records'     => count($matched),
                'unmatched_imeis'     => count($unmatched),
                'duplicate_imeis'     => $duplicateKeys,
                'duplicate_extra_rows'=> array_sum(array_column($duplicates, 'excess')),
                'missing_imeis'       => max(0, $totalRows - $totalUnique),
                'match_rate'          => $rate,
                'target_rate'         => $targetPercent,
                'meets_target'        => $eligible > 0 && $rate >= $targetPercent,
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private static function sourceLabels(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $label = $row['source_name'] ?? (isset($row['source_id']) ? 'source#' . $row['source_id'] : null);
            if ($label !== null && $label !== '' && !in_array($label, $labels, true)) {
                $labels[] = (string) $label;
            }
        }
        return $labels;
    }
}