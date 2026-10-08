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
 * The run log: every run with its status, attempts, error and file sizes, and a Resend button.
 *
 * Metadata only. The log never holds CSV content.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportfeed\local\mailer;
use local_reportfeed\local\run_manager;
use local_reportfeed\local\schedules;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_reportfeed_log');

$status = optional_param('status', '', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$url = new moodle_url('/local/reportfeed/log.php', ['status' => $status]);
$PAGE->set_url($url);

$resend = optional_param('resend', 0, PARAM_INT);
if ($resend) {
    require_sesskey();
    $done = (new run_manager())->resend($resend);
    redirect(
        $url,
        get_string($done ? 'log_resent' : 'log_notresent', 'local_reportfeed'),
        null,
        $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

$where = $status === '' ? '1 = 1' : 'r.status = :status';
$params = ['status' => $status];
$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_reportfeed_run} r WHERE $where", $params);
$namefields = \core_user\fields::for_name()->get_sql('u', false, 'u_')->selects;
$runs = $DB->get_records_sql(
    "SELECT r.*, s.name AS schedulename, c.shortname AS courseshortname $namefields
       FROM {local_reportfeed_run} r
  LEFT JOIN {local_reportfeed_schedule} s ON s.id = r.scheduleid
  LEFT JOIN {course} c ON c.id = r.courseid
  LEFT JOIN {user} u ON u.id = r.userid
      WHERE $where
   ORDER BY r.id DESC",
    $params,
    $page * $perpage,
    $perpage
);

// Why deliveries were skipped, and which outside addresses each run went to, for the runs on this page.
$skipped = [];
$external = [];
if ($runs) {
    [$in, $inparams] = $DB->get_in_or_equal(array_keys($runs), SQL_PARAMS_NAMED);
    $rs = $DB->get_recordset_sql(
        "SELECT runid, reason, COUNT(1) AS n FROM {local_reportfeed_delivery}
          WHERE status = 'skipped' AND runid $in GROUP BY runid, reason",
        $inparams
    );
    foreach ($rs as $row) {
        $skipped[$row->runid][] = $row->n . ' ' . s($row->reason);
    }
    $rs->close();
    $rs = $DB->get_recordset_sql(
        "SELECT runid, email, MIN(status) AS status, MIN(reason) AS reason
           FROM {local_reportfeed_delivery}
          WHERE extid > 0 AND runid $in
       GROUP BY runid, email ORDER BY runid, email",
        $inparams
    );
    foreach ($rs as $row) {
        $external[$row->runid][] = s($row->email) . ' (' . s($row->status . ($row->reason ? ': ' . $row->reason : '')) . ')';
    }
    $rs->close();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('runlog', 'local_reportfeed'));
echo html_writer::div(get_string('log_note', 'local_reportfeed'), 'text-muted mb-3');

$options = ['' => get_string('all')];
foreach (['queued', 'running', 'sent', 'skipped', 'too_large', 'failed', 'expired'] as $s) {
    $options[$s] = $s;
}
echo $OUTPUT->single_select($url, 'status', $options, $status, null, 'filter');

$table = new html_table();
$table->head = array_map(
    fn($key) => get_string($key, 'local_reportfeed'),
    ['log_run', 'log_what', 'log_period', 'log_status', 'log_attempts', 'log_details', 'actions']
);
foreach ($runs as $run) {
    if ($run->type === 'hrfeed') {
        $what = s($run->schedulename ?? get_string('log_deletedschedule', 'local_reportfeed'))
            . ($run->manual ? ' (' . get_string('log_manual', 'local_reportfeed') . ')' : '');
    } else {
        $what = get_string('log_digest', 'local_reportfeed', (object) [
            'course' => s($run->courseshortname ?? '#' . $run->courseid),
            'user' => $run->u_firstname !== null ? fullname((object) [
                'firstname' => $run->u_firstname, 'lastname' => $run->u_lastname,
                'firstnamephonetic' => $run->u_firstnamephonetic, 'lastnamephonetic' => $run->u_lastnamephonetic,
                'middlename' => $run->u_middlename, 'alternatename' => $run->u_alternatename,
            ]) : '#' . $run->userid,
        ]);
    }
    $details = [];
    if ($run->error) {
        $details[] = html_writer::span(s($run->error), 'text-danger');
    }
    $summary = $run->summary ? json_decode($run->summary, true) : [];
    foreach ($summary['files'] ?? [] as $file => $meta) {
        $parts = (int) ($meta['parts'] ?? 1) > 1 ? ', ' . get_string('log_parts', 'local_reportfeed', (int) $meta['parts']) : '';
        $details[] = s($file) . ': ' . (int) $meta['rows'] . ' ' . get_string('log_rows', 'local_reportfeed') . ', '
            . display_size((int) $meta['bytes']) . $parts
            . ($meta['attach'] ? '' : ' (' . get_string('log_notattached', 'local_reportfeed') . ')');
    }
    if (!empty($skipped[$run->id])) {
        $details[] = get_string('log_skipped', 'local_reportfeed', implode(', ', $skipped[$run->id]));
    }
    if (!empty($summary['unavailable'])) {
        $details[] = get_string('log_unavailable', 'local_reportfeed', implode(', ', array_keys($summary['unavailable'])));
    }
    if (!empty($external[$run->id])) {
        $details[] = get_string('log_external', 'local_reportfeed', implode(', ', $external[$run->id]));
    }
    $action = '';
    if (in_array($run->status, run_manager::RESENDABLE, true)) {
        $action = $OUTPUT->single_button(
            new moodle_url($url, ['resend' => $run->id, 'sesskey' => sesskey()]),
            get_string('log_resend', 'local_reportfeed')
        );
    }
    $table->data[] = [
        $run->id,
        $what,
        mailer::date((int) $run->periodend),
        schedules::badge($run->status) . ($run->timefinished ? ', ' . userdate($run->timefinished) : ''),
        $run->attempts . '/' . run_manager::MAX_ATTEMPTS,
        implode('<br>', $details),
        $action,
    ];
}
if ($table->data) {
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
} else {
    echo $OUTPUT->notification(get_string('log_none', 'local_reportfeed'), \core\output\notification::NOTIFY_INFO);
}
$link = html_writer::link(new moodle_url('/local/reportfeed/schedules.php'), get_string('schedules', 'local_reportfeed'));
echo html_writer::div($link, 'mt-3');
echo $OUTPUT->footer();
