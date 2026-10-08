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
 * List HR feed schedules, with Run now, Send test, edit and delete.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportfeed\local\health;
use local_reportfeed\local\run_manager;
use local_reportfeed\local\schedule_time;
use local_reportfeed\local\schedules;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_reportfeed_schedules');

$url = new moodle_url('/local/reportfeed/schedules.php');
$PAGE->set_url($url);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

if ($action && $id) {
    $schedule = $DB->get_record('local_reportfeed_schedule', ['id' => $id], '*', MUST_EXIST);
    if ($action === 'delete' && optional_param('confirm', 0, PARAM_INT)) {
        require_sesskey();
        schedules::delete($id);
        redirect($url, get_string('schedule_deleted', 'local_reportfeed'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($action === 'delete') {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('schedule_deleteconfirm', 'local_reportfeed', s($schedule->name)),
            new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
            $url
        );
        echo $OUTPUT->footer();
        die();
    }
    require_sesskey();
    if ($action === 'runnow') {
        $queued = $schedule->enabled && (new run_manager())->run_now($id);
        $message = $queued ? 'schedule_runqueued' : ($schedule->enabled ? 'schedule_runexists' : 'schedule_rundisabled');
        redirect(
            $url,
            get_string($message, 'local_reportfeed'),
            null,
            $queued ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    } else if ($action === 'test') {
        try {
            $count = (new run_manager())->send_test($id, $USER);
            redirect(
                $url,
                get_string('schedule_testsent', 'local_reportfeed', $count),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('schedules', 'local_reportfeed'));
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/local/reportfeed/start.php'),
    get_string('start_healthlink', 'local_reportfeed')
), ['class' => 'small']);
foreach (health::warnings() as $warning) {
    echo $OUTPUT->notification(get_string($warning, 'local_reportfeed'), \core\output\notification::NOTIFY_WARNING);
}

$table = new html_table();
$table->head = array_map(
    fn($key) => get_string($key, 'local_reportfeed'),
    ['schedule_name', 'schedule_when', 'schedule_last', 'actions']
);
foreach ($DB->get_records('local_reportfeed_schedule', null, 'name ASC') as $schedule) {
    $buttons = [];
    $buttons[] = html_writer::link(
        new moodle_url('/local/reportfeed/schedule.php', ['id' => $schedule->id]),
        get_string('edit')
    );
    $buttons[] = html_writer::link(
        new moodle_url('/local/reportfeed/preview.php', ['id' => $schedule->id]),
        get_string('preview', 'local_reportfeed')
    );
    foreach (['runnow' => 'schedule_runnow', 'test' => 'schedule_test'] as $do => $label) {
        $buttons[] = $OUTPUT->single_button(
            new moodle_url($url, ['action' => $do, 'id' => $schedule->id, 'sesskey' => sesskey()]),
            get_string($label, 'local_reportfeed')
        );
    }
    $buttons[] = html_writer::link(new moodle_url($url, ['action' => 'delete', 'id' => $schedule->id]), get_string('delete'));
    $when = schedules::describe($schedule);
    if ($schedule->enabled) {
        $when .= html_writer::div(
            get_string('schedule_next', 'local_reportfeed', userdate(schedule_time::next_due_for($schedule, time()))),
            'text-muted small'
        );
    }
    $table->data[] = [
        html_writer::tag('strong', s($schedule->name))
            . ($schedule->enabled ? '' : ' (' . get_string('disabled', 'local_reportfeed') . ')')
            . html_writer::div(schedules::summary($schedule), 'text-muted small'),
        $when,
        $schedule->laststatus ? schedules::badge($schedule->laststatus) . ' ' . userdate($schedule->lastrun) : '-',
        implode(' ', $buttons),
    ];
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('schedules_none', 'local_reportfeed'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->single_button(
    new moodle_url('/local/reportfeed/schedule.php'),
    get_string('schedule_add', 'local_reportfeed'),
    'get'
);
$link = html_writer::link(new moodle_url('/local/reportfeed/log.php'), get_string('runlog', 'local_reportfeed'));
echo html_writer::div($link, 'mt-3');
echo $OUTPUT->footer();
