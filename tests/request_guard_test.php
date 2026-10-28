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
 * The request guard (core hook after_config): routing of direct calls of mod/lti/services.php to the proxy (finding SP-03),
 * prediction of refused launches before the OIDC login initiation (SP-02), and the LTI 1.x rule.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\request_guard
 * @covers     \ltisource_ltipseudonym\local\launch_processor
 */
final class request_guard_test extends \advanced_testcase {
    /** @var array Saved superglobals. */
    private $saved = [];

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
        global $SESSION;
        unset($SESSION->lti_initiatelogin_status);
        $_SERVER = $this->saved['server'];
        $_GET = $this->saved['get'];
        parent::tearDown();
    }

    /**
     * A course with two learners and a sharing tool with AGS and NRPS.
     *
     * @param array $config extra tool configuration
     * @return \stdClass
     */
    private function world(array $config = []): \stdClass {
        $this->resetAfterTest();
        $this->redirectEvents();
        $gen = $this->getDataGenerator();
        $w = new \stdClass();
        $w->course = $gen->create_course();
        $w->anna = $gen->create_and_enrol($w->course, 'student', ['firstname' => 'Anna', 'email' => 'anna@example.com']);
        $w->ben = $gen->create_and_enrol($w->course, 'student', ['firstname' => 'Ben', 'email' => 'ben@example.com']);
        $w->teacher = $gen->create_and_enrol($w->course, 'editingteacher');
        $w->typeid = fixture::create_type($config + ['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1,
            'lti_contentitem' => 1, 'lti_toolurl_ContentItemSelectionRequest' => 'https://guard.example.com/dl']);
        $w->activity = fixture::create_activity($w->course, $w->typeid);
        $w->token = client::access_token($w->typeid);
        return $w;
    }

    /**
     * Core registers the callback for the after_config hook (it runs for every request, before the script).
     */
    public function test_callback_is_registered_for_after_config(): void {
        $callbacks = \core\di::get(\core\hook\manager::class)->get_callbacks_for_hook(\core\hook\after_config::class);
        $encoded = json_encode($callbacks);
        $this->assertStringContainsString('ltisource_ltipseudonym\\\\local\\\\request_guard::after_config', $encoded);
        // In CLI (and therefore in PHPUnit) the callback returns at once.
        request_guard::after_config(new \core\hook\after_config());
        $this->assertTrue(CLI_SCRIPT);
    }

    /**
     * Without the guard, a tool can call the core endpoint directly and gets local ids and names, against the policy (the
     * bypass this guard closes). With the guard, the same request is answered by the proxy.
     */
    public function test_direct_core_roster_is_answered_by_the_proxy(): void {
        $w = $this->world();
        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'discname' => 'blank', 'discemail' => 'blank']);
        $path = "/CourseSection/{$w->course->id}/bindings/{$w->typeid}/memberships";

        // Negative control: core alone (what a request would get if the guard did not exist).
        client::send('GET', 'core', $path, $w->token, [], '', client::NRPS);
        $core = client::core_dispatch($path, '');
        $this->assertSame(200, $core->get_code());
        $this->assertStringContainsString('"user_id":"' . $w->anna->id . '"', $core->get_body());
        $this->assertStringContainsString('anna@example.com', $core->get_body());

        $response = client::send('GET', 'core', $path, $w->token, [], '', client::NRPS);
        $this->assertSame('guard', client::$answeredby);
        $this->assertSame(200, $response->get_code());
        $body = $response->get_body();
        $this->assertStringNotContainsString('"user_id":"' . $w->anna->id . '"', $body);
        $this->assertStringNotContainsString('anna@example.com', $body);
        $this->assertStringNotContainsString('Anna', $body);
        $sub = fixture::launch($w->activity, $w->anna)[2]['sub'];
        $this->assertStringContainsString($sub, $body);
        $this->assertStringContainsString(client::PROXY_SCRIPT, json_decode($body)->id, 'links of the answer point to the proxy');
    }

    /**
     * A direct core score for a guessed local id is answered by the proxy: refused, nothing stored.
     */
    public function test_direct_core_score_with_local_id_is_refused(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $w = $this->world();
        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'course']);
        $item = new \grade_item(['courseid' => $w->course->id, 'itemtype' => 'mod', 'itemmodule' => 'lti',
            'iteminstance' => $w->activity->id, 'itemnumber' => 0, 'itemname' => 'lti', 'grademax' => 100], false);
        $item->insert();
        $path = "/{$w->course->id}/lineitems/{$item->id}/lineitem/scores";
        $response = client::send(
            'POST',
            'core',
            $path,
            $w->token,
            ['type_id' => $w->typeid],
            client::score((string) $w->ben->id, 33),
            client::SCORE
        );
        $this->assertSame('guard', client::$answeredby);
        $this->assertSame(404, $response->get_code());
        $this->assertFalse($DB->record_exists('grade_grades', ['itemid' => $item->id, 'userid' => $w->ben->id]));
        // Also without type_id: the tool is taken from the bearer token.
        $response = client::send('POST', 'core', $path, $w->token, [], client::score((string) $w->ben->id, 34), client::SCORE);
        $this->assertSame('guard', client::$answeredby);
        $this->assertSame(404, $response->get_code());
        $this->assertFalse($DB->record_exists('grade_grades', ['itemid' => $item->id, 'userid' => $w->ben->id]));
    }

    /**
     * Zero regression: without an active policy, for line items, for LTI 1.x tools and for unknown links core answers.
     */
    public function test_core_answers_everything_else(): void {
        $w = $this->world();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = client::NRPS;
        $path = "/CourseSection/{$w->course->id}/bindings/{$w->typeid}/memberships";
        $this->assertNull(request_guard::core_service_response($path, ''), 'no policy');
        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'none']);
        $this->assertNull(request_guard::core_service_response($path, ''), 'a policy that changes nothing');
        fixture::set_policy(['typeid' => 0, 'pseudotype' => 'random_persisted', 'scopetype' => 'site']);
        $legacy = fixture::create_type(['lti_ltiversion' => LTI_VERSION_1, 'ltiservice_memberships' => 1]);
        $this->assertNull(request_guard::core_service_response(
            "/CourseSection/{$w->course->id}/bindings/{$legacy}/memberships",
            ''
        ), 'LTI 1.x tool: not covered');
        $this->assertNull(request_guard::core_service_response('/links/999999/memberships', ''), 'unknown link');
        $_SERVER['HTTP_ACCEPT'] = 'application/vnd.ims.lis.v2.lineitemcontainer+json';
        $_GET = ['type_id' => $w->typeid];
        $this->assertInstanceOf(
            \ltiservice_gradebookservices\local\resources\lineitems::class,
            local\proxy\dispatcher::match(local\proxy\dispatcher::new_response(), "/{$w->course->id}/lineitems")
        );
        $this->assertNull(
            request_guard::core_service_response("/{$w->course->id}/lineitems", ''),
            'line items carry no user data'
        );
        $_GET = [];
        $this->assertNull(request_guard::core_service_response('', ''), 'no path');
        $this->assertNull(request_guard::core_service_response('/nothing/here', ''));
        // The site policy now covers the LTI 1.3 tool: routed (and answered by the proxy with the authentication of core).
        $response = client::send('GET', 'core', $path, $w->token, [], '', client::NRPS);
        $this->assertSame('guard', client::$answeredby);
        $this->assertSame(200, $response->get_code());
    }

    /**
     * A launch the policy refuses is refused BEFORE core posts the login initiation (no login_hint leaves), without any
     * side effect; afterwards a passing launch works normally.
     */
    public function test_refused_launch_is_predicted_before_the_login(): void {
        global $DB;
        $w = $this->world();
        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'cm',
            'discname' => 'remove']);
        $this->setUser($w->anna);
        try {
            request_guard::precheck_launch((int) $w->activity->cmid);
            $this->fail('the remove conflict must be predicted');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
            $this->assertStringNotContainsString((string) $w->anna->id, $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('ltisource_ltipseudonym_map'), 'a prediction creates no mapping');

        // A passing prediction: no mapping either, and the real launch afterwards is pseudonymised as usual.
        $DB->set_field('ltisource_ltipseudonym_pol', 'discname', 'blank', ['typeid' => $w->typeid]);
        request_guard::precheck_launch((int) $w->activity->cmid);
        $this->assertSame(0, $DB->count_records('ltisource_ltipseudonym_map'));
        $claims = fixture::launch($w->activity, $w->anna)[2];
        $this->assertNotEquals($w->anna->id, $claims['sub']);
        $this->assertSame('', $claims['name']);
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));

        // Legacy mode disabled: predicted as well.
        $DB->set_field('ltisource_ltipseudonym_pol', 'pseudotype', 'legacy_userid', ['typeid' => $w->typeid]);
        $this->expectException(\moodle_exception::class);
        request_guard::precheck_launch((int) $w->activity->cmid);
    }

    /**
     * The prediction leaves everything it is not entitled to judge to core: not logged in, guest, no capability, the second
     * pass after the login, LTI 1.x tools, unknown activities, and policies that pass.
     */
    public function test_prediction_only_where_it_applies(): void {
        global $SESSION;
        $w = $this->world();
        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'discname' => 'remove']);
        $cmid = (int) $w->activity->cmid;
        $this->setUser(null);
        request_guard::precheck_launch($cmid);
        $this->setGuestUser();
        request_guard::precheck_launch($cmid);
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        request_guard::precheck_launch($cmid);
        $this->setUser($w->anna);
        $SESSION->lti_initiatelogin_status = 1;
        request_guard::precheck_launch($cmid);
        unset($SESSION->lti_initiatelogin_status);
        request_guard::precheck_launch(0);
        request_guard::precheck_launch(999999);
        $legacy = fixture::create_type(['lti_ltiversion' => LTI_VERSION_1]);
        $legacyactivity = fixture::create_activity($w->course, $legacy);
        request_guard::precheck_launch((int) $legacyactivity->cmid);
        $this->expectException(\moodle_exception::class);
        request_guard::precheck_launch($cmid);
    }

    /**
     * Deep linking: a refused selection request is predicted before the login initiation; a user who may not add tools is
     * left to core.
     */
    public function test_deep_linking_prediction(): void {
        global $DB;
        $w = $this->world();
        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'discemail' => 'remove']);
        $this->setUser($w->anna);
        request_guard::precheck_deep_linking($w->typeid, (int) $w->course->id);
        $this->setUser($w->teacher);
        request_guard::precheck_deep_linking($w->typeid, 0);
        request_guard::precheck_deep_linking(999999, (int) $w->course->id);
        try {
            request_guard::precheck_deep_linking($w->typeid, (int) $w->course->id);
            $this->fail('the remove conflict must be predicted');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
        }
        $DB->set_field('ltisource_ltipseudonym_pol', 'discemail', 'blank', ['typeid' => $w->typeid]);
        request_guard::precheck_deep_linking($w->typeid, (int) $w->course->id);
        $this->assertSame(0, $DB->count_records('ltisource_ltipseudonym_map'), 'a prediction creates no mapping');
    }

    /**
     * LTI 1.x launches: delivered unchanged by default (not covered), refused under an active policy when the site says so;
     * the same for a deep-linking request of an identified LTI 1.x registration.
     */
    public function test_lti1_rule(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $legacy = fixture::create_type(['lti_ltiversion' => LTI_VERSION_1, 'lti_resourcekey' => 'k', 'lti_password' => 's',
            'lti_contentitem' => 1, 'lti_toolurl_ContentItemSelectionRequest' => 'https://legacy.example.com/dl']);
        $activity = fixture::create_activity($course, $legacy);
        $launch = function () use ($activity, $user, $course, &$PAGE) {
            \core\session\manager::set_user($user);
            $PAGE = new \moodle_page();
            $PAGE->set_url('/mod/lti/view.php');
            $PAGE->set_course($course);
            return lti_get_launch_data(clone $activity, 'n', 'basic-lti-launch-request')[1];
        };
        $select = fn() => lti_build_content_item_selection_request(
            $legacy,
            $course,
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
            'n'
        )->params;

        fixture::set_policy(['typeid' => 0, 'pseudotype' => 'random_persisted', 'scopetype' => 'site']);
        $this->assertEquals($user->id, $launch()['user_id'], 'default pass: delivered unchanged, with the local id');
        $this->assertEquals($user->id, $select()['user_id']);

        set_config('legacyprotocol', 'refuse', 'ltisource_ltipseudonym');
        foreach (['launch' => $launch, 'deep linking' => $select] as $label => $call) {
            try {
                $call();
                $this->fail($label . ' must be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('launchrefused', $e->errorcode, $label);
            }
        }
        // Without an active policy nothing is refused.
        global $DB;
        $DB->delete_records('ltisource_ltipseudonym_pol');
        $this->assertEquals($user->id, $launch()['user_id']);
    }
}
