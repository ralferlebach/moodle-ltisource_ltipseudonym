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
 * Safe exit path from the 0.1.0 alpha (component ltisource_scopedidentity, finding SP-04).
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Imports the pseudonym map (and optionally the policies) of the renamed 0.1.0 alpha.
 *
 * The rename to ltisource_ltipseudonym was a fresh install by owner decision: core offers no upgrade path between two
 * components. Without an import, a site that ran the alpha would issue new pseudonyms after uninstalling it, and every
 * tool would see its users as new people. The import copies the old rows, unchanged, while both plugins are installed
 * (the old tables are deleted by the uninstall of the old plugin). It never changes a row that exists here already:
 *
 * - same identity key (user, tool, scope, pseudonym type, generation) with the same pseudonym: already present;
 * - same identity key with another pseudonym, or the pseudonym used by another identity here: conflict, the row here
 *   is kept (the tool may already know it) and the conflict is reported;
 * - a deleted or missing user, a missing tool registration: skipped (nothing to keep a pseudonym for);
 * - the identity generation of a tool is raised to the old one where the old one is higher, so that the imported
 *   active rows are the ones get_or_create() finds.
 *
 * Dry run by default; the write runs in one transaction and writes one audit row with the counts (never a pseudonym).
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_import {
    /** @var string Component of the 0.1.0 alpha. */
    public const OLD_COMPONENT = 'ltisource_scopedidentity';
    /** @var string Mapping table of the 0.1.0 alpha. */
    public const OLD_MAP = 'ltisource_scopedidentity_map';
    /** @var string Policy table of the 0.1.0 alpha. */
    public const OLD_POL = 'ltisource_scopedidentity_pol';
    /** @var string Mapping table of this plugin. */
    private const MAP = 'ltisource_ltipseudonym_map';
    /** @var string Policy table of this plugin. */
    private const POL = 'ltisource_ltipseudonym_pol';

    /**
     * Whether the mapping table of the old plugin exists.
     *
     * @return bool
     */
    public static function old_tables_exist(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::OLD_MAP);
    }

    /**
     * Compute (dry run) or write the import.
     *
     * @param bool $execute false: nothing is written
     * @param bool $policies also import the policies
     * @param int $actorid user recorded in the audit
     * @return \stdClass report with the counts (found, imported, already, conflicts, skippeduser, skippedtype,
     *         generations, retired, policies, policyconflicts) and ->tablefound, ->executed
     */
    public static function run(bool $execute, bool $policies, int $actorid): \stdClass {
        global $DB;
        $report = (object) [
            'tablefound' => self::old_tables_exist(), 'executed' => false, 'found' => 0, 'imported' => 0, 'already' => 0,
            'conflicts' => 0, 'skippeduser' => 0, 'skippedtype' => 0, 'generations' => 0, 'retired' => 0, 'policies' => 0,
            'policyconflicts' => 0,
        ];
        if (!$report->tablefound) {
            return $report;
        }
        $transaction = $execute ? $DB->start_delegated_transaction() : null;
        self::import_generations($report, $execute);
        self::import_map($report, $execute);
        if ($policies && $DB->get_manager()->table_exists(self::OLD_POL)) {
            self::import_policies($report, $execute);
        }
        if ($execute) {
            audit::log('legacy_import', 0, 0, 0, $actorid, null, json_encode([
                'from' => self::OLD_COMPONENT, 'found' => $report->found, 'imported' => $report->imported,
                'already' => $report->already, 'conflicts' => $report->conflicts, 'skippeduser' => $report->skippeduser,
                'skippedtype' => $report->skippedtype, 'generations' => $report->generations, 'retired' => $report->retired,
                'policies' => $report->policies,
                'policyconflicts' => $report->policyconflicts,
            ]));
            $transaction->allow_commit();
            $report->executed = true;
        }
        return $report;
    }

    /**
     * Raise the identity generations to those of the old plugin.
     *
     * @param \stdClass $report
     * @param bool $execute
     */
    private static function import_generations(\stdClass $report, bool $execute): void {
        global $DB;
        $rows = $DB->get_records_select(
            'config_plugins',
            'plugin = :plugin AND ' . $DB->sql_like('name', ':name'),
            ['plugin' => self::OLD_COMPONENT, 'name' => 'generation\_%'],
            '',
            'id, name, value'
        );
        foreach ($rows as $row) {
            $typeid = (int) substr($row->name, strlen('generation_'));
            if ((int) $row->value > config::generation($typeid)) {
                $report->generations++;
                // Active rows here of an older generation would be issued no more; retire them as a rotation does.
                $select = 'typeid = :typeid AND version < :version AND status = :active';
                $params = ['typeid' => $typeid, 'version' => (int) $row->value, 'active' => 'active'];
                $report->retired += $DB->count_records_select(self::MAP, $select, $params);
                if ($execute) {
                    $DB->set_field_select(self::MAP, 'status', 'retired', $select, $params);
                    set_config('generation_' . $typeid, (int) $row->value, config::COMPONENT);
                }
            }
        }
    }

    /**
     * Copy the mapping rows.
     *
     * @param \stdClass $report
     * @param bool $execute
     */
    private static function import_map(\stdClass $report, bool $execute): void {
        global $DB;
        $rs = $DB->get_recordset(self::OLD_MAP, null, 'id ASC');
        foreach ($rs as $old) {
            $report->found++;
            $user = $DB->get_record('user', ['id' => $old->userid], 'id, deleted', IGNORE_MISSING);
            if (!$user || $user->deleted) {
                $report->skippeduser++;
                continue;
            }
            if ((int) $old->typeid > 0 && !$DB->record_exists('lti_types', ['id' => $old->typeid])) {
                $report->skippedtype++;
                continue;
            }
            $key = [
                'userid' => (int) $old->userid, 'typeid' => (int) $old->typeid, 'scopetype' => (string) $old->scopetype,
                'scopeid' => (int) $old->scopeid, 'ptype' => (string) $old->ptype, 'version' => (int) $old->version,
            ];
            $here = $DB->get_record(self::MAP, $key);
            if ($here) {
                if ($here->token === $old->token) {
                    $report->already++;
                } else {
                    $report->conflicts++;
                }
                continue;
            }
            if ($DB->record_exists(self::MAP, ['token' => $old->token])) {
                $report->conflicts++;
                continue;
            }
            $report->imported++;
            if ($execute) {
                $DB->insert_record(self::MAP, (object) ($key + [
                    'token' => (string) $old->token,
                    'status' => ((string) $old->status === 'active') ? 'active' : 'retired',
                    'timecreated' => (int) $old->timecreated,
                    'timelastused' => (int) $old->timelastused,
                ]));
            }
        }
        $rs->close();
    }

    /**
     * Copy the policy rows to levels that have no policy here (values that are not valid any more are left empty).
     *
     * @param \stdClass $report
     * @param bool $execute
     */
    private static function import_policies(\stdClass $report, bool $execute): void {
        global $DB;
        $fields = ['pseudotype', 'scopetype'];
        foreach (catalogue::DISCLOSURES as $category) {
            $fields[] = catalogue::disclosure_field($category);
        }
        foreach ($DB->get_records(self::OLD_POL, null, 'id ASC') as $old) {
            $level = ['typeid' => (int) $old->typeid, 'courseid' => (int) $old->courseid, 'cmid' => (int) $old->cmid];
            $exists = ($level['cmid'] > 0) ? $DB->record_exists(self::POL, ['cmid' => $level['cmid']])
                : $DB->record_exists(self::POL, $level);
            if ($exists) {
                $report->policyconflicts++;
                continue;
            }
            $row = (object) $level;
            foreach ($fields as $field) {
                $value = $old->$field ?? null;
                $row->$field = ($value !== null && $value !== '' && policy_manager::is_valid_value($field, (string) $value))
                    ? (string) $value : null;
            }
            $row->allowoverride = empty($old->allowoverride) ? 0 : 1;
            $row->usermodified = (int) ($old->usermodified ?? 0);
            $row->timecreated = (int) ($old->timecreated ?? time());
            $row->timemodified = time();
            $report->policies++;
            if ($execute) {
                $row->id = $DB->insert_record(self::POL, $row);
                audit::log(
                    'policy_created',
                    $level['typeid'],
                    $level['courseid'],
                    $level['cmid'],
                    $row->usermodified,
                    null,
                    policy_manager::snapshot($row)
                );
            }
        }
    }
}
