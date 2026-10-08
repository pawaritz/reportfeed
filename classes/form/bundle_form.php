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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Edit form for one course bundle.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bundle_form extends \moodleform {
    /**
     * Form fields.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('text', 'name', get_string('bundle_name', 'local_reportfeed'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', null, 'maxlength', 255, 'client');
        $mform->addElement(
            'course',
            'courseids',
            get_string('bundle_courses', 'local_reportfeed'),
            ['multiple' => true, 'includefrontpage' => false]
        );
        $mform->setType('courseids', PARAM_INT);
        $mform->addHelpButton('courseids', 'bundle_courses', 'local_reportfeed');
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
        return parent::validation($data, $files) + bundles::errors($data);
    }
}
