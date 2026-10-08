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
 * Builds and sends one Reportfeed email through the Message API (ADR-003).
 *
 * One file per email, because the Message API takes a single attachment (confirmed in the 5.3 source).
 * "Sent" means handed to Moodle mail; Moodle cannot see the remote mailbox (change C4).
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mailer {
    /**
     * The site short name as it appears in subjects and file names: lower case letters, digits, dash, underscore.
     *
     * @return string
     */
    public static function site_slug(): string {
        $slug = trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower(get_site()->shortname)), '-');
        return $slug === '' ? 'moodle' : $slug;
    }

    /**
     * A YYYYMMDD period end as YYYY-MM-DD.
     *
     * @param int $periodend
     * @return string
     */
    public static function date(int $periodend): string {
        return sprintf('%04d-%02d-%02d', intdiv($periodend, 10000), intdiv($periodend, 100) % 100, $periodend % 100);
    }

    /**
     * Attachment name: <file>_<site>_<YYYY-MM-DD>_v1.<ext>, with _p<part>of<parts> before the extension for a split report.
     *
     * @param string $file learner_course, learner_summary, an activity file or teacher_digest
     * @param int $periodend YYYYMMDD
     * @param string $ext csv, xlsx or zip
     * @param int $courseid the course, for a teacher digest (its name carries c<id>)
     * @param int $part part number, 1 when the report is not split
     * @param int $parts number of parts
     * @return string
     */
    public static function filename(
        string $file,
        int $periodend,
        string $ext = 'csv',
        int $courseid = 0,
        int $part = 1,
        int $parts = 1
    ): string {
        $course = $file === 'teacher_digest' ? '_c' . $courseid : '';
        $split = $parts > 1 ? "_p{$part}of{$parts}" : '';
        return $file . '_' . self::site_slug() . $course . '_' . self::date($periodend) . '_v' . contract::SCHEMA
            . $split . '.' . $ext;
    }

    /**
     * Subject: [Reportfeed] hr_feed | <file> | <site> | <YYYY-MM-DD>, plus | part N of M for a split report.
     * The tag never changes.
     *
     * @param string $file learner_course or learner_summary
     * @param int $periodend YYYYMMDD
     * @param int $part part number
     * @param int $parts number of parts
     * @return string
     */
    public static function subject(string $file, int $periodend, int $part = 1, int $parts = 1): string {
        $split = $parts > 1 ? " | part $part of $parts" : '';
        return "[Reportfeed] hr_feed | $file | " . self::site_slug() . ' | ' . self::date($periodend) . $split;
    }

    /**
     * Subject of a teacher digest: [Reportfeed] teacher_digest | <site> | <course shortname> | <YYYY-MM-DD>.
     *
     * Line breaks and bars in the course name are replaced, so a name can never break the subject's shape.
     *
     * @param string $courseshortname
     * @param int $periodend YYYYMMDD
     * @return string
     */
    public static function digest_subject(string $courseshortname, int $periodend): string {
        $course = trim(preg_replace('/[\r\n\t|]+/', ' ', $courseshortname));
        return '[Reportfeed] teacher_digest | ' . self::site_slug() . " | $course | " . self::date($periodend);
    }

    /**
     * Plain-text body: a sentence for people, then one key block for machines.
     *
     * @param array $file metadata: name, rows, bytes, sha256, attach
     * @param \stdClass $run the run row
     * @param int $now unix time of generation
     * @return string
     */
    public static function body(array $file, \stdClass $run, int $now): string {
        $text = get_string('mail_intro', 'local_reportfeed', get_site()->fullname) . "\n";
        if (!$file['attach'] && !empty($file['rowcap'])) {
            $text .= get_string('mail_rowcap', 'local_reportfeed', (int) $file['rowcap']) . "\n";
        } else if (!$file['attach'] && !empty($file['needed'])) {
            $text .= get_string('mail_toomany', 'local_reportfeed', (object) [
                'parts' => (int) $file['needed'], 'max' => (int) get_config('local_reportfeed', 'maxparts'),
            ]) . "\n";
        } else if (!$file['attach']) {
            $cap = (float) get_config('local_reportfeed', 'sizecapmb');
            $text .= get_string('mail_toolarge', 'local_reportfeed', $cap) . "\n";
        }
        if (!empty($file['roster'])) {
            $text .= get_string('mail_roster', 'local_reportfeed', (object) $file['roster']) . "\n";
        }
        $block = [
            'file' => $file['name'],
            'schema_version' => contract::SCHEMA,
            'period_end' => self::date((int) $run->periodend),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'rows' => $file['rows'],
            'bytes' => $file['bytes'],
            'sha256' => $file['sha256'],
            'run_id' => (int) $run->id,
            'attached' => $file['attach'] ? 'yes' : 'no',
            'part' => (int) ($file['part'] ?? 1),
            'parts' => (int) ($file['parts'] ?? 1),
        ];
        $text .= "\n";
        foreach ($block as $key => $value) {
            $text .= "$key: $value\n";
        }
        return $text;
    }

    /**
     * The administrator's own intro or footer for the teacher digest: plain text, trimmed, at most 1000 characters.
     *
     * @param string $name digestintro or digestfooter
     * @return string empty when not set
     */
    private static function digest_note(string $name): string {
        $text = trim((string) get_config('local_reportfeed', $name));
        return \core_text::substr(str_replace("\r", '', $text), 0, 1000);
    }

    /**
     * The lines of the teacher digest summary: counts only, never a learner's name.
     *
     * @param array $digest course (record) and stats: learners, completed, in_progress, not_started, inactive,
     *     sum and counted (for the average), tracking (whether the course tracks completion), days (inactivity threshold)
     * @return array{title:string,lines:string[],links:array<string,string>}
     */
    private static function digest_parts(array $digest): array {
        $course = $digest['course'];
        $s = $digest['stats'];
        $title = html_entity_decode(
            strip_tags(format_string($course->fullname, true, ['context' => \context_course::instance($course->id)])),
            ENT_QUOTES
        );
        $lines = [get_string('digest_mail_learners', 'local_reportfeed', (int) $s['learners'])];
        if ($s['tracking']) {
            $lines[] = get_string('digest_mail_status', 'local_reportfeed', (object) [
                'done' => (int) $s['completed'], 'progress' => (int) $s['in_progress'], 'notstarted' => (int) $s['not_started'],
            ]);
            if ($s['counted']) {
                $lines[] = get_string('digest_mail_average', 'local_reportfeed', format_float($s['sum'] / $s['counted'], 1));
            }
        } else {
            $lines[] = get_string('digest_mail_trackingoff', 'local_reportfeed');
        }
        $lines[] = get_string('digest_mail_inactive', 'local_reportfeed', (object) [
            'days' => (int) $s['days'], 'inactive' => (int) $s['inactive'],
        ]);
        return [
            'title' => get_string('digest_mail_title', 'local_reportfeed', $title),
            'lines' => $lines,
            'links' => [
                'digest_mail_course' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'digest_mail_change' => (new \moodle_url('/local/reportfeed/digest.php', ['courseid' => $course->id]))->out(false),
                'digest_mail_mute' => (new \moodle_url('/message/notificationpreferences.php'))->out(false),
            ],
        ];
    }

    /**
     * The plain-text summary that opens a teacher digest email.
     *
     * @param array $digest see {@see digest_parts()}
     * @return string
     */
    public static function digest_text(array $digest): string {
        $p = self::digest_parts($digest);
        $intro = self::digest_note('digestintro');
        $text = ($intro === '' ? '' : "$intro\n\n") . $p['title'] . "\n\n" . implode("\n", $p['lines']) . "\n\n"
            . get_string('digest_mail_csv', 'local_reportfeed') . "\n";
        foreach ($p['links'] as $key => $url) {
            $text .= "\n" . get_string($key, 'local_reportfeed') . "\n$url\n";
        }
        $footer = self::digest_note('digestfooter');
        return $footer === '' ? $text : "$text\n$footer\n";
    }

    /**
     * The HTML copy of a teacher digest email: the same summary, with links, then the machine block in small print.
     *
     * @param array $digest see {@see digest_parts()}
     * @param string $block the plain-text intro and key block
     * @return string
     */
    public static function digest_html(array $digest, string $block): string {
        $p = self::digest_parts($digest);
        $intro = self::digest_note('digestintro');
        $html = $intro === '' ? '' : \html_writer::tag('p', nl2br(s($intro), false));
        $html .= \html_writer::tag('h3', s($p['title']));
        $html .= \html_writer::alist(array_map('s', $p['lines']));
        $html .= \html_writer::tag('p', s(get_string('digest_mail_csv', 'local_reportfeed')));
        $links = [];
        foreach ($p['links'] as $key => $url) {
            $links[] = s(get_string($key, 'local_reportfeed')) . ' ' . \html_writer::link($url, s($url));
        }
        $html .= \html_writer::alist($links);
        $footer = self::digest_note('digestfooter');
        $html .= $footer === '' ? '' : \html_writer::tag('p', nl2br(s($footer), false));
        return $html . \html_writer::tag('pre', s($block), ['style' => 'font-size: 85%; color: #555;']);
    }

    /**
     * Hand one file to Moodle mail for one recipient.
     *
     * The attachment is a stored file that lives only for the duration of the send (change C1).
     *
     * @param \stdClass $to the recipient user
     * @param int $deliveryid the delivery row id (keeps the stored file unique per recipient)
     * @param string $file learner_course or learner_summary
     * @param array $meta metadata: name, path, rows, bytes, sha256, attach
     * @param \stdClass $run the run row
     * @param int $now unix time of generation
     * @param string|null $subject a ready subject (teacher digest); the HR subject when null
     * @param string $provider message provider: hrfeed (email forced) or teacherdigest (the teacher may mute it)
     * @param array|null $digest teacher digest only: course and stats for the friendly summary, see {@see digest_text()}
     * @return bool whether Moodle accepted the message
     */
    public static function send(
        \stdClass $to,
        int $deliveryid,
        string $file,
        array $meta,
        \stdClass $run,
        int $now,
        ?string $subject = null,
        string $provider = 'hrfeed',
        ?array $digest = null
    ): bool {
        $subject ??= self::subject(
            $file,
            (int) $run->periodend,
            (int) ($meta['part'] ?? 1),
            (int) ($meta['parts'] ?? 1)
        );
        if (!empty($to->reportfeedexternal)) {
            return self::send_external($to, $subject, $meta, $run, $now);
        }
        $message = new \core\message\message();
        $message->component = 'local_reportfeed';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $to;
        $message->subject = $subject;
        $message->fullmessage = self::body($meta, $run, $now);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        if ($digest) {
            $message->fullmessage = self::digest_text($digest) . "\n" . $message->fullmessage;
            $message->fullmessagehtml = self::digest_html($digest, self::body($meta, $run, $now));
        }
        $message->smallmessage = $message->subject;
        $message->notification = 1;

        $stored = null;
        if ($meta['attach']) {
            $stored = get_file_storage()->create_file_from_pathname([
                'contextid' => \context_system::instance()->id,
                'component' => 'local_reportfeed',
                'filearea' => 'attachment',
                'itemid' => $deliveryid,
                'filepath' => '/',
                'filename' => $meta['name'],
            ], $meta['path']);
            $message->attachment = $stored;
            $message->attachname = $meta['name'];
        }
        try {
            return (bool) message_send($message);
        } finally {
            if ($stored) {
                // Whole area, so the folder record goes too, not only the file.
                get_file_storage()->delete_area_files(
                    \context_system::instance()->id,
                    'local_reportfeed',
                    'attachment',
                    $deliveryid
                );
            }
        }
    }

    /**
     * Hand one file to Moodle's email function for an address that is not a Moodle user (D19).
     *
     * There is no account, so the Message API (which logs notifications for a user) is not used; the email function takes
     * a stand-in user with the address. The file is read from the request directory it was built in.
     *
     * @param \stdClass $to stand-in user from {@see external::user()}
     * @param string $subject
     * @param array $meta file metadata: name, path, rows, bytes, sha256, attach
     * @param \stdClass $run the run row
     * @param int $now unix time of generation
     * @return bool whether Moodle accepted the email
     */
    private static function send_external(\stdClass $to, string $subject, array $meta, \stdClass $run, int $now): bool {
        $path = $meta['attach'] ? $meta['path'] : '';
        return (bool) email_to_user(
            $to,
            \core_user::get_noreply_user(),
            $subject,
            self::body($meta, $run, $now),
            '',
            $path,
            $path === '' ? '' : $meta['name']
        );
    }
}
