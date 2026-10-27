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
use ltisource_ltipseudonym\local\claim_guard;
use ltisource_ltipseudonym\local\policy_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Finding SP-03: claims are removed, not merely emptied. The tests decode the real signed id token of a real launch and
 * compare it with the disclosure policy, for every mode (pass, blank, remove), every category and several tool
 * registrations. The documented matrix in docs/DOCUMENTATION.md is the table these tests enforce.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\launch_processor
 * @covers     \ltisource_ltipseudonym\local\claim_guard
 */
final class disclosure_matrix_test extends \advanced_testcase {
    /** @var string Claim prefix of LTI 1.3. */
    private const CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/';
    /** @var string Basic outcomes claim. */
    private const BO = 'https://purl.imsglobal.org/spec/lti-bo/claim/basicoutcome';

    /**
     * Where the claims of a category live in the decoded token: list of [group or null, member] pairs.
     *
     * @param string $category
     * @return array[]
     */
    private static function locations(string $category): array {
        switch ($category) {
            case 'name':
                return [[null, 'given_name'], [null, 'family_name'], [null, 'name']];
            case 'email':
                return [[null, 'email']];
            case 'username':
                return [[self::CLAIM . 'ext', 'user_username']];
            case 'sourcedid':
                return [[self::CLAIM . 'lis', 'person_sourcedid']];
            case 'result':
                return [[self::BO, 'lis_result_sourcedid']];
        }
        throw new \coding_exception('no location for ' . $category);
    }

    /**
     * Whether the claim of a location is present (the key exists, whatever the value) and its value.
     *
     * @param array $claims decoded token
     * @param array $location [group, member]
     * @return array [present, value]
     */
    private static function probe(array $claims, array $location): array {
        [$group, $member] = $location;
        $container = $group === null ? $claims : ($claims[$group] ?? []);
        return [array_key_exists($member, $container), $container[$member] ?? null];
    }

    /**
     * Tool registrations: what core is configured to build.
     *
     * @return array[] name => [type config, activity fields, categories core builds]
     */
    private static function registrations(): array {
        $all = ['lti_sendname' => LTI_SETTING_ALWAYS, 'lti_sendemailaddr' => LTI_SETTING_ALWAYS,
            'lti_acceptgrades' => LTI_SETTING_ALWAYS];
        $none = ['lti_sendname' => LTI_SETTING_NEVER, 'lti_sendemailaddr' => LTI_SETTING_NEVER,
            'lti_acceptgrades' => LTI_SETTING_NEVER];
        $delegate = ['lti_sendname' => LTI_SETTING_DELEGATE, 'lti_sendemailaddr' => LTI_SETTING_DELEGATE,
            'lti_acceptgrades' => LTI_SETTING_DELEGATE];
        $yes = ['instructorchoicesendname' => 1, 'instructorchoicesendemailaddr' => 1, 'instructorchoiceacceptgrades' => 1];
        $no = ['instructorchoicesendname' => 0, 'instructorchoicesendemailaddr' => 0, 'instructorchoiceacceptgrades' => 0];
        return [
            'registration shares everything' => [$all, $yes, ['name', 'email', 'username', 'sourcedid', 'result']],
            'registration shares nothing' => [$none, $no, ['sourcedid']],
            'activity decides: shares' => [$delegate, $yes, ['name', 'email', 'username', 'sourcedid', 'result']],
            'activity decides: shares nothing' => [$delegate, $no, ['sourcedid']],
        ];
    }

    /**
     * Data provider: registration x category x mode.
     *
     * @return array
     */
    public static function matrix_provider(): array {
        $cases = [];
        foreach (array_keys(self::registrations()) as $registration) {
            foreach (['name', 'email', 'username', 'sourcedid', 'result'] as $category) {
                foreach ([catalogue::DISC_PASS, catalogue::DISC_BLANK, catalogue::DISC_REMOVE] as $mode) {
                    $cases["$registration / $category / $mode"] = [$registration, $category, $mode];
                }
            }
        }
        return $cases;
    }

