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

use ltisource_ltipseudonym\form\policy_form;
use ltisource_ltipseudonym\local\overview_builder;
use ltisource_ltipseudonym\local\policy_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * UI level tests that need no browser: form validation and overview data.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\form\policy_form
 * @covers     \ltisource_ltipseudonym\local\overview_builder
 * @covers     \ltisource_ltipseudonym\output\overview
 * @covers     \ltisource_ltipseudonym\output\renderer
 */
final class ui_test extends \advanced_testcase {
    /**
     * Build the form for a level and return its validation result for the submitted data.
     *
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @param array $submitted
     * @return array errors
     */
    private function validate(int $typeid, int $courseid, int $cmid, array $submitted): array {
        $form = new policy_form(new \moodle_url('/mod/lti/source/ltipseudonym/editpolicy.php'), [
            'typeid' => $typeid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'stored' => policy_manager::get_row($typeid, $courseid, $cmid),
            'effectivebefore' => policy_manager::resolve($typeid, $courseid, $cmid),
            'activemappings' => 0,
        ]);
        $data = $submitted + ['typeid' => $typeid, 'courseid' => $courseid, 'cmid' => $cmid, 'pseudotype' => '',
            'scopetype' => '', 'allowoverride' => 0, 'confirmchange' => 0];
        foreach (local\catalogue::DISCLOSURES as $category) {
            $data += [local\catalogue::disclosure_field($category) => ''];
        }
        return $form->validation($data, []);
    }

    /**
     * Changing the identity requires the explicit confirmation (scope change warning).
     */
    public function test_identity_change_requires_confirmation(): void {
        $this->resetAfterTest();
        $tool = testing\fixture::create_type();
        $errors = $this->validate($tool, 0, 0, ['pseudotype' => 'random_persisted', 'scopetype' => 'course']);
        $this->assertArrayHasKey('confirmchange', $errors);
        $errors = $this->validate($tool, 0, 0, ['pseudotype' => 'random_persisted', 'scopetype' => 'course',
            'confirmchange' => 1]);
        $this->assertSame([], $errors);
    }

    /**
     * Disclosure changes alone do not change the identity and need no confirmation.
     */
    public function test_disclosure_change_needs_no_confirmation(): void {
        $this->resetAfterTest();
        $tool = testing\fixture::create_type();
        $this->assertSame([], $this->validate($tool, 0, 0, ['discemail' => 'blank']));
    }

