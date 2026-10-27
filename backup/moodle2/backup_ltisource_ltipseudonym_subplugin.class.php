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

/**
 * Backup of the course module level policy.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backs up the policy override of an LTI activity. Mappings (users and pseudonyms) are never part of a backup:
 * a restored activity is a new scope and its users receive new pseudonyms.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_ltisource_ltipseudonym_subplugin extends backup_subplugin {
    /**
     * Subplugin structure attached to the lti element.
     *
     * @return backup_subplugin_element
     */
    protected function define_lti_subplugin_structure() {
        $subplugin = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $policy = new backup_nested_element('policy', null, [
            'pseudotype', 'scopetype', 'discname', 'discemail', 'discpicture', 'discsourcedid', 'discusername',
            'discresult', 'discextra', 'allowoverride',
        ]);
        $subplugin->add_child($wrapper);
        $wrapper->add_child($policy);
        $policy->set_source_table('ltisource_ltipseudonym_pol', ['cmid' => backup::VAR_MODID]);
        return $subplugin;
    }
}
