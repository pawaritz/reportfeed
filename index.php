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
 * The Reportfeed home page: is it on, how far is the setup, what ran lately, and one click to everything else.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportfeed\local\dashboard;
use local_reportfeed\local\digest_settings;
use local_reportfeed\local\health;
use local_reportfeed\local\schedule_time;
use local_reportfeed\local\schedules;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_reportfeed_home');

$url = new moodle_url('/local/reportfeed/index.php');
$PAGE->set_url($url);
$canconfig = has_capability('moodle/site:config', context_system::instance());

$switch = optional_param('switch', '', PARAM_ALPHA);
if ($canconfig && in_array($switch, ['on', 'off'], true)) {
    require_sesskey();
    set_config('enabled', $switch === 'on' ? 1 : 0, 'local_reportfeed');
    health::record_switch();
    redirect(
        $url,
        get_string($switch === 'on' ? 'home_switched_on' : 'home_switched_off', 'local_reportfeed'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_reportfeed'));
echo dashboard::nav('home');

// On or off, and the next thing to do.
$enabled = (bool) get_config('local_reportfeed', 'enabled');
$progress = dashboard::progress();
$percent = $progress['total'] ? (int) round(100 * $progress['done'] / $progress['total']) : 0;
$status = html_writer::tag('h3', get_string($enabled ? 'home_on' : 'home_off', 'local_reportfeed'), ['class' => 'h5']);
if ($canconfig) {
    $status .= $OUTPUT->single_button(
        new moodle_url($url, ['switch' => $enabled ? 'off' : 'on', 'sesskey' => sesskey()]),
        get_string($enabled ? 'home_turnoff' : 'home_turnon', 'local_reportfeed')
    );
}
$status .= html_writer::div(
    html_writer::div('', 'progress-bar', ['style' => "width: {$percent}%", 'role' => 'progressbar']),
    'progress mt-3',
    ['title' => $percent . '%']
);
$status .= html_writer::div(
    get_string('home_progress', 'local_reportfeed', (object) ['done' => $progress['done'], 'total' => $progress['total']]),
    'mt-1'
);
if ($progress['next']) {
    $key = $progress['next']['key'];
    $next = html_writer::tag('strong', get_string('home_next', 'local_reportfeed'))
        . ' ' . get_string("start_{$key}", 'local_reportfeed');
    if ($progress['next']['url']) {
        $next .= ' ' . html_writer::link($progress['next']['url'], get_string("start_{$key}_link", 'local_reportfeed'));
    }
    $status .= html_writer::div($next, 'mt-2');
} else {
    $status .= html_writer::div(get_string('home_alldone', 'local_reportfeed'), 'mt-2');
}
echo html_writer::div($status, 'border rounded p-3 mb-3');

foreach (health::warnings() as $warning) {
    echo $OUTPUT->notification(get_string($warning, 'local_reportfeed'), \core\output\notification::NOTIFY_WARNING);
}
if ($problem = dashboard::last_mail_error()) {
    echo $OUTPUT->notification(get_string('home_mailerror', 'local_reportfeed', (object) [
        'when' => userdate($problem['time']), 'error' => s($problem['error']),
    ]), \core\output\notification::NOTIFY_ERROR);
}

// Main buttons.
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/reportfeed/schedule.php'),
        get_string('schedule_add', 'local_reportfeed'),
        'get',
        ['type' => single_button::BUTTON_PRIMARY]
    )
    . $OUTPUT->single_button(new moodle_url('/local/reportfeed/schedules.php'), get_string('schedules', 'local_reportfeed'), 'get')
    . $OUTPUT->single_button(new moodle_url('/local/reportfeed/log.php'), get_string('runlog', 'local_reportfeed'), 'get')
    . $OUTPUT->single_button(new moodle_url('/local/reportfeed/start.php'), get_string('start_title', 'local_reportfeed'), 'get'),
    'd-flex flex-wrap gap-2 mb-3'
);

// Schedules.
echo $OUTPUT->heading(get_string('schedules', 'local_reportfeed'), 3, 'h5');
$rows = $DB->get_records('local_reportfeed_schedule', null, 'name ASC');
if (!$rows) {
    echo $OUTPUT->notification(get_string('schedules_none', 'local_reportfeed'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('schedule_name', 'local_reportfeed'), get_string('schedule_when', 'local_reportfeed'),
        get_string('home_sendsto', 'local_reportfeed'), get_string('schedule_last', 'local_reportfeed'),
    ];
    foreach ($rows as $schedule) {
        $name = html_writer::link(
            new moodle_url('/local/reportfeed/schedule.php', ['id' => $schedule->id]),
            s($schedule->name)
        ) . ($schedule->enabled ? '' : ' (' . get_string('disabled', 'local_reportfeed') . ')');
        $when = schedules::describe($schedule);
        if ($schedule->enabled) {
            $when .= html_writer::div(
                get_string('schedule_next', 'local_reportfeed', userdate(schedule_time::next_due_for($schedule, time()))),
                'text-muted small'
            );
        }
        $table->data[] = [
            $name,
            $when,
            get_string('home_people', 'local_reportfeed', dashboard::recipient_count($schedule->id)),
            $schedule->laststatus ? schedules::badge($schedule->laststatus) . ' ' . userdate($schedule->lastrun) : '-',
        ];
    }
    echo html_writer::table($table);
    echo html_writer::tag('p', get_string('home_testhint', 'local_reportfeed'), ['class' => 'text-muted small']);
}

// Recent runs.
echo $OUTPUT->heading(get_string('home_recent', 'local_reportfeed'), 3, 'h5');
$runs = dashboard::recent_runs();
if (!$runs) {
    echo html_writer::tag('p', get_string('home_norun', 'local_reportfeed'), ['class' => 'text-muted']);
} else {
    $table = new html_table();
    $table->head = [
        get_string('log_run', 'local_reportfeed'), get_string('log_what', 'local_reportfeed'),
        get_string('log_status', 'local_reportfeed'), get_string('log_details', 'local_reportfeed'),
    ];
    foreach ($runs as $run) {
        $what = $run->type === 'hrfeed'
            ? s($run->schedulename ?? get_string('log_deletedschedule', 'local_reportfeed'))
                . ($run->manual ? ' (' . get_string('log_manual', 'local_reportfeed') . ')' : '')
            : get_string('home_digestrun', 'local_reportfeed');
        $table->data[] = [
            userdate($run->timefinished ?: $run->timecreated),
            $what,
            schedules::badge($run->status),
            $run->error ? html_writer::span(s($run->error), 'text-danger') : '',
        ];
    }
    echo html_writer::table($table);
    echo html_writer::link(new moodle_url('/local/reportfeed/log.php'), get_string('home_alllog', 'local_reportfeed'));
}

// Teacher digest.
echo $OUTPUT->heading(get_string('digest_title', 'local_reportfeed'), 3, 'h5 mt-4');
echo html_writer::tag('p', get_string('home_digest_' . digest_settings::mode(), 'local_reportfeed'));
echo $OUTPUT->footer();
