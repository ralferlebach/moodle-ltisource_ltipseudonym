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

use ltisource_ltipseudonym\testing\fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Finding SP-04: claim diff. For every policy mode, exactly the claims the policy promises to change are changed in the signed
 * id token, compared with the same launch without policy; nothing else changes, and no claim is ever added or removed by the
 * plugin (a callback can only replace values). The diff survives a backup and restore of the activity.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\launch_processor
 * @covers     \restore_ltisource_ltipseudonym_subplugin
 * @covers     \backup_ltisource_ltipseudonym_subplugin
 */
final class claim_diff_test extends \advanced_testcase {
    /** @var string[] Claims that differ between any two launches. */
    private const VOLATILE = ['iat', 'exp', 'nonce'];

    /** @var string[] Service URLs moved to the proxy by every active policy. */
    private const SERVICES = ['ags:endpoint.lineitem', 'ags:endpoint.lineitems', 'lti:custom.context_memberships_url',
        'nrps:namesroleservice.context_memberships_url'];

    /** @var string[] Claims blanked by "blank" for every category the full registration shares. */
    private const PERSONAL = ['email', 'family_name', 'given_name', 'lti:custom.email', 'lti:custom.idnumber', 'lti:custom.name',
        'lti:custom.username', 'lti:ext.user_username', 'lti:lis.person_sourcedid', 'name'];

    /** @var string[] The pseudonymised identifiers. */
    private const SUBJECT = ['lti:custom.userid', 'sub'];

    /** @var string[] The LTI 1.1 result id and its custom parameter. */
    private const RESULT = ['bo:basicoutcome.lis_result_sourcedid', 'lti:custom.resultid'];

    /**
     * Policy modes and the claims they change.
     *
     * @return array[]
     */
    public static function modes_provider(): array {
        $all = fn(string $value) => ['discname' => $value, 'discemail' => $value, 'discsourcedid' => $value,
            'discusername' => $value, 'discresult' => $value, 'discextra' => $value, 'discpicture' => $value];
        return [
            'nothing configured' => [['pseudotype' => 'none'], []],
            'legacy internal id (services through the proxy, nothing else)' => [['pseudotype' => 'legacy_userid'],
                self::SERVICES],
            'random, pass (result id empty by default)' => [['pseudotype' => 'random_persisted'],
                array_merge(self::SUBJECT, self::RESULT, self::SERVICES)],
            'hmac, pass' => [['pseudotype' => 'hmac_secret_v1', 'scopetype' => 'cm'],
                array_merge(self::SUBJECT, self::RESULT, self::SERVICES)],
            'random, result id passed explicitly' => [['pseudotype' => 'random_persisted', 'discresult' => 'pass'],
                array_merge(self::SUBJECT, self::SERVICES)],
            'random, everything blank' => [['pseudotype' => 'random_persisted'] + $all('blank'),
                array_merge(self::SUBJECT, self::RESULT, self::SERVICES, self::PERSONAL)],
            'disclosure only, everything blank' => [['pseudotype' => 'none'] + $all('blank'),
                array_merge(self::RESULT, self::SERVICES, self::PERSONAL)],
            'only e-mail blank, course scope' => [['pseudotype' => 'random_persisted', 'scopetype' => 'course',
                'discemail' => 'blank'], array_merge(self::SUBJECT, self::RESULT, self::SERVICES, ['email', 'lti:custom.email'])],
        ];
    }

