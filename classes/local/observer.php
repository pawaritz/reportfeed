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
 * Event observers.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Moodle could not send an email. When it was a Reportfeed email, keep the reason for the home page.
     *
     * Only the error text is kept, never the email body or the address.
     *
     * @param \core\event\email_failed $event
     */
    public static function email_failed(\core\event\email_failed $event): void {
        if (strpos((string) ($event->other['subject'] ?? ''), '[Reportfeed]') !== 0) {
            return;
        }
        $error = \core_text::substr(clean_param((string) ($event->other['errorinfo'] ?? ''), PARAM_TEXT), 0, 300);
        set_config('lastmailerror', json_encode(['time' => time(), 'error' => $error]), 'local_reportfeed');
    }
}
