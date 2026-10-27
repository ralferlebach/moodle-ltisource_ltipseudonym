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
 * Explicit identity migration: preview, rotation, re-binding and purging.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Every operation that changes an identity already issued to a tool.
 *
 * Nothing in this class runs implicitly. Each operation is audited and has a preview so that the
 * administrator sees the extent before confirming (SP-P04, SP-P07).
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migration_service {
    /**
     * Counts that describe what a migration of a tool type would touch.
     *
     * @param int $typeid tool type (0 for the site scope)
     * @return array counts: active, retired, users, courses, cms
     */
    public static function preview(int $typeid): array {
        global $DB;
        $params = ['typeid' => $typeid];
        return [
            'active' => $DB->count_records('ltisource_ltipseudonym_map', $params + ['status' => 'active']),
            'retired' => $DB->count_records('ltisource_ltipseudonym_map', $params + ['status' => 'retired']),
            'users' => (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {ltisource_ltipseudonym_map} WHERE typeid = :typeid AND status = 'active'",
                $params
            ),
            'generation' => config::generation($typeid),
        ];
    }

    /**
     * Retire all active mappings of a tool type and start a new identity generation.
     *
     * Afterwards every user receives a new pseudonym on the next launch. The tool will treat them
     * as new accounts; this is the intended effect of a rotation and why it needs confirmation.
     *
     * @param int $typeid tool type (0 for the site scope)
     * @param int $actorid acting user
     * @return int number of retired mappings
     */
    public static function rotate(int $typeid, int $actorid): int {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $before = self::preview($typeid);
        $DB->set_field('ltisource_ltipseudonym_map', 'status', 'retired', ['typeid' => $typeid, 'status' => 'active']);
        $generation = config::bump_generation($typeid);
        audit::log(
            'identity_rotated',
            $typeid,
            0,
            0,
            $actorid,
            json_encode(['generation' => $before['generation'], 'mappings' => $before['active']]),
            json_encode(['generation' => $generation])
        );
        $transaction->allow_commit();
        return $before['active'];
    }

    /**
     * Describe moving the active mappings of a removed registration to a new one.
     *
     * @param int $fromtypeid old tool type id (usually no longer in lti_types)
     * @param int $totypeid new tool type id
     * @return array counts: move, conflicts
     */
    public static function rebind_preview(int $fromtypeid, int $totypeid): array {
        global $DB;
        $move = $DB->count_records('ltisource_ltipseudonym_map', ['typeid' => $fromtypeid, 'status' => 'active']);
        $generation = config::generation($totypeid);
        $conflicts = (int) $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {ltisource_ltipseudonym_map} a
               JOIN {ltisource_ltipseudonym_map} b ON b.userid = a.userid AND b.typeid = :to AND b.scopetype = a.scopetype
                                       AND b.scopeid = a.scopeid AND b.ptype = a.ptype AND b.version = :gen
              WHERE a.typeid = :from AND a.status = 'active'",
            ['from' => $fromtypeid, 'to' => $totypeid, 'gen' => $generation]
        );
        return ['move' => $move, 'conflicts' => $conflicts];
    }

    /**
     * Move the active mappings of an old registration to a new registration of the same tool.
     *
     * After re-registration of a tool Moodle creates a new lti_types record, so the tool would see
     * new pseudonyms for everybody. Re-binding keeps the pseudonyms already issued. It refuses to
     * run when it would collide with mappings that already exist for the new registration.
     *
     * @param int $fromtypeid
     * @param int $totypeid
     * @param int $actorid
     * @return int number of moved mappings
     * @throws \moodle_exception on conflicts or an unusable target
     */
    public static function rebind(int $fromtypeid, int $totypeid, int $actorid): int {
        global $DB;
        if ($fromtypeid <= 0 || $totypeid <= 0 || $fromtypeid === $totypeid) {
            throw new \moodle_exception('rebindinvalid', config::COMPONENT);
        }
        if (!$DB->record_exists('lti_types', ['id' => $totypeid, 'ltiversion' => '1.3.0'])) {
            throw new \moodle_exception('rebindinvalid', config::COMPONENT);
        }
        $preview = self::rebind_preview($fromtypeid, $totypeid);
        if ($preview['conflicts'] > 0) {
            throw new \moodle_exception('rebindconflicts', config::COMPONENT, '', $preview['conflicts']);
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->execute(
            "UPDATE {ltisource_ltipseudonym_map} SET typeid = :to, version = :gen WHERE typeid = :from AND status = 'active'",
            ['to' => $totypeid, 'gen' => config::generation($totypeid), 'from' => $fromtypeid]
        );
        audit::log(
            'mappings_rebound',
            $totypeid,
            0,
            0,
            $actorid,
            json_encode(['fromtype' => $fromtypeid]),
            json_encode(['totype' => $totypeid, 'mappings' => $preview['move']])
        );
        $transaction->allow_commit();
        return $preview['move'];
    }

    /**
     * Number of mappings that belong to tool registrations that no longer exist.
     *
     * @return int
     */
    public static function count_orphans(): int {
        global $DB;
        return (int) $DB->count_records_sql(
            "SELECT COUNT(1) FROM {ltisource_ltipseudonym_map} m
              WHERE m.typeid > 0 AND NOT EXISTS (SELECT 1 FROM {lti_types} t WHERE t.id = m.typeid)"
        );
    }

    /**
     * Delete mappings of removed tool registrations.
     *
     * @param int $actorid
     * @return int number deleted
     */
    public static function purge_orphans(int $actorid): int {
        global $DB;
        $count = self::count_orphans();
        if ($count > 0) {
            $DB->execute(
                "DELETE FROM {ltisource_ltipseudonym_map}
                  WHERE typeid > 0 AND NOT EXISTS (SELECT 1 FROM {lti_types} t WHERE t.id = {ltisource_ltipseudonym_map}.typeid)"
            );
            audit::log('orphans_purged', 0, 0, 0, $actorid, null, json_encode(['mappings' => $count]));
        }
        return $count;
    }

    /**
     * Delete retired mappings that were last used before a point in time.
     *
     * @param int $before unix time
     * @return int number deleted
     */
    public static function purge_retired(int $before): int {
        global $DB;
        $select = "status = 'retired' AND timelastused < :before";
        $count = $DB->count_records_select('ltisource_ltipseudonym_map', $select, ['before' => $before]);
        if ($count > 0) {
            $DB->delete_records_select('ltisource_ltipseudonym_map', $select, ['before' => $before]);
        }
        return $count;
    }
}
