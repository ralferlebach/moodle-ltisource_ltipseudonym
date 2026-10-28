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

use ltisource_ltipseudonym\local\proxy\dispatcher;
use ltisource_ltipseudonym\local\proxy\translator;
use mod_lti\local\ltiservice\response;

/**
 * Guards three core entry points of mod_lti before they run (core hook after_config).
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Request guard (findings SP-02 and SP-03), fork-free through the core hook \core\hook\after_config.
 *
 * The hook is dispatched at the end of lib/setup.php for every request, after the session and the user are known and
 * before the script itself runs. The guard does nothing unless the script is one of these three:
 *
 * - mod/lti/services.php: the core endpoint of the memberships (NRPS) and grade (AGS) services answers with LOCAL user
 *   ids and the full roster. A tool that was given pseudonyms could call it directly (its URLs are predictable) and
 *   bypass the service proxy: read the roster with names against the disclosure policy, or post a score for a guessed
 *   local id. When the effective policy of the addressed tool, course and activity is active, the guard answers the
 *   request with the service proxy instead (same authentication, same resources, translated identities) and ends the
 *   request. Line items and every other resource stay with core.
 * - mod/lti/launch.php and mod/lti/contentitem.php: for LTI 1.3 core posts the OIDC login initiation, with the local
 *   user id as login_hint, before it builds the launch (gate SP-G1). A launch the policy will refuse is predicted here
 *   (launch_processor::dry_run()) and refused before anything is sent to the tool.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_guard {
    /** @var string Core services endpoint (relative to the site root). */
    public const SCRIPT_SERVICES = '/mod/lti/services.php';
    /** @var string Core launch page of an activity. */
    public const SCRIPT_LAUNCH = '/mod/lti/launch.php';
    /** @var string Core deep-linking (content item) request page. */
    public const SCRIPT_CONTENTITEM = '/mod/lti/contentitem.php';

    /**
     * Callback of \core\hook\after_config.
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $SCRIPT, $CFG;
        if (CLI_SCRIPT || during_initial_install() || !empty($CFG->upgraderunning)) {
            return;
        }
        switch ((string) $SCRIPT) {
            case self::SCRIPT_SERVICES:
                $response = self::core_service_response();
                if ($response !== null) {
                    $response->send();
                    die();
                }
                return;
            case self::SCRIPT_LAUNCH:
                self::precheck_launch(
                    optional_param('id', 0, PARAM_INT),
                    optional_param('action', '', PARAM_TEXT),
                    optional_param('user', 0, PARAM_INT)
                );
                return;
            case self::SCRIPT_CONTENTITEM:
                self::precheck_deep_linking(
                    optional_param('id', 0, PARAM_INT),
                    optional_param('course', 0, PARAM_INT),
                    optional_param('title', '', PARAM_TEXT),
                    optional_param('text', '', PARAM_RAW)
                );
                return;
        }
    }

    /**
     * Answer a request to the core services endpoint with the service proxy when the policy requires it.
     *
     * @param string|null $path path info, null for $_SERVER['PATH_INFO']
     * @param string|null $body request body, null for the input stream
     * @return response|null the answer of the proxy, null when core shall answer
     */
    public static function core_service_response(?string $path = null, ?string $body = null): ?response {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        if ($path === null) {
            $path = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
        } else {
            // The resources of core read the path info of the request itself.
            $_SERVER['PATH_INFO'] = $path;
        }
        if ($path === '') {
            return null;
        }
        $resource = dispatcher::match(dispatcher::new_response(), $path);
        if (!self::carries_user_data($resource) || !self::policy_applies($resource)) {
            return null;
        }
        return dispatcher::handle($path, $body);
    }

    /**
     * Whether a resource of the proxy carries user identifiers (roster, scores, results).
     *
     * @param \mod_lti\local\ltiservice\resource_base|null $resource
     * @return bool
     */
    private static function carries_user_data($resource): bool {
        return $resource instanceof \ltisource_ltipseudonym\local\services\resources\contextmemberships
            || $resource instanceof \ltisource_ltipseudonym\local\services\resources\linkmemberships
            || $resource instanceof \ltisource_ltipseudonym\local\services\resources\scores
            || $resource instanceof \ltisource_ltipseudonym\local\services\resources\results;
    }

    /**
     * Whether an active policy (or coordinates that do not belong together) concerns the request.
     *
     * The tool is taken from every place a request can name it: the path (tool code, link), the type_id parameter and
     * the bearer token. Any doubt routes the request to the proxy, which authenticates exactly like core and fails closed.
     *
     * @param \mod_lti\local\ltiservice\resource_base $resource
     * @return bool
     */
    private static function policy_applies($resource): bool {
        global $DB;
        $translator = new translator();
        try {
            $params = $resource->request_params();
            $typeids = [];
            $courseid = 0;
            $cmid = 0;
            if (isset($params['link_id'])) {
                $lti = $DB->get_record('lti', ['id' => (int) $params['link_id']], '*', IGNORE_MISSING);
                if (!$lti) {
                    return false;
                }
                $courseid = (int) $lti->course;
                $linktype = lti_get_instance_type($lti);
                $typeids[] = $linktype ? (int) $linktype->id : 0;
                $cm = get_coursemodule_from_instance('lti', $lti->id, 0, false, IGNORE_MISSING);
                $cmid = $cm ? (int) $cm->id : 0;
            } else {
                $courseid = (int) ($params['context_id'] ?? 0);
                if (isset($params['tool_code'])) {
                    $typeids[] = (int) $params['tool_code'];
                    $rlid = optional_param('rlid', 0, PARAM_INT);
                    if ($rlid > 0) {
                        $cm = get_coursemodule_from_instance('lti', $rlid, 0, false, IGNORE_MISSING);
                        $cmid = $cm ? (int) $cm->id : 0;
                    }
                }
                if (isset($params['item_id'])) {
                    $cmid = $translator->cmid_of_lineitem((int) $params['item_id'], $courseid);
                }
                $typeids[] = optional_param('type_id', 0, PARAM_INT);
            }
            $typeids[] = self::bearer_type();
            foreach (array_unique(array_filter($typeids)) as $typeid) {
                $type = $DB->get_record('lti_types', ['id' => $typeid], '*', IGNORE_MISSING);
                if ($type && $translator->context_for($type, $courseid, $cmid) !== null) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            // Coordinates that do not belong together, or anything unexpected: the proxy answers (and fails closed).
            return true;
        }
    }

    /**
     * The tool registration a bearer token of the request was issued to (read only, no access is logged).
     *
     * @return int type id, 0 when there is no bearer token or it is unknown
     */
    private static function bearer_type(): int {
        global $DB;
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (strpos((string) $header, 'Bearer ') !== 0) {
            return 0;
        }
        $token = trim(substr($header, 7));
        if ($token === '') {
            return 0;
        }
        return (int) $DB->get_field('lti_access_tokens', 'typeid', ['token' => $token], IGNORE_MISSING);
    }

    /**
     * Predict the launch of an LTI 1.3 activity and refuse it before core posts the login initiation.
     *
     * Only for a logged-in user who may launch the activity, and only on the first pass of launch.php (the second pass,
     * after the login, is the real launch, checked by the callback). Anything else is left to core.
     *
     * @param int $cmid course module of the activity
     * @param string $action launch action (gradeReport for a submission review)
     * @param int $foruserid user of a submission review
     * @throws \moodle_exception launchrefused
     */
    public static function precheck_launch(int $cmid, string $action = '', int $foruserid = 0): void {
        global $DB, $SESSION, $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        if ($cmid <= 0 || !isloggedin() || isguestuser() || isset($SESSION->lti_initiatelogin_status)) {
            return;
        }
        $cm = get_coursemodule_from_id('lti', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || !has_capability('mod/lti:view', \context_module::instance($cm->id))) {
            return;
        }
        $lti = $DB->get_record('lti', ['id' => $cm->instance], '*', IGNORE_MISSING);
        $type = $lti ? lti_get_instance_type($lti) : null;
        if (!$type || $type->ltiversion !== LTI_VERSION_1P3) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $cm->course], '*', IGNORE_MISSING);
        if (!$course) {
            return;
        }
        $lti->cmid = $cm->id;
        $messagetype = ($action === 'gradeReport') ? 'LtiSubmissionReviewRequest' : 'basic-lti-launch-request';
        self::with_course_page($course, function () use ($lti, $messagetype, $foruserid) {
            launch_processor::dry_run(function () use ($lti, $messagetype, $foruserid) {
                lti_get_launch_data($lti, '', $messagetype, $foruserid);
            });
        });
    }

    /**
     * Predict a deep-linking request of an LTI 1.3 tool and refuse it before core posts the login initiation.
     *
     * Only for a logged-in user who may add tools to the course.
     *
     * @param int $typeid tool registration
     * @param int $courseid
     * @param string $title
     * @param string $text
     * @throws \moodle_exception launchrefused
     */
    public static function precheck_deep_linking(int $typeid, int $courseid, string $title = '', string $text = ''): void {
        global $DB, $SESSION, $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        if ($typeid <= 0 || $courseid <= 0 || !isloggedin() || isguestuser() || isset($SESSION->lti_initiatelogin_status)) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        $type = $DB->get_record('lti_types', ['id' => $typeid], '*', IGNORE_MISSING);
        if (!$course || !$type || $type->ltiversion !== LTI_VERSION_1P3) {
            return;
        }
        if (!has_capability('mod/lti:addcoursetool', \context_course::instance($course->id))) {
            return;
        }
        $returnurl = new \moodle_url('/mod/lti/contentitem_return.php', ['course' => $course->id, 'id' => $typeid]);
        self::with_course_page($course, function () use ($typeid, $course, $returnurl, $title, $text) {
            launch_processor::dry_run(function () use ($typeid, $course, $returnurl, $title, $text) {
                lti_build_content_item_selection_request(
                    $typeid,
                    $course,
                    $returnurl,
                    $title,
                    $text,
                    [],
                    [],
                    false,
                    true,
                    false,
                    false,
                    false,
                    ''
                );
            });
        });
    }

    /**
     * Run a function with a page of the course as $PAGE (core reads the course of the launch from it), then restore.
     *
     * @param \stdClass $course
     * @param callable $run
     */
    private static function with_course_page(\stdClass $course, callable $run): void {
        global $PAGE, $COURSE;
        $savedpage = $PAGE;
        $savedcourse = $COURSE;
        try {
            $page = new \moodle_page();
            $page->set_course($course);
            $PAGE = $page;
            $run();
        } finally {
            $PAGE = $savedpage;
            $COURSE = $savedcourse;
        }
    }
}
