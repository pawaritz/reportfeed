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
 * A teacher's weekly digest preferences for one course.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportfeed\local\digest_settings;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/reportfeed:receiveteacherdigest', $context);

$url = new moodle_url('/local/reportfeed/digest.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('digest_title', 'local_reportfeed'));
$PAGE->set_heading($course->fullname);

$off = digest_settings::mode() === 'off';
if (!$off) {
    $form = new \local_reportfeed\form\digest_form($url, ['courseid' => $courseid]);
    if ($form->is_cancelled()) {
        redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
    }
    if ($data = $form->get_data()) {
        digest_settings::save((int) $USER->id, $courseid, $data);
        digest_settings::nominate((int) $USER->id, $courseid, (array) ($data->nominees ?? []));
        redirect($url, get_string('digest_saved', 'local_reportfeed'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $form->set_data(digest_settings::load((int) $USER->id, $courseid));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('digest_title', 'local_reportfeed'));
if ($off) {
    echo $OUTPUT->notification(get_string('digest_siteoff', 'local_reportfeed'), \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::div(get_string('digest_intro', 'local_reportfeed'), 'mb-3');
    $form->display();
}
echo $OUTPUT->footer();
