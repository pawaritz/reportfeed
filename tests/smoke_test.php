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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Smoke tests: the skeleton installs and its building blocks behave as designed.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_reportfeed\local\health::class)]
final class smoke_test extends \advanced_testcase {
    /**
     * The three capabilities exist with the expected context levels.
     */
    public function test_capabilities_are_defined(): void {
        global $DB;
        $expected = [
            'local/reportfeed:manageschedules' => CONTEXT_SYSTEM,
            'local/reportfeed:receivehrfeed' => CONTEXT_SYSTEM,
            'local/reportfeed:receiveteacherdigest' => CONTEXT_COURSE,
        ];
        foreach ($expected as $name => $level) {
            $record = $DB->get_record('capabilities', ['name' => $name], '*', MUST_EXIST);
            $this->assertEquals($level, (int) $record->contextlevel, $name);
        }
    }

    /**
     * Both message providers exist (their names are part of the user-facing preferences).
     */
    public function test_message_providers_are_defined(): void {
        global $DB;
        foreach (['hrfeed', 'teacherdigest'] as $name) {
            $this->assertTrue(
                $DB->record_exists('message_providers', ['component' => 'local_reportfeed', 'name' => $name]),
                $name
            );
        }
    }

    /**
     * The health check follows $CFG->allowattachments.
     */
    public function test_health_attachments_follow_site_setting(): void {
        $this->resetAfterTest();
        set_config('allowattachments', 1);
        $this->assertTrue(\local_reportfeed\local\health::attachments_enabled());
        set_config('allowattachments', 0);
        $this->assertFalse(\local_reportfeed\local\health::attachments_enabled());
    }

    /**
     * Settings ship with the designed defaults, including the teacher digest being off (decision D14).
     */
    public function test_setting_defaults(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertEmpty(get_config('local_reportfeed', 'enabled'));
        $page = \admin_get_root(true, true)->locate('local_reportfeed');
        $defaults = [];
        foreach ($page->settings as $setting) {
            $defaults[$setting->plugin . '/' . $setting->name] = $setting->get_defaultsetting();
        }
        $this->assertSame('off', $defaults['local_reportfeed/teacherdigestmode']);
        $this->assertEquals(14, $defaults['local_reportfeed/inactivitydays']);
        $this->assertEquals(10, $defaults['local_reportfeed/sizecapmb']);
        $this->assertEquals(90, $defaults['local_reportfeed/retentiondays']);
    }

    /**
     * Whether the settings page contains the attachments health heading.
     *
     * @param \admin_settingpage $page
     * @return bool
     */
    private function has_health_heading(\admin_settingpage $page): bool {
        foreach ($page->settings as $setting) {
            if ($setting instanceof \admin_setting_heading && str_contains($setting->name, 'healthattachments')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The settings page shows a health warning only when attachments are disabled.
     */
    public function test_settings_page_warns_when_attachments_are_off(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('allowattachments', 1);
        $page = \admin_get_root(true, true)->locate('local_reportfeed');
        $this->assertFalse($this->has_health_heading($page));

        set_config('allowattachments', 0);
        $CFG->allowattachments = 0;
        $page = \admin_get_root(true, true)->locate('local_reportfeed');
        $this->assertTrue($this->has_health_heading($page));
    }

    /**
     * The frozen-clock pattern used by every later scheduling test works here.
     */
    public function test_frozen_clock_pattern(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(1791270000);
        $this->assertSame(1791270000, \core\di::get(\core\clock::class)->time());
        $clock->bump(3600);
        $this->assertSame(1791273600, \core\di::get(\core\clock::class)->time());
    }
}
