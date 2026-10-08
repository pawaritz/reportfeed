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

namespace local_reportfeed;

use local_reportfeed\local\checklist;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The getting-started checklist reads live site state.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(checklist::class)]
final class checklist_test extends \advanced_testcase {
    /**
     * The state of one checklist step.
     *
     * @param string $key the step
     * @return string
     */
    private function state(string $key): string {
        foreach (checklist::steps() as $step) {
            if ($step['key'] === $key) {
                return $step['state'];
            }
        }
        $this->fail("No step $key");
    }

    public function test_fresh_site_has_everything_to_do(): void {
        $this->resetAfterTest();
        $this->assertSame(checklist::TODO, $this->state('enable'));
        $this->assertSame(checklist::TODO, $this->state('receiver'));
        $this->assertSame(checklist::TODO, $this->state('schedule'));
        $this->assertSame(checklist::TODO, $this->state('recipients'));
        $this->assertSame(checklist::INFO, $this->state('firstrun'));
        $this->assertSame(checklist::INFO, $this->state('teachers'));
    }

    public function test_mail_and_attachments(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->smtphosts = '';
        $CFG->noemailever = false;
        $this->assertSame(checklist::CHECK, $this->state('mail'));
        $CFG->smtphosts = 'smtp.example.org:587';
        $this->assertSame(checklist::DONE, $this->state('mail'));
        $CFG->noemailever = true;
        $this->assertSame(checklist::TODO, $this->state('mail'));
        $CFG->allowattachments = 0;
        $this->assertSame(checklist::TODO, $this->state('attachments'));
        $CFG->allowattachments = 1;
        $this->assertSame(checklist::DONE, $this->state('attachments'));
    }

    public function test_settings_receiver_schedule_and_runs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled', 1, 'local_reportfeed');
        $this->assertSame(checklist::DONE, $this->state('enable'));

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/reportfeed:receivehrfeed', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $this->assertSame(checklist::TODO, $this->state('receiver'));
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->assertSame(checklist::DONE, $this->state('receiver'));
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertSame(checklist::TODO, $this->state('receiver'));

        $id = $DB->insert_record('local_reportfeed_schedule', (object) [
            'name' => 'S', 'enabled' => 1, 'timecreated' => 1, 'timemodified' => 1,
        ]);
        $this->assertSame(checklist::DONE, $this->state('schedule'));
        $this->assertSame(checklist::CHECK, $this->state('recipients'));
        $DB->insert_record('local_reportfeed_recipient', (object) ['scheduleid' => $id, 'userid' => $user->id]);
        $this->assertSame(checklist::DONE, $this->state('recipients'));

        $run = [
            'type' => 'hrfeed', 'scheduleid' => $id, 'periodend' => 20261001, 'timedue' => 1, 'status' => 'failed',
        ];
        $runid = $DB->insert_record('local_reportfeed_run', (object) $run);
        $this->assertSame(checklist::CHECK, $this->state('firstrun'));
        $DB->set_field('local_reportfeed_run', 'status', 'sent', ['id' => $runid]);
        $this->assertSame(checklist::DONE, $this->state('firstrun'));
    }
}
