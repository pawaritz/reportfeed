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
use local_reportfeed\local\data_provider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Golden-file test: real Moodle data through the provider and writer must match the saved Contract v1 files.
 *
 * The files in tests/fixtures hold tokens ({c} course id, {u:s1} user id) because database ids vary;
 * everything else is compared byte for byte, including CRLF line endings.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(data_provider::class)]
#[CoversClass(csv_writer::class)]
final class contract_golden_test extends \advanced_testcase {
    /** @var int Frozen "now": 2026-10-06 12:00:00 UTC. */
    private const NOW = 1791288000;

    /** @var int[] User ids by key. */
    private array $users = [];

    /** @var int Course id. */
    private int $courseid;

    /** @var int The teacher. */
    private int $teacherid;

    /**
     * Learner per course, HR view, identity columns idnumber and email.
     */
    public function test_learner_course_golden(): void {
        $this->build_fixture();
        $rows = (new data_provider())->learner_course([$this->courseid]);
        $columns = contract::columns('learner_course', ['idnumber', 'email']);
        $this->assert_golden('learner_course_v1.csv', $columns, $rows);
    }

    /**
     * Learner summary, HR view, identity column username.
     */
    public function test_learner_summary_golden(): void {
        $this->build_fixture();
        $rows = (new data_provider())->learner_summary([$this->courseid]);
        $this->assert_golden('learner_summary_v1.csv', contract::columns('learner_summary', ['username']), $rows);
    }

    /**
     * Teacher digest for the course teacher, with a subset of columns.
     */
    public function test_teacher_digest_golden(): void {
        $this->build_fixture();
        $rows = (new data_provider())->learner_course([$this->courseid], $this->teacherid);
        $choices = ['grade', 'days_inactive', 'completion_status', 'completion_percent'];
        $this->assert_golden('teacher_digest_v1.csv', contract::columns('teacher_digest', [], $choices), $rows);
    }

    /**
     * Write the rows and compare with the saved file after replacing the id tokens.
     *
     * @param string $fixture file name in tests/fixtures
     * @param string[] $columns
     * @param iterable $rows
     */
    private function assert_golden(string $fixture, array $columns, iterable $rows): void {
        $path = make_request_directory() . '/out.csv';
        csv_writer::write($path, $columns, $rows);
        $tokens = ['{c}' => $this->courseid];
        foreach ($this->users as $key => $id) {
            $tokens["{u:$key}"] = $id;
        }
        $expected = strtr(file_get_contents(__DIR__ . '/fixtures/' . $fixture), $tokens);
        $this->assertSame($expected, file_get_contents($path));
    }

    /**
     * One course with four learners that exercise every rule, and a teacher.
     */
    private function build_fixture(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        $gen = $this->getDataGenerator();
        $day = DAYSECS;

        $course = $gen->create_course(['shortname' => 'GOLD1', 'idnumber' => 'G-1', 'enablecompletion' => 1]);
        $this->courseid = (int) $course->id;
        $this->teacherid = (int) $gen->create_and_enrol($course, 'editingteacher')->id;

        // Three tracked visible activities, one tracked but hidden, one not tracked.
        $cms = [];
        foreach ([1, 2, 3] as $n) {
            $cms[$n] = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL])->cmid;
        }
        $hidden = $gen->create_module(
            'page',
            ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL, 'visible' => 0]
        )->cmid;
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_NONE]);

        // Dates are all relative to NOW so the fixture reads naturally.
        $learners = [
            's1' => ['first' => 'Ann', 'last' => 'One', 'enrol' => self::NOW - 35 * $day - 12 * 3600,
                'done' => [1, 2, 'hidden'], 'access' => self::NOW - $day, 'site' => self::NOW - 3600, 'grade' => 80],
            's2' => ['first' => 'Bob', 'last' => 'Two', 'enrol' => self::NOW - 34 * $day - 12 * 3600,
                'done' => [1, 2, 3], 'access' => null, 'site' => null, 'grade' => 100],
            's3' => ['first' => 'Cat', 'last' => 'Three', 'enrol' => self::NOW - 66 * $day - 12 * 3600,
                'done' => [], 'access' => self::NOW - 30 * $day, 'site' => self::NOW - 29 * $day, 'grade' => null],
            's4' => ['first' => 'Pat', 'last' => 'O"Neil, Jr', 'enrol' => self::NOW - $day - 12 * 3600,
                'done' => [3], 'access' => null, 'site' => null, 'grade' => 55, 'idnumber' => '=CMD()'],
        ];
        $info = new \completion_info(get_course($course->id));
        $manual = $gen->create_grade_item(['courseid' => $course->id, 'grademax' => 100, 'grademin' => 0]);
        $gradeitem = new \grade_item($manual, false);
        foreach ($learners as $key => $l) {
            $user = $gen->create_user([
                'username' => $key, 'email' => "$key@example.com", 'idnumber' => $l['idnumber'] ?? strtoupper($key),
                'firstname' => $l['first'], 'lastname' => $l['last'],
            ]);
            $this->users[$key] = (int) $user->id;
            $gen->enrol_user($user->id, $course->id, 'student', 'manual', $l['enrol']);
            foreach ($l['done'] as $n) {
                $cmid = $n === 'hidden' ? $hidden : $cms[$n];
                $info->update_state(get_coursemodule_from_id('page', $cmid), COMPLETION_COMPLETE, $user->id);
            }
            if ($l['access']) {
                $DB->insert_record('user_lastaccess', [
                    'userid' => $user->id, 'courseid' => $course->id, 'timeaccess' => $l['access'],
                ]);
            }
            if ($l['site']) {
                $DB->set_field('user', 'lastaccess', $l['site'], ['id' => $user->id]);
            }
            if ($l['grade'] !== null) {
                $gradeitem->update_final_grade($user->id, $l['grade'], 'test');
            }
        }
        // Bob finished the whole course; Pat's course total is hidden from him (and from HR).
        $done = new \completion_completion(['course' => $course->id, 'userid' => $this->users['s2']]);
        $done->mark_complete(self::NOW - 5 * $day - 12600);
        grade_regrade_final_grades($course->id);
        $courseitem = \grade_item::fetch_course_item($course->id);
        \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $this->users['s4']])->set_hidden(1);

        // A suspended enrolment and a teacher must never appear as learners.
        $late = $gen->create_user(['username' => 'late']);
        $gen->enrol_user($late->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
    }
}
