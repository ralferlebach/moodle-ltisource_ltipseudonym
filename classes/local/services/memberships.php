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

namespace ltisource_ltipseudonym\local\services;

/**
 * The memberships service (NRPS) of mod_lti, answering at the URL of the identity-aware service proxy.
 *
 * The inherited resource URLs, ids and pagination links are built from get_service_path() through the service
 * instance, so overriding it moves every link the tool follows to the proxy.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class memberships extends \ltiservice_memberships\local\service\memberships {
    /**
     * Base URL of the services endpoint: the proxy instead of mod/lti/services.php.
     *
     * @return string
     */
    public static function get_service_path() {
        return proxy_path::url();
    }

    /**
     * The resources, bound to this service instance.
     *
     * @return array
     */
    public function get_resources() {
        if (empty($this->resources)) {
            $this->resources = [
                new resources\contextmemberships($this),
                new resources\linkmemberships($this),
            ];
        }
        return $this->resources;
    }
}
