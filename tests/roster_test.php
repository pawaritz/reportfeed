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
use local_reportfeed\local\retention;
use local_reportfeed\local\roster;
use local_reportfeed\local\run_manager;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mail_reader.php');

/**
 * The learner roster: new, unchanged and removed learners since the last send.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(roster::class)]
final class roster_test extends \advanced_testcase {
    /** @var int Frozen "now": Tuesday 2026-10-06 12:00 UTC. */
    private const NOW = 1791288000;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setTimezone('UTC', 'UTC');
        $this->mock_clock_with_frozen(self::NOW);
        set_config('enabled', 1, 'local_reportfeed');
    }

    /**
     * A schedule row that only the roster reads.
     *
     * @param string $hrfiles
     * @return int
     */
    private function schedule(string $hrfiles = 'learner_roster'): int {
        global $DB;
        return $DB->insert_record('local_reportfeed_schedule', (object) [
            'name' => 'Roster', 'enabled' => 1, 'weekday' => 2, 'hour' => 8, 'minute' => 0, 'scope' => 'all',
            'identitycols' => 'idnumber', 'hrfiles' => $hrfiles, 'timecreated' => 1, 'timemodified' => 1,
        ]);
    }

    /**
     * A run row of a schedule.
     *
     * @param int $scheduleid
     * @param int $number distinguishes the send date
     * @param string $status
     * @return int
     */
    private function make_run(int $scheduleid, int $number, string $status = 'sent'): int {
        global $DB;
        return $DB->insert_record('local_reportfeed_run', (object) [
            'type' => 'hrfeed', 'scheduleid' => $scheduleid, 'periodend' => 20261000 + $number, 'timedue' => $number,
            'status' => $status, 'timecreated' => self::NOW - 100 + $number,
        ]);
    }

    /**
     * The roster of a run as user id => [change, reason].
     *
     * @param int[] $courseids
     * @param int $scheduleid
     * @param int|null $runid
     * @return array<int,string[]>
     */
    private function roster(array $courseids, int $scheduleid, ?int $runid): array {
        $out = [];
        foreach (roster::rows(new data_provider(), $courseids, $scheduleid, $runid) as $row) {
            $out[$row['user_id']] = [$row['change_type'], $row['reason']];
        }
        return $out;
    }

    public function test_first_roster_marks_everyone_new(): void {
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $b = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $sid = $this->schedule();

        $rows = $this->roster([$course->id], $sid, $this->make_run($sid, 1));

        $this->assertSame([$a->id => ['new', ''], $b->id => ['new', '']], $rows);
    }

    public function test_changes_since_the_last_sent_roster_and_why(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator();
        $keep = $gen->create_and_enrol($course, 'student');
        $left = $gen->create_and_enrol($course, 'student');
        $paused = $gen->create_and_enrol($course, 'student');
        $blocked = $gen->create_and_enrol($course, 'student');
        $deleted = $gen->create_and_enrol($course, 'student');
        $sid = $this->schedule();
        $this->roster([$course->id], $sid, $this->make_run($sid, 1));

        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->unenrol_user($instance, $left->id);
        $manual->update_user_enrol($instance, $paused->id, ENROL_USER_SUSPENDED);
        $DB->set_field('user', 'suspended', 1, ['id' => $blocked->id]);
        delete_user($deleted);
        $joined = $gen->create_and_enrol($course, 'student');

        $rows = $this->roster([$course->id], $sid, $this->make_run($sid, 2));

        $this->assertSame([
            $keep->id => ['unchanged', ''],
            $left->id => ['removed', 'not_enrolled'],
            $paused->id => ['removed', 'not_active_learner'],
            $blocked->id => ['removed', 'account_suspended'],
            $deleted->id => ['removed', 'account_deleted'],
            $joined->id => ['new', ''],
        ], $rows);

        // A removed learner is listed once: the next roster no longer carries them.
        $third = $this->roster([$course->id], $sid, $this->make_run($sid, 3));
        $this->assertSame([$keep->id => ['unchanged', ''], $joined->id => ['unchanged', '']], $third);
    }

    public function test_a_retry_sends_the_same_roster_even_if_data_changed(): void {
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $sid = $this->schedule();
        $run = $this->make_run($sid, 1);
        $first = $this->roster([$course->id], $sid, $run);

        $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertSame($first, $this->roster([$course->id], $sid, $run));
        $this->assertSame([(int) $a->id], array_keys($first));
    }

    public function test_a_run_that_was_not_sent_is_not_the_baseline(): void {
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $sid = $this->schedule();
        $this->roster([$course->id], $sid, $this->make_run($sid, 1));
        $b = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->roster([$course->id], $sid, $this->make_run($sid, 2, 'failed'));

        $rows = $this->roster([$course->id], $sid, $this->make_run($sid, 3));

        $this->assertSame([$a->id => ['unchanged', ''], $b->id => ['new', '']], $rows);
    }

    public function test_preview_and_test_send_store_nothing(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $sid = $this->schedule();

        $this->assertCount(1, $this->roster([$course->id], $sid, null));
        $this->assertSame(0, $DB->count_records('local_reportfeed_roster'));
    }

    public function test_only_the_newest_three_runs_are_kept_and_purge_follows_the_log(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $sid = $this->schedule();
        $runs = [];
        foreach (range(1, 5) as $n) {
            $runs[$n] = $this->make_run($sid, $n);
            $this->roster([$course->id], $sid, $runs[$n]);
        }
        $this->assertEqualsCanonicalizing(
            [$runs[3], $runs[4], $runs[5]],
            array_map('intval', $DB->get_fieldset_sql('SELECT DISTINCT runid FROM {local_reportfeed_roster}'))
        );

        $DB->delete_records('local_reportfeed_run', ['id' => $runs[3]]);
        retention::purge(self::NOW);
        $this->assertSame(2, $DB->count_records('local_reportfeed_roster'));

        \local_reportfeed\local\schedules::delete($sid);
        $this->assertSame(0, $DB->count_records('local_reportfeed_roster'));
    }

    public function test_hidden_courses_and_other_scopes(): void {
        $hidden = $this->getDataGenerator()->create_course(['visible' => 0]);
        $a = $this->getDataGenerator()->create_and_enrol($hidden, 'student');
        $sid = $this->schedule();
        $ids = run_manager::course_ids_for((object) ['scope' => 'all', 'scopeids' => '']);
        $this->assertContains((int) $hidden->id, $ids);
        $this->assertSame([$a->id => ['new', '']], $this->roster($ids, $sid, null));
    }

    /**
     * A schedule that sends only the roster: one file per recipient, with the roster columns.
     */
    public function test_roster_only_schedule_sends_one_roster_file(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student', ['idnumber' => 'EMP-1']);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/reportfeed:receivehrfeed', CAP_ALLOW, $role, \context_system::instance()->id);
        $hr = $this->getDataGenerator()->create_user();
        role_assign($role, $hr->id, \context_system::instance()->id);
        $sid = $this->schedule();
        $DB->insert_record('local_reportfeed_recipient', ['scheduleid' => $sid, 'userid' => $hr->id]);
        $DB->set_field('local_reportfeed_schedule', 'timemodified', self::NOW - 10 * DAYSECS, ['id' => $sid]);

        $manager = new run_manager();
        $this->assertSame(1, $manager->dispatch());
        $sink = $this->redirectEmails();
        $manager->execute((int) $DB->get_field('local_reportfeed_run', 'id', ['scheduleid' => $sid]));

        $mails = \local_reportfeed\mail_reader::read($sink);
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('hr_feed | learner_roster |', $mails[0]['subject']);
        $this->assertCount(1, $mails[0]['files']);
        $this->assertStringContainsString(
            'Learners now: 1. New since the last send: 1. Removed since the last send: 0.',
            preg_replace('/\s+/', ' ', $mails[0]['body'])
        );
        $csv = reset($mails[0]['csv']);
        $lines = array_values(array_filter(explode("\r\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertSame('user_id,idnumber,status,change_type,reason,account_created', $lines[0]);
        $this->assertStringStartsWith($a->id . ',EMP-1,active,new,,', $lines[1]);
    }

    public function test_the_baseline_survives_newer_failed_runs_and_a_short_retention(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $sid = $this->schedule();
        $sent = $this->make_run($sid, 1);
        $this->roster([$course->id], $sid, $sent);
        foreach (range(2, 6) as $n) {
            $this->roster([$course->id], $sid, $this->make_run($sid, $n, 'failed'));
        }
        $this->assertTrue($DB->record_exists('local_reportfeed_roster', ['runid' => $sent]), 'prune keeps the baseline');

        // The sent run is old enough to be purged by age, but it is still the baseline of the next send.
        $DB->set_field('local_reportfeed_run', 'timecreated', self::NOW - 400 * DAYSECS, ['id' => $sent]);
        retention::purge(self::NOW);
        $this->assertTrue($DB->record_exists('local_reportfeed_run', ['id' => $sent]));
        $rows = $this->roster([$course->id], $sid, $this->make_run($sid, 7));
        $this->assertSame('unchanged', array_values($rows)[0][0]);
    }
}