    /**
     * A policy weaker than the level above is rejected with an error on the field.
     */
    public function test_form_rejects_weaker_values(): void {
        $this->resetAfterTest();
        $tool = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'course',
            'discemail' => 'blank', 'allowoverride' => 1]);
        $errors = $this->validate($tool, 5, 0, ['pseudotype' => 'hmac_secret_v1', 'discemail' => 'pass']);
        $this->assertArrayHasKey('pseudotype', $errors);
        $this->assertArrayHasKey('discemail', $errors);
    }

    /**
     * AGS and NRPS no longer block the policy: the service proxy translates the pseudonym (finding SP-02), and there is
     * no acknowledgement field left in the form.
     */
    public function test_form_accepts_pseudonymisation_with_services(): void {
        $this->resetAfterTest();
        $tool = testing\fixture::create_type(['ltiservice_gradesynchronization' => 1, 'ltiservice_memberships' => 1]);
        $this->assertSame([], $this->validate($tool, 0, 0, ['pseudotype' => 'random_persisted', 'confirmchange' => 1]));
        $this->assertSame([], $this->validate($tool, 0, 0, ['discname' => 'blank']));
        $form = new policy_form(new \moodle_url('/mod/lti/source/ltipseudonym/editpolicy.php'), [
            'typeid' => $tool, 'courseid' => 0, 'cmid' => 0, 'stored' => null,
            'effectivebefore' => policy_manager::resolve($tool), 'activemappings' => 0,
        ]);
        $this->assertStringNotContainsString('ackservices', $form->render());
    }

    /**
     * The policy form shows the data protection boundary right where the identity is chosen.
     */
    public function test_form_shows_the_boundary(): void {
        $this->resetAfterTest();
        $tool = testing\fixture::create_type();
        $form = new policy_form(new \moodle_url('/mod/lti/source/ltipseudonym/editpolicy.php'), [
            'typeid' => $tool, 'courseid' => 0, 'cmid' => 0, 'stored' => null,
            'effectivebefore' => policy_manager::resolve($tool), 'activemappings' => 0,
        ]);
        $this->assertStringContainsString(get_string('loginhintnotice', 'ltisource_ltipseudonym'), $form->render());
    }

    /**
     * The legacy mode is not selectable and not savable unless enabled.
     */
    public function test_form_rejects_legacy_when_disabled(): void {
        $this->resetAfterTest();
        $tool = testing\fixture::create_type();
        $errors = $this->validate($tool, 0, 0, ['pseudotype' => 'legacy_userid', 'confirmchange' => 1]);
        $this->assertArrayHasKey('pseudotype', $errors);
    }

    /**
     * The overview lists the site default and each LTI 1.3 tool with the effective policy and warnings.
     */
    public function test_overview_rows(): void {
        $this->resetAfterTest();
        $plain = testing\fixture::create_type();
        $ags = testing\fixture::create_type(['ltiservice_gradesynchronization' => 1]);
        testing\fixture::create_type(['lti_ltiversion' => LTI_VERSION_1]);
        testing\fixture::set_policy(['typeid' => $ags, 'pseudotype' => 'random_persisted', 'discemail' => 'blank']);

        $rows = overview_builder::build();
        $this->assertCount(3, $rows, 'site default and the two LTI 1.3 tools, not the LTI 1.1 tool');
        $this->assertSame(0, $rows[0]['typeid']);
        $byid = array_column($rows, null, 'typeid');
        $this->assertSame([], $byid[$plain]['services']);
        $this->assertFalse((bool) $byid[$plain]['viaproxy']);
        $this->assertSame(['ags'], $byid[$ags]['services']);
        $this->assertTrue((bool) $byid[$ags]['viaproxy'], 'pseudonymising and AGS: answered by the service proxy');
        $this->assertArrayNotHasKey('blocking', $byid[$ags]);
        // The result identifier is blanked by default while the tool is pseudonymised (finding SP-02).
        $this->assertSame(['email', 'result'], $byid[$ags]['blanked']);
        $this->assertTrue($byid[$ags]['hasstored']);

        // A disclosure-only policy is answered by the proxy as well; a policy that changes nothing is not.
        $disclosure = testing\fixture::create_type(['ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $disclosure, 'discname' => 'blank']);
        $inert = testing\fixture::create_type(['ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $inert, 'pseudotype' => 'none']);
        $byid = array_column(overview_builder::build(), null, 'typeid');
        $this->assertTrue((bool) $byid[$disclosure]['viaproxy']);
        $this->assertFalse((bool) $byid[$inert]['viaproxy']);
    }

    /**
     * The renderer shows the tools and that the services are answered by the service proxy.
     */
    public function test_overview_is_rendered(): void {
        global $PAGE;
        $this->resetAfterTest();
        $ags = testing\fixture::create_type(['ltiservice_gradesynchronization' => 1], ['name' => 'Grade tool']);
        testing\fixture::set_policy(['typeid' => $ags, 'pseudotype' => 'random_persisted']);
        $PAGE->set_url('/mod/lti/source/ltipseudonym/policy.php');
        $renderer = $PAGE->get_renderer('ltisource_ltipseudonym');
        $html = $renderer->render_overview(new output\overview(overview_builder::build(), true, 2));
        $this->assertStringContainsString(get_string('sitedefault', 'ltisource_ltipseudonym'), $html);
        $this->assertStringContainsString('AGS', $html);
        $this->assertStringContainsString(get_string('servicesproxy', 'ltisource_ltipseudonym'), $html);
        $this->assertStringContainsString('migrate.php', $html);
        $this->assertStringContainsString(get_string('orphansfound', 'ltisource_ltipseudonym', 2), $html);
    }

    /**
     * Finding SP-04: the overview states what a launch really carries per category and never says "removed" (absent) for a
     * claim that core still sends: that is a refused launch. LTI 1.x registrations are listed as not covered.
     */
    public function test_overview_never_claims_removed_for_a_sent_claim(): void {
        global $PAGE;
        $this->resetAfterTest();
        $component = 'ltisource_ltipseudonym';
        $sharing = testing\fixture::create_type(['lti_typename' => 'Sharing tool']);
        $quiet = testing\fixture::create_type(
            ['lti_sendname' => LTI_SETTING_NEVER, 'lti_sendemailaddr' => LTI_SETTING_NEVER, 'lti_typename' => 'Quiet tool']
        );
        $delegating = testing\fixture::create_type(['lti_sendname' => LTI_SETTING_DELEGATE, 'lti_typename' => 'Delegating tool']);
        testing\fixture::create_type(['lti_ltiversion' => LTI_VERSION_1, 'lti_typename' => 'Old 1.1 tool']);
        foreach ([$sharing, $quiet, $delegating] as $typeid) {
            testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'discname' => 'remove',
                'discemail' => 'blank']);
        }
        testing\fixture::set_policy(['typeid' => 0, 'discname' => 'blank', 'allowoverride' => 1]);
        $byid = array_column(overview_builder::build(), null, 'typeid');
        $this->assertSame(['mode' => 'remove', 'effect' => 'refused'], $byid[$sharing]['effects']['name']);
        $this->assertSame(['mode' => 'blank', 'effect' => 'sentempty'], $byid[$sharing]['effects']['email']);
        $this->assertSame(['mode' => 'remove', 'effect' => 'absent'], $byid[$quiet]['effects']['name']);
        $this->assertSame(['mode' => 'blank', 'effect' => 'notsent'], $byid[$quiet]['effects']['email']);
        $this->assertSame(['mode' => 'remove', 'effect' => 'refusedmaybe'], $byid[$delegating]['effects']['name']);
        $this->assertSame(['mode' => 'blank', 'effect' => 'depends'], $byid[0]['effects']['name']);
        $this->assertArrayNotHasKey('picture', $byid[$sharing]['effects'], 'pass is not listed as a restriction');

        $PAGE->set_url('/mod/lti/source/ltipseudonym/policy.php');
        $html = $PAGE->get_renderer($component)->render_overview(new output\overview(overview_builder::build(), false, 0));
        $line = fn($category, $mode, $effect) => s(get_string('effectline', $component, (object) [
            'category' => get_string('disc:' . $category, $component),
            'mode' => get_string('discshort:' . $mode, $component),
            'effect' => get_string('effect:' . $effect, $component),
        ]));
        $this->assertStringContainsString($line('name', 'remove', 'refused'), $html);
        $this->assertStringContainsString($line('name', 'remove', 'absent'), $html);
        $this->assertStringContainsString($line('email', 'blank', 'sentempty'), $html);
        $this->assertSame(1, substr_count($html, s(get_string('effect:absent', $component))), 'absent only for the quiet tool');
        $this->assertStringContainsString(s(get_string('disclosureoutcomes', $component)), $html);
        $this->assertStringContainsString('Old 1.1 tool', $html);
        $this->assertStringContainsString(get_string('legacytypes:pass', $component, 'Old 1.1 tool'), $html);
        set_config('legacyprotocol', 'refuse', $component);
        $html = $PAGE->get_renderer($component)->render_overview(new output\overview(overview_builder::build(), false, 0));
        $this->assertStringContainsString(get_string('legacytypes:refuse', $component, 'Old 1.1 tool'), $html);
        foreach (['disclosureoutcomes', 'disclosureinfo'] as $key) {
            foreach (['en', 'de'] as $lang) {
                // The language files are read directly: the test site has no German language pack installed.
                $string = [];
                include(__DIR__ . '/../lang/' . $lang . '/ltisource_ltipseudonym.php');
                $text = $string[$key];
                $this->assertGreaterThanOrEqual(3, substr_count(strtolower($text), $lang === 'en' ? 'remove' : 'entfernen') +
                    substr_count(strtolower($text), $lang === 'en' ? 'refuse' : 'ablehn'), $key . ' ' . $lang);
            }
        }
    }
}