    /**
     * A course, a learner and a tool that shares everything (names, e-mail, grades, custom user variables, AGS, NRPS).
     *
     * @return \stdClass
     */
    private function world(): \stdClass {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        for ($i = 0; $i < 30; $i++) {
            $gen->create_user();
        }
        $w = new \stdClass();
        $w->course = $gen->create_course();
        $w->learner = $gen->create_and_enrol($w->course, 'student', ['idnumber' => 'ID-L', 'email' => 'l@example.com']);
        $custom = "userid=\$User.id\nusername=\$User.username\nemail=\$Person.email.primary\nname=\$Person.name.full\n" .
            "idnumber=\$Person.sourcedId";
        $w->typeid = fixture::create_type(['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1,
            'lti_acceptgrades' => LTI_SETTING_ALWAYS, 'lti_customparameters' => $custom]);
        $w->activity = fixture::create_activity($w->course, $w->typeid, ['instructorchoiceacceptgrades' => 1, 'grade' => 100,
            'instructorcustomparameters' => 'resultid=$Result.sourcedId']);
        return $w;
    }

    /**
     * Launch with the page state of launch.php and flatten the claims.
     *
     * @param \stdClass $activity
     * @param \stdClass $user
     * @return array key => value
     */
    private function claims(\stdClass $activity, \stdClass $user): array {
        global $COURSE, $DB;
        $COURSE = $DB->get_record('course', ['id' => $activity->course]);
        $_GET['id'] = $activity->cmid;
        try {
            return $this->flatten(fixture::launch($activity, $user)[2]);
        } finally {
            unset($_GET['id']);
        }
    }

