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

namespace ltisource_ltipseudonym\local;

/**
 * Integration with the account merge tool tool_mergeusers (gate finding F2).
 *
 * The callbacks are registered in db/hooks.php for the hooks of tool_mergeusers. They are typed with `object`, so this class
 * does not depend on tool_mergeusers being installed, and they only use the public API of the hook objects.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class merge_integration {
    /** @var string Table of the mappings user -> pseudonym. */
    public const MAP_TABLE = 'ltisource_ltipseudonym_map';
    /** @var string[] Columns of the unique index "identity" besides userid. */
    public const IDENTITY_FIELDS = ['typeid', 'scopetype', 'scopeid', 'ptype', 'version'];
    /** @var string Audit action of a merge. */
    public const AUDIT_ACTION = 'user_merged';

    /**
     * Database settings for tool_mergeusers.
     *
     * The mapping table is taken out of the generic processing (`exceptions`): the generic table merger resolves a conflict on
     * a unique index by deleting a row (which side depends on the site setting `uniquekeynewidtomaintain`), and without a
     * declared index its fallback deletes every row of the merged-away user. The plugin merges the table itself in
     * {@see self::after_merged_all_tables()}, deterministically and without deleting. The compound index is declared as well,
     * so that a site which re-enables the table (setting `excluded_exceptions` or custom settings) at least gets the
     * row-by-row resolution of tool_mergeusers instead of the fallback.
     *
     * String keys are used throughout: tool_mergeusers merges the layers with array_replace_recursive(), and a numeric key
     * would replace an entry of its default list of excluded tables.
     *
     * @return array
     */
    public static function db_settings(): array {
        return [
            'exceptions' => [self::MAP_TABLE => self::MAP_TABLE],
            'compoundindexes' => [
                self::MAP_TABLE => [
                    'userfield' => ['userid'],
                    'otherfields' => self::IDENTITY_FIELDS,
                ],
            ],
        ];
    }

    /**
     * Callback for \tool_mergeusers\hook\add_settings_before_merging.
     *
     * @param object $hook the hook (offers add_raw_settings(array))
     */
    public static function add_settings_before_merging(object $hook): void {
        $hook->add_raw_settings(self::db_settings());
    }

    /**
     * Callback for \tool_mergeusers\hook\after_merged_all_tables. Runs inside the transaction of the merge.
     *
     * Any failure is reported as an error message of the merge, which makes tool_mergeusers roll the whole merge back
     * (fail closed: no half merged identities). Messages and logs contain counts only, never a pseudonym.
     *
     * @param object $hook the hook (offers toid, fromid, add_log(string), add_error_message(string))
     */
    public static function after_merged_all_tables(object $hook): void {
        try {
            $counts = self::merge_mappings((int) $hook->toid, (int) $hook->fromid);
            $hook->add_log('ltisource_ltipseudonym: pseudonyms moved ' . $counts['moved'] . ', conflicts ' .
                $counts['conflicts'] . ' (retired on the merged-away account ' . $counts['retired'] . ')');
        } catch (\Throwable $e) {
            $hook->add_error_message('ltisource_ltipseudonym: merging the pseudonym mappings failed (' .
                get_class($e) . ': ' . $e->getMessage() . ')');
        }
    }

    /**
     * Move the pseudonyms of a merged-away account to the kept account.
     *
     * Per row of the merged-away account (A, $fromid), keyed by the unique index (tool, scope, pseudonym type, generation):
     * - the kept account (B, $toid) has no row with that key: the row moves to B with its pseudonym unchanged, so the tool
     *   keeps seeing the same person in that scope;
     * - B has a row with that key (conflict): B's row wins and stays unchanged; A's row stays on A and is retired if it was
     *   active (never deleted). The service proxy then refuses A's pseudonym (404, audited as "retired").
     * One audit row per affected tool type holds the counts (never a pseudonym).
     *
     * @param int $toid user that is kept
     * @param int $fromid user that is merged away
     * @return array counts: moved, conflicts, retired, pertype
     */
    public static function merge_mappings(int $toid, int $fromid): array {
        global $DB, $USER;
        if ($toid <= 0 || $fromid <= 0 || $toid === $fromid) {
            throw new \coding_exception('Invalid pair of users for a merge');
        }
        $counts = ['moved' => 0, 'conflicts' => 0, 'retired' => 0, 'pertype' => []];
        $rows = $DB->get_records(self::MAP_TABLE, ['userid' => $fromid], 'id');
        if (!$rows) {
            return $counts;
        }
        $kept = [];
        foreach ($DB->get_records(self::MAP_TABLE, ['userid' => $toid], 'id') as $row) {
            $kept[self::key($row)] = $row;
        }
        foreach ($rows as $row) {
            $typeid = (int) $row->typeid;
            $counts['pertype'][$typeid] = $counts['pertype'][$typeid] ?? ['moved' => 0, 'conflicts' => 0, 'retired' => 0];
            if (!isset($kept[self::key($row)])) {
                $DB->set_field(self::MAP_TABLE, 'userid', $toid, ['id' => $row->id]);
                $kept[self::key($row)] = $row;
                $counts['moved']++;
                $counts['pertype'][$typeid]['moved']++;
                continue;
            }
            $counts['conflicts']++;
            $counts['pertype'][$typeid]['conflicts']++;
            if ($row->status === 'active') {
                $DB->set_field(self::MAP_TABLE, 'status', 'retired', ['id' => $row->id]);
                $counts['retired']++;
                $counts['pertype'][$typeid]['retired']++;
            }
        }
        foreach ($counts['pertype'] as $typeid => $typecounts) {
            if ($typecounts['moved'] + $typecounts['retired'] === 0) {
                // Nothing changed for this tool (a repeated run): no new audit entry.
                continue;
            }
            audit::log(self::AUDIT_ACTION, (int) $typeid, 0, 0, (int) ($USER->id ?? 0), null, json_encode($typecounts));
        }
        return $counts;
    }

    /**
     * Key of a mapping row in the unique index, without the user.
     *
     * @param \stdClass $row
     * @return string
     */
    private static function key(\stdClass $row): string {
        $parts = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $parts[] = (string) $row->$field;
        }
        return implode('|', $parts);
    }
}
