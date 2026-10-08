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
 * Reads learner progress for Contract v1: one row per learner per course, and per learner.
 *
 * Rows hold native values (timestamps as integers, null for an empty cell); {@see csv_writer}
 * does the formatting. Rules: ADR-004, changes C8 to C11.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class data_provider {
    /** @var int Learners loaded at a time within a course, so memory does not grow with the size of the course. */
    public const CHUNK = 2000;

    /** @var \core\clock Time source, so tests can freeze it. */
    private \core\clock $clock;

    /** @var int Learners per chunk. */
    private int $chunksize;

    /**
     * Constructor.
     *
     * @param \core\clock|null $clock defaults to the site clock
     * @param int $chunksize learners loaded at a time within a course (tests use a small number)
     */
    public function __construct(?\core\clock $clock = null, int $chunksize = self::CHUNK) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
        $this->chunksize = max(1, $chunksize);
    }

    /**
     * Learner-per-course rows for the given courses, one course at a time.
     *
     * With no viewer this is the full HR view. With a viewer (a teacher digest recipient) the
     * recipient's permissions decide the content (PRD rule 1): nothing at all without the
     * receiveteacherdigest capability in the course, only the viewer's own groups in a course that
     * uses groups unless the viewer may access all groups, and no grades unless the viewer may view them.
     *
     * @param int[] $courseids
     * @param int|null $viewerid the recipient, or null for the HR feed
     * @return \Generator rows keyed by contract name (see {@see course_rows()})
     */
    public function learner_course(array $courseids, ?int $viewerid = null): \Generator {
        foreach ($courseids as $courseid) {
            if ((int) $courseid !== SITEID) {
                yield from $this->course_rows((int) $courseid, $viewerid);
            }
        }
    }

    /**
     * One row per learner across the given courses (HR feed only, so no viewer).
     *
     * Held in memory: a few numbers per learner, fine into the hundreds of thousands (C12).
     *
     * @param int[] $courseids
     * @return array[] rows ordered by user id
     */
    public function learner_summary(array $courseids): array {
        $users = [];
        $sums = [];
        foreach ($this->learner_course($courseids) as $row) {
            $id = $row['user_id'];
            if (!isset($users[$id])) {
                $users[$id] = [
                    'user_id' => $id, 'idnumber' => $row['idnumber'], 'username' => $row['username'],
                    'email' => $row['email'], 'courses_enrolled' => 0, 'courses_completed' => 0,
                    'avg_completion_percent' => null, 'last_access_site' => $row['last_access_site'],
                    'days_inactive_site' => null, 'first_enrol' => $row['enrol_start'],
                ];
                $sums[$id] = [0.0, 0];
            }
            $users[$id]['courses_enrolled']++;
            $users[$id]['courses_completed'] += $row['completion_status'] === 'completed' ? 1 : 0;
            $users[$id]['first_enrol'] = min($users[$id]['first_enrol'], $row['enrol_start']);
            if ($row['completion_percent'] !== null) {
                $sums[$id][0] += $row['completion_percent'];
                $sums[$id][1]++;
            }
        }
        ksort($users);
        foreach ($users as $id => &$user) {
            $user['avg_completion_percent'] = $sums[$id][1] ? $sums[$id][0] / $sums[$id][1] : null;
            $user['days_inactive_site'] = $this->days_since($user['last_access_site'] ?? $user['first_enrol']);
            unset($user['first_enrol']);
        }
        return array_values($users);
    }

    /**
     * Rows for one course.
     *
     * Keys: user_id, idnumber, username, email, fullname, course_id, course_idnumber, course_shortname,
     * enrol_start, completion_status (completed, in_progress, not_started; null when completion is off),
     * completion_percent, completion_date, grade, grade_percent, last_access_course, days_inactive,
     * last_access_site.
     *
     * @param int $courseid
     * @param int|null $viewerid
     * @return \Generator
     */
    private function course_rows(int $courseid, ?int $viewerid): \Generator {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/querylib.php');
        require_once($CFG->libdir . '/grouplib.php');

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return;
        }
        $context = \context_course::instance($courseid);

        // Who may see what (viewer only; the HR feed sees all).
        $groupids = 0;
        $cangrades = true;
        $showhidden = false;
        if ($viewerid !== null) {
            // Id 0 would mean "the current user" to has_capability(); a recipient is always a real user.
            if ($viewerid <= 0 || !has_capability('local/reportfeed:receiveteacherdigest', $context, $viewerid)) {
                return;
            }
            if (
                groups_get_course_groupmode($course) != NOGROUPS
                && !has_capability('moodle/site:accessallgroups', $context, $viewerid)
            ) {
                $groupids = array_keys(groups_get_all_groups($courseid, $viewerid, 0, 'g.id'));
                if (!$groupids) {
                    return;
                }
            }
            $cangrades = has_capability('moodle/grade:viewall', $context, $viewerid);
            $showhidden = has_capability('moodle/grade:viewhidden', $context, $viewerid);
        }

        // Visible tracked activities: the learner's view (C8). Availability restrictions are not applied.
        $tracked = 0;
        if ($course->enablecompletion) {
            $tracked = $DB->count_records_select(
                'course_modules',
                'course = :c AND completion > 0 AND visible = 1 AND deletioninprogress = 0',
                ['c' => $courseid]
            );
        }

        $names = implode(', ', array_map(fn($f) => "u.$f", \core_user\fields::get_name_fields()));

        $ids = $this->learner_ids($context, $groupids);
        foreach (array_chunk($ids, $this->chunksize) as $chunk) {
            yield from $this->chunk_rows($course, $courseid, $names, $chunk, $tracked, $cangrades, $showhidden);
        }
    }

    /**
     * The learners a report covers in a course, in id order.
     *
     * Tracked learners with an active enrolment only (C10): teachers, suspended enrolments and suspended accounts
     * never appear. Only the ids are held, a chunk of learners is loaded at a time.
     *
     * @param \context_course $context
     * @param int[]|int $groupids limit to members of these groups, 0 for everybody
     * @return int[]
     */
    public function learner_ids(\context_course $context, array|int $groupids = 0): array {
        global $DB;
        [$esql, $params] = get_enrolled_sql($context, 'moodle/course:isincompletionreports', $groupids, true);
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT u.id FROM {user} u JOIN ($esql) je ON je.id = u.id AND u.suspended = 0 ORDER BY u.id",
            $params
        ));
    }

    /**
     * Rows for one chunk of a course's learners (ascending ids).
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param string $names SQL for the user name fields
     * @param int[] $chunk learner ids, ascending
     * @param int $tracked number of visible tracked activities
     * @param bool $cangrades whether grades may be shown
     * @param bool $showhidden whether hidden grades may be shown
     * @return \Generator
     */
    private function chunk_rows(
        \stdClass $course,
        int $courseid,
        string $names,
        array $chunk,
        int $tracked,
        bool $cangrades,
        bool $showhidden
    ): \Generator {
        global $DB;
        $lo = (int) reset($chunk);
        $hi = (int) end($chunk);
        // The ids already passed the enrolled, tracked and not-suspended rules, so the chunk is selected by id.
        [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'uid');
        $sql = "SELECT u.id AS user_id, u.idnumber, u.username, u.email, u.lastaccess AS site_lastaccess, $names,
                       en.enrolstart, ula.timeaccess AS course_lastaccess, cc.timecompleted,
                       COALESCE(dn.done, 0) AS done
                  FROM {user} u
                  JOIN (SELECT ue.userid,
                               MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) AS enrolstart
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE e.courseid = :ecourse AND ue.status = 0 AND e.status = 0
                               AND ue.userid BETWEEN :elo AND :ehi
                      GROUP BY ue.userid) en ON en.userid = u.id
             LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = :ucourse
             LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = :cccourse
             LEFT JOIN (SELECT cmc.userid, COUNT(1) AS done
                          FROM {course_modules_completion} cmc
                          JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                         WHERE cm.course = :dcourse AND cm.completion > 0 AND cm.visible = 1
                               AND cm.deletioninprogress = 0 AND cmc.completionstate <> 0
                               AND cmc.userid BETWEEN :dlo AND :dhi
                      GROUP BY cmc.userid) dn ON dn.userid = u.id
                 WHERE u.id $insql
              ORDER BY u.id";
        $params += [
            'ecourse' => $courseid, 'ucourse' => $courseid, 'cccourse' => $courseid, 'dcourse' => $courseid,
            'elo' => $lo, 'ehi' => $hi, 'dlo' => $lo, 'dhi' => $hi,
        ];

        $rows = [];
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $r) {
            $rows[(int) $r->user_id] = $r;
        }
        $recordset->close();
        if (!$rows) {
            return;
        }

        $item = grade_get_course_grades($courseid, array_keys($rows));
        $range = (float) $item->grademax - (float) $item->grademin;

        foreach ($rows as $userid => $r) {
            $done = (int) $r->done;
            $grade = $gradepercent = null;
            $g = $item->grades[$userid] ?? null;
            // The API returns the raw grade even when hidden, so blank it here (C9, K4).
            if (
                $cangrades && $g && $g->grade !== null && $g->grade !== false
                && ($showhidden || !($g->hidden || $item->hidden))
            ) {
                $grade = (float) $g->grade;
                $gradepercent = $range > 0 ? ($grade - (float) $item->grademin) / $range * 100 : null;
            }
            $status = null;
            if ($course->enablecompletion) {
                $status = $r->timecompleted ? 'completed' : ($done > 0 ? 'in_progress' : 'not_started');
            }
            $lastaccess = $r->course_lastaccess ? (int) $r->course_lastaccess : null;
            yield [
                'user_id' => $userid,
                'idnumber' => $r->idnumber,
                'username' => $r->username,
                'email' => $r->email,
                'fullname' => fullname($r),
                'course_id' => $courseid,
                'course_idnumber' => $course->idnumber,
                'course_shortname' => $course->shortname,
                'enrol_start' => (int) $r->enrolstart,
                'completion_status' => $status,
                'completion_percent' => $tracked ? round($done / $tracked * 100, 2) : null,
                'completion_date' => $r->timecompleted ? (int) $r->timecompleted : null,
                'grade' => $grade,
                'grade_percent' => $gradepercent,
                'last_access_course' => $lastaccess,
                'days_inactive' => $this->days_since($lastaccess ?? (int) $r->enrolstart),
                'last_access_site' => $r->site_lastaccess ? (int) $r->site_lastaccess : null,
            ];
        }
    }

    /**
     * Whole days from a timestamp to now, never negative.
     *
     * @param int $timestamp
     * @return int
     */
    private function days_since(int $timestamp): int {
        return max(0, intdiv($this->clock->time() - $timestamp, DAYSECS));
    }
}