    /**
     * Flatten a claim set (claim URIs shortened, lists kept as JSON).
     *
     * @param array $data
     * @param string $prefix
     * @return array
     */
    private function flatten(array $data, string $prefix = ''): array {
        $short = [
            'https://purl.imsglobal.org/spec/lti/claim/' => 'lti:',
            'https://purl.imsglobal.org/spec/lti-ags/claim/' => 'ags:',
            'https://purl.imsglobal.org/spec/lti-nrps/claim/' => 'nrps:',
            'https://purl.imsglobal.org/spec/lti-bo/claim/' => 'bo:',
        ];
        $result = [];
        foreach ($data as $key => $value) {
            $key = str_replace(array_keys($short), array_values($short), (string) $key);
            $name = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
                $result += $this->flatten($value, $name);
            } else {
                $result[$name] = is_array($value) ? json_encode($value) : $value;
            }
        }
        return $result;
    }

    /**
     * The claim diff of two claim sets.
     *
     * @param array $base
     * @param array $other
     * @return array [changed, added, removed] sorted key lists
     */
    private function diff(array $base, array $other): array {
        foreach (self::VOLATILE as $key) {
            unset($base[$key], $other[$key]);
        }
        // The result id carries a random launch id: compare whether it is sent with a value, not the value itself.
        foreach (self::RESULT as $key) {
            foreach ([&$base, &$other] as &$set) {
                if (array_key_exists($key, $set)) {
                    $set[$key] = ($set[$key] === '') ? '' : 'value';
                }
            }
            unset($set);
        }
        $changed = [];
        foreach ($base as $key => $value) {
            if (array_key_exists($key, $other) && $other[$key] !== $value) {
                $changed[] = $key;
            }
        }
        $added = array_values(array_diff(array_keys($other), array_keys($base)));
        $removed = array_values(array_diff(array_keys($base), array_keys($other)));
        sort($changed);
        sort($added);
        sort($removed);
        return [$changed, $added, $removed];
    }

    /**
     * Exactly the promised claims change; nothing is added or removed; blanked claims are empty, not absent.
     *
     * @dataProvider modes_provider
     * @param array $policy policy columns (tool level)
     * @param string[] $expected claims that change
     */
    public function test_claim_diff(array $policy, array $expected): void {
        set_config('legacyenabled', 1, 'ltisource_ltipseudonym');
        $w = $this->world();
        $base = $this->claims($w->activity, $w->learner);
        $this->assertArrayHasKey('bo:basicoutcome.lis_result_sourcedid', $base, 'the registration builds the result id');
        $this->assertArrayHasKey('lti:custom.resultid', $base);
        fixture::set_policy(['typeid' => $w->typeid] + $policy + ['scopetype' => 'deployment']);
        $with = $this->claims($w->activity, $w->learner);

        [$changed, $added, $removed] = $this->diff($base, $with);
        sort($expected);
        $this->assertSame($expected, $changed, 'claims changed by the policy');
        $this->assertSame([], $added, 'the plugin never adds a claim');
        $this->assertSame([], $removed, 'the plugin never removes a claim (it cannot)');
        foreach (array_intersect($changed, array_merge(self::PERSONAL, self::RESULT)) as $key) {
            $this->assertSame('', $with[$key], $key . ' is blanked: present with an empty value');
        }
        foreach (array_intersect($changed, self::SERVICES) as $key) {
            $this->assertStringContainsString('/mod/lti/source/ltipseudonym/service.php/', $with[$key]);
        }
    }

    /**
     * "remove" on a registration that shares nothing: the diff is the same as for "pass" (the claims are absent in both),
     * and on a registration that shares, the launch is refused instead of sending anything.
     */
    public function test_remove_is_absent_or_refused(): void {
        $w = $this->world();
        $quiet = fixture::create_type(['lti_sendname' => LTI_SETTING_NEVER, 'lti_sendemailaddr' => LTI_SETTING_NEVER]);
        $activity = fixture::create_activity($w->course, $quiet, ['instructorchoicesendname' => 0,
            'instructorchoicesendemailaddr' => 0]);
        $base = $this->claims($activity, $w->learner);
        $this->assertArrayNotHasKey('email', $base);
        fixture::set_policy(['typeid' => $quiet, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'discname' => 'remove', 'discemail' => 'remove', 'discusername' => 'remove']);
        [$changed, $added, $removed] = $this->diff($base, $this->claims($activity, $w->learner));
        $this->assertSame(['sub'], $changed);
        $this->assertSame([], $added);
        $this->assertSame([], $removed);

        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'discemail' => 'remove']);
        $this->expectException(\moodle_exception::class);
        $this->claims($w->activity, $w->learner);
    }

    /**
     * Backup and restore (course import): the activity policy is restored with the activity, and the restored activity shows
     * the same claim diff as the original.
     */
    public function test_claim_diff_survives_backup_and_restore(): void {
        global $DB;
        $w = $this->world();
        fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'course',
            'allowoverride' => 1]);
        $original = $this->claims($w->activity, $w->learner);
        fixture::set_policy(['courseid' => $w->course->id, 'cmid' => $w->activity->cmid, 'scopetype' => 'cm',
            'discemail' => 'blank', 'discname' => 'blank']);
        [$expected] = $this->diff($original, $this->claims($w->activity, $w->learner));
        $this->assertSame(['email', 'family_name', 'given_name', 'lti:custom.email', 'lti:custom.name', 'lti:custom.userid', 'name',
            'sub'], $expected);

        $target = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($w->learner->id, $target->id, 'student');
        $this->setAdminUser();
        fixture::import_course($w->course, $target);
        $copy = $DB->get_record('lti', ['course' => $target->id], '*', MUST_EXIST);
        $copy->cmid = get_coursemodule_from_instance('lti', $copy->id, $target->id, false, MUST_EXIST)->id;
        $row = $DB->get_record('ltisource_ltipseudonym_pol', ['cmid' => $copy->cmid], '*', MUST_EXIST);
        $this->assertSame('cm', $row->scopetype);
        $this->assertSame('blank', $row->discemail);

        $with = $this->claims($copy, $w->learner);
        $DB->delete_records('ltisource_ltipseudonym_pol', ['cmid' => $copy->cmid]);
        $without = $this->claims($copy, $w->learner);
        [$changed, $added, $removed] = $this->diff($without, $with);
        $this->assertSame($expected, $changed, 'the restored activity changes the same claims');
        $this->assertSame([], $added);
        $this->assertSame([], $removed);
        $this->assertNotSame($original['sub'], $with['sub'], 'a copied activity is a new activity scope');
    }
}
