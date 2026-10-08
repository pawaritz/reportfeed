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
 * Site health checks that decide whether a run may send.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health {
    /**
     * Whether Moodle is allowed to attach files to outgoing email.
     *
     * When this is off, Moodle sends the email without the attachment and raises no error,
     * so a run must refuse to send (ADR-003, change C2).
     *
     * @return bool
     */
    public static function attachments_enabled(): bool {
        global $CFG;
        return !empty($CFG->allowattachments);
    }

    /**
     * Remember when Reportfeed or the teacher mode was last switched. The dispatcher sends nothing for a
     * scheduled moment before that, so switching it on at 15:00 never sends this morning's report.
     */
    public static function record_switch(): void {
        set_config('switchtime', time(), 'local_reportfeed');
    }

    /**
     * Language string keys for everything that currently stops or may stop reports from arriving.
     *
     * @return string[] keys in the plugin language file
     */
    public static function warnings(): array {
        $warnings = [];
        if (!get_config('local_reportfeed', 'enabled')) {
            $warnings[] = 'health_disabled';
        }
        if (!self::attachments_enabled()) {
            $warnings[] = 'health_attachments_desc';
        }
        global $DB;
        if (!external::allowed() && $DB->record_exists('local_reportfeed_extrecipient', [])) {
            $warnings[] = 'health_external_off';
        }
        $engagement = array_filter(
            $DB->get_fieldset_select('local_reportfeed_schedule', 'activityopts', 'enabled = 1 AND activityopts IS NOT NULL'),
            fn($json) => isset(activity_options::parse($json)['groups']['activity_engagement'])
        );
        if ($engagement) {
            $lifetime = (int) get_config('logstore_standard', 'loglifetime');
            if (!activity_provider::log_available()) {
                $warnings[] = 'health_nolog';
            } else if ($lifetime > 0 && $lifetime < 62) {
                $warnings[] = 'health_logretention';
            }
        }
        $task = \core\task\manager::get_scheduled_task(\local_reportfeed\task\dispatch_task::class);
        if ($task && $task->get_last_run_time() < time() - HOURSECS) {
            $warnings[] = 'health_cron';
        }
        return $warnings;
    }
}
