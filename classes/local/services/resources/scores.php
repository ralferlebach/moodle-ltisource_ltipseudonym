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
use ltisource_ltipseudonym\local\services\proxy_resource;

/**
 * Score submission (AGS) with the pseudonym as userId.
 *
 * The pseudonym of the request body is translated into the local user id before the core resource saves the
 * grade, and the local id is removed from error texts that quote the request.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scores extends \ltiservice_gradebookservices\local\resources\scores {
    use proxy_resource;

    /**
     * Execute the request for this resource.
     *
     * @param \mod_lti\local\ltiservice\response $response
     */
    public function execute($response) {
        $translation = null;
        if ($response->get_request_method() === self::HTTP_POST) {
            try {
                $translation = $this->translate_request($response);
            } catch (\Throwable $e) {
                $this->fail($response, $e);
                return;
            }
        }
        parent::execute($response);
        $this->normalise_code($response);
        if ($translation !== null) {
            $this->translator()->scrub_reason($response, $translation);
        }
    }

    /**
     * Translate the userId of the request body when the policy of the addressed activity pseudonymises.
     *
     * @param \mod_lti\local\ltiservice\response $response
     * @return array|null the translation (see translator::translate_score()), null when nothing was translated
     */
    private function translate_request($response): ?array {
        $params = $this->parse_template();
        $type = $this->authenticated_type(
            $response,
            gradebookservices::SCOPE_GRADEBOOKSERVICES_SCORE,
            optional_param('type_id', null, PARAM_ALPHANUM)
        );
        if ($type === null) {
            return null;
        }
        $courseid = (int) $params['context_id'];
        $translator = $this->translator();
        $ctx = $translator->context_for($type, $courseid, $translator->cmid_of_lineitem((int) $params['item_id'], $courseid));
        if ($ctx === null || !$ctx->pseudonymising()) {
            return null;
        }
        $translation = $translator->translate_score($ctx, (string) $response->get_request_data());
        if ($translation !== null) {
            $response->set_request_data($translation['translated']);
        }
        return $translation;
    }
}
