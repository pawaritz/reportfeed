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
 * Site settings for local_reportfeed.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The getting-started page is the first entry, then the screens below.
$ADMIN->add('localplugins', new admin_externalpage(
    'local_reportfeed_start',
    new lang_string('start_title', 'local_reportfeed'),
    new moodle_url('/local/reportfeed/start.php'),
    'local/reportfeed:manageschedules'
));
// The screens below open with their own capability, so a manager without site configuration can use them.
$ADMIN->add('localplugins', new admin_externalpage(
    'local_reportfeed_schedules',
    new lang_string('schedules', 'local_reportfeed'),
    new moodle_url('/local/reportfeed/schedules.php'),
    'local/reportfeed:manageschedules'
));
$ADMIN->add('localplugins', new admin_externalpage(
    'local_reportfeed_bundles',
    new lang_string('bundles', 'local_reportfeed'),
    new moodle_url('/local/reportfeed/bundles.php'),
    'local/reportfeed:manageschedules'
));
$ADMIN->add('localplugins', new admin_externalpage(
    'local_reportfeed_log',
    new lang_string('runlog', 'local_reportfeed'),
    new moodle_url('/local/reportfeed/log.php'),
    'local/reportfeed:manageschedules'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_reportfeed', new lang_string('pluginname', 'local_reportfeed'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_reportfeed/startlink',
            '',
            html_writer::link(new moodle_url('/local/reportfeed/start.php'), get_string('start_healthlink', 'local_reportfeed'))
        ));
        // Health notice: attachments must be allowed or emails arrive without their CSV.
        if (!\local_reportfeed\local\health::attachments_enabled()) {
            $settings->add(new admin_setting_heading(
                'local_reportfeed/healthattachments',
                new lang_string('health_attachments', 'local_reportfeed'),
                $OUTPUT->notification(get_string('health_attachments_desc', 'local_reportfeed'), 'notifyproblem')
            ));
        }

        $setting = new admin_setting_configcheckbox(
            'local_reportfeed/enabled',
            new lang_string('settings_enabled', 'local_reportfeed'),
            new lang_string('settings_enabled_desc', 'local_reportfeed'),
            0
        );
        $setting->set_updatedcallback('\local_reportfeed\local\health::record_switch');
        $settings->add($setting);

        $setting = new admin_setting_configselect(
            'local_reportfeed/teacherdigestmode',
            new lang_string('settings_teacherdigestmode', 'local_reportfeed'),
            new lang_string('settings_teacherdigestmode_desc', 'local_reportfeed'),
            'off',
            [
                'off' => new lang_string('settings_mode_off', 'local_reportfeed'),
                'optin' => new lang_string('settings_mode_optin', 'local_reportfeed'),
                'optout' => new lang_string('settings_mode_optout', 'local_reportfeed'),
            ]
        );
        $setting->set_updatedcallback('\local_reportfeed\local\health::record_switch');
        $settings->add($setting);

        $settings->add(new admin_setting_configtextarea(
            'local_reportfeed/digestintro',
            new lang_string('settings_digestintro', 'local_reportfeed'),
            new lang_string('settings_digestintro_desc', 'local_reportfeed'),
            '',
            PARAM_RAW_TRIMMED,
            60,
            4
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_reportfeed/digestfooter',
            new lang_string('settings_digestfooter', 'local_reportfeed'),
            new lang_string('settings_digestfooter_desc', 'local_reportfeed'),
            '',
            PARAM_RAW_TRIMMED,
            60,
            4
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_reportfeed/allowexternal',
            new lang_string('settings_allowexternal', 'local_reportfeed'),
            new lang_string('settings_allowexternal_desc', 'local_reportfeed'),
            0
        ));

        $settings->add(new admin_setting_configtext(
            'local_reportfeed/inactivitydays',
            new lang_string('settings_inactivitydays', 'local_reportfeed'),
            new lang_string('settings_inactivitydays_desc', 'local_reportfeed'),
            14,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'local_reportfeed/sizecapmb',
            new lang_string('settings_sizecapmb', 'local_reportfeed'),
            new lang_string('settings_sizecapmb_desc', 'local_reportfeed'),
            10,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'local_reportfeed/maxparts',
            new lang_string('settings_maxparts', 'local_reportfeed'),
            new lang_string('settings_maxparts_desc', 'local_reportfeed'),
            10,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'local_reportfeed/activitymaxrows',
            new lang_string('settings_activitymaxrows', 'local_reportfeed'),
            new lang_string('settings_activitymaxrows_desc', 'local_reportfeed'),
            1000000,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'local_reportfeed/retentiondays',
            new lang_string('settings_retentiondays', 'local_reportfeed'),
            new lang_string('settings_retentiondays_desc', 'local_reportfeed'),
            90,
            PARAM_INT
        ));
    }
}
