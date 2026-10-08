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

namespace local_reportfeed\form;

use local_reportfeed\local\bundles;
use local_reportfeed\local\activity_options;
use local_reportfeed\local\activity_provider;
use local_reportfeed\local\contract;
use local_reportfeed\local\external;
use local_reportfeed\local\schedule_time;
use local_reportfeed\local\schedules;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Edit form for one HR feed schedule.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('schedule_name', 'local_reportfeed'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', null, 'maxlength', 255, 'client');

        $mform->addElement('advcheckbox', 'enabled', get_string('schedule_enabled', 'local_reportfeed'));
        $mform->setDefault('enabled', 1);

        $days = [];
        foreach (schedules::DAYS as $i => $day) {
            $days[$i] = get_string($day, 'calendar');
        }
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = sprintf('%02d', $h);
        }
        $minutes = [];
        for ($m = 0; $m < 60; $m += 5) {
            $minutes[$m] = sprintf('%02d', $m);
        }
        $mform->addElement('select', 'frequency', get_string('schedule_frequency', 'local_reportfeed'), [
            'weekly' => get_string('freq_weekly', 'local_reportfeed'),
            'biweekly' => get_string('freq_biweekly', 'local_reportfeed'),
            'semimonthly' => get_string('freq_semimonthly', 'local_reportfeed'),
            'monthly' => get_string('freq_monthly', 'local_reportfeed'),
        ]);
        $mform->addHelpButton('frequency', 'schedule_frequency', 'local_reportfeed');

        $rules = [];
        foreach (schedule_time::MONTHRULES as $rule) {
            $rules[$rule] = get_string('rule_' . $rule, 'local_reportfeed');
        }
        $mform->addElement('select', 'monthrule', get_string('schedule_monthrule', 'local_reportfeed'), $rules);
        $mform->hideIf('monthrule', 'frequency', 'neq', 'monthly');
        $daysofmonth = array_combine(range(1, 31), range(1, 31));
        $mform->addElement('select', 'monthday', get_string('schedule_monthday', 'local_reportfeed'), $daysofmonth);
        $mform->addHelpButton('monthday', 'schedule_monthday', 'local_reportfeed');
        $mform->hideIf('monthday', 'frequency', 'neq', 'monthly');
        $mform->hideIf('monthday', 'monthrule', 'neq', 'day');

        $mform->addElement('select', 'weekday', get_string('schedule_weekday', 'local_reportfeed'), $days);
        $mform->hideIf('weekday', 'frequency', 'in', 'semimonthly|monthly');
        $mform->setDefault('weekday', 1);
        $mform->addElement('select', 'dow', get_string('schedule_dow', 'local_reportfeed'), $days);
        $mform->hideIf('dow', 'frequency', 'neq', 'monthly');
        $mform->hideIf('dow', 'monthrule', 'in', 'day|lastday|firstworkday|lastworkday');
        $mform->setDefault('dow', 1);
        $mform->addElement('date_selector', 'anchor', get_string('schedule_anchor', 'local_reportfeed'));
        $mform->addHelpButton('anchor', 'schedule_anchor', 'local_reportfeed');
        $mform->hideIf('anchor', 'frequency', 'neq', 'biweekly');

        $when = [
            $mform->createElement('select', 'hour', '', $hours),
            $mform->createElement('select', 'minute', '', $minutes),
        ];
        $mform->addGroup($when, 'when', get_string('schedule_when', 'local_reportfeed'), ' : ', false);
        $mform->addHelpButton('when', 'schedule_when', 'local_reportfeed');
        $mform->setDefault('hour', 7);
        $mform->setDefault('minute', 0);

        $mform->addElement('select', 'scope', get_string('schedule_scope', 'local_reportfeed'), [
            'all' => get_string('scope_all', 'local_reportfeed'),
            'categories' => get_string('scope_categories', 'local_reportfeed'),
            'courses' => get_string('scope_courses', 'local_reportfeed'),
            'bundle' => get_string('scope_bundle', 'local_reportfeed'),
        ]);
        $mform->addElement(
            'autocomplete',
            'categoryids',
            get_string('scope_categories', 'local_reportfeed'),
            \core_course_category::make_categories_list(),
            ['multiple' => true]
        );
        $mform->setType('categoryids', PARAM_INT);
        $mform->hideIf('categoryids', 'scope', 'neq', 'categories');
        $mform->addElement(
            'course',
            'courseids',
            get_string('scope_courses', 'local_reportfeed'),
            ['multiple' => true, 'includefrontpage' => false]
        );
        $mform->setType('courseids', PARAM_INT);
        $mform->hideIf('courseids', 'scope', 'neq', 'courses');
        $mform->addElement('select', 'bundleid', get_string('scope_bundle', 'local_reportfeed'), bundles::menu());
        $mform->hideIf('bundleid', 'scope', 'neq', 'bundle');
        $mform->addHelpButton('bundleid', 'scope_bundle', 'local_reportfeed');

        $mform->addElement('autocomplete', 'recipients', get_string('schedule_recipients', 'local_reportfeed'), [], [
            'multiple' => true,
            'ajax' => 'core_user/form_user_selector',
            'valuehtmlcallback' => function ($userid) {
                global $OUTPUT;
                // An empty picker submits a marker string instead of an id.
                $user = is_numeric($userid) ? \core_user::get_user($userid) : null;
                if (!$user) {
                    return false;
                }
                return $OUTPUT->render_from_template('core_user/form_user_selector_suggestion', [
                    'fullname' => fullname($user),
                    'extrafields' => [(object) ['name' => 'email', 'value' => $user->email]],
                ]);
            },
        ]);
        $mform->setType('recipients', PARAM_RAW);
        $mform->addHelpButton('recipients', 'schedule_recipients', 'local_reportfeed');

        if (external::can_edit()) {
            $mform->addElement('static', 'externalwarning', '', \html_writer::div(
                get_string('external_warning', 'local_reportfeed'),
                'alert alert-warning mb-0'
            ));
            $mform->addElement('textarea', 'externalemails', get_string('schedule_external', 'local_reportfeed'), [
                'rows' => 4, 'cols' => 50,
            ]);
            $mform->setType('externalemails', PARAM_RAW);
            $mform->addHelpButton('externalemails', 'schedule_external', 'local_reportfeed');
        } else if (!external::allowed() && !empty($this->_customdata['hasexternal'])) {
            $mform->addElement('static', 'externaloff', '', \html_writer::div(
                get_string('external_off', 'local_reportfeed'),
                'alert alert-info mb-0'
            ));
        }

        $identity = [];
        foreach (contract::IDENTITY as $column) {
            $identity[$column] = $column;
        }
        $mform->addElement(
            'autocomplete',
            'identitycols',
            get_string('schedule_identity', 'local_reportfeed'),
            $identity,
            ['multiple' => true]
        );
        $mform->setType('identitycols', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('identitycols', 'schedule_identity', 'local_reportfeed');
        $mform->setDefault('identitycols', ['idnumber']);

        $mform->addElement('select', 'format', get_string('schedule_format', 'local_reportfeed'), [
            'csv' => get_string('format_csv', 'local_reportfeed'),
            'xlsx' => get_string('format_xlsx', 'local_reportfeed'),
        ]);
        $mform->addHelpButton('format', 'schedule_format', 'local_reportfeed');
        $mform->addElement('advcheckbox', 'usebom', get_string('schedule_usebom', 'local_reportfeed'));
        $mform->hideIf('usebom', 'format', 'eq', 'xlsx');
        $mform->addElement('advcheckbox', 'zip', get_string('schedule_zip', 'local_reportfeed'));
        $mform->addHelpButton('zip', 'schedule_zip', 'local_reportfeed');

        $this->hr_section($mform);
        $this->activity_section($mform);

        $this->add_action_buttons();
    }

    /**
     * The core HR files: a tick per file. At least one file in all must be chosen, here or below.
     *
     * @param \MoodleQuickForm $mform
     */
    private function hr_section(\MoodleQuickForm $mform): void {
        $mform->addElement('header', 'hrhdr', get_string('hr_header', 'local_reportfeed'));
        $mform->addElement('static', 'hrintro', '', get_string('hr_intro', 'local_reportfeed'));
        foreach (['course' => 'learner_course', 'summary' => 'learner_summary', 'roster' => 'learner_roster'] as $tick => $file) {
            $mform->addElement('advcheckbox', "hr_$tick", get_string("hr_$file", 'local_reportfeed'));
            $mform->addHelpButton("hr_$tick", "hr_$file", 'local_reportfeed');
            $mform->setDefault("hr_$tick", (int) in_array($file, contract::HR_DEFAULT, true));
        }
    }

    /**
     * The optional activity-level files: a tick per file, then the fields and filter of each one.
     *
     * @param \MoodleQuickForm $mform
     */
    private function activity_section(\MoodleQuickForm $mform): void {
        $mform->addElement('header', 'activityhdr', get_string('activity_header', 'local_reportfeed'));
        $mform->addElement('static', 'activityintro', '', get_string('activity_intro', 'local_reportfeed'));
        foreach (contract::ACTIVITY_FILES as $file) {
            $short = activity_options::short($file);
            $mform->addElement('advcheckbox', "act_$short", get_string("file_$file", 'local_reportfeed'));
            $mform->addHelpButton("act_$short", "file_$file", 'local_reportfeed');
            $fields = [];
            foreach (contract::activity_fields($file) as $column) {
                $fields[$column] = get_string(
                    get_string_manager()->string_exists("acol_$column", 'local_reportfeed') ? "acol_$column" : "col_$column",
                    'local_reportfeed'
                );
            }
            $mform->addElement(
                'autocomplete',
                "actfields_$short",
                get_string('activity_fields', 'local_reportfeed'),
                $fields,
                ['multiple' => true]
            );
            $mform->setType("actfields_$short", PARAM_ALPHANUMEXT);
            $mform->setDefault("actfields_$short", array_keys($fields));
            $mform->hideIf("actfields_$short", "act_$short", 'notchecked');
            if (count(activity_options::FILTERS[$file]) > 1) {
                $filters = [];
                foreach (activity_options::FILTERS[$file] as $filter) {
                    $filters[$filter] = get_string("filter_{$short}_$filter", 'local_reportfeed');
                }
                $mform->addElement('select', "actfilter_$short", get_string('activity_filter', 'local_reportfeed'), $filters);
                $mform->hideIf("actfilter_$short", "act_$short", 'notchecked');
            }
            if ($file === 'activity_completion') {
                $types = [];
                foreach (array_keys(\core_component::get_plugin_list('mod')) as $mod) {
                    $types[$mod] = get_string('pluginname', "mod_$mod");
                }
                \core_collator::asort($types);
                $mform->addElement(
                    'autocomplete',
                    'actmodtypes',
                    get_string('activity_modtypes', 'local_reportfeed'),
                    $types,
                    ['multiple' => true, 'placeholder' => get_string('activity_modtypes_all', 'local_reportfeed')]
                );
                $mform->setType('actmodtypes', PARAM_ALPHANUMEXT);
                $mform->hideIf('actmodtypes', 'act_completion', 'notchecked');
            }
        }
        $windows = ['' => get_string('window_default', 'local_reportfeed')];
        foreach (activity_options::WINDOWS as $window) {
            $windows[$window] = get_string("window_$window", 'local_reportfeed');
        }
        $mform->addElement('select', 'actwindow', get_string('activity_window', 'local_reportfeed'), $windows);
        $mform->addHelpButton('actwindow', 'activity_window', 'local_reportfeed');
        $mform->hideIf('actwindow', 'act_engagement', 'notchecked');
        if (!activity_provider::log_available()) {
            $mform->addElement('static', 'nolog', '', \html_writer::div(
                get_string('activity_nolog', 'local_reportfeed'),
                'alert alert-warning mb-0'
            ));
            $mform->hideIf('nolog', 'act_engagement', 'notchecked');
        }
    }

    /**
     * Server-side checks.
     *
     * @param array $data
     * @param array $files
     * @return array errors by field
     */
    public function validation($data, $files): array {
        return parent::validation($data, $files) + schedules::errors($data);
    }
}
