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
 * Decides whether a claim can be made absent, and reports the claims core still builds.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Claim removal support (finding SP-03).
 *
 * mod_lti merges the result of a ltisource callback over the launch parameters with array_merge() and
 * signs every key as a claim, so a callback can replace a value but never delete a parameter (evidence:
 * tests/claims_evidence_test.php). A claim is therefore absent from the token only when core never built
 * the parameter. The policy value "remove" is enforced in two ways: this class tells the editor and the
 * overview which claims core still builds for a tool registration (type configuration), and the launch
 * processor refuses a launch whose parameters still contain a claim that the policy says must be absent.
 * The policy value "blank" keeps the claim in the token with an empty value, and says so.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claim_guard {
    /** @var string Core builds the claim. */
    public const SENDS = 'yes';
    /** @var string Core builds the claim depending on the activity (instructor choice). */
    public const MAYBE = 'maybe';
    /** @var string Core does not build the claim with this configuration. */
    public const NOT_SENT = 'no';

    /**
     * Launch parameters of a category that are present in a parameter set (the key exists, whatever its value).
     *
     * @param string $category disclosure category
     * @param array $params launch parameters built by core
     * @param array $customvariables custom launch parameter name => user variable name (see launch_processor)
     * @return string[] parameter names
     */
    public static function present_params(string $category, array $params, array $customvariables = []): array {
        $present = [];
        foreach (catalogue::disclosure_params()[$category] ?? [] as $name) {
            if (array_key_exists($name, $params)) {
                $present[] = $name;
            }
        }
        foreach ($customvariables as $name => $variable) {
            if (catalogue::category_of_variable($variable) === $category && array_key_exists($name, $params)) {
                $present[] = $name;
            }
        }
        return $present;
    }

    /**
     * Whether core builds the claims of a category for a tool registration, judged from its configuration only.
     *
     * @param array $typeconfig lti_get_type_config() result
     * @param string $category disclosure category
     * @return string one of the class constants
     */
    public static function core_sends(array $typeconfig, string $category): string {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        switch ($category) {
            case 'name':
            case 'username':
                return self::setting($typeconfig['sendname'] ?? LTI_SETTING_NEVER);
            case 'email':
                return self::setting($typeconfig['sendemailaddr'] ?? LTI_SETTING_NEVER);
            case 'result':
                return self::setting($typeconfig['acceptgrades'] ?? LTI_SETTING_NEVER);
            case 'sourcedid':
                return self::SENDS;
            case 'picture':
                return self::NOT_SENT;
        }
        // Other personal data can only leave through custom parameters substituting user variables.
        foreach (self::custom_variables($typeconfig) as $variable) {
            if (catalogue::category_of_variable($variable) === $category) {
                return self::SENDS;
            }
        }
        return self::NOT_SENT;
    }

    /**
     * Map a LTI_SETTING_* value to a result.
     *
     * @param mixed $value
     * @return string
     */
    private static function setting($value): string {
        if ((int) $value === LTI_SETTING_ALWAYS) {
            return self::SENDS;
        }
        return ((int) $value === LTI_SETTING_DELEGATE) ? self::MAYBE : self::NOT_SENT;
    }

    /**
     * User variables referenced by the custom parameters of a tool registration.
     *
     * @param array $typeconfig lti_get_type_config() result
     * @return string[] variable names (without the dollar sign) that carry user data
     */
    private static function custom_variables(array $typeconfig): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $variables = [];
        foreach (lti_split_parameters((string) ($typeconfig['customparameters'] ?? '')) as $value) {
            if (substr($value, 0, 1) === '$' && catalogue::category_of_variable(substr($value, 1)) !== null) {
                $variables[] = substr($value, 1);
            }
        }
        return $variables;
    }

    /**
     * Categories a policy wants removed although core still builds them for the tool registration.
     *
     * @param \stdClass $effective effective policy
     * @param array $typeconfig lti_get_type_config() result
     * @return string[] category => verdict (SENDS or MAYBE)
     */
    public static function conflicts(\stdClass $effective, array $typeconfig): array {
        $conflicts = [];
        foreach (catalogue::DISCLOSURES as $category) {
            if ($effective->{catalogue::disclosure_field($category)} !== catalogue::DISC_REMOVE) {
                continue;
            }
            $verdict = self::core_sends($typeconfig, $category);
            if ($verdict !== self::NOT_SENT) {
                $conflicts[$category] = $verdict;
            }
        }
        return $conflicts;
    }
}
