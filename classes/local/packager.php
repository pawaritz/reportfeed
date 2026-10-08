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
 * Turns the rows of one report file into the attachments that are emailed: CSV or Excel, zipped or not, split into
 * numbered parts when one file would be above the attachment cap (D18, D25).
 *
 * The rows are always written once as the canonical CSV (so the content is identical whatever the format); Excel and
 * parts are made from that file by reading it back one row at a time, so memory stays flat.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class packager {
    /** @var int Data rows an Excel sheet can hold (the limit is 1,048,576 rows including the header). */
    public const XLSX_MAXROWS = 1000000;

    /** @var float Parts are aimed at this share of the cap, because the first guess is only an estimate. */
    private const TARGET = 0.9;

    /** @var int How often the split is redone with smaller parts when a part is still over the cap. */
    private const TRIES = 8;

    /**
     * Build the attachments of one file.
     *
     * @param string $dir directory to work in (the caller removes it)
     * @param callable $stem function(int $part, int $parts, string $ext): string giving a file name stem for a part
     * @param array $columns header, from {@see contract::columns()}
     * @param iterable $rows rows for {@see csv_writer::write()}
     * @param array $options format (csv or xlsx), zip, bom, cap (bytes), maxparts, maxrows (0 for no limit)
     * @return array{parts:array[],rows:int,partcount:int,toomany:bool} each part: name, path, rows, bytes, sha256,
     *     attach, part, parts. With toomany the single part has no file (attach false) and holds the totals.
     */
    public static function build(string $dir, callable $stem, array $columns, iterable $rows, array $options): array {
        $format = ($options['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        $zip = !empty($options['zip']);
        $bom = !empty($options['bom']) && $format === 'csv';
        $cap = (float) ($options['cap'] ?? 10 * 1048576);
        $maxparts = max(1, (int) ($options['maxparts'] ?? 10));

        $maxrows = max(0, (int) ($options['maxrows'] ?? 0));
        $canonical = $dir . '/canonical.csv';
        $over = false;
        $total = csv_writer::write($canonical, $columns, self::limited($rows, $maxrows, $over), $bom);
        $count = $total['rows'];
        if ($over) {
            // Rows beyond the limit were not written: the notice says the file was refused, not how big it would be.
            $result = self::too_many($canonical, $total, $stem, 0);
            $result['parts'][0]['rowcap'] = $maxrows;
            return $result;
        }

        // A report far beyond the limit is not worth building: the estimate keeps a wide margin (zip about 11 times
        // smaller than the CSV, Excel about 3 times).
        $ratio = $format === 'csv' && !$zip ? 1.0 : ($format === 'csv' ? 0.08 : 0.3);
        $estimate = (int) ceil($total['bytes'] * $ratio / ($cap * self::TARGET));
        if ($estimate > 2 * $maxparts) {
            return self::too_many($canonical, $total, $stem, $estimate);
        }

        // One part first: the common case needs no second pass for a plain CSV.
        $perpart = max(1, $format === 'xlsx' ? min($count, self::XLSX_MAXROWS) : $count);
        $parts = self::make_parts($dir, $canonical, $columns, $stem, $count, $perpart, [$format, $zip, $bom, $cap], true);
        for ($try = 0; $try < self::TRIES && self::too_big($parts, $cap) && $perpart > 1; $try++) {
            $biggest = max(array_column($parts, 'bytes'));
            $planned = max(count($parts), (int) ceil($biggest * count($parts) / ($cap * self::TARGET)));
            $perpart = max(1, (int) min($perpart - 1, ceil($count / $planned)));
            if ($format === 'xlsx') {
                $perpart = min($perpart, self::XLSX_MAXROWS);
            }
            if ((int) ceil($count / $perpart) > $maxparts) {
                array_map('unlink', array_column($parts, 'path'));
                return self::too_many($canonical, $total, $stem, (int) ceil($count / $perpart));
            }
            array_map('unlink', array_column($parts, 'path'));
            $parts = self::make_parts($dir, $canonical, $columns, $stem, $count, $perpart, [$format, $zip, $bom, $cap], false);
        }
        if (self::too_big($parts, $cap)) {
            // Even a single row does not fit (an absurdly small cap): nothing can be attached.
            array_map('unlink', array_column($parts, 'path'));
            return self::too_many($canonical, $total, $stem, 0);
        }
        @unlink($canonical);
        return ['parts' => $parts, 'rows' => $count, 'partcount' => count($parts), 'toomany' => false];
    }

    /**
     * The rows, stopping as soon as there is one more than the limit.
     *
     * @param iterable $rows
     * @param int $max 0 for no limit
     * @param bool $over set to true when the limit was passed
     * @return \Generator
     */
    private static function limited(iterable $rows, int $max, bool &$over): \Generator {
        $n = 0;
        foreach ($rows as $row) {
            if ($max && ++$n > $max) {
                $over = true;
                return;
            }
            yield $row;
        }
    }

    /**
     * Whether any part is over the cap.
     *
     * @param array[] $parts
     * @param float $cap
     * @return bool
     */
    private static function too_big(array $parts, float $cap): bool {
        foreach ($parts as $part) {
            if ($part['bytes'] > $cap) {
                return true;
            }
        }
        return false;
    }

    /**
     * The result when the report would need more parts than allowed: nothing is attached, the totals are reported.
     *
     * @param string $canonical
     * @param array $total rows, bytes, sha256 of the canonical CSV
     * @param callable $stem
     * @param int $needed the number of parts that would be needed, 0 when no part can be small enough
     * @return array
     */
    private static function too_many(string $canonical, array $total, callable $stem, int $needed): array {
        @unlink($canonical);
        $part = [
            'name' => $stem(1, 1, 'csv'), 'path' => '', 'rows' => $total['rows'], 'bytes' => $total['bytes'],
            'sha256' => $total['sha256'], 'attach' => false, 'part' => 1, 'parts' => 1, 'needed' => $needed,
        ];
        return ['parts' => [$part], 'rows' => $total['rows'], 'partcount' => $needed, 'toomany' => true];
    }

    /**
     * Cut the canonical CSV into parts of a given number of data rows and wrap each as the chosen format.
     *
     * @param string $dir
     * @param string $canonical canonical CSV path
     * @param string[] $columns
     * @param callable $stem
     * @param int $all data rows in the canonical file
     * @param int $perpart data rows per part
     * @param array $wrap format (csv or xlsx), zip, bom, cap
     * @param bool $whole true on the first pass, when a single plain CSV part is simply a copy of the canonical file
     * @return array[] the parts, see {@see build()}
     */
    private static function make_parts(
        string $dir,
        string $canonical,
        array $columns,
        callable $stem,
        int $all,
        int $perpart,
        array $wrap,
        bool $whole
    ): array {
        [$format, $zip, $bom, $cap] = $wrap;
        $types = array_map([contract::class, 'type'], $columns);
        $in = fopen($canonical, 'rb');
        if ($bom) {
            fread($in, 3);
        }
        $header = fgetcsv($in, 0, ',', '"', '');
        $partcount = max(1, (int) ceil($all / max(1, $perpart)));
        $parts = [];
        for ($n = 1; $n <= $partcount; $n++) {
            $rows = $n < $partcount ? $perpart : $all - $perpart * ($partcount - 1);
            $inner = $dir . '/' . $stem($n, $partcount, $format);
            $source = $inner;
            if ($whole && $partcount === 1 && $format === 'csv') {
                // The canonical file is already the finished CSV: copy it, or zip it straight from where it is.
                if ($zip) {
                    $source = $canonical;
                } else {
                    copy($canonical, $inner);
                }
            } else if ($format === 'xlsx') {
                self::write_xlsx($inner, $header, $types, $in, $rows);
            } else {
                self::write_csv_part($inner, $header, $in, $rows, $bom);
            }
            $final = $inner;
            if ($zip) {
                $final = $dir . '/' . $stem($n, $partcount, 'zip');
                (new \zip_packer())->archive_to_pathname([basename($inner) => $source], $final);
                if ($source === $inner) {
                    @unlink($inner);
                }
            }
            clearstatcache(true, $final);
            $bytes = filesize($final);
            $parts[] = [
                'name' => basename($final), 'path' => $final, 'rows' => $rows, 'bytes' => $bytes,
                'sha256' => hash_file('sha256', $final), 'attach' => $bytes <= $cap, 'part' => $n, 'parts' => $partcount,
            ];
        }
        fclose($in);
        return $parts;
    }

    /**
     * Write one CSV part: the header and the next rows from the open canonical file.
     *
     * @param string $path
     * @param array $header
     * @param resource $in
     * @param int $rows
     * @param bool $bom
     */
    private static function write_csv_part(string $path, array $header, $in, int $rows, bool $bom): void {
        $out = fopen($path, 'wb');
        fwrite($out, ($bom ? "\xEF\xBB\xBF" : '') . csv_writer::line($header));
        for ($i = 0; $i < $rows; $i++) {
            $row = fgetcsv($in, 0, ',', '"', '');
            if ($row === false) {
                break;
            }
            fwrite($out, csv_writer::line($row));
        }
        fclose($out);
    }

    /**
     * Write one Excel part with the OpenSpout library that is part of Moodle (streaming, no new dependency).
     *
     * Cells keep the text of the CSV, so the CSV's formula guard applies (a text starting with = + - @ already has its
     * leading apostrophe, and OpenSpout only reads a string starting with = as a formula); counts and percentages
     * become real numbers. Moodle's own Excel data format writes every cell as text, which Excel flags.
     *
     * @param string $path
     * @param array $header
     * @param string[] $types contract column types
     * @param resource $in
     * @param int $rows
     */
    private static function write_xlsx(string $path, array $header, array $types, $in, int $rows): void {
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->getOptions()->setTempFolder(make_request_directory());
        $writer->openToFile($path);
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($header));
        for ($i = 0; $i < $rows; $i++) {
            $row = fgetcsv($in, 0, ',', '"', '');
            if ($row === false) {
                break;
            }
            foreach ($row as $c => $cell) {
                if ($cell !== '' && in_array($types[$c] ?? 'text', ['int', 'percent', 'number'], true)) {
                    $row[$c] = $types[$c] === 'int' ? (int) $cell : (float) $cell;
                }
            }
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
        }
        $writer->close();
    }
}
