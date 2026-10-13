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
 * What the Reportfeed home page shows: setup progress, the newest runs, and the row of links shared by every screen.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard {
    /** @var int A mail problem older than this many seconds is no longer shown. */
    public const MAILERROR_AGE = 7 * DAYSECS;

    /**
     * How far the setup is. Optional steps are not counted.
     *
     * @return array done (int), total (int), next (the first step still open, or null)
     */
    public static function progress(): array {
        $steps = array_values(array_filter(checklist::steps(), fn($step) => $step['state'] !== checklist::INFO));
        $open = array_values(array_filter($steps, fn($step) => $step['state'] !== checklist::DONE));
        return ['done' => count($steps) - count($open), 'total' => count($steps), 'next' => $open[0] ?? null];
    }

    /**
     * The newest runs, newest first.
     *
     * @param int $limit
     * @return \stdClass[] id, type, status, periodend, manual, error, timefinished, schedulename
     */
    public static function recent_runs(int $limit = 5): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT r.id, r.type, r.status, r.periodend, r.manual, r.error, r.timefinished, r.timecreated,
                    s.name AS schedulename
               FROM {local_reportfeed_run} r
          LEFT JOIN {local_reportfeed_schedule} s ON s.id = r.scheduleid
           ORDER BY r.id DESC",
            [],
            0,
            $limit
        ));
    }

    /**
     * How many people a schedule sends to: Moodle users plus outside addresses.
     *
     * @param int $scheduleid
     * @return int
     */
    public static function recipient_count(int $scheduleid): int {
        global $DB;
        return $DB->count_records('local_reportfeed_recipient', ['scheduleid' => $scheduleid])
            + $DB->count_records('local_reportfeed_extrecipient', ['scheduleid' => $scheduleid]);
    }

    /**
     * The last time Moodle reported that it could not send a Reportfeed email, if that was recent.
     *
     * @return array|null time (int), error (string)
     */
    public static function last_mail_error(): ?array {
        $saved = json_decode((string) get_config('local_reportfeed', 'lastmailerror'), true);
        if (!is_array($saved) || empty($saved['time']) || (int) $saved['time'] < time() - self::MAILERROR_AGE) {
            return null;
        }
        return ['time' => (int) $saved['time'], 'error' => (string) ($saved['error'] ?? '')];
    }

    /**
     * The row of links at the top of every Reportfeed screen.
     *
     * @param string $active home, schedules, bundles, log or start
     * @return string html
     */
    public static function nav(string $active): string {
        $pages = [
            'home' => ['index.php', 'home_title'],
            'schedules' => ['schedules.php', 'schedules'],
            'bundles' => ['bundles.php', 'bundles'],
            'log' => ['log.php', 'runlog'],
            'start' => ['start.php', 'start_title'],
        ];
        $links = [];
        foreach ($pages as $key => [$file, $string]) {
            $links[] = \html_writer::link(
                new \moodle_url('/local/reportfeed/' . $file),
                get_string($string, 'local_reportfeed'),
                ['class' => 'nav-link' . ($key === $active ? ' active' : '')]
            );
        }
        if (has_capability('moodle/site:config', \context_system::instance())) {
            $links[] = \html_writer::link(
                new \moodle_url('/admin/settings.php', ['section' => 'local_reportfeed']),
                get_string('home_settings', 'local_reportfeed'),
                ['class' => 'nav-link']
            );
        }
        return \html_writer::div(implode('', $links), 'nav nav-pills mb-3');
    }
}
