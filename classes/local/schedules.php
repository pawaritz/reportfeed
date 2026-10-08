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
 * Creating, changing and deleting HR feed schedules, and checking what the edit form sends.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedules {
    /** @var string[] Day names as Moodle's calendar strings, index 0 = Sunday (the schedule's weekday). */
    public const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * What is wrong with the submitted schedule, by field. Empty when it is fine.
     *
     * A recipient must already hold the receive capability: the run checks it again before every send,
     * so refusing here is kinder than saving a schedule that would silently skip that person.
     *
     * @param \stdClass|array $data the submitted form data
     * @return string[] error text by field name
     */
    public static function errors($data): array {
        global $DB;
        $data = (object) $data;
        $errors = [];
        if (trim((string) ($data->name ?? '')) === '') {
            $errors['name'] = get_string('required');
        }
        if (!in_array($data->frequency ?? 'weekly', schedule_time::FREQUENCIES, true)) {
            $errors['frequency'] = get_string('required');
        } else if (($data->frequency ?? '') === 'monthly') {
            if (!in_array($data->monthrule ?? '', schedule_time::MONTHRULES, true)) {
                $errors['monthrule'] = get_string('required');
            } else if ($data->monthrule === 'day' && ((int) ($data->monthday ?? 0) < 1 || (int) $data->monthday > 31)) {
                $errors['monthday'] = get_string('required');
            }
        }
        if (!in_array($data->scope ?? '', ['all', 'categories', 'courses', 'bundle'], true)) {
            $errors['scope'] = get_string('required');
        } else if ($data->scope === 'categories' && empty($data->categoryids)) {
            $errors['categoryids'] = get_string('required');
        } else if ($data->scope === 'courses' && empty($data->courseids)) {
            $errors['courseids'] = get_string('required');
        } else if (
            $data->scope === 'bundle'
            && !$DB->record_exists('local_reportfeed_bundle', ['id' => (int) ($data->bundleid ?? 0)])
        ) {
            $errors['bundleid'] = get_string('required');
        }
        if (!self::hr_files($data) && !activity_options::from_form($data)) {
            $errors['hr_course'] = get_string('error_nofiles', 'local_reportfeed');
        }
        $typed = isset($data->externalemails) ? (string) $data->externalemails : '';
        $kept = !isset($data->externalemails) && !empty($data->id) ? external::addresses((int) $data->id) : [];
        $people = self::user_ids($data->recipients ?? []);
        if (!$people && !external::parse($typed) && !$kept) {
            $errors['recipients'] = get_string('required');
        } else if ($typed !== '' && ($problem = external::error($typed))) {
            $errors['externalemails'] = $problem;
        }
        if ($people) {
            $context = \context_system::instance();
            $names = [];
            foreach ($people as $userid) {
                $user = $DB->get_record('user', ['id' => (int) $userid, 'deleted' => 0]);
                if (!$user || !has_capability('local/reportfeed:receivehrfeed', $context, $user->id)) {
                    $names[] = $user ? fullname($user) : '#' . (int) $userid;
                }
            }
            if ($names) {
                $errors['recipients'] = get_string('error_recipientcap', 'local_reportfeed', implode(', ', $names));
            }
        }
        return $errors;
    }

    /**
     * The HR files a submitted schedule asks for, in their fixed order.
     *
     * A form sends one tick per file. Data without any of them (code that saves a schedule directly) gets the
     * default pair, and a ready list in hrfiles is cleaned against the contract.
     *
     * @param \stdClass|array $data
     * @return string[] names from {@see contract::HR_FILES}
     */
    public static function hr_files($data): array {
        $data = (object) $data;
        if (property_exists($data, 'hrfiles')) {
            $chosen = is_array($data->hrfiles) ? $data->hrfiles : explode(',', (string) $data->hrfiles);
        } else if (array_intersect(['hr_course', 'hr_summary', 'hr_roster'], array_keys(get_object_vars($data)))) {
            $ticks = ['course' => 'learner_course', 'summary' => 'learner_summary', 'roster' => 'learner_roster'];
            $chosen = [];
            foreach ($ticks as $tick => $file) {
                if (!empty($data->{"hr_$tick"})) {
                    $chosen[] = $file;
                }
            }
        } else {
            $chosen = contract::HR_DEFAULT;
        }
        return array_values(array_intersect(contract::HR_FILES, $chosen));
    }

    /**
     * Positive, unique user ids from what the picker submitted (an empty picker sends a marker string, not an id).
     *
     * @param mixed $values
     * @return int[]
     */
    private static function user_ids($values): array {
        return array_values(array_unique(array_filter(array_map('intval', (array) $values), fn($id) => $id > 0)));
    }

    /**
     * Create or update a schedule and its recipients.
     *
     * Saving counts as an edit (timemodified): the dispatcher then never sends for a moment before this save.
     *
     * @param \stdClass $data validated form data; id is 0 or unset for a new schedule
     * @param int $ownerid the person saving (kept as owner when the schedule is new)
     * @return int the schedule id
     */
    public static function save(\stdClass $data, int $ownerid): int {
        global $DB;
        $now = time();
        $scope = $data->scope;
        $frequency = (string) ($data->frequency ?? 'weekly');
        $frequency = in_array($frequency, schedule_time::FREQUENCIES, true) ? $frequency : 'weekly';
        $monthrule = (string) ($data->monthrule ?? 'day');
        $monthrule = in_array($monthrule, schedule_time::MONTHRULES, true) ? $monthrule : 'day';
        $ids = match ($scope) {
            'categories' => $data->categoryids ?? [],
            'courses' => $data->courseids ?? [],
            'bundle' => [(int) ($data->bundleid ?? 0)],
            default => [],
        };
        $record = (object) [
            'name' => trim($data->name),
            'enabled' => empty($data->enabled) ? 0 : 1,
            'frequency' => $frequency,
            'weekday' => (int) ($frequency === 'monthly' ? ($data->dow ?? $data->weekday ?? 1) : ($data->weekday ?? 1)),
            'monthrule' => $monthrule,
            'monthday' => min(31, max(1, (int) ($data->monthday ?? 1))),
            'anchor' => self::anchor_date($data),
            'hour' => (int) $data->hour,
            'minute' => (int) $data->minute,
            'scope' => $scope,
            'scopeids' => implode(',', array_map('intval', $ids)),
            'identitycols' => implode(',', array_intersect(contract::IDENTITY, (array) ($data->identitycols ?? []))),
            'format' => ($data->format ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv',
            'hrfiles' => implode(',', self::hr_files($data)),
            'activityopts' => property_exists($data, 'activityopts')
                ? self::clean_activity((string) $data->activityopts) : activity_options::from_form($data),
            'usebom' => empty($data->usebom) ? 0 : 1,
            'zip' => empty($data->zip) ? 0 : 1,
            'timemodified' => $now,
        ];
        if (!empty($data->id)) {
            $record->id = (int) $data->id;
            $DB->update_record('local_reportfeed_schedule', $record);
        } else {
            $record->ownerid = $ownerid;
            $record->lastrun = 0;
            $record->laststatus = '';
            $record->timecreated = $now;
            $record->id = $DB->insert_record('local_reportfeed_schedule', $record);
        }
        if (property_exists($data, 'externalemails') && external::can_edit()) {
            external::store((int) $record->id, (string) $data->externalemails);
        }
        $DB->delete_records('local_reportfeed_recipient', ['scheduleid' => $record->id]);
        foreach (self::user_ids($data->recipients ?? []) as $userid) {
            $DB->insert_record('local_reportfeed_recipient', (object) ['scheduleid' => $record->id, 'userid' => $userid]);
        }
        return (int) $record->id;
    }

    /**
     * Stored activity choices passed in as JSON, cleaned; null when no file is switched on.
     *
     * @param string $json
     * @return string|null
     */
    private static function clean_activity(string $json): ?string {
        $clean = activity_options::parse($json);
        return $clean['groups'] ? json_encode($clean) : null;
    }

    /**
     * The "every 2 weeks" anchor from the form's date selector, as YYYYMMDD (0 when not set).
     *
     * @param \stdClass $data
     * @return int
     */
    private static function anchor_date(\stdClass $data): int {
        $value = (int) ($data->anchor ?? 0);
        // The form's date selector gives a unix time; stored and tested values are already YYYYMMDD.
        return $value > 99999999 ? (int) userdate($value, '%Y%m%d') : $value;
    }

    /**
     * A schedule's timing in words, in the site timezone, for lists and the run log.
     *
     * @param \stdClass $s a schedule row
     * @return string
     */
    public static function describe(\stdClass $s): string {
        $time = sprintf('%02d:%02d', $s->hour, $s->minute);
        $day = get_string(self::DAYS[(int) $s->weekday], 'calendar');
        switch ($s->frequency ?? 'weekly') {
            case 'biweekly':
                return get_string('when_biweekly', 'local_reportfeed', ['day' => $day, 'time' => $time]);
            case 'semimonthly':
                return get_string('when_semimonthly', 'local_reportfeed', $time);
            case 'monthly':
                $rule = $s->monthrule ?? 'day';
                return get_string('when_month_' . $rule, 'local_reportfeed', [
                    'day' => $day, 'time' => $time, 'n' => (int) $s->monthday,
                ]);
        }
        return get_string('when_weekly', 'local_reportfeed', ['day' => $day, 'time' => $time]);
    }

    /**
     * One plain-language line about a schedule: which courses, how many recipients, in what form.
     *
     * @param \stdClass $s a schedule row
     * @return string already safe for output
     */
    public static function summary(\stdClass $s): string {
        global $DB;
        $ids = array_filter(array_map('intval', explode(',', (string) $s->scopeids)));
        switch ($s->scope) {
            case 'categories':
                $scope = get_string('summary_categories', 'local_reportfeed', count($ids));
                break;
            case 'courses':
                $scope = get_string('summary_courses', 'local_reportfeed', count($ids));
                break;
            case 'bundle':
                $name = $ids ? $DB->get_field('local_reportfeed_bundle', 'name', ['id' => reset($ids)]) : false;
                $scope = get_string('summary_bundle', 'local_reportfeed', s($name === false ? '?' : $name));
                break;
            default:
                $scope = get_string('scope_all', 'local_reportfeed');
        }
        $people = $DB->count_records('local_reportfeed_recipient', ['scheduleid' => $s->id]);
        $outside = $DB->count_records('local_reportfeed_extrecipient', ['scheduleid' => $s->id]);
        $hr = array_map(
            fn($file) => get_string("hr_short_$file", 'local_reportfeed'),
            array_values(array_intersect(contract::HR_FILES, array_filter(explode(',', (string) $s->hrfiles))))
        );
        $parts = [$scope, get_string('summary_recipients', 'local_reportfeed', $people)];
        if ($hr) {
            $parts[] = implode(', ', $hr);
        }
        if ($outside) {
            $parts[] = get_string('summary_external', 'local_reportfeed', $outside);
        }
        if ($files = activity_options::files($s)) {
            $parts[] = get_string('summary_activity', 'local_reportfeed', count($files));
        }
        $parts[] = get_string(($s->format ?? 'csv') === 'xlsx' ? 'summary_xlsx' : 'summary_csv', 'local_reportfeed')
            . ($s->zip ? ', ' . get_string('summary_zip', 'local_reportfeed') : '');
        return implode(' · ', $parts);
    }

    /**
     * A status as a coloured badge.
     *
     * @param string $status a run status
     * @return string HTML
     */
    public static function badge(string $status): string {
        $class = match ($status) {
            'sent' => 'bg-success',
            'failed', 'expired' => 'bg-danger',
            'too_large', 'skipped' => 'bg-warning text-dark',
            default => 'bg-secondary',
        };
        return \html_writer::span(s($status), "badge $class");
    }

    /**
     * Delete a schedule and its recipients. Its runs stay in the log (retention removes them later); a run
     * still queued for it ends as skipped.
     *
     * @param int $id
     */
    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records('local_reportfeed_recipient', ['scheduleid' => $id]);
        $DB->delete_records('local_reportfeed_extrecipient', ['scheduleid' => $id]);
        $DB->delete_records('local_reportfeed_roster', ['scheduleid' => $id]);
        $DB->delete_records('local_reportfeed_schedule', ['id' => $id]);
    }

    /**
     * The data an edit form needs to show a stored schedule.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function load(int $id): \stdClass {
        global $DB;
        $schedule = $DB->get_record('local_reportfeed_schedule', ['id' => $id], '*', MUST_EXIST);
        $ids = array_filter(array_map('intval', explode(',', (string) $schedule->scopeids)));
        $schedule->categoryids = $schedule->scope === 'categories' ? $ids : [];
        $schedule->courseids = $schedule->scope === 'courses' ? $ids : [];
        $schedule->bundleid = $schedule->scope === 'bundle' ? (int) reset($ids) : 0;
        $schedule->identitycols = array_filter(explode(',', $schedule->identitycols));
        $schedule->dow = $schedule->weekday;
        $anchor = (int) $schedule->anchor;
        $schedule->anchor = $anchor > 0
            ? make_timestamp(intdiv($anchor, 10000), intdiv($anchor, 100) % 100, $anchor % 100)
            : time();
        $chosen = array_filter(explode(',', (string) $schedule->hrfiles));
        $schedule->hr_course = (int) in_array('learner_course', $chosen, true);
        $schedule->hr_summary = (int) in_array('learner_summary', $chosen, true);
        $schedule->hr_roster = (int) in_array('learner_roster', $chosen, true);
        foreach (activity_options::to_form($schedule) as $name => $value) {
            $schedule->$name = $value;
        }
        $schedule->externalemails = implode("\n", external::addresses($id));
        $schedule->recipients = $DB->get_fieldset_select('local_reportfeed_recipient', 'userid', 'scheduleid = ?', [$id]);
        return $schedule;
    }
}
