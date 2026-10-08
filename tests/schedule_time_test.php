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

use local_reportfeed\local\schedule_time;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Weekly schedule arithmetic in the site timezone, including daylight saving changes.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(schedule_time::class)]
final class schedule_time_test extends \advanced_testcase {
    /**
     * A UTC date and time as unix time.
     *
     * @param string $text for example 2026-03-30 07:00
     * @return int
     */
    private function utc(string $text): int {
        return (new \DateTimeImmutable($text, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Monday 08:00 London stays 08:00 London across the March change to summer time.
     */
    public function test_local_hour_survives_spring_forward(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/London', 'Europe/London');

        // Before the change (GMT): 08:00 UTC.
        $this->assertSame($this->utc('2026-03-23 08:00'), schedule_time::latest_due(1, 8, 0, $this->utc('2026-03-23 12:00')));
        // After the change (BST): 07:00 UTC.
        $this->assertSame($this->utc('2026-03-30 07:00'), schedule_time::latest_due(1, 8, 0, $this->utc('2026-03-30 12:00')));
        // The next send after the change is 167 hours later, not 168.
        $next = schedule_time::next_due(1, 8, 0, $this->utc('2026-03-23 12:00'));
        $this->assertSame($this->utc('2026-03-30 07:00'), $next);
        $this->assertSame(167 * HOURSECS, $next - $this->utc('2026-03-23 08:00'));
    }

    /**
     * And back again in October: 169 hours.
     */
    public function test_local_hour_survives_fall_back(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/London', 'Europe/London');
        $next = schedule_time::next_due(1, 8, 0, $this->utc('2026-10-19 12:00'));
        $this->assertSame($this->utc('2026-10-26 08:00'), $next);
        $this->assertSame(169 * HOURSECS, $next - $this->utc('2026-10-19 07:00'));
    }

    /**
     * Before this week's moment, the latest due moment is last week's.
     */
    public function test_before_the_moment_means_last_week(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/London', 'Europe/London');
        // Monday 06:00 UTC is 07:00 BST, an hour before 08:00 BST.
        $this->assertSame($this->utc('2026-03-23 08:00'), schedule_time::latest_due(1, 8, 0, $this->utc('2026-03-30 06:00')));
        // Exactly at the moment counts as due.
        $this->assertSame($this->utc('2026-03-30 07:00'), schedule_time::latest_due(1, 8, 0, $this->utc('2026-03-30 07:00')));
        // Sunday is weekday 0.
        $this->assertSame($this->utc('2026-03-29 07:00'), schedule_time::latest_due(0, 8, 0, $this->utc('2026-03-30 12:00')));
    }

    /**
     * The period end is the local date: Monday 00:30 in Bangkok is still Sunday in UTC.
     */
    public function test_period_end_is_the_site_date(): void {
        $this->resetAfterTest();
        $this->setTimezone('Asia/Bangkok', 'Asia/Bangkok');
        $now = $this->utc('2026-10-04 19:00');
        $due = schedule_time::latest_due(1, 0, 30, $now);
        $this->assertSame($this->utc('2026-10-04 17:30'), $due);
        $this->assertSame(20261005, schedule_time::period_end($due));
    }

    /**
     * A schedule row with the defaults of the form.
     *
     * @param array $override fields to change
     * @return \stdClass
     */
    private function schedule(array $override = []): \stdClass {
        return (object) ($override + [
            'frequency' => 'weekly', 'weekday' => 1, 'hour' => 7, 'minute' => 0,
            'monthrule' => 'day', 'monthday' => 1, 'anchor' => 0,
        ]);
    }

    /**
     * Every 2 weeks counts weeks from the anchor week, also across a year end and before the anchor.
     */
    public function test_biweekly_counts_from_the_anchor_week(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        // Anchor: Wednesday 2026-12-23, so Monday 2026-12-21 starts an "on" week.
        $s = $this->schedule(['frequency' => 'biweekly', 'weekday' => 1, 'anchor' => 20261223]);
        $this->assertSame($this->utc('2026-12-21 07:00'), schedule_time::latest_due_for($s, $this->utc('2026-12-22 12:00')));
        // The week after is off, so a Monday in it still reports the previous "on" Monday.
        $this->assertSame($this->utc('2026-12-21 07:00'), schedule_time::latest_due_for($s, $this->utc('2026-12-30 12:00')));
        // Across the year end: 2027-01-04 is the next "on" Monday.
        $this->assertSame($this->utc('2027-01-04 07:00'), schedule_time::latest_due_for($s, $this->utc('2027-01-05 12:00')));
        $this->assertSame($this->utc('2027-01-04 07:00'), schedule_time::next_due_for($s, $this->utc('2026-12-21 07:00')));
        // Before the anchor week the pattern continues backwards.
        $this->assertSame($this->utc('2026-12-07 07:00'), schedule_time::latest_due_for($s, $this->utc('2026-12-15 12:00')));
        // A weekday that comes before the anchor date inside the same week still belongs to the anchor week.
        $friday = $this->schedule(['frequency' => 'biweekly', 'weekday' => 5, 'anchor' => 20261223]);
        $this->assertSame($this->utc('2026-12-25 07:00'), schedule_time::latest_due_for($friday, $this->utc('2026-12-26 12:00')));
    }

    /**
     * The 1st and the 15th, with the 15th and the following 1st both found as the next moment.
     */
    public function test_semimonthly_is_the_first_and_the_fifteenth(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        $s = $this->schedule(['frequency' => 'semimonthly']);
        $this->assertSame($this->utc('2026-10-01 07:00'), schedule_time::latest_due_for($s, $this->utc('2026-10-14 23:00')));
        $this->assertSame($this->utc('2026-10-15 07:00'), schedule_time::latest_due_for($s, $this->utc('2026-10-15 07:00')));
        $this->assertSame($this->utc('2026-10-15 07:00'), schedule_time::latest_due_for($s, $this->utc('2026-10-31 23:00')));
        $this->assertSame($this->utc('2026-11-01 07:00'), schedule_time::next_due_for($s, $this->utc('2026-10-15 07:00')));
        // Before the 1st of a month: the 15th of the month before.
        $this->assertSame($this->utc('2026-12-15 07:00'), schedule_time::latest_due_for($s, $this->utc('2027-01-01 06:00')));
    }

    /**
     * Monthly on a chosen day, with days the month does not have meaning its last day.
     */
    public function test_monthly_day_clamps_to_the_last_day(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        $s = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'day', 'monthday' => 31]);
        $this->assertSame($this->utc('2027-02-28 07:00'), schedule_time::latest_due_for($s, $this->utc('2027-03-10 12:00')));
        $this->assertSame($this->utc('2028-02-29 07:00'), schedule_time::latest_due_for($s, $this->utc('2028-03-10 12:00')));
        $this->assertSame($this->utc('2026-04-30 07:00'), schedule_time::latest_due_for($s, $this->utc('2026-05-10 12:00')));
        $this->assertSame($this->utc('2026-05-31 07:00'), schedule_time::next_due_for($s, $this->utc('2026-04-30 07:00')));
        $this->assertSame($this->utc('2027-02-28 07:00'), schedule_time::next_due_for($s, $this->utc('2027-01-31 07:00')));
        $five = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'day', 'monthday' => 5]);
        $this->assertSame($this->utc('2026-10-05 07:00'), schedule_time::latest_due_for($five, $this->utc('2026-10-06 12:00')));
        $this->assertSame($this->utc('2026-09-05 07:00'), schedule_time::latest_due_for($five, $this->utc('2026-10-05 06:59')));
    }

    /**
     * The last day, the first or last chosen weekday, and the first or last working day.
     */
    public function test_monthly_rules(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        $last = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'lastday']);
        $this->assertSame($this->utc('2026-09-30 07:00'), schedule_time::latest_due_for($last, $this->utc('2026-10-20 12:00')));

        // 2026-10-01 is a Thursday: the first Monday is the 5th, the last Friday is the 30th.
        $firstmon = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'firstdow', 'weekday' => 1]);
        $this->assertSame($this->utc('2026-10-05 07:00'), schedule_time::latest_due_for($firstmon, $this->utc('2026-10-20 12:00')));
        $lastfri = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'lastdow', 'weekday' => 5]);
        $this->assertSame($this->utc('2026-10-30 07:00'), schedule_time::latest_due_for($lastfri, $this->utc('2026-10-31 12:00')));
        // The first Thursday is the 1st itself.
        $firstthu = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'firstdow', 'weekday' => 4]);
        $this->assertSame($this->utc('2026-10-01 07:00'), schedule_time::latest_due_for($firstthu, $this->utc('2026-10-01 07:00')));

        // March 2026 starts on a Sunday and ends on a Tuesday; November 2026 starts on a Sunday, ends on a Monday.
        $firstwork = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'firstworkday']);
        $march = schedule_time::latest_due_for($firstwork, $this->utc('2026-03-20 12:00'));
        $this->assertSame($this->utc('2026-03-02 07:00'), $march);
        $november = schedule_time::latest_due_for($firstwork, $this->utc('2026-11-20 12:00'));
        $this->assertSame($this->utc('2026-11-02 07:00'), $november);
        $lastwork = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'lastworkday']);
        // 2026-05-31 is a Sunday, so the last working day is Friday the 29th.
        $this->assertSame($this->utc('2026-05-29 07:00'), schedule_time::latest_due_for($lastwork, $this->utc('2026-06-10 12:00')));
        $this->assertSame($this->utc('2026-11-30 07:00'), schedule_time::latest_due_for($lastwork, $this->utc('2026-12-10 12:00')));
        // Next moment looks into the following month.
        $this->assertSame($this->utc('2026-06-30 07:00'), schedule_time::next_due_for($lastwork, $this->utc('2026-05-29 07:00')));
    }

    /**
     * The local hour survives daylight saving for monthly schedules too, and the previous moment is the period start.
     */
    public function test_monthly_local_hour_and_previous_moment(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/London', 'Europe/London');
        $s = $this->schedule(['frequency' => 'monthly', 'monthrule' => 'day', 'monthday' => 1, 'hour' => 8]);
        // 1 April is in summer time (07:00 UTC), 1 March is not (08:00 UTC).
        $april = schedule_time::latest_due_for($s, $this->utc('2026-04-10 12:00'));
        $this->assertSame($this->utc('2026-04-01 07:00'), $april);
        $this->assertSame($this->utc('2026-03-01 08:00'), schedule_time::previous_due_for($s, $april));
        $this->assertSame(20260401, schedule_time::period_end($april));
    }

    /**
     * An old weekly row (no new fields) still works, and the weekly result equals the original method.
     */
    public function test_weekly_rows_without_new_fields_still_work(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        $old = (object) ['weekday' => 1, 'hour' => 8, 'minute' => 30];
        $now = $this->utc('2026-10-07 10:00');
        $this->assertSame(schedule_time::latest_due(1, 8, 30, $now), schedule_time::latest_due_for($old, $now));
        $this->assertSame(schedule_time::next_due(1, 8, 30, $now), schedule_time::next_due_for($old, $now));
    }
}
