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

use core_privacy\local\metadata\collection;
use ltisource_ltipseudonym\privacy\provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Finding SP-01 (gate SP-G1): the OIDC login_hint cannot be decoupled from the local user id with the extension points that
 * Moodle core offers, so the data protection boundary is declared instead. These tests document, with evidence from the
 * running core, what leaves the platform before the pseudonymised id token, and that the declaration is shown where an
 * administrator decides. They go red when core starts to offer a way to change the hint (then the gate must be re-assessed)
 * or when a declaration disappears.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class login_hint_boundary_test extends \advanced_testcase {
    /**
     * Build the login initiation form of a real activity, as core posts it to the tool.
     *
     * @param \stdClass $course
     * @param \stdClass $activity
     * @param int $typeid
     * @return string HTML of the auto-submitting form
     */
    private function initiation_form(\stdClass $course, \stdClass $activity, int $typeid): string {
        global $DB;
        $config = (object) array_merge(['typeid' => $typeid], lti_get_type_config($typeid));
        $config->lti_toolurl = $config->toolurl;
        $config->lti_initiatelogin = $config->initiatelogin;
        $config->lti_clientid = $DB->get_field('lti_types', 'clientid', ['id' => $typeid]);
        return lti_initiate_login($course->id, $activity->cmid, $activity, $config, 'basic-lti-launch-request');
    }

    /**
     * What the tool receives first: a browser form post to the tool's login URL carrying the LOCAL user id as login_hint, under a
     * pseudonymising policy, while the id token that follows carries the pseudonym.
     */
    public function test_tool_receives_the_local_id_as_login_hint(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $typeid = testing\fixture::create_type();
        $activity = testing\fixture::create_activity($course, $typeid);
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'cm']);

        $this->setUser($user);
        $form = $this->initiation_form($course, $activity, $typeid);
        $claims = testing\fixture::launch($activity, $user)[2];

        $login = lti_get_type_config($typeid)['initiatelogin'];
        $this->assertStringContainsString('action="' . $login . '"', $form, 'sent to the tool');
        $this->assertStringContainsString('method="post"', $form);
        $this->assertStringContainsString('name="login_hint" value="' . $user->id . '"', $form, 'boundary: the local id leaves');
        $this->assertStringNotContainsString($claims['sub'], $form, 'the pseudonym is not part of the initiation');
        $this->assertNotEquals($user->id, $claims['sub'], 'while the id token carries the pseudonym');
        $this->assertArrayNotHasKey('login_hint', $claims);
    }

    /**
     * The same for every pseudonym type and scope: the policy has no influence on the hint.
     */
    public function test_login_hint_is_independent_of_the_policy(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $typeid = testing\fixture::create_type();
        $activity = testing\fixture::create_activity($course, $typeid);
        $this->setUser($user);
        $hints = [];
        $policies = [[null, null], ['random_persisted', 'deployment'], ['hmac_secret_v1', 'course'], ['random_persisted', 'cm']];
        foreach ($policies as $p) {
            $DB->delete_records('ltisource_ltipseudonym_pol');
            if ($p[0] !== null) {
                testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => $p[0], 'scopetype' => $p[1],
                    'discname' => 'remove']);
            }
            $form = $this->initiation_form($course, $activity, $typeid);
            preg_match('/name="login_hint" value="([^"]*)"/', $form, $m);
            $hints[] = $m[1];
        }
        $this->assertSame([(string) $user->id], array_unique($hints));
    }

    /**
     * Why it cannot be decoupled: core builds the hint in a function without any extension point...
     */
    public function test_core_builds_the_hint_without_an_extension_point(): void {
        $function = new \ReflectionFunction('lti_build_login_request');
        $lines = file($function->getFileName());
        $length = $function->getEndLine() - $function->getStartLine() + 1;
        $body = implode('', array_slice($lines, $function->getStartLine() - 1, $length));
        $this->assertStringContainsString("\$params['login_hint'] = \$USER->id;", $body);
        foreach (['component_callback', 'get_plugins_with_function', 'dispatch', 'hook', 'trigger('] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "no $needle in the login request builder");
        }
    }

    /**
     * ... and the login endpoint accepts exactly that value and nothing else, so a replaced hint would break every launch.
     */
    public function test_core_login_endpoint_accepts_only_the_local_id(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/mod/lti/auth.php');
        $this->assertStringContainsString('$loginhint !== $USER->id', $source);
        $this->assertStringContainsString("'access_denied'", $source);
    }

    /**
     * The declaration is in the administration settings, where the plugin is configured.
     */
    public function test_declaration_in_the_admin_settings(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('ltisource_ltipseudonym');
        $root = admin_get_root(true, true);
        $page = $root->locate($plugininfo->get_settings_section_name());
        $this->assertNotNull($page, 'the settings page exists');
        $setting = $page->settings->ltisource_ltipseudonymboundary;
        $text = $setting->name . ' ' . $setting->visiblename . ' ' . $setting->description;
        $this->assertStringContainsString('login_hint', $text);
        $this->assertStringContainsString('SP-G1', $text);
    }

    /**
     * The declaration is a warning at the top of the overview, in the policy form, and in the privacy metadata.
     */
    public function test_declaration_in_overview_form_and_privacy_metadata(): void {
        global $PAGE;
        $this->resetAfterTest();
        $notice = get_string('loginhintnotice', 'ltisource_ltipseudonym');
        $this->assertStringContainsString('login_hint', $notice);
        $this->assertStringContainsString('not end-to-end pseudonymous', $notice);

        $PAGE->set_url('/mod/lti/source/ltipseudonym/policy.php');
        $renderer = $PAGE->get_renderer('ltisource_ltipseudonym');
        $html = $renderer->render_overview(new output\overview(local\overview_builder::build(), false, 0));
        $this->assertStringContainsString($notice, $html);
        // The notification renderer of the CLI test environment prints notifications as "!! text !!".
        $this->assertMatchesRegularExpression('/alert-warning|^!! /', $html, 'rendered as a notification');
        $this->assertLessThan(strpos($html, '<table'), strpos($html, $notice), 'above the table, not a footnote');

        $collection = provider::get_metadata(new collection('ltisource_ltipseudonym'));
        $external = array_values(array_filter($collection->get_collection(), fn($i) => $i->get_name() === 'lti_provider'))[0];
        $this->assertArrayHasKey('userid', $external->get_privacy_fields());
        $this->assertStringContainsString('login_hint', get_string('privacy:metadata:external', 'ltisource_ltipseudonym'));
        $this->assertStringContainsString('login_hint', get_string('privacy:metadata:external:userid', 'ltisource_ltipseudonym'));
    }

    /**
     * The German texts carry the same declaration.
     */
    public function test_declaration_in_german(): void {
        $stringman = get_string_manager();
        foreach (['loginhintnotice', 'boundary_desc', 'privacy:metadata:external', 'privacy:metadata:external:userid'] as $key) {
            $text = $stringman->get_string($key, 'ltisource_ltipseudonym', null, 'de');
            $this->assertStringContainsString('login_hint', $text, $key);
        }
    }

    /**
     * The documentation of the plugin makes the same statement (README, DOCUMENTATION, PRIVACY, SECURITY, ACCEPTANCE).
     */
    public function test_declaration_in_the_documents(): void {
        global $CFG;
        $root = $CFG->dirroot . '/mod/lti/source/ltipseudonym/';
        foreach (['README.md', 'docs/DOCUMENTATION.md', 'docs/PRIVACY.md', 'docs/SECURITY.md', 'docs/ACCEPTANCE.md'] as $file) {
            $text = file_get_contents($root . $file);
            $this->assertStringContainsString('login_hint', $text, $file);
            $this->assertStringContainsString('SP-G1', $text, $file);
        }
    }
}
