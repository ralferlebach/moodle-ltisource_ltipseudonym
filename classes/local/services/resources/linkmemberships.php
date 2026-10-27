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
 * Link memberships (NRPS) with pseudonymised members.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linkmemberships extends \ltiservice_memberships\local\resources\linkmemberships {
    use proxy_resource;

    /**
     * Execute the request for this resource.
     *
     * @param \mod_lti\local\ltiservice\response $response
     */
    public function execute($response) {
        parent::execute($response);
        if ($response->get_code() !== 200) {
            return;
        }
        try {
            global $DB;
            $params = $this->parse_template();
            $ltiid = (int) $params['link_id'];
            $lti = $DB->get_record('lti', ['id' => $ltiid], 'id,course');
            $translator = $this->translator();
            $type = $this->get_service()->get_type();
            $cmid = $translator->cmid_of_link($ltiid, (int) $lti->course);
            $ctx = $translator->context_for($type ?: null, (int) $lti->course, $cmid);
            if ($ctx !== null) {
                $response->set_body($translator->filter_members($ctx, $response->get_body()));
            }
        } catch (\Throwable $e) {
            $this->fail($response, $e);
        }
    }
}