    /**
     * Every cell of the matrix, against the decoded signed token of a real launch.
     *
     * pass: unchanged. blank: present and empty when core builds the claim, otherwise still absent (the plugin never adds
     * a claim). remove: absent when core does not build it; the launch is refused when it does (an empty claim would break
     * the promise). The ID number claim is always built by core, so "remove" is not a valid value for it.
     *
     * @dataProvider matrix_provider
     * @param string $registration key of registrations()
     * @param string $category
     * @param string $mode
     */
    public function test_matrix(string $registration, string $category, string $mode): void {
        global $DB;
        $this->resetAfterTest();
        [$typeconfig, $activityfields, $built] = self::registrations()[$registration];
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'learner@example.com']);
        $DB->set_field('user', 'idnumber', 'MATR-4711', ['id' => $user->id]);
        $user->idnumber = 'MATR-4711';
        $typeid = testing\fixture::create_type($typeconfig);
        $activity = testing\fixture::create_activity($course, $typeid, $activityfields);
        $corebuilds = in_array($category, $built, true);

        // Control launch without any policy: what core builds is what the matrix assumes.
        $control = testing\fixture::launch($activity, $user)[2];
        foreach (self::locations($category) as $location) {
            $this->assertSame($corebuilds, self::probe($control, $location)[0], 'control: core builds ' . $category);
        }

        $field = catalogue::disclosure_field($category);
        if ($mode === catalogue::DISC_REMOVE && !catalogue::is_removable($category)) {
            // Not a valid value: a stored one is ignored (invalid), the claim stays as core builds it.
            testing\fixture::set_policy(['typeid' => $typeid, $field => $mode, 'discextra' => 'pass']);
            $claims = testing\fixture::launch($activity, $user)[2];
            $this->assertSame('MATR-4711', self::probe($claims, self::locations($category)[0])[1]);
            $this->assertContains('invalid', array_column(policy_manager::resolve($typeid)->trace, 'note'));
            return;
        }
        testing\fixture::set_policy(['typeid' => $typeid, $field => $mode]);
        if ($mode === catalogue::DISC_REMOVE && $corebuilds) {
            try {
                testing\fixture::launch($activity, $user);
                $this->fail('a launch that still carries the claim must be refused, not sent with an empty value');
            } catch (\moodle_exception $e) {
                $this->assertSame('launchrefused', $e->errorcode);
            }
            return;
        }
        $claims = testing\fixture::launch($activity, $user)[2];
        foreach (self::locations($category) as $location) {
            [$present, $value] = self::probe($claims, $location);
            if (!$corebuilds) {
                $this->assertFalse($present, "$category: a claim core does not build is never added");
            } else if ($mode === catalogue::DISC_PASS) {
                $this->assertTrue($present);
                $this->assertNotSame('', $value, 'pass delivers the value');
            } else if ($mode === catalogue::DISC_BLANK) {
                $this->assertTrue($present, 'blank keeps the claim in the token');
                $this->assertSame('', $value, 'blank empties the value');
            }
        }
        if ($mode === catalogue::DISC_REMOVE) {
            foreach (self::locations($category) as $location) {
                $this->assertFalse(self::probe($claims, $location)[0], 'remove: the claim is absent from the token');
            }
        }
    }

    /**
     * The core of SP-03: with a registration that shares nothing, "remove" gives a token WITHOUT the claims, and it differs
     * observably from "blank" on a registration that shares them.
     */
    public function test_remove_means_absent_and_blank_means_empty(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'learner@example.com']);

        $quiet = testing\fixture::create_type(['lti_sendname' => LTI_SETTING_NEVER, 'lti_sendemailaddr' => LTI_SETTING_NEVER]);
        $quietactivity = testing\fixture::create_activity($course, $quiet, ['instructorchoicesendname' => 0,
            'instructorchoicesendemailaddr' => 0]);
        testing\fixture::set_policy(['typeid' => $quiet, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'discname' => 'remove', 'discemail' => 'remove', 'discusername' => 'remove', 'discpicture' => 'remove']);
        $removed = testing\fixture::launch($quietactivity, $user)[2];
        foreach (['given_name', 'family_name', 'name', 'email', 'picture'] as $key) {
            $this->assertArrayNotHasKey($key, $removed, "$key is absent (removed)");
        }
        $this->assertArrayNotHasKey('user_username', $removed[self::CLAIM . 'ext'] ?? []);
        $this->assertNotEquals($user->id, $removed['sub']);

        $chatty = testing\fixture::create_type();
        $chattyactivity = testing\fixture::create_activity($course, $chatty);
        testing\fixture::set_policy(['typeid' => $chatty, 'discname' => 'blank', 'discemail' => 'blank']);
        $blanked = testing\fixture::launch($chattyactivity, $user)[2];
        $this->assertArrayHasKey('email', $blanked, 'blank leaves the claim in the token');
        $this->assertSame('', $blanked['email']);
        $this->assertSame('', $blanked['given_name']);
    }

    /**
     * An empty claim that core builds from an empty profile value is still a present claim: "remove" must refuse it.
     */
    public function test_remove_refuses_even_when_the_value_is_already_empty(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->set_field('user', 'email', '', ['id' => $user->id]);
        $user->email = '';
        $typeid = testing\fixture::create_type();
        $activity = testing\fixture::create_activity($course, $typeid);
        $this->assertSame('', testing\fixture::launch($activity, $user)[2]['email'], 'control: present and empty');
        testing\fixture::set_policy(['typeid' => $typeid, 'discemail' => 'remove']);
        $this->expectException(\moodle_exception::class);
        testing\fixture::launch($activity, $user);
    }

    /**
     * Custom claims (substituted user variables) follow the same three modes.
     */
    public function test_custom_claims(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', ['email' => 'learner@example.com']);
        $tool = testing\fixture::create_type([
            'lti_customparameters' => "mail=\$Person.email.primary\nfull=\$Person.name.full\ncourse=\$Context.id",
        ]);
        $activity = testing\fixture::create_activity($course, $tool);
        $custom = fn() => testing\fixture::launch($activity, $user)[2][self::CLAIM . 'custom'];

        $this->assertSame('learner@example.com', $custom()['mail'], 'control: pass');

        testing\fixture::set_policy(['typeid' => $tool, 'discemail' => 'blank']);
        $claims = $custom();
        $this->assertArrayHasKey('mail', $claims);
        $this->assertSame('', $claims['mail'], 'blank: the custom claim stays, empty');
        $this->assertNotSame('', $claims['full'], 'other categories untouched');

        global $DB;
        $DB->set_field('ltisource_ltipseudonym_pol', 'discemail', 'remove', ['typeid' => $tool]);
        try {
            $custom();
            $this->fail('the custom claim is still built by core: the launch must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
            $this->assertStringContainsString('custom_mail', $e->getMessage());
        }

        // Once the registration no longer carries the variable and does not share the address, remove is satisfied and the
        // custom claim is absent. (Core fails on a custom variable of an address it does not build, hence both at once.)
        $DB->set_field('lti_types_config', 'value', "full=\$Person.name.full\ncourse=\$Context.id", [
            'typeid' => $tool, 'name' => 'customparameters']);
        $DB->set_field('lti_types_config', 'value', LTI_SETTING_NEVER, ['typeid' => $tool, 'name' => 'sendemailaddr']);
        $claims = $custom();
        $this->assertArrayNotHasKey('mail', $claims);
        $this->assertEquals($course->id, $claims['course']);
    }

    /**
     * Deep-linking requests are covered as well: signed token compared with the policy.
     */
    public function test_deep_linking_request_claims(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher', ['email' => 'teacher@example.com']);
        $quiet = testing\fixture::create_type([
            'lti_sendname' => LTI_SETTING_NEVER,
            'lti_sendemailaddr' => LTI_SETTING_NEVER,
            'lti_toolurl_ContentItemSelectionRequest' => 'https://dl-quiet.example.com/select',
        ]);
        $chatty = testing\fixture::create_type([
            'lti_toolurl_ContentItemSelectionRequest' => 'https://dl-chatty.example.com/select',
        ]);
        $request = function (int $typeid) use ($course, $user) {
            $this->setUser($user);
            $built = lti_build_content_item_selection_request(
                $typeid,
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
            return testing\fixture::decode($built->params['id_token']);
        };
        $this->assertArrayHasKey('email', $request($chatty), 'control');
        $this->assertArrayNotHasKey('email', $request($quiet), 'control');

        testing\fixture::set_policy(['typeid' => $quiet, 'discemail' => 'remove', 'discname' => 'remove']);
        $claims = $request($quiet);
        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('given_name', $claims);

        testing\fixture::set_policy(['typeid' => $chatty, 'discemail' => 'blank']);
        $this->assertSame('', $request($chatty)['email']);

        global $DB;
        $DB->set_field('ltisource_ltipseudonym_pol', 'discemail', 'remove', ['typeid' => $chatty]);
        $this->expectException(\moodle_exception::class);
        $request($chatty);
    }

    /**
     * Disclosure-only policies do not depend on the deployment, so several registrations with the same endpoint no longer
     * abort a deep-linking request when their patches agree (SP-04).
     */
    public function test_ambiguous_registrations_with_agreeing_disclosure_policy(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher', ['email' => 'teacher@example.com']);
        $config = ['lti_toolurl_ContentItemSelectionRequest' => 'https://shared.example.com/select'];
        $first = testing\fixture::create_type($config);
        $second = testing\fixture::create_type($config);
        testing\fixture::set_policy(['discemail' => 'blank']);
        $this->setUser($user);
        $built = lti_build_content_item_selection_request(
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
        $this->assertSame('', testing\fixture::decode($built->params['id_token'])['email']);
        $this->assertNotSame($first, $second);
    }

    /**
     * The hierarchy treats remove as the strongest value and rejects it for the always-built ID number.
     */
    public function test_policy_hierarchy_and_validation(): void {
        $this->resetAfterTest();
        testing\fixture::set_policy(['typeid' => 9, 'discemail' => 'blank', 'allowoverride' => 1]);
        testing\fixture::set_policy(['typeid' => 9, 'courseid' => 4, 'discemail' => 'remove']);
        $this->assertSame('remove', policy_manager::resolve(9, 4)->discemail, 'a lower level may strengthen blank to remove');
        testing\fixture::set_policy(['typeid' => 10, 'discemail' => 'remove', 'allowoverride' => 1]);
        testing\fixture::set_policy(['typeid' => 10, 'courseid' => 4, 'discemail' => 'blank']);
        $this->assertSame('remove', policy_manager::resolve(10, 4)->discemail, 'but never weaken remove');

        $problems = policy_manager::validate(9, 0, 0, (object) ['discsourcedid' => 'remove']);
        $this->assertContains('err:invalidvalue:discsourcedid', $problems);
        $this->assertSame([], policy_manager::validate(9, 0, 0, (object) ['discsourcedid' => 'blank']));
        $this->assertSame([], policy_manager::validate(9, 0, 0, (object) ['discemail' => 'remove']));
    }

    /**
     * The pre-flight verdict agrees with what a real launch builds, for every registration of the matrix.
     */
    public function test_core_sends_verdict_matches_the_launch(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        foreach (self::registrations() as $name => [$typeconfig, $activityfields, $built]) {
            $typeid = testing\fixture::create_type($typeconfig);
            $verdicts = [];
            foreach (['name', 'email', 'username', 'result', 'sourcedid', 'picture'] as $category) {
                $verdicts[$category] = claim_guard::core_sends(lti_get_type_config($typeid), $category);
            }
            $activity = testing\fixture::create_activity($course, $typeid, $activityfields);
            $claims = testing\fixture::launch($activity, $user)[2];
            foreach (['name', 'email', 'username', 'result', 'sourcedid'] as $category) {
                $present = self::probe($claims, self::locations($category)[0])[0];
                if ($verdicts[$category] === claim_guard::SENDS) {
                    $this->assertTrue($present, "$name: $category");
                } else if ($verdicts[$category] === claim_guard::NOT_SENT) {
                    $this->assertFalse($present, "$name: $category");
                } else {
                    $this->assertSame(in_array($category, $built, true), $present, "$name: $category depends on the activity");
                }
            }
            $this->assertSame(claim_guard::NOT_SENT, $verdicts['picture']);
            $this->assertArrayNotHasKey('picture', $claims);
        }
    }
}
