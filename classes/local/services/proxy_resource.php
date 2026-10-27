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

use ltisource_ltipseudonym\local\proxy\refusal;
use ltisource_ltipseudonym\local\proxy\translator;

/**
 * Helpers shared by the resources of the identity-aware service proxy.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait proxy_resource {
    /**
     * The translator of the proxy.
     *
     * @return translator
     */
    protected function translator(): translator {
        return new translator();
    }

    /**
     * Authenticate the bearer token before the core resource does, to learn which tool the request belongs to.
     *
     * The core resource authenticates again; a failure here is not an answer, core answers it (401).
     *
     * @param \mod_lti\local\ltiservice\response $response
     * @param string $scope scope the request needs
     * @param mixed $typeparam value of the type_id parameter, or the tool code of the path
     * @return \stdClass|null lti_types record of the tool, null when the request is not authenticated
     */
    protected function authenticated_type($response, string $scope, $typeparam = null): ?\stdClass {
        if (!$this->check_tool($typeparam, $response->get_request_data(), [$scope])) {
            return null;
        }
        $type = $this->get_service()->get_type();
        return ($type && !empty($type->id)) ? $type : null;
    }

    /**
     * Answer a request with the error of a refusal, or with a 500 for any other failure (fail closed: the
     * unfiltered answer of core is never delivered).
     *
     * @param \mod_lti\local\ltiservice\response $response
     * @param \Throwable $e
     */
    protected function fail($response, \Throwable $e): void {
        $response->set_body('');
        if ($e instanceof refusal) {
            $response->set_code($e->getCode());
            $response->set_reason($e->getMessage());
            return;
        }
        $response->set_code(500);
        $response->set_reason('Pseudonym translation failed');
    }
}
