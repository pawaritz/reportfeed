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

namespace local_reportfeed;

use local_reportfeed\local\mailer;
use local_reportfeed\local\packager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CSV or Excel, zip, and splitting into numbered parts.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(packager::class)]
final class packager_test extends \advanced_testcase {
    /** @var string[] Columns used by the tests (learner_summary without identity columns). */
    private const COLUMNS = ['user_id', 'courses_enrolled', 'avg_completion_percent', 'last_access_site'];

    /**
     * Rows for the test file.
     *
     * @param int $count
     * @return array[]
     */
    private function rows(int $count): array {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'user_id' => $i, 'courses_enrolled' => $i % 4, 'avg_completion_percent' => $i / 3,
                'last_access_site' => 1790000000 + $i * 3600,
            ];
        }
        return $rows;
    }

    /**
     * The stem function the run manager would pass.
     *
     * @return \Closure
     */
    private function stem(): \Closure {
        return fn(int $part, int $parts, string $ext) => mailer::filename('learner_summary', 20261006, $ext, 0, $part, $parts);
    }

    /**
     * Build a file with options.
     *
     * @param int $count rows
     * @param array $options packager options
     * @return array
     */
    private function build(int $count, array $options): array {
        return packager::build(make_request_directory(), $this->stem(), self::COLUMNS, $this->rows($count), $options);
    }

    /**
     * Data lines of a CSV part (header first).
     *
     * @param string $path
     * @return string[]
     */
    private function lines(string $path): array {
        return array_filter(explode("\r\n", file_get_contents($path)), fn($l) => $l !== '');
    }

    public function test_one_plain_csv_is_the_canonical_file(): void {
        $this->resetAfterTest();
        $result = $this->build(5, ['cap' => 1048576]);
        $this->assertFalse($result['toomany']);
        $this->assertSame(1, $result['partcount']);
        $part = $result['parts'][0];
        $this->assertMatchesRegularExpression('/^learner_summary_.*_2026-10-06_v1\.csv$/', $part['name']);
        $this->assertSame(5, $part['rows']);
        $this->assertTrue($part['attach']);
        $this->assertSame(hash_file('sha256', $part['path']), $part['sha256']);
        $this->assertCount(6, $this->lines($part['path']));
        $this->assertSame(1, $part['part']);
        $this->assertSame(1, $part['parts']);
    }

    public function test_split_csv_repeats_the_header_and_keeps_every_row_once(): void {
        $this->resetAfterTest();
        $whole = $this->build(200, ['cap' => 10 * 1048576]);
        $all = $this->lines($whole['parts'][0]['path']);
        $result = $this->build(200, ['cap' => 2000, 'maxparts' => 50]);
        $this->assertFalse($result['toomany']);
        $this->assertGreaterThan(2, $result['partcount']);
        $joined = [];
        foreach ($result['parts'] as $i => $part) {
            $this->assertLessThanOrEqual(2000, $part['bytes']);
            $this->assertTrue($part['attach']);
            $this->assertSame($i + 1, $part['part']);
            $this->assertSame($result['partcount'], $part['parts']);
            $this->assertMatchesRegularExpression('/_v1_p' . ($i + 1) . 'of' . $result['partcount'] . '\.csv$/', $part['name']);
            $lines = $this->lines($part['path']);
            $this->assertSame($all[0], $lines[0], 'every part starts with the header');
            $this->assertSame($part['rows'], count($lines) - 1);
            $joined = array_merge($joined, array_slice($lines, 1));
        }
        $this->assertSame(array_slice($all, 1), $joined);
        $this->assertSame(200, array_sum(array_column($result['parts'], 'rows')));
    }

    public function test_zip_parts_hold_one_file_each(): void {
        $this->resetAfterTest();
        $result = $this->build(400, ['cap' => 1500, 'maxparts' => 50, 'zip' => true]);
        $this->assertGreaterThan(1, $result['partcount']);
        foreach ($result['parts'] as $part) {
            $this->assertStringEndsWith('.zip', $part['name']);
            $this->assertLessThanOrEqual(1500, $part['bytes']);
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($part['path']));
            $this->assertSame(1, $zip->numFiles);
            $this->assertStringEndsWith('.csv', $zip->getNameIndex(0));
            $this->assertStringStartsWith('user_id,', $zip->getFromIndex(0));
            $zip->close();
        }
    }

    public function test_excel_holds_the_same_rows_as_numbers_and_text(): void {
        $this->resetAfterTest();
        $result = $this->build(30, ['format' => 'xlsx', 'cap' => 10 * 1048576]);
        $part = $result['parts'][0];
        $this->assertMatchesRegularExpression('/_v1\.xlsx$/', $part['name']);
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($part['path']);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertCount(31, $rows);
        $this->assertSame(self::COLUMNS, $rows[0]);
        $this->assertSame(7, $rows[7][0]);
        $this->assertSame(3, $rows[7][1]);
        $this->assertEqualsWithDelta(2.33, $rows[7][2], 0.001);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1790000000 + 3600), $rows[1][3]);
    }

    public function test_split_excel(): void {
        $this->resetAfterTest();
        $single = $this->build(300, ['format' => 'xlsx', 'cap' => 10 * 1048576]);
        $cap = (int) ($single['parts'][0]['bytes'] * 0.6);
        $result = $this->build(300, ['format' => 'xlsx', 'cap' => $cap, 'maxparts' => 20]);
        $this->assertGreaterThan(1, $result['partcount']);
        $total = 0;
        foreach ($result['parts'] as $part) {
            $this->assertLessThanOrEqual($cap, $part['bytes']);
            $this->assertStringEndsWith('.xlsx', $part['name']);
            $total += $part['rows'];
        }
        $this->assertSame(300, $total);
    }

    public function test_too_many_parts_attaches_nothing(): void {
        $this->resetAfterTest();
        $result = $this->build(500, ['cap' => 1000, 'maxparts' => 2]);
        $this->assertTrue($result['toomany']);
        $this->assertFalse($result['parts'][0]['attach']);
        $this->assertGreaterThan(2, $result['partcount']);
        $this->assertSame(500, $result['rows']);
        $this->assertSame('', $result['parts'][0]['path']);
    }

    public function test_cap_smaller_than_one_row_attaches_nothing(): void {
        $this->resetAfterTest();
        $result = $this->build(5, ['cap' => 20, 'maxparts' => 99]);
        $this->assertTrue($result['toomany']);
        $this->assertSame(0, $result['partcount']);
    }

    public function test_cells_with_quotes_and_line_breaks_survive_a_split(): void {
        $this->resetAfterTest();
        $columns = ['user_id', 'username'];
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = ['user_id' => $i, 'username' => "a,\"b\"\r\nline $i"];
        }
        $stem = fn(int $part, int $parts, string $ext) => "t_p{$part}of{$parts}.$ext";
        $result = packager::build(make_request_directory(), $stem, $columns, $rows, ['cap' => 500, 'maxparts' => 40]);
        $this->assertGreaterThan(1, $result['partcount']);
        $seen = [];
        foreach ($result['parts'] as $part) {
            $handle = fopen($part['path'], 'rb');
            fgetcsv($handle, 0, ',', '"', '');
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $seen[(int) $row[0]] = $row[1];
            }
            fclose($handle);
        }
        $this->assertCount(40, $seen);
        $this->assertSame("a,\"b\"\r\nline 17", $seen[17]);
    }

    public function test_byte_order_mark_is_on_every_csv_part(): void {
        $this->resetAfterTest();
        $result = $this->build(200, ['cap' => 2000, 'maxparts' => 50, 'bom' => true]);
        $this->assertGreaterThan(1, $result['partcount']);
        foreach ($result['parts'] as $part) {
            $this->assertSame("\xEF\xBB\xBF", file_get_contents($part['path'], false, null, 0, 3));
        }
    }

    public function test_empty_report_is_one_part_with_zero_rows(): void {
        $this->resetAfterTest();
        foreach (['csv', 'xlsx'] as $format) {
            $result = $this->build(0, ['format' => $format, 'cap' => 1048576]);
            $this->assertSame(1, $result['partcount']);
            $this->assertSame(0, $result['parts'][0]['rows']);
            $this->assertTrue($result['parts'][0]['attach']);
        }
    }

    public function test_excel_text_starting_with_a_formula_character_is_not_a_formula(): void {
        $this->resetAfterTest();
        $stem = fn(int $part, int $parts, string $ext) => "t.$ext";
        $rows = [['user_id' => 1, 'username' => '=HYPERLINK("http://x")'], ['user_id' => 2, 'username' => '@SUM(1)']];
        $result = packager::build(make_request_directory(), $stem, ['user_id', 'username'], $rows, ['format' => 'xlsx']);
        $zip = new \ZipArchive();
        $zip->open($result['parts'][0]['path']);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString("'=HYPERLINK", html_entity_decode($sheet));
        $this->assertStringContainsString("'@SUM(1)", html_entity_decode($sheet));
    }
}
