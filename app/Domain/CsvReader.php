<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * RFC 4180 style CSV reader with a configurable delimiter.
 *
 * Deliberately hand-written rather than fgetcsv() because fgetcsv() has
 * driver-specific escape behaviour that silently corrupts identifiers and
 * notes containing backslashes. UTF-8 BOM is stripped so the first header
 * name doesn't become "\uFEFFimei".
 */
final class CsvReader
{
    public static function parse(string $contents, string $delimiter = ','): array
    {
        // Strip UTF-8 BOM.
        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $contents = substr($contents, 3);
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        $rows    = [];
        $row     = [];
        $field   = '';
        $inQuote = false;
        $len     = strlen($contents);

        for ($i = 0; $i < $len; $i++) {
            $char = $contents[$i];

            if ($inQuote) {
                if ($char === '"') {
                    if ($i + 1 < $len && $contents[$i + 1] === '"') {
                        $field .= '"';   // Escaped quote.
                        $i++;
                    } else {
                        $inQuote = false;
                    }
                } else {
                    $field .= $char;
                }
                continue;
            }

            if ($char === '"') {
                $inQuote = true;
            } elseif ($char === $delimiter) {
                $row[] = $field;
                $field = '';
            } elseif ($char === "\n") {
                $row[]  = $field;
                $rows[] = $row;
                $row    = [];
                $field  = '';
            } else {
                $field .= $char;
            }
        }

        // Flush trailing field/row when the file lacks a final newline.
        if ($field !== '' || !empty($row)) {
            $row[]  = $field;
            $rows[] = $row;
        }

        // Drop entirely blank rows but keep rows with meaningful cells.
        return array_values(array_filter($rows, static function (array $r): bool {
            foreach ($r as $cell) {
                if (trim($cell) !== '') {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Guess the delimiter from the header line when the caller didn't know it.
     * Google Sheets exports use commas; Excel exports vary by locale.
     */
    public static function detectDelimiter(string $headerLine): string
    {
        $candidates = [',', ';', "\t", '|'];
        $best = ',';
        $bestCount = 0;
        foreach ($candidates as $c) {
            $count = substr_count($headerLine, $c);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $c;
            }
        }
        return $best;
    }
}