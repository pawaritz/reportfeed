<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_reportfeed\local;

/**
 * Writes Contract v1 CSV: RFC 4180 quoting, ISO 8601 UTC timestamps, formula-injection guard.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class csv_writer {
    /** @var string The optional Excel-friendly byte order mark (off by default, K3). */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Write one CSV file, one row at a time.
     *
     * @param string $path file to create (the caller owns the directory and deletes the file after sending)
     * @param string[] $columns header, from {@see contract::columns()}
     * @param iterable $rows associative rows holding native values (timestamps as ints, null for empty)
     * @param bool $bom add a UTF-8 byte order mark
     * @return array{rows:int,bytes:int,sha256:string} metadata for the run log; never the content
     */
    public static function write(string $path, array $columns, iterable $rows, bool $bom = false): array {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \moodle_exception('cannotcreatefile', 'error', '', $path);
        }
        $count = 0;
        try {
            fwrite($handle, ($bom ? self::BOM : '') . self::line($columns));
            $types = array_map([contract::class, 'type'], $columns);
            foreach ($rows as $row) {
                $cells = [];
                foreach ($columns as $i => $column) {
                    $cells[] = self::format($row[$column] ?? null, $types[$i]);
                }
                fwrite($handle, self::line($cells));
                $count++;
            }
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        return ['rows' => $count, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    /**
     * Format one cell by its column type. Null is an empty cell.
     *
     * @param mixed $value
     * @param string $type int, text, time, percent or number
     * @return string
     */
    public static function format(mixed $value, string $type): string {
        if ($value === null || $value === '') {
            return '';
        }
        switch ($type) {
            case 'int':
                return (string) (int) $value;
            case 'time':
                return $value > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int) $value) : '';
            case 'percent':
            case 'number':
                return number_format((float) $value, 2, '.', '');
        }
        $text = (string) $value;
        // The one injection rule (ADR-005): text only, numbers and timestamps are never touched.
        return strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
    }

    /**
     * Join cells into one RFC 4180 line (comma separated, CRLF terminated).
     *
     * @param string[] $cells
     * @return string
     */
    public static function line(array $cells): string {
        $out = [];
        foreach ($cells as $cell) {
            $out[] = strpbrk($cell, ",\"\r\n") !== false ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
        }
        return implode(',', $out) . "\r\n";
    }
}
