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

namespace local_reportfeed\task;

use local_reportfeed\local\run_manager;

/**
 * Builds and sends one run. Custom data: runid.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_task extends \core\task\adhoc_task {
    /**
     * Task name shown on the ad hoc tasks screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_run', 'local_reportfeed');
    }

    /**
     * Execute the run. A failure is rethrown so Moodle retries, up to the attempt limit set when queued.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (!empty($data->runid)) {
            (new run_manager())->execute((int) $data->runid);
        }
    }
}
