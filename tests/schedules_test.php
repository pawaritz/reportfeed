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

use local_reportfeed\local\health;
use local_reportfeed\local\schedules;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Creating and validating schedules, and the health warnings.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedules::class)]
#[CoversClass(health::class)]
final class schedules_test extends \advanced_testcase {
    /**
     * A user holding the HR feed capability.
     *
     * @return \stdClass
     */
    private function hr_user(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/reportfeed:receivehrfeed', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * Valid form data.
     *
     * @param array $extra fields to override
     * @return \stdClass
     */
    private function data(array $extra = []): \stdClass {
        return (object) ($extra + [
            'name' => 'Weekly', 'enabled' => 1, 'weekday' => 1, 'hour' => 7, 'minute' => 30, 'scope' => 'all',
            'categoryids' => [], 'courseids' => [], 'recipients' => [$this->hr_user()->id],
            'identitycols' => ['email', 'idnumber'], 'usebom' => 0, 'zip' => 1,
        ]);
    }

    public function test_errors(): void {
        $this->resetAfterTest();
        $this->assertSame([], schedules::errors($this->data()));
        $this->assertArrayHasKey('name', schedules::errors($this->data(['name' => '  '])));
        $this->assertArrayHasKey('recipients', schedules::errors($this->data(['recipients' => []])));
        // An empty picker submits a marker string that becomes 0: it counts as nobody, and is never saved.
        $marker = ['_qf__force_multiselect_submission', 0];
        $this->assertArrayHasKey('recipients', schedules::errors($this->data(['recipients' => $marker])));
        $this->assertSame([], schedules::errors($this->data(['recipients' => $marker, 'externalemails' => 'a@b.example'])));
        $this->assertArrayHasKey('categoryids', schedules::errors($this->data(['scope' => 'categories'])));
        $this->assertArrayHasKey('courseids', schedules::errors($this->data(['scope' => 'courses'])));
        $this->assertArrayHasKey('scope', schedules::errors($this->data(['scope' => 'nonsense'])));

        $plain = $this->getDataGenerator()->create_user();
        $errors = schedules::errors($this->data(['recipients' => [$plain->id]]));
        $this->assertStringContainsString(fullname($plain), $errors['recipients']);
        $deleted = $this->getDataGenerator()->create_user(['deleted' => 1]);
        $this->assertArrayHasKey('recipients', schedules::errors($this->data(['recipients' => [$deleted->id]])));
    }

    public function test_frequency_errors_and_storage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertArrayHasKey('frequency', schedules::errors($this->data(['frequency' => 'daily'])));
        $this->assertArrayHasKey('monthrule', schedules::errors($this->data(['frequency' => 'monthly', 'monthrule' => 'nope'])));
        $toolate = $this->data(['frequency' => 'monthly', 'monthrule' => 'day', 'monthday' => 32]);
        $this->assertArrayHasKey('monthday', schedules::errors($toolate));
        $this->assertSame([], schedules::errors($this->data(['frequency' => 'monthly', 'monthrule' => 'lastday'])));

        // A monthly "first Friday" keeps the weekday chosen in its own field; the form's date selector gives a unix time.
        $id = schedules::save($this->data([
            'frequency' => 'monthly', 'monthrule' => 'firstdow', 'weekday' => 1, 'dow' => 5,
            'anchor' => make_timestamp(2026, 10, 12),
        ]), 1);
        $row = $DB->get_record('local_reportfeed_schedule', ['id' => $id]);
        $this->assertSame('monthly', $row->frequency);
        $this->assertSame('firstdow', $row->monthrule);
        $this->assertSame('5', $row->weekday);
        $this->assertSame('20261012', $row->anchor);
        $loaded = schedules::load($id);
        $this->assertSame('5', $loaded->dow);
        $this->assertSame(make_timestamp(2026, 10, 12), $loaded->anchor);
    }

