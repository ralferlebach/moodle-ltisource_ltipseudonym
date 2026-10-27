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

use ltiservice_gradebookservices\local\resources\lineitem;
use ltiservice_gradebookservices\local\resources\lineitems;

/**
 * The grade service (AGS) of mod_lti, answering at the URL of the identity-aware service proxy.
 *
 * Line items carry no user data and are served by the unchanged core resources, bound to this instance so that
 * their ids point to the proxy.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradebookservices extends \ltiservice_gradebookservices\local\service\gradebookservices {
    /**
     * Base URL of the services endpoint: the proxy instead of mod/lti/services.php.
     *
     * @return string
     */
    public static function get_service_path() {
        return proxy_path::url();
    }

    /**
     * The resources, bound to this service instance. The containers come after their elements, as in core.
     *
     * @return array
     */
    public function get_resources() {
        if (empty($this->resources)) {
            $this->resources = [
                new lineitem($this),
                new lineitems($this),
                new resources\results($this),
                new resources\scores($this),
            ];
        }
        return $this->resources;
    }
}
