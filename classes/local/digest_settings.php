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
 * A teacher's digest settings for one course, and the site default when they have not set any (ADR-007).
 *
 * The site mode decides what "no row" means: off (nobody gets a digest), optin (no row means no digest) or
 * optout (no row means the digest is on with the defaults below). A row always wins over the mode, except
 * that mode "off" stops everything.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class digest_settings {
    /** @var string[] Columns on by default. Grades are never on by default. */
    public const DEFAULT_CHOICES = ['completion_status', 'completion_percent', 'last_access_course', 'days_inactive'];

    /**
     * The site mode.
     *
     * @return string off, optin or optout
     */
    public static function mode(): string {
        $mode = get_config('local_reportfeed', 'teacherdigestmode');
        return in_array($mode, ['optin', 'optout'], true) ? $mode : 'off';
    }

    /**
     * What a teacher gets in optout mode before touching anything: Monday 07:00, the default columns.
     *
     * @return \stdClass enabled, weekday, hour, choices (array), timemodified
     */
    public static function defaults(): \stdClass {
        return (object) [
            'enabled' => 1, 'weekday' => 1, 'hour' => 7, 'choices' => self::DEFAULT_CHOICES,
            'nominatedby' => 0, 'timemodified' => 0,
        ];
    }

    /**
     * The settings that apply to one teacher in one course, or null when they get no digest.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null enabled, weekday, hour, choices (array), nominatedby, timemodified
     */
    public static function effective(int $userid, int $courseid): ?\stdClass {
        global $DB;
        $mode = self::mode();
        if ($mode === 'off') {
            return null;
        }
        $row = $DB->get_record('local_reportfeed_digestcourse', ['userid' => $userid, 'courseid' => $courseid]);
        if ($row) {
            $row->choices = array_values(array_intersect(contract::DIGEST_CHOICES, explode(',', (string) $row->choices)));
            return $row->enabled ? $row : null;
        }
        return $mode === 'optout' ? self::defaults() : null;
    }

    /**
     * What the preferences form shows for a teacher: their row, or what they would get by default.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass enabled, weekday, hour, choices (array), nominees (int[])
     */
    public static function load(int $userid, int $courseid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_reportfeed_digestcourse', ['userid' => $userid, 'courseid' => $courseid]);
        if ($row) {
            $data = (object) [
                'enabled' => (int) $row->enabled, 'weekday' => (int) $row->weekday, 'hour' => (int) $row->hour,
                'choices' => array_values(array_intersect(contract::DIGEST_CHOICES, explode(',', (string) $row->choices))),
            ];
        } else {
            $data = self::defaults();
            $data->enabled = self::mode() === 'optout' ? 1 : 0;
        }
        $data->nominees = self::nominees($courseid, $userid);
        return $data;
    }

    /**
     * Store a teacher's own settings (not their nominees).
     *
     * @param int $userid
     * @param int $courseid
     * @param \stdClass $data enabled, weekday, hour, choices (array)
     * @param int $nominatedby the nominating teacher, 0 for a teacher's own choice
     */
    public static function save(int $userid, int $courseid, \stdClass $data, int $nominatedby = 0): void {
        global $DB;
        $now = time();
        $row = (object) [
            'enabled' => empty($data->enabled) ? 0 : 1,
            'weekday' => (int) $data->weekday,
            'hour' => (int) $data->hour,
            'choices' => implode(',', array_intersect(contract::DIGEST_CHOICES, (array) ($data->choices ?? []))),
            'timemodified' => $now,
        ];
        $existing = $DB->get_record('local_reportfeed_digestcourse', ['userid' => $userid, 'courseid' => $courseid]);
        if ($existing) {
            // Editing your own settings makes the row yours, even if someone nominated you first.
            $row->id = $existing->id;
            $row->nominatedby = $nominatedby;
            $DB->update_record('local_reportfeed_digestcourse', $row);
        } else {
            $row->userid = $userid;
            $row->courseid = $courseid;
            $row->nominatedby = $nominatedby;
            $row->timecreated = $now;
            $DB->insert_record('local_reportfeed_digestcourse', $row);
        }
    }

    /**
     * The people one teacher has nominated for a course.
     *
     * @param int $courseid
     * @param int $nominator
     * @return int[]
     */
    public static function nominees(int $courseid, int $nominator): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'local_reportfeed_digestcourse',
            'userid',
            'courseid = ? AND nominatedby = ?',
            [$courseid, $nominator]
        ));
    }

    /**
     * Make a teacher's list of nominees match the given people.
     *
     * A new nominee gets a copy of the nominator's current settings and may change them. Someone who already
     * has settings of their own keeps them. Dropped nominees lose their row. Each nominee still receives only
     * what their own permissions allow, because the data provider checks that at send time.
     *
     * @param int $nominator
     * @param int $courseid
     * @param int[] $userids
     */
    public static function nominate(int $nominator, int $courseid, array $userids): void {
        global $DB;
        $userids = array_diff(array_unique(array_map('intval', $userids)), [$nominator]);
        $mine = self::load($nominator, $courseid);
        $mine->enabled = 1;
        foreach (self::nominees($courseid, $nominator) as $old) {
            if (!in_array($old, $userids, true)) {
                $DB->delete_records('local_reportfeed_digestcourse', [
                    'userid' => $old, 'courseid' => $courseid, 'nominatedby' => $nominator,
                ]);
            }
        }
        foreach ($userids as $userid) {
            if (!$DB->record_exists('local_reportfeed_digestcourse', ['userid' => $userid, 'courseid' => $courseid])) {
                self::save($userid, $courseid, $mine, $nominator);
            }
        }
    }

    /**
     * What is wrong with submitted settings, by field.
     *
     * @param \stdClass|array $data
     * @param int $courseid
     * @param int $userid the teacher saving
     * @return string[]
     */
    public static function errors($data, int $courseid, int $userid): array {
        global $DB;
        $data = (object) $data;
        $errors = [];
        if (!isset($data->weekday) || $data->weekday < 0 || $data->weekday > 6) {
            $errors['when'] = get_string('required');
        }
        if (!isset($data->hour) || $data->hour < 0 || $data->hour > 23) {
            $errors['when'] = get_string('required');
        }
        $context = \context_course::instance($courseid);
        $gradecolumns = array_intersect((array) ($data->choices ?? []), contract::GRADE_COLUMNS);
        if ($gradecolumns && !has_capability('moodle/grade:viewall', $context, $userid)) {
            $errors['choices'] = get_string('error_gradecolumns', 'local_reportfeed');
        }
        $bad = [];
        foreach ((array) ($data->nominees ?? []) as $nominee) {
            $user = $DB->get_record('user', ['id' => (int) $nominee, 'deleted' => 0]);
            if (
                !$user || (int) $nominee === $userid
                || !has_capability('local/reportfeed:receiveteacherdigest', $context, $user->id)
                || !is_enrolled($context, $user->id, 'local/reportfeed:receiveteacherdigest', true)
            ) {
                $bad[] = $user ? fullname($user) : '#' . (int) $nominee;
            }
        }
        if ($bad) {
            $errors['nominees'] = get_string('error_nomineecap', 'local_reportfeed', implode(', ', $bad));
        }
        return $errors;
    }
}
