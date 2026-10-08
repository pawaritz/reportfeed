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
 * The learner roster: who is a learner in the schedule's courses now, and what changed since the last send.
 *
 * Built for people who keep a master list of learners (HR data) and want to match Moodle against it. A learner
 * is new (not in the previous sent roster), unchanged, or removed (was there, is not any more, with the reason).
 * Removed learners appear once, in the first roster after they left.
 *
 * Each run stores its own result, so a retry or a resend of that run sends the same roster, and the next run
 * compares itself with the last run that was actually sent.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class roster {
    /** @var string[] Why a learner was removed. */
    public const REASONS = ['account_deleted', 'account_suspended', 'not_enrolled', 'not_active_learner'];

    /** @var int Runs of one schedule whose roster is kept: enough for a retry and a baseline. */
    private const KEEP = 3;

    /** @var int Learners looked up together. */
    private const CHUNK = 500;

    /**
     * The roster rows in user id order.
     *
     * @param data_provider $provider
     * @param int[] $courseids the courses the schedule covers
     * @param int $scheduleid
     * @param int|null $runid the run to store the result for; null for a preview or test, which stores nothing
     * @return \Generator rows keyed by contract name
     */
    public static function rows(data_provider $provider, array $courseids, int $scheduleid, ?int $runid): \Generator {
        global $DB;
        $entries = self::entries($provider, $courseids, $scheduleid, $runid);
        foreach (array_chunk(array_keys($entries), self::CHUNK) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $users = $DB->get_records_select('user', "id $in", $params, '', 'id, idnumber, username, email, timecreated');
            foreach ($chunk as $userid) {
                $user = $users[$userid] ?? null;
                [$change, $reason] = $entries[$userid];
                yield [
                    'user_id' => $userid,
                    'idnumber' => $user->idnumber ?? '',
                    'username' => $user->username ?? '',
                    'email' => $user->email ?? '',
                    'status' => $change === 'removed' ? 'inactive' : 'active',
                    'change' => $change,
                    'reason' => $reason,
                    'account_created' => $user && $user->timecreated ? (int) $user->timecreated : null,
                ];
            }
        }
    }

    /**
     * Every learner of the roster with the change and reason, sorted by user id.
     *
     * @param data_provider $provider
     * @param int[] $courseids
     * @param int $scheduleid
     * @param int|null $runid
     * @return array<int,array{0:string,1:string}> user id => [change, reason]
     */
    private static function entries(data_provider $provider, array $courseids, int $scheduleid, ?int $runid): array {
        global $DB;
        if ($runid && $DB->record_exists('local_reportfeed_roster', ['runid' => $runid])) {
            $entries = [];
            $set = $DB->get_recordset('local_reportfeed_roster', ['runid' => $runid], 'userid', 'userid, changetype, reason');
            foreach ($set as $row) {
                $entries[(int) $row->userid] = [$row->changetype, (string) $row->reason];
            }
            $set->close();
            return $entries;
        }

        $current = [];
        foreach ($courseids as $courseid) {
            foreach ($provider->learner_ids(\context_course::instance((int) $courseid)) as $id) {
                $current[$id] = true;
            }
        }
        $previous = self::baseline($scheduleid, $runid);
        $entries = [];
        foreach (array_keys($current) as $id) {
            $entries[$id] = [isset($previous[$id]) ? 'unchanged' : 'new', ''];
        }
        $gone = array_diff_key($previous, $current);
        foreach (self::reasons(array_keys($gone), $courseids) as $id => $reason) {
            $entries[$id] = ['removed', $reason];
        }
        ksort($entries);

        if ($runid) {
            $batch = [];
            foreach ($entries as $id => [$change, $reason]) {
                $batch[] = (object) [
                    'scheduleid' => $scheduleid, 'runid' => $runid, 'userid' => $id,
                    'changetype' => $change, 'reason' => $reason === '' ? null : $reason,
                ];
                if (count($batch) >= 1000) {
                    $DB->insert_records('local_reportfeed_roster', $batch);
                    $batch = [];
                }
            }
            if ($batch) {
                $DB->insert_records('local_reportfeed_roster', $batch);
            }
            self::prune($scheduleid);
        }
        return $entries;
    }

    /**
     * The learners that were in the roster of the last run that was sent, as user id => true.
     *
     * A run that failed or went out without its file does not count: the receiver never had it.
     *
     * @param int $scheduleid
     * @param int|null $before only runs older than this one count; null for any
     * @return array<int,bool>
     */
    private static function baseline(int $scheduleid, ?int $before): array {
        global $DB;
        $runs = $DB->get_fieldset_sql(
            "SELECT r.id
               FROM {local_reportfeed_run} r
              WHERE r.scheduleid = :s AND r.type = 'hrfeed' AND r.status = 'sent' AND r.id < :before
                AND EXISTS (SELECT 1 FROM {local_reportfeed_roster} ro WHERE ro.runid = r.id)
           ORDER BY r.id DESC",
            ['s' => $scheduleid, 'before' => $before ?? PHP_INT_MAX],
            0,
            1
        );
        $ids = [];
        if ($runs) {
            $set = $DB->get_recordset_select(
                'local_reportfeed_roster',
                "runid = :r AND changetype <> 'removed'",
                ['r' => $runs[0]],
                '',
                'userid'
            );
            foreach ($set as $row) {
                $ids[(int) $row->userid] = true;
            }
            $set->close();
        }
        return $ids;
    }

    /**
     * Why each of the learners is no longer in the roster.
     *
     * @param int[] $userids
     * @param int[] $courseids the schedule's courses
     * @return array<int,string> user id => one of {@see REASONS}
     */
    private static function reasons(array $userids, array $courseids): array {
        global $DB;
        $out = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$in, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $users = $DB->get_records_select('user', "id $in", $params, '', 'id, deleted, suspended');
            $enrolled = [];
            if ($courseids) {
                [$cin, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
                $enrolled = $DB->get_fieldset_sql(
                    "SELECT DISTINCT ue.userid
                       FROM {user_enrolments} ue
                       JOIN {enrol} e ON e.id = ue.enrolid
                      WHERE ue.userid $in AND e.courseid $cin",
                    $params + $cparams
                );
            }
            $enrolled = array_flip(array_map('intval', $enrolled));
            foreach ($chunk as $id) {
                $user = $users[$id] ?? null;
                if (!$user || $user->deleted) {
                    $out[$id] = 'account_deleted';
                } else if ($user->suspended) {
                    $out[$id] = 'account_suspended';
                } else {
                    $out[$id] = isset($enrolled[$id]) ? 'not_active_learner' : 'not_enrolled';
                }
            }
        }
        return $out;
    }

    /**
     * Keep the roster of the newest runs of a schedule only.
     *
     * @param int $scheduleid
     */
    private static function prune(int $scheduleid): void {
        global $DB;
        $runs = $DB->get_fieldset_sql(
            'SELECT DISTINCT runid FROM {local_reportfeed_roster} WHERE scheduleid = ? ORDER BY runid DESC',
            [$scheduleid]
        );
        if (count($runs) > self::KEEP) {
            $oldest = $runs[self::KEEP - 1];
            $DB->delete_records_select('local_reportfeed_roster', 'scheduleid = ? AND runid < ?', [$scheduleid, $oldest]);
        }
    }
}
