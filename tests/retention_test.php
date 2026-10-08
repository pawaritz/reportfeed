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

use local_reportfeed\local\retention;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Run-log retention.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(retention::class)]
final class retention_test extends \advanced_testcase {
    /** @var int Fixed "now" for the tests. */
    private const NOW = 1800000000;

    /**
     * Insert a run with one delivery row.
     *
     * @param string $status run status
     * @param int $ageindays age of the run
     * @return int run id
     */
    private function run_row(string $status, int $ageindays): int {
        global $DB;
        static $n = 0;
        $n++;
        $runid = $DB->insert_record('local_reportfeed_run', (object) [
            'type' => 'hrfeed', 'scheduleid' => 1, 'userid' => 0, 'courseid' => 0, 'manual' => 0,
            'periodend' => 20300000 + $n, 'timedue' => self::NOW, 'status' => $status, 'attempts' => 1,
            'timecreated' => self::NOW - $ageindays * DAYSECS, 'timestarted' => 0, 'timefinished' => 0,
        ]);
        $DB->insert_record('local_reportfeed_delivery', (object) [
            'runid' => $runid, 'userid' => 2, 'filekey' => 'learner_course', 'status' => 'sent', 'timemodified' => 0,
        ]);
        return $runid;
    }

    /**
     * Old finished runs go with their deliveries; recent and in-flight ones stay.
     */
    public function test_only_old_finished_runs_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $oldsent = $this->run_row('sent', 100);
        $oldfailed = $this->run_row('failed', 100);
        $oldqueued = $this->run_row('queued', 100);
        $oldrunning = $this->run_row('running', 100);
        $recent = $this->run_row('sent', 10);

        $this->assertSame(2, retention::purge(self::NOW));

        $this->assertFalse($DB->record_exists('local_reportfeed_run', ['id' => $oldsent]));
        $this->assertFalse($DB->record_exists('local_reportfeed_run', ['id' => $oldfailed]));
        $this->assertFalse($DB->record_exists('local_reportfeed_delivery', ['runid' => $oldsent]));
        foreach ([$oldqueued, $oldrunning, $recent] as $kept) {
            $this->assertTrue($DB->record_exists('local_reportfeed_run', ['id' => $kept]));
            $this->assertTrue($DB->record_exists('local_reportfeed_delivery', ['runid' => $kept]));
        }
    }

    /**
     * A tiny or empty setting cannot wipe the log: seven days is the floor.
     */
    public function test_the_period_has_a_floor(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 0, 'local_reportfeed');
        $this->assertSame(retention::MIN_DAYS, retention::days());
        set_config('retentiondays', 30, 'local_reportfeed');
        $this->assertSame(30, retention::days());
        unset_config('retentiondays', 'local_reportfeed');
        $this->assertSame(retention::DEFAULT_DAYS, retention::days());

        set_config('retentiondays', 1, 'local_reportfeed');
        $threedays = $this->run_row('sent', 3);
        $this->assertSame(0, retention::purge(self::NOW));
        $this->assertTrue($DB->record_exists('local_reportfeed_run', ['id' => $threedays]));
    }

    /**
     * Digest preferences of a deleted course are removed; others stay.
     */
    public function test_preferences_of_deleted_courses_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $kept = $this->getDataGenerator()->create_course();
        $row = (object) ['userid' => $user->id, 'courseid' => $kept->id, 'enabled' => 1, 'weekday' => 1, 'hour' => 8,
            'choices' => '', 'nominatedby' => 0, 'timecreated' => 0, 'timemodified' => 0];
        $DB->insert_record('local_reportfeed_digestcourse', $row);
        $row->courseid = $kept->id + 1000;
        $DB->insert_record('local_reportfeed_digestcourse', $row);

        retention::purge(self::NOW);

        $this->assertSame(1, $DB->count_records('local_reportfeed_digestcourse'));
        $this->assertTrue($DB->record_exists('local_reportfeed_digestcourse', ['courseid' => $kept->id]));
    }
}
