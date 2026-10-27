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
 * Restore of the course module level policy.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the policy override of an LTI activity. The restored row is only a proposal: at launch time
 * the resolver ignores or clamps it exactly as for a policy created in the user interface.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_ltisource_ltipseudonym_subplugin extends restore_subplugin {
    /**
     * Paths handled at activity level.
     *
     * @return restore_path_element[]
     */
    protected function define_lti_subplugin_structure() {
        return [new restore_path_element($this->get_namefor('policy'), $this->get_pathfor('/policy'))];
    }

    /**
     * Process one policy element.
     *
     * @param array $data
     */
    public function process_ltisource_ltipseudonym_policy($data) {
        global $DB, $USER;
        $data = (object) $data;
        $cmid = (int) $this->task->get_moduleid();
        if ($cmid <= 0 || $DB->record_exists('ltisource_ltipseudonym_pol', ['cmid' => $cmid])) {
            return;
        }
        $row = new \stdClass();
        foreach (
            ['pseudotype', 'scopetype', 'discname', 'discemail', 'discpicture', 'discsourcedid', 'discusername',
                'discresult', 'discextra'] as $field
        ) {
            $value = (isset($data->$field) && $data->$field !== '') ? (string) $data->$field : null;
            // A value this version does not know (or that is not valid for the field) is dropped, never stored.
            $row->$field = ($value !== null && \ltisource_ltipseudonym\local\policy_manager::is_valid_value($field, $value))
                ? $value : null;
        }
        $row->allowoverride = !empty($data->allowoverride) ? 1 : 0;
        $row->typeid = 0;
        $row->courseid = (int) $this->task->get_courseid();
        $row->cmid = $cmid;
        $row->usermodified = (int) $USER->id;
        $row->timecreated = $row->timemodified = time();
        $DB->insert_record('ltisource_ltipseudonym_pol', $row);
        \ltisource_ltipseudonym\local\audit::log(
            'policy_restored',
            0,
            $row->courseid,
            $cmid,
            (int) $USER->id,
            null,
            \ltisource_ltipseudonym\local\policy_manager::snapshot($row)
        );
    }
}
