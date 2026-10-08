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

use local_reportfeed\local\external;
use local_reportfeed\local\schedules;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * External recipients: parsing, validation, the permission and site setting, and storage.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(external::class)]
final class external_test extends \advanced_testcase {
    public function test_parse(): void {
        $this->assertSame(['a@x.org', 'b@y.com', 'c@z.net'], external::parse("A@X.org, b@y.com;\n c@z.net  a@x.org"));
        $this->assertSame([], external::parse("  \n "));
    }

    public function test_error(): void {
        $this->resetAfterTest();
        $this->assertNull(external::error("hr@company.example\nboss@gmail.com"));
        $this->assertStringContainsString('not-an-address', external::error('ok@x.org not-an-address'));
        $this->assertNotNull(external::error('someone@nowhere.invalid'));
        $many = implode(' ', array_map(fn($i) => "u$i@x.org", range(1, external::MAX + 1)));
        $this->assertStringContainsString((string) external::MAX, external::error($many));
        $this->assertNull(external::error(implode(' ', array_map(fn($i) => "u$i@x.org", range(1, external::MAX)))));
        // The text is escaped, so a typed script cannot reach the page.
        $this->assertStringNotContainsString('<script>', external::error('<script>alert(1)</script>'));
    }

    public function test_store_keeps_ids_of_addresses_that_stay(): void {
        global $DB;
        $this->resetAfterTest();
        $id = $DB->insert_record('local_reportfeed_schedule', (object) ['name' => 'S', 'timecreated' => 1, 'timemodified' => 1]);
        external::store($id, "a@x.org\nb@x.org");
        $before = external::addresses($id);
        $this->assertSame(['a@x.org', 'b@x.org'], array_values($before));
        external::store($id, "b@x.org\nc@x.org");
        $after = external::addresses($id);
        $this->assertSame(['b@x.org', 'c@x.org'], array_values($after));
        $this->assertSame(array_search('b@x.org', $before), array_search('b@x.org', $after));
    }

    public function test_the_stand_in_user_is_not_an_account(): void {
        $this->resetAfterTest();
        $user = external::user('hr@company.example');
        $this->assertSame(external::FAKE_ID, $user->id);
        $this->assertSame('hr@company.example', $user->email);
        $this->assertTrue($user->reportfeedexternal);
    }

    /**
     * Valid form data for a schedule with external recipients only.
     *
     * @param array $extra
     * @return \stdClass
     */
    private function data(array $extra = []): \stdClass {
        return (object) ($extra + [
            'name' => 'Outside', 'enabled' => 1, 'weekday' => 1, 'hour' => 7, 'minute' => 0, 'scope' => 'all',
            'recipients' => [], 'externalemails' => "hr@company.example\nboss@gmail.com", 'identitycols' => ['idnumber'],
        ]);
    }

    public function test_save_needs_the_setting_and_the_capability(): void {
        global $DB;
        $this->resetAfterTest();
        // Nobody logged in: the capability is not held, whatever the setting says.
        $this->setUser(0);
        set_config('allowexternal', 1, 'local_reportfeed');
        $id = schedules::save($this->data(), 1);
        $this->assertSame([], external::addresses($id), 'no capability: not stored');

        // A manager role with the manager schedule capability but not the external one.
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability('local/reportfeed:manageschedules', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);
        $this->assertFalse(external::can_edit());
        schedules::save($this->data(['id' => $id]), 1);
        $this->assertSame([], external::addresses($id));

        // With the capability but the setting off: still not stored.
        assign_capability('local/reportfeed:editexternalrecipients', CAP_ALLOW, $roleid, $context->id);
        set_config('allowexternal', 0, 'local_reportfeed');
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(external::can_edit());
        schedules::save($this->data(['id' => $id]), 1);
        $this->assertSame([], external::addresses($id));

        // Both: stored.
        set_config('allowexternal', 1, 'local_reportfeed');
        $this->assertTrue(external::can_edit());
        schedules::save($this->data(['id' => $id]), 1);
        $this->assertSame(['hr@company.example', 'boss@gmail.com'], array_values(external::addresses($id)));

        // A form without the field (a user without the capability) leaves the addresses alone, and a schedule that has only
        // external recipients is still valid.
        $edit = $this->data(['id' => $id, 'name' => 'Renamed']);
        unset($edit->externalemails);
        $this->assertSame([], schedules::errors($edit));
        schedules::save($edit, 1);
        $this->assertCount(2, external::addresses($id));
        $this->assertSame('Renamed', $DB->get_field('local_reportfeed_schedule', 'name', ['id' => $id]));

        schedules::delete($id);
        $this->assertSame(0, $DB->count_records('local_reportfeed_extrecipient'));
    }

    public function test_a_schedule_needs_some_recipient_and_valid_addresses(): void {
        $this->resetAfterTest();
        $this->assertArrayHasKey('recipients', schedules::errors($this->data(['externalemails' => ''])));
        $errors = schedules::errors($this->data(['externalemails' => 'nope']));
        $this->assertArrayHasKey('externalemails', $errors);
        $this->assertArrayNotHasKey('recipients', $errors);
        $this->assertSame([], schedules::errors($this->data()));
    }
}
