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

use local_reportfeed\local\activity_options;
use local_reportfeed\local\activity_provider;
use local_reportfeed\local\contract;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the activity-level rows and choices (D21, D23, D24).
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(activity_provider::class)]
#[CoversClass(activity_options::class)]
final class activity_provider_test extends \advanced_testcase {
    /** @var int A fixed "now", a Tuesday noon UTC. */
    private const NOW = 1791288000;

    /** @var \core\clock Frozen clock. */
    private \core\clock $clock;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        $this->clock = $this->mock_clock_with_frozen(self::NOW);
        $this->setAdminUser();
    }

    /**
     * A course with completion on and two students.
     *
     * @return array [course, student a, student b]
     */
    private function setup_course(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        return [$course, $gen->create_and_enrol($course, 'student'), $gen->create_and_enrol($course, 'student')];
    }

    /**
     * Rows keyed "userid:cmid" (or "userid" when there is no cm id).
     *
     * @param iterable $rows
     * @return array
     */
    private function keyed(iterable $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['user_id'] . (isset($row['cm_id']) ? ':' . $row['cm_id'] : '')] = $row;
        }
        return $out;
    }

    /**
     * One row per learner and tracked visible activity, with the right state and date.
     */
    public function test_completion(): void {
        [$course, $a, $b] = $this->setup_course();
        $gen = $this->getDataGenerator();
        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $forum = $gen->create_module('forum', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $gen->create_module('page', ['course' => $course->id]); // Not tracked.
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL, 'visible' => 0]);
        (new \completion_info(get_course($course->id)))
            ->update_state(get_coursemodule_from_id('page', $page->cmid), COMPLETION_COMPLETE, $a->id);

        $rows = $this->keyed((new activity_provider($this->clock))->completion([$course->id], []));
        $this->assertCount(4, $rows, 'two learners times two tracked visible activities');
        $this->assertSame('complete', $rows["{$a->id}:{$page->cmid}"]['activity_status']);
        $this->assertEqualsWithDelta(time(), $rows["{$a->id}:{$page->cmid}"]['completion_date'], 120);
        $this->assertSame('incomplete', $rows["{$a->id}:{$forum->cmid}"]['activity_status']);
        $this->assertNull($rows["{$a->id}:{$forum->cmid}"]['completion_date']);
        $this->assertSame('incomplete', $rows["{$b->id}:{$page->cmid}"]['activity_status']);
        $this->assertSame('page', $rows["{$a->id}:{$page->cmid}"]['activity_type']);
        $this->assertSame($a->email, $rows["{$a->id}:{$page->cmid}"]['email']);

        $open = $this->keyed((new activity_provider($this->clock))->completion([$course->id], ['filter' => 'open']));
        $this->assertCount(3, $open);
        $this->assertArrayNotHasKey("{$a->id}:{$page->cmid}", $open);

        $forums = (new activity_provider($this->clock))->completion([$course->id], ['modtypes' => ['forum']]);
        $this->assertCount(2, iterator_to_array($forums, false));
    }

    /**
     * Course without completion tracking gives no completion rows; slices do not change the rows.
     */
    public function test_completion_off_and_slices(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $off = $gen->create_course(['enablecompletion' => 0]);
        $gen->create_and_enrol($off, 'student');
        $tracked = $gen->create_module('page', ['course' => $off->id]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_MANUAL, ['id' => $tracked->cmid]);
        $this->assertCount(0, iterator_to_array((new activity_provider($this->clock))->completion([$off->id], []), false));

        $course = $gen->create_course(['enablecompletion' => 1]);
        for ($i = 0; $i < 5; $i++) {
            $gen->create_and_enrol($course, 'student');
        }
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $whole = iterator_to_array((new activity_provider($this->clock))->completion([$course->id], []), false);
        $this->assertCount(10, $whole);
        foreach ([1, 2, 3] as $slice) {
            $this->assertSame(
                $whole,
                iterator_to_array((new activity_provider($this->clock, $slice))->completion([$course->id], []), false)
            );
        }
    }

    /**
     * Status, dates, overdue, extension and grade of assignments.
     */
    public function test_assignments(): void {
        global $DB;
        [$course, $a, $b] = $this->setup_course();
        $c = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $due = self::NOW - 3 * DAYSECS;
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'duedate' => $due, 'grade' => 100,
        ]);
        $future = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'duedate' => self::NOW + DAYSECS, 'grade' => 100,
        ]);
        $sub = [
            'assignment' => $assign->id, 'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0, 'timecreated' => $due - 100,
        ];
        // A submitted two days after the due date, B only a draft, C nothing.
        $DB->insert_record('assign_submission', (object) ($sub + [
            'userid' => $a->id, 'status' => 'submitted', 'timemodified' => $due + 2 * DAYSECS - 10,
        ]));
        $DB->insert_record('assign_submission', (object) ($sub + [
            'userid' => $b->id, 'status' => 'draft', 'timemodified' => $due - 50,
        ]));
        // C has an extension that has not run out.
        $DB->insert_record('assign_user_flags', (object) [
            'userid' => $c->id, 'assignment' => $assign->id, 'extensionduedate' => self::NOW + DAYSECS, 'locked' => 0,
            'mailed' => 0, 'workflowstate' => '', 'allocatedmarker' => 0,
        ]);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id]);
        $item->update_final_grade($a->id, 80);
        grade_regrade_final_grades($course->id);

        $rows = $this->keyed((new activity_provider($this->clock))->assignments([$course->id], []));
        $this->assertCount(6, $rows);
        $ra = $rows["{$a->id}:{$assign->cmid}"];
        $this->assertSame('submitted', $ra['submission_status']);
        $this->assertSame('no', $ra['overdue']);
        $this->assertSame(2, $ra['days_late']);
        $this->assertSame($due, $ra['due_date']);
        $this->assertEqualsWithDelta(80.0, $ra['grade'], 0.001);
        $this->assertEqualsWithDelta(80.0, $ra['grade_percent'], 0.001);
        $rb = $rows["{$b->id}:{$assign->cmid}"];
        $this->assertSame('draft', $rb['submission_status']);
        $this->assertSame('yes', $rb['overdue']);
        $this->assertNull($rb['days_late']);
        $this->assertNull($rb['submitted_date']);
        $this->assertNull($rb['grade']);
        $rc = $rows["{$c->id}:{$assign->cmid}"];
        $this->assertSame('not_submitted', $rc['submission_status']);
        $this->assertSame('no', $rc['overdue'], 'the extension has not run out');
        $this->assertSame(self::NOW + DAYSECS, $rc['due_date']);
        $this->assertSame('no', $rows["{$b->id}:{$future->cmid}"]['overdue']);

        $open = $this->keyed((new activity_provider($this->clock))->assignments([$course->id], ['filter' => 'open']));
        $this->assertCount(5, $open);
        $overdue = $this->keyed((new activity_provider($this->clock))->assignments([$course->id], ['filter' => 'overdue']));
        $this->assertSame(["{$b->id}:{$assign->cmid}"], array_keys($overdue));
    }

    /**
     * Attempts and status of quizzes; previews do not count.
     */
    public function test_quizzes(): void {
        global $DB;
        [$course, $a, $b] = $this->setup_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $base = (object) [
            'quiz' => $quiz->id, 'layout' => '', 'currentpage' => 0, 'preview' => 0, 'timemodified' => self::NOW,
            'sumgrades' => 0, 'timecheckstate' => null,
        ];
        $attempts = [
            [$a->id, 1, 'finished', self::NOW - 5 * DAYSECS, 0], [$a->id, 2, 'finished', self::NOW - 2 * DAYSECS, 0],
            [$b->id, 1, 'inprogress', self::NOW - DAYSECS, 0], [$b->id, 2, 'finished', self::NOW, 1],
        ];
        foreach ($attempts as $i => [$userid, $n, $state, $start, $preview]) {
            $DB->insert_record('quiz_attempts', (object) ([
                'userid' => $userid, 'attempt' => $n, 'uniqueid' => 9000 + $i, 'state' => $state, 'timestart' => $start,
                'timefinish' => $start + 60, 'preview' => $preview,
            ] + (array) $base));
        }

        $rows = $this->keyed((new activity_provider($this->clock))->quizzes([$course->id], []));
        $ra = $rows["{$a->id}:{$quiz->cmid}"];
        $this->assertSame(2, $ra['attempts']);
        $this->assertSame(2, $ra['finished_attempts']);
        $this->assertSame('finished', $ra['quiz_status']);
        $this->assertSame(self::NOW - 2 * DAYSECS, $ra['last_attempt_date']);
        $rb = $rows["{$b->id}:{$quiz->cmid}"];
        $this->assertSame(1, $rb['attempts'], 'the preview attempt is not counted');
        $this->assertSame('in_progress', $rb['quiz_status']);
        $open = $this->keyed((new activity_provider($this->clock))->quizzes([$course->id], ['filter' => 'open']));
        $this->assertSame(["{$b->id}:{$quiz->cmid}"], array_keys($open));
    }

    /**
     * Engagement counts inside the window only, zero for a quiet learner, and the log must be on.
     */
    public function test_engagement(): void {
        global $DB;
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        get_log_manager(true);
        $this->assertTrue(activity_provider::log_available());
        [$course, $a, $b] = $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $start = self::NOW - 30 * DAYSECS;
        $end = self::NOW;
        $log = function (
            int $userid,
            string $event,
            int $time,
            int $level = 70,
            string $crud = 'r',
            int $courseid = 0
        ) use (
            $course,
            $DB
        ) {
            $DB->insert_record('logstore_standard_log', (object) [
                'eventname' => $event, 'component' => 'core', 'action' => 'x', 'target' => 'x', 'crud' => $crud,
                'edulevel' => 2, 'contextid' => 1, 'contextlevel' => $level, 'contextinstanceid' => 0, 'userid' => $userid,
                'courseid' => $courseid ?: $course->id, 'relateduserid' => null, 'anonymous' => 0, 'other' => null,
                'timecreated' => $time, 'origin' => 'web', 'ip' => '127.0.0.1', 'realuserid' => null,
            ]);
        };
        $view = '\core\event\course_viewed';
        $mod = '\mod_page\event\course_module_viewed';
        $log($a->id, $view, $start + DAYSECS, 50);
        $log($a->id, $view, $start + DAYSECS + 60, 50);
        $log($a->id, $mod, $start + 2 * DAYSECS);
        $log($a->id, $mod, $start - DAYSECS); // Before the window.
        $log($a->id, $mod, $end); // The end is not included.
        $log($a->id, '\core\event\user_loggedin', $start + 5 * DAYSECS, 10, 'r', SITEID);
        $log($a->id, '\core\event\user_loggedin', $start + 6 * DAYSECS, 10, 'r', SITEID);

        $fields = ['course_views', 'activity_views', 'active_days', 'site_logins'];
        $rows = $this->keyed((new activity_provider($this->clock))->engagement([$course->id], ['fields' => $fields], $start, $end));
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[$a->id]['course_views']);
        $this->assertSame(1, $rows[$a->id]['activity_views']);
        $this->assertSame(2, $rows[$a->id]['active_days'], 'two different days in the course');
        $this->assertSame(2, $rows[$a->id]['site_logins']);
        $quiet = $rows[$b->id];
        $this->assertSame(0, $quiet['course_views'] + $quiet['activity_views'] + $quiet['active_days'] + $quiet['site_logins']);
        $this->assertSame($start, $rows[$a->id]['window_start']);
        $this->assertSame($end, $rows[$a->id]['window_end']);
        // Only the chosen counts are computed: with none chosen the counts stay 0.
        $none = $this->keyed((new activity_provider($this->clock))->engagement([$course->id], ['fields' => []], $start, $end));
        $this->assertSame(0, $none[$a->id]['course_views']);

        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $this->assertFalse(activity_provider::log_available());
    }

    /**
     * Stored choices are cleaned: unknown files, columns and activity types never get through.
     */
    public function test_options_are_cleaned(): void {
        $json = json_encode(['groups' => [
            'activity_quizzes' => ['fields' => ['attempts', 'password', 'grade'], 'filter' => 'overdue'],
            'activity_completion' => ['fields' => ['section'], 'filter' => 'open', 'modtypes' => ['quiz', 'nonsense']],
            'activity_hacked' => ['fields' => ['x']],
        ], 'window' => 'forever']);
        $opts = activity_options::parse($json);
        $this->assertSame(['activity_completion', 'activity_quizzes'], array_keys($opts['groups']));
        $this->assertSame(['attempts', 'grade'], $opts['groups']['activity_quizzes']['fields']);
        $this->assertSame('all', $opts['groups']['activity_quizzes']['filter'], 'overdue exists only for assignments');
        $this->assertSame(['quiz'], $opts['groups']['activity_completion']['modtypes']);
        $this->assertSame('', $opts['window']);
        $this->assertSame([], activity_options::parse('not json')['groups']);
        $this->assertSame([], activity_options::parse(null)['groups']);

        $monthly = (object) ['activityopts' => null, 'frequency' => 'monthly'];
        $this->assertSame('lastmonth', activity_options::window($monthly));
        $weekly = (object) ['activityopts' => null, 'frequency' => 'weekly'];
        $this->assertSame('sinceprev', activity_options::window($weekly));
        $chosen = (object) ['activityopts' => json_encode(['groups' => [], 'window' => 'monthtodate']), 'frequency' => 'monthly'];
        $this->assertSame('monthtodate', activity_options::window($chosen));
    }

    /**
     * The form data round trip: ticked groups, fields and filters go in, come out, and the columns follow the contract.
     */
    public function test_form_round_trip_and_columns(): void {
        $data = (object) [
            'act_completion' => 1, 'actfields_completion' => ['completion_date', 'activity_type'], 'actmodtypes' => ['page'],
            'act_assignments' => 0, 'act_quizzes' => 1, 'actfields_quizzes' => [], 'actfilter_quizzes' => 'open',
            'act_engagement' => 1, 'actfields_engagement' => ['site_logins'], 'actwindow' => 'lastmonth',
        ];
        $json = activity_options::from_form($data);
        $schedule = (object) ['activityopts' => $json];
        $this->assertSame(['activity_completion', 'activity_quizzes', 'activity_engagement'], activity_options::files($schedule));
        $values = activity_options::to_form($schedule);
        $this->assertSame(1, $values['act_completion']);
        $this->assertSame(['activity_type', 'completion_date'], $values['actfields_completion'], 'contract order');
        $this->assertSame(['page'], $values['actmodtypes']);
        $this->assertSame('open', $values['actfilter_quizzes']);
        $this->assertSame('lastmonth', $values['actwindow']);
        $this->assertNull(activity_options::from_form((object) ['act_completion' => 0]));

        $this->assertSame(
            ['user_id', 'idnumber', 'course_id', 'course_shortname', 'cm_id', 'activity_name', 'activity_type', 'completion_date'],
            contract::columns('activity_completion', ['idnumber'], ['completion_date', 'activity_type', 'bogus'])
        );
        $this->assertSame(
            ['user_id', 'course_id', 'course_shortname', 'cm_id', 'activity_name'],
            contract::columns('activity_assignments', [], [])
        );
        foreach (contract::ACTIVITY_FILES as $file) {
            foreach (contract::columns($file, contract::IDENTITY, contract::activity_fields($file)) as $column) {
                $this->assertNotEmpty(contract::type($column));
                $this->assertTrue(get_string_manager()->string_exists("col_$column", 'local_reportfeed')
                    || get_string_manager()->string_exists("acol_$column", 'local_reportfeed'), $column);
            }
        }
    }
}
