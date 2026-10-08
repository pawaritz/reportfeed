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

/**
 * Upgrade steps for local_reportfeed.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_reportfeed_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100800) {
        // More frequencies than weekly (D20).
        $table = new xmldb_table('local_reportfeed_schedule');
        $fields = [
            new xmldb_field('frequency', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'weekly', 'name'),
            new xmldb_field('monthrule', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, 'day', 'weekday'),
            new xmldb_field('monthday', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'monthrule'),
            new xmldb_field('anchor', XMLDB_TYPE_INTEGER, '8', null, XMLDB_NOTNULL, null, '0', 'monthday'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026100800, 'local', 'reportfeed');
    }

    if ($oldversion < 2026100801) {
        // Named course bundles (D17).
        $bundle = new xmldb_table('local_reportfeed_bundle');
        if (!$dbman->table_exists($bundle)) {
            $bundle->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $bundle->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $bundle->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $bundle->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $bundle->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($bundle);
        }
        $courses = new xmldb_table('local_reportfeed_bundlecourse');
        if (!$dbman->table_exists($courses)) {
            $courses->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $courses->add_field('bundleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $courses->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $courses->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $courses->add_key('bundleid', XMLDB_KEY_FOREIGN, ['bundleid'], 'local_reportfeed_bundle', ['id']);
            $courses->add_key('bundlecourse', XMLDB_KEY_UNIQUE, ['bundleid', 'courseid']);
            $dbman->create_table($courses);
        }
        upgrade_plugin_savepoint(true, 2026100801, 'local', 'reportfeed');
    }

    if ($oldversion < 2026100802) {
        // File format per schedule, parts of split reports and external recipients on deliveries (D18, D19, D25).
        $schedule = new xmldb_table('local_reportfeed_schedule');
        $field = new xmldb_field('format', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, 'csv', 'scopeids');
        if (!$dbman->field_exists($schedule, $field)) {
            $dbman->add_field($schedule, $field);
        }
        $delivery = new xmldb_table('local_reportfeed_delivery');
        $oldkey = new xmldb_key('runuserfile', XMLDB_KEY_UNIQUE, ['runid', 'userid', 'filekey']);
        $dbman->drop_key($delivery, $oldkey);
        $fields = [
            new xmldb_field('extid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid'),
            new xmldb_field('email', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'extid'),
            new xmldb_field('part', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '1', 'filekey'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($delivery, $field)) {
                $dbman->add_field($delivery, $field);
            }
        }
        $newkey = new xmldb_key('runuserfile', XMLDB_KEY_UNIQUE, ['runid', 'userid', 'extid', 'filekey', 'part']);
        $dbman->add_key($delivery, $newkey);
        upgrade_plugin_savepoint(true, 2026100802, 'local', 'reportfeed');
    }

    if ($oldversion < 2026100803) {
        // Recipients who are not Moodle users (D19).
        $table = new xmldb_table('local_reportfeed_extrecipient');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('scheduleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('scheduleid', XMLDB_KEY_FOREIGN, ['scheduleid'], 'local_reportfeed_schedule', ['id']);
            $table->add_key('scheduleemail', XMLDB_KEY_UNIQUE, ['scheduleid', 'email']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026100803, 'local', 'reportfeed');
    }

    if ($oldversion < 2026100804) {
        // Optional activity-level files chosen per schedule (D21, D23, D24).
        $table = new xmldb_table('local_reportfeed_schedule');
        $field = new xmldb_field('activityopts', XMLDB_TYPE_TEXT, null, null, null, null, null, 'format');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026100804, 'local', 'reportfeed');
    }

    if ($oldversion < 2026100805) {
        // Choice of HR files per schedule and the learner roster snapshot (D27, D28).
        $table = new xmldb_table('local_reportfeed_schedule');
        $field = new xmldb_field(
            'hrfiles',
            XMLDB_TYPE_CHAR,
            '60',
            null,
            XMLDB_NOTNULL,
            null,
            'learner_course,learner_summary',
            'format'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $roster = new xmldb_table('local_reportfeed_roster');
        if (!$dbman->table_exists($roster)) {
            $roster->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $roster->add_field('scheduleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $roster->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $roster->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $roster->add_field('changetype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $roster->add_field('reason', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $roster->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $roster->add_key('runuser', XMLDB_KEY_UNIQUE, ['runid', 'userid']);
            $roster->add_index('schedulerun', XMLDB_INDEX_NOTUNIQUE, ['scheduleid', 'runid']);
            $roster->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($roster);
        }
        upgrade_plugin_savepoint(true, 2026100805, 'local', 'reportfeed');
    }

    return true;
}
