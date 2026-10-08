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
 * Removes finished run-log entries after the retention period. The log holds metadata only; no CSV content
 * is ever kept, so this is about the who-got-what trail, not about files.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class retention {
    /** @var int Shortest period honoured, so a mistyped 0 or 1 cannot empty the log under a running dispatcher. */
    public const MIN_DAYS = 7;

    /** @var int Default period in days. */
    public const DEFAULT_DAYS = 90;

    /** @var string[] Statuses that are still in flight and are never purged. */
    private const OPEN = ['queued', 'running'];

    /**
     * The configured period in days, never below the minimum.
     *
     * @return int
     */
    public static function days(): int {
        $days = get_config('local_reportfeed', 'retentiondays');
        $days = ($days === false || $days === '') ? self::DEFAULT_DAYS : (int) $days;
        return max(self::MIN_DAYS, $days);
    }

    /**
     * Delete finished runs (and their delivery rows) older than the retention period, and digest
     * preferences of courses that no longer exist.
     *
     * @param int|null $now current time, for tests
     * @return int number of runs deleted
     */
    public static function purge(?int $now = null): int {
        global $DB;

        $cutoff = ($now ?? time()) - self::days() * DAYSECS;
        [$insql, $params] = $DB->get_in_or_equal(self::OPEN, SQL_PARAMS_NAMED, 'open', false);
        $params['cutoff'] = $cutoff;
        $old = 'timecreated < :cutoff AND status ' . $insql;

        $DB->execute(
            'DELETE FROM {local_reportfeed_delivery} WHERE runid IN (SELECT id FROM {local_reportfeed_run} WHERE ' . $old . ')',
            $params
        );
        $count = $DB->count_records_select('local_reportfeed_run', $old, $params);
        $DB->delete_records_select('local_reportfeed_run', $old, $params);
        $DB->delete_records_select('local_reportfeed_roster', 'runid NOT IN (SELECT id FROM {local_reportfeed_run})');

        $DB->delete_records_select('local_reportfeed_digestcourse', 'courseid NOT IN (SELECT id FROM {course})');
        return $count;
    }
}
