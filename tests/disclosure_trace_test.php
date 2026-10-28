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

namespace ltisource_ltipseudonym;

use ltisource_ltipseudonym\local\request_guard;
use ltisource_ltipseudonym\testing\fixture;
use ltisource_ltipseudonym\testing\service_client as client;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');
require_once(__DIR__ . '/fixtures/service_client.php');

/**
 * Finding SP-02: protocol capture of what a tool receives, per LTI version, scope and policy mode, on real core flows.
 *
 * Every message is produced by Moodle core itself: the OIDC login initiation (lti_build_login_request()), the signed id token
 * of a launch (lti_get_launch_data()) and of a deep-linking request (lti_build_content_item_selection_request()), the LTI 1.1
 * launch parameters, the NRPS roster and the AGS score/result exchange through the URL the id token carries (the proxy, or core
 * behind the request guard), and a direct call of the core endpoint (bypass attempt). The request guard of the plugin runs
 * before the login initiation as in a real request. Every value is REDACTED into a class (local_user_id, pseudonym, name,
 * email, username, idnumber, contains_local_user_id, contains_session_key, empty, ...); no real value is recorded.
 *
 * The capture is compared with tests/fixtures/disclosure_trace.json, so the test goes red when core or the plugin changes what
 * leaves the platform. With the environment variable LTIPSEUDONYM_TRACE_OUT=<dir> the test writes the capture
 * (disclosure_trace.json) and the report (DISCLOSURE_TRACE.md, including the two-site capture of
 * tests/fixtures/disclosure_trace_e2e.json when present) into that directory instead of comparing.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\launch_processor
 * @covers     \ltisource_ltipseudonym\local\request_guard
 * @covers     \ltisource_ltipseudonym\local\proxy\translator
 */
final class disclosure_trace_test extends \advanced_testcase {
    /** @var string[] Scopes in the order of the report. */
    private const SCOPES = ['site', 'deployment', 'course', 'cm'];

    /** @var array Saved superglobals. */
    private $saved = [];

    /** @var \stdClass The world of the capture. */
    private $w;

    /**
     * Remember the request state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->saved = ['server' => $_SERVER, 'get' => $_GET];
    }

    /**
     * Restore the request state.
     */
    protected function tearDown(): void {
        $_SERVER = $this->saved['server'];
        $_GET = $this->saved['get'];
        parent::tearDown();
    }

    /**
     * The policy modes of the capture.
     *
     * tool: full = shares names, e-mail, grades and custom user variables; quiet = shares nothing (remove can be honoured).
     *
     * @return array[] mode => [pseudotype, disclosure value for all categories or null, scoped, tool, extra columns]
     */
    public static function modes(): array {
        return [
            'none' => [null, null, false, 'full', []],
            'legacy_userid' => ['legacy_userid', null, false, 'full', []],
            'disclosure_blank' => ['none', 'blank', false, 'full', []],
            'random_pass' => ['random_persisted', null, true, 'full', []],
            'hmac_pass' => ['hmac_secret_v1', null, true, 'full', []],
            'random_blank' => ['random_persisted', 'blank', true, 'full', []],
            'random_result_pass' => ['random_persisted', null, true, 'full', ['discresult' => 'pass']],
            'random_remove_shared' => ['random_persisted', 'remove', true, 'full', []],
            'random_remove_quiet' => ['random_persisted', 'remove', true, 'quiet', []],
        ];
    }

