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
 * List course bundles, with edit and delete.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportfeed\local\bundles;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_reportfeed_bundles');

$url = new moodle_url('/local/reportfeed/bundles.php');
$PAGE->set_url($url);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action === 'delete' && $id) {
    $bundle = $DB->get_record('local_reportfeed_bundle', ['id' => $id], '*', MUST_EXIST);
    if (optional_param('confirm', 0, PARAM_INT)) {
        require_sesskey();
        if (bundles::delete($id)) {
            redirect($url, get_string('bundle_deleted', 'local_reportfeed'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect(
            $url,
            get_string('bundle_inuse', 'local_reportfeed', implode(', ', array_map('s', bundles::used_by($id)))),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('bundle_deleteconfirm', 'local_reportfeed', s($bundle->name)),
        new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bundles', 'local_reportfeed'));
echo html_writer::div(get_string('bundles_intro', 'local_reportfeed'), 'text-muted mb-3');

$table = new html_table();
$table->head = array_map(
    fn($key) => get_string($key, 'local_reportfeed'),
    ['bundle_name', 'bundle_coursecount', 'bundle_usedby', 'actions']
);
foreach ($DB->get_records('local_reportfeed_bundle', null, 'name ASC') as $bundle) {
    $users = bundles::used_by((int) $bundle->id);
    $table->data[] = [
        s($bundle->name),
        count(bundles::course_ids((int) $bundle->id)),
        $users ? implode(', ', array_map('s', $users)) : '-',
        html_writer::link(new moodle_url('/local/reportfeed/bundle.php', ['id' => $bundle->id]), get_string('edit')) . ' '
            . html_writer::link(new moodle_url($url, ['action' => 'delete', 'id' => $bundle->id]), get_string('delete')),
    ];
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('bundles_none', 'local_reportfeed'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->single_button(new moodle_url('/local/reportfeed/bundle.php'), get_string('bundle_add', 'local_reportfeed'), 'get');
echo $OUTPUT->footer();
