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

use local_reportfeed\local\bundles;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Course bundles: validation, storage, deletion and use as a schedule scope.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(bundles::class)]
final class bundles_test extends \advanced_testcase {
    public function test_errors(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame([], bundles::errors(['name' => 'A', 'courseids' => [$course->id]]));
        $this->assertArrayHasKey('name', bundles::errors(['name' => ' ', 'courseids' => [$course->id]]));
        $this->assertArrayHasKey('courseids', bundles::errors(['name' => 'A', 'courseids' => []]));

        $id = bundles::save((object) ['name' => 'Compulsory', 'courseids' => [$course->id]]);
        // Names are unique ignoring case, but a bundle may keep its own name.
        $this->assertArrayHasKey('name', bundles::errors(['name' => 'compulsory', 'courseids' => [$course->id]]));
        $this->assertSame([], bundles::errors(['id' => $id, 'name' => 'Compulsory', 'courseids' => [$course->id]]));
    }

    public function test_save_load_edit(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        [$a, $b, $c] = [$gen->create_course(), $gen->create_course(), $gen->create_course()];
        $id = bundles::save((object) ['name' => ' Set ', 'courseids' => [$a->id, $b->id, $a->id, SITEID]]);
        $loaded = bundles::load($id);
        $this->assertSame('Set', $loaded->name);
        $this->assertSame([(int) $a->id, (int) $b->id], $loaded->courseids, 'duplicates and the site course are dropped');

        $this->assertSame($id, bundles::save((object) ['id' => $id, 'name' => 'Set 2', 'courseids' => [$c->id]]));
        $this->assertSame([(int) $c->id], bundles::course_ids($id));
        $this->assertSame(1, $DB->count_records('local_reportfeed_bundlecourse'));
        $this->assertSame([$id => 'Set 2'], bundles::menu());
    }

    public function test_deleted_course_drops_out(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        [$a, $b] = [$gen->create_course(), $gen->create_course()];
        $id = bundles::save((object) ['name' => 'Two', 'courseids' => [$a->id, $b->id]]);
        delete_course($b->id, false);
        $this->assertSame([(int) $a->id], bundles::course_ids($id));
    }

    public function test_cannot_delete_a_bundle_in_use(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $id = bundles::save((object) ['name' => 'Used', 'courseids' => [$course->id]]);
        $schedule = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_reportfeed_schedule', (object) [
            'name' => 'Weekly feed', 'scope' => 'bundle', 'scopeids' => (string) $id, 'timecreated' => 1, 'timemodified' => 1,
        ]);
        $this->assertSame(['Weekly feed'], bundles::used_by($id));
        $this->assertFalse(bundles::delete($id));
        $this->assertTrue($DB->record_exists('local_reportfeed_bundle', ['id' => $id]));

        $DB->delete_records('local_reportfeed_schedule');
        $this->assertTrue(bundles::delete($id));
        $this->assertSame(0, $DB->count_records('local_reportfeed_bundlecourse'));
        $this->assertSame(0, $DB->count_records('local_reportfeed_bundle'));
    }
}
