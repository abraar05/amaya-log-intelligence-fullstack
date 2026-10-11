<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Data-quality scanner.
 *
 * Each distinct issue category deducts a fixed number of points, so the score
 * is explainable and reproducible rather than a black-box number. The
 * weighting is surfaced in the API response and UI (see docs/DATA_MODEL.md,
 * which requires the score to disclose its weighting).
 */
final class QualityScanner
{
    public const PENALTY_PER_CATEGORY = 6;

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<int,array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    public static function scan(array $records, array $sources, int $staleMinutes = 60): array
    {
        $issues = [];

        if (!$records) {
            return [
                'score'       => null,
                'issues'      => [],
                'scanned_at'  => gmdate('c'),
                'records'     => 0,
                'formula'     => 'No records to score',
                'penalty_each' => self::PENALTY_PER_CATEGORY,
            ];
        }

        $total = count($records);

        // 1. Missing identifiers — rows that can never be reconciled.
        $missingImei = [];
        foreach ($records as $r) {
            if (Imei::normalize($r['imei_norm'] ?? $r['imei'] ?? '') === '') {
                $missingImei[] = $r;
            }
        }
        if ($missingImei) {
            $issues[] = self::issue(
                'high',
                'Missing device identifiers',
                sprintf(
                    '%d imported row%s ha%s no IMEI or serial number and cannot be reconciled.',
                    count($missingImei),
                    count($missingImei) === 1 ? '' : 's',
                    count($missingImei) === 1 ? 's' : 've'
                ),
                'Operations',
                'red',
                count($missingImei)
            );
        }

        // 2. Duplicate identifiers.
        $rec = Reconciler::run($records);
        if ($rec['duplicates']) {
            $extra = $rec['kpis']['duplicate_extra_rows'];
            $issues[] = self::issue(
                'high',
                'Duplicate IMEI identifiers',
                sprintf(
                    '%d IMEI%s appear%s more than once across %d redundant row%s.',
                    count($rec['duplicates']),
                    count($rec['duplicates']) === 1 ? '' : 's',
                    count($rec['duplicates']) === 1 ? 's' : '',
                    $extra,
                    $extra === 1 ? '' : 's'
                ),
                'Logistics',
                'red',
                $extra
            );
        }

        // 3. Missing invoice/reference on identifiable rows.
        $noReference = 0;
        foreach ($records as $r) {
            if (Imei::normalize($r['imei_norm'] ?? $r['imei'] ?? '') !== ''
                && !Imei::hasReference($r['invoice_number'] ?? null)) {
                $noReference++;
            }
        }
        if ($noReference) {
            $issues[] = self::issue(
                'high',
                'Missing invoice reference',
                sprintf(
                    '%d device record%s do%s not have a verified sales or transfer invoice.',
                    $noReference,
                    $noReference === 1 ? '' : 's',
                    $noReference === 1 ? 'es' : ''
                ),
                'Logistics',
                'red',
                $noReference
            );
        }

        // 4. Stale sources.
        $cutoff = time() - $staleMinutes * 60;
        $stale  = [];
        foreach ($sources as $s) {
            $last = $s['last_synced_at'] ?? null;
            if ($last === null || $last === '') {
                $stale[] = ($s['name'] ?? 'unnamed') . ' (never synced)';
                continue;
            }
            $ts = strtotime((string) $last);
            if ($ts === false || $ts < $cutoff) {
                $stale[] = (string) ($s['name'] ?? 'unnamed');
            }
        }
        if ($stale) {
            $issues[] = self::issue(
                'medium',
                'Stale data source' . (count($stale) === 1 ? '' : 's'),
                sprintf(
                    '%d source%s not refreshed in over %d minutes: %s.',
                    count($stale),
                    count($stale) === 1 ? ' has' : 's have',
                    $staleMinutes,
                    implode(', ', array_slice($stale, 0, 3)) . (count($stale) > 3 ? '…' : '')
                ),
                'Operations',
                'amber',
                count($stale)
            );
        }

        // 5. Incomplete records (missing routing fields).
        $incomplete = 0;
        foreach ($records as $r) {
            if (trim((string) ($r['department'] ?? '')) === ''
                || trim((string) ($r['record_date'] ?? '')) === '') {
                $incomplete++;
            }
        }
        if ($incomplete) {
            $issues[] = self::issue(
                'medium',
                'Incomplete records',
                sprintf(
                    '%d imported row%s missing a department or record date.',
                    $incomplete,
                    $incomplete === 1 ? ' is' : 's are'
                ),
                'Operations',
                'amber',
                $incomplete
            );
        }

        $score = max(0, 100 - (count($issues) * self::PENALTY_PER_CATEGORY));

        return [
            'score'        => $score,
            'issues'       => $issues,
            'scanned_at'   => gmdate('c'),
            'records'      => $total,
            'formula'      => sprintf('100 − %d per distinct issue category (max %d issues)', self::PENALTY_PER_CATEGORY, self::PENALTY_PER_CATEGORY),
            'penalty_each' => self::PENALTY_PER_CATEGORY,
            'summary'      => [
                'high'   => count(array_filter($issues, static fn($i) => $i['severity'] === 'high')),
                'medium' => count(array_filter($issues, static fn($i) => $i['severity'] === 'medium')),
            ],
        ];
    }

    private static function issue(string $severity, string $title, string $description, string $department, string $tone, int $affected): array
    {
        return [
            'severity'   => $severity,
            'title'      => $title,
            'description'=> $description,
            'department' => $department,
            'tone'       => $tone,
            'affected'   => $affected,
        ];
    }
}