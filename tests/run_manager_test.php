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

use local_reportfeed\local\run_manager;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mail_reader.php');

/**
 * Dispatching, sending, retrying and resending runs.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(run_manager::class)]
final class run_manager_test extends \advanced_testcase {
    /** @var int Frozen "now": Tuesday 2026-10-06 12:00 UTC. */
    private const NOW = 1791288000;

    /** @var \frozen_clock The clock the manager and provider use. */
    private \frozen_clock $clock;

    /** @var int|null Role that holds the HR feed capability, created on first use. */
    private ?int $hrroleid = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Moodle buffers messages while a transaction is open; tests that expect emails must not run inside one.
        $this->preventResetByRollback();
        $this->setTimezone('UTC', 'UTC');
        $this->clock = $this->mock_clock_with_frozen(self::NOW);
        set_config('enabled', 1, 'local_reportfeed');
    }

    /**
     * A course with two students, so the files have rows.
     *
     * @return \stdClass
     */
    private function course(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        return $course;
    }

    /**
     * A user who may receive the HR feed.
     *
     * @return \stdClass
     */
    private function hr_user(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        if ($this->hrroleid === null) {
            $this->hrroleid = $this->getDataGenerator()->create_role();
            assign_capability('local/reportfeed:receivehrfeed', CAP_ALLOW, $this->hrroleid, $context->id);
        }
        role_assign($this->hrroleid, $user->id, $context->id);
        return $user;
    }

    /**
     * A schedule due this morning (Tuesday 08:00 UTC) with the given recipients, edited ten days ago.
     *
     * @param \stdClass[] $recipients
     * @param array $extra schedule fields to override
     * @return \stdClass
     */
    private function schedule(array $recipients, array $extra = []): \stdClass {
        global $DB;
        $row = (object) ($extra + [
            'name' => 'Weekly HR feed', 'enabled' => 1, 'weekday' => 2, 'hour' => 8, 'minute' => 0, 'scope' => 'all',
            'scopeids' => '', 'identitycols' => 'idnumber,email', 'usebom' => 0, 'zip' => 0, 'ownerid' => 0,
            'lastrun' => 0, 'timecreated' => self::NOW - 10 * DAYSECS, 'timemodified' => self::NOW - 10 * DAYSECS,
        ]);
        $row->id = $DB->insert_record('local_reportfeed_schedule', $row);
        foreach ($recipients as $user) {
            $DB->insert_record('local_reportfeed_recipient', ['scheduleid' => $row->id, 'userid' => $user->id]);
        }
        return $row;
    }

    /**
     * Dispatch and return the one run created.
     *
     * @return \stdClass
     */
    private function dispatch_one(): \stdClass {
        global $DB;
        $this->assertSame(1, (new run_manager())->dispatch());
        return $DB->get_record('local_reportfeed_run', [], '*', MUST_EXIST);
    }

    /**
     * The captured emails.
     *
     * @param \core\test\phpunit\phpmailer_sink $sink
     * @return array[] each: to, subject, files, body, csv
     */
    private function mails(\core\test\phpunit\phpmailer_sink $sink): array {
        return mail_reader::read($sink);
    }

    /**
     * One run and one queued task per schedule and send date, however often the dispatcher runs.
     */
    public function test_dispatch_creates_one_run_and_one_task(): void {
        global $DB;
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        $this->assertSame('queued', $run->status);
        $this->assertSame(20261006, (int) $run->periodend);
        $this->assertSame(self::NOW - 4 * HOURSECS, (int) $run->timedue);

        $this->assertSame(0, (new run_manager())->dispatch());
        $this->assertSame(0, (new run_manager())->dispatch());
        $this->assertSame(1, $DB->count_records('local_reportfeed_run'));
        $tasks = \core\task\manager::get_adhoc_tasks(\local_reportfeed\task\run_task::class);
        $this->assertCount(1, $tasks);
        $this->assertSame(run_manager::MAX_ATTEMPTS, reset($tasks)->get_attempts_available());
    }

    /**
     * Nothing fires for a disabled schedule, a master switch that is off, an old moment, or an edit after the moment.
     */
    public function test_dispatch_rules(): void {
        global $DB;
        $user = $this->hr_user();
        $this->schedule([$user], ['enabled' => 0]);
        $this->schedule([$user], ['weekday' => 1]); // Monday 08:00 was 28 hours ago.
        $this->schedule([$user], ['timemodified' => self::NOW - HOURSECS]); // Edited after this morning's moment.
        $this->assertSame(0, (new run_manager())->dispatch());

        $this->schedule([$user]);
        set_config('enabled', 0, 'local_reportfeed');
        $this->assertSame(0, (new run_manager())->dispatch());
        set_config('enabled', 1, 'local_reportfeed');
        $this->assertSame(1, (new run_manager())->dispatch());
        $this->assertSame(1, $DB->count_records('local_reportfeed_run'));
    }

    /**
     * The other frequencies fire on their own moments: monthly on a chosen day, every 2 weeks only in "on" weeks.
     */
    public function test_dispatch_other_frequencies(): void {
        global $DB;
        $user = $this->hr_user();
        // Monthly on day 6 at 08:00: this morning (Tuesday 6 October).
        $monthly = $this->schedule([$user], ['frequency' => 'monthly', 'monthrule' => 'day', 'monthday' => 6]);
        // Monthly on day 7: its last moment was 7 September, long expired.
        $this->schedule([$user], ['frequency' => 'monthly', 'monthrule' => 'day', 'monthday' => 7]);
        // Every 2 weeks on Tuesday: the anchor week is this week, so today sends; the other schedule has the off week.
        $on = $this->schedule([$user], ['frequency' => 'biweekly', 'weekday' => 2, 'anchor' => 20261005]);
        $off = $this->schedule([$user], ['frequency' => 'biweekly', 'weekday' => 2, 'anchor' => 20260928]);
        $this->assertSame(2, (new run_manager())->dispatch());
        $runs = $DB->get_records('local_reportfeed_run', null, '', 'scheduleid, periodend');
        $this->assertEquals([$monthly->id, $on->id], array_keys($runs));
        $this->assertArrayNotHasKey($off->id, $runs);
        $this->assertSame(0, (new run_manager())->dispatch());
    }

    /**
     * Two files per recipient, one per email, with the contract subject, name and body.
     */
    public function test_execute_sends_one_file_per_email(): void {
        global $DB;
        $this->course();
        $a = $this->hr_user();
        $b = $this->hr_user();
        $schedule = $this->schedule([$a, $b]);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $mails = $this->mails($sink);
        $this->assertCount(4, $mails);
        $subjects = array_unique(array_column($mails, 'subject'));
        sort($subjects);
        $site = \local_reportfeed\local\mailer::site_slug();
        $this->assertSame([
            "[Reportfeed] hr_feed | learner_course | $site | 2026-10-06",
            "[Reportfeed] hr_feed | learner_summary | $site | 2026-10-06",
        ], $subjects);
        $this->assertEqualsCanonicalizing([$a->email, $a->email, $b->email, $b->email], array_column($mails, 'to'));
        foreach ($mails as $mail) {
            $this->assertCount(1, $mail['files']);
            $this->assertMatchesRegularExpression(
                '/^learner_(course|summary)_' . preg_quote($site) . '_2026-10-06_v1\.csv$/',
                $mail['files'][0]
            );
            $this->assertStringContainsString("file: {$mail['files'][0]}\n", $mail['body']);
            $this->assertStringContainsString(
                "schema_version: 1\nperiod_end: 2026-10-06\ngenerated_at: 2026-10-06T12:00:00Z\n",
                $mail['body']
            );
            $this->assertStringContainsString("attached: yes\n", $mail['body']);
            $this->assertMatchesRegularExpression('/sha256: [0-9a-f]{64}\n/', $mail['body']);
        }

        $run = $DB->get_record('local_reportfeed_run', ['id' => $run->id]);
        $this->assertSame('sent', $run->status);
        $this->assertSame(1, (int) $run->attempts);
        $this->assertSame(4, $DB->count_records('local_reportfeed_delivery', ['runid' => $run->id, 'status' => 'sent']));
        $summary = json_decode($run->summary, true);
        $this->assertSame(2, $summary['files']['learner_course']['rows']);
        $this->assertSame(2, $summary['files']['learner_summary']['rows']);
        $this->assertArrayNotHasKey('path', $summary['files']['learner_course']);
        $after = $DB->get_record('local_reportfeed_schedule', ['id' => $schedule->id]);
        $this->assertSame('sent', $after->laststatus);
        $this->assertSame(self::NOW, (int) $after->lastrun);
        $this->assertSame((int) $schedule->timemodified, (int) $after->timemodified, 'a run is not an edit');
        $this->assertSame(0, $DB->count_records('files', ['component' => 'local_reportfeed', 'filearea' => 'attachment']));
    }

    /**
     * A retry never emails someone already served (M3 acceptance).
     */
    public function test_retry_does_not_email_served_recipients_again(): void {
        global $DB;
        $this->course();
        $a = $this->hr_user();
        $b = $this->hr_user();
        $this->schedule([$a, $b]);
        $run = $this->dispatch_one();
        (new run_manager())->execute($run->id);

        // Crash after sending but before the run was marked: same run, back in the queue.
        $DB->set_field('local_reportfeed_run', 'status', 'queued', ['id' => $run->id]);
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $this->assertSame(0, $sink->count());
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));

        // One delivery never got through: only that one is sent again.
        $delivery = $DB->get_record(
            'local_reportfeed_delivery',
            ['runid' => $run->id, 'userid' => $b->id, 'filekey' => 'learner_summary']
        );
        $DB->set_field('local_reportfeed_delivery', 'status', 'pending', ['id' => $delivery->id]);
        $DB->set_field('local_reportfeed_run', 'status', 'queued', ['id' => $run->id]);
        $sink->clear();
        (new run_manager())->execute($run->id);
        $mails = $this->mails($sink);
        $this->assertCount(1, $mails);
        $this->assertSame($b->email, $mails[0]['to']);
        $this->assertStringContainsString('learner_summary', $mails[0]['subject']);
    }

    /**
     * Suspended, deleted, address-less and unauthorised recipients are skipped with a reason, and nobody else is affected.
     */
    public function test_ineligible_recipients_are_skipped(): void {
        global $DB;
        $this->course();
        $good = $this->hr_user();
        $suspended = $this->hr_user();
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $deleted = $this->hr_user();
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);
        $noemail = $this->hr_user();
        $DB->set_field('user', 'email', '', ['id' => $noemail->id]);
        $nocap = $this->getDataGenerator()->create_user();
        $this->schedule([$good, $suspended, $deleted, $noemail, $nocap]);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $mails = $this->mails($sink);
        $this->assertCount(2, $mails);
        $this->assertSame([$good->email], array_unique(array_column($mails, 'to')));
        $reasons = $DB->get_records_menu(
            'local_reportfeed_delivery',
            ['filekey' => 'learner_course', 'status' => 'skipped'],
            '',
            'userid, reason'
        );
        ksort($reasons);
        $this->assertSame([
            $suspended->id => 'suspended', $deleted->id => 'deleted', $noemail->id => 'noemail', $nocap->id => 'nocapability',
        ], $reasons);
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * With nobody eligible the run is skipped, and a resend after the account is fixed serves it.
     */
    public function test_nobody_eligible_then_fixed_and_resent(): void {
        global $DB;
        $this->course();
        $user = $this->hr_user();
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->schedule([$user]);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $this->assertSame(0, $sink->count());
        $this->assertSame('skipped', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));

        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        $this->assertTrue((new run_manager())->resend($run->id));
        (new run_manager())->execute($run->id);
        $this->assertSame(2, $sink->count());
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * Attachments off: three attempts, then failed with the error; fixing the cause and resending sends everything.
     */
    public function test_failure_after_three_attempts_then_resend(): void {
        global $CFG, $DB;
        $this->course();
        $schedule = $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        $manager = new run_manager();
        $sink = $this->redirectEmails();
        $CFG->allowattachments = 0;

        foreach ([1, 2, 3] as $attempt) {
            try {
                $manager->execute($run->id);
                $this->fail('the attempt should throw so Moodle retries');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_attachments', $e->errorcode);
            }
            $row = $DB->get_record('local_reportfeed_run', ['id' => $run->id]);
            $this->assertSame($attempt, (int) $row->attempts);
            $this->assertSame($attempt < 3 ? 'queued' : 'failed', $row->status);
            $this->assertNotEmpty($row->error);
        }
        $this->assertSame(0, $sink->count());
        $this->assertSame('failed', $DB->get_field('local_reportfeed_schedule', 'laststatus', ['id' => $schedule->id]));
        $this->assertNotSame(0, (int) $DB->get_field('local_reportfeed_run', 'timefinished', ['id' => $run->id]));

        // A finished run is left alone if Moodle ever calls it again.
        $manager->execute($run->id);
        $this->assertSame(3, (int) $DB->get_field('local_reportfeed_run', 'attempts', ['id' => $run->id]));

        $CFG->allowattachments = 1;
        $this->assertTrue($manager->resend($run->id));
        $row = $DB->get_record('local_reportfeed_run', ['id' => $run->id]);
        $this->assertSame('queued', $row->status);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->error);
        $manager->execute($run->id);
        $this->assertSame(2, $sink->count());
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * A run that has been sent cannot be resent, and neither can one that does not exist.
     */
    public function test_resend_refuses_sent_and_missing_runs(): void {
        $this->course();
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        $manager = new run_manager();
        $this->redirectEmails();
        $manager->execute($run->id);
        $this->assertFalse($manager->resend($run->id));
        $this->assertFalse($manager->resend(987654));
    }

    /**
     * Above the size cap no file is attached and the email says so; the run is logged too_large.
     */
    public function test_too_large_sends_a_notice_without_the_file(): void {
        global $DB;
        $this->course();
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        set_config('sizecapmb', '0.0001', 'local_reportfeed');
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $mails = $this->mails($sink);
        $this->assertCount(2, $mails);
        foreach ($mails as $mail) {
            $this->assertSame([], $mail['files']);
            $this->assertStringContainsString("attached: no\n", $mail['body']);
            $this->assertStringContainsString('larger than the size cap', $mail['body']);
        }
        $this->assertSame('too_large', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * A course with many students, so a small cap splits the files.
     *
     * @param int $students
     */
    private function big_course(int $students): void {
        $course = $this->getDataGenerator()->create_course();
        for ($i = 0; $i < $students; $i++) {
            $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
    }

    /**
     * A report above the cap goes out as numbered parts, one email each, with every row exactly once.
     */
    public function test_large_report_is_split_into_numbered_parts(): void {
        global $DB;
        $this->big_course(25);
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        set_config('sizecapmb', '0.001', 'local_reportfeed'); // About 2 KB.
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $mails = $this->mails($sink);
        $course = array_values(array_filter($mails, fn($m) => str_contains($m['subject'], 'learner_course')));
        $parts = count($course);
        $this->assertGreaterThan(1, $parts);
        $rows = 0;
        foreach ($course as $mail) {
            $this->assertMatchesRegularExpression("/ \| part \d+ of $parts$/", $mail['subject']);
            $this->assertMatchesRegularExpression("/_v1_p\\d+of$parts\\.csv$/", $mail['files'][0]);
            $this->assertStringContainsString("parts: $parts\n", $mail['body']);
            $this->assertStringContainsString("attached: yes\n", $mail['body']);
            $rows += count(array_filter(explode("\r\n", $mail['csv'][$mail['files'][0]]))) - 1;
        }
        $this->assertSame(25, $rows);
        $partnumbers = array_map(fn($m) => (int) preg_replace('/.* part (\d+) of .*/', '$1', $m['subject']), $course);
        sort($partnumbers);
        $this->assertSame(range(1, $parts), $partnumbers);

        $run = $DB->get_record('local_reportfeed_run', ['id' => $run->id]);
        $this->assertSame('sent', $run->status);
        $summary = json_decode($run->summary, true);
        $this->assertSame($parts, $summary['files']['learner_course']['parts']);
        $this->assertSame(25, $summary['files']['learner_course']['rows']);
        $this->assertSame($parts, $summary['plan']['learner_course']);
    }

    /**
     * A retry sends only the part that was not delivered, and nothing when all parts went out.
     */
    public function test_retry_sends_only_the_missing_part(): void {
        global $DB;
        $this->big_course(25);
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        set_config('sizecapmb', '0.001', 'local_reportfeed');
        (new run_manager())->execute($run->id);

        $DB->set_field('local_reportfeed_run', 'status', 'queued', ['id' => $run->id]);
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $this->assertSame(0, $sink->count());

        $delivery = $DB->get_record('local_reportfeed_delivery', ['runid' => $run->id, 'filekey' => 'learner_course', 'part' => 2]);
        $DB->set_field('local_reportfeed_delivery', 'status', 'pending', ['id' => $delivery->id]);
        $DB->set_field('local_reportfeed_run', 'status', 'queued', ['id' => $run->id]);
        $sink->clear();
        (new run_manager())->execute($run->id);
        $mails = $this->mails($sink);
        $this->assertCount(1, $mails);
        $this->assertMatchesRegularExpression('/learner_course .* \| part 2 of \d+$/', $mails[0]['subject']);
    }

    /**
     * Above the part limit nothing is attached, and the notice says how many parts it would need.
     */
    public function test_too_many_parts_sends_a_notice(): void {
        global $DB;
        $this->big_course(60);
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        set_config('sizecapmb', '0.002', 'local_reportfeed');
        set_config('maxparts', '2', 'local_reportfeed');
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $mails = $this->mails($sink);
        $notice = array_values(array_filter($mails, fn($m) => str_contains($m['subject'], 'learner_course')));
        $this->assertCount(1, $notice);
        $this->assertSame([], $notice[0]['files']);
        $this->assertStringContainsString('more than the limit of 2', $notice[0]['body']);
        $this->assertStringContainsString("attached: no\n", $notice[0]['body']);
        $this->assertSame('too_large', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * The Excel format attaches .xlsx files with the same rows.
     */
    public function test_excel_format(): void {
        $this->course();
        $this->schedule([$this->hr_user()], ['format' => 'xlsx']);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $mails = $this->mails($sink);
        $this->assertCount(2, $mails);
        foreach ($mails as $mail) {
            $this->assertMatchesRegularExpression('/_v1\.xlsx$/', $mail['files'][0]);
            $this->assertStringStartsWith('PK', $mail['csv'][$mail['files'][0]], 'an xlsx file is a zip container');
        }
    }

    /**
     * The zip option attaches .zip files.
     */
    public function test_zip_option(): void {
        $this->course();
        $this->schedule([$this->hr_user()], ['zip' => 1]);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $mails = $this->mails($sink);
        $this->assertCount(2, $mails);
        foreach ($mails as $mail) {
            $this->assertMatchesRegularExpression('/_v1\.zip$/', $mail['files'][0]);
        }
    }

    /**
     * A run that would start more than 24 hours after its moment is abandoned, and a resend gives it a fresh start.
     */
    public function test_expiry(): void {
        global $DB;
        $this->course();
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        $this->clock->bump(DAYSECS);
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $this->assertSame('expired', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
        $this->assertSame(0, $sink->count());

        $this->assertTrue((new run_manager())->resend($run->id));
        (new run_manager())->execute($run->id);
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * Switching the plugin off after a run was queued stops the sending.
     */
    public function test_master_switch_off_skips_the_run(): void {
        global $DB;
        $this->course();
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        set_config('enabled', 0, 'local_reportfeed');
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $this->assertSame(0, $sink->count());
        $this->assertSame('skipped', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * An empty report is still sent: header row only, zero rows.
     */
    public function test_empty_run_is_still_sent(): void {
        global $DB;
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();
        (new run_manager())->execute($run->id);
        $this->assertSame(2, $sink->count());
        $summary = json_decode($DB->get_field('local_reportfeed_run', 'summary', ['id' => $run->id]), true);
        $this->assertSame(0, $summary['files']['learner_course']['rows']);
    }

    /**
     * Scope: all courses, listed courses, or categories including their subcategories.
     */
    public function test_scope(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $parent = $gen->create_category();
        $child = $gen->create_category(['parent' => $parent->id]);
        $other = $gen->create_category();
        $c1 = $gen->create_course(['category' => $child->id]);
        $c2 = $gen->create_course(['category' => $other->id]);
        $c3 = $gen->create_course(['category' => $parent->id]);
        foreach ([$c1, $c2, $c3] as $course) {
            $gen->create_and_enrol($course, 'student');
        }
        $user = $this->hr_user();
        $bundleid = \local_reportfeed\local\bundles::save((object) ['name' => 'Two', 'courseids' => [$c1->id, $c3->id]]);
        $cases = [
            ['bundle', (string) $bundleid, 2],
            ['bundle', '9999', 0],
            ['all', '', 3],
            ['courses', "{$c2->id}", 1],
            ['courses', "{$c1->id},{$c3->id}", 2],
            ['categories', "{$parent->id}", 2],
            ['categories', "{$other->id}", 1],
            ['categories', '', 0],
        ];
        $this->redirectEmails();
        foreach ($cases as $i => [$scope, $ids, $rows]) {
            $DB->delete_records('local_reportfeed_run');
            $DB->delete_records('local_reportfeed_delivery');
            $DB->delete_records('local_reportfeed_schedule');
            $DB->delete_records('local_reportfeed_recipient');
            $this->schedule([$user], ['scope' => $scope, 'scopeids' => $ids]);
            $run = $this->dispatch_one();
            (new run_manager())->execute($run->id);
            $summary = json_decode($DB->get_field('local_reportfeed_run', 'summary', ['id' => $run->id]), true);
            $this->assertSame($rows, $summary['files']['learner_course']['rows'], "case $i: $scope $ids");
        }
    }

    /**
     * Run now: one manual run per schedule and day, and it does not use up the scheduled send.
     */
    public function test_run_now(): void {
        global $DB;
        $this->course();
        $a = $this->hr_user();
        $schedule = $this->schedule([$a]);
        $manager = new run_manager();

        $this->assertTrue($manager->run_now($schedule->id));
        $this->assertFalse($manager->run_now($schedule->id), 'a double click queues nothing new');
        $this->assertSame(1, $manager->dispatch(), 'the scheduled send is still created');
        $this->assertSame(2, $DB->count_records('local_reportfeed_run'));
        $this->assertSame(1, $DB->count_records('local_reportfeed_run', ['manual' => 1]));

        $manual = $DB->get_record('local_reportfeed_run', ['manual' => 1], '*', MUST_EXIST);
        $sink = $this->redirectEmails();
        $manager->execute($manual->id);
        $this->assertCount(2, $this->mails($sink));
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $manual->id]));
    }

    /**
     * Send test: the real files to one person, logged nowhere, and nothing left in the file table.
     */
    public function test_send_test(): void {
        global $DB;
        $this->course();
        $schedule = $this->schedule([$this->hr_user()]);
        $admin = get_admin();
        $sink = $this->redirectEmails();

        $this->assertSame(2, (new run_manager())->send_test($schedule->id, $admin));

        $mails = $this->mails($sink);
        $this->assertCount(2, $mails);
        foreach ($mails as $mail) {
            $this->assertSame($admin->email, $mail['to']);
            $this->assertStringContainsString("run_id: 0\n", $mail['body']);
            $this->assertMatchesRegularExpression('/^\[Reportfeed\] hr_feed \| learner_(course|summary) \|/', $mail['subject']);
        }
        $this->assertSame(0, $DB->count_records('local_reportfeed_run'));
        $this->assertSame(0, $DB->count_records('files', ['component' => 'local_reportfeed', 'filearea' => 'attachment']));

        set_config('allowattachments', 0);
        $this->expectException(\moodle_exception::class);
        (new run_manager())->send_test($schedule->id, $admin);
    }

    /**
     * Only failed, expired, skipped and too-large runs can be resent.
     */
    public function test_resendable_statuses(): void {
        global $DB;
        $this->course();
        $this->schedule([$this->hr_user()]);
        $run = $this->dispatch_one();
        foreach (['queued', 'running', 'sent'] as $status) {
            $DB->set_field('local_reportfeed_run', 'status', $status, ['id' => $run->id]);
            $this->assertFalse((new run_manager())->resend($run->id), $status);
        }
        foreach (run_manager::RESENDABLE as $status) {
            $DB->set_field('local_reportfeed_run', 'status', $status, ['id' => $run->id]);
            $this->assertTrue((new run_manager())->resend($run->id), $status);
        }
    }

    /**
     * An external address gets the same files as an internal recipient, and the delivery row keeps the address.
     */
    public function test_external_recipient_gets_the_files(): void {
        global $DB;
        set_config('allowexternal', 1, 'local_reportfeed');
        $this->course();
        $a = $this->hr_user();
        $schedule = $this->schedule([$a]);
        \local_reportfeed\local\external::store($schedule->id, "hr@partner.example\nsecond@other.example");
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $mails = $this->mails($sink);
        $this->assertCount(6, $mails);
        $to = array_column($mails, 'to');
        $this->assertSame(2, count(array_keys($to, 'hr@partner.example')));
        $this->assertSame(2, count(array_keys($to, 'second@other.example')));
        $this->assertSame(2, count(array_keys($to, $a->email)));
        $rows = $DB->get_records_select('local_reportfeed_delivery', 'runid = ? AND extid > 0', [$run->id]);
        $this->assertCount(4, $rows);
        $this->assertEqualsCanonicalizing(
            ['hr@partner.example', 'second@other.example'],
            array_unique(array_column($rows, 'email'))
        );
        (new run_manager())->execute($run->id);
        $this->assertCount(6, $this->mails($sink), 'a repeat sends nothing');
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * With the site setting off an external address is skipped with a reason; the internal recipient is still served.
     */
    public function test_external_recipient_skipped_when_setting_is_off(): void {
        global $DB;
        set_config('allowexternal', 0, 'local_reportfeed');
        $this->course();
        $a = $this->hr_user();
        $schedule = $this->schedule([$a]);
        \local_reportfeed\local\external::store($schedule->id, 'hr@partner.example');
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $mails = $this->mails($sink);
        $this->assertCount(2, $mails);
        $this->assertSame([$a->email], array_unique(array_column($mails, 'to')));
        $this->assertSame(2, $DB->count_records('local_reportfeed_delivery', [
            'runid' => $run->id, 'status' => 'skipped', 'reason' => 'externaldisabled',
        ]));

        // A partly served run is "sent": the skipped address waits for the next run, and a repeat sends nothing.
        set_config('allowexternal', 1, 'local_reportfeed');
        $this->assertFalse((new run_manager())->resend($run->id));
        (new run_manager())->execute($run->id);
        $this->assertCount(2, $this->mails($sink));
    }

    /**
     * The activity files the administrator chose are sent beside the two HR files, with only the chosen columns.
     */
    public function test_activity_files_are_sent(): void {
        $course = $this->course();
        global $DB;
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => self::NOW + DAYSECS]);
        $user = $this->hr_user();
        $opts = json_encode(['groups' => [
            'activity_completion' => ['fields' => ['activity_type'], 'filter' => 'all', 'modtypes' => []],
            'activity_assignments' => ['fields' => ['due_date', 'overdue'], 'filter' => 'all', 'modtypes' => []],
        ], 'window' => '']);
        $this->schedule([$user], ['activityopts' => $opts, 'identitycols' => 'idnumber']);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $mails = $this->mails($sink);
        $this->assertCount(4, $mails);
        $site = \local_reportfeed\local\mailer::site_slug();
        $subjects = array_column($mails, 'subject');
        sort($subjects);
        $this->assertSame([
            "[Reportfeed] hr_feed | activity_assignments | $site | 2026-10-06",
            "[Reportfeed] hr_feed | activity_completion | $site | 2026-10-06",
            "[Reportfeed] hr_feed | learner_course | $site | 2026-10-06",
            "[Reportfeed] hr_feed | learner_summary | $site | 2026-10-06",
        ], $subjects);
        $by = array_column($mails, null, 'subject');
        $completion = reset($by["[Reportfeed] hr_feed | activity_completion | $site | 2026-10-06"]['csv']);
        $lines = preg_split('/\r?\n/', trim($completion));
        $this->assertSame(
            'user_id,idnumber,course_id,course_shortname,cm_id,activity_name,activity_type',
            $lines[0]
        );
        $this->assertCount(3, $lines, 'two learners times one tracked page');
        $assign = reset($by["[Reportfeed] hr_feed | activity_assignments | $site | 2026-10-06"]['csv']);
        $this->assertStringStartsWith('user_id,idnumber,course_id,course_shortname,cm_id,activity_name,due_date,overdue', $assign);
        $summary = json_decode($DB->get_field('local_reportfeed_run', 'summary', ['id' => $run->id]), true);
        $this->assertSame(2, $summary['files']['activity_completion']['rows']);
        $this->assertSame(
            ['learner_course', 'learner_summary', 'activity_completion', 'activity_assignments'],
            array_keys($summary['plan'])
        );
    }

    /**
     * An activity file over the site row limit is not built; recipients get a notice instead.
     */
    public function test_activity_file_over_the_row_limit_sends_a_notice(): void {
        global $DB;
        $course = $this->course();
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        set_config('activitymaxrows', 1, 'local_reportfeed');
        $opts = json_encode(['groups' => ['activity_completion' => ['fields' => [], 'filter' => 'all']], 'window' => '']);
        $this->schedule([$this->hr_user()], ['activityopts' => $opts]);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $site = \local_reportfeed\local\mailer::site_slug();
        $by = array_column($this->mails($sink), null, 'subject');
        $mail = $by["[Reportfeed] hr_feed | activity_completion | $site | 2026-10-06"];
        $this->assertSame([], $mail['files']);
        $this->assertStringContainsString("attached: no\n", $mail['body']);
        $this->assertStringContainsString('more than 1 rows', $mail['body']);
        $this->assertSame('too_large', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * Without the standard log the engagement file is left out and the run says so; the other files still go.
     */
    public function test_engagement_is_left_out_without_the_log(): void {
        global $DB;
        $this->course();
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $opts = json_encode(['groups' => ['activity_engagement' => ['fields' => ['course_views']]], 'window' => '']);
        $this->schedule([$this->hr_user()], ['activityopts' => $opts]);
        $run = $this->dispatch_one();
        $sink = $this->redirectEmails();

        (new run_manager())->execute($run->id);

        $this->assertCount(2, $this->mails($sink));
        $summary = json_decode($DB->get_field('local_reportfeed_run', 'summary', ['id' => $run->id]), true);
        $this->assertSame(['activity_engagement' => 'nolog'], $summary['unavailable']);
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    /**
     * The engagement window: since the previous send, the month just ended, or the month so far (site time).
     */
    public function test_engagement_window(): void {
        $due = self::NOW; // Tuesday 6 October 2026 12:00 UTC.
        $weekly = (object) ['frequency' => 'weekly', 'weekday' => 2, 'hour' => 8, 'minute' => 0, 'activityopts' => null];
        $moment = $due - 4 * HOURSECS; // This morning's 08:00, the scheduled moment.
        $this->assertSame([$moment - 7 * DAYSECS, $moment], run_manager::window($weekly, $moment));
        $monthly = (object) ['frequency' => 'monthly', 'activityopts' => null];
        $this->assertSame([gmmktime(0, 0, 0, 9, 1, 2026), gmmktime(0, 0, 0, 10, 1, 2026)], run_manager::window($monthly, $due));
        $todate = (object) [
            'frequency' => 'monthly', 'activityopts' => json_encode(['groups' => [], 'window' => 'monthtodate']),
        ];
        $this->assertSame([gmmktime(0, 0, 0, 10, 1, 2026), $due], run_manager::window($todate, $due));
        // Across a year end.
        $january = gmmktime(9, 0, 0, 1, 15, 2027);
        $this->assertSame([gmmktime(0, 0, 0, 12, 1, 2026), gmmktime(0, 0, 0, 1, 1, 2027)], run_manager::window($monthly, $january));
    }
}
