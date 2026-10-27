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
 * Services of a tool registration that identify users.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Which LTI Advantage services that identify users are enabled for a tool registration.
 *
 * Core identifies users by their local id in these services. With a pseudonymising policy the tool is pointed to the
 * service proxy of this plugin (classes/local/proxy), which translates the pseudonym (finding SP-02), so the
 * information is shown to administrators and does not block anything.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_info {
    /**
     * Services enabled for a tool type that identify users by their local id.
     *
     * @param int $typeid
     * @param array|null $config type configuration when the caller already loaded it
     * @return string[] subset of ['ags', 'nrps']
     */
    public static function services_of_type(int $typeid, ?array $config = null): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $config = $config ?? lti_get_type_config($typeid);
        $services = [];
        if (!empty($config['ltiservice_gradesynchronization'])) {
            $services[] = 'ags';
        }
        if (!empty($config['ltiservice_memberships'])) {
            $services[] = 'nrps';
        }
        return $services;
    }
}