    public function test_bundle_scope_and_summary(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertArrayHasKey('bundleid', schedules::errors($this->data(['scope' => 'bundle', 'bundleid' => 0])));
        $bundleid = \local_reportfeed\local\bundles::save((object) ['name' => 'Core <b>', 'courseids' => [$course->id]]);
        $data = $this->data(['scope' => 'bundle', 'bundleid' => $bundleid, 'format' => 'csv']);
        $this->assertSame([], schedules::errors($data));
        $id = schedules::save($data, 1);
        $this->assertSame((string) $bundleid, $DB->get_field('local_reportfeed_schedule', 'scopeids', ['id' => $id]));
        $this->assertSame($bundleid, schedules::load($id)->bundleid);
        $summary = schedules::summary($DB->get_record('local_reportfeed_schedule', ['id' => $id]));
        $this->assertStringContainsString('Bundle: Core &lt;b&gt;', $summary);
        $this->assertStringContainsString('1 recipient(s)', $summary);
        $this->assertStringContainsString('CSV, zipped', $summary);
    }

    public function test_badge(): void {
        $this->assertStringContainsString('bg-success', schedules::badge('sent'));
        $this->assertStringContainsString('bg-danger', schedules::badge('failed'));
        $this->assertStringContainsString('bg-warning', schedules::badge('too_large'));
        $this->assertStringContainsString('bg-secondary', schedules::badge('queued'));
    }

    public function test_describe(): void {
        $this->resetAfterTest();
        $row = (object) [
            'frequency' => 'weekly', 'weekday' => 1, 'hour' => 7, 'minute' => 5, 'monthrule' => 'day', 'monthday' => 3,
        ];
        $this->assertSame('Every Monday 07:05', schedules::describe($row));
        $row->frequency = 'biweekly';
        $this->assertSame('Every 2 weeks, Monday 07:05', schedules::describe($row));
        $row->frequency = 'semimonthly';
        $this->assertSame('1st and 15th of each month at 07:05', schedules::describe($row));
        $row->frequency = 'monthly';
        $this->assertSame('Monthly, day 3 at 07:05', schedules::describe($row));
        $row->monthrule = 'lastworkday';
        $this->assertSame('Monthly, last working day at 07:05', schedules::describe($row));
        $row->monthrule = 'firstdow';
        $row->weekday = 5;
        $this->assertSame('Monthly, first Friday at 07:05', schedules::describe($row));
    }

    public function test_activity_choices_are_saved_and_loaded(): void {
        global $DB;
        $this->resetAfterTest();
        $id = schedules::save($this->data([
            'act_quizzes' => 1, 'actfields_quizzes' => ['attempts', 'bogus'], 'actfilter_quizzes' => 'open',
            'act_engagement' => 1, 'actfields_engagement' => ['active_days'], 'actwindow' => 'monthtodate',
        ]), 1);
        $loaded = schedules::load($id);
        $this->assertSame(1, $loaded->act_quizzes);
        $this->assertSame(['attempts'], $loaded->actfields_quizzes);
        $this->assertSame('open', $loaded->actfilter_quizzes);
        $this->assertSame('monthtodate', $loaded->actwindow);
        $row = $DB->get_record('local_reportfeed_schedule', ['id' => $id]);
        $this->assertStringContainsString('2 activity file(s)', schedules::summary($row));
        // Unticking everything stores nothing; a hand-made JSON value is cleaned the same way.
        schedules::save($this->data(['id' => $id]), 1);
        $this->assertNull($DB->get_field('local_reportfeed_schedule', 'activityopts', ['id' => $id]));
        $handmade = '{"groups":{"activity_quizzes":{"fields":["grade","x"]},"evil":{}}}';
        schedules::save($this->data(['id' => $id, 'activityopts' => $handmade]), 1);
        $this->assertSame(['grade'], schedules::load($id)->actfields_quizzes);
    }

