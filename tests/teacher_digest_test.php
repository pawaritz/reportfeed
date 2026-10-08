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

use local_reportfeed\local\digest_settings;
use local_reportfeed\local\mailer;
use local_reportfeed\local\run_manager;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mail_reader.php');

/**
 * Teacher digests: who gets one, when, what it holds, and that it never leaks.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(digest_settings::class)]
#[CoversClass(run_manager::class)]
final class teacher_digest_test extends \advanced_testcase {
    /** @var int Frozen "now": Monday 2026-10-05 12:00 UTC (the default digest moment, Monday 07:00, was 5 hours ago). */
    private const NOW = 1791201600;

    /** @var int|null Role that may receive the digest but may not view grades. */
    private ?int $nogradesrole = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setTimezone('UTC', 'UTC');
        $this->mock_clock_with_frozen(self::NOW);
        set_config('enabled', 1, 'local_reportfeed');
        set_config('teacherdigestmode', 'optin', 'local_reportfeed');
    }

    /**
     * A course with three students.
     *
     * @param array $extra course fields
     * @return \stdClass
     */
    private function course(array $extra = []): \stdClass {
        $course = $this->getDataGenerator()->create_course($extra + ['enablecompletion' => 1]);
        for ($i = 0; $i < 3; $i++) {
            $this->getDataGenerator()->create_and_enrol($course, 'student');
        }
        return $course;
    }

    /**
     * A teacher in a course.
     *
     * @param \stdClass $course
     * @param string $role role shortname
     * @return \stdClass
     */
    private function teacher(\stdClass $course, string $role = 'editingteacher'): \stdClass {
        return $this->getDataGenerator()->create_and_enrol($course, $role);
    }

    /**
     * A teacher who holds the digest capability but cannot view grades.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    private function teacher_without_grades(\stdClass $course): \stdClass {
        if ($this->nogradesrole === null) {
            $this->nogradesrole = $this->getDataGenerator()->create_role(['shortname' => 'rfnogrades']);
            assign_capability(
                'local/reportfeed:receiveteacherdigest',
                CAP_ALLOW,
                $this->nogradesrole,
                \context_system::instance()->id
            );
        }
        return $this->getDataGenerator()->create_and_enrol($course, 'rfnogrades');
    }

    /**
     * Store a teacher's settings for a course: Monday 08:00, edited ten days ago.
     *
     * @param \stdClass $teacher
     * @param \stdClass $course
     * @param array $extra settings to override
     */
    private function settings(\stdClass $teacher, \stdClass $course, array $extra = []): void {
        global $DB;
        digest_settings::save($teacher->id, $course->id, (object) ($extra + [
            'enabled' => 1, 'weekday' => 1, 'hour' => 8,
            'choices' => ['completion_status', 'completion_percent', 'grade'],
        ]));
        $DB->set_field('local_reportfeed_digestcourse', 'timemodified', self::NOW - 10 * DAYSECS, [
            'userid' => $teacher->id, 'courseid' => $course->id,
        ]);
    }

    /**
     * Dispatch, then execute every queued run, and return the captured emails.
     *
     * @return array[]
     */
    private function send_all(): array {
        global $DB;
        $sink = $this->redirectEmails();
        $manager = new run_manager();
        $manager->dispatch();
        foreach ($DB->get_fieldset_select('local_reportfeed_run', 'id', "status = 'queued'") as $id) {
            $manager->execute((int) $id);
        }
        return mail_reader::read($sink);
    }

    /**
     * The data rows of a CSV, each as a list of cells (the header is returned by header()).
     *
     * @param string $csv
     * @return array[]
     */
    private function rows(string $csv): array {
        $lines = array_filter(explode("\r\n", $csv), fn($l) => $l !== '');
        return array_map('str_getcsv', array_slice(array_values($lines), 1));
    }

    /**
     * The header cells of a CSV.
     *
     * @param string $csv
     * @return string[]
     */
    private function header(string $csv): array {
        return str_getcsv(explode("\r\n", $csv)[0]);
    }

    public function test_mode_off_sends_nothing(): void {
        global $DB;
        $course = $this->course();
        $this->settings($this->teacher($course), $course);
        set_config('teacherdigestmode', 'off', 'local_reportfeed');
        $this->assertSame(0, (new run_manager())->dispatch());
        $this->assertSame(0, $DB->count_records('local_reportfeed_run'));
    }

    public function test_optin_only_runs_for_enabled_settings(): void {
        global $DB;
        $course = $this->course();
        $on = $this->teacher($course);
        $off = $this->teacher($course);
        $this->teacher($course);
        $this->settings($on, $course);
        $this->settings($off, $course, ['enabled' => 0]);

        $manager = new run_manager();
        $this->assertSame(1, $manager->dispatch());
        $this->assertSame(0, $manager->dispatch(), 'a second dispatch creates nothing');
        $run = $DB->get_record('local_reportfeed_run', [], '*', MUST_EXIST);
        $this->assertSame('teacherdigest', $run->type);
        $this->assertEquals([$on->id, $course->id], [$run->userid, $run->courseid]);
    }

    public function test_settings_edited_after_the_moment_do_not_send_for_the_past(): void {
        global $DB;
        $course = $this->course();
        $teacher = $this->teacher($course);
        $this->settings($teacher, $course);
        $DB->set_field('local_reportfeed_digestcourse', 'timemodified', self::NOW - 60);
        $this->assertSame(0, (new run_manager())->dispatch());
    }

    public function test_optout_default_for_every_teacher_once(): void {
        global $DB;
        set_config('teacherdigestmode', 'optout', 'local_reportfeed');
        $one = $this->course();
        $two = $this->course();
        $hidden = $this->course(['visible' => 0]);
        $ended = $this->course(['enddate' => self::NOW - DAYSECS, 'startdate' => self::NOW - 90 * DAYSECS]);
        $a = $this->teacher($one);
        $b = $this->teacher($one);
        $c = $this->teacher($two);
        $this->teacher($hidden);
        $this->teacher($ended);
        $this->teacher($one, 'student');
        // One teacher switched their own course off: their row wins over the default.
        $this->settings($b, $one, ['enabled' => 0]);

        $manager = new run_manager();
        $this->assertSame(2, $manager->dispatch());
        $users = $DB->get_fieldset_select('local_reportfeed_run', 'userid', "type = 'teacherdigest'");
        $this->assertEqualsCanonicalizing([$a->id, $c->id], array_map('intval', $users));
        $this->assertSame(0, $manager->dispatch(), 'the expansion happens once per default moment');
    }

    public function test_switching_on_never_sends_for_the_past(): void {
        set_config('teacherdigestmode', 'optout', 'local_reportfeed');
        $course = $this->course();
        $this->teacher($course);
        $this->settings($this->teacher($course), $course);
        // The admin switched the mode on after this morning's default moment.
        set_config('switchtime', self::NOW - 60, 'local_reportfeed');
        $this->assertSame(0, (new run_manager())->dispatch());
    }

    public function test_one_email_per_enabled_course_with_the_contract_shape(): void {
        global $DB;
        $one = $this->course(['shortname' => 'BIO 101']);
        $two = $this->course(['shortname' => 'CHEM 202']);
        $teacher = $this->teacher($one);
        $this->getDataGenerator()->enrol_user($teacher->id, $two->id, 'editingteacher');
        $this->settings($teacher, $one);
        $this->settings($teacher, $two);

        $mails = $this->send_all();
        $this->assertCount(2, $mails);
        $site = mailer::site_slug();
        $subjects = array_column($mails, 'subject');
        sort($subjects);
        $this->assertSame([
            "[Reportfeed] teacher_digest | $site | BIO 101 | 2026-10-05",
            "[Reportfeed] teacher_digest | $site | CHEM 202 | 2026-10-05",
        ], $subjects);
        foreach ($mails as $mail) {
            $this->assertSame($teacher->email, $mail['to']);
            $this->assertCount(1, $mail['files']);
            $this->assertMatchesRegularExpression(
                '/^teacher_digest_' . preg_quote($site) . '_c\d+_2026-10-05_v1\.csv$/',
                $mail['files'][0]
            );
            $this->assertStringContainsString("attached: yes\n", $mail['body']);
            $csv = $mail['csv'][$mail['files'][0]];
            $this->assertSame([
                'course_id', 'course_shortname', 'user_id', 'fullname',
                'completion_status', 'completion_percent', 'grade',
            ], $this->header($csv));
            $this->assertCount(3, $this->rows($csv));
        }
        $this->assertSame(2, $DB->count_records('local_reportfeed_run', ['status' => 'sent']));
    }

    /**
     * The email opens with counts only (never a learner's name), links to the course and to the settings page.
     */
    public function test_email_summary_is_counts_only_with_links(): void {
        $course = $this->course(['shortname' => 'BIO 101']);
        $teacher = $this->teacher($course);
        $this->settings($teacher, $course);
        $names = array_map(
            fn($u) => fullname($u),
            get_enrolled_users(\context_course::instance($course->id), '', 0, 'u.*')
        );

        $mails = $this->send_all();

        $this->assertCount(1, $mails);
        $body = $mails[0]['body'];
        $this->assertStringContainsString("Learners in your view of this course: 3.\n", $body);
        $this->assertStringContainsString("Completed: 0. In progress: 0. Not started: 3.\n", $body);
        $this->assertStringContainsString('Not seen in the course for 14 days or more: 0.', $body);
        $this->assertStringContainsString("/local/reportfeed/digest.php?courseid={$course->id}", $body);
        $this->assertStringContainsString("/course/view.php?id={$course->id}", $body);
        $this->assertStringContainsString('/message/notificationpreferences.php', $body);
        $this->assertStringContainsString("attached: yes\n", $body, 'the machine block is still there');
        foreach ($names as $name) {
            $this->assertStringNotContainsString($name, $body);
        }
        // The HTML copy: the same facts as a list and links.
        $html = mailer::digest_html(
            ['course' => $course, 'stats' => [
                'learners' => 3, 'completed' => 1, 'in_progress' => 1, 'not_started' => 1, 'inactive' => 2, 'sum' => 150.0,
                'counted' => 3, 'tracking' => true, 'days' => 14,
            ]],
            "file: x\n"
        );
        $this->assertStringContainsString('<li>Completed: 1. In progress: 1. Not started: 1.</li>', $html);
        $this->assertStringContainsString('Average completion: 50.0%.', $html);
        $this->assertStringContainsString('digest.php?courseid=' . $course->id, $html);
        $this->assertStringContainsString('<pre', $html);
    }

    /**
     * The administrator's own words frame the digest email, as escaped plain text, and only the digest.
     */
    public function test_admin_intro_and_footer_frame_the_email(): void {
        set_config('digestintro', "Hello from the learning team\n<b>keep going</b>", 'local_reportfeed');
        set_config('digestfooter', 'Questions: help@example.org', 'local_reportfeed');
        $course = $this->course();
        $this->settings($this->teacher($course), $course);

        $body = $this->send_all()[0]['body'];

        // Moodle's mail layer renders tags in the plain part as text, so only the words are compared here.
        $this->assertStringContainsString("Hello from the learning team\n", $body);
        $this->assertLessThan(strpos($body, 'Weekly digest'), strpos($body, 'Hello from the learning team'));
        $this->assertStringContainsString("Questions: help@example.org\n", $body);
        $this->assertLessThan(strpos($body, "attached: yes"), strpos($body, 'help@example.org'));

        $stats = [
            'learners' => 1, 'completed' => 0, 'in_progress' => 0, 'not_started' => 1, 'inactive' => 0, 'sum' => 0.0,
            'counted' => 0, 'tracking' => true, 'days' => 14,
        ];
        $html = mailer::digest_html(['course' => $course, 'stats' => $stats], "file: x\n");
        $this->assertStringContainsString('&lt;b&gt;keep going&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>keep going</b>', $html);
        $this->assertStringContainsString('Questions: help@example.org', $html);

        set_config('digestintro', '', 'local_reportfeed');
        set_config('digestfooter', str_repeat('x', 1500), 'local_reportfeed');
        $text = mailer::digest_text(['course' => $course, 'stats' => $stats]);
        $this->assertStringContainsString(str_repeat('x', 1000), $text);
        $this->assertStringNotContainsString(str_repeat('x', 1001), $text);
    }

    public function test_retry_sends_no_second_email(): void {
        global $DB;
        $course = $this->course();
        $this->settings($this->teacher($course), $course);
        $this->assertCount(1, $this->send_all());

        $runid = $DB->get_field('local_reportfeed_run', 'id', ['type' => 'teacherdigest']);
        $DB->set_field('local_reportfeed_run', 'status', 'queued', ['id' => $runid]);
        $sink = $this->redirectEmails();
        (new run_manager())->execute((int) $runid);
        $this->assertSame(0, $sink->count());
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $runid]));
    }

    public function test_course_name_cannot_break_the_subject(): void {
        $this->assertSame(
            '[Reportfeed] teacher_digest | ' . mailer::site_slug() . ' | A B X | 2026-10-05',
            mailer::digest_subject("A|B\r\nX", 20261005)
        );
    }

    public function test_separate_groups_limit_a_teacher_to_their_own_groups(): void {
        global $DB;
        $course = $this->course();
        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $course->id]);
        $students = array_values(array_map(
            fn($r) => $r->userid,
            $DB->get_records_sql(
                'SELECT ue.userid FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = ? ORDER BY ue.userid',
                [$course->id]
            )
        ));
        $mine = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $other = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $students[0], 'groupid' => $mine->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $students[1], 'groupid' => $mine->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $students[2], 'groupid' => $other->id]);

        $grouped = $this->teacher($course, 'teacher');
        $ungrouped = $this->teacher($course, 'teacher');
        $all = $this->teacher($course);
        $this->getDataGenerator()->create_group_member(['userid' => $grouped->id, 'groupid' => $mine->id]);
        foreach ([$grouped, $ungrouped, $all] as $teacher) {
            $this->settings($teacher, $course, ['choices' => []]);
        }

        $counts = [];
        foreach ($this->send_all() as $mail) {
            $counts[$mail['to']] = count($this->rows($mail['csv'][$mail['files'][0]]));
        }
        $this->assertSame([
            $grouped->email => 2,
            $ungrouped->email => 0,
            $all->email => 3,
        ], $counts);
    }

    public function test_a_recipient_without_grade_permission_never_gets_grade_columns(): void {
        $course = $this->course();
        $viewer = $this->teacher_without_grades($course);
        $this->settings($viewer, $course, ['choices' => ['completion_status', 'grade', 'grade_percent']]);

        $mails = $this->send_all();
        $this->assertCount(1, $mails);
        $header = $this->header($mails[0]['csv'][$mails[0]['files'][0]]);
        $this->assertSame(['course_id', 'course_shortname', 'user_id', 'fullname', 'completion_status'], $header);
    }

    public function test_a_nominee_gets_only_what_their_own_permissions_allow(): void {
        global $DB;
        $course = $this->course();
        $nominator = $this->teacher($course);
        $nominee = $this->teacher_without_grades($course);
        $this->settings($nominator, $course, ['choices' => ['completion_status', 'grade']]);
        digest_settings::nominate($nominator->id, $course->id, [$nominee->id]);
        $DB->set_field('local_reportfeed_digestcourse', 'timemodified', self::NOW - 10 * DAYSECS);

        $headers = [];
        foreach ($this->send_all() as $mail) {
            $headers[$mail['to']] = $this->header($mail['csv'][$mail['files'][0]]);
        }
        $this->assertContains('grade', $headers[$nominator->email]);
        $this->assertNotContains('grade', $headers[$nominee->email]);
        $this->assertContains('completion_status', $headers[$nominee->email]);
    }

    public function test_suspended_learners_never_appear(): void {
        $course = $this->course();
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['suspended' => 1]);
        $this->getDataGenerator()->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->settings($this->teacher($course), $course, ['choices' => []]);

        $mails = $this->send_all();
        $this->assertCount(3, $this->rows($mails[0]['csv'][$mails[0]['files'][0]]));
    }

    public function test_a_recipient_who_lost_the_capability_is_skipped_then_served_after_resend(): void {
        global $DB;
        $course = $this->course();
        $teacher = $this->teacher($course);
        $this->settings($teacher, $course);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(
            'local/reportfeed:receiveteacherdigest',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );

        $this->assertCount(0, $this->send_all());
        $run = $DB->get_record('local_reportfeed_run', ['type' => 'teacherdigest'], '*', MUST_EXIST);
        $this->assertSame('skipped', $run->status);
        $this->assertSame('nocapability', $DB->get_field('local_reportfeed_delivery', 'reason', ['runid' => $run->id]));

        unassign_capability('local/reportfeed:receiveteacherdigest', $roleid, \context_course::instance($course->id)->id);
        $sink = $this->redirectEmails();
        $manager = new run_manager();
        $this->assertTrue($manager->resend((int) $run->id));
        $manager->execute((int) $run->id);
        $this->assertSame(1, $sink->count());
        $this->assertSame('sent', $DB->get_field('local_reportfeed_run', 'status', ['id' => $run->id]));
    }

    public function test_settings_modes_and_defaults(): void {
        $course = $this->course();
        $teacher = $this->teacher($course);

        $this->assertNull(digest_settings::effective($teacher->id, $course->id), 'optin: nothing until they opt in');
        set_config('teacherdigestmode', 'optout', 'local_reportfeed');
        $default = digest_settings::effective($teacher->id, $course->id);
        $this->assertSame([1, 7], [$default->weekday, $default->hour]);
        $this->assertNotContains('grade', $default->choices, 'grades are never on by default');
        $this->settings($teacher, $course, ['enabled' => 0]);
        $this->assertNull(digest_settings::effective($teacher->id, $course->id), 'their own off wins over the default');
        set_config('teacherdigestmode', 'off', 'local_reportfeed');
        $this->settings($teacher, $course);
        $this->assertNull(digest_settings::effective($teacher->id, $course->id), 'off stops everything');
    }

    public function test_nominations_and_validation(): void {
        global $DB;
        $course = $this->course();
        $a = $this->teacher($course);
        $b = $this->teacher($course);
        $c = $this->teacher($course);
        $plain = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->settings($a, $course);
        $this->settings($c, $course, ['weekday' => 4]);

        digest_settings::nominate($a->id, $course->id, [$b->id, $c->id, $a->id]);
        $this->assertSame(
            [(int) $b->id],
            digest_settings::nominees($course->id, $a->id),
            'c keeps their own row, a is not self-nominated'
        );
        $this->assertSame('4', $DB->get_field('local_reportfeed_digestcourse', 'weekday', ['userid' => $c->id]));

        digest_settings::nominate($a->id, $course->id, []);
        $this->assertSame([], digest_settings::nominees($course->id, $a->id));
        $this->assertFalse($DB->record_exists('local_reportfeed_digestcourse', ['userid' => $b->id]));

        $errors = digest_settings::errors(['weekday' => 9, 'hour' => 8, 'nominees' => [$plain->id, $a->id]], $course->id, $a->id);
        $this->assertArrayHasKey('when', $errors);
        $this->assertArrayHasKey('nominees', $errors);
        $this->assertSame([], digest_settings::errors(['weekday' => 1, 'hour' => 8, 'nominees' => [$b->id]], $course->id, $a->id));
    }
}
