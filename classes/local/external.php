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
 * Recipients who are not Moodle users: plain email addresses typed in by an admin (D19).
 *
 * Learner data goes to these addresses with no Moodle account behind them, so four safeguards apply: the whole feature is
 * off until a site setting is ticked, a separate capability is needed to edit the addresses, the schedule form warns, and
 * the run log lists every address a run was sent to. There is deliberately no domain restriction.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external {
    /** @var int Addresses allowed per schedule (a guard against pasting a mailing list by mistake). */
    public const MAX = 50;

    /** @var int User id given to the stand-in user objects (never a real account). */
    public const FAKE_ID = -30;

    /**
     * Whether the site setting allows external recipients at all.
     *
     * @return bool
     */
    public static function allowed(): bool {
        return (bool) get_config('local_reportfeed', 'allowexternal');
    }

    /**
     * Whether the current user may change external addresses: the setting is on and they hold the capability.
     *
     * @return bool
     */
    public static function can_edit(): bool {
        return self::allowed() && has_capability('local/reportfeed:editexternalrecipients', \context_system::instance());
    }

    /**
     * Split typed text into addresses: separated by new lines, commas, semicolons or spaces; lower case; no duplicates.
     *
     * @param string $text
     * @return string[]
     */
    public static function parse(string $text): array {
        $parts = preg_split('/[\s,;]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($parts));
    }

    /**
     * What is wrong with typed addresses, as one error text, or null when they are fine.
     *
     * @param string $text
     * @return string|null
     */
    public static function error(string $text): ?string {
        $emails = self::parse($text);
        if (count($emails) > self::MAX) {
            return get_string('external_toomany', 'local_reportfeed', self::MAX);
        }
        $bad = [];
        foreach ($emails as $email) {
            if (!validate_email($email) || strlen($email) > 255 || str_ends_with($email, '.invalid')) {
                $bad[] = s($email);
            }
        }
        return $bad ? get_string('external_invalid', 'local_reportfeed', implode(', ', $bad)) : null;
    }

    /**
     * The stored addresses of a schedule.
     *
     * @param int $scheduleid
     * @return string[] by row id
     */
    public static function addresses(int $scheduleid): array {
        global $DB;
        return $DB->get_records_menu('local_reportfeed_extrecipient', ['scheduleid' => $scheduleid], 'id', 'id, email');
    }

    /**
     * Replace a schedule's addresses with the typed ones. Rows of addresses that stay keep their id.
     *
     * @param int $scheduleid
     * @param string $text typed addresses
     */
    public static function store(int $scheduleid, string $text): void {
        global $DB;
        $wanted = self::parse($text);
        foreach (self::addresses($scheduleid) as $id => $email) {
            if (in_array($email, $wanted, true)) {
                $wanted = array_values(array_diff($wanted, [$email]));
            } else {
                $DB->delete_records('local_reportfeed_extrecipient', ['id' => $id]);
            }
        }
        foreach ($wanted as $email) {
            $DB->insert_record('local_reportfeed_extrecipient', (object) ['scheduleid' => $scheduleid, 'email' => $email]);
        }
    }

    /**
     * A stand-in user object for Moodle's mail function: a real address, no account.
     *
     * @param string $email
     * @return \stdClass
     */
    public static function user(string $email): \stdClass {
        $user = \core_user::get_noreply_user();
        $user->id = self::FAKE_ID;
        $user->email = $email;
        $user->firstname = $email;
        $user->lastname = '';
        $user->reportfeedexternal = true;
        return $user;
    }
}
