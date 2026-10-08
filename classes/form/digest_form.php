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

use local_reportfeed\local\contract;
use local_reportfeed\local\digest_settings;
use local_reportfeed\local\schedules;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A teacher's digest preferences for one course.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digest_form extends \moodleform {
    /**
     * Form fields. Custom data: courseid.
     */
    protected function definition(): void {
        global $USER;
        $mform = $this->_form;
        $courseid = (int) $this->_customdata['courseid'];
        $context = \context_course::instance($courseid);

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('advcheckbox', 'enabled', get_string('digest_enabled', 'local_reportfeed'));

        $days = [];
        foreach (schedules::DAYS as $i => $day) {
            $days[$i] = get_string($day, 'calendar');
        }
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = sprintf('%02d:00', $h);
        }
        $mform->addGroup([
            $mform->createElement('select', 'weekday', '', $days),
            $mform->createElement('select', 'hour', '', $hours),
        ], 'when', get_string('digest_when', 'local_reportfeed'), ' ', false);
        $mform->addHelpButton('when', 'digest_when', 'local_reportfeed');

        // Grade columns only for a teacher who may see grades; the data provider enforces it again at send time.
        $cangrades = has_capability('moodle/grade:viewall', $context);
        $choices = [];
        foreach (contract::DIGEST_CHOICES as $column) {
            if ($cangrades || !in_array($column, contract::GRADE_COLUMNS, true)) {
                $choices[$column] = $column;
            }
        }
        $mform->addElement('autocomplete', 'choices', get_string('digest_columns', 'local_reportfeed'), $choices, [
            'multiple' => true,
        ]);
        $mform->setType('choices', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('choices', 'digest_columns', 'local_reportfeed');

        $others = [];
        $users = get_enrolled_users($context, 'local/reportfeed:receiveteacherdigest', 0, 'u.*', null, 0, 0, true);
        foreach ($users as $user) {
            if ((int) $user->id !== (int) $USER->id) {
                $others[$user->id] = fullname($user);
            }
        }
        $mform->addElement('autocomplete', 'nominees', get_string('digest_nominees', 'local_reportfeed'), $others, [
            'multiple' => true,
        ]);
        $mform->setType('nominees', PARAM_INT);
        $mform->addHelpButton('nominees', 'digest_nominees', 'local_reportfeed');

        $this->add_action_buttons();
    }

    /**
     * Server-side checks.
     *
     * @param array $data
     * @param array $files
     * @return array errors by field
     */
    public function validation($data, $files): array {
        global $USER;
        return parent::validation($data, $files) + digest_settings::errors($data, (int) $data['courseid'], (int) $USER->id);
    }
}
