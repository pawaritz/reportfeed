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
 * The "Getting started" checklist: what an administrator has to do, in order, and what is already done.
 *
 * Every step is worked out from live site data, so the page is a status board and not a static guide.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checklist {
    /** @var string The step is in place. */
    public const DONE = 'done';

    /** @var string The step still has to be done, and reports will not arrive without it. */
    public const TODO = 'todo';

    /** @var string The step may be fine, but a person should look. */
    public const CHECK = 'check';

    /** @var string An action with nothing to measure. */
    public const INFO = 'info';

    /**
     * The steps in the order an administrator works through them.
     *
     * @return array[] each: key (language string stem), state, url (\moodle_url|null)
     */
    public static function steps(): array {
        global $CFG, $DB;

        $mailurl = new \moodle_url('/admin/settings.php', ['section' => 'outgoingmailconfig']);
        if (!empty($CFG->noemailever)) {
            $mail = self::TODO;
        } else {
            $mail = empty($CFG->smtphosts) ? self::CHECK : self::DONE;
        }

        $task = \core\task\manager::get_scheduled_task(\local_reportfeed\task\dispatch_task::class);
        $cron = $task && $task->get_last_run_time() >= time() - HOURSECS ? self::DONE : self::TODO;

        $schedules = $DB->count_records('local_reportfeed_schedule', ['enabled' => 1]);
        $bare = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_reportfeed_schedule} s
              WHERE s.enabled = 1
                AND NOT EXISTS (SELECT 1 FROM {local_reportfeed_recipient} r WHERE r.scheduleid = s.id)
                AND NOT EXISTS (SELECT 1 FROM {local_reportfeed_extrecipient} e WHERE e.scheduleid = s.id)"
        );
        if (!$schedules) {
            $recipients = self::TODO;
        } else {
            $recipients = $bare ? self::CHECK : self::DONE;
        }

        $sent = $DB->record_exists_select('local_reportfeed_run', "type = 'hrfeed' AND status = 'sent'");
        $failed = $DB->record_exists_select('local_reportfeed_run', "type = 'hrfeed' AND status IN ('failed', 'too_large')");
        if ($sent) {
            $first = self::DONE;
        } else {
            $first = $failed ? self::CHECK : self::INFO;
        }

        return [
            ['key' => 'mail', 'state' => $mail, 'url' => $mailurl],
            ['key' => 'attachments', 'state' => health::attachments_enabled() ? self::DONE : self::TODO, 'url' => $mailurl],
            [
                'key' => 'cron', 'state' => $cron,
                'url' => new \moodle_url('/admin/tool/task/scheduledtasks.php'),
            ],
            [
                'key' => 'enable', 'state' => get_config('local_reportfeed', 'enabled') ? self::DONE : self::TODO,
                'url' => new \moodle_url('/admin/settings.php', ['section' => 'local_reportfeed']),
            ],
            [
                'key' => 'receiver', 'state' => self::receiver_exists() ? self::DONE : self::TODO,
                'url' => new \moodle_url('/admin/roles/manage.php'),
            ],
            [
                'key' => 'schedule', 'state' => $schedules ? self::DONE : self::TODO,
                'url' => new \moodle_url('/local/reportfeed/schedule.php'),
            ],
            ['key' => 'recipients', 'state' => $recipients, 'url' => new \moodle_url('/local/reportfeed/schedules.php')],
            ['key' => 'test', 'state' => self::INFO, 'url' => new \moodle_url('/local/reportfeed/schedules.php')],
            ['key' => 'firstrun', 'state' => $first, 'url' => new \moodle_url('/local/reportfeed/log.php')],
            [
                'key' => 'teachers', 'state' => digest_settings::mode() === 'off' ? self::INFO : self::DONE,
                'url' => new \moodle_url('/admin/settings.php', ['section' => 'local_reportfeed']),
            ],
        ];
    }

    /**
     * Whether any role that is allowed to receive the HR feed is assigned to somebody in the site context.
     *
     * @return bool
     */
    public static function receiver_exists(): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {role_capabilities} rc
               JOIN {role_assignments} ra ON ra.roleid = rc.roleid
               JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
              WHERE rc.capability = :cap AND rc.permission = :allow AND ra.contextid = :ctx",
            [
                'cap' => 'local/reportfeed:receivehrfeed', 'allow' => CAP_ALLOW,
                'ctx' => \context_system::instance()->id,
            ]
        );
    }
}
