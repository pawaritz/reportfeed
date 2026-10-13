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
use local_reportfeed\local\dashboard;
use local_reportfeed\local\observer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The home page data, the row of links, and the record of a failed Reportfeed email.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(dashboard::class)]
#[CoversClass(observer::class)]
final class dashboard_test extends \advanced_testcase {
    /**
     * Make a schedule row and return its id.
     *
     * @param string $name
     * @return int
     */
    private function make_schedule(string $name = 'Weekly'): int {
        global $DB;
        return $DB->insert_record('local_reportfeed_schedule', (object) [
            'name' => $name, 'enabled' => 1, 'weekday' => 1, 'hour' => 7, 'minute' => 0, 'scope' => 'all', 'scopeids' => '',
            'identitycols' => 'idnumber,email', 'usebom' => 0, 'zip' => 0, 'ownerid' => 0, 'lastrun' => 0,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Make a run row.
     *
     * @param int $scheduleid
     * @param string $status
     * @param int $day send day, one run per schedule and day
     * @return int
     */
    private function make_run(int $scheduleid, string $status, int $day = 1): int {
        global $DB;
        return $DB->insert_record('local_reportfeed_run', (object) [
            'type' => 'hrfeed', 'scheduleid' => $scheduleid, 'periodend' => 20261000 + $day, 'timedue' => time(),
            'status' => $status, 'timecreated' => time(),
        ]);
    }

    /**
     * Fire Moodle's "could not send email" event.
     *
     * @param string $subject
     * @param string $error
     */
    private function fail_mail(string $subject, string $error): void {
        \core\event\email_failed::create([
            'context' => \context_system::instance(), 'userid' => 2, 'relateduserid' => 2,
            'other' => ['subject' => $subject, 'message' => 'body', 'errorinfo' => $error],
        ])->trigger();
    }

    public function test_progress_counts_required_steps_and_names_the_next_one(): void {
        $this->resetAfterTest();
        $before = dashboard::progress();
        $required = array_filter(checklist::steps(), fn($step) => $step['state'] !== checklist::INFO);
        $this->assertSame(count($required), $before['total']);
        $this->assertGreaterThan(0, $before['total'] - $before['done']);
        $this->assertNotNull($before['next']);
        $this->assertNotSame(checklist::DONE, $before['next']['state']);

        set_config('enabled', 1, 'local_reportfeed');
        $after = dashboard::progress();
        $this->assertSame($before['done'] + 1, $after['done']);
        $this->assertNotSame('enable', $after['next']['key']);
    }

    public function test_recent_runs_newest_first_and_limited(): void {
        $this->resetAfterTest();
        $scheduleid = $this->make_schedule('Named');
        $this->assertSame([], dashboard::recent_runs());
        $ids = [];
        foreach (['sent', 'failed', 'sent', 'skipped', 'sent', 'queued'] as $day => $status) {
            $ids[] = $this->make_run($scheduleid, $status, $day + 1);
        }
        $runs = dashboard::recent_runs();
        $this->assertCount(5, $runs);
        $this->assertSame($ids[5], (int) $runs[0]->id);
        $this->assertSame('Named', $runs[0]->schedulename);
        $this->assertCount(2, dashboard::recent_runs(2));
    }

    public function test_recipient_count_adds_users_and_outside_addresses(): void {
        global $DB;
        $this->resetAfterTest();
        $id = $this->make_schedule();
        $this->assertSame(0, dashboard::recipient_count($id));
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_reportfeed_recipient', ['scheduleid' => $id, 'userid' => $user->id]);
        $DB->insert_record('local_reportfeed_extrecipient', ['scheduleid' => $id, 'email' => 'a@example.org']);
        $DB->insert_record('local_reportfeed_extrecipient', ['scheduleid' => $id, 'email' => 'b@example.org']);
        $this->assertSame(3, dashboard::recipient_count($id));
        $this->assertSame(0, dashboard::recipient_count($id + 1));
    }

    public function test_a_failed_reportfeed_email_is_remembered(): void {
        $this->resetAfterTest();
        $this->assertNull(dashboard::last_mail_error());
        $this->fail_mail('[Reportfeed] hr_feed | learner_roster | Lab | 2026-10-13', 'SMTP Error: Could not authenticate.');
        $problem = dashboard::last_mail_error();
        $this->assertSame('SMTP Error: Could not authenticate.', $problem['error']);
        $this->assertEqualsWithDelta(time(), $problem['time'], 5);
    }

    public function test_other_emails_and_old_problems_are_ignored(): void {
        $this->resetAfterTest();
        $this->fail_mail('Welcome to the site', 'SMTP Error: boom');
        $this->assertNull(dashboard::last_mail_error());

        set_config('lastmailerror', json_encode([
            'time' => time() - dashboard::MAILERROR_AGE - 10, 'error' => 'old',
        ]), 'local_reportfeed');
        $this->assertNull(dashboard::last_mail_error());
        set_config('lastmailerror', 'not json', 'local_reportfeed');
        $this->assertNull(dashboard::last_mail_error());
    }

    public function test_long_error_text_is_cut_and_tags_removed(): void {
        $this->resetAfterTest();
        $this->fail_mail('[Reportfeed] teacher_digest | Lab | C1 | 2026-10-13', '<b>' . str_repeat('x', 500) . '</b>');
        $error = dashboard::last_mail_error()['error'];
        $this->assertSame(300, \core_text::strlen($error));
        $this->assertStringNotContainsString('<', $error);
    }

    public function test_nav_links_every_screen_and_shows_settings_only_to_site_admins(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $html = dashboard::nav('log');
        foreach (['index.php', 'start.php', 'schedules.php', 'bundles.php', 'log.php', 'section=local_reportfeed'] as $target) {
            $this->assertStringContainsString($target, $html);
        }
        $this->assertSame(1, substr_count($html, 'nav-link active'));
        $this->assertMatchesRegularExpression('~class="nav-link active" href="[^"]*log\.php~', $html);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/reportfeed:manageschedules', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $html = dashboard::nav('home');
        $this->assertStringContainsString('index.php', $html);
        $this->assertStringNotContainsString('section=local_reportfeed', $html);
    }
}
