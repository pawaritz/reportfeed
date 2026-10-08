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

/**
 * Reads the emails PHPUnit's mail sink captured.
 *
 * The sink keeps the raw MIME message (header, body, subject, from, to). Moodle nests a text/plain and a
 * text/html alternative inside multipart/mixed, so every boundary line is a split point.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mail_reader {
    /**
     * Every captured email as plain data.
     *
     * @param \core\test\phpunit\phpmailer_sink $sink
     * @return array[] each: to, subject, files (attachment names), body (plain text, LF), csv (name => content)
     */
    public static function read(\core\test\phpunit\phpmailer_sink $sink): array {
        $out = [];
        foreach ($sink->get_messages() as $m) {
            $files = [];
            $csv = [];
            $text = '';
            foreach (preg_split('/^--b\d+=_\S+\r?$/m', $m->body) as $part) {
                [$head, $content] = array_pad(preg_split('/\r?\n\r?\n/', $part, 2), 2, '');
                if (preg_match('/filename="?([^";\r\n]+)/i', $head, $f)) {
                    $files[] = $f[1];
                    $csv[$f[1]] = self::decode($head, $content);
                } else if (stripos($head, 'text/plain') !== false) {
                    $text = str_replace("\r\n", "\n", self::decode($head, $content));
                }
            }
            if ($text === '' && !preg_match('/multipart/i', $m->header)) {
                $text = str_replace("\r\n", "\n", self::decode($m->header, $m->body));
            }
            $out[] = ['to' => $m->to, 'subject' => $m->subject, 'files' => $files, 'body' => $text, 'csv' => $csv];
        }
        return $out;
    }

    /**
     * Decode one MIME part's content.
     *
     * @param string $head part headers
     * @param string $content part content
     * @return string
     */
    private static function decode(string $head, string $content): string {
        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $head)) {
            return quoted_printable_decode($content);
        }
        if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $head)) {
            return base64_decode($content);
        }
        return $content;
    }
}