    /**
     * Capture everything, then compare with the committed capture (or write it).
     */
    public function test_disclosure_trace(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->redirectEvents();
        set_config('legacyenabled', 1, 'ltisource_ltipseudonym');
        $this->w = $this->world();

        $cells = [];
        foreach (self::modes() as $mode => $def) {
            $scopes = $def[2] ? self::SCOPES : [null];
            foreach ($scopes as $scope) {
                $cells[] = $this->lti13_cell($mode, $def, $scope);
            }
        }
        foreach (['none' => 'pass', 'site_policy' => 'pass', 'site_policy_refuse' => 'refuse'] as $mode => $setting) {
            $cells[] = $this->lti11_cell($mode, $setting);
        }
        $capture = ['format' => 1, 'cells' => $cells];

        $out = getenv('LTIPSEUDONYM_TRACE_OUT');
        if ($out) {
            $json = json_encode($capture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($out . '/disclosure_trace.json', $json);
            $e2e = $CFG->dirroot . '/mod/lti/source/ltipseudonym/tests/fixtures/disclosure_trace_e2e.json';
            file_put_contents($out . '/DISCLOSURE_TRACE.md', $this->report($capture, is_readable($e2e) ?
                json_decode(file_get_contents($e2e), true) : null, $CFG->release));
        }
        $expected = json_decode(file_get_contents(__DIR__ . '/fixtures/disclosure_trace.json'), true);
        $this->assertNotEmpty($expected, 'committed capture missing: run with LTIPSEUDONYM_TRACE_OUT');
        foreach ($capture['cells'] as $i => $cell) {
            $this->assertEquals($expected['cells'][$i] ?? null, $cell, 'capture cell ' . $i . ' (' . $cell['lti'] . ' ' .
                $cell['mode'] . ' ' . ($cell['scope'] ?? '-') . ') differs from tests/fixtures/disclosure_trace.json: what ' .
                'leaves the platform changed; re-assess and regenerate with LTIPSEUDONYM_TRACE_OUT');
        }
        $this->assertCount(count($expected['cells']), $capture['cells']);
    }

    /**
     * Courses, users, tools and activities of the capture.
     *
     * @return \stdClass
     */
    private function world(): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $gen = $this->getDataGenerator();
        $w = new \stdClass();
        // Push the user ids far above the ids of courses, activities, tools and items, so that a number in a message can
        // only be classified as the local user id when it really is one.
        for ($i = 0; $i < 60; $i++) {
            $gen->create_user();
        }
        $w->c1 = $gen->create_course(['idnumber' => 'C1-IDNUMBER']);
        $w->c2 = $gen->create_course();
        $w->learner = $gen->create_user(['firstname' => 'Lara', 'lastname' => 'Learner', 'email' => 'lara@example.com',
            'idnumber' => 'ID-LARA', 'username' => 'lara.learner']);
        $w->mate = $gen->create_user(['firstname' => 'Max', 'lastname' => 'Mate', 'email' => 'max@example.com',
            'idnumber' => 'ID-MAX', 'username' => 'max.mate']);
        $w->teacher = $gen->create_user(['firstname' => 'Tom', 'lastname' => 'Teacher', 'email' => 'tom@example.com',
            'idnumber' => 'ID-TOM', 'username' => 'tom.teacher']);
        $gen->enrol_user($w->learner->id, $w->c1->id, 'student');
        $gen->enrol_user($w->learner->id, $w->c2->id, 'student');
        $gen->enrol_user($w->mate->id, $w->c1->id, 'student');
        $gen->enrol_user($w->teacher->id, $w->c1->id, 'editingteacher');

        $custom = "userid=\$User.id\nusername=\$User.username\nemail=\$Person.email.primary\nname=\$Person.name.full\n" .
            "idnumber=\$Person.sourcedId\ncourse=\$Context.id";
        $services = ['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1];
        $full = $services + ['lti_acceptgrades' => LTI_SETTING_ALWAYS, 'lti_customparameters' => $custom,
            'lti_contentitem' => 1];
        $w->full = fixture::create_type($full + ['lti_toolurl_ContentItemSelectionRequest' => 'https://full.example.com/dl']);
        $w->full2 = fixture::create_type($full + ['lti_toolurl_ContentItemSelectionRequest' => 'https://full2.example.com/dl']);
        $w->quiet = fixture::create_type($services + ['lti_sendname' => LTI_SETTING_NEVER,
            'lti_sendemailaddr' => LTI_SETTING_NEVER, 'lti_contentitem' => 1,
            'lti_toolurl_ContentItemSelectionRequest' => 'https://quiet.example.com/dl']);
        $w->legacy = fixture::create_type([
            'lti_ltiversion' => LTI_VERSION_1, 'lti_resourcekey' => 'key11', 'lti_password' => 'secret11',
            'lti_acceptgrades' => LTI_SETTING_ALWAYS, 'lti_customparameters' => $custom,
        ] + $services);
        // The variable $Result.sourcedId only in activities that accept grades: core reads the parameter without checking
        // that it exists
        // (lti_parse_custom_parameter()), which warns in deep linking and in launches without a result id.
        $grades = ['instructorchoiceacceptgrades' => 1, 'grade' => 100,
            'instructorcustomparameters' => 'resultid=$Result.sourcedId'];
        $w->a1 = fixture::create_activity($w->c1, $w->full, $grades);
        $w->a2 = fixture::create_activity($w->c1, $w->full, $grades);
        $w->b1 = fixture::create_activity($w->c2, $w->full, $grades);
        $w->x1 = fixture::create_activity($w->c1, $w->full2, $grades);
        $w->q1 = fixture::create_activity($w->c1, $w->quiet, ['instructorchoicesendname' => 0,
            'instructorchoicesendemailaddr' => 0]);
        $w->g1 = fixture::create_activity($w->c1, $w->legacy, $grades);
        $w->tokens = [];
        foreach (['full', 'full2', 'quiet'] as $tool) {
            $w->tokens[$tool] = client::access_token($w->$tool);
        }
        return $w;
    }

    /**
     * Store the policy of a mode for every LTI 1.3 tool of the world (tool level).
     *
     * @param array $def mode definition
     * @param string|null $scope
     */
    private function apply_policy(array $def, ?string $scope): void {
        global $DB;
        $DB->delete_records('ltisource_ltipseudonym_pol');
        [$pseudotype, $disc, , , $extra] = $def;
        if ($pseudotype === null) {
            return;
        }
        $row = ['pseudotype' => $pseudotype, 'scopetype' => $scope ?? 'deployment'] + $extra;
        if ($disc !== null) {
            foreach (local\catalogue::DISCLOSURES as $category) {
                $value = ($disc === 'remove' && !local\catalogue::is_removable($category)) ? 'blank' : $disc;
                $row += [local\catalogue::disclosure_field($category) => $value];
            }
        }
        if ($scope === 'site') {
            // The site scope is a decision of the site level: a tool level row cannot choose a scope weaker than the default
            // deployment scope (it would be clamped), so the policy is stored as the site default.
            fixture::set_policy(['typeid' => 0] + $row);
            return;
        }
        foreach ([$this->w->full, $this->w->full2, $this->w->quiet] as $typeid) {
            fixture::set_policy(['typeid' => $typeid] + $row);
        }
    }

    /**
     * Known values of a user, to classify what a tool receives.
     *
     * @param \stdClass $user
     * @return array
     */
    private function knowns(\stdClass $user): array {
        global $DB;
        return [
            'localid' => (string) $user->id,
            'pseudonyms' => $DB->get_fieldset_select('ltisource_ltipseudonym_map', 'token', 'userid = ?', [$user->id]),
            'username' => $user->username, 'email' => $user->email, 'idnumber' => $user->idnumber,
            'names' => [$user->firstname, $user->lastname, fullname($user)],
        ];
    }

    /**
     * The redacted class of one value.
     *
     * @param mixed $value
     * @param array $k knowns of the user the message is about
     * @return string
     */
    private function classify($value, array $k): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'no_user_identifier';
        }
        $s = (string) $value;
        if ($s === '') {
            return 'empty';
        }
        if ($s === $k['localid']) {
            return 'local_user_id';
        }
        if (in_array($s, $k['pseudonyms'], true)) {
            return 'pseudonym';
        }
        if ($s === $k['email']) {
            return 'email';
        }
        if ($s === $k['username']) {
            return 'username';
        }
        if ($s === $k['idnumber']) {
            return 'idnumber';
        }
        if (in_array($s, $k['names'], true)) {
            return 'name';
        }
        $json = json_decode($s);
        if (is_object($json) && isset($json->data->userid)) {
            return ((string) $json->data->userid === $k['localid']) ? 'contains_local_user_id' : 'contains_other_user_id';
        }
        if (strpos($s, 'sesskey=') !== false) {
            return 'contains_session_key';
        }
        if (strpos($s, client::PROXY_SCRIPT) !== false) {
            return 'service_url_proxy';
        }
        if (strpos($s, client::CORE_SCRIPT) !== false) {
            return 'service_url_core';
        }
        return 'no_user_identifier';
    }

    /**
     * Flatten a structure into redacted leaves (claim URIs shortened).
     *
     * @param mixed $data
     * @param array $k knowns
     * @param string $prefix
     * @return string[] path => class
     */
    private function flatten($data, array $k, string $prefix = ''): array {
        $short = [
            'https://purl.imsglobal.org/spec/lti/claim/' => 'lti:',
            'https://purl.imsglobal.org/spec/lti-ags/claim/' => 'ags:',
            'https://purl.imsglobal.org/spec/lti-nrps/claim/' => 'nrps:',
            'https://purl.imsglobal.org/spec/lti-bo/claim/' => 'bo:',
            'https://purl.imsglobal.org/spec/lti-dl/claim/' => 'dl:',
            'http://www.brightspace.com' => 'brightspace',
        ];
        $result = [];
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (
            is_array($data) && $data !== [] && array_keys($data) === range(0, count($data) - 1) && !is_array(reset($data))
                && !is_object(reset($data))
        ) {
            // A list of scalars (roles, scopes): one leaf with the classes of its elements.
            $classes = array_unique(array_map(fn($v) => $this->classify($v, $k), $data));
            sort($classes);
            $result[$prefix] = implode('+', $classes);
            return $result;
        }
        if (is_array($data)) {
            if ($data === []) {
                $result[$prefix] = 'empty';
                return $result;
            }
            foreach ($data as $key => $value) {
                $key = str_replace(array_keys($short), array_values($short), (string) $key);
                $result += $this->flatten($value, $k, $prefix === '' ? $key : $prefix . '.' . $key);
            }
            return $result;
        }
        $result[$prefix] = $this->classify($data, $k);
        return $result;
    }

    /**
     * Replace session-specific parts of the OIDC login request (lti_message_hint is JSON).
     *
     * @param array $params login request parameters
     * @param array $k knowns
     * @return array path => class
     */
    private function login_classes(array $params, array $k): array {
        $result = [];
        foreach ($params as $name => $value) {
            if ($name === 'lti_message_hint') {
                $hint = json_decode($value, true);
                foreach ($hint as $key => $part) {
                    // The cmid is the activity; launchid is "ltilaunch<instance id>_<random>" (a key of the session store).
                    $result['lti_message_hint.' . $key] = $this->classify($part, $k) . ($key === 'cmid' ? ' (activity id)' :
                        ' (session store key: lti instance id and a random number)');
                }
                continue;
            }
            $result[$name] = $this->classify($value, $k);
        }
        ksort($result);
        return $result;
    }

    /**
     * One LTI 1.3 cell: precheck, OIDC login, launch, deep linking, NRPS, AGS, bypass attempt, linkability.
     *
     * @param string $mode
     * @param array $def
     * @param string|null $scope
     * @return array
     */
    private function lti13_cell(string $mode, array $def, ?string $scope): array {
        global $DB;
        $w = $this->w;
        $this->apply_policy($def, $scope);
        $tool = $def[3];
        $activity = ($tool === 'quiet') ? $w->q1 : $w->a1;
        $typeid = $w->$tool;
        $cell = ['lti' => '1.3', 'mode' => $mode, 'scope' => $scope, 'tool' => $tool];

        // 1. The request guard runs on mod/lti/launch.php before core posts the login initiation.
        $this->setUser($w->learner);
        try {
            request_guard::precheck_launch((int) $activity->cmid);
            $cell['precheck'] = 'passed';
        } catch (\moodle_exception $e) {
            $cell['precheck'] = 'refused before the login initiation';
        }
        $k = $this->knowns($w->learner);
        if ($cell['precheck'] !== 'passed') {
            $cell['oidc_login'] = 'not sent';
            $cell['id_token'] = 'not sent';
            // Without the prediction (for example a direct call of mod/lti/auth.php), core would have sent the login
            // initiation first; the launch itself is still refused by the callback.
            $cell['without_guard'] = ['oidc_login' => $this->login_classes($this->login_request($activity, $typeid), $k),
                'id_token' => $this->launch_outcome($activity, $w->learner)];
            return $cell + $this->deep_linking($typeid, $k);
        }
        $login = $this->login_request($activity, $typeid);
        $claims = $this->launch_claims($activity, $w->learner);
        $k = $this->knowns($w->learner);
        $cell['oidc_login'] = $this->login_classes($login, $k);
        $leaves = $this->flatten($claims, $k);
        ksort($leaves);
        $cell['id_token'] = $leaves;
        $cell += $this->deep_linking($typeid, $this->knowns($w->teacher));
        $cell['linkability'] = $this->linkability($claims['sub'], $tool);
        $cell += $this->services($claims, $tool, $activity);
        return $cell;
    }

    /**
     * The OIDC login initiation parameters of a basic launch, as core builds them.
     *
     * @param \stdClass $activity
     * @param int $typeid
     * @return array
     */
    private function login_request(\stdClass $activity, int $typeid): array {
        global $DB;
        $config = lti_get_type_type_config($typeid);
        $config->lti_clientid = $DB->get_field('lti_types', 'clientid', ['id' => $typeid]);
        return lti_build_login_request($activity->course, $activity->cmid, $activity, $config, 'basic-lti-launch-request');
    }

    /**
     * Launch and return the decoded claims (with the course and module of the page set, as launch.php has them).
     *
     * @param \stdClass $activity
     * @param \stdClass $user
     * @return array
     */
    private function launch_claims(\stdClass $activity, \stdClass $user): array {
        global $COURSE, $DB;
        $COURSE = $DB->get_record('course', ['id' => $activity->course]);
        $_GET['id'] = $activity->cmid;
        try {
            return fixture::launch($activity, $user)[2];
        } finally {
            unset($_GET['id']);
        }
    }

    /**
     * The outcome of a launch that may be refused.
     *
     * @param \stdClass $activity
     * @param \stdClass $user
     * @return string
     */
    private function launch_outcome(\stdClass $activity, \stdClass $user): string {
        try {
            $this->launch_claims($activity, $user);
            return 'sent';
        } catch (\moodle_exception $e) {
            return 'refused by the callback (no id token)';
        }
    }

    /**
     * Deep-linking request of the teacher: precheck, login initiation and id token.
     *
     * @param int $typeid
     * @param array $k knowns of the teacher
     * @return array
     */
    private function deep_linking(int $typeid, array $k): array {
        global $DB;
        $w = $this->w;
        $this->setUser($w->teacher);
        $result = [];
        try {
            request_guard::precheck_deep_linking($typeid, (int) $w->c1->id);
            $result['precheck'] = 'passed';
        } catch (\moodle_exception $e) {
            $result['precheck'] = 'refused before the login initiation';
            return ['deep_linking' => $result];
        }
        $config = lti_get_type_type_config($typeid);
        $config->lti_clientid = $DB->get_field('lti_types', 'clientid', ['id' => $typeid]);
        $login = lti_build_login_request($w->c1->id, 0, null, $config, 'ContentItemSelectionRequest');
        try {
            $request = lti_build_content_item_selection_request(
                $typeid,
                $w->c1,
                new \moodle_url('/mod/lti/contentitem_return.php'),
                '',
                '',
                [],
                [],
                false,
                true,
                false,
                false,
                false,
                'nonce-dl'
            );
            $claims = fixture::decode($request->params['id_token']);
        } catch (\moodle_exception $e) {
            $result['oidc_login'] = $this->login_classes($login, $this->knowns($w->teacher));
            $result['id_token'] = 'refused by the callback (no id token)';
            return ['deep_linking' => $result];
        }
        $k = $this->knowns($w->teacher);
        $result['oidc_login'] = $this->login_classes($login, $k);
        $leaves = $this->flatten($claims, $k);
        ksort($leaves);
        $result['id_token'] = $leaves;
        return ['deep_linking' => $result];
    }

    /**
     * Which other launches of the same person carry the same sub (linkability across activities, courses and tools).
     *
     * @param string $sub sub of the launch of activity a1 (or q1)
     * @param string $tool full or quiet
     * @return array
     */
    private function linkability(string $sub, string $tool): array {
        $w = $this->w;
        if ($tool !== 'full') {
            return [];
        }
        $again = $this->launch_claims($w->a1, $w->learner)['sub'];
        return [
            'same activity again' => $again === $sub ? 'same' : 'different',
            'other activity, same course and tool' => $this->launch_claims($w->a2, $w->learner)['sub'] === $sub ?
                'same' : 'different',
            'other course, same tool' => $this->launch_claims($w->b1, $w->learner)['sub'] === $sub ? 'same' : 'different',
            'other tool, same course' => $this->launch_claims($w->x1, $w->learner)['sub'] === $sub ? 'same' : 'different',
        ];
    }

    /**
     * NRPS and AGS through the URLs of the id token, and a direct call of the core endpoint (bypass attempt).
     *
     * @param array $claims id token claims of the learner
     * @param string $tool
     * @param \stdClass $activity
     * @return array
     */
    private function services(array $claims, string $tool, \stdClass $activity): array {
        global $DB;
        $w = $this->w;
        $bearer = $w->tokens[$tool];
        $knownsof = fn($user) => $this->knowns($user);
        $result = [];
        $nrpsurl = $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url'];
        $result['nrps'] = $this->roster($nrpsurl, $bearer, [], $knownsof);
        $result['nrps_with_rlid'] = $this->roster($nrpsurl, $bearer, ['rlid' => $activity->id], $knownsof);
        $result['nrps_paged'] = $this->paging($nrpsurl, $bearer, ['rlid' => $activity->id]);
        $coreurl = str_replace(client::PROXY_SCRIPT, client::CORE_SCRIPT, $nrpsurl);
        $result['nrps_direct_core_url'] = $this->roster($coreurl, $bearer, ['rlid' => $activity->id], $knownsof);

        $ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? [];
        if (!empty($ags['lineitem'])) {
            $lineitem = $ags['lineitem'];
            $parts = parse_url($lineitem);
            $scoresurl = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') .
                $parts['path'] . '/scores' . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $resultsurl = str_replace('/scores', '/results', $scoresurl);
            $item = \grade_item::fetch(['iteminstance' => $activity->id, 'itemmodule' => 'lti', 'courseid' => $activity->course]);
            $score = client::call('POST', $scoresurl, $bearer, [], client::score((string) $claims['sub'], 42), client::SCORE);
            $grade = $item ? $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $w->learner->id]) :
                false;
            $result['ags_score'] = ['endpoint' => $this->classify($scoresurl, $this->knowns($w->learner)),
                'answered_by' => client::$answeredby, 'code' => $score->get_code(),
                'grade of the launching person' => ((float) $grade === 42.0) ? 'stored' : 'not stored'];
            $res = client::call('GET', $resultsurl, $bearer, [], '', client::RESULT);
            $rows = json_decode((string) $res->get_body(), true) ?: [];
            $k = $this->knowns($w->learner);
            $result['ags_results'] = ['answered_by' => client::$answeredby, 'code' => $res->get_code(),
                'rows' => count($rows), 'userId' => $rows ? $this->classify($rows[0]['userId'] ?? null, $k) : 'n/a',
                'id' => $rows ? $this->classify_result_id((string) ($rows[0]['id'] ?? ''), $k) : 'n/a'];
            // The tool tries the local id it can guess (sequential ids), through the URL it was given and through core.
            $guess = client::call('POST', $scoresurl, $bearer, [], client::score((string) $w->mate->id, 7), client::SCORE);
            $mategrade = $item ? $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $w->mate->id]) :
                false;
            $result['ags_score_guessed_local_id'] = ['code' => $guess->get_code(),
                'grade of the other person' => ((float) $mategrade === 7.0) ? 'stored' : 'not stored'];
            $direct = client::call(
                'POST',
                str_replace(client::PROXY_SCRIPT, client::CORE_SCRIPT, $scoresurl),
                $bearer,
                [],
                client::score((string) $w->mate->id, 8),
                client::SCORE
            );
            $mategrade = $item ? $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $w->mate->id]) :
                false;
            $result['ags_score_guessed_local_id_direct_core_url'] = ['answered_by' => client::$answeredby,
                'code' => $direct->get_code(), 'grade of the other person' => ((float) $mategrade === 8.0) ? 'stored' :
                'not stored'];
            if ($item) {
                $DB->delete_records('grade_grades', ['itemid' => $item->id]);
            }
        }
        return $result;
    }

    /**
     * The class of a result id URL (it ends with user_id=<id or pseudonym>).
     *
     * @param string $id
     * @param array $k
     * @return string
     */
    private function classify_result_id(string $id, array $k): string {
        if (!preg_match('/[?&]user_id=([^&]+)/', $id, $m)) {
            return 'no_user_identifier';
        }
        return 'url with ' . $this->classify(urldecode($m[1]), $k);
    }

    /**
     * Roster request with redacted members (the launching learner and the other learner).
     *
     * @param string $url
     * @param string $bearer
     * @param array $get
     * @param callable $knownsof
     * @return array
     */
    private function roster(string $url, string $bearer, array $get, callable $knownsof): array {
        $w = $this->w;
        $response = client::call('GET', $url, $bearer, $get, '', client::NRPS);
        $result = ['endpoint' => $this->classify($url, $knownsof($w->learner)), 'answered_by' => client::$answeredby,
            'code' => $response->get_code()];
        if ($response->get_code() !== 200) {
            $result['body'] = ((string) $response->get_body() === '') ? 'empty' : 'not empty';
            return $result;
        }
        $data = json_decode($response->get_body(), true);
        foreach (['learner' => $w->learner, 'other member' => $w->mate] as $label => $user) {
            $k = $knownsof($user);
            $found = null;
            foreach ($data['members'] as $member) {
                $class = $this->classify($member['user_id'], $k);
                if (in_array($class, ['local_user_id', 'pseudonym'], true)) {
                    $found = $member;
                }
            }
            if ($found === null) {
                $result[$label] = 'not identifiable in the roster';
                continue;
            }
            $leaves = $this->flatten($found, $k);
            ksort($leaves);
            $result[$label] = $leaves;
        }
        return $result;
    }

    /**
     * Paging of the roster (limit=1): every page is answered where the first one was, links stay on that endpoint.
     *
     * @param string $url
     * @param string $bearer
     * @param array $get
     * @return array
     */
    private function paging(string $url, string $bearer, array $get): array {
        $pages = 0;
        $members = 0;
        $endpoints = [];
        $next = $url;
        $query = $get + ['limit' => 1];
        while ($next !== null && $pages < 10) {
            $response = client::call('GET', $next, $bearer, $query, '', client::NRPS);
            if ($response->get_code() !== 200) {
                return ['code' => $response->get_code()];
            }
            $pages++;
            $members += count(json_decode($response->get_body(), true)['members'] ?? []);
            $next = client::next_link($response);
            if ($next !== null) {
                $endpoints[] = (strpos($next, client::PROXY_SCRIPT) !== false) ? 'proxy' : 'core';
            }
            $query = [];
        }
        $endpoints = array_values(array_unique($endpoints));
        return ['pages' => $pages, 'members' => $members, 'next links point to' => $endpoints ? implode('+', $endpoints) :
            'n/a'];
    }

    /**
     * One LTI 1.1 cell: launch parameters (OAuth 1 form post), deep linking, service URLs.
     *
     * @param string $mode none, site_policy, site_policy_refuse
     * @param string $setting value of legacyprotocol
     * @return array
     */
    private function lti11_cell(string $mode, string $setting): array {
        global $DB, $COURSE, $PAGE;
        $w = $this->w;
        $DB->delete_records('ltisource_ltipseudonym_pol');
        set_config('legacyprotocol', $setting, 'ltisource_ltipseudonym');
        if ($mode !== 'none') {
            fixture::set_policy(['typeid' => 0, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
                'discname' => 'blank', 'discemail' => 'blank']);
        }
        $cell = ['lti' => '1.1', 'mode' => $mode, 'scope' => null, 'tool' => 'full', 'legacyprotocol' => $setting,
            'oidc_login' => 'none in LTI 1.1'];
        try {
            \core\session\manager::set_user($w->learner);
            $COURSE = $DB->get_record('course', ['id' => $w->g1->course]);
            $PAGE = new \moodle_page();
            $PAGE->set_url('/mod/lti/view.php');
            $PAGE->set_course($COURSE);
            $_GET['id'] = $w->g1->cmid;
            [, $params] = lti_get_launch_data(clone $w->g1, 'nonce11', 'basic-lti-launch-request');
            unset($_GET['id']);
            $k = $this->knowns($w->learner);
            $leaves = [];
            foreach ($params as $name => $value) {
                if (in_array($name, ['oauth_nonce', 'oauth_signature', 'oauth_timestamp'], true)) {
                    $value = 'x';
                }
                $leaves[$name] = $this->classify($value, $k);
            }
            ksort($leaves);
            $cell['launch_params'] = $leaves;
        } catch (\moodle_exception $e) {
            unset($_GET['id']);
            $cell['launch_params'] = 'refused by the callback (nothing sent)';
        }
        // Service calls of an LTI 1.x tool go to core: the guard never routes them (not covered by the policies).
        $_GET = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['HTTP_ACCEPT'] = client::NRPS;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $route = request_guard::core_service_response("/CourseSection/{$w->c1->id}/bindings/{$w->legacy}/memberships", '');
        $cell['nrps_ags'] = ($route === null) ? 'answered by core with local ids (not routed to the proxy)' : 'routed to the proxy';
        set_config('legacyprotocol', 'pass', 'ltisource_ltipseudonym');
        return $cell;
    }

    /**
     * The Markdown report of a capture.
     *
     * @param array $capture
     * @param array|null $e2e capture of the two-site Playwright run
     * @param string $release Moodle release the capture ran on
     * @return string
     */
    private function report(array $capture, ?array $e2e, string $release): string {
        $md = [];
        $md[] = '# Disclosure trace';
        $md[] = '';
        $md[] = 'Generated by ' . self::c('tests/disclosure_trace_test.php') . ' (PHPUnit, Moodle ' . $release .
            ') and, for the real two-site ' .
            'capture, by ' . self::c('tests/playwright/disclosure_capture.spec.js') . '. Do not edit by hand: regenerate with ' .
            self::c('LTIPSEUDONYM_TRACE_OUT=<dir>') . ' (see docs/TESTING.md). Machine-readable: ' .
            self::c('tests/fixtures/disclosure_trace.json') . ' ' .
            'and ' . self::c('tests/fixtures/disclosure_trace_e2e.json') . '.';
        $md[] = '';
        $md[] = '**Redaction:** no real value is stored. Every value is replaced by its class: ' . self::c('local_user_id') .
            ' (the Moodle user ' .
            'id), ' . self::c('pseudonym') . ' (a token of this plugin), ' . self::c('name') . ', ' . self::c('email') . ', ' .
            self::c('username') . ', ' . self::c('idnumber') . ', ' . self::c('contains_local_user_id') . ' ' .
            '(a structure that carries the local id, e.g. the LTI 1.1 result id), ' . self::c('contains_session_key') .
            ' (a URL with the ' .
            'Moodle session key), ' . self::c('service_url_proxy') . ' / ' . self::c('service_url_core') . ', ' .
            self::c('empty') . ' (present with an empty value), ' .
            self::c('no_user_identifier') . '. A claim that is not listed is absent.';
        $md[] = '';
        $md[] = 'Tool registrations of the capture: **full** shares names, e-mail and grades and has custom parameters with ' .
            'user variables (' . self::c('$User.id') . ', ' . self::c('$User.username') . ', ' .
            self::c('$Person.email.primary') . ', ' . self::c('$Person.name.full') . ', ' . self::c('$Person.sourcedId') .
            ', ' .
            self::c('$Result.sourcedId') .
            ' in the activity); **quiet** shares nothing. Both have AGS and NRPS. Policies are stored at the tool level ' .
            '(the site scope at the site level, where it is chosen); ' .
            self::c('remove') . ' is set for every category that can be removed (' . self::c('blank') . ' for the ID number).';
        $md[] = '';
        $cells13 = array_values(array_filter($capture['cells'], fn($c) => $c['lti'] === '1.3'));
        $md = array_merge(
            $md,
            $this->report_lti13($cells13),
            $this->report_linkability($cells13),
            $this->report_services($cells13),
            $this->report_lti11($capture['cells'])
        );
        if ($e2e) {
            $md = array_merge($md, $this->report_e2e($e2e));
        }
        return implode("\n", $md) . "\n";
    }

    /**
     * Table of the LTI 1.3 launch carriers per mode (scope deployment, or the only scope).
     *
     * @param array $cells
     * @return string[]
     */
    private function report_lti13(array $cells): array {
        $rows = [
            'OIDC login: login_hint' => fn($c) => $c['oidc_login']['login_hint'] ?? null,
            'OIDC login: lti_message_hint.cmid' => fn($c) => $c['oidc_login']['lti_message_hint.cmid'] ?? null,
            'OIDC login: lti_message_hint.launchid' => fn($c) => $c['oidc_login']['lti_message_hint.launchid'] ?? null,
            'id token: sub' => fn($c) => $c['id_token']['sub'] ?? null,
            'id token: name, given_name, family_name' => fn($c) => $c['id_token']['name'] ?? null,
            'id token: email' => fn($c) => $c['id_token']['email'] ?? null,
            'id token: lti:ext.user_username' => fn($c) => $c['id_token']['lti:ext.user_username'] ?? null,
            'id token: lti:lis.person_sourcedid' => fn($c) => $c['id_token']['lti:lis.person_sourcedid'] ?? null,
            'id token: bo:basicoutcome.lis_result_sourcedid (legacy result id)' =>
                fn($c) => $c['id_token']['bo:basicoutcome.lis_result_sourcedid'] ?? null,
            'id token: lti:custom.userid ($User.id)' => fn($c) => $c['id_token']['lti:custom.userid'] ?? null,
            'id token: lti:custom.email ($Person.email.primary)' => fn($c) => $c['id_token']['lti:custom.email'] ?? null,
            'id token: lti:custom.name ($Person.name.full)' => fn($c) => $c['id_token']['lti:custom.name'] ?? null,
            'id token: lti:custom.username ($User.username)' => fn($c) => $c['id_token']['lti:custom.username'] ?? null,
            'id token: lti:custom.idnumber ($Person.sourcedId)' => fn($c) => $c['id_token']['lti:custom.idnumber'] ?? null,
            'id token: lti:custom.resultid ($Result.sourcedId)' => fn($c) => $c['id_token']['lti:custom.resultid'] ?? null,
            'id token: lti:launch_presentation.return_url' =>
                fn($c) => $c['id_token']['lti:launch_presentation.return_url'] ?? null,
            'id token: nrps:namesroleservice.context_memberships_url' =>
                fn($c) => $c['id_token']['nrps:namesroleservice.context_memberships_url'] ?? null,
            'id token: ags:endpoint.lineitem' => fn($c) => $c['id_token']['ags:endpoint.lineitem'] ?? null,
            'deep linking: precheck' => fn($c) => $c['deep_linking']['precheck'] ?? null,
            'deep linking: login_hint' => fn($c) => $c['deep_linking']['oidc_login']['login_hint'] ?? null,
            'deep linking: sub' => fn($c) => is_array($c['deep_linking']['id_token'] ?? null) ?
                ($c['deep_linking']['id_token']['sub'] ?? null) : ($c['deep_linking']['id_token'] ?? null),
            'deep linking: email' => fn($c) => is_array($c['deep_linking']['id_token'] ?? null) ?
                ($c['deep_linking']['id_token']['email'] ?? null) : null,
            'precheck (launch.php)' => fn($c) => $c['precheck'],
            'without the guard: login_hint' => fn($c) => $c['without_guard']['oidc_login']['login_hint'] ?? null,
            'without the guard: launch' => fn($c) => $c['without_guard']['id_token'] ?? null,
        ];
        $cols = array_values(array_filter($cells, fn($c) => in_array($c['scope'], [null, 'deployment'], true)));
        $md = ['## LTI 1.3: what the tool receives per policy mode', ''];
        $md[] = 'Scope ' . self::c('deployment') .
            ' for the pseudonymising modes (the other scopes give the same classes; they differ in ' .
            'linkability, see the next section). "-" = absent / not applicable.';
        $md[] = '';
        $md[] = '| Carrier | ' . implode(' | ', array_map(fn($c) => self::c($c['mode']), $cols)) . ' |';
        $md[] = '|---|' . str_repeat('---|', count($cols));
        foreach ($rows as $label => $get) {
            $values = array_map(function ($c) use ($get) {
                $v = $get($c);
                return ($v === null) ? '-' : (is_array($v) ? 'see JSON' : $v);
            }, $cols);
            $md[] = '| ' . $label . ' | ' . implode(' | ', $values) . ' |';
        }
        $md[] = '';
        return $md;
    }

    /**
     * Linkability per scope.
     *
     * @param array $cells
     * @return string[]
     */
    private function report_linkability(array $cells): array {
        $md = ['## LTI 1.3: linkability of the sub per scope', ''];
        $md[] = 'Whether another launch of the same person carries the same ' . self::c('sub') . ' (mode ' .
            self::c('random_pass') . ', ' . self::c('hmac_pass') . ' gives the ' .
            'same pattern). Remember that ' . self::c('login_hint') .
            ' carries the local id in every case, so the tool operator can link ' .
            'all of them anyway (gate SP-G1).';
        $md[] = '';
        $md[] = '| Scope | same activity again | other activity, same course and tool | other course, same tool | ' .
            'other tool, same course |';
        $md[] = '|---|---|---|---|---|';
        foreach ($cells as $c) {
            if ($c['mode'] !== 'random_pass') {
                continue;
            }
            $l = $c['linkability'];
            $md[] = '| ' . self::c((string) $c['scope']) . ' | ' . $l['same activity again'] . ' | ' .
                $l['other activity, same course and tool'] .
                ' | ' . $l['other course, same tool'] . ' | ' . $l['other tool, same course'] . ' |';
        }
        foreach ($cells as $c) {
            if ($c['mode'] === 'none') {
                $l = $c['linkability'];
                $md[] = '| (no policy: local id) | ' . $l['same activity again'] . ' | ' .
                    $l['other activity, same course and tool'] . ' | ' . $l['other course, same tool'] . ' | ' .
                    $l['other tool, same course'] . ' |';
            }
        }
        $md[] = '';
        return $md;
    }

    /**
     * NRPS and AGS per mode and scope.
     *
     * @param array $cells
     * @return string[]
     */
    private function report_services(array $cells): array {
        $g = function ($array, string ...$keys) {
            foreach ($keys as $key) {
                if (!is_array($array) || !array_key_exists($key, $array)) {
                    return '-';
                }
                $array = $array[$key];
            }
            return is_array($array) ? 'see JSON' : (string) $array;
        };
        $md = ['## LTI 1.3: NRPS roster and AGS', ''];
        $md[] = 'The tool uses the URLs of its id token. "direct core URL" = the tool calls ' .
            self::c('mod/lti/services.php') . ' itself ' .
            '(bypass attempt); ' . self::c('guard') .
            ' = the request guard answered it with the proxy. Member columns show the learner who ' .
            'launched (roster with ' . self::c('rlid') . '); the other member is in the JSON.';
        $md[] = '';
        $md[] = '| Mode | Scope | NRPS without rlid | NRPS with rlid: user_id | name / email | basic outcome | ' .
            'NRPS direct core URL | paging (limit=1) | AGS score | grade of the launching person | results userId / id | ' .
            'guessed local id via proxy URL | guessed local id via core URL |';
        $md[] = '|---|---|---|---|---|---|---|---|---|---|---|---|---|';
        foreach ($cells as $c) {
            if (!isset($c['nrps'])) {
                $md[] = '| ' . self::c($c['mode']) . ' | ' . ($c['scope'] ?? '-') . ' | launch refused, no service URL issued' .
                    str_repeat(' | ', 11) . ' |';
                continue;
            }
            $paged = $c['nrps_paged'];
            $md[] = '| ' . implode(' | ', [
                self::c($c['mode']),
                $c['scope'] ?? '-',
                $g($c, 'nrps', 'answered_by') . ' ' . $g($c, 'nrps', 'code'),
                $g($c, 'nrps_with_rlid', 'learner', 'user_id'),
                $g($c, 'nrps_with_rlid', 'learner', 'name') . ' / ' . $g($c, 'nrps_with_rlid', 'learner', 'email'),
                $g($c, 'nrps_with_rlid', 'learner', 'message.0.bo:basicoutcome.lis_result_sourcedid'),
                $g($c, 'nrps_direct_core_url', 'answered_by') . ' ' . $g($c, 'nrps_direct_core_url', 'code') . ', user_id ' .
                    $g($c, 'nrps_direct_core_url', 'learner', 'user_id'),
                isset($paged['pages']) ? $paged['pages'] . ' pages, ' . $paged['members'] . ' members, links: ' .
                    $paged['next links point to'] : 'code ' . $g($paged, 'code'),
                $g($c, 'ags_score', 'answered_by') . ' ' . $g($c, 'ags_score', 'code'),
                $g($c, 'ags_score', 'grade of the launching person'),
                $g($c, 'ags_results', 'userId') . ' / ' . $g($c, 'ags_results', 'id'),
                $g($c, 'ags_score_guessed_local_id', 'code') . ', ' .
                    $g($c, 'ags_score_guessed_local_id', 'grade of the other person'),
                $g($c, 'ags_score_guessed_local_id_direct_core_url', 'answered_by') . ' ' .
                    $g($c, 'ags_score_guessed_local_id_direct_core_url', 'code') . ', ' .
                    $g($c, 'ags_score_guessed_local_id_direct_core_url', 'grade of the other person'),
            ]) . ' |';
        }
        $md[] = '';
        return $md;
    }

    /**
     * LTI 1.1 table.
     *
     * @param array $cells
     * @return string[]
     */
    private function report_lti11(array $cells): array {
        $md = ['## LTI 1.1: not covered by the policies (specification SP-L05)', ''];
        $md[] = 'LTI 1.1 has no OIDC login. The launch is an OAuth 1 signed form post built by core; the plugin leaves it ' .
            'unchanged unless the setting "LTI 1.0/1.1 tools under an active policy" is ' . self::c('refuse') . '.';
        $md[] = '';
        $rows = ['user_id', 'lis_person_name_full', 'lis_person_contact_email_primary', 'ext_user_username',
            'lis_person_sourcedid', 'lis_result_sourcedid', 'custom_userid', 'custom_email', 'launch_presentation_return_url'];
        $cols = array_values(array_filter($cells, fn($c) => $c['lti'] === '1.1'));
        $md[] = '| Parameter | ' . implode(' | ', array_map(fn($c) => self::c($c['mode']) . ' (setting ' . $c['legacyprotocol'] .
            ')', $cols)) . ' |';
        $md[] = '|---|' . str_repeat('---|', count($cols));
        foreach ($rows as $row) {
            $md[] = '| ' . $row . ' | ' . implode(' | ', array_map(fn($c) => is_array($c['launch_params']) ?
                ($c['launch_params'][$row] ?? '-') : $c['launch_params'], $cols)) . ' |';
        }
        $md[] = '| NRPS / AGS | ' . implode(' | ', array_map(fn($c) => $c['nrps_ags'], $cols)) . ' |';
        $md[] = '';
        return $md;
    }

    /**
     * The two-site capture of the Playwright run.
     *
     * @param array $e2e
     * @return string[]
     */
    private function report_e2e(array $e2e): array {
        $md = ['## Real two-site capture (Playwright, browser and HTTP)', ''];
        $md[] = 'Captured on the two-site stack on ' . ($e2e['date'] ?? '?') . ': real browser launches, the OIDC login ' .
            'initiation and the id token as they travel through the browser, and NRPS/AGS calls of a capture tool with a ' .
            'real client-credentials access token. Platform sites: ' . implode(', ', array_map(fn($p) => $p['site'] . ' = ' .
            $p['moodle'], $e2e['platforms'] ?? [])) . '.';
        $md[] = '';
        $md[] = '| Platform | Case | login_hint | lti_message_hint | sub | name / email | NRPS user_id | NRPS direct core URL | ' .
            'AGS score -> grade | note |';
        $md[] = '|---|---|---|---|---|---|---|---|---|---|';
        foreach ($e2e['cases'] ?? [] as $case) {
            $md[] = '| ' . implode(' | ', [
                $case['platform'] ?? '', $case['case'] ?? '', $case['login_hint'] ?? '-', $case['lti_message_hint'] ?? '-',
                $case['sub'] ?? '-', $case['names'] ?? '-', $case['nrps_user_id'] ?? '-', $case['nrps_direct_core'] ?? '-',
                $case['ags'] ?? '-', $case['note'] ?? '',
            ]) . ' |';
        }
        $md[] = '';
        return $md;
    }

    /**
     * Markdown inline code (the code checker discourages backticks in PHP strings).
     *
     * @param string $text
     * @return string
     */
    private static function c(string $text): string {
        return chr(96) . $text . chr(96);
    }
}
