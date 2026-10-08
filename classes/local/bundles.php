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

namespace local_reportfeed\local;

/**
 * Named bundles of courses that several schedules can share (D17).
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bundles {
    /**
     * What is wrong with a submitted bundle, by field.
     *
     * @param \stdClass|array $data submitted form data: id, name, courseids
     * @return string[]
     */
    public static function errors($data): array {
        global $DB;
        $data = (object) $data;
        $errors = [];
        $name = trim((string) ($data->name ?? ''));
        if ($name === '') {
            $errors['name'] = get_string('required');
        } else {
            $same = $DB->get_records_select('local_reportfeed_bundle', $DB->sql_equal('name', ':n', false), ['n' => $name]);
            unset($same[(int) ($data->id ?? 0)]);
            if ($same) {
                $errors['name'] = get_string('bundle_nameused', 'local_reportfeed');
            }
        }
        if (empty($data->courseids)) {
            $errors['courseids'] = get_string('required');
        }
        return $errors;
    }

    /**
     * Create or update a bundle and replace its courses.
     *
     * @param \stdClass $data validated data; id 0 or unset for a new bundle
     * @return int the bundle id
     */
    public static function save(\stdClass $data): int {
        global $DB;
        $now = time();
        $record = (object) ['name' => trim($data->name), 'timemodified' => $now];
        if (!empty($data->id)) {
            $record->id = (int) $data->id;
            $DB->update_record('local_reportfeed_bundle', $record);
        } else {
            $record->timecreated = $now;
            $record->id = $DB->insert_record('local_reportfeed_bundle', $record);
        }
        $DB->delete_records('local_reportfeed_bundlecourse', ['bundleid' => $record->id]);
        foreach (array_unique(array_map('intval', $data->courseids)) as $courseid) {
            if ($courseid != SITEID) {
                $DB->insert_record('local_reportfeed_bundlecourse', (object) ['bundleid' => $record->id, 'courseid' => $courseid]);
            }
        }
        return (int) $record->id;
    }

    /**
     * Names of the schedules that use a bundle as their scope.
     *
     * @param int $id
     * @return string[]
     */
    public static function used_by(int $id): array {
        global $DB;
        return array_values($DB->get_fieldset_select(
            'local_reportfeed_schedule',
            'name',
            'scope = :scope AND scopeids = :id',
            ['scope' => 'bundle', 'id' => (string) $id]
        ));
    }

    /**
     * Delete a bundle that no schedule uses.
     *
     * @param int $id
     * @return bool false when a schedule still uses it
     */
    public static function delete(int $id): bool {
        global $DB;
        if (self::used_by($id)) {
            return false;
        }
        $DB->delete_records('local_reportfeed_bundlecourse', ['bundleid' => $id]);
        $DB->delete_records('local_reportfeed_bundle', ['id' => $id]);
        return true;
    }

    /**
     * The data an edit form needs.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function load(int $id): \stdClass {
        global $DB;
        $bundle = $DB->get_record('local_reportfeed_bundle', ['id' => $id], '*', MUST_EXIST);
        $bundle->courseids = self::course_ids($id);
        return $bundle;
    }

    /**
     * The ids of the courses in a bundle that still exist (a deleted course just drops out).
     *
     * @param int $id
     * @return int[]
     */
    public static function course_ids(int $id): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql(
            'SELECT c.id FROM {local_reportfeed_bundlecourse} bc JOIN {course} c ON c.id = bc.courseid
              WHERE bc.bundleid = :id AND c.id <> :site ORDER BY c.id',
            ['id' => $id, 'site' => SITEID]
        ));
    }

    /**
     * Bundle names by id, for a select.
     *
     * @return string[]
     */
    public static function menu(): array {
        global $DB;
        return $DB->get_records_menu('local_reportfeed_bundle', null, 'name ASC', 'id, name');
    }
}
