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

use local_reportfeed\local\data_provider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Who appears, what they see, and how each value is computed (ADR-004, changes C8 to C11).
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(data_provider::class)]
final class data_provider_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Rows of one course, keyed by user id.
     *
     * @param int $courseid
     * @param int|null $viewerid
     * @return array
     */
    private function rows(int $courseid, ?int $viewerid = null): array {
        $out = [];
        foreach ((new data_provider())->learner_course([$courseid], $viewerid) as $row) {
            $out[$row['user_id']] = $row;
        }
        return $out;
    }

    /**
     * Create a course with completion on.
     *
     * @param array $extra course fields
     * @return \stdClass
     */
    private function course(array $extra = []): \stdClass {
        $this->setAdminUser();
        return $this->getDataGenerator()->create_course($extra + ['enablecompletion' => 1]);
    }

    /**
     * Add a tracked page to a course.
     *
     * @param \stdClass $course
     * @param array $extra module fields
     * @return int course module id
     */
    private function activity(\stdClass $course, array $extra = []): int {
        return (int) $this->getDataGenerator()->create_module(
            'page',
            $extra + ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]
        )->cmid;
    }

    /**
     * Mark an activity complete for a user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param int $userid
     */
    private function complete(\stdClass $course, int $cmid, int $userid): void {
        $info = new \completion_info(get_course($course->id));
        $info->update_state(get_coursemodule_from_id('page', $cmid), COMPLETION_COMPLETE, $userid);
    }

    /**
     * Loading learners in small chunks gives exactly the rows of one big load, in the same order (grades, completion
     * and last access included), so memory use can stay flat on a course with tens of thousands of learners.
     */
    public function test_chunked_loading_gives_identical_rows(): void {
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $cmid = $this->activity($course);
        $item = \grade_item::fetch_course_item($course->id);
        for ($i = 0; $i < 7; $i++) {
            $user = $gen->create_and_enrol($course, 'student');
            if ($i % 2) {
                $this->complete($course, $cmid, $user->id);
            }
            $item->update_final_grade($user->id, 50 + $i);
        }
        // A grade item that still needs regrading is blanked on purpose (K4); settle it so both loads see grades.
        grade_regrade_final_grades($course->id);
        $whole = iterator_to_array((new data_provider())->learner_course([$course->id]), false);
        $this->assertCount(7, $whole);
        foreach ([1, 2, 3, 7, 100] as $size) {
            $chunked = iterator_to_array((new data_provider(null, $size))->learner_course([$course->id]), false);
            $this->assertSame($whole, $chunked, "chunk size $size");
        }
    }

    /**
     * Only tracked learners with an active enrolment are rows: no teachers, no suspended people (C10).
     */
    public function test_only_active_tracked_learners_appear(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $student = $gen->create_and_enrol($course, 'student');
        $gen->create_and_enrol($course, 'editingteacher');
        $gen->create_and_enrol($course, 'teacher');
        $suspendedenrol = $gen->create_user();
        $gen->enrol_user($suspendedenrol->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $suspendeduser = $gen->create_and_enrol($course, 'student');
        $DB->set_field('user', 'suspended', 1, ['id' => $suspendeduser->id]);
        $deleted = $gen->create_and_enrol($course, 'student');
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);
        $future = $gen->create_user();
        $gen->enrol_user($future->id, $course->id, 'student', 'manual', time() + DAYSECS);

        $this->assertSame([(int) $student->id], array_keys($this->rows($course->id)));
    }

    /**
     * A learner with two active enrolment methods is one row, starting at the earliest start (C10).
     */
    public function test_two_enrolment_methods_give_one_row(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $self = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self'], '*', MUST_EXIST);
        enrol_get_plugin('self')->update_status($self, ENROL_INSTANCE_ENABLED);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, 'student', 'manual', 2000000000 - 30 * DAYSECS);
        $gen->enrol_user($user->id, $course->id, 'student', 'self', 1700000000);

        $rows = $this->rows($course->id);
        $this->assertCount(1, $rows);
        $this->assertSame(1700000000, $rows[$user->id]['enrol_start']);
    }

    /**
     * Our set-based percentage equals Moodle's own function as the learner sees it (C8), hidden activity included.
     */
    public function test_percent_matches_core_as_the_learner(): void {
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $cms = [$this->activity($course), $this->activity($course), $this->activity($course)];
        $hidden = $this->activity($course, ['visible' => 0]);
        $this->activity($course, ['completion' => COMPLETION_TRACKING_NONE]);
        $plans = [[], [0], [0, 1], [0, 2], [1], [0, 1, 2], [0, 'h'], ['h']];
        $users = [];
        foreach ($plans as $i => $plan) {
            $user = $gen->create_and_enrol($course, 'student');
            $users[$user->id] = $i;
            foreach ($plan as $n) {
                $this->complete($course, $n === 'h' ? $hidden : $cms[$n], $user->id);
            }
        }
        $rows = $this->rows($course->id);
        $this->assertCount(count($plans), $rows);
        $seen = [];
        foreach ($users as $userid => $i) {
            $this->setUser($userid);
            $core = \core_completion\progress::get_course_progress_percentage(get_course($course->id), $userid);
            $this->assertEqualsWithDelta($core, $rows[$userid]['completion_percent'], 0.01, "plan $i");
            $seen[(string) round($core, 2)] = true;
        }
        $this->assertGreaterThan(3, count($seen), 'the plans should give a spread of values');
    }

    /**
     * Completion off, or no tracked activity, gives an empty percent (and no status when completion is off).
     */
    public function test_completion_off_or_nothing_tracked(): void {
        $gen = $this->getDataGenerator();
        global $DB;
        $off = $this->course();
        $this->activity($off);
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $off->id]);
        $u1 = $gen->create_and_enrol($off, 'student');
        $row = $this->rows($off->id)[$u1->id];
        $this->assertNull($row['completion_percent']);
        $this->assertNull($row['completion_status']);

        $empty = $this->course();
        $u2 = $gen->create_and_enrol($empty, 'student');
        $row = $this->rows($empty->id)[$u2->id];
        $this->assertNull($row['completion_percent']);
        $this->assertSame('not_started', $row['completion_status']);
    }

    /**
     * Known limit: availability restrictions are not applied, so a restricted activity still counts (C8).
     */
    public function test_availability_restrictions_are_not_applied(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $open = $this->activity($course);
        $restricted = $this->activity($course);
        $json = '{"op":"&","c":[{"type":"date","d":">=","t":4102444800}],"showc":[true]}';
        $DB->set_field('course_modules', 'availability', $json, ['id' => $restricted]);
        rebuild_course_cache($course->id, true);
        $user = $gen->create_and_enrol($course, 'student');
        $this->complete($course, $open, $user->id);
        $this->assertEqualsWithDelta(50.0, $this->rows($course->id)[$user->id]['completion_percent'], 0.001);
    }

    /**
     * Hidden grades are blank for the HR feed; a viewer who may see hidden grades still sees them (C9, K4).
     */
    public function test_hidden_grades(): void {
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        [$a, $b] = [$gen->create_and_enrol($course, 'student'), $gen->create_and_enrol($course, 'student')];
        $item = new \grade_item($gen->create_grade_item(['courseid' => $course->id, 'grademax' => 200]), false);
        $item->update_final_grade($a->id, 150, 'test');
        $item->update_final_grade($b->id, 100, 'test');
        grade_regrade_final_grades($course->id);
        $courseitem = \grade_item::fetch_course_item($course->id);

        $rows = $this->rows($course->id);
        $this->assertEqualsWithDelta(150.0, $rows[$a->id]['grade'], 0.001);
        $this->assertEqualsWithDelta(75.0, $rows[$a->id]['grade_percent'], 0.001);
        $this->assertEqualsWithDelta(50.0, $rows[$b->id]['grade_percent'], 0.001);

        // One learner's total hidden.
        \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $b->id])->set_hidden(1);
        $rows = $this->rows($course->id);
        $this->assertNotNull($rows[$a->id]['grade']);
        $this->assertNull($rows[$b->id]['grade']);
        $this->assertNull($rows[$b->id]['grade_percent']);
        $this->assertEqualsWithDelta(100.0, $this->rows($course->id, $teacher->id)[$b->id]['grade'], 0.001);
        \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $b->id])->set_hidden(0);

        // The whole course total hidden, then hidden until a future date.
        foreach ([1, time() + 7 * DAYSECS] as $hide) {
            $courseitem->set_hidden($hide);
            $rows = $this->rows($course->id);
            $this->assertNull($rows[$a->id]['grade'], "hide=$hide");
            $this->assertNull($rows[$b->id]['grade'], "hide=$hide");
            $this->assertNotNull($this->rows($course->id, $teacher->id)[$a->id]['grade'], "hide=$hide");
        }
    }

    /**
     * A teacher override of the course total is what the feed reports.
     */
    public function test_overridden_grade_is_reported(): void {
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $user = $gen->create_and_enrol($course, 'student');
        $item = new \grade_item($gen->create_grade_item(['courseid' => $course->id, 'grademax' => 100]), false);
        $item->update_final_grade($user->id, 40, 'test');
        grade_regrade_final_grades($course->id);
        $courseitem = \grade_item::fetch_course_item($course->id);
        $courseitem->update_final_grade($user->id, 90, 'override');
        $gg = \grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $user->id]);
        $gg->set_overridden(true);
        grade_regrade_final_grades($course->id);

        $this->assertEqualsWithDelta(90.0, $this->rows($course->id)[$user->id]['grade'], 0.001);
    }

    /**
     * Last access: a missing row is empty, a future time never gives negative days, never-accessed counts from enrolment.
     */
    public function test_last_access_and_days_inactive(): void {
        global $DB;
        $now = 1791288000;
        $this->mock_clock_with_frozen($now);
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $never = $gen->create_user();
        $gen->enrol_user($never->id, $course->id, 'student', 'manual', $now - 10 * DAYSECS - 100);
        $future = $gen->create_and_enrol($course, 'student');
        $DB->insert_record('user_lastaccess', [
            'userid' => $future->id, 'courseid' => $course->id, 'timeaccess' => $now + DAYSECS,
        ]);

        $rows = $this->rows($course->id);
        $this->assertNull($rows[$never->id]['last_access_course']);
        $this->assertSame(10, $rows[$never->id]['days_inactive']);
        $this->assertSame(0, $rows[$future->id]['days_inactive']);
    }

    /**
     * A recipient without the digest capability in the course gets nothing, enrolled or not.
     */
    public function test_viewer_without_capability_gets_nothing(): void {
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $student = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $stranger = $gen->create_user();

        $this->assertCount(1, $this->rows($course->id, $teacher->id));
        $this->assertSame([], $this->rows($course->id, $student->id));
        $this->assertSame([], $this->rows($course->id, $stranger->id));
        $this->assertSame([], $this->rows($course->id, 0));
    }

    /**
     * A teacher in another course never sees this course.
     */
    public function test_teacher_of_another_course_gets_nothing(): void {
        $gen = $this->getDataGenerator();
        $mine = $this->course();
        $other = $this->course();
        $gen->create_and_enrol($other, 'student');
        $teacher = $gen->create_and_enrol($mine, 'editingteacher');
        $this->assertSame([], $this->rows($other->id, $teacher->id));
    }

    /**
     * With separate groups, a viewer who cannot access all groups sees only their own group; without groups, nothing.
     */
    public function test_group_scoping(): void {
        $gen = $this->getDataGenerator();
        $course = $this->course(['groupmode' => SEPARATEGROUPS]);
        $s1 = $gen->create_and_enrol($course, 'student');
        $s2 = $gen->create_and_enrol($course, 'student');
        $s3 = $gen->create_and_enrol($course, 'student');
        $ta = $gen->create_and_enrol($course, 'teacher');
        $loner = $gen->create_and_enrol($course, 'teacher');
        $lead = $gen->create_and_enrol($course, 'editingteacher');
        $g1 = $gen->create_group(['courseid' => $course->id]);
        $g2 = $gen->create_group(['courseid' => $course->id]);
        foreach ([[$g1, $s1], [$g1, $s2], [$g1, $ta], [$g2, $s3]] as [$group, $user]) {
            $gen->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
        }

        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], array_keys($this->rows($course->id, $ta->id)));
        $this->assertSame([], $this->rows($course->id, $loner->id));
        $this->assertCount(3, $this->rows($course->id, $lead->id), 'accessallgroups sees everyone');
        $this->assertCount(3, $this->rows($course->id), 'the HR feed has no group limit');

        // Without groups in use the same assistant sees everyone.
        $plain = $this->course();
        $gen->create_and_enrol($plain, 'student');
        $helper = $gen->create_and_enrol($plain, 'teacher');
        $this->assertCount(1, $this->rows($plain->id, $helper->id));
    }

    /**
     * A recipient who may not view grades gets none, even when they are not hidden.
     */
    public function test_no_grades_without_view_grades_capability(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $this->course();
        $student = $gen->create_and_enrol($course, 'student');
        $ta = $gen->create_and_enrol($course, 'teacher');
        $item = new \grade_item($gen->create_grade_item(['courseid' => $course->id, 'grademax' => 100]), false);
        $item->update_final_grade($student->id, 70, 'test');
        grade_regrade_final_grades($course->id);

        $this->assertNotNull($this->rows($course->id, $ta->id)[$student->id]['grade']);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('moodle/grade:viewall', CAP_PROHIBIT, $roleid, \context_course::instance($course->id)->id);
        $row = $this->rows($course->id, $ta->id)[$student->id];
        $this->assertNull($row['grade']);
        $this->assertNull($row['grade_percent']);
    }

    /**
     * The site course and a missing course produce nothing instead of an error.
     */
    public function test_site_course_and_missing_course_are_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame([], iterator_to_array((new data_provider())->learner_course([SITEID, 99999]), false));
    }

    /**
     * The summary counts courses, completed courses and averages per learner across courses.
     */
    public function test_learner_summary(): void {
        $now = 1791288000;
        $this->mock_clock_with_frozen($now);
        $gen = $this->getDataGenerator();
        $c1 = $this->course();
        $c2 = $this->course();
        $c3 = $this->course();
        $a1 = $this->activity($c1);
        $a2 = $this->activity($c2);
        $this->activity($c2);
        $this->activity($c3, ['completion' => COMPLETION_TRACKING_NONE]);
        $user = $gen->create_user();
        $only = $gen->create_user();
        foreach ([$c1, $c2, $c3] as $c) {
            $gen->enrol_user($user->id, $c->id, 'student', 'manual', $now - 20 * DAYSECS);
        }
        $gen->enrol_user($only->id, $c1->id, 'student', 'manual', $now - 3 * DAYSECS);
        $this->complete($c1, $a1, $user->id);
        $this->complete($c2, $a2, $user->id);
        (new \completion_completion(['course' => $c1->id, 'userid' => $user->id]))->mark_complete($now - DAYSECS);

        $rows = array_column((new data_provider())->learner_summary([$c1->id, $c2->id, $c3->id]), null, 'user_id');
        $this->assertSame(3, $rows[$user->id]['courses_enrolled']);
        $this->assertSame(1, $rows[$user->id]['courses_completed']);
        // Courses 1 and 2 count (100 and 50); course 3 has nothing tracked and is left out of the average.
        $this->assertEqualsWithDelta(75.0, $rows[$user->id]['avg_completion_percent'], 0.001);
        $this->assertSame(20, $rows[$user->id]['days_inactive_site']);
        $this->assertSame(1, $rows[$only->id]['courses_enrolled']);
        $this->assertSame(3, $rows[$only->id]['days_inactive_site']);
    }
}
