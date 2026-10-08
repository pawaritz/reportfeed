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
 * Creates runs, executes them, and keeps the truth about each one in our own run table (ADR-002, ADR-003).
 *
 * Guarantees: one run per schedule and send date (unique key); a retry never emails someone already served
 * (a delivery row per recipient and file); after the last attempt the run says failed, with the error.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_manager {
    /** @var int Attempts per run, also given to the ad hoc task. */
    public const MAX_ATTEMPTS = 3;

    /** @var int A run that would start later than this after its scheduled moment expires (seconds). */
    public const EXPIRY = DAYSECS;

    /** @var string[] The two HR files, in send order. */

    /** @var string[] Statuses that end a run. */
    private const FINAL = ['sent', 'skipped', 'too_large', 'failed', 'expired'];

    /** @var string[] Statuses from which an admin may resend (never a sent run). */
    public const RESENDABLE = ['skipped', 'too_large', 'failed', 'expired'];

    /** @var \core\clock Time source. */
    private \core\clock $clock;

    /**
     * Constructor.
     *
     * @param \core\clock|null $clock defaults to the site clock
     */
    public function __construct(?\core\clock $clock = null) {
        $this->clock = $clock ?? \core\di::get(\core\clock::class);
    }

    /**
     * Create the runs that are due now. Safe to call as often as you like.
     *
     * A schedule fires for its latest scheduled moment only if that moment is less than 24 hours old and not
     * older than the schedule's last edit, so a new or edited schedule never sends for a moment in the past.
     *
     * @return int number of runs created
     */
    public function dispatch(): int {
        global $DB;
        if (!get_config('local_reportfeed', 'enabled')) {
            return 0;
        }
        $now = $this->clock->time();
        // Nothing is sent for a moment before the admin last switched Reportfeed or the teacher mode on.
        $floor = (int) get_config('local_reportfeed', 'switchtime');
        $created = 0;
        foreach ($DB->get_records('local_reportfeed_schedule', ['enabled' => 1]) as $schedule) {
            $due = schedule_time::latest_due_for($schedule, $now);
            if ($now - $due > self::EXPIRY || $due < max((int) $schedule->timemodified, $floor)) {
                continue;
            }
            $created += $this->create_run('hrfeed', (int) $schedule->id, $due) ? 1 : 0;
        }
        return $created + $this->dispatch_digests($now, $floor);
    }

    /**
     * Create the teacher digest runs that are due: one per teacher and course, from their own settings, and in
     * optout mode one for every teacher with the capability who has no settings of their own.
     *
     * @param int $now
     * @param int $floor no run for a moment before this time
     * @return int number of runs created
     */
    private function dispatch_digests(int $now, int $floor): int {
        global $DB;
        $mode = digest_settings::mode();
        if ($mode === 'off') {
            return 0;
        }
        $created = 0;
        $own = [];
        $fields = 'id, userid, courseid, enabled, weekday, hour, timemodified';
        foreach ($DB->get_records('local_reportfeed_digestcourse', null, '', $fields) as $row) {
            $own[$row->userid . '-' . $row->courseid] = true;
            if (!$row->enabled) {
                continue;
            }
            $due = schedule_time::latest_due((int) $row->weekday, (int) $row->hour, 0, $now);
            if ($now - $due > self::EXPIRY || $due < max((int) $row->timemodified, $floor)) {
                continue;
            }
            $created += $this->create_run('teacherdigest', 0, $due, 0, (int) $row->userid, (int) $row->courseid) ? 1 : 0;
        }
        return $created + ($mode === 'optout' ? $this->dispatch_default_digests($now, $floor, $own) : 0);
    }

    /**
     * Optout mode: a digest for every teacher without settings of their own, in every running course.
     *
     * Looking at every course is slow, so it happens once per default moment (remembered in the
     * digestexpanded setting); the unique key still stops a duplicate if it is interrupted and repeated.
     *
     * @param int $now
     * @param int $floor
     * @param array $own keys "userid-courseid" of people who have their own settings
     * @return int number of runs created
     */
    private function dispatch_default_digests(int $now, int $floor, array $own): int {
        global $DB;
        $default = digest_settings::defaults();
        $due = schedule_time::latest_due($default->weekday, $default->hour, 0, $now);
        if (
            $now - $due > self::EXPIRY || $due < $floor
            || (int) get_config('local_reportfeed', 'digestexpanded') === $due
        ) {
            return 0;
        }
        $created = 0;
        $courses = $DB->get_records_select(
            'course',
            'id <> :site AND visible = 1 AND (enddate = 0 OR enddate > :now)',
            ['site' => SITEID, 'now' => $now],
            'id',
            'id'
        );
        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            foreach (get_enrolled_users($context, 'local/reportfeed:receiveteacherdigest', 0, 'u.id', null, 0, 0, true) as $user) {
                if (!isset($own[$user->id . '-' . $course->id])) {
                    $created += $this->create_run('teacherdigest', 0, $due, 0, (int) $user->id, (int) $course->id) ? 1 : 0;
                }
            }
        }
        set_config('digestexpanded', $due, 'local_reportfeed');
        return $created;
    }

    /**
     * Run a schedule now (the admin pressed Run now). One manual run per schedule and day: a second press
     * returns false, so nobody is emailed twice by a double click. It does not use up the scheduled send.
     *
     * @param int $scheduleid
     * @return bool whether a run was queued
     */
    public function run_now(int $scheduleid): bool {
        return $this->create_run('hrfeed', $scheduleid, $this->clock->time(), 1);
    }

    /**
     * Send the schedule's real files to one person only, nothing logged. The emails look exactly like the real
     * ones (same subject, name and body block), with run_id 0 marking a test.
     *
     * @param int $scheduleid
     * @param \stdClass $to the person to send to (a recipient capability is not needed)
     * @return int number of emails handed to Moodle mail
     */
    public function send_test(int $scheduleid, \stdClass $to): int {
        global $DB;
        $schedule = $DB->get_record('local_reportfeed_schedule', ['id' => $scheduleid], '*', MUST_EXIST);
        if (!health::attachments_enabled()) {
            throw new \moodle_exception('error_attachments', 'local_reportfeed');
        }
        \core_php_time_limit::raise(300);
        $now = $this->clock->time();
        $run = (object) ['id' => 0, 'periodend' => schedule_time::period_end($now)];
        $sent = 0;
        $summary = [];
        foreach ($this->build_files($schedule, (int) $run->periodend, $now, $summary) as $file => $result) {
            foreach ($result['parts'] as $meta) {
                // A random item id keeps two simultaneous tests from clashing on the stored attachment.
                $sent += mailer::send($to, random_int(1000000000, 2000000000), $file, $meta, $run, $now) ? 1 : 0;
            }
        }
        return $sent;
    }

    /**
     * Put a failed, expired, skipped or too-large run back in the queue.
     *
     * Recipients already served keep their "sent" mark, so a resend never emails them twice.
     *
     * @param int $runid
     * @return bool false when the run does not exist or has already been sent
     */
    public function resend(int $runid): bool {
        global $DB;
        $run = $DB->get_record('local_reportfeed_run', ['id' => $runid]);
        if (!$run || !in_array($run->status, self::RESENDABLE, true)) {
            return false;
        }
        $DB->update_record('local_reportfeed_run', (object) [
            'id' => $runid, 'status' => 'queued', 'attempts' => 0, 'timedue' => $this->clock->time(),
            'timefinished' => 0, 'error' => null,
        ]);
        $this->queue($runid);
        return true;
    }

    /**
     * Execute one run (called by the ad hoc task).
     *
     * @param int $runid
     * @throws \Throwable after recording the error, so Moodle retries until the attempts run out
     */
    public function execute(int $runid): void {
        global $DB;
        $run = $DB->get_record('local_reportfeed_run', ['id' => $runid]);
        if (!$run || in_array($run->status, self::FINAL)) {
            return;
        }
        $now = $this->clock->time();
        if ($now - $run->timedue > self::EXPIRY) {
            $this->finish($run, 'expired', null, get_string('error_expired', 'local_reportfeed'));
            return;
        }
        $run->attempts++;
        $run->status = 'running';
        $run->timestarted = $now;
        $DB->update_record('local_reportfeed_run', $run);

        try {
            [$status, $summary] = $run->type === 'teacherdigest'
                ? $this->deliver_teacherdigest($run)
                : $this->deliver_hrfeed($run);
            $this->finish($run, $status, $summary, null);
        } catch (\Throwable $e) {
            $last = $run->attempts >= self::MAX_ATTEMPTS;
            $this->finish($run, $last ? 'failed' : 'queued', null, mb_substr($e->getMessage(), 0, 1000), !$last);
            throw $e;
        }
    }

    /**
     * Insert the run row (the unique key blocks a second one) and queue its task.
     *
     * @param string $type
     * @param int $scheduleid
     * @param int $due the scheduled moment
     * @param int $manual 1 for Run now
     * @param int $userid teacher digest recipient
     * @param int $courseid teacher digest course
     * @return bool whether a new run was created
     */
    private function create_run(
        string $type,
        int $scheduleid,
        int $due,
        int $manual = 0,
        int $userid = 0,
        int $courseid = 0
    ): bool {
        global $DB;
        $run = (object) [
            'type' => $type, 'scheduleid' => $scheduleid, 'userid' => $userid, 'courseid' => $courseid,
            'manual' => $manual, 'periodend' => schedule_time::period_end($due), 'timedue' => $due,
            'status' => 'queued', 'attempts' => 0, 'timecreated' => $this->clock->time(),
        ];
        $key = [
            'type' => $type, 'scheduleid' => $scheduleid, 'userid' => $userid, 'courseid' => $courseid,
            'manual' => $manual, 'periodend' => $run->periodend,
        ];
        if ($DB->record_exists('local_reportfeed_run', $key)) {
            return false;
        }
        try {
            $id = $DB->insert_record('local_reportfeed_run', $run);
        } catch (\dml_write_exception $e) {
            return false; // Another cron process won the race for the same unique key.
        }
        $this->queue($id);
        return true;
    }

    /**
     * Queue the ad hoc task for a run, with the attempt limit (change C6).
     *
     * @param int $runid
     */
    private function queue(int $runid): void {
        $task = new \local_reportfeed\task\run_task();
        $task->set_custom_data(['runid' => $runid]);
        $task->set_attempts_available(self::MAX_ATTEMPTS);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Record the outcome of an attempt on the run and on its schedule.
     *
     * @param \stdClass $run
     * @param string $status
     * @param array|null $summary
     * @param string|null $error
     * @param bool $retrying true when another attempt will follow (the run is not finished)
     */
    private function finish(\stdClass $run, string $status, ?array $summary, ?string $error, bool $retrying = false): void {
        global $DB;
        $now = $this->clock->time();
        $update = ['id' => $run->id, 'status' => $status, 'error' => $error, 'timefinished' => $retrying ? 0 : $now];
        if ($summary !== null) {
            $update['summary'] = json_encode($summary);
        }
        $DB->update_record('local_reportfeed_run', (object) $update);
        if (!$retrying && $run->scheduleid) {
            // Not update_record with timemodified: that field means "last edited by a person".
            $DB->set_field('local_reportfeed_schedule', 'lastrun', $now, ['id' => $run->scheduleid]);
            $DB->set_field('local_reportfeed_schedule', 'laststatus', $status, ['id' => $run->scheduleid]);
        }
    }

    /**
     * Build the files (in parts when they are large) and hand them to Moodle mail for every recipient not yet served.
     *
     * A delivery row exists per recipient, file and part; a retry skips the ones already sent. Rows are created
     * after the files are built, because only then is the number of parts known; the plan (parts per file) is kept
     * in the run summary so a retry knows what "everything sent" means.
     *
     * @param \stdClass $run
     * @return array [final status, summary]
     */
    private function deliver_hrfeed(\stdClass $run): array {
        global $DB;
        if (!get_config('local_reportfeed', 'enabled')) {
            return ['skipped', ['reason' => 'disabled']];
        }
        $schedule = $DB->get_record('local_reportfeed_schedule', ['id' => $run->scheduleid]);
        if (!$schedule || !$schedule->enabled) {
            return ['skipped', ['reason' => 'schedule disabled or deleted']];
        }
        if (!health::attachments_enabled()) {
            throw new \moodle_exception('error_attachments', 'local_reportfeed');
        }
        $summary = $run->summary ? (json_decode($run->summary, true) ?: []) : [];
        $targets = $this->hr_targets($schedule);
        $eligible = array_filter($targets, fn($t) => $t->reason === null);
        // Without a plan nothing has been built yet; with one, the files are the ones it holds, whatever the schedule
        // says now.
        $plan = $summary['plan'] ?? [];
        $files = $plan ? array_keys($plan) : $this->files_for($schedule);
        $this->sync_skips($run, $targets, $files);

        // Does anything still need to be sent?
        $rows = $this->rows($run);
        $needbuild = $eligible && !$plan;
        foreach ($plan ? $eligible : [] as $t) {
            foreach ($files as $file) {
                for ($part = 1; $part <= ($plan[$file] ?? 1); $part++) {
                    $row = $rows[$this->rowkey($t, $file, $part)] ?? null;
                    $needbuild = $needbuild || !$row || $row->status === 'pending';
                }
            }
        }

        $toolarge = !empty($summary['toolarge']);
        $failures = 0;
        if ($needbuild) {
            if (in_array('activity_engagement', $files, true) && empty($summary['window'])) {
                // Fixed now and kept, so a retry or a resend later counts the same period.
                $summary['window'] = self::window($schedule, (int) $run->timedue);
                $DB->set_field('local_reportfeed_run', 'summary', json_encode($summary), ['id' => $run->id]);
            }
            $built = $this->build_files($schedule, (int) $run->periodend, (int) $run->timedue, $summary, (int) $run->id);
            $newplan = array_map(fn($b) => $b['toomany'] ? 1 : $b['partcount'], $built);
            if ($plan && $plan != $newplan) {
                $summary['splitchanged'] = true;
            }
            $plan = $newplan;
            $summary['plan'] = $plan;
            foreach ($built as $file => $result) {
                $summary['files'][$file] = [
                    'rows' => $result['rows'],
                    'bytes' => array_sum(array_column($result['parts'], 'bytes')),
                    'parts' => $plan[$file],
                    'attach' => !$result['toomany'] && !self::any_unattached($result['parts']),
                ] + ($result['toomany'] ? ['needed' => $result['partcount']] : [])
                  + (isset($result['parts'][0]['rowcap']) ? ['rowcap' => $result['parts'][0]['rowcap']] : []);
                $toolarge = $toolarge || !$summary['files'][$file]['attach'];
            }
            foreach ($eligible as $t) {
                foreach ($built as $file => $result) {
                    for ($part = 1; $part <= $plan[$file]; $part++) {
                        $this->ensure_row($run, $t, $file, $part);
                    }
                }
            }
            $this->drop_vanished_parts($run, $plan);
            $now = $this->clock->time();
            foreach ($DB->get_records('local_reportfeed_delivery', ['runid' => $run->id, 'status' => 'pending']) as $delivery) {
                $t = $targets[$this->targetkey($delivery)] ?? null;
                $result = $built[$delivery->filekey] ?? null;
                $meta = $result['parts'][$delivery->part - 1] ?? null;
                if (!$t || !$meta) {
                    continue;
                }
                if ($delivery->filekey === 'learner_roster' && !empty($summary['roster'])) {
                    $meta['roster'] = $summary['roster'];
                }
                if (mailer::send($t->user, (int) $delivery->id, $delivery->filekey, $meta, $run, $now)) {
                    $this->mark((int) $delivery->id, 'sent', null);
                } else {
                    $failures++;
                }
            }
        }
        if ($failures) {
            throw new \moodle_exception('error_sendfailed', 'local_reportfeed', '', $failures);
        }
        $summary['toolarge'] = $toolarge;
        $summary['recipients'] = $DB->get_records_sql_menu(
            'SELECT status, COUNT(1) FROM {local_reportfeed_delivery} WHERE runid = ? AND part > 0 GROUP BY status',
            [$run->id]
        );
        $skips = $DB->count_records('local_reportfeed_delivery', ['runid' => $run->id, 'status' => 'skipped']);
        if ($skips) {
            $summary['recipients']['skipped'] = $skips;
        }
        $sent = (int) ($summary['recipients']['sent'] ?? 0);
        return [$sent ? ($toolarge ? 'too_large' : 'sent') : 'skipped', $summary];
    }

    /**
     * Whether any part of a file has no attachment.
     *
     * @param array[] $parts
     * @return bool
     */
    private static function any_unattached(array $parts): bool {
        foreach ($parts as $part) {
            if (!$part['attach']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Who a schedule's run is for, with the reason for anyone who must not be emailed.
     *
     * @param \stdClass $schedule
     * @return \stdClass[] by target key: userid, extid, email, user, reason
     */
    private function hr_targets(\stdClass $schedule): array {
        global $DB;
        $targets = [];
        foreach ($DB->get_fieldset_select('local_reportfeed_recipient', 'userid', 'scheduleid = ?', [$schedule->id]) as $userid) {
            $reason = $this->ineligible_reason((int) $userid);
            $t = (object) [
                'userid' => (int) $userid, 'extid' => 0, 'email' => null, 'reason' => $reason,
                'user' => $reason === null ? $DB->get_record('user', ['id' => $userid]) : null,
            ];
            $targets[$this->targetkey($t)] = $t;
        }
        $allowed = external::allowed();
        foreach ($DB->get_records('local_reportfeed_extrecipient', ['scheduleid' => $schedule->id], 'id') as $ext) {
            $reason = !$allowed ? 'externaldisabled' : (validate_email($ext->email) ? null : 'noemail');
            $t = (object) [
                'userid' => 0, 'extid' => (int) $ext->id, 'email' => $ext->email, 'reason' => $reason,
                'user' => $reason === null ? external::user($ext->email) : null,
            ];
            $targets[$this->targetkey($t)] = $t;
        }
        return $targets;
    }

    /**
     * The key of a target or delivery row: the Moodle user, or the external recipient.
     *
     * @param \stdClass $x anything with userid and extid
     * @return string
     */
    private function targetkey(\stdClass $x): string {
        return (int) $x->userid . ':' . (int) $x->extid;
    }

    /**
     * The key of one delivery row.
     *
     * @param \stdClass $t target
     * @param string $file
     * @param int $part
     * @return string
     */
    private function rowkey(\stdClass $t, string $file, int $part): string {
        return $this->targetkey($t) . ":$file:$part";
    }

    /**
     * All delivery rows of a run by their key.
     *
     * @param \stdClass $run
     * @return \stdClass[]
     */
    private function rows(\stdClass $run): array {
        global $DB;
        $rows = [];
        foreach ($DB->get_records('local_reportfeed_delivery', ['runid' => $run->id]) as $row) {
            $rows[$this->rowkey($row, $row->filekey, (int) $row->part)] = $row;
        }
        return $rows;
    }

    /**
     * Make the skip rows match the recipients' present state: one row (part 0) per file for a person who must not be
     * emailed, the reason refreshed, their unsent parts marked skipped; none for a person who may be emailed now.
     *
     * @param \stdClass $run
     * @param \stdClass[] $targets
     * @param string[] $files the file keys of the run
     */
    private function sync_skips(\stdClass $run, array $targets, array $files): void {
        global $DB;
        foreach ($targets as $t) {
            foreach ($files as $file) {
                $key = ['runid' => $run->id, 'userid' => $t->userid, 'extid' => $t->extid, 'filekey' => $file, 'part' => 0];
                $row = $DB->get_record('local_reportfeed_delivery', $key);
                $where = 'runid = :r AND userid = :u AND extid = :e AND filekey = :f AND part > 0';
                $params = ['r' => $run->id, 'u' => $t->userid, 'e' => $t->extid, 'f' => $file];
                if ($t->reason === null) {
                    if ($row) {
                        $DB->delete_records('local_reportfeed_delivery', ['id' => $row->id]);
                    }
                    // Parts skipped earlier because the account was broken are served now that it is fixed.
                    $DB->set_field_select(
                        'local_reportfeed_delivery',
                        'status',
                        'pending',
                        "$where AND status = 'skipped' AND reason IN "
                            . "('deleted', 'suspended', 'noemail', 'nocapability', 'notenrolled')",
                        $params
                    );
                    continue;
                }
                if ($row) {
                    $this->mark((int) $row->id, 'skipped', $t->reason);
                } else {
                    $DB->insert_record('local_reportfeed_delivery', (object) ($key + [
                        'status' => 'skipped', 'reason' => $t->reason, 'email' => $t->email,
                        'timemodified' => $this->clock->time(),
                    ]));
                }
                $DB->set_field_select('local_reportfeed_delivery', 'reason', $t->reason, "$where AND status = 'pending'", $params);
                $DB->set_field_select('local_reportfeed_delivery', 'status', 'skipped', "$where AND status = 'pending'", $params);
            }
        }
    }

    /**
     * Create the pending delivery row of one recipient, file and part unless it exists; a row skipped earlier for
     * a reason that no longer holds is not touched here (sync_skips has removed the part 0 row).
     *
     * @param \stdClass $run
     * @param \stdClass $t target
     * @param string $file
     * @param int $part
     */
    private function ensure_row(\stdClass $run, \stdClass $t, string $file, int $part): void {
        global $DB;
        $key = [
            'runid' => $run->id, 'userid' => $t->userid, 'extid' => $t->extid, 'filekey' => $file, 'part' => $part,
        ];
        if (!$DB->record_exists('local_reportfeed_delivery', $key)) {
            $DB->insert_record('local_reportfeed_delivery', (object) ($key + [
                'status' => 'pending', 'email' => $t->email, 'timemodified' => $this->clock->time(),
            ]));
        }
    }

    /**
     * After a rebuild with fewer parts than before, pending rows for parts that no longer exist cannot be sent.
     *
     * @param \stdClass $run
     * @param int[] $plan parts per file
     */
    private function drop_vanished_parts(\stdClass $run, array $plan): void {
        global $DB;
        foreach ($plan as $file => $parts) {
            $DB->set_field_select(
                'local_reportfeed_delivery',
                'status',
                'skipped',
                "runid = :r AND filekey = :f AND part > :p AND status = 'pending'",
                ['r' => $run->id, 'f' => $file, 'p' => $parts]
            );
            $DB->set_field_select(
                'local_reportfeed_delivery',
                'reason',
                'partgone',
                "runid = :r AND filekey = :f AND part > :p AND status = 'skipped' AND reason IS NULL",
                ['r' => $run->id, 'f' => $file, 'p' => $parts]
            );
        }
    }

    /**
     * Build one teacher's digest for one course and hand it to Moodle mail.
     *
     * One run is one recipient and one course, so a failure never repeats another teacher's email.
     * What the file holds is decided by the recipient's own permissions in the data provider (ADR-005); the
     * digest settings only choose among columns the recipient may see.
     *
     * @param \stdClass $run
     * @return array [final status, summary]
     */
    private function deliver_teacherdigest(\stdClass $run): array {
        global $DB;
        if (!get_config('local_reportfeed', 'enabled')) {
            return ['skipped', ['reason' => 'disabled']];
        }
        $course = $DB->get_record('course', ['id' => $run->courseid]);
        $settings = digest_settings::effective((int) $run->userid, (int) $run->courseid);
        if (!$course || !$settings) {
            return ['skipped', ['reason' => 'digest switched off or course deleted']];
        }
        if (!health::attachments_enabled()) {
            throw new \moodle_exception('error_attachments', 'local_reportfeed');
        }
        $summary = $run->summary ? (json_decode($run->summary, true) ?: []) : [];
        $key = ['runid' => $run->id, 'userid' => $run->userid, 'filekey' => 'teacher_digest'];
        $delivery = $DB->get_record('local_reportfeed_delivery', $key);
        if (!$delivery) {
            $key += ['status' => 'pending', 'timemodified' => $this->clock->time()];
            $key['id'] = $DB->insert_record('local_reportfeed_delivery', (object) $key);
            $delivery = (object) $key;
        }
        if ($delivery->status === 'sent') {
            return [empty($summary['toolarge']) ? 'sent' : 'too_large', $summary];
        }
        $context = \context_course::instance($course->id);
        $reason = $this->ineligible_reason((int) $run->userid, 'local/reportfeed:receiveteacherdigest', $context);
        $this->mark($delivery->id, $reason === null ? 'pending' : 'skipped', $reason);
        if ($reason !== null) {
            return ['skipped', ['reason' => $reason]];
        }

        $now = $this->clock->time();
        $name = mailer::filename('teacher_digest', (int) $run->periodend, 'csv', (int) $course->id);
        $dir = make_request_directory();
        $columns = contract::columns(
            'teacher_digest',
            [],
            $settings->choices,
            has_capability('moodle/grade:viewall', $context, (int) $run->userid)
        );
        $rows = (new data_provider($this->clock))->learner_course([(int) $course->id], (int) $run->userid);
        $stats = [
            'learners' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0, 'inactive' => 0, 'sum' => 0.0,
            'counted' => 0, 'tracking' => false, 'days' => (int) (get_config('local_reportfeed', 'inactivitydays') ?: 14),
        ];
        $meta = csv_writer::write("$dir/$name", $columns, $this->tally($rows, $stats));
        $cap = (float) get_config('local_reportfeed', 'sizecapmb') * 1048576;
        $meta = ['name' => $name, 'path' => "$dir/$name", 'attach' => $meta['bytes'] <= $cap] + $meta;

        $user = $DB->get_record('user', ['id' => $run->userid], '*', MUST_EXIST);
        $subject = mailer::digest_subject($course->shortname, (int) $run->periodend);
        $digest = ['course' => $course, 'stats' => $stats];
        if (!mailer::send($user, (int) $delivery->id, 'teacher_digest', $meta, $run, $now, $subject, 'teacherdigest', $digest)) {
            throw new \moodle_exception('error_sendfailed', 'local_reportfeed', '', 1);
        }
        $this->mark($delivery->id, 'sent', null);
        $summary['files']['teacher_digest'] = array_diff_key($meta, ['path' => 1]);
        $summary['toolarge'] = !$meta['attach'];
        return [$summary['toolarge'] ? 'too_large' : 'sent', $summary];
    }

    /**
     * Pass rows through unchanged while counting them for the friendly summary of the digest email.
     *
     * @param iterable $rows
     * @param array $stats counters, updated as the rows pass
     * @return \Generator
     */
    private function tally(iterable $rows, array &$stats): \Generator {
        foreach ($rows as $row) {
            $stats['learners']++;
            if ($row['completion_status'] !== null) {
                $stats['tracking'] = true;
                $stats[$row['completion_status']]++;
            }
            if ($row['completion_percent'] !== null) {
                $stats['sum'] += $row['completion_percent'];
                $stats['counted']++;
            }
            $stats['inactive'] += $row['days_inactive'] >= $stats['days'] ? 1 : 0;
            yield $row;
        }
    }

    /**
     * Set the status and reason of one delivery row.
     *
     * @param int $deliveryid
     * @param string $status
     * @param string|null $reason
     */
    private function mark(int $deliveryid, string $status, ?string $reason): void {
        global $DB;
        $DB->update_record('local_reportfeed_delivery', (object) [
            'id' => $deliveryid, 'status' => $status, 'reason' => $reason, 'timemodified' => $this->clock->time(),
        ]);
    }

    /**
     * Why a recipient must not be emailed, or null when they may be (change C3).
     *
     * @param int $userid
     * @param string $capability what the recipient must hold
     * @param \context|null $context where they must hold it (the system by default)
     * @return string|null deleted, suspended, noemail, nocapability, notenrolled or null
     */
    private function ineligible_reason(
        int $userid,
        string $capability = 'local/reportfeed:receivehrfeed',
        ?\context $context = null
    ): ?string {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || $user->deleted) {
            return 'deleted';
        }
        if ($user->suspended) {
            return 'suspended';
        }
        if (!validate_email($user->email)) {
            return 'noemail';
        }
        if (!has_capability($capability, $context ?? \context_system::instance(), $userid)) {
            return 'nocapability';
        }
        // A teacher whose enrolment is suspended or ended keeps the role but must stop getting the course's learner data.
        if ($context instanceof \context_course && !is_enrolled($context, $userid, $capability, true)) {
            return 'notenrolled';
        }
        return null;
    }

    /**
     * The files a schedule sends: the two HR files, then the activity files the administrator chose.
     *
     * @param \stdClass $schedule
     * @return string[]
     */
    public function files_for(\stdClass $schedule): array {
        $chosen = array_filter(explode(',', (string) ($schedule->hrfiles ?? '')));
        return array_merge(array_values(array_intersect(contract::HR_FILES, $chosen)), activity_options::files($schedule));
    }

    /**
     * The moments an engagement report covers, kept in the run summary so a retry or resend counts the same period.
     *
     * @param \stdClass $schedule
     * @param int $due the scheduled moment (or now for a manual run)
     * @return int[] start (inclusive) and end (exclusive)
     */
    public static function window(\stdClass $schedule, int $due): array {
        $first = (new \DateTimeImmutable('@' . $due))->setTimezone(\core_date::get_server_timezone_object())
            ->modify('first day of this month')->setTime(0, 0);
        return match (activity_options::window($schedule)) {
            'lastmonth' => [$first->modify('-1 month')->getTimestamp(), $first->getTimestamp()],
            'monthtodate' => [$first->getTimestamp(), $due],
            default => [schedule_time::previous_due_for($schedule, $due), $due],
        };
    }

    /**
     * Write the schedule's files in its format, zipped or split into parts as needed.
     *
     * An engagement file is left out, and noted in the summary, when the standard log is off.
     *
     * @param \stdClass $schedule
     * @param int $periodend YYYYMMDD
     * @param int $due the scheduled moment (or now), the end of an engagement window
     * @param array $summary the run summary: the window is kept in it, unavailable files are noted in it
     * @param int|null $runid the run the roster is stored for; null for a test send
     * @return array[] by file key: the result of {@see packager::build()}
     */
    private function build_files(\stdClass $schedule, int $periodend, int $due, array &$summary, ?int $runid = null): array {
        $courseids = $this->course_ids($schedule);
        $identity = array_filter(explode(',', $schedule->identitycols));
        $provider = new data_provider($this->clock);
        $activity = new activity_provider($this->clock);
        $opts = activity_options::parse($schedule->activityopts ?? null)['groups'];
        $dir = make_request_directory();
        $options = [
            'format' => $schedule->format ?? 'csv', 'zip' => (bool) $schedule->zip, 'bom' => (bool) $schedule->usebom,
            'cap' => (float) get_config('local_reportfeed', 'sizecapmb') * 1048576,
            'maxparts' => (int) (get_config('local_reportfeed', 'maxparts') ?: 10),
        ];
        $files = [];
        foreach ($this->files_for($schedule) as $file) {
            switch ($file) {
                case 'learner_course':
                    $rows = $provider->learner_course($courseids);
                    break;
                case 'learner_summary':
                    $rows = $provider->learner_summary($courseids);
                    break;
                case 'learner_roster':
                    $tally = ['active' => 0, 'new' => 0, 'removed' => 0];
                    $rows = (function () use ($provider, $courseids, $schedule, $runid, &$tally) {
                        foreach (roster::rows($provider, $courseids, (int) $schedule->id, $runid) as $row) {
                            $tally['active'] += $row['change_type'] === 'removed' ? 0 : 1;
                            $tally['new'] += $row['change_type'] === 'new' ? 1 : 0;
                            $tally['removed'] += $row['change_type'] === 'removed' ? 1 : 0;
                            yield $row;
                        }
                    })();
                    break;
                case 'activity_completion':
                    $rows = $activity->completion($courseids, $opts[$file]);
                    break;
                case 'activity_assignments':
                    $rows = $activity->assignments($courseids, $opts[$file]);
                    break;
                case 'activity_quizzes':
                    $rows = $activity->quizzes($courseids, $opts[$file]);
                    break;
                default:
                    if (!activity_provider::log_available()) {
                        $summary['unavailable'][$file] = 'nolog';
                        continue 2;
                    }
                    if (empty($summary['window'])) {
                        $summary['window'] = self::window($schedule, $due);
                    }
                    $rows = $activity->engagement($courseids, $opts[$file], ...$summary['window']);
            }
            $fields = $opts[$file]['fields'] ?? [];
            $stem = fn(int $part, int $parts, string $ext) => mailer::filename($file, $periodend, $ext, 0, $part, $parts);
            $extra = in_array($file, contract::ACTIVITY_FILES, true)
                ? ['maxrows' => (int) get_config('local_reportfeed', 'activitymaxrows')] : [];
            $files[$file] = packager::build(
                $dir,
                $stem,
                contract::columns($file, $identity, $fields),
                $rows,
                $extra + $options
            );
            if ($file === 'learner_roster') {
                // The packager has read every row by now, so the counts are complete.
                $summary['roster'] = $tally;
            }
        }
        return $files;
    }

    /**
     * The courses a schedule covers (for the preview screen).
     *
     * @param \stdClass $schedule
     * @return int[]
     */
    public static function course_ids_for(\stdClass $schedule): array {
        return (new self())->course_ids($schedule);
    }

    /**
     * The courses a schedule covers: all (except the site course), some categories with their subcategories, or listed courses.
     *
     * @param \stdClass $schedule
     * @return int[]
     */
    private function course_ids(\stdClass $schedule): array {
        global $DB;
        $ids = array_filter(array_map('intval', explode(',', (string) $schedule->scopeids)));
        $params = ['site' => SITEID];
        if ($schedule->scope === 'bundle') {
            return $ids ? bundles::course_ids((int) reset($ids)) : [];
        }
        if ($schedule->scope === 'courses') {
            if (!$ids) {
                return [];
            }
            [$in, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $where = "c.id $in";
            $params += $inparams;
            $join = '';
        } else if ($schedule->scope === 'categories') {
            $cats = $ids ? $DB->get_records_list('course_categories', 'id', $ids, '', 'id, path') : [];
            if (!$cats) {
                return [];
            }
            $clauses = [];
            foreach ($cats as $i => $cat) {
                $clauses[] = "cc.id = :i$i OR " . $DB->sql_like('cc.path', ":p$i");
                $params["i$i"] = $cat->id;
                $params["p$i"] = $DB->sql_like_escape($cat->path) . '/%';
            }
            $join = 'JOIN {course_categories} cc ON cc.id = c.category';
            $where = '(' . implode(' OR ', $clauses) . ')';
        } else {
            $join = '';
            $where = '1 = 1';
        }
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT c.id FROM {course} c $join WHERE c.id <> :site AND $where ORDER BY c.id",
            $params
        ));
    }
}
