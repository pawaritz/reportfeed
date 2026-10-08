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

namespace local_reportfeed\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_reportfeed.
 *
 * Personal data is the user id in four places: HR feed recipients, run and delivery logs, the owner of a
 * schedule, and teacher digest settings (also the nominating teacher). The CSV content is never stored.
 * Site-wide rows live in the system context, digest settings in their course context.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_reportfeed_recipient', [
            'userid' => 'privacy:metadata:recipient:userid',
        ], 'privacy:metadata:recipient');
        $collection->add_database_table('local_reportfeed_digestcourse', [
            'userid' => 'privacy:metadata:digestcourse:userid',
            'courseid' => 'privacy:metadata:digestcourse:courseid',
            'nominatedby' => 'privacy:metadata:digestcourse:nominatedby',
        ], 'privacy:metadata:digestcourse');
        $collection->add_database_table('local_reportfeed_run', [
            'userid' => 'privacy:metadata:run:userid',
        ], 'privacy:metadata:run');
        $collection->add_database_table('local_reportfeed_extrecipient', [
            'email' => 'privacy:metadata:extrecipient:email',
        ], 'privacy:metadata:extrecipient');
        $collection->add_database_table('local_reportfeed_delivery', [
            'userid' => 'privacy:metadata:delivery:userid',
            'email' => 'privacy:metadata:delivery:email',
            'status' => 'privacy:metadata:delivery:status',
        ], 'privacy:metadata:delivery');
        $collection->add_database_table('local_reportfeed_roster', [
            'userid' => 'privacy:metadata:roster:userid',
            'changetype' => 'privacy:metadata:roster:changetype',
            'reason' => 'privacy:metadata:roster:reason',
        ], 'privacy:metadata:roster');
        $collection->add_database_table('local_reportfeed_schedule', [
            'ownerid' => 'privacy:metadata:schedule:ownerid',
        ], 'privacy:metadata:schedule');
        $collection->add_external_location_link('externalmail', [
            'learners' => 'privacy:metadata:externalmail:learners',
        ], 'privacy:metadata:externalmail');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        return $collection;
    }

    /**
     * Contexts that hold data about the user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql(
            "SELECT ctx.id FROM {context} ctx
              WHERE ctx.contextlevel = :sys AND (
                    EXISTS (SELECT 1 FROM {local_reportfeed_recipient} WHERE userid = :u1)
                 OR EXISTS (SELECT 1 FROM {local_reportfeed_delivery} WHERE userid = :u2)
                 OR EXISTS (SELECT 1 FROM {local_reportfeed_run} WHERE userid = :u3)
                 OR EXISTS (SELECT 1 FROM {local_reportfeed_roster} WHERE userid = :u5)
                 OR EXISTS (SELECT 1 FROM {local_reportfeed_schedule} WHERE ownerid = :u4))",
            ['sys' => CONTEXT_SYSTEM, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid, 'u5' => $userid]
        );
        $list->add_from_sql(
            "SELECT ctx.id FROM {context} ctx
               JOIN {local_reportfeed_digestcourse} d ON d.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :course AND (d.userid = :u1 OR d.nominatedby = :u2)",
            ['course' => CONTEXT_COURSE, 'u1' => $userid, 'u2' => $userid]
        );
        return $list;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_system) {
            $fields = [
                'local_reportfeed_recipient' => 'userid', 'local_reportfeed_delivery' => 'userid',
                'local_reportfeed_run' => 'userid', 'local_reportfeed_schedule' => 'ownerid',
                'local_reportfeed_roster' => 'userid',
            ];
            foreach ($fields as $table => $field) {
                $userlist->add_from_sql($field, 'SELECT ' . $field . ' FROM {' . $table . '} WHERE ' . $field . ' <> 0', []);
            }
        } else if ($context instanceof \context_course) {
            $params = ['courseid' => $context->instanceid];
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {local_reportfeed_digestcourse} WHERE courseid = :courseid',
                $params
            );
            $userlist->add_from_sql(
                'nominatedby',
                'SELECT nominatedby FROM {local_reportfeed_digestcourse} WHERE courseid = :courseid AND nominatedby <> 0',
                $params
            );
        }
    }

    /**
     * Export what is stored about the user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $subcontext = [get_string('pluginname', 'local_reportfeed')];
        foreach ($contextlist->get_contexts() as $context) {
            $data = [];
            if ($context instanceof \context_system) {
                $data['recipient_of_schedules'] = array_values($DB->get_fieldset_sql(
                    'SELECT s.name FROM {local_reportfeed_recipient} r JOIN {local_reportfeed_schedule} s ON s.id = r.scheduleid
                      WHERE r.userid = ? ORDER BY s.id',
                    [$userid]
                ));
                $data['schedules_owned'] = array_values(
                    $DB->get_fieldset_select('local_reportfeed_schedule', 'name', 'ownerid = ?', [$userid])
                );
                $data['roster'] = [];
                $sql = 'SELECT ro.id, ro.changetype, ro.reason, r.periodend, s.name
                          FROM {local_reportfeed_roster} ro
                          JOIN {local_reportfeed_run} r ON r.id = ro.runid
                     LEFT JOIN {local_reportfeed_schedule} s ON s.id = ro.scheduleid
                         WHERE ro.userid = ? ORDER BY ro.id';
                foreach ($DB->get_records_sql($sql, [$userid]) as $ro) {
                    $data['roster'][] = [
                        'schedule' => $ro->name, 'period_end' => $ro->periodend, 'change' => $ro->changetype,
                        'reason' => $ro->reason,
                    ];
                }
                $data['deliveries'] = [];
                $sql = 'SELECT d.id, d.filekey, d.status, d.reason, d.timemodified, r.periodend
                           FROM {local_reportfeed_delivery} d JOIN {local_reportfeed_run} r ON r.id = d.runid
                          WHERE d.userid = ? ORDER BY d.id';
                foreach ($DB->get_records_sql($sql, [$userid]) as $d) {
                    $data['deliveries'][] = [
                        'file' => $d->filekey, 'period_end' => $d->periodend, 'status' => $d->status,
                        'reason' => $d->reason, 'time' => transform::datetime($d->timemodified),
                    ];
                }
            } else if ($context instanceof \context_course) {
                $data['digest_settings'] = [];
                $rows = $DB->get_records_select(
                    'local_reportfeed_digestcourse',
                    'courseid = ? AND (userid = ? OR nominatedby = ?)',
                    [$context->instanceid, $userid, $userid]
                );
                foreach ($rows as $row) {
                    $data['digest_settings'][] = [
                        'enabled' => transform::yesno($row->enabled), 'weekday' => $row->weekday, 'hour' => $row->hour,
                        'columns' => $row->choices, 'role' => (int) $row->userid === (int) $userid ? 'recipient' : 'nominator',
                    ];
                }
            }
            writer::with_context($context)->export_data($subcontext, (object) $data);
        }
    }

    /**
     * Delete everything about every user in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('local_reportfeed_recipient');
            $DB->delete_records('local_reportfeed_delivery');
            $DB->delete_records('local_reportfeed_run');
            $DB->delete_records('local_reportfeed_roster');
            $DB->set_field_select('local_reportfeed_schedule', 'ownerid', 0, 'ownerid <> 0');
        } else if ($context instanceof \context_course) {
            $DB->delete_records('local_reportfeed_digestcourse', ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Delete the user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_users($context, [$userid]);
        }
    }

    /**
     * Delete the data of several users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        self::delete_users($userlist->get_context(), $userlist->get_userids());
    }

    /**
     * Delete or detach the data of the given users in one context.
     *
     * @param \context $context system or course
     * @param int[] $userids
     */
    private static function delete_users(\context $context, array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        if ($context instanceof \context_system) {
            $DB->delete_records_select('local_reportfeed_recipient', "userid $in", $params);
            $DB->delete_records_select('local_reportfeed_delivery', "userid $in", $params);
            $DB->delete_records_select(
                'local_reportfeed_delivery',
                "runid IN (SELECT id FROM {local_reportfeed_run} WHERE userid $in)",
                $params
            );
            $DB->delete_records_select('local_reportfeed_run', "userid $in", $params);
            $DB->delete_records_select('local_reportfeed_roster', "userid $in", $params);
            // A schedule outlives its owner; it just no longer names a person.
            $DB->set_field_select('local_reportfeed_schedule', 'ownerid', 0, "ownerid $in", $params);
        } else if ($context instanceof \context_course) {
            $params['courseid'] = $context->instanceid;
            $DB->delete_records_select('local_reportfeed_digestcourse', "courseid = :courseid AND userid $in", $params);
            $DB->set_field_select(
                'local_reportfeed_digestcourse',
                'nominatedby',
                0,
                "courseid = :courseid AND nominatedby $in",
                $params
            );
        }
    }
}
