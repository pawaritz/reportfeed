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

use local_reportfeed\local\contract;
use local_reportfeed\local\csv_writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cell formatting, quoting, injection guard, BOM and column selection for Contract v1.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(csv_writer::class)]
#[CoversClass(contract::class)]
final class csv_writer_test extends \advanced_testcase {
    /**
     * Cell formatting by type.
     *
     * @param mixed $value
     * @param string $type
     * @param string $expected
     */
    #[DataProvider('cells_provider')]
    public function test_format(mixed $value, string $type, string $expected): void {
        $this->assertSame($expected, csv_writer::format($value, $type));
    }

    /**
     * Cases for test_format.
     *
     * @return array
     */
    public static function cells_provider(): array {
        return [
            'null is empty' => [null, 'text', ''],
            'null number is empty' => [null, 'percent', ''],
            'int' => ['42', 'int', '42'],
            'zero int is kept' => [0, 'int', '0'],
            'time is ISO 8601 UTC with Z' => [1790000000, 'time', '2026-09-21T14:13:20Z'],
            'zero time is empty' => [0, 'time', ''],
            'percent has two decimals and no sign' => [66.6666, 'percent', '66.67'],
            'whole percent' => [100, 'percent', '100.00'],
            'number has two decimals' => [80, 'number', '80.00'],
            'plain text' => ['abc', 'text', 'abc'],
            'text zero is kept' => ['0', 'text', '0'],
            'equals is neutralised' => ['=SUM(A1)', 'text', "'=SUM(A1)"],
            'plus is neutralised' => ['+1', 'text', "'+1"],
            'minus is neutralised' => ['-1', 'text', "'-1"],
            'at is neutralised' => ['@x', 'text', "'@x"],
            'tab is neutralised' => ["\tx", 'text', "'\tx"],
            'carriage return is neutralised' => ["\rx", 'text', "'\rx"],
            'dash inside text is left alone' => ['a-b', 'text', 'a-b'],
        ];
    }

    /**
     * Quoting, line endings, header, empty run, BOM and metadata.
     */
    public function test_write_file(): void {
        $dir = make_request_directory();
        $columns = ['user_id', 'fullname', 'days_inactive'];
        $rows = [
            ['user_id' => 7, 'fullname' => 'Ann, "A" One', 'days_inactive' => 3],
            ['user_id' => 8, 'fullname' => "Two\nLines", 'days_inactive' => null],
        ];

        $meta = csv_writer::write("$dir/a.csv", $columns, $rows);
        $expected = "user_id,fullname,days_inactive\r\n7,\"Ann, \"\"A\"\" One\",3\r\n8,\"Two\nLines\",\r\n";
        $this->assertSame($expected, file_get_contents("$dir/a.csv"));
        $this->assertSame(2, $meta['rows']);
        $this->assertSame(strlen($expected), $meta['bytes']);
        $this->assertSame(hash('sha256', $expected), $meta['sha256']);

        $empty = csv_writer::write("$dir/b.csv", $columns, []);
        $this->assertSame("user_id,fullname,days_inactive\r\n", file_get_contents("$dir/b.csv"));
        $this->assertSame(0, $empty['rows']);

        csv_writer::write("$dir/c.csv", $columns, [], true);
        $this->assertSame("\xEF\xBB\xBFuser_id,fullname,days_inactive\r\n", file_get_contents("$dir/c.csv"));
        csv_writer::write("$dir/d.csv", $columns, []);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", file_get_contents("$dir/d.csv"));
    }

    /**
     * Header order is fixed by the contract, whatever order the settings were saved in.
     */
    public function test_columns(): void {
        $this->assertSame(
            ['user_id', 'idnumber', 'email', 'course_id', 'course_idnumber', 'course_shortname', 'enrol_start',
                'completion_status', 'completion_percent', 'completion_date', 'grade', 'grade_percent',
                'last_access_course', 'days_inactive'],
            contract::columns('learner_course', ['email', 'idnumber', 'nonsense'])
        );
        $this->assertSame(
            ['user_id', 'course_id', 'course_idnumber', 'course_shortname', 'enrol_start', 'completion_status',
                'completion_percent', 'completion_date', 'grade', 'grade_percent', 'last_access_course',
                'days_inactive'],
            contract::columns('learner_course')
        );
        $this->assertSame(
            ['user_id', 'username', 'courses_enrolled', 'courses_completed', 'avg_completion_percent',
                'last_access_site', 'days_inactive_site'],
            contract::columns('learner_summary', ['username'])
        );
        $digest = ['grade', 'days_inactive', 'evil_column', 'completion_status'];
        $this->assertSame(
            ['course_id', 'course_shortname', 'user_id', 'fullname', 'completion_status', 'days_inactive', 'grade'],
            contract::columns('teacher_digest', [], $digest)
        );
        $this->assertSame(
            ['course_id', 'course_shortname', 'user_id', 'fullname', 'completion_status', 'days_inactive'],
            contract::columns('teacher_digest', [], $digest, false)
        );
    }
}