    /**
     * Which HR files a schedule sends: a tick each, the default pair when nothing is said, at least one file overall.
     */
    public function test_hr_file_choice(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Code that never mentions the files gets the default pair.
        $id = schedules::save($this->data(), 2);
        $this->assertSame('learner_course,learner_summary', $DB->get_field('local_reportfeed_schedule', 'hrfiles', ['id' => $id]));

        // The form sends one tick per file; the order is the contract's, whatever the order of the ticks.
        $both = $this->data(['hr_course' => 0, 'hr_summary' => 1, 'hr_roster' => 1]);
        $id = schedules::save($both, 2);
        $this->assertSame('learner_summary,learner_roster', $DB->get_field('local_reportfeed_schedule', 'hrfiles', ['id' => $id]));
        $loaded = schedules::load($id);
        $this->assertSame([0, 1, 1], [(int) $loaded->hr_course, (int) $loaded->hr_summary, (int) $loaded->hr_roster]);
        $this->assertStringContainsString('Learner roster', schedules::summary($loaded));
        $this->assertSame(
            ['learner_summary', 'learner_roster'],
            (new \local_reportfeed\local\run_manager())->files_for($loaded)
        );

        // A name that is not an HR file is ignored.
        $this->assertSame(['learner_roster'], schedules::hr_files(['hrfiles' => 'evil,learner_roster']));

        // Nothing ticked anywhere is refused, with an activity file it is fine.
        $none = $this->data(['hr_course' => 0, 'hr_summary' => 0, 'hr_roster' => 0]);
        $this->assertArrayHasKey('hr_course', schedules::errors($none));
        $withactivity = $this->data(['hr_course' => 0, 'hr_summary' => 0, 'hr_roster' => 0, 'act_completion' => 1]);
        $this->assertArrayNotHasKey('hr_course', schedules::errors($withactivity));
    }

    public function test_save_load_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $data = $this->data(['scope' => 'categories', 'categoryids' => [$category->id], 'identitycols' => ['email', 'hacker']]);
        $id = schedules::save($data, 77);

        $row = $DB->get_record('local_reportfeed_schedule', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('77', $row->ownerid);
        $this->assertSame((string) $category->id, $row->scopeids);
        $this->assertSame('email', $row->identitycols, 'unknown columns are dropped');
        $this->assertSame('1', $row->zip);
        $this->assertSame(1, $DB->count_records('local_reportfeed_recipient', ['scheduleid' => $id]));

        $loaded = schedules::load($id);
        $this->assertSame([(int) $category->id], $loaded->categoryids);
        $this->assertSame([], $loaded->courseids);
        $this->assertEquals($data->recipients, $loaded->recipients);

        // An edit keeps the owner, replaces the recipients and counts as an edit.
        $this->waitForSecond();
        $other = $this->hr_user();
        $edit = $this->data(['id' => $id, 'name' => 'Renamed', 'recipients' => [$other->id]]);
        $this->assertSame($id, schedules::save($edit, 99));
        $row2 = $DB->get_record('local_reportfeed_schedule', ['id' => $id]);
        $this->assertSame('77', $row2->ownerid);
        $this->assertSame('Renamed', $row2->name);
        $this->assertGreaterThan((int) $row->timemodified, (int) $row2->timemodified);
        $this->assertSame([(string) $other->id], array_values(
            $DB->get_fieldset_select('local_reportfeed_recipient', 'userid', 'scheduleid = ?', [$id])
        ));

        schedules::delete($id);
        $this->assertSame(0, $DB->count_records('local_reportfeed_schedule'));
        $this->assertSame(0, $DB->count_records('local_reportfeed_recipient'));
    }

    public function test_health_warnings(): void {
        $this->resetAfterTest();
        set_config('enabled', 0, 'local_reportfeed');
        set_config('allowattachments', 0);
        $warnings = health::warnings();
        $this->assertContains('health_disabled', $warnings);
        $this->assertContains('health_attachments_desc', $warnings);
        $this->assertContains('health_cron', $warnings, 'cron has never run the dispatcher in a fresh test site');

        set_config('enabled', 1, 'local_reportfeed');
        set_config('allowattachments', 1);
        $this->assertNotContains('health_disabled', health::warnings());
        $this->assertNotContains('health_attachments_desc', health::warnings());
    }
}
