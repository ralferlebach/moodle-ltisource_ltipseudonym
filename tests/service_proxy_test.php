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

use ltisource_ltipseudonym\local;
use ltisource_ltipseudonym\local\proxy\dispatcher;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Finding SP-02 (evidence C and D of the design): the identity-aware service proxy with real access token rows, real path
 * info, the real core resources and the real gradebook.
 *
 * Every request goes through dispatcher::handle() exactly as service.php does; only the sending of the response is left out.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\proxy\dispatcher
 * @covers     \ltisource_ltipseudonym\local\proxy\translator
 * @covers     \ltisource_ltipseudonym\local\services\memberships
 * @covers     \ltisource_ltipseudonym\local\services\gradebookservices
 * @covers     \ltisource_ltipseudonym\local\services\resources\contextmemberships
 * @covers     \ltisource_ltipseudonym\local\services\resources\linkmemberships
 * @covers     \ltisource_ltipseudonym\local\services\resources\scores
 * @covers     \ltisource_ltipseudonym\local\services\resources\results
 */
final class service_proxy_test extends \advanced_testcase {
    /** @var string NRPS media type. */
    private const NRPS = 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json';
    /** @var string NRPS scope. */
    private const NRPS_SCOPE = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';
    /** @var string AGS score scope. */
    private const SCORE_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
    /** @var string AGS result scope. */
    private const RESULT_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';
    /** @var string AGS score media type. */
    private const SCORE = 'application/vnd.ims.lis.v1.score+json';
    /** @var string AGS result media type. */
    private const RESULT = 'application/vnd.ims.lis.v2.resultcontainer+json';

