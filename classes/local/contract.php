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
 * Contract v1: which columns each file has, in which order, and how each is formatted.
 *
 * The single source of truth for docs/CONTRACT-v1.md. Columns are only ever added at the end;
 * a rename or removal is schema v2 (ADR-006).
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class contract {
    /** @var int Schema version, also the _v1 file name suffix. */
    public const SCHEMA = 1;

    /** @var string[] Identity columns a schedule may select, in their fixed output order. */
    public const IDENTITY = ['idnumber', 'username', 'email'];

    /** @var string[] Teacher digest columns a recipient may choose, in their fixed output order. */
    public const DIGEST_CHOICES = [
        'completion_status', 'completion_percent', 'last_access_course', 'days_inactive', 'grade', 'grade_percent',
    ];

    /** @var string[] Digest columns that reveal grades (only for a recipient who may view grades). */
    public const GRADE_COLUMNS = ['grade', 'grade_percent'];

    /** @var string[] Columns after the identity columns in learner_course. */
    private const LEARNER_COURSE = [
        'course_id', 'course_idnumber', 'course_shortname', 'enrol_start', 'completion_status',
        'completion_percent', 'completion_date', 'grade', 'grade_percent', 'last_access_course', 'days_inactive',
    ];

    /** @var string[] Columns after the identity columns in learner_summary. */
    private const LEARNER_SUMMARY = [
        'courses_enrolled', 'courses_completed', 'avg_completion_percent', 'last_access_site', 'days_inactive_site',
    ];

    /** @var string[] The HR files a schedule may include, in their fixed order. */
    public const HR_FILES = ['learner_course', 'learner_summary', 'learner_roster'];

    /** @var string[] HR files a new schedule includes. The roster is opt-in. */
    public const HR_DEFAULT = ['learner_course', 'learner_summary'];

    /** @var string[] Columns after the identity columns in learner_roster. */
    private const LEARNER_ROSTER = ['status', 'change', 'reason', 'account_created'];

    /** @var string[] The optional activity-level files, in their fixed order. */
    public const ACTIVITY_FILES = ['activity_completion', 'activity_assignments', 'activity_quizzes', 'activity_engagement'];

    /**
     * Activity files: the columns that are always there after the identity columns, and the ones an administrator
     * may switch on (listed in their fixed output order).
     */
    private const ACTIVITY = [
        'activity_completion' => [
            ['course_id', 'course_shortname', 'cm_id', 'activity_name'],
            ['activity_type', 'section', 'activity_status', 'completion_date'],
        ],
        'activity_assignments' => [
            ['course_id', 'course_shortname', 'cm_id', 'activity_name'],
            ['due_date', 'submission_status', 'submitted_date', 'overdue', 'days_late', 'grade', 'grade_percent'],
        ],
        'activity_quizzes' => [
            ['course_id', 'course_shortname', 'cm_id', 'activity_name'],
            ['attempts', 'finished_attempts', 'last_attempt_date', 'quiz_status', 'grade', 'grade_percent'],
        ],
        'activity_engagement' => [
            ['course_id', 'course_shortname', 'window_start', 'window_end'],
            ['course_views', 'activity_views', 'active_days', 'site_logins'],
        ],
    ];

    /** @var array<string,string> How each column is formatted: int, text, time, percent or number. */
    private const TYPES = [
        'user_id' => 'int', 'idnumber' => 'text', 'username' => 'text', 'email' => 'text', 'fullname' => 'text',
        'course_id' => 'int', 'course_idnumber' => 'text', 'course_shortname' => 'text',
        'enrol_start' => 'time', 'completion_status' => 'text', 'completion_percent' => 'percent',
        'completion_date' => 'time', 'grade' => 'number', 'grade_percent' => 'percent',
        'last_access_course' => 'time', 'days_inactive' => 'int',
        'courses_enrolled' => 'int', 'courses_completed' => 'int', 'avg_completion_percent' => 'percent',
        'last_access_site' => 'time', 'days_inactive_site' => 'int',
        'status' => 'text', 'change' => 'text', 'reason' => 'text', 'account_created' => 'time',
        'cm_id' => 'int', 'activity_name' => 'text', 'activity_type' => 'text', 'section' => 'int',
        'activity_status' => 'text', 'due_date' => 'time', 'submission_status' => 'text', 'submitted_date' => 'time',
        'overdue' => 'text', 'days_late' => 'int', 'attempts' => 'int', 'finished_attempts' => 'int',
        'last_attempt_date' => 'time', 'quiz_status' => 'text', 'window_start' => 'time', 'window_end' => 'time',
        'course_views' => 'int', 'activity_views' => 'int', 'active_days' => 'int', 'site_logins' => 'int',
    ];

    /**
     * The ordered header of one file.
     *
     * Unknown identity or choice names are ignored, so user-supplied settings can never add a column.
     *
     * @param string $file an HR file, an activity file or teacher_digest
     * @param string[] $identity identity columns the schedule selected (HR files)
     * @param string[] $choices columns the recipient (teacher digest) or the administrator (activity file) chose
     * @param bool $grades whether grade columns may appear (teacher digest)
     * @return string[]
     */
    public static function columns(string $file, array $identity = [], array $choices = [], bool $grades = true): array {
        $identity = array_values(array_intersect(self::IDENTITY, $identity));
        switch ($file) {
            case 'learner_course':
                return array_merge(['user_id'], $identity, self::LEARNER_COURSE);
            case 'learner_summary':
                return array_merge(['user_id'], $identity, self::LEARNER_SUMMARY);
            case 'learner_roster':
                return array_merge(['user_id'], $identity, self::LEARNER_ROSTER);
            case 'teacher_digest':
                $chosen = array_values(array_intersect(self::DIGEST_CHOICES, $choices));
                if (!$grades) {
                    $chosen = array_values(array_diff($chosen, self::GRADE_COLUMNS));
                }
                return array_merge(['course_id', 'course_shortname', 'user_id', 'fullname'], $chosen);
        }
        if (isset(self::ACTIVITY[$file])) {
            [$fixed, $optional] = self::ACTIVITY[$file];
            return array_merge(['user_id'], $identity, $fixed, array_values(array_intersect($optional, $choices)));
        }
        throw new \coding_exception('Unknown Reportfeed file: ' . $file);
    }

    /**
     * The columns of an activity file that an administrator may switch on, in output order.
     *
     * @param string $file one of {@see ACTIVITY_FILES}
     * @return string[]
     */
    public static function activity_fields(string $file): array {
        return self::ACTIVITY[$file][1] ?? throw new \coding_exception('Unknown Reportfeed activity file: ' . $file);
    }

    /**
     * How a column is formatted.
     *
     * @param string $column
     * @return string int, text, time, percent or number
     */
    public static function type(string $column): string {
        return self::TYPES[$column] ?? throw new \coding_exception('Unknown Reportfeed column: ' . $column);
    }
}
