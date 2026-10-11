<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Maps arbitrary spreadsheet headers onto the canonical field set.
 *
 * Real-world exports rarely use exact column names, so each canonical field
 * accepts a list of aliases matched case-insensitively with spaces,
 * underscores and punctuation normalised away.
 */
final class HeaderMapper
{
    /** Canonical field => accepted aliases. */
    public const FIELDS = [
        'imei'           => ['imei', 'imeino', 'imeinumber', 'imeiserial', 'serial', 'serialno', 'serialnumber', 'imeiserialnumber', 'imeicode', 'deviceid', 'identifier'],
        'serial_number'  => ['serial', 'serialno', 'serialnumber', 'sn'],
        'device_model'   => ['device', 'model', 'devicename', 'product', 'productname', 'item', 'itemname', 'device model'],
        'invoice_number' => ['invoice', 'invoiceno', 'invoicenumber', 'bill', 'billno', 'reference', 'ref', 'refno', 'document', 'docno'],
        'department'     => ['department', 'dept', 'division', 'team', 'section', 'costcenter'],
        'record_date'    => ['date', 'recorddate', 'transactiondate', 'postingdate', 'entrydate', 'day', 'datetime'],
        'movement_type'  => ['movement', 'movementtype', 'type', 'transactiontype', 'transaction', 'action'],
        'quantity'       => ['quantity', 'qty', 'units', 'count', 'pieces', 'nos'],
        'warehouse'      => ['warehouse', 'location', 'store', 'site', 'godown', 'branch', 'hub'],
        'status'         => ['status', 'state', 'stage', 'result'],
        'notes'          => ['notes', 'note', 'remarks', 'remark', 'comment', 'comments', 'description'],
    ];

    /** Canonical field => stored column on the records table. */
    public const COLUMNS = [
        'imei'           => 'imei',
        'serial_number'  => 'serial_number',
        'device_model'   => 'device_model',
        'invoice_number' => 'invoice_number',
        'department'     => 'department',
        'record_date'    => 'record_date',
        'movement_type'  => 'movement_type',
        'quantity'       => 'quantity',
        'warehouse'      => 'warehouse',
        'status'         => 'status',
        'notes'          => 'notes',
    ];

    public static function normalizeHeader(string $header): string
    {
        $value = strtolower(trim($header));
        $value = str_replace(["\xEF\xBB\xBF"], '', $value);
        // Strip punctuation but keep alphanumerics and spaces.
        $value = preg_replace('/[^a-z0-9\s]/', '', $value) ?? '';
        // Collapse separators: underscores/dots become spaces, then squeeze.
        $value = preg_replace('/[\s_]+/', ' ', $value) ?? '';
        return trim($value);
    }

    /**
     * @param array<int,string> $headers
     * @return array<string,int> canonical field => column index
     */
    public static function map(array $headers): array
    {
        $normalized = array_map([self::class, 'normalizeHeader'], $headers);
        $map = [];

        foreach (self::FIELDS as $field => $aliases) {
            $index = self::match($normalized, $aliases);
            if ($index !== null) {
                $map[$field] = $index;
            }
        }

        // "serial" can map to either imei or serial_number. If an explicit IMEI
        // column already exists, let serial_number take the "serial" column
        // instead of both pointing at the same index.
        if (isset($map['imei'], $map['serial_number']) && $map['imei'] === $map['serial_number']) {
            unset($map['serial_number']);
        }

        return $map;
    }

    /**
     * Exact alias match first, then a prefix/contains match.
     *
     * @param array<int,string> $normalizedHeaders
     * @param array<int,string> $aliases
     */
    private static function match(array $normalizedHeaders, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            foreach ($normalizedHeaders as $i => $header) {
                if ($header === $alias) {
                    return $i;
                }
            }
        }
        foreach ($aliases as $alias) {
            foreach ($normalizedHeaders as $i => $header) {
                if ($header !== '' && strlen($alias) >= 4 && strpos($header, $alias) === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Build a row array ready for insertion.
     *
     * @param array<int,string> $row
     * @param array<string,int>  $map
     * @return array<string,string>
     */
    public static function extract(array $row, array $map): array
    {
        $out = [];
        foreach (self::COLUMNS as $field => $column) {
            $index = $map[$field] ?? null;
            $out[$column] = ($index !== null && isset($row[$index]))
                ? trim((string) $row[$index])
                : '';
        }
        return $out;
    }
}