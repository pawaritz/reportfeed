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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_reportfeed\privacy\provider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Privacy API: what is declared, found, exported and deleted.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass[] Users by key. */
    private array $u = [];

    /** @var int The course with digest settings. */
    private int $courseid;

    /**
     * Data: ann receives schedule 1 and a run; bea owns the schedule; cat has a digest and was nominated by dan.
     */
    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        foreach (['ann', 'bea', 'cat', 'dan', 'eve'] as $key) {
            $this->u[$key] = $gen->create_user(['username' => $key]);
        }
        $this->courseid = (int) $gen->create_course()->id;
        $now = time();
        $sid = $DB->insert_record('local_reportfeed_schedule', [
            'name' => 'Weekly', 'ownerid' => $this->u['bea']->id, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_reportfeed_recipient', ['scheduleid' => $sid, 'userid' => $this->u['ann']->id]);
        $DB->insert_record('local_reportfeed_recipient', ['scheduleid' => $sid, 'userid' => $this->u['eve']->id]);
        $runid = $DB->insert_record('local_reportfeed_run', [
            'type' => 'hrfeed', 'scheduleid' => $sid, 'periodend' => 20261006, 'timedue' => $now, 'timecreated' => $now,
        ]);
        foreach (['ann', 'eve'] as $key) {
            $DB->insert_record('local_reportfeed_delivery', [
                'runid' => $runid, 'userid' => $this->u[$key]->id, 'filekey' => 'learner_course', 'status' => 'sent',
                'timemodified' => $now,
            ]);
        }
        $digestrun = $DB->insert_record('local_reportfeed_run', [
            'type' => 'teacherdigest', 'userid' => $this->u['cat']->id, 'courseid' => $this->courseid,
            'periodend' => 20261006, 'timedue' => $now, 'timecreated' => $now,
        ]);
        $DB->insert_record('local_reportfeed_delivery', [
            'runid' => $digestrun, 'userid' => $this->u['cat']->id, 'filekey' => 'teacher_digest', 'status' => 'sent',
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_reportfeed_digestcourse', [
            'userid' => $this->u['cat']->id, 'courseid' => $this->courseid, 'choices' => 'grade',
            'nominatedby' => $this->u['dan']->id, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Every declared table, field and summary has a language string.
     */
    public function test_metadata_strings_exist(): void {
        $collection = provider::get_metadata(new collection('local_reportfeed'));
        $items = $collection->get_collection();
        $this->assertNotEmpty($items);
        $sm = get_string_manager();
        foreach ($items as $item) {
            $this->assertTrue($sm->string_exists($item->get_summary(), 'local_reportfeed'), $item->get_summary());
            foreach ($item->get_privacy_fields() as $field) {
                $this->assertTrue($sm->string_exists($field, 'local_reportfeed'), $field);
            }
        }
    }

    /**
     * Contexts: system for recipients, owners and delivery logs; the course for digest settings and nominators.
     */
    public function test_contexts_for_user(): void {
        $system = \context_system::instance()->id;
        $course = \context_course::instance($this->courseid)->id;
        $ids = fn(\stdClass $u): array => array_map(
            'intval',
            provider::get_contexts_for_userid($u->id)->get_contextids()
        );
        $this->assertSame([$system], $ids($this->u['ann']));
        $this->assertSame([$system], $ids($this->u['bea']));
        $this->assertEqualsCanonicalizing([$system, $course], $ids($this->u['cat']));
        $this->assertSame([$course], $ids($this->u['dan']));
        $this->assertSame([], $ids($this->getDataGenerator()->create_user()));
    }

    /**
     * Users in each context, for bulk requests.
     */
    public function test_users_in_context(): void {
        $list = new userlist(\context_system::instance(), 'local_reportfeed');
        provider::get_users_in_context($list);
        $this->assertEqualsCanonicalizing(
            [$this->u['ann']->id, $this->u['bea']->id, $this->u['cat']->id, $this->u['eve']->id],
            $list->get_userids()
        );
        $list = new userlist(\context_course::instance($this->courseid), 'local_reportfeed');
        provider::get_users_in_context($list);
        $this->assertEqualsCanonicalizing([$this->u['cat']->id, $this->u['dan']->id], $list->get_userids());
    }

    /**
     * Export holds the user's own schedules, deliveries and digest settings.
     */
    public function test_export(): void {
        $user = $this->u['ann'];
        $list = new approved_contextlist($user, 'local_reportfeed', [\context_system::instance()->id]);
        provider::export_user_data($list);
        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'local_reportfeed')]);
        $this->assertSame(['Weekly'], $data->recipient_of_schedules);
        $this->assertCount(1, $data->deliveries);
        $this->assertSame('learner_course', $data->deliveries[0]['file']);

        $cat = $this->u['cat'];
        $list = new approved_contextlist($cat, 'local_reportfeed', [\context_course::instance($this->courseid)->id]);
        provider::export_user_data($list);
        $data = writer::with_context(\context_course::instance($this->courseid))
            ->get_data([get_string('pluginname', 'local_reportfeed')]);
        $this->assertSame('recipient', $data->digest_settings[0]['role']);
        $this->assertSame('grade', $data->digest_settings[0]['columns']);
    }

    /**
     * Deleting one user removes their rows only; a schedule they owned stays but loses its owner.
     */
    public function test_delete_for_user(): void {
        global $DB;
        $system = \context_system::instance();
        provider::delete_data_for_user(new approved_contextlist($this->u['ann'], 'local_reportfeed', [$system->id]));
        $this->assertFalse($DB->record_exists('local_reportfeed_recipient', ['userid' => $this->u['ann']->id]));
        $this->assertFalse($DB->record_exists('local_reportfeed_delivery', ['userid' => $this->u['ann']->id]));
        $this->assertTrue($DB->record_exists('local_reportfeed_recipient', ['userid' => $this->u['eve']->id]));
        $this->assertTrue($DB->record_exists('local_reportfeed_delivery', ['userid' => $this->u['eve']->id]));

        provider::delete_data_for_user(new approved_contextlist($this->u['bea'], 'local_reportfeed', [$system->id]));
        $this->assertSame(1, $DB->count_records('local_reportfeed_schedule'));
        $this->assertSame(0, (int) $DB->get_field('local_reportfeed_schedule', 'ownerid', []));

        // The digest recipient: their digest run, its delivery and their settings go.
        $course = \context_course::instance($this->courseid);
        provider::delete_data_for_user(new approved_contextlist($this->u['cat'], 'local_reportfeed', [$system->id, $course->id]));
        $this->assertSame(0, $DB->count_records('local_reportfeed_digestcourse'));
        $this->assertFalse($DB->record_exists('local_reportfeed_run', ['type' => 'teacherdigest']));
        $this->assertFalse($DB->record_exists('local_reportfeed_delivery', ['filekey' => 'teacher_digest']));
    }

    /**
     * A nominator's deletion keeps the digest but clears who nominated it.
     */
    public function test_delete_nominator(): void {
        global $DB;
        $course = \context_course::instance($this->courseid);
        provider::delete_data_for_user(new approved_contextlist($this->u['dan'], 'local_reportfeed', [$course->id]));
        $row = $DB->get_record('local_reportfeed_digestcourse', [], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->nominatedby);
        $this->assertSame((int) $this->u['cat']->id, (int) $row->userid);
    }

    /**
     * Bulk deletion of listed users, and of everyone in a context.
     */
    public function test_delete_for_users_and_all(): void {
        global $DB;
        $system = \context_system::instance();
        $ids = [$this->u['ann']->id, $this->u['eve']->id];
        provider::delete_data_for_users(new approved_userlist($system, 'local_reportfeed', $ids));
        $this->assertSame(0, $DB->count_records('local_reportfeed_recipient'));
        $this->assertSame(1, $DB->count_records('local_reportfeed_delivery'));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertSame(0, $DB->count_records('local_reportfeed_delivery'));
        $this->assertSame(0, $DB->count_records('local_reportfeed_run'));
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->courseid));
        $this->assertSame(0, $DB->count_records('local_reportfeed_digestcourse'));
    }
    /**
     * A learner who appears in a roster snapshot is found, exported and erased, and nobody else is touched.
     */
    public function test_roster_rows_follow_the_learner(): void {
        global $DB;
        $fay = $this->getDataGenerator()->create_user(['username' => 'fay']);
        $sid = (int) $DB->get_field('local_reportfeed_schedule', 'id', ['name' => 'Weekly']);
        $runid = (int) $DB->get_field('local_reportfeed_run', 'id', ['type' => 'hrfeed']);
        foreach ([$fay->id => 'removed', $this->u['ann']->id => 'new'] as $userid => $change) {
            $DB->insert_record('local_reportfeed_roster', [
                'scheduleid' => $sid, 'runid' => $runid, 'userid' => $userid, 'changetype' => $change,
                'reason' => $change === 'removed' ? 'not_enrolled' : null,
            ]);
        }
        $system = \context_system::instance();
        $this->assertSame([$system->id], array_map('intval', provider::get_contexts_for_userid($fay->id)->get_contextids()));
        $list = new userlist($system, 'local_reportfeed');
        provider::get_users_in_context($list);
        $this->assertContains((int) $fay->id, array_map('intval', $list->get_userids()));

        provider::export_user_data(new approved_contextlist($fay, 'local_reportfeed', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'local_reportfeed')]);
        $this->assertSame('removed', $data->roster[0]['change']);
        $this->assertSame('not_enrolled', $data->roster[0]['reason']);

        provider::delete_data_for_user(new approved_contextlist($fay, 'local_reportfeed', [$system->id]));
        $this->assertSame(0, $DB->count_records('local_reportfeed_roster', ['userid' => $fay->id]));
        $this->assertSame(1, $DB->count_records('local_reportfeed_roster', ['userid' => $this->u['ann']->id]));
    }
}
