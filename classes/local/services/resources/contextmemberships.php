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

namespace ltisource_ltipseudonym\local\services\resources;

use ltisource_ltipseudonym\local\services\proxy_resource;

/**
 * Context memberships (NRPS) with pseudonymised members.
 *
 * Runs the core resource unchanged and translates its answer.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contextmemberships extends \ltiservice_memberships\local\resources\contextmemberships {
    use proxy_resource;

    /**
     * Execute the request for this resource.
     *
     * @param \mod_lti\local\ltiservice\response $response
     */
    public function execute($response) {
        // Bind the activity (rlid) to the course and the tool before core runs (finding SP-03). Core does not check that
        // the link belongs to the course or the tool, and answers a foreign link with an invalid status (0). Unauthenticated
        // requests are left to core (401), so that nothing about courses or links is revealed to them.
        try {
            $params = $this->parse_template();
            $rlid = (int) optional_param('rlid', 0, PARAM_INT);
            if ($rlid > 0) {
                $type = $this->authenticated_type(
                    $response,
                    \ltiservice_memberships\local\service\memberships::SCOPE_MEMBERSHIPS_READ,
                    $params['tool_code']
                );
                if ($type !== null) {
                    $translator = $this->translator();
                    $translator->assert_binding(
                        $type,
                        (int) $params['context_id'],
                        $translator->cmid_of_link($rlid, (int) $params['context_id'])
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->fail($response, $e);
            return;
        }
        parent::execute($response);
        $this->normalise_code($response);
        if ($response->get_code() !== 200) {
            return;
        }
        try {
            $params = $this->parse_template();
            $courseid = (int) $params['context_id'];
            $translator = $this->translator();
            $type = $this->get_service()->get_type();
            $cmid = $translator->cmid_of_link((int) optional_param('rlid', 0, PARAM_INT), $courseid);
            $ctx = $translator->context_for($type ?: null, $courseid, $cmid);
            if ($ctx !== null) {
                $response->set_body($translator->filter_members($ctx, $response->get_body()));
            }
        } catch (\Throwable $e) {
            $this->fail($response, $e);
        }
    }
}
