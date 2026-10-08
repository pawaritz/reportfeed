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
 * The activity-level choices of a schedule: which optional files, which fields in each, filters and the
 * engagement time window. Kept as one JSON string in the schedule row, and always read through here, so a
 * damaged or hand-edited value can never add a file or a column that the contract does not define.
 *
 * @package    local_reportfeed
 * @copyright  2026 Pawarit Pingmuang, Edvanced.me
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_options {
    /** @var string[] Engagement windows: since the previous send, the month just ended, this month so far. */
    public const WINDOWS = ['sinceprev', 'lastmonth', 'monthtodate'];

    /** @var array<string,string[]> Row filters each group offers ("all" is always first). */
    public const FILTERS = [
        'activity_completion' => ['all', 'open'],
        'activity_assignments' => ['all', 'open', 'overdue'],
        'activity_quizzes' => ['all', 'open'],
        'activity_engagement' => ['all'],
    ];

    /**
     * Short form name of a file: activity_quizzes becomes quizzes.
     *
     * @param string $file
     * @return string
     */
    public static function short(string $file): string {
        return substr($file, strlen('activity_'));
    }

    /**
     * Read the stored JSON into a clean structure.
     *
     * @param string|null $json
     * @return array{groups:array<string,array{fields:string[],filter:string,modtypes:string[]}>,window:string}
     */
    public static function parse(?string $json): array {
        $raw = $json ? json_decode($json, true) : null;
        $raw = is_array($raw) ? $raw : [];
        $groups = [];
        foreach (contract::ACTIVITY_FILES as $file) {
            $g = $raw['groups'][$file] ?? null;
            if (!is_array($g)) {
                continue;
            }
            $filter = (string) ($g['filter'] ?? 'all');
            $groups[$file] = [
                'fields' => array_values(array_intersect(contract::activity_fields($file), (array) ($g['fields'] ?? []))),
                'filter' => in_array($filter, self::FILTERS[$file], true) ? $filter : 'all',
                'modtypes' => $file === 'activity_completion' ? self::modtypes((array) ($g['modtypes'] ?? [])) : [],
            ];
        }
        $window = (string) ($raw['window'] ?? '');
        return ['groups' => $groups, 'window' => in_array($window, self::WINDOWS, true) ? $window : ''];
    }

    /**
     * Keep only names of installed activity modules.
     *
     * @param string[] $names
     * @return string[]
     */
    private static function modtypes(array $names): array {
        $known = array_keys(\core_component::get_plugin_list('mod'));
        return array_values(array_intersect($known, array_map('strval', $names)));
    }

    /**
     * The optional files a schedule sends, in contract order.
     *
     * @param \stdClass $schedule
     * @return string[]
     */
    public static function files(\stdClass $schedule): array {
        return array_keys(self::parse($schedule->activityopts ?? null)['groups']);
    }

    /**
     * The engagement window in force: the one chosen, else the month just ended for a monthly schedule and
     * the time since the previous send for any other.
     *
     * @param \stdClass $schedule
     * @return string one of {@see WINDOWS}
     */
    public static function window(\stdClass $schedule): string {
        $window = self::parse($schedule->activityopts ?? null)['window'];
        return $window ?: (($schedule->frequency ?? '') === 'monthly' ? 'lastmonth' : 'sinceprev');
    }

    /**
     * Build the JSON to store from submitted form data; null when no activity file is switched on.
     *
     * @param \stdClass $data
     * @return string|null
     */
    public static function from_form(\stdClass $data): ?string {
        $groups = [];
        foreach (contract::ACTIVITY_FILES as $file) {
            $short = self::short($file);
            if (empty($data->{"act_$short"})) {
                continue;
            }
            $groups[$file] = [
                'fields' => (array) ($data->{"actfields_$short"} ?? []),
                'filter' => (string) ($data->{"actfilter_$short"} ?? 'all'),
                'modtypes' => (array) ($data->actmodtypes ?? []),
            ];
        }
        if (!$groups) {
            return null;
        }
        $clean = self::parse(json_encode(['groups' => $groups, 'window' => (string) ($data->actwindow ?? '')]));
        return json_encode($clean);
    }

    /**
     * The form values for a stored schedule.
     *
     * @param \stdClass $schedule
     * @return array<string,mixed>
     */
    public static function to_form(\stdClass $schedule): array {
        $opts = self::parse($schedule->activityopts ?? null);
        $values = ['actwindow' => $opts['window'], 'actmodtypes' => $opts['groups']['activity_completion']['modtypes'] ?? []];
        foreach ($opts['groups'] as $file => $g) {
            $short = self::short($file);
            $values["act_$short"] = 1;
            $values["actfields_$short"] = $g['fields'];
            $values["actfilter_$short"] = $g['filter'];
        }
        return $values;
    }
}
