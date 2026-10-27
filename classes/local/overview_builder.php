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
 * Builds the data of the policy overview page.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Collects the effective tool type level policies for the administration overview.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overview_builder {
    /**
     * One row per LTI 1.3 tool registration, preceded by the site default.
     *
     * @return array[] rows with plain values (the renderer translates them)
     */
    public static function build(): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        $rows = [self::row(0, get_string('sitedefault', config::COMPONENT))];
        $types = $DB->get_records('lti_types', ['ltiversion' => LTI_VERSION_1P3], 'name ASC, id ASC', 'id, name');
        foreach ($types as $type) {
            $rows[] = self::row((int) $type->id, format_string($type->name));
        }
        return $rows;
    }

    /**
     * Summary of one level-1 policy.
     *
     * @param int $typeid 0 for the site default
     * @param string $name display name
     * @return array
     */
    public static function row(int $typeid, string $name): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $eff = policy_manager::resolve($typeid);
        $typeconfig = $typeid > 0 ? lti_get_type_config($typeid) : [];
        $services = $typeid > 0 ? service_info::services_of_type($typeid, $typeconfig) : [];
        // The LTI 1.1 result sourcedid carries the local user id: it leaks when the policy passes it and core builds it.
        $outcomeleak = $eff->pseudonymising && $typeid > 0 && $eff->discresult === catalogue::DISC_PASS &&
            claim_guard::core_sends($typeconfig, 'result') !== claim_guard::NOT_SENT;
        $blanked = [];
        $removed = [];
        foreach (catalogue::DISCLOSURES as $category) {
            $mode = $eff->{catalogue::disclosure_field($category)};
            if ($mode === catalogue::DISC_BLANK) {
                $blanked[] = $category;
            } else if ($mode === catalogue::DISC_REMOVE) {
                $removed[] = $category;
            }
        }
        $stored = policy_manager::get_row($typeid);
        return [
            'typeid' => $typeid,
            'name' => $name,
            'pseudotype' => $eff->pseudotype,
            'scopetype' => $eff->scopetype,
            'blanked' => $blanked,
            'removed' => $removed,
            'conflicts' => $typeid > 0 ? claim_guard::conflicts($eff, $typeconfig) : [],
            'services' => $services,
            'viaproxy' => policy_manager::is_active($eff) && $services,
            'outcomeleak' => $outcomeleak,
            'hasstored' => (bool) $stored,
            'overrides' => $typeid > 0 ? $DB->count_records_select(
                'ltisource_ltipseudonym_pol',
                'typeid = :t AND courseid > 0',
                ['t' => $typeid]
            ) : 0,
            'activemappings' => $DB->count_records('ltisource_ltipseudonym_map', ['typeid' => $typeid, 'status' => 'active']),
        ];
    }
}
