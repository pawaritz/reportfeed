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
 * Edit one course bundle.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_reportfeed_bundles');

$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/reportfeed/bundle.php', ['id' => $id]);
$list = new moodle_url('/local/reportfeed/bundles.php');
$PAGE->set_url($url);

// An id that does not exist stops here, before any form data can be saved against it.
$existing = $id ? \local_reportfeed\local\bundles::load($id) : null;

$form = new \local_reportfeed\form\bundle_form($url);
if ($form->is_cancelled()) {
    redirect($list);
}
if ($data = $form->get_data()) {
    $data->id = $id;
    \local_reportfeed\local\bundles::save($data);
    redirect($list, get_string('bundle_saved', 'local_reportfeed'), null, \core\output\notification::NOTIFY_SUCCESS);
}
if ($existing) {
    $form->set_data($existing);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($id ? 'bundle_edit' : 'bundle_add', 'local_reportfeed'));
$form->display();
echo $OUTPUT->footer();
