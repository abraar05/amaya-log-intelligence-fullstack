<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Normalisation and classification rules for device identifiers.
 *
 * Domain rules (see docs/DATA_MODEL.md):
 *  - Identifiers are compared as trimmed strings, never coerced to numbers,
 *    so leading zeroes and 15-digit IMEIs survive intact.
 *  - Duplicates are tracked separately from unmatched identifiers.
 *  - A record is only "Matched" when it carries a verified reference.
 */
final class Imei
{
    public const STATUS_MATCHED   = 'matched';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_UNREVIEWED = 'unreviewed';

    /** Characters used only for display that must not affect matching. */
    private const DISPLAY_SEPARATORS = [' ', "\t", '-', '.', '_'];

    /**
     * Canonical form used for equality comparisons.
     */
    public static function normalize(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $value = trim($raw);
        // Strip zero-width characters that survive copy/paste from spreadsheets.
        $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"], ' ', $value);
        foreach (self::DISPLAY_SEPARATORS as $sep) {
            $value = str_replace($sep, '', $value);
        }
        return $value;
    }

    /**
     * True when a value looks like a plausible IMEI (14–17 digits after
     * normalization). Used for advisory flags only — never to reject data,
     * because serial numbers legitimately fall in the same column.
     */
    public static function looksValid(?string $raw): bool
    {
        $n = self::normalize($raw);
        if ($n === '') {
            return false;
        }
        return (bool) preg_match('/^\d{14,17}$/', $n);
    }

    public static function hasReference(?string $invoice): bool
    {
        return trim((string) $invoice) !== '';
    }

    /**
     * Classify one identifier given how many times it appears and whether
     * any of its records carry a reference.
     *
     * @param int $occurrences Number of rows sharing this identifier.
     * @param bool $hasReference Whether any row carries an invoice/reference.
     */
    public static function classify(int $occurrences, bool $hasReference): string
    {
        if ($occurrences > 1) {
            return self::STATUS_DUPLICATE;
        }
        return $hasReference ? self::STATUS_MATCHED : self::STATUS_UNMATCHED;
    }
}