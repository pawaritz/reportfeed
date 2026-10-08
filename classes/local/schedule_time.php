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

namespace local_reportfeed\local;

/**
 * Weekly schedule arithmetic in the site timezone (ADR-002).
 *
 * The send time is wall-clock time in the site timezone, so a daylight saving change moves the
 * UTC moment but never the local hour. Weekday is 0 (Sunday) to 6 (Saturday), like PHP's "w".
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_time {
    /**
     * The most recent scheduled moment at or before now.
     *
     * @param int $weekday 0 (Sunday) to 6
     * @param int $hour 0 to 23
     * @param int $minute 0 to 59
     * @param int $now unix time
     * @return int unix time
     */
    public static function latest_due(int $weekday, int $hour, int $minute, int $now): int {
        $local = self::local($now);
        $back = ((int) $local->format('w') - $weekday + 7) % 7;
        $due = $local->setTime($hour, $minute)->modify("-$back days");
        if ($due > $local) {
            $due = $due->modify('-7 days');
        }
        return $due->getTimestamp();
    }

    /**
     * The first scheduled moment after now.
     *
     * @param int $weekday 0 (Sunday) to 6
     * @param int $hour 0 to 23
     * @param int $minute 0 to 59
     * @param int $now unix time
     * @return int unix time
     */
    public static function next_due(int $weekday, int $hour, int $minute, int $now): int {
        $latest = self::local(self::latest_due($weekday, $hour, $minute, $now));
        return $latest->modify('+7 days')->getTimestamp();
    }

    /** @var string[] Frequencies a schedule may have. */
    public const FREQUENCIES = ['weekly', 'biweekly', 'semimonthly', 'monthly'];

    /** @var string[] Rules for the monthly frequency. */
    public const MONTHRULES = ['day', 'lastday', 'firstdow', 'lastdow', 'firstworkday', 'lastworkday'];

    /** @var string[] English day names, index 0 = Sunday, for PHP's relative date formats. */
    private const DAYNAMES = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * The most recent scheduled moment at or before now, for any frequency (ADR-002, D20).
     *
     * @param \stdClass $s a schedule row: frequency, weekday, hour, minute, monthrule, monthday, anchor
     * @param int $now unix time
     * @return int unix time
     */
    public static function latest_due_for(\stdClass $s, int $now): int {
        $hour = (int) $s->hour;
        $minute = (int) $s->minute;
        $frequency = $s->frequency ?? 'weekly';
        if ($frequency === 'weekly') {
            return self::latest_due((int) $s->weekday, $hour, $minute, $now);
        }
        if ($frequency === 'biweekly') {
            $due = self::latest_due((int) $s->weekday, $hour, $minute, $now);
            while (self::week_index($due, (int) ($s->anchor ?? 0)) % 2 !== 0) {
                $due = self::latest_due((int) $s->weekday, $hour, $minute, $due - 1);
            }
            return $due;
        }
        $local = self::local($now);
        $best = 0;
        // This month and the two before: enough because a month's moment is never more than a month apart.
        for ($back = 0; $back <= 2; $back++) {
            $first = $local->modify('first day of this month')->setTime(0, 0)->modify("-$back months");
            foreach (self::moments($s, $first) as $moment) {
                if ($moment <= $now && $moment > $best) {
                    $best = $moment;
                }
            }
        }
        return $best;
    }

    /**
     * The first scheduled moment after now, for any frequency.
     *
     * @param \stdClass $s a schedule row
     * @param int $now unix time
     * @return int unix time
     */
    public static function next_due_for(\stdClass $s, int $now): int {
        $frequency = $s->frequency ?? 'weekly';
        if ($frequency === 'weekly') {
            return self::next_due((int) $s->weekday, (int) $s->hour, (int) $s->minute, $now);
        }
        if ($frequency === 'biweekly') {
            $next = self::next_due((int) $s->weekday, (int) $s->hour, (int) $s->minute, $now);
            while (self::week_index($next, (int) ($s->anchor ?? 0)) % 2 !== 0) {
                $next = self::next_due((int) $s->weekday, (int) $s->hour, (int) $s->minute, $next);
            }
            return $next;
        }
        $local = self::local($now);
        $best = PHP_INT_MAX;
        for ($ahead = 0; $ahead <= 2; $ahead++) {
            $first = $local->modify('first day of this month')->setTime(0, 0)->modify("+$ahead months");
            foreach (self::moments($s, $first) as $moment) {
                if ($moment > $now && $moment < $best) {
                    $best = $moment;
                }
            }
        }
        return $best;
    }

    /**
     * The moment before a given scheduled moment (the start of the period that ends at it).
     *
     * @param \stdClass $s a schedule row
     * @param int $due a scheduled moment
     * @return int unix time
     */
    public static function previous_due_for(\stdClass $s, int $due): int {
        return self::latest_due_for($s, $due - 1);
    }

    /**
     * The moments a monthly or twice-a-month schedule fires in one month.
     *
     * @param \stdClass $s a schedule row
     * @param \DateTimeImmutable $first local midnight on the first day of the month
     * @return int[] unix times
     */
    private static function moments(\stdClass $s, \DateTimeImmutable $first): array {
        $days = [];
        $last = (int) $first->format('t');
        if (($s->frequency ?? '') === 'semimonthly') {
            $days = [1, 15];
        } else {
            switch ($s->monthrule ?? 'day') {
                case 'lastday':
                    $days = [$last];
                    break;
                case 'firstdow':
                case 'lastdow':
                    $order = $s->monthrule === 'firstdow' ? 'first' : 'last';
                    $name = self::DAYNAMES[(int) $s->weekday];
                    $days = [(int) $first->modify("$order $name of this month")->format('j')];
                    break;
                case 'firstworkday':
                    $day = 1;
                    while ((int) $first->modify('+' . ($day - 1) . ' days')->format('N') > 5) {
                        $day++;
                    }
                    $days = [$day];
                    break;
                case 'lastworkday':
                    $day = $last;
                    while ((int) $first->modify('+' . ($day - 1) . ' days')->format('N') > 5) {
                        $day--;
                    }
                    $days = [$day];
                    break;
                default:
                    // A day the month does not have (29, 30, 31) means its last day.
                    $days = [min(max(1, (int) $s->monthday), $last)];
            }
        }
        $moments = [];
        foreach ($days as $day) {
            $moments[] = $first->modify('+' . ($day - 1) . ' days')
                ->setTime((int) $s->hour, (int) $s->minute)->getTimestamp();
        }
        return $moments;
    }

    /**
     * How many whole weeks a moment lies after the Monday of the anchor week (the "on" weeks are the even ones).
     *
     * @param int $due unix time
     * @param int $anchor a date as YYYYMMDD in any "on" week; 0 means a fixed week in 1970
     * @return int
     */
    private static function week_index(int $due, int $anchor): int {
        $tz = \core_date::get_server_timezone_object();
        $start = $anchor > 0
            ? new \DateTimeImmutable(sprintf('%08d', $anchor), $tz)
            : new \DateTimeImmutable('1970-01-05', $tz);
        $monday = $start->setTime(0, 0)->modify('monday this week');
        $day = self::local($due)->setTime(0, 0);
        $days = (int) $monday->diff($day)->format('%r%a');
        return intdiv($days - ($days % 7 + 7) % 7, 7);
    }

    /**
     * The send date in the site timezone as YYYYMMDD, the "period end" in the run table and the files.
     *
     * @param int $due unix time
     * @return int
     */
    public static function period_end(int $due): int {
        return (int) self::local($due)->format('Ymd');
    }

    /**
     * A unix time as a date and time in the site timezone.
     *
     * @param int $time
     * @return \DateTimeImmutable
     */
    private static function local(int $time): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(\core_date::get_server_timezone_object());
    }
}
