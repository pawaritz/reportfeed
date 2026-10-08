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
 * Every five minutes: create the runs that are due and queue their ad hoc tasks.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dispatch_task extends \core\task\scheduled_task {
    /**
     * Task name shown on the scheduled tasks screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_dispatch', 'local_reportfeed');
    }

    /**
     * Create due runs.
     */
    public function execute(): void {
        $created = (new run_manager())->dispatch();
        mtrace("Reportfeed: $created run(s) created.");
    }
}
