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
 * Computes the launch parameter patch of a pseudonymised or redacted launch.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Core logic behind ltisource_ltipseudonym_before_launch().
 *
 * Fail closed: once a launch is identified as LTI 1.3, any error while evaluating or applying the
 * policy aborts the launch with an exception. The real user id is never delivered as a fallback.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class launch_processor {
    /** @var token_service Token source (replaceable in tests). */
    private $tokens;

    /** @var string Instructor custom parameters of the activity being launched. */
    private $instructorcustom = '';

    /**
     * @var bool True while a launch is only predicted (see dry_run()): every check runs, no pseudonym is created, and
     * the callback ends the core request builder with precheck_passed instead of returning a patch.
     */
    private static $dryrun = false;

    /**
     * Constructor.
     *
     * @param token_service|null $tokens
     */
    public function __construct(?token_service $tokens = null) {
        $this->tokens = $tokens ?? new token_service();
    }

    /**
     * Compute the parameter patch for a launch.
     *
     * @param object $instance LTI activity record, or a bare object with ->course for deep linking
     * @param string $endpoint tool endpoint
     * @param array $params launch parameters built by core so far
     * @return array parameters to merge over $params (empty when nothing has to change)
     * @throws \moodle_exception when the launch must not continue
     */
    public function process(object $instance, string $endpoint, array $params): array {
        $patch = $this->compute($instance, $endpoint, $params);
        if (self::$dryrun) {
            throw new precheck_passed();
        }
        return $patch;
    }

    /**
     * Predict a launch before Moodle core sends anything to the tool (fail closed before the OIDC login initiation).
     *
     * For LTI 1.3 core posts the login initiation, which carries the local user id as login_hint, BEFORE it builds the
     * launch (gate SP-G1). A launch that this plugin will refuse would therefore still disclose the local id. The request
     * guard calls this method on mod/lti/launch.php and mod/lti/contentitem.php before core initiates the login: $build
     * runs the real core request builder, which calls the callback of this plugin in dry-run mode. A refusal is thrown to
     * the caller; every other outcome (passed, or any error of the core builder in this early context) returns silently,
     * because the real launch is checked again, fail closed, when core builds it after the login.
     *
     * @param callable $build runs lti_get_launch_data() or lti_build_content_item_selection_request()
     * @throws \moodle_exception launchrefused
     */
    public static function dry_run(callable $build): void {
        self::$dryrun = true;
        try {
            $build();
        } catch (precheck_passed $e) {
            return;
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'launchrefused' && $e->module === config::COMPONENT) {
                throw $e;
            }
        } catch (\Throwable $e) {
            // Not ours to decide here: the real launch decides, fail closed.
            return;
        } finally {
            self::$dryrun = false;
        }
    }

    /**
     * Compute the parameter patch for a launch (see process()).
     *
     * @param object $instance
     * @param string $endpoint
     * @param array $params
     * @return array
     */
    private function compute(object $instance, string $endpoint, array $params): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        $deeplinking = ($params['lti_message_type'] ?? '') === 'ContentItemSelectionRequest';
        $all = $this->candidate_types($instance, $endpoint, $deeplinking);
        $candidates = array_filter($all, fn($type) => $type->ltiversion === LTI_VERSION_1P3);
        if (!$candidates) {
            if ($all || !$deeplinking) {
                // LTI 1.0/1.1/2.0, or an activity configured without a registration (core launches it as LTI 1.x).
                // Not covered by the policies (specification SP-L05), unless the site refuses such launches.
                $this->legacy_protocol_rule(
                    $all ? (int) reset($all)->id : 0,
                    (int) ($instance->course ?? 0),
                    $deeplinking ? 0 : $this->cmid_of($instance)
                );
            } else if ($this->site_policy_active()) {
                // An unidentifiable deep-linking request must not bypass a site wide policy.
                $this->refuse('deep linking request of an unidentifiable tool under an active site policy');
            }
            return [];
        }

        $courseid = (int) ($instance->course ?? 0);
        $cmid = $deeplinking ? 0 : $this->cmid_of($instance);

        $patch = [];
        $first = true;
        foreach ($candidates as $type) {
            $typepatch = $this->process_type($type, $courseid, $cmid, $params, $deeplinking, count($candidates) > 1);
            if ($first) {
                $patch = $typepatch;
                $first = false;
            } else if ($typepatch !== $patch) {
                $this->refuse('ambiguous tool registration with differing policies');
            }
        }
        return $patch;
    }

    /**
     * Apply the policy of one tool type.
     *
     * @param \stdClass $type lti_types record
     * @param int $courseid
     * @param int $cmid
     * @param array $params
     * @param bool $deeplinking
     * @param bool $ambiguous true when several registrations match the endpoint
     * @return array patch
     */
    private function process_type(
        \stdClass $type,
        int $courseid,
        int $cmid,
        array $params,
        bool $deeplinking,
        bool $ambiguous
    ): array {
        global $USER;
        try {
            $eff = policy_manager::resolve((int) $type->id, $courseid, $cmid);
        } catch (\Throwable $e) {
            $this->refuse('policy resolution failed');
        }
        if (!policy_manager::is_active($eff)) {
            return [];
        }
        if ($ambiguous && $eff->pseudonymising) {
            // Tokens depend on the deployment; with several candidates the tool is not known. A policy that only
            // restricts disclosure does not depend on the deployment and is evaluated for every candidate instead
            // (the patches must agree, see process()).
            $this->refuse('ambiguous tool registration');
        }
        if ($eff->pseudotype === catalogue::TYPE_LEGACY_USERID && !config::legacy_enabled()) {
            $this->refuse('legacy identifier mode is disabled');
        }
        if (empty($USER->id)) {
            $this->refuse('no authenticated user');
        }
        $typeconfig = lti_get_type_config((int) $type->id);

        // Claims that must be absent but that core still builds refuse the launch before any pseudonym is created.
        $this->assert_removable($eff, $typeconfig, $params);

        $patch = [];
        try {
            $tokenfor = function (int $userid) use ($eff, $type, $courseid, $cmid, $deeplinking): ?string {
                if (!$eff->pseudonymising) {
                    return null;
                }
                if (self::$dryrun) {
                    // A prediction creates no mapping.
                    return 'dry-run';
                }
                $scope = $eff->scopetype;
                if ($deeplinking && $scope === catalogue::SCOPE_CM) {
                    // No course module exists while an item is being selected: the course scope
                    // is the narrowest one available. Documented in docs/DOCUMENTATION.md.
                    $scope = catalogue::SCOPE_COURSE;
                }
                [$mtype, $mscope, $mscopeid] = token_service::scope_coordinates($scope, (int) $type->id, $courseid, $cmid);
                return $this->tokens->get_or_create($userid, $mtype, $mscope, $mscopeid, $eff->pseudotype);
            };

            $token = $tokenfor((int) $USER->id);
            if ($token !== null) {
                if (array_key_exists('user_id', $params)) {
                    $patch['user_id'] = $token;
                }
                if (!empty($params['for_user_id'])) {
                    $patch['for_user_id'] = $tokenfor((int) $params['for_user_id']);
                }
            }
            $patch = array_merge($patch, $this->disclosure_patch($eff, $typeconfig, $params, $token));
            // The services of core identify users by their local id and know no disclosure policy. Whenever the
            // effective policy is active in any way (pseudonymisation or a disclosure value other than pass), the
            // tool is pointed to the service proxy of this plugin, which translates pseudonyms and applies the
            // disclosure policy to the roster (finding SP-02). A policy that changes nothing leaves the URLs to core.
            $patch = array_merge($patch, $this->service_urls($params));
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'launchrefused') {
                throw $e;
            }
            $this->refuse('pseudonym generation failed');
        } catch (\Throwable $e) {
            $this->refuse('pseudonym generation failed');
        }
        return $patch;
    }

    /**
     * Launch parameters holding a service URL of core (memberships, line items, ...) moved to the service proxy.
     *
     * @param array $params core parameters
     * @return array parameter name => URL of the proxy
     */
    private function service_urls(array $params): array {
        $patch = [];
        foreach ($params as $name => $value) {
            if (strpos((string) $name, 'custom_') !== 0) {
                continue;
            }
            $moved = services\proxy_path::rewrite($value);
            if ($moved !== $value) {
                $patch[$name] = $moved;
            }
        }
        return $patch;
    }

    /**
     * Refuse the launch when a claim that the policy wants absent is still built by core.
     *
     * A callback cannot delete a parameter, so the launch is refused (fail closed) instead of delivering an empty claim
     * under the promise "removed".
     *
     * @param \stdClass $eff effective policy
     * @param array $typeconfig configuration of the tool registration
     * @param array $params core parameters
     * @throws \moodle_exception launchrefused
     */
    private function assert_removable(\stdClass $eff, array $typeconfig, array $params): void {
        $customvariables = $this->custom_parameter_variables($typeconfig);
        foreach (catalogue::DISCLOSURES as $category) {
            if ($eff->{catalogue::disclosure_field($category)} !== catalogue::DISC_REMOVE) {
                continue;
            }
            $present = claim_guard::present_params($category, $params, $customvariables);
            if ($present) {
                $this->refuse('claim cannot be removed, core still builds ' . implode(', ', $present) .
                    ' (change the tool registration or use the empty value)');
            }
        }
    }

    /**
     * Patch for the disclosure categories (and custom parameters resolving to user data).
     *
     * pass: nothing happens. blank: the value of a present, non-empty parameter is replaced by an empty
     * string, so the claim STAYS in the token with an empty value (blanking an absent parameter would add an
     * empty claim that core did not send, hence only present parameters are touched). remove: the claim must be
     * absent. A callback cannot delete a parameter, so if core still built one the launch is refused (fail
     * closed) instead of delivering an empty claim under the promise "removed".
     *
     * @param \stdClass $eff effective policy
     * @param array $typeconfig configuration of the tool registration
     * @param array $params core parameters
     * @param string|null $token the pseudonym when pseudonymising
     * @return array
     */
    private function disclosure_patch(\stdClass $eff, array $typeconfig, array $params, ?string $token): array {
        $patch = [];
        $customvariables = $this->custom_parameter_variables($typeconfig);
        foreach (catalogue::disclosure_params() as $category => $names) {
            $mode = $eff->{catalogue::disclosure_field($category)};
            if ($mode === catalogue::DISC_REMOVE) {
                continue;
            }
            if ($mode !== catalogue::DISC_BLANK) {
                continue;
            }
            foreach (claim_guard::present_params($category, $params, $customvariables) as $name) {
                if ($params[$name] !== '') {
                    $patch[$name] = '';
                }
            }
        }
        if ($token !== null) {
            foreach ($customvariables as $paramname => $variable) {
                if (catalogue::category_of_variable($variable) === 'sub' && !empty($params[$paramname])) {
                    $patch[$paramname] = $token;
                }
            }
        }
        return $patch;
    }

    /**
     * Custom launch parameters whose configured value is a user substitution variable.
     *
     * @param array $config configuration of the tool registration
     * @return string[] launch parameter name => variable name
     */
    private function custom_parameter_variables(array $config): array {
        $lines = ($config['customparameters'] ?? '') . "\n" . ($this->instructorcustom ?? '');
        $result = [];
        foreach (lti_split_parameters($lines) as $key => $value) {
            if (substr($value, 0, 1) !== '$') {
                continue;
            }
            $variable = substr($value, 1);
            if (catalogue::category_of_variable($variable) === null) {
                continue;
            }
            $result['custom_' . lti_map_keyname($key)] = $variable;
            $result['custom_' . $key] = $variable;
        }
        return $result;
    }

    /**
     * Tool types the launch may belong to.
     *
     * @param object $instance
     * @param string $endpoint
     * @param bool $deeplinking
     * @return \stdClass[] lti_types records
     */
    private function candidate_types(object $instance, string $endpoint, bool $deeplinking): array {
        global $DB;
        $this->instructorcustom = (string) ($instance->instructorcustomparameters ?? '');
        if (!$deeplinking) {
            $type = lti_get_instance_type($instance);
            return $type ? [$type->id => $type] : [];
        }
        // The deep-linking request builder passes no type id to the callback: identify the tool by
        // the endpoint of the request.
        $courseid = (int) ($instance->course ?? 0);
        // Registrations of every LTI version: an LTI 1.x match is handled by the LTI 1.x rule, not as unidentifiable.
        $types = $DB->get_records_select(
            'lti_types',
            'course = :site OR course = :course',
            ['site' => SITEID, 'course' => $courseid]
        );
        $matches = [];
        foreach ($types as $type) {
            $config = lti_get_type_config($type->id);
            $url = !empty($config['toolurl_ContentItemSelectionRequest']) ? $config['toolurl_ContentItemSelectionRequest']
                : ($config['toolurl'] ?? '');
            if ($url !== '' && $this->same_endpoint($url, $endpoint, !empty($config['forcessl']))) {
                $matches[$type->id] = $type;
            }
        }
        return $matches;
    }

    /**
     * Compare a configured URL with the endpoint the request is built for.
     *
     * @param string $configured
     * @param string $endpoint
     * @param bool $forcessl whether core upgrades the scheme to https
     * @return bool
     */
    private function same_endpoint(string $configured, string $endpoint, bool $forcessl): bool {
        $configured = trim($configured);
        if ($forcessl) {
            $configured = lti_ensure_url_is_https($configured);
        }
        return $configured === trim($endpoint);
    }

    /**
     * Course module id of the launched activity.
     *
     * @param object $instance
     * @return int
     */
    private function cmid_of(object $instance): int {
        if (!empty($instance->cmid)) {
            return (int) $instance->cmid;
        }
        if (!empty($instance->id)) {
            $cm = get_coursemodule_from_instance('lti', $instance->id, 0, false, IGNORE_MISSING);
            return $cm ? (int) $cm->id : 0;
        }
        return 0;
    }

    /**
     * The rule for launches of LTI 1.0/1.1/2.0 tools, which the policies do not cover (specification SP-L05).
     *
     * With the setting "legacyprotocol" = refuse, such a launch is refused while the effective policy of its position
     * (site default, or a policy stored for its registration) is active. Default "pass": the launch is delivered unchanged,
     * with the local user id, and the overview lists these registrations as not covered.
     *
     * @param int $typeid registration of the LTI 1.x tool, 0 when unknown
     * @param int $courseid
     * @param int $cmid
     */
    private function legacy_protocol_rule(int $typeid, int $courseid, int $cmid): void {
        if (config::legacy_protocol() !== config::LEGACY_PROTOCOL_REFUSE) {
            return;
        }
        try {
            $active = policy_manager::is_active(policy_manager::resolve($typeid, $courseid, $cmid));
        } catch (\Throwable $e) {
            $active = true;
        }
        if ($active) {
            $this->refuse('LTI 1.x launch under an active policy (setting: refuse LTI 1.x launches)');
        }
    }

    /**
     * Whether the site level policy changes launches (used for tools that cannot be identified).
     *
     * @return bool
     */
    private function site_policy_active(): bool {
        return policy_manager::is_active(policy_manager::resolve(0));
    }

    /**
     * Abort the launch. The reason is a technical hint without identifiers.
     *
     * @param string $reason
     * @throws \moodle_exception always
     */
    private function refuse(string $reason): void {
        throw new \moodle_exception('launchrefused', config::COMPONENT, '', $reason);
    }
}
