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

use ltisource_ltipseudonym\local\catalogue;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Integration tests: policies applied through lti_get_launch_data() and the deep-linking request builder.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\launch_processor
 * @covers     ::ltisource_ltipseudonym_before_launch
 */
final class launch_test extends \advanced_testcase {
    /** @var string Claim prefix of LTI 1.3. */
    private const CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/';

    /**
     * Create a course with a learner, a tool and an activity.
     *
     * @param array $typeconfig tool configuration overrides
     * @return array [course, user, typeid, activity]
     */
    private function scenario(array $typeconfig = []): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'learner@example.com']);
        $typeid = testing\fixture::create_type($typeconfig);
        $activity = testing\fixture::create_activity($course, $typeid);
        return [$course, $user, $typeid, $activity];
    }

    /**
     * Control: without any policy the plugin changes nothing and the real id is sent.
     */
    public function test_without_policy_nothing_changes(): void {
        $this->resetAfterTest();
        [, $user, , $activity] = $this->scenario();
        [, $params, $claims] = testing\fixture::launch($activity, $user);
        $this->assertEquals($user->id, $claims['sub']);
        $this->assertSame('learner@example.com', $claims['email']);
        $this->assertSame($user->firstname, $claims['given_name']);
        $this->assertArrayHasKey('id_token', $params);
    }

    /**
     * The random persisted pseudonym replaces the sub of a real launch.
     */
    public function test_random_pseudonym_replaces_sub(): void {
        global $DB;
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_RANDOM, 'scopetype' => 'deployment']);
        [, $params, $claims] = testing\fixture::launch($activity, $user);

        $this->assertIsString($claims['sub']);
        $this->assertNotEquals($user->id, $claims['sub']);
        $mapping = $DB->get_record('ltisource_ltipseudonym_map', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame($mapping->token, $claims['sub']);
        $this->assertSame('random_persisted', $mapping->ptype);
        // The local id appears nowhere as the subject, neither in the token body.
        $this->assertStringNotContainsString('"sub":' . $user->id . ',', json_encode($claims));
        $this->assertStringNotContainsString('"sub":"' . $user->id . '"', json_encode($claims));
    }

    /**
     * Same user, same tool, same scope: the identical sub over 100 real launches and exactly one mapping.
     */
    public function test_sub_is_stable_over_100_launches(): void {
        global $DB;
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_RANDOM, 'scopetype' => 'deployment']);
        $subs = [];
        for ($i = 0; $i < 100; $i++) {
            $subs[] = testing\fixture::launch($activity, $user)[2]['sub'];
        }
        $this->assertCount(1, array_unique($subs));
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));
    }

    /**
     * The HMAC type works through a real launch too and is reproducible by the site only.
     */
    public function test_hmac_launch(): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_HMAC, 'scopetype' => 'deployment']);
        $first = testing\fixture::launch($activity, $user)[2]['sub'];
        $this->assertSame($first, testing\fixture::launch($activity, $user)[2]['sub']);
        $this->assertNotEquals($user->id, $first);
        $this->assertSame(43, strlen($first), 'SHA-256 MAC, base64url without padding');
    }

    /**
     * Another tool, course or activity yields a token that cannot be correlated.
     */
    public function test_scopes_and_tools_yield_separate_tokens(): void {
        $this->resetAfterTest();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course1, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $course2->id, 'student');

        $tool1 = testing\fixture::create_type();
        $tool2 = testing\fixture::create_type();
        // Tool 1 per deployment, tool 2 per course.
        testing\fixture::set_policy(['typeid' => $tool1, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        testing\fixture::set_policy(['typeid' => $tool2, 'pseudotype' => 'random_persisted', 'scopetype' => 'course']);
        $a1c1 = testing\fixture::create_activity($course1, $tool1);
        $a1c2 = testing\fixture::create_activity($course2, $tool1);
        $a2c1 = testing\fixture::create_activity($course1, $tool2);
        $a2c2 = testing\fixture::create_activity($course2, $tool2);

        $sub = fn($activity) => testing\fixture::launch($activity, $user)[2]['sub'];
        // Deployment scope: both courses show the same identity to tool 1.
        $this->assertSame($sub($a1c1), $sub($a1c2));
        // Course scope: tool 2 sees different identities per course.
        $this->assertNotSame($sub($a2c1), $sub($a2c2));
        // Different tools never share a token.
        $tokens = [$sub($a1c1), $sub($a2c1), $sub($a2c2)];
        $this->assertCount(3, array_unique($tokens));
    }

    /**
     * The activity scope separates two activities of the same tool in the same course.
     */
    public function test_cm_scope_separates_activities(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'hmac_secret_v1', 'scopetype' => 'cm']);
        $first = testing\fixture::create_activity($course, $tool);
        $second = testing\fixture::create_activity($course, $tool);
        $s1 = testing\fixture::launch($first, $user)[2]['sub'];
        $s2 = testing\fixture::launch($second, $user)[2]['sub'];
        $this->assertNotSame($s1, $s2);
        $this->assertSame($s1, testing\fixture::launch($first, $user)[2]['sub']);
    }

    /**
     * The site scope is one identity across tools, by explicit choice.
     */
    public function test_site_scope_is_shared_across_tools(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        testing\fixture::set_policy(['pseudotype' => 'random_persisted', 'scopetype' => 'site', 'allowoverride' => 0]);
        $a = testing\fixture::create_activity($course, testing\fixture::create_type());
        $b = testing\fixture::create_activity($course, testing\fixture::create_type());
        $this->assertSame(testing\fixture::launch($a, $user)[2]['sub'], testing\fixture::launch($b, $user)[2]['sub']);
    }

    /**
     * An override at the activity level (explicitly allowed) wins over the tool policy.
     */
    public function test_activity_override_changes_the_scope(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'allowoverride' => 1]);
        $plain = testing\fixture::create_activity($course, $tool);
        $special = testing\fixture::create_activity($course, $tool);
        testing\fixture::set_policy(['courseid' => $course->id, 'cmid' => $special->cmid, 'scopetype' => 'cm']);
        $p = testing\fixture::launch($plain, $user)[2]['sub'];
        $s = testing\fixture::launch($special, $user)[2]['sub'];
        $this->assertNotSame($p, $s);
    }

    /**
     * Fail closed: when the pseudonym cannot be created the launch is refused, never sent with the real id.
     */
    public function test_launch_fails_closed_when_token_generation_fails(): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_HMAC, 'scopetype' => 'deployment']);
        set_config('hmacsecret_v1', 'corrupted', 'ltisource_ltipseudonym');
        try {
            testing\fixture::launch($activity, $user);
            $this->fail('the launch must not be produced');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
            $this->assertStringNotContainsString((string) $user->email, $e->getMessage());
        }
    }

    /**
     * An unusable policy row is not interpreted weakly: an unexpected failure aborts the launch.
     */
    public function test_launch_fails_closed_on_unexpected_errors(): void {
        global $DB;
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_RANDOM, 'scopetype' => 'deployment']);
        // The scope requires a course module, which the processor cannot find.
        $DB->set_field('ltisource_ltipseudonym_pol', 'scopetype', 'cm', ['typeid' => $typeid]);
        $broken = clone $activity;
        $broken->cmid = 0;
        $broken->id = 999999;
        try {
            testing\fixture::launch($broken, $user);
            $this->fail('the launch must not be produced');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
        }
    }

    /**
     * Only LTI 1.3 is handled: an LTI 1.1 tool is left untouched even when a policy exists for it.
     */
    public function test_lti11_tool_is_untouched(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type([
            'lti_ltiversion' => LTI_VERSION_1,
            'lti_resourcekey' => 'key',
            'lti_password' => 'secret',
        ]);
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'discemail' => 'blank']);
        $activity = testing\fixture::create_activity($course, $tool);
        $this->setUser($user);
        global $PAGE;
        $PAGE->set_url('/mod/lti/view.php');
        $PAGE->set_course($course);
        [, $params] = lti_get_launch_data(clone $activity);
        $this->assertEquals($user->id, $params['user_id']);
        $this->assertSame($user->email, $params['lis_person_contact_email_primary']);
    }

    /**
     * Legacy internal id passthrough: refused while disabled, delivered unchanged once enabled.
     */
    public function test_legacy_userid_mode(): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_LEGACY_USERID]);
        try {
            testing\fixture::launch($activity, $user);
            $this->fail('legacy mode is disabled by default');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
        }
        set_config('legacyenabled', 1, 'ltisource_ltipseudonym');
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertEquals($user->id, $claims['sub']);
    }

    /**
     * Migration scenario: legacy first, then a pseudonym; the tool sees two different identities.
     */
    public function test_migration_from_legacy_to_pseudonym_changes_identity(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('legacyenabled', 1, 'ltisource_ltipseudonym');
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_LEGACY_USERID]);
        $before = testing\fixture::launch($activity, $user)[2]['sub'];
        $DB->set_field('ltisource_ltipseudonym_pol', 'pseudotype', 'random_persisted', ['typeid' => $typeid]);
        $after = testing\fixture::launch($activity, $user)[2]['sub'];
        $this->assertEquals($user->id, $before);
        $this->assertNotEquals($before, $after);
    }

    /**
     * The disclosure of names, e-mail and ID number is independent of the sub.
     */
    public function test_disclosure_is_independent_of_sub(): void {
        $this->resetAfterTest();
        [$course, $user, $typeid, $activity] = $this->scenario();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        global $DB;
        $DB->set_field('user', 'idnumber', 'MATR-4711', ['id' => $user->id]);
        $user->idnumber = 'MATR-4711';

        // Names and e-mail blanked, sub untouched (no pseudonymisation).
        testing\fixture::set_policy(['typeid' => $typeid, 'discname' => 'blank', 'discemail' => 'blank']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertEquals($user->id, $claims['sub']);
        $this->assertSame('', $claims['given_name']);
        $this->assertSame('', $claims['family_name']);
        $this->assertSame('', $claims['name']);
        $this->assertSame('', $claims['email']);
        $this->assertSame('MATR-4711', $claims[self::CLAIM . 'lis']['person_sourcedid'], 'not restricted by this policy');

        // Now the ID number too, and ext username.
        $DB->set_field('ltisource_ltipseudonym_pol', 'discsourcedid', 'blank', ['typeid' => $typeid]);
        $DB->set_field('ltisource_ltipseudonym_pol', 'discusername', 'blank', ['typeid' => $typeid]);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertSame('', $claims[self::CLAIM . 'lis']['person_sourcedid']);
        $this->assertSame('', $claims[self::CLAIM . 'ext']['user_username']);
    }

    /**
     * Blanking never adds a claim that core did not send (a blank value is not a removal either way).
     */
    public function test_disclosure_does_not_add_claims(): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario(['lti_sendname' => LTI_SETTING_NEVER,
            'lti_sendemailaddr' => LTI_SETTING_NEVER]);
        $activity->instructorchoicesendname = 0;
        $activity->instructorchoicesendemailaddr = 0;
        testing\fixture::set_policy(['typeid' => $typeid, 'discname' => 'blank', 'discemail' => 'blank']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertArrayNotHasKey('given_name', $claims);
        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('name', $claims);
    }

    /**
     * Custom parameters that resolve to user data follow the same rules.
     */
    public function test_custom_parameters_are_covered(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'learner@example.com']);
        $tool = testing\fixture::create_type([
            'lti_customparameters' => "uid=\$User.id\nmail=\$Person.email.primary\nuname=\$User.username\ncourse=\$Context.id",
        ]);
        $activity = testing\fixture::create_activity($course, $tool, ['instructorcustomparameters' => 'iuid=$User.id']);

        // Control: core delivers the local id and the e-mail address in custom parameters.
        $custom = testing\fixture::launch($activity, $user)[2][self::CLAIM . 'custom'];
        $this->assertEquals($user->id, $custom['uid']);
        $this->assertSame('learner@example.com', $custom['mail']);

        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'discemail' => 'blank', 'discusername' => 'blank']);
        [, , $claims] = testing\fixture::launch($activity, $user);
        $custom = $claims[self::CLAIM . 'custom'];
        $this->assertSame($claims['sub'], $custom['uid'], 'the id in custom parameters is the pseudonym');
        $this->assertSame($claims['sub'], $custom['iuid'], 'also for instructor defined parameters');
        $this->assertSame('', $custom['mail']);
        $this->assertSame('', $custom['uname']);
        $this->assertEquals($course->id, $custom['course'], 'parameters without user data are untouched');
    }

    /**
     * lis_result_sourcedid carries the local user id in plain JSON; the policy can blank it.
     */
    public function test_result_sourcedid_leaks_the_id_unless_blanked(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type(['lti_acceptgrades' => LTI_SETTING_ALWAYS]);
        $activity = testing\fixture::create_activity($course, $tool, ['instructorchoiceacceptgrades' => 1]);
        $bo = 'https://purl.imsglobal.org/spec/lti-bo/claim/basicoutcome';

        $claims = testing\fixture::launch($activity, $user)[2];
        $sourcedid = json_decode($claims[$bo]['lis_result_sourcedid']);
        $this->assertEquals($user->id, $sourcedid->data->userid, 'evidence: core puts the local user id here');

        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'discresult' => 'blank']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertSame('', $claims[$bo]['lis_result_sourcedid']);
    }

    /**
     * A launch on behalf of another user (for_user) carries that user's pseudonym, not the local id.
     */
    public function test_for_user_claim_is_pseudonymised(): void {
        $this->resetAfterTest();
        [$course, $teacher, $typeid, $activity] = $this->scenario();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $claims = testing\fixture::launch($activity, $teacher, $student->id)[2];
        $foruser = $claims[self::CLAIM . 'for_user']['user_id'];
        $this->assertNotEquals($student->id, $foruser);
        // It is exactly the pseudonym the student receives in a normal launch.
        $this->assertSame(testing\fixture::launch($activity, $student)[2]['sub'], $foruser);
    }

    /**
     * Claims of the services as they reach the tool, read from the signed id token.
     *
     * @param array $claims id token claims
     * @return string[] name => URL
     */
    private function service_urls(array $claims): array {
        $urls = [];
        $nrps = $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice'] ?? [];
        $ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? [];
        foreach (['context_memberships_url' => $nrps, 'lineitems' => $ags, 'lineitem' => $ags] as $name => $group) {
            if (isset($group[$name])) {
                $urls[$name] = $group[$name];
            }
        }
        return $urls;
    }

    /**
     * Finding SP-02: a pseudonymised launch to a tool with AGS or NRPS is no longer refused. The tool is pointed to the
     * service proxy of this plugin, and the sub is the pseudonym.
     *
     * @dataProvider service_provider
     * @param array $serviceconfig
     * @param string[] $expected URL claims that must be present
     */
    public function test_services_launch_points_the_tool_to_the_proxy(array $serviceconfig, array $expected): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario($serviceconfig);
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertNotEquals($user->id, $claims['sub']);
        $urls = $this->service_urls($claims);
        $this->assertEqualsCanonicalizing($expected, array_keys($urls));
        $proxy = (new \moodle_url('/mod/lti/source/ltipseudonym/service.php'))->out(false) . '/';
        foreach ($urls as $name => $url) {
            $this->assertStringStartsWith($proxy, $url, $name);
            $this->assertStringNotContainsString('/mod/lti/services.php', $url, $name);
        }
    }

    /**
     * Negative control of the rewrite: without a policy, or with a policy that changes nothing, the URLs are those of core.
     * A disclosure-only policy is answered by the proxy (the roster must apply it), see the next test.
     */
    public function test_service_urls_are_untouched_when_the_policy_changes_nothing(): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario(['ltiservice_gradesynchronization' => 1, 'ltiservice_memberships' => 1]);
        $core = (new \moodle_url('/mod/lti/services.php'))->out(false) . '/';
        foreach ([null, ['pseudotype' => 'none'], ['pseudotype' => 'none', 'discemail' => 'pass']] as $policy) {
            if ($policy !== null) {
                testing\fixture::set_policy(['typeid' => $typeid] + $policy);
            }
            $urls = $this->service_urls(testing\fixture::launch($activity, $user)[2]);
            $this->assertNotEmpty($urls);
            foreach ($urls as $name => $url) {
                $this->assertStringStartsWith($core, $url, $name);
            }
            global $DB;
            $DB->delete_records('ltisource_ltipseudonym_pol');
        }
    }

    /**
     * Every pseudonym type and scope launches with services and moves the URLs.
     *
     * @dataProvider type_scope_provider
     * @param string $pseudotype
     * @param string $scope
     */
    public function test_services_launch_for_every_type_and_scope(string $pseudotype, string $scope): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario(['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => $pseudotype, 'scopetype' => $scope,
            'allowoverride' => 1]);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertNotEquals($user->id, $claims['sub']);
        $this->assertStringContainsString('/source/ltipseudonym/service.php/', $this->service_urls($claims)['lineitems']);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function type_scope_provider(): array {
        $cases = [];
        foreach (['random_persisted', 'hmac_secret_v1'] as $type) {
            foreach (['site', 'deployment', 'course', 'cm'] as $scope) {
                $cases["$type/$scope"] = [$type, $scope];
            }
        }
        return $cases;
    }

    /**
     * A policy set on the course or the activity moves the URLs as well (the effective policy decides).
     */
    public function test_service_urls_follow_course_and_activity_level_policies(): void {
        $this->resetAfterTest();
        [$course, $user, $typeid, $activity] = $this->scenario(['ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $typeid, 'allowoverride' => 1]);
        $this->assertStringContainsString('/mod/lti/services.php/', $this->service_urls(
            testing\fixture::launch($activity, $user)[2]
        )['context_memberships_url']);
        testing\fixture::set_policy(['typeid' => $typeid, 'courseid' => $course->id, 'allowoverride' => 1,
            'pseudotype' => 'random_persisted', 'scopetype' => 'course']);
        $this->assertStringContainsString('/source/ltipseudonym/service.php/', $this->service_urls(
            testing\fixture::launch($activity, $user)[2]
        )['context_memberships_url']);
    }

    /**
     * The URLs follow the policy per launch: switching a service on afterwards is picked up immediately.
     */
    public function test_service_urls_appear_when_a_service_is_switched_on(): void {
        global $DB;
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $this->assertSame([], $this->service_urls(testing\fixture::launch($activity, $user)[2]));
        $DB->insert_record('lti_types_config', (object) ['typeid' => $typeid, 'name' => 'ltiservice_memberships', 'value' => 1]);
        $urls = $this->service_urls(testing\fixture::launch($activity, $user)[2]);
        $this->assertStringContainsString('/source/ltipseudonym/service.php/', $urls['context_memberships_url']);
    }

    /**
     * A disclosure-only policy (the sub stays the local id) sends the service URLs to the proxy, which applies the policy to
     * the roster.
     */
    public function test_disclosure_only_policy_moves_the_service_urls_to_the_proxy(): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario(['ltiservice_gradesynchronization' => 1, 'ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $typeid, 'discemail' => 'blank']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertEquals($user->id, $claims['sub']);
        $urls = $this->service_urls($claims);
        $this->assertNotEmpty($urls);
        $proxy = (new \moodle_url('/mod/lti/source/ltipseudonym/service.php'))->out(false) . '/';
        foreach ($urls as $name => $url) {
            $this->assertStringStartsWith($proxy, $url, $name);
        }
    }

    /**
     * The legacy internal id does not pseudonymise: AGS and NRPS see the same id the token carries. The policy is active, so
     * the proxy answers (it keeps the local ids and applies the disclosure policy).
     */
    public function test_legacy_userid_uses_the_proxy_with_local_ids(): void {
        $this->resetAfterTest();
        set_config('legacyenabled', 1, 'ltisource_ltipseudonym');
        [, $user, $typeid, $activity] = $this->scenario(['ltiservice_gradesynchronization' => 1, 'ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'legacy_userid']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertEquals($user->id, $claims['sub']);
        $urls = $this->service_urls($claims);
        $this->assertStringContainsString('/source/ltipseudonym/service.php/', $urls['context_memberships_url']);
    }

    /**
     * LTI 1.1 basic outcomes cannot be translated (the result sourcedid carries the local id). With a tool that accepts
     * grades the pseudonymising policy empties the sourcedid by default, the administrator can pass it explicitly (it then
     * leaks, which the overview warns about), and "remove" refuses the launch because core always builds it.
     */
    public function test_basic_outcomes_are_the_one_strict_boundary(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type(['lti_acceptgrades' => LTI_SETTING_ALWAYS, 'ltiservice_gradesynchronization' => 1]);
        $activity = testing\fixture::create_activity($course, $tool, ['instructorchoiceacceptgrades' => 1]);
        $bo = 'https://purl.imsglobal.org/spec/lti-bo/claim/basicoutcome';
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);

        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertSame('', $claims[$bo]['lis_result_sourcedid'], 'default: no local id through the result id');
        $this->assertStringNotContainsString('"userid":' . $user->id, json_encode($claims));
        $this->assertNotEmpty($this->service_urls($claims), 'AGS works at the same time');

        $DB->set_field('ltisource_ltipseudonym_pol', 'discresult', 'pass', ['typeid' => $tool]);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertEquals($user->id, json_decode($claims[$bo]['lis_result_sourcedid'])->data->userid, 'explicit pass leaks');

        $DB->set_field('ltisource_ltipseudonym_pol', 'discresult', 'remove', ['typeid' => $tool]);
        try {
            testing\fixture::launch($activity, $user);
            $this->fail('remove cannot be honoured while core builds the result id');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
            $this->assertStringContainsString('lis_result_sourcedid', $e->getMessage());
        }
    }

    /**
     * A deep-linking request of a tool with services is no longer refused under a pseudonymising policy.
     */
    public function test_deep_linking_with_services_is_pseudonymised(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $tool = testing\fixture::create_type([
            'ltiservice_gradesynchronization' => 1,
            'lti_contentitem' => 1,
            'lti_toolurl_ContentItemSelectionRequest' => 'https://dl.example.com/select',
        ]);
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $this->setUser($user);
        $request = lti_build_content_item_selection_request(
            $tool,
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
            'nonce1'
        );
        $claims = testing\fixture::decode($request->params['id_token']);
        $this->assertNotEquals($user->id, $claims['sub']);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function service_provider(): array {
        return [
            'AGS' => [['ltiservice_gradesynchronization' => 1], ['lineitems']],
            'NRPS' => [['ltiservice_memberships' => 1], ['context_memberships_url']],
            'both' => [['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1],
                ['lineitems', 'context_memberships_url']],
        ];
    }

    /**
     * Disclosure-only policies do not touch the sub.
     */
    public function test_disclosure_only_policy_with_services(): void {
        $this->resetAfterTest();
        [, $user, $typeid, $activity] = $this->scenario(['ltiservice_gradesynchronization' => 1,
            'ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $typeid, 'discemail' => 'blank']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertEquals($user->id, $claims['sub']);
        $this->assertSame('', $claims['email']);
    }

    /**
     * Gate SP-G1 evidence: login_hint of the OIDC login initiation is the local user id, whatever the policy.
     */
    public function test_login_hint_still_exposes_the_local_id(): void {
        $this->resetAfterTest();
        [$course, $user, $typeid, $activity] = $this->scenario();
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $this->setUser($user);
        $config = (object) array_merge(['typeid' => $typeid], lti_get_type_config($typeid));
        $config->lti_toolurl = $config->toolurl;
        $config->lti_clientid = $GLOBALS['DB']->get_field('lti_types', 'clientid', ['id' => $typeid]);
        $request = lti_build_login_request($course->id, $activity->cmid, $activity, $config, 'basic-lti-launch-request');
        $this->assertEquals($user->id, $request['login_hint'], 'gate SP-G1 is open: no hook exists to change login_hint');
        $hint = json_decode($request['lti_message_hint'], true);
        $this->assertEquals($activity->cmid, $hint['cmid']);
        // The id token that follows carries the pseudonym.
        $this->assertNotEquals($user->id, testing\fixture::launch($activity, $user)[2]['sub']);
    }

    /**
     * Deep linking: the selection request carries the pseudonym as well.
     */
    public function test_deep_linking_request_is_pseudonymised(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher', ['email' => 'teacher@example.com']);
        $tool = testing\fixture::create_type([
            'lti_contentitem' => 1,
            'lti_toolurl_ContentItemSelectionRequest' => 'https://dl.example.com/select',
        ]);
        $activity = testing\fixture::create_activity($course, $tool);
        $dl = function () use ($course, $tool, $user) {
            $this->setUser($user);
            $request = lti_build_content_item_selection_request(
                $tool,
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
                'nonce1'
            );
            return testing\fixture::decode($request->params['id_token']);
        };
        $this->assertEquals($user->id, $dl()['sub'], 'control without policy');

        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'discemail' => 'blank']);
        $claims = $dl();
        $this->assertNotEquals($user->id, $claims['sub']);
        $this->assertSame('', $claims['email']);
        $this->assertSame(
            testing\fixture::launch($activity, $user)[2]['sub'],
            $claims['sub'],
            'deployment scope: selection request and launch show the same identity'
        );
    }

    /**
     * Deep linking has no course module yet: the activity scope falls back to the course scope.
     */
    public function test_deep_linking_cm_scope_uses_course_scope(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $tool = testing\fixture::create_type(['lti_toolurl_ContentItemSelectionRequest' => 'https://dl.example.com/select']);
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'cm']);
        $this->setUser($user);
        $request = lti_build_content_item_selection_request(
            $tool,
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
            'nonce1'
        );
        $sub = testing\fixture::decode($request->params['id_token'])['sub'];
        $mapping = $DB->get_record('ltisource_ltipseudonym_map', ['token' => $sub], '*', MUST_EXIST);
        $this->assertSame('course', $mapping->scopetype);
        $this->assertEquals($course->id, $mapping->scopeid);
    }

    /**
     * Two registrations with the same selection endpoint cannot be told apart: refuse when a policy applies.
     */
    public function test_deep_linking_with_ambiguous_registrations_fails_closed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $config = ['lti_toolurl_ContentItemSelectionRequest' => 'https://shared.example.com/select'];
        $first = testing\fixture::create_type($config);
        testing\fixture::create_type($config);
        testing\fixture::set_policy(['typeid' => $first, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        lti_build_content_item_selection_request(
            $first,
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
            'nonce1'
        );
    }

    /**
     * A deep-linking request of an unidentifiable tool must not bypass an active site policy.
     */
    public function test_deep_linking_of_unknown_tool_under_site_policy_fails_closed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($user);
        $instance = (object) ['course' => $course->id];
        $params = ['lti_message_type' => 'ContentItemSelectionRequest', 'user_id' => $user->id];
        $this->assertSame([], ltisource_ltipseudonym_before_launch($instance, 'https://nowhere.example.com', $params));
        testing\fixture::set_policy(['pseudotype' => 'random_persisted', 'scopetype' => 'deployment', 'allowoverride' => 1]);
        $this->expectException(\moodle_exception::class);
        ltisource_ltipseudonym_before_launch($instance, 'https://nowhere.example.com', $params);
    }

    /**
     * The audit trail contains no pseudonym after launches.
     */
    public function test_audit_and_events_never_contain_tokens(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $user, $typeid, $activity] = $this->scenario();
        \ltisource_ltipseudonym\local\policy_manager::save($typeid, 0, 0, (object) ['pseudotype' => 'random_persisted',
            'scopetype' => 'deployment'], get_admin()->id);
        $sub = testing\fixture::launch($activity, $user)[2]['sub'];
        foreach ($DB->get_records('ltisource_ltipseudonym_aud') as $audit) {
            $this->assertStringNotContainsString($sub, (string) $audit->oldvalue . $audit->newvalue . $audit->action);
        }
        foreach ($DB->get_records('logstore_standard_log', ['component' => 'ltisource_ltipseudonym']) as $log) {
            $this->assertStringNotContainsString($sub, json_encode($log));
        }
    }

    /**
     * The plugin entry point is found and called by core through component_callback.
     */
    public function test_core_calls_the_callback_by_its_component_name(): void {
        $this->resetAfterTest();
        [, $user, , $activity] = $this->scenario();
        $this->setUser($user);
        $patch = component_callback(
            'ltisource_ltipseudonym',
            'before_launch',
            [$activity, 'https://x.example.com', ['user_id' => $user->id]],
            'NOT-FOUND'
        );
        $this->assertSame([], $patch);
        $this->assertArrayHasKey('ltipseudonym', \core_component::get_plugin_list('ltisource'));
    }
}
