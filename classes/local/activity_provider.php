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
 * Rows for the optional activity-level files (HR view: every tracked learner of each course, see ADR-005).
 *
 * Each method walks the courses one at a time and the learners a slice at a time, so memory does not grow
 * with the size of a course. Learners are the same ones the learner_course file covers.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_provider {
    /** @var int Learners handled together. Smaller than a learner_course chunk because each learner has many rows. */
    public const SLICE = 500;

    /** @var \core\clock Time source, so tests can freeze it. */
    private \core\clock $clock;

    /** @var int Learners per slice. */
    private int $slice;

    /**
     * Constructor.
     *
     * @param \core\clock|null $clock defaults to the site clock
     * @param int $slice learners handled together (tests use a small number)
     */
    public function __construct(?\core\clock $clock = null, int $slice = self::SLICE) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
        $this->slice = max(1, $slice);
    }

    /**
     * Whether the standard log, the only source of engagement counts, is switched on.
     *
     * @return bool
     */
    public static function log_available(): bool {
        return isset(get_log_manager()->get_readers('\core\log\sql_reader')['logstore_standard']);
    }

    /**
     * One row per learner and tracked activity.
     *
     * Tracked means visible with completion tracking on, as for the course completion percent. A learner without a
     * completion record is "incomplete".
     *
     * @param int[] $courseids
     * @param array $options one group of {@see activity_options::parse()}: filter, modtypes
     * @return \Generator
     */
    public function completion(array $courseids, array $options): \Generator {
        global $DB;
        foreach ($this->courses($courseids) as [$course, $slices]) {
            if (!$course->enablecompletion) {
                continue;
            }
            $cms = $this->modules($course, $options['modtypes'] ?? [], true);
            if (!$cms) {
                continue;
            }
            [$in, $inparams] = $DB->get_in_or_equal(array_keys($cms), SQL_PARAMS_NAMED, 'cm');
            foreach ($slices as $ids) {
                $states = [];
                $rs = $DB->get_recordset_sql(
                    "SELECT userid, coursemoduleid, completionstate, timemodified
                       FROM {course_modules_completion}
                      WHERE coursemoduleid $in AND userid BETWEEN :lo AND :hi",
                    $inparams + ['lo' => reset($ids), 'hi' => end($ids)]
                );
                foreach ($rs as $r) {
                    $states[$r->userid][$r->coursemoduleid] = $r;
                }
                $rs->close();
                $who = $this->identity($ids);
                foreach ($ids as $userid) {
                    foreach ($cms as $cm) {
                        $r = $states[$userid][$cm->id] ?? null;
                        $state = $r ? (int) $r->completionstate : 0;
                        if (($options['filter'] ?? 'all') === 'open' && $state !== 0) {
                            continue;
                        }
                        yield $who[$userid] + $this->base($userid, $course, $cm) + [
                            'activity_type' => $cm->modname,
                            'section' => $cm->sectionnum,
                            'activity_status' => ['incomplete', 'complete', 'complete_pass', 'complete_fail'][$state] ?? 'complete',
                            'completion_date' => $state !== 0 ? (int) $r->timemodified : null,
                        ];
                    }
                }
            }
        }
    }

    /**
     * One row per learner and assignment.
     *
     * The due date is the assignment's own, moved to the learner's extension when they have one. Group and user
     * overrides are not applied, and team submissions are not followed (the learner shows as not submitted).
     * The grade is the gradebook's final grade when the assignment is graded with points.
     *
     * @param int[] $courseids
     * @param array $options filter: all, open (not submitted) or overdue
     * @return \Generator
     */
    public function assignments(array $courseids, array $options): \Generator {
        global $DB;
        $now = $this->clock->time();
        foreach ($this->courses($courseids) as [$course, $slices]) {
            $cms = $this->modules($course, ['assign'], false);
            if (!$cms) {
                continue;
            }
            $instances = array_map(fn($cm) => (int) $cm->instance, $cms);
            $assigns = $DB->get_records_list('assign', 'id', $instances, '', 'id, duedate');
            $items = $this->grade_items($course->id, 'assign');
            [$in, $inparams] = $DB->get_in_or_equal($instances, SQL_PARAMS_NAMED, 'as');
            foreach ($slices as $ids) {
                $range = ['lo' => reset($ids), 'hi' => end($ids)];
                $subs = [];
                $rs = $DB->get_recordset_sql(
                    "SELECT assignment, userid, status, timemodified FROM {assign_submission}
                      WHERE assignment $in AND latest = 1 AND userid BETWEEN :lo AND :hi",
                    $inparams + $range
                );
                foreach ($rs as $r) {
                    $subs[$r->userid][$r->assignment] = $r;
                }
                $rs->close();
                $extensions = [];
                $rs = $DB->get_recordset_sql(
                    "SELECT assignment, userid, extensionduedate FROM {assign_user_flags}
                      WHERE assignment $in AND extensionduedate > 0 AND userid BETWEEN :lo AND :hi",
                    $inparams + $range
                );
                foreach ($rs as $r) {
                    $extensions[$r->userid][$r->assignment] = (int) $r->extensionduedate;
                }
                $rs->close();
                $grades = $this->grades($items, $range);
                $who = $this->identity($ids);
                foreach ($ids as $userid) {
                    foreach ($cms as $cm) {
                        $sub = $subs[$userid][$cm->instance] ?? null;
                        $status = $sub ? ($sub->status === 'new' ? 'not_submitted' : (string) $sub->status) : 'not_submitted';
                        $submitted = $status === 'submitted';
                        $due = (int) ($assigns[$cm->instance]->duedate ?? 0);
                        $due = $due > 0 ? max($due, $extensions[$userid][$cm->instance] ?? 0) : 0;
                        $overdue = !$submitted && $due > 0 && $now > $due;
                        $filter = $options['filter'] ?? 'all';
                        if (($filter === 'open' && $submitted) || ($filter === 'overdue' && !$overdue)) {
                            continue;
                        }
                        $item = $items[$cm->instance] ?? null;
                        [$grade, $percent] = $this->grade_cells($item, $grades[$item->id ?? 0][$userid] ?? null);
                        yield $who[$userid] + $this->base($userid, $course, $cm) + [
                            'due_date' => $due ?: null,
                            'submission_status' => $status,
                            'submitted_date' => $submitted ? (int) $sub->timemodified : null,
                            'overdue' => $overdue ? 'yes' : 'no',
                            'days_late' => $submitted && $due > 0
                                ? max(0, (int) ceil(((int) $sub->timemodified - $due) / DAYSECS)) : null,
                            'grade' => $grade,
                            'grade_percent' => $percent,
                        ];
                    }
                }
            }
        }
    }

    /**
     * One row per learner and quiz.
     *
     * Preview attempts do not count. The grade is the gradebook's final grade for the quiz, so it follows the quiz's
     * own grading method (highest, average, first or last attempt).
     *
     * @param int[] $courseids
     * @param array $options filter: all or open (not finished)
     * @return \Generator
     */
    public function quizzes(array $courseids, array $options): \Generator {
        global $DB;
        foreach ($this->courses($courseids) as [$course, $slices]) {
            $cms = $this->modules($course, ['quiz'], false);
            if (!$cms) {
                continue;
            }
            $instances = array_map(fn($cm) => (int) $cm->instance, $cms);
            $items = $this->grade_items($course->id, 'quiz');
            [$in, $inparams] = $DB->get_in_or_equal($instances, SQL_PARAMS_NAMED, 'qz');
            foreach ($slices as $ids) {
                $range = ['lo' => reset($ids), 'hi' => end($ids)];
                $attempts = [];
                $rs = $DB->get_recordset_sql(
                    "SELECT quiz, userid, COUNT(1) AS attempts,
                            SUM(CASE WHEN state = 'finished' THEN 1 ELSE 0 END) AS finished,
                            SUM(CASE WHEN state IN ('inprogress', 'overdue') THEN 1 ELSE 0 END) AS running,
                            MAX(timestart) AS lastattempt
                       FROM {quiz_attempts}
                      WHERE quiz $in AND preview = 0 AND userid BETWEEN :lo AND :hi
                   GROUP BY quiz, userid",
                    $inparams + $range
                );
                foreach ($rs as $r) {
                    $attempts[$r->userid][$r->quiz] = $r;
                }
                $rs->close();
                $grades = $this->grades($items, $range);
                $who = $this->identity($ids);
                foreach ($ids as $userid) {
                    foreach ($cms as $cm) {
                        $a = $attempts[$userid][$cm->instance] ?? null;
                        $status = 'not_attempted';
                        if ($a && $a->running > 0) {
                            $status = 'in_progress';
                        } else if ($a && $a->finished > 0) {
                            $status = 'finished';
                        }
                        if (($options['filter'] ?? 'all') === 'open' && $status === 'finished') {
                            continue;
                        }
                        $item = $items[$cm->instance] ?? null;
                        [$grade, $percent] = $this->grade_cells($item, $grades[$item->id ?? 0][$userid] ?? null);
                        yield $who[$userid] + $this->base($userid, $course, $cm) + [
                            'attempts' => $a ? (int) $a->attempts : 0,
                            'finished_attempts' => $a ? (int) $a->finished : 0,
                            'last_attempt_date' => $a ? (int) $a->lastattempt : null,
                            'quiz_status' => $status,
                            'grade' => $grade,
                            'grade_percent' => $percent,
                        ];
                    }
                }
            }
        }
    }

    /**
     * One row per learner and course with counts of what they did between two moments.
     *
     * Counts come from the standard log: views of the course page, views of activities, days (in site time) with at
     * least one logged action in the course, and logins to the site (the same figure on every course row).
     *
     * @param int[] $courseids
     * @param array $options fields: the optional columns switched on
     * @param int $start window start, inclusive
     * @param int $end window end, exclusive
     * @return \Generator
     */
    public function engagement(array $courseids, array $options, int $start, int $end): \Generator {
        global $DB;
        $fields = $options['fields'] ?? [];
        $offset = (new \DateTimeZone(\core_date::get_server_timezone()))->getOffset(new \DateTimeImmutable('@' . $end));
        foreach ($this->courses($courseids) as [$course, $slices]) {
            foreach ($slices as $ids) {
                $range = ['lo' => reset($ids), 'hi' => end($ids)];
                $counts = $logins = [];
                $select = [];
                $params = [];
                if (in_array('course_views', $fields, true)) {
                    $select[] = 'SUM(CASE WHEN eventname = :cv THEN 1 ELSE 0 END) AS course_views';
                    $params['cv'] = '\core\event\course_viewed';
                }
                if (in_array('activity_views', $fields, true)) {
                    $select[] = 'SUM(CASE WHEN contextlevel = :cl AND crud = :cr AND edulevel = :el THEN 1 ELSE 0 END)
                                 AS activity_views';
                    $params += ['cl' => CONTEXT_MODULE, 'cr' => 'r', 'el' => \core\event\base::LEVEL_PARTICIPATING];
                }
                if (in_array('active_days', $fields, true)) {
                    $select[] = 'COUNT(DISTINCT FLOOR((timecreated + :off) / 86400)) AS active_days';
                    $params['off'] = $offset;
                }
                if ($select) {
                    $rs = $DB->get_recordset_sql(
                        'SELECT userid, ' . implode(', ', $select) . ' FROM {logstore_standard_log}
                          WHERE courseid = :course AND anonymous = 0 AND timecreated >= :ts AND timecreated < :te
                                AND userid BETWEEN :lo AND :hi
                       GROUP BY userid',
                        $params + $range + ['course' => $course->id, 'ts' => $start, 'te' => $end]
                    );
                    foreach ($rs as $r) {
                        $counts[$r->userid] = $r;
                    }
                    $rs->close();
                }
                if (in_array('site_logins', $fields, true)) {
                    $rs = $DB->get_recordset_sql(
                        'SELECT userid, COUNT(1) AS logins FROM {logstore_standard_log}
                          WHERE eventname = :ev AND timecreated >= :ts AND timecreated < :te AND userid BETWEEN :lo AND :hi
                       GROUP BY userid',
                        $range + ['ev' => '\core\event\user_loggedin', 'ts' => $start, 'te' => $end]
                    );
                    foreach ($rs as $r) {
                        $logins[$r->userid] = (int) $r->logins;
                    }
                    $rs->close();
                }
                $who = $this->identity($ids);
                foreach ($ids as $userid) {
                    $c = $counts[$userid] ?? null;
                    yield $who[$userid] + [
                        'user_id' => $userid, 'course_id' => (int) $course->id, 'course_shortname' => $course->shortname,
                        'window_start' => $start, 'window_end' => $end,
                        'course_views' => (int) ($c->course_views ?? 0),
                        'activity_views' => (int) ($c->activity_views ?? 0),
                        'active_days' => (int) ($c->active_days ?? 0),
                        'site_logins' => $logins[$userid] ?? 0,
                    ];
                }
            }
        }
    }

    /**
     * The courses to report on, each with its learners cut into slices.
     *
     * @param int[] $courseids
     * @return \Generator of [course record, list of id slices]; courses that are gone or have no learners are left out
     */
    private function courses(array $courseids): \Generator {
        global $DB;
        $provider = new data_provider($this->clock);
        foreach ($courseids as $courseid) {
            $course = (int) $courseid === SITEID ? false : $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                continue;
            }
            $ids = $provider->learner_ids(\context_course::instance($course->id));
            if ($ids) {
                yield [$course, array_chunk($ids, $this->slice)];
            }
        }
    }

    /**
     * The tracked or listed activities of a course, in course order.
     *
     * @param \stdClass $course
     * @param string[] $types activity types to keep, empty for all
     * @param bool $tracked only activities with completion tracking on
     * @return \cm_info[] by course module id
     */
    private function modules(\stdClass $course, array $types, bool $tracked): array {
        $found = [];
        foreach (get_fast_modinfo($course, -1)->get_cms() as $cm) {
            if (
                !$cm->visible || $cm->deletioninprogress || ($tracked && $cm->completion <= 0)
                || ($types && !in_array($cm->modname, $types, true))
            ) {
                continue;
            }
            $found[$cm->id] = $cm;
        }
        return $found;
    }

    /**
     * The columns every activity row starts with.
     *
     * @param int $userid
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return array
     */
    private function base(int $userid, \stdClass $course, \cm_info $cm): array {
        return [
            'user_id' => $userid, 'course_id' => (int) $course->id, 'course_shortname' => $course->shortname,
            'cm_id' => (int) $cm->id, 'activity_name' => $cm->name,
        ];
    }

    /**
     * Identity columns of some learners.
     *
     * @param int[] $ids
     * @return array[] by user id: idnumber, username, email
     */
    private function identity(array $ids): array {
        global $DB;
        $who = [];
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'id');
        $rs = $DB->get_recordset_select('user', "id $in", $params, '', 'id, idnumber, username, email');
        foreach ($rs as $u) {
            $who[(int) $u->id] = ['idnumber' => $u->idnumber, 'username' => $u->username, 'email' => $u->email];
        }
        $rs->close();
        return $who;
    }

    /**
     * Gradebook items of one activity type in a course.
     *
     * @param int $courseid
     * @param string $module assign or quiz
     * @return \stdClass[] by activity instance id
     */
    private function grade_items(int $courseid, string $module): array {
        global $DB;
        $items = [];
        $rs = $DB->get_recordset_select(
            'grade_items',
            "courseid = :c AND itemtype = 'mod' AND itemmodule = :m AND itemnumber = 0",
            ['c' => $courseid, 'm' => $module],
            '',
            'id, iteminstance, gradetype, grademin, grademax'
        );
        foreach ($rs as $item) {
            $items[(int) $item->iteminstance] = $item;
        }
        $rs->close();
        return $items;
    }

    /**
     * Final grades of a slice of learners for some grade items.
     *
     * @param \stdClass[] $items from {@see grade_items()}
     * @param array $range lo and hi user ids
     * @return array final grade by item id then user id
     */
    private function grades(array $items, array $range): array {
        global $DB;
        $grades = [];
        if (!$items) {
            return $grades;
        }
        [$in, $params] = $DB->get_in_or_equal(array_map(fn($i) => (int) $i->id, $items), SQL_PARAMS_NAMED, 'gi');
        $rs = $DB->get_recordset_select(
            'grade_grades',
            "itemid $in AND userid BETWEEN :lo AND :hi AND finalgrade IS NOT NULL",
            $params + $range,
            '',
            'id, itemid, userid, finalgrade'
        );
        foreach ($rs as $g) {
            $grades[(int) $g->itemid][(int) $g->userid] = (float) $g->finalgrade;
        }
        $rs->close();
        return $grades;
    }

    /**
     * Grade and percent cells. Scale grades have no percent and are left empty.
     *
     * @param \stdClass|null $item
     * @param float|null $final
     * @return array [grade or null, percent or null]
     */
    private function grade_cells(?\stdClass $item, ?float $final): array {
        if (!$item || $final === null || (int) $item->gradetype !== GRADE_TYPE_VALUE) {
            return [null, null];
        }
        $span = (float) $item->grademax - (float) $item->grademin;
        return [$final, $span > 0 ? ($final - (float) $item->grademin) / $span * 100 : null];
    }
}
