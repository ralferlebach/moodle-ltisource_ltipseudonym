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

use ltiservice_gradebookservices\local\service\gradebookservices;
use ltisource_ltipseudonym\local\proxy\translator;
use ltisource_ltipseudonym\local\proxy\refusal;
use ltisource_ltipseudonym\local\services\proxy_resource;

/**
 * Result query (AGS) with pseudonyms.
 *
 * The user_id filter of the query is translated into the local id before the core resource reads the grades,
 * and the userId and the result ids of the answer are translated back. An unknown pseudonym yields an empty set.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class results extends \ltiservice_gradebookservices\local\resources\results {
    use proxy_resource;

    /**
     * Execute the request for this resource.
     *
     * @param \mod_lti\local\ltiservice\response $response
     */
    public function execute($response) {
        if ($response->get_request_method() !== self::HTTP_GET) {
            parent::execute($response);
            return;
        }
        $original = $_GET['user_id'] ?? null;
        $ctx = null;
        $empty = false;
        try {
            $params = $this->parse_template();
            $type = $this->authenticated_type(
                $response,
                gradebookservices::SCOPE_GRADEBOOKSERVICES_RESULT_READ,
                optional_param('type_id', null, PARAM_INT)
            );
            $translator = $this->translator();
            if ($type !== null) {
                $courseid = (int) $params['context_id'];
                $cmid = $translator->cmid_of_lineitem((int) $params['item_id'], $courseid);
                $ctx = $translator->context_for($type, $courseid, $cmid);
                if ($ctx !== null && !$ctx->pseudonymising()) {
                    $ctx = null;
                }
            }
            if ($ctx !== null && $original !== null && $original !== '') {
                try {
                    $_GET['user_id'] = (string) $translator->user_of($ctx, (string) $original, 'ags');
                } catch (refusal $e) {
                    // Not a user of this tool and scope: the query has no result, and the answer says so.
                    $_GET['user_id'] = (string) translator::NO_USER;
                    $empty = true;
                }
            }
            parent::execute($response);
            if ($ctx !== null && $response->get_code() === 200) {
                $response->set_body($empty ? '[]' : $translator->filter_results($ctx, $response->get_body()));
            }
        } catch (\Throwable $e) {
            $this->fail($response, $e);
        } finally {
            if ($original === null) {
                unset($_GET['user_id']);
            } else {
                $_GET['user_id'] = $original;
            }
        }
    }
}