    /** @var array Superglobals restored after each test. */
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
        $_SERVER = $this->saved['server'];
        $_GET = $this->saved['get'];
        parent::tearDown();
    }

    /**
     * A course with two learners, two tools with NRPS and AGS and an activity of the first tool with a grade item.
     *
     * @param array $policy policy columns for both tools
     * @return \stdClass
     */
    private function world(array $policy = ['pseudotype' => 'random_persisted', 'scopetype' => 'deployment']): \stdClass {
        $this->resetAfterTest();
        $w = new \stdClass();
        $w->course = $this->getDataGenerator()->create_course();
        $w->anna = $this->getDataGenerator()->create_and_enrol($w->course, 'student', [
            'firstname' => 'Anna', 'lastname' => 'Alpha', 'email' => 'anna@example.com', 'idnumber' => 'ID-ANNA',
        ]);
        $w->ben = $this->getDataGenerator()->create_and_enrol($w->course, 'student', [
            'firstname' => 'Ben', 'lastname' => 'Beta', 'email' => 'ben@example.com', 'idnumber' => 'ID-BEN',
        ]);
        $services = ['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1];
        $w->typeid = testing\fixture::create_type($services);
        $w->othertypeid = testing\fixture::create_type($services);
        $w->activity = testing\fixture::create_activity($w->course, $w->typeid);
        $w->otheractivity = testing\fixture::create_activity($w->course, $w->othertypeid);
        $w->item = $this->grade_item($w->course, $w->activity);
        $w->otheritem = $this->grade_item($w->course, $w->otheractivity);
        if ($policy) {
            foreach ([$w->typeid, $w->othertypeid] as $typeid) {
                testing\fixture::set_policy(['typeid' => $typeid] + $policy);
            }
        }
        $w->token = $this->access_token($w->typeid, [self::NRPS_SCOPE, self::SCORE_SCOPE, self::RESULT_SCOPE]);
        $w->othertoken = $this->access_token($w->othertypeid, [self::NRPS_SCOPE, self::SCORE_SCOPE, self::RESULT_SCOPE]);
        $this->redirectEvents();
        return $w;
    }

    /**
     * The grade item of an activity.
     *
     * @param \stdClass $course
     * @param \stdClass $activity
     * @return \grade_item
     */
    private function grade_item(\stdClass $course, \stdClass $activity): \grade_item {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'lti',
            'iteminstance' => $activity->id]);
        if (!$item) {
            $item = new \grade_item(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'lti',
                'iteminstance' => $activity->id, 'itemnumber' => 0, 'itemname' => 'lti', 'grademax' => 100], false);
            $item->insert();
        }
        return $item;
    }

    /**
     * A real access token row, as mod/lti/token.php writes it.
     *
     * @param int $typeid
     * @param string[] $scopes
     * @return string the bearer token
     */
    private function access_token(int $typeid, array $scopes): string {
        global $DB;
        $token = bin2hex(random_bytes(16));
        $DB->insert_record('lti_access_tokens', (object) ['typeid' => $typeid, 'scope' => json_encode($scopes),
            'token' => $token, 'validuntil' => time() + 3600, 'timecreated' => time(), 'lastaccess' => null]);
        return $token;
    }

    /**
     * One request through the proxy dispatcher.
     *
     * @param string $method
     * @param string $path path info
     * @param string|null $bearer bearer token
     * @param array $get query parameters
     * @param string $body request body
     * @param string $type Accept (GET) or Content-Type (POST) header
     * @return \mod_lti\local\ltiservice\response not yet sent
     */
    private function request(
        string $method,
        string $path,
        ?string $bearer,
        array $get = [],
        string $body = '',
        string $type = ''
    ): \mod_lti\local\ltiservice\response {
        foreach (['HTTP_ACCEPT', 'CONTENT_TYPE', 'HTTP_AUTHORIZATION'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['QUERY_STRING'] = http_build_query($get);
        $_GET = $get;
        if ($bearer !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        if ($method === 'GET') {
            $_SERVER['HTTP_ACCEPT'] = $type;
        } else {
            $_SERVER['CONTENT_TYPE'] = $type;
        }
        return dispatcher::handle($path, $body);
    }

    /**
     * Roster of the course through the proxy.
     *
     * @param \stdClass $w world
     * @param string|null $bearer
     * @param array $get query
     * @param int|null $typeid tool of the path (default: the tool of the world)
     * @return \mod_lti\local\ltiservice\response
     */
    private function roster(\stdClass $w, ?string $bearer, array $get = [], ?int $typeid = null) {
        $typeid = $typeid ?? $w->typeid;
        $path = "/CourseSection/{$w->course->id}/bindings/{$typeid}/memberships";
        return $this->request('GET', $path, $bearer, $get, '', self::NRPS);
    }

    /**
     * Post a score through the proxy.
     *
     * @param \stdClass $w world
     * @param string|null $bearer
     * @param array $score score fields
     * @param \grade_item|null $item
     * @return \mod_lti\local\ltiservice\response
     */
    private function post_score(\stdClass $w, ?string $bearer, array $score, ?\grade_item $item = null) {
        $item = $item ?? $w->item;
        $score += ['timestamp' => date('c', time() + 60), 'scoreGiven' => 50, 'scoreMaximum' => 100,
            'gradingProgress' => 'FullyGraded', 'activityProgress' => 'Completed'];
        return $this->request(
            'POST',
            "/{$w->course->id}/lineitems/{$item->id}/lineitem/scores",
            $bearer,
            [],
            json_encode($score),
            self::SCORE
        );
    }

    /**
     * The pseudonym a learner gets in a real launch of an activity.
     *
     * @param \stdClass $activity
     * @param \stdClass $user
     * @return string
     */
    private function launched_sub(\stdClass $activity, \stdClass $user): string {
        return testing\fixture::launch($activity, $user)[2]['sub'];
    }

    // Evidence C: NRPS.

    /**
     * The roster carries the pseudonyms the learners received in their launches, and no local id and no name.
     */
    public function test_roster_has_the_pseudonyms_of_the_launches(): void {
        $w = $this->world(['pseudotype' => 'random_persisted', 'scopetype' => 'deployment', 'discname' => 'blank',
            'discemail' => 'blank']);
        $annasub = $this->launched_sub($w->activity, $w->anna);
        $bensub = $this->launched_sub($w->activity, $w->ben);

        $response = $this->roster($w, $w->token);
        $this->assertSame(200, $response->get_code());
        $raw = $response->get_body();
        $members = json_decode($raw)->members;
        $this->assertEqualsCanonicalizing([$annasub, $bensub], array_map(fn($m) => $m->user_id, $members));
        foreach ([$w->anna, $w->ben] as $user) {
            $this->assertStringNotContainsString('"user_id":' . $user->id . ',', $raw);
            $this->assertStringNotContainsString('"user_id":"' . $user->id . '"', $raw);
        }
    }

    /**
     * The disclosure policy applies to the roster: removed, not emptied. Control: without policy everything is there.
     */
    public function test_roster_applies_the_disclosure_policy(): void {
        $w = $this->world(['pseudotype' => 'random_persisted', 'scopetype' => 'deployment', 'discname' => 'blank',
            'discemail' => 'remove', 'discusername' => 'blank', 'discsourcedid' => 'blank']);
        $raw = $this->roster($w, $w->token)->get_body();
        $absent = ['Anna', 'Alpha', 'anna@example.com', 'ID-ANNA', $w->anna->username, '"name"', '"given_name"', '"family_name"',
            '"email"', '"ext_user_username"', '"lis_person_sourcedid"'];
        foreach ($absent as $needle) {
            $this->assertStringNotContainsString($needle, $raw, $needle);
        }
        $members = json_decode($raw)->members;
        $this->assertCount(2, $members);
        $this->assertSame(['status', 'roles', 'user_id'], array_keys((array) $members[0]));
    }

    /**
     * With only the pseudonymising policy (no disclosure restriction) names stay: pseudonymisation is not disclosure.
     */
    public function test_roster_keeps_what_the_policy_passes(): void {
        $w = $this->world();
        $members = json_decode($this->roster($w, $w->token)->get_body())->members;
        $names = array_map(fn($m) => $m->name, $members);
        $this->assertEqualsCanonicalizing(['Anna Alpha', 'Ben Beta'], $names);
        $this->assertSame('anna@example.com', $members[0]->email ?? $members[1]->email);
    }

    /**
     * The roster of one activity (rlid) has exactly the sub of the launch of that activity in the cm scope.
     */
    public function test_roster_with_activity_scope_needs_and_uses_rlid(): void {
        $w = $this->world(['pseudotype' => 'random_persisted', 'scopetype' => 'cm']);
        $annasub = $this->launched_sub($w->activity, $w->anna);

        $missing = $this->roster($w, $w->token);
        $this->assertSame(400, $missing->get_code(), 'cm scope without rlid is ambiguous');
        $this->assertSame('', $missing->get_body());
        $this->assertStringContainsString('rlid', $missing->get_reason());

        $response = $this->roster($w, $w->token, ['rlid' => $w->activity->id]);
        $this->assertSame(200, $response->get_code());
        $this->assertContains($annasub, array_map(fn($m) => $m->user_id, json_decode($response->get_body())->members));

        // The same pseudonym is not the one of another activity of the same tool.
        $second = testing\fixture::create_activity($w->course, $w->typeid);
        $response = $this->roster($w, $w->token, ['rlid' => $second->id]);
        $this->assertNotContains($annasub, array_map(fn($m) => $m->user_id, json_decode($response->get_body())->members));
    }

    /**
     * Pagination links and the resource id of the answer point to the proxy, with the activity kept.
     */
    public function test_roster_links_point_to_the_proxy(): void {
        $w = $this->world();
        $response = $this->roster($w, $w->token, ['limit' => 1, 'rlid' => $w->activity->id]);
        $this->assertSame(200, $response->get_code());
        $body = json_decode($response->get_body());
        $proxy = (new \moodle_url('/mod/lti/source/ltipseudonym/service.php'))->out(false);
        $this->assertStringStartsWith($proxy . '/', $body->id);
        $this->assertCount(1, $body->members);
        $rc = new \ReflectionProperty($response, 'additionalheaders');
        $rc->setAccessible(true);
        $link = implode(' ', $rc->getValue($response));
        $this->assertStringContainsString($proxy . '/', $link);
        $this->assertStringContainsString('rlid=' . $w->activity->id, $link);
        $this->assertStringNotContainsString('/mod/lti/services.php', $link);
    }

    /**
     * Control: without a pseudonymising policy the proxy answers like core (local ids), disclosure-only policies apply.
     */
    public function test_roster_without_pseudonymisation_is_the_roster_of_core(): void {
        $w = $this->world([]);
        $members = json_decode($this->roster($w, $w->token)->get_body())->members;
        $this->assertEqualsCanonicalizing(
            [(string) $w->anna->id, (string) $w->ben->id],
            array_map(fn($m) => (string) $m->user_id, $members)
        );
        testing\fixture::set_policy(['typeid' => $w->typeid, 'discemail' => 'blank']);
        $raw = $this->roster($w, $w->token)->get_body();
        $this->assertStringNotContainsString('anna@example.com', $raw);
        $this->assertStringContainsString('Anna Alpha', $raw);
        $this->assertContains((string) $w->anna->id, array_map(fn($m) => (string) $m->user_id, json_decode($raw)->members));
    }

    /**
     * The LTI 1.1 basic outcome of a member carries the local id: removed unless the policy passes it.
     */
    public function test_roster_removes_the_basic_outcome_unless_passed(): void {
        $w = $this->world();
        $bo = 'https://purl.imsglobal.org/spec/lti-bo/claim/basicoutcome';
        $response = $this->roster($w, $w->token, ['rlid' => $w->activity->id]);
        $this->assertStringNotContainsString('lis_result_sourcedid', $response->get_body());
        $this->assertStringNotContainsString($bo, $response->get_body());

        global $DB;
        $DB->set_field('ltisource_ltipseudonym_pol', 'discresult', 'pass', ['typeid' => $w->typeid, 'courseid' => 0]);
        $raw = $this->roster($w, $w->token, ['rlid' => $w->activity->id])->get_body();
        $this->assertStringContainsString('lis_result_sourcedid', $raw, 'explicit pass: the documented boundary');
    }

    /**
     * The token of another tool does not open the roster of this tool, and without a token there is no roster.
     */
    public function test_roster_needs_the_token_of_the_tool(): void {
        $w = $this->world();
        // Core builds its 401 with new Exception(null, 401), which PHP 8.3 reports as deprecated. That notice is a quirk
        // of core, not of the proxy: it is collected here and must be nothing but that notice.
        $notices = [];
        set_error_handler(function (int $no, string $message) use (&$notices): bool {
            $notices[] = $message;
            return true;
        }, E_DEPRECATED);
        try {
            $none = $this->roster($w, null);
            $bad = $this->roster($w, 'not-a-token');
            $other = $this->roster($w, $w->othertoken);
        } finally {
            restore_error_handler();
        }
        foreach ($notices as $message) {
            $this->assertStringStartsWith('Exception::__construct()', $message);
        }
        $this->assertSame(401, $none->get_code());
        $this->assertSame(401, $bad->get_code());
        $this->assertSame(401, $other->get_code(), 'token of tool Y on the roster of tool X');
        $this->assertSame('', $other->get_body());
        $this->assertSame(200, $this->roster($w, $w->othertoken, [], $w->othertypeid)->get_code(), 'control: its own roster');
    }

    /**
     * The link roster (older media type) is translated as well.
     */
    public function test_link_roster_in_the_old_format_is_translated(): void {
        $w = $this->world();
        $annasub = $this->launched_sub($w->activity, $w->anna);
        $response = $this->request(
            'GET',
            "/links/{$w->activity->id}/memberships",
            $w->token,
            [],
            '',
            'application/vnd.ims.lis.v2.membershipcontainer+json'
        );
        $this->assertSame(200, $response->get_code());
        $raw = $response->get_body();
        $members = array_map(fn($m) => $m->member, json_decode($raw)->pageOf->membershipSubject->membership);
        $this->assertContains($annasub, array_map(fn($m) => $m->userId, $members));
        $this->assertStringNotContainsString('"userId":"' . $w->anna->id . '"', $raw);
        $this->assertStringNotContainsString('resultSourcedId', $raw);
        $this->assertStringNotContainsString('lis_result_sourcedid', $raw);
    }

    /**
     * Fail closed: when the translation cannot be produced the unfiltered roster is never delivered.
     */
    public function test_roster_fails_closed(): void {
        global $DB;
        $w = $this->world();
        // A pseudonym type that cannot create tokens: the policy row is valid, the secret is unusable.
        $DB->set_field('ltisource_ltipseudonym_pol', 'pseudotype', 'hmac_secret_v1', ['typeid' => $w->typeid]);
        set_config('hmacsecret_v1', 'not-an-encrypted-secret', 'ltisource_ltipseudonym');
        $response = $this->roster($w, $w->token);
        $this->assertSame(500, $response->get_code());
        $this->assertSame('', $response->get_body());
        $this->assertStringNotContainsString('Anna', (string) $response->get_reason());
    }

    // Evidence D: AGS.

    /**
     * A score for the pseudonym lands in the grade of the right user and only there.
     */
    public function test_score_with_pseudonym_grades_the_right_user(): void {
        global $DB;
        $w = $this->world();
        $annasub = $this->launched_sub($w->activity, $w->anna);
        $bensub = $this->launched_sub($w->activity, $w->ben);
        $this->assertNotEquals($annasub, $bensub);

        $response = $this->post_score($w, $w->token, ['userId' => $bensub, 'scoreGiven' => 77]);
        $this->assertSame(200, $response->get_code(), $response->get_reason());
        $grade = $DB->get_record('grade_grades', ['itemid' => $w->item->id, 'userid' => $w->ben->id], '*', MUST_EXIST);
        $this->assertEquals(77, $grade->finalgrade);
        $this->assertEmpty($DB->get_field('grade_grades', 'finalgrade', ['itemid' => $w->item->id, 'userid' => $w->anna->id]));
    }

    /**
     * Unknown pseudonyms, local ids, empty values and the pseudonym of another tool are answered 404 and audited, and grade
     * nobody.
     */
    public function test_score_with_unknown_or_foreign_user_is_refused(): void {
        global $DB;
        $w = $this->world();
        // The pseudonym Anna has with the OTHER tool (deployment scope: tokens differ per tool).
        $foreign = $this->launched_sub($w->otheractivity, $w->anna);
        $this->launched_sub($w->activity, $w->anna);
        $before = $DB->count_records('ltisource_ltipseudonym_aud', ['action' => 'service_pseudonym_miss']);

        foreach (['definitely-not-a-pseudonym', (string) $w->anna->id, $foreign] as $userid) {
            $response = $this->post_score($w, $w->token, ['userId' => $userid]);
            $this->assertSame(404, $response->get_code(), $userid);
            $this->assertStringNotContainsString($userid, $response->get_body() . $response->get_reason(), 'no echo');
        }
        $graded = $DB->count_records_select('grade_grades', 'itemid = :i AND finalgrade IS NOT NULL', ['i' => $w->item->id]);
        $this->assertSame(0, $graded);
        $this->assertSame($before + 3, $DB->count_records('ltisource_ltipseudonym_aud', ['action' => 'service_pseudonym_miss']));
        $audit = $DB->get_records('ltisource_ltipseudonym_aud', ['action' => 'service_pseudonym_miss'], 'id');
        $this->assertStringNotContainsString($foreign, json_encode($audit), 'the audit trail never contains a pseudonym');
        $reasons = array_map(fn($row) => json_decode($row->newvalue)->reason, array_values($audit));
        $this->assertSame(['unknown', 'unknown', 'tool'], $reasons);

        // A malformed userId is a client error.
        $this->assertSame(400, $this->post_score($w, $w->token, ['userId' => ''])->get_code());
        $this->assertSame(400, $this->post_score($w, $w->token, ['userId' => 5])->get_code());
    }

    /**
     * Security: the token of tool X cannot resolve a pseudonym of tool Y, even on the line item of tool Y... and the token
     * of tool Y cannot post on the line item of tool X.
     */
    public function test_a_token_never_resolves_the_pseudonym_of_another_tool(): void {
        global $DB;
        $w = $this->world();
        $annax = $this->launched_sub($w->activity, $w->anna);
        $annay = $this->launched_sub($w->otheractivity, $w->anna);
        $this->assertNotEquals($annax, $annay);

        // Tool Y posts to its own line item with its own pseudonym: fine (control).
        $this->assertSame(200, $this->post_score($w, $w->othertoken, ['userId' => $annay], $w->otheritem)->get_code());
        // Tool Y presents the pseudonym of tool X on its own line item: refused.
        $this->assertSame(404, $this->post_score($w, $w->othertoken, ['userId' => $annax], $w->otheritem)->get_code());
        // Tool Y posts on the line item of tool X: core refuses, nothing changes.
        $before = $DB->count_records('grade_grades', ['itemid' => $w->item->id]);
        $response = $this->post_score($w, $w->othertoken, ['userId' => $annay], $w->item);
        $this->assertGreaterThanOrEqual(400, $response->get_code());
        $this->assertSame($before, $DB->count_records('grade_grades', ['itemid' => $w->item->id]));
    }

    /**
     * Retired pseudonyms (after an identity rotation) are not resolved; the active one is.
     */
    public function test_retired_pseudonym_is_refused(): void {
        global $DB;
        $w = $this->world();
        $old = $this->launched_sub($w->activity, $w->anna);
        // What an identity rotation does: retire the mappings and raise the generation.
        $DB->set_field('ltisource_ltipseudonym_map', 'status', 'retired', ['token' => $old]);
        local\config::bump_generation($w->typeid);
        $this->assertSame(404, $this->post_score($w, $w->token, ['userId' => $old])->get_code());
        $new = $this->launched_sub($w->activity, $w->anna);
        $this->assertNotEquals($old, $new);
        $this->assertSame(200, $this->post_score($w, $w->token, ['userId' => $new])->get_code());
    }

    /**
     * The scope of the policy is enforced: with the activity scope the pseudonym of another activity does not grade.
     */
    public function test_activity_scope_pseudonym_belongs_to_its_activity(): void {
        $w = $this->world(['pseudotype' => 'random_persisted', 'scopetype' => 'cm']);
        $second = testing\fixture::create_activity($w->course, $w->typeid);
        $first = $this->launched_sub($w->activity, $w->anna);
        $other = $this->launched_sub($second, $w->anna);
        $this->assertNotEquals($first, $other);
        $this->assertSame(200, $this->post_score($w, $w->token, ['userId' => $first])->get_code());
        $this->assertSame(404, $this->post_score($w, $w->token, ['userId' => $other])->get_code(), 'second activity');
    }

    /**
     * Error texts of core quote the request; they do not carry the local id to the tool.
     */
    public function test_error_texts_do_not_carry_the_local_id(): void {
        $w = $this->world();
        $sub = $this->launched_sub($w->activity, $w->anna);
        // Invalid score: core answers 400 with the request body in the text.
        $response = $this->post_score($w, $w->token, ['userId' => $sub, 'timestamp' => 'not-a-date']);
        $this->assertSame(400, $response->get_code());
        $this->assertStringContainsString($sub, $response->get_reason(), 'the tool sees its own request');
        $this->assertStringNotContainsString('"userId":"' . $w->anna->id . '"', $response->get_reason());
        // Stale score: core names the user in the text.
        $this->assertSame(200, $this->post_score($w, $w->token, ['userId' => $sub, 'timestamp' => date('c', time() + 120)])
            ->get_code());
        $stale = $this->post_score($w, $w->token, ['userId' => $sub, 'timestamp' => date('c', time() + 30)]);
        $this->assertSame(409, $stale->get_code());
        $this->assertDoesNotMatchRegularExpression('/user ' . $w->anna->id . '\b/', $stale->get_reason());
    }

    /**
     * Results: the user filter takes the pseudonym, the answer carries pseudonyms and no local id.
     */
    public function test_results_round_trip(): void {
        $w = $this->world();
        $annasub = $this->launched_sub($w->activity, $w->anna);
        $bensub = $this->launched_sub($w->activity, $w->ben);
        $this->post_score($w, $w->token, ['userId' => $annasub, 'scoreGiven' => 40]);
        $this->post_score($w, $w->token, ['userId' => $bensub, 'scoreGiven' => 90]);
        $path = "/{$w->course->id}/lineitems/{$w->item->id}/lineitem/results";

        $all = $this->request('GET', $path, $w->token, [], '', self::RESULT);
        $this->assertSame(200, $all->get_code(), $all->get_reason());
        $results = json_decode($all->get_body());
        $this->assertEqualsCanonicalizing([$annasub, $bensub], array_column($results, 'userId'));
        foreach ($results as $result) {
            $this->assertStringContainsString('user_id=' . $result->userId, $result->id);
            $this->assertDoesNotMatchRegularExpression('/user_id=\d+(?:&|$)/', $result->id);
            $this->assertStringContainsString('/source/ltipseudonym/service.php/', $result->scoreOf);
        }
        $byuser = array_column($results, 'resultScore', 'userId');
        $this->assertEquals(40, $byuser[$annasub]);
        $this->assertEquals(90, $byuser[$bensub]);

        // The id of a result is a working query.
        $one = $this->request('GET', $path, $w->token, ['user_id' => $bensub], '', self::RESULT);
        $this->assertSame(200, $one->get_code());
        $this->assertSame([$bensub], array_column(json_decode($one->get_body()), 'userId'));
    }

    /**
     * An unknown pseudonym (or a local id) in the query is an empty result set, not an error and not everybody's results.
     */
    public function test_results_for_an_unknown_user_are_empty(): void {
        $w = $this->world();
        $this->post_score($w, $w->token, ['userId' => $this->launched_sub($w->activity, $w->anna)]);
        $path = "/{$w->course->id}/lineitems/{$w->item->id}/lineitem/results";
        foreach (['unknown-pseudonym', (string) $w->anna->id, '0'] as $userid) {
            $response = $this->request('GET', $path, $w->token, ['user_id' => $userid], '', self::RESULT);
            $this->assertSame(200, $response->get_code());
            $this->assertSame('[]', $response->get_body(), $userid);
        }
        $this->assertSame('0', $_GET['user_id'], 'the superglobal is restored by the resource');
    }

    /**
     * Control: without a pseudonymising policy the score of the local id works as in core and a pseudonym-looking value does
     * not.
     */
    public function test_scores_without_pseudonymisation_are_those_of_core(): void {
        global $DB;
        $w = $this->world([]);
        $this->assertSame(200, $this->post_score($w, $w->token, ['userId' => (string) $w->anna->id])->get_code());
        $this->assertEquals(50, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $w->item->id, 'userid' => $w->anna->id]));
        $results = json_decode($this->request(
            'GET',
            "/{$w->course->id}/lineitems/{$w->item->id}/lineitem/results",
            $w->token,
            [],
            '',
            self::RESULT
        )->get_body());
        $this->assertEquals($w->anna->id, $results[0]->userId);
    }

    // Dispatch and links.

    /**
     * The proxy serves exactly the templates and media types of the core resources it replaces, so a core change shows here.
     */
    public function test_proxy_resources_equal_the_core_resources(): void {
        $core = [];
        foreach (lti_get_services() as $service) {
            if (in_array($service->get_component_id(), ['ltiservice_memberships', 'ltiservice_gradebookservices'])) {
                foreach ($service->get_resources() as $resource) {
                    $core[$resource->get_template()] = [$resource->get_formats(), $resource->get_methods()];
                }
            }
        }
        $proxy = [];
        foreach (dispatcher::services() as $service) {
            foreach ($service->get_resources() as $resource) {
                $proxy[$resource->get_template()] = [$resource->get_formats(), $resource->get_methods()];
            }
        }
        ksort($core);
        ksort($proxy);
        $this->assertNotEmpty($core);
        $this->assertSame($core, $proxy);
    }

    /**
     * Every endpoint of the proxy services is built on the proxy URL.
     */
    public function test_all_endpoints_are_on_the_proxy(): void {
        $proxy = (new \moodle_url('/mod/lti/source/ltipseudonym/service.php'))->out(false);
        $core = (new \moodle_url('/mod/lti/services.php'))->out(false);
        foreach (dispatcher::services() as $service) {
            $this->assertSame($proxy, $service::get_service_path());
        }
        foreach (lti_get_services() as $service) {
            if (in_array($service->get_component_id(), ['ltiservice_memberships', 'ltiservice_gradebookservices'])) {
                $this->assertSame($core, $service::get_service_path(), 'control: core is untouched');
            }
        }
    }

    /**
     * End to end from the launch: the URLs in the signed id token work at the proxy with the bearer token of the tool.
     */
    public function test_urls_of_the_id_token_are_served_by_the_proxy(): void {
        global $COURSE;
        $w = $this->world();
        // Core resolves $LineItem.url from the course module of the request, as view.php/launch.php provide it.
        $COURSE = $w->course;
        $_GET['id'] = $w->activity->cmid;
        $claims = testing\fixture::launch($w->activity, $w->anna)[2];
        unset($_GET['id']);
        $nrps = $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url'];
        $ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'];
        $script = '/mod/lti/source/ltipseudonym/service.php';

        $path = substr($nrps, strpos($nrps, $script) + strlen($script));
        $response = $this->request('GET', $path, $w->token, [], '', self::NRPS);
        $this->assertSame(200, $response->get_code(), $nrps);
        $this->assertContains($claims['sub'], array_map(fn($m) => $m->user_id, json_decode($response->get_body())->members));

        $this->assertStringContainsString($script, $ags['lineitems'] ?? '', json_encode($ags));
        $this->assertStringContainsString($script, $ags['lineitem'] ?? '', json_encode($ags));
        $parts = parse_url($ags['lineitem']);
        parse_str($parts['query'] ?? '', $query);
        $path = substr($parts['path'], strpos($parts['path'], $script) + strlen($script));
        $response = $this->request('POST', $path . '/scores', $w->token, $query, json_encode([
            'userId' => $claims['sub'], 'timestamp' => date('c', time() + 60), 'scoreGiven' => 61, 'scoreMaximum' => 100,
            'gradingProgress' => 'FullyGraded', 'activityProgress' => 'Completed',
        ]), self::SCORE);
        $this->assertSame(200, $response->get_code(), $response->get_reason());
        global $DB;
        $this->assertEquals(61, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $w->item->id, 'userid' => $w->anna->id]));
    }

    /**
     * A disclosure-only policy (no pseudonymisation) also moves the service URLs to the proxy: the sub stays the local
     * id, the roster applies the disclosure policy and keeps the local user id (finding SP-02, disclosure-only case).
     */
    public function test_disclosure_only_policy_routes_the_services_through_the_proxy(): void {
        $w = $this->world(['pseudotype' => 'none', 'discemail' => 'blank', 'discname' => 'blank']);
        $claims = testing\fixture::launch($w->activity, $w->anna)[2];
        $this->assertSame((string) $w->anna->id, (string) $claims['sub'], 'no pseudonymisation: the sub is the local id');
        $script = '/mod/lti/source/ltipseudonym/service.php';
        $nrps = $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url'];
        $ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'];
        $this->assertStringContainsString($script, $nrps);
        $this->assertStringContainsString($script, $ags['lineitems']);
        $this->assertStringNotContainsString('/mod/lti/services.php', $nrps . $ags['lineitems']);

        $path = substr($nrps, strpos($nrps, $script) + strlen($script));
        $response = $this->request('GET', $path, $w->token, [], '', self::NRPS);
        $this->assertSame(200, $response->get_code(), $nrps);
        $raw = $response->get_body();
        $this->assertStringNotContainsString('anna@example.com', $raw);
        $this->assertStringNotContainsString('Anna', $raw);
        $this->assertEqualsCanonicalizing(
            [(string) $w->anna->id, (string) $w->ben->id],
            array_map(fn($m) => (string) $m->user_id, json_decode($raw)->members),
            'the user ids stay the local ids, as the sub of the launch'
        );
    }

    /**
     * Control: a policy that changes nothing (no pseudonymisation, every disclosure value pass) leaves the URLs to core,
     * and so does a tool without any policy.
     */
    public function test_a_policy_that_changes_nothing_keeps_the_urls_of_core(): void {
        $w = $this->world(['pseudotype' => 'none', 'discemail' => 'pass', 'discname' => 'pass']);
        $core = '/mod/lti/services.php';
        $claims = testing\fixture::launch($w->activity, $w->anna)[2];
        $nrps = $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url'];
        $this->assertStringContainsString($core, $nrps);
        $this->assertStringNotContainsString('ltipseudonym', $nrps);
        $this->assertStringContainsString($core, $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint']['lineitems']);

        $none = $this->world([]);
        $claims = testing\fixture::launch($none->activity, $none->anna)[2];
        $nrps = $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url'];
        $this->assertStringContainsString($core, $nrps);
        $this->assertStringNotContainsString('ltipseudonym', $nrps);
    }

    /**
     * Requests the proxy has no resource for are answered 400 like core, wrong methods 405.
     */
    public function test_unknown_paths_and_methods(): void {
        $w = $this->world();
        $this->assertSame(400, $this->request('GET', '/nothing/here', $w->token, [], '', self::NRPS)->get_code());
        $this->assertSame(400, $this->request('GET', '/profile/something', $w->token)->get_code());
        $path = "/CourseSection/{$w->course->id}/bindings/{$w->typeid}/memberships";
        $response = $this->request('POST', $path, $w->token, [], '{}', self::NRPS);
        $this->assertSame(405, $response->get_code());
    }
}
