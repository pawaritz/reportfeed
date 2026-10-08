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
 * Preview what a schedule's files hold: the columns in plain language and the first rows.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportfeed\local\contract;
use local_reportfeed\local\csv_writer;
use local_reportfeed\local\data_provider;
use local_reportfeed\local\activity_options;
use local_reportfeed\local\activity_provider;
use local_reportfeed\local\roster;
use local_reportfeed\local\run_manager;
use local_reportfeed\local\schedules;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_reportfeed_schedules');

$id = optional_param('id', 0, PARAM_INT);
$schedule = $DB->get_record('local_reportfeed_schedule', ['id' => $id], '*', MUST_EXIST);
$PAGE->set_url(new moodle_url('/local/reportfeed/preview.php', ['id' => $id]));

$identity = array_filter(explode(',', $schedule->identitycols));
$courseids = run_manager::course_ids_for($schedule);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('preview_title', 'local_reportfeed', s($schedule->name)));
echo html_writer::div(schedules::summary($schedule), 'text-muted mb-3');
echo $OUTPUT->notification(get_string('preview_note', 'local_reportfeed'), \core\output\notification::NOTIFY_INFO);

$label = fn(string $column, string $suffix = '') => get_string(
    get_string_manager()->string_exists("acol_$column$suffix", 'local_reportfeed') ? "acol_$column$suffix" : "col_$column$suffix",
    'local_reportfeed'
);
$opts = activity_options::parse($schedule->activityopts ?? null)['groups'];
$files = (new run_manager())->files_for($schedule);
$now = time();
$activity = new activity_provider();

foreach ($files as $file) {
    $columns = contract::columns($file, $identity, $opts[$file]['fields'] ?? []);
    echo $OUTPUT->heading(get_string('preview_file_' . $file, 'local_reportfeed'), 3);
    $guide = new html_table();
    $guide->head = [get_string('preview_column', 'local_reportfeed'), get_string('preview_label', 'local_reportfeed'),
        get_string('preview_meaning', 'local_reportfeed')];
    $guide->attributes['class'] = 'generaltable table-sm';
    foreach ($columns as $column) {
        $guide->data[] = [html_writer::tag('code', $column), $label($column), $label($column, '_desc')];
    }
    echo html_writer::table($guide);
    if ($file === 'activity_engagement') {
        [$from, $to] = run_manager::window($schedule, $now);
        echo html_writer::div(get_string('preview_window', 'local_reportfeed', (object) [
            'from' => userdate($from, get_string('strftimedatetimeshort')),
            'to' => userdate($to, get_string('strftimedatetimeshort')),
        ]), 'text-muted mb-3');
    }
}

// The first rows come from the first learners only: cheap even on a large site.
echo $OUTPUT->heading(get_string('preview_rows', 'local_reportfeed', 10), 3);
if (!$courseids) {
    echo $OUTPUT->notification(get_string('preview_nocourses', 'local_reportfeed'), \core\output\notification::NOTIFY_WARNING);
}
foreach ($courseids ? $files : [] as $file) {
    if ($file === 'learner_summary') {
        continue;
    }
    $columns = contract::columns($file, $identity, $opts[$file]['fields'] ?? []);
    $rows = match ($file) {
        'learner_course' => (new data_provider())->learner_course($courseids),
        'learner_roster' => roster::rows(new data_provider(), $courseids, (int) $schedule->id, null),
        'activity_completion' => $activity->completion($courseids, $opts[$file]),
        'activity_assignments' => $activity->assignments($courseids, $opts[$file]),
        'activity_quizzes' => $activity->quizzes($courseids, $opts[$file]),
        default => activity_provider::log_available()
            ? $activity->engagement($courseids, $opts[$file], ...run_manager::window($schedule, $now)) : [],
    };
    echo $OUTPUT->heading(get_string('preview_file_' . $file, 'local_reportfeed'), 4);
    $table = new html_table();
    $table->head = array_map('s', $columns);
    $table->attributes['class'] = 'generaltable table-sm';
    $types = array_map([contract::class, 'type'], $columns);
    $shown = 0;
    foreach ($rows as $row) {
        $cells = [];
        foreach ($columns as $i => $column) {
            $cells[] = s(csv_writer::format($row[$column] ?? null, $types[$i]));
        }
        $table->data[] = $cells;
        if (++$shown >= 10) {
            break;
        }
    }
    if ($table->data) {
        echo html_writer::div(html_writer::table($table), 'table-responsive');
    } else {
        echo $OUTPUT->notification(get_string('preview_norows', 'local_reportfeed'), \core\output\notification::NOTIFY_INFO);
    }
}
echo html_writer::div(
    html_writer::link(new moodle_url('/local/reportfeed/schedules.php'), get_string('schedules', 'local_reportfeed')),
    'mt-3'
);
echo $OUTPUT->footer();
