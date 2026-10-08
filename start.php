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
 * Getting started: the steps to get a first report out, with a live status for each.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportfeed\local\checklist;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_reportfeed_start');

$PAGE->set_url(new moodle_url('/local/reportfeed/start.php'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('start_title', 'local_reportfeed'));
echo html_writer::tag('p', get_string('start_intro', 'local_reportfeed'));

$badges = [
    checklist::DONE => 'bg-success',
    checklist::TODO => 'bg-danger',
    checklist::CHECK => 'bg-warning text-dark',
    checklist::INFO => 'bg-secondary',
];
$number = 0;
echo html_writer::start_tag('ol', ['class' => 'list-unstyled']);
foreach (checklist::steps() as $step) {
    $number++;
    $key = $step['key'];
    $state = get_string('start_state_' . $step['state'], 'local_reportfeed');
    $item = html_writer::tag('span', $state, ['class' => 'badge ' . $badges[$step['state']] . ' me-2'])
        . html_writer::tag('strong', get_string("start_{$key}", 'local_reportfeed'));
    $item .= html_writer::div(get_string("start_{$key}_body", 'local_reportfeed'), 'mt-1');
    if (get_string_manager()->string_exists("start_{$key}_{$step['state']}", 'local_reportfeed')) {
        $item .= html_writer::div(get_string("start_{$key}_{$step['state']}", 'local_reportfeed'), 'mt-1 fw-bold');
    }
    if ($step['url']) {
        $item .= html_writer::div(
            html_writer::link($step['url'], get_string("start_{$key}_link", 'local_reportfeed')),
            'mt-1'
        );
    }
    echo html_writer::tag('li', $item, ['class' => 'border rounded p-3 mb-2']);
}
echo html_writer::end_tag('ol');
echo html_writer::tag('p', get_string('start_privacy', 'local_reportfeed'), ['class' => 'text-muted']);
echo $OUTPUT->footer();
