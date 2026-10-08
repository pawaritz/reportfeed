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

/**
 * Library callbacks for local_reportfeed.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the digest preferences to a course's navigation for teachers, when the site has the digest on.
 *
 * @param navigation_node $navigation the course navigation
 * @param stdClass $course
 * @param context $context
 */
function local_reportfeed_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    if (
        \local_reportfeed\local\digest_settings::mode() !== 'off'
        && has_capability('local/reportfeed:receiveteacherdigest', $context)
    ) {
        $navigation->add(
            get_string('digest_title', 'local_reportfeed'),
            new moodle_url('/local/reportfeed/digest.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'reportfeeddigest'
        );
    }
}
