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

use ltisource_ltipseudonym\local\merge_integration;
use ltisource_ltipseudonym\local\migration_service;
use ltisource_ltipseudonym\local\proxy\refusal;
use ltisource_ltipseudonym\local\proxy\translator;
use ltisource_ltipseudonym\testing\fixture;
use ltisource_ltipseudonym\testing\service_client as client;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');
require_once(__DIR__ . '/fixtures/service_client.php');
require_once(__DIR__ . '/fixtures/after_merge_hook_standin.php');

/**
 * Finding SP-03: negative security protocol of the service proxy, the request guard and the translator.
 *
 * Every case is an attack or a mismatch a tool (or anybody holding its access token) can try. Each case records the answer
 * and checks the two invariants of the plugin: no grade of a local user changes, and no answer carries user data (names,
 * e-mail, local ids, pseudonyms) that the request was not entitled to. Positive controls show that the legitimate request
 * still works. The protocol is compared with tests/fixtures/security_protocol.json; with LTIPSEUDONYM_TRACE_OUT=<dir> it is
 * written there (security_protocol.json, SECURITY_PROTOCOL.md) instead.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\proxy\translator
 * @covers     \ltisource_ltipseudonym\local\proxy\dispatcher
 * @covers     \ltisource_ltipseudonym\local\request_guard
 * @covers     \ltisource_ltipseudonym\local\services\resources\contextmemberships
 * @covers     \ltisource_ltipseudonym\local\services\resources\linkmemberships
 * @covers     \ltisource_ltipseudonym\local\services\resources\scores
 * @covers     \ltisource_ltipseudonym\local\services\resources\results
 */
final class security_protocol_test extends \advanced_testcase {
    /** @var array Saved superglobals. */
    private $saved = [];
    /** @var \stdClass World. */
    private $w;
    /** @var array[] Recorded cases. */
    private $cases = [];

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
     * Two courses, three learners, two site tools and one course tool with AGS and NRPS; every tool pseudonymises with
     * course scope and blanks names and e-mail.
     *
     * @return \stdClass
     */
    private function world(): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $gen = $this->getDataGenerator();
        for ($i = 0; $i < 40; $i++) {
            $gen->create_user();
        }
        $w = new \stdClass();
        $w->c1 = $gen->create_course();
        $w->c2 = $gen->create_course();
        $w->anna = $gen->create_user(['firstname' => 'Anna', 'lastname' => 'Alpha', 'email' => 'anna@example.com']);
        $w->ben = $gen->create_user(['firstname' => 'Ben', 'lastname' => 'Beta', 'email' => 'ben@example.com']);
        $w->cara = $gen->create_user(['firstname' => 'Cara', 'lastname' => 'Gamma', 'email' => 'cara@example.com']);
        foreach ([$w->anna, $w->ben] as $user) {
            $gen->enrol_user($user->id, $w->c1->id, 'student');
            $gen->enrol_user($user->id, $w->c2->id, 'student');
        }
        $gen->enrol_user($w->cara->id, $w->c1->id, 'student');
        $services = ['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1];
        $w->t = fixture::create_type($services);
        $w->u = fixture::create_type($services);
        $w->v = fixture::create_type($services, ['course' => $w->c2->id]);
        $grades = ['instructorchoiceacceptgrades' => 1, 'grade' => 100];
        $w->ta = fixture::create_activity($w->c1, $w->t, $grades);
        $w->ta2 = fixture::create_activity($w->c1, $w->t, $grades);
        $w->ua = fixture::create_activity($w->c1, $w->u, $grades);
        $w->tb = fixture::create_activity($w->c2, $w->t, $grades);
        foreach (['ta', 'ta2', 'ua', 'tb'] as $key) {
            $w->{'item' . $key} = $this->grade_item($w->$key);
        }
        foreach ([$w->t, $w->u, $w->v] as $typeid) {
            fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'course',
                'discname' => 'blank', 'discemail' => 'blank', 'allowoverride' => 1]);
        }
        $w->token = client::access_token($w->t);
        $w->utoken = client::access_token($w->u);
        $w->vtoken = client::access_token($w->v);
        return $w;
    }

    /**
     * The grade item of an activity.
     *
     * @param \stdClass $activity
     * @return \grade_item
     */
    private function grade_item(\stdClass $activity): \grade_item {
        $item = \grade_item::fetch(['courseid' => $activity->course, 'itemtype' => 'mod', 'itemmodule' => 'lti',
            'iteminstance' => $activity->id]);
        if (!$item) {
            $item = new \grade_item(['courseid' => $activity->course, 'itemtype' => 'mod', 'itemmodule' => 'lti',
                'iteminstance' => $activity->id, 'itemnumber' => 0, 'itemname' => 'lti', 'grademax' => 100], false);
            $item->insert();
        }
        return $item;
    }

    /**
     * The sub of a real launch.
     *
     * @param \stdClass $activity
     * @param \stdClass $user
     * @return string
     */
    private function sub(\stdClass $activity, \stdClass $user): string {
        return (string) fixture::launch($activity, $user)[2]['sub'];
    }

    /**
     * Fingerprint of every grade (to prove that nothing changed).
     *
     * @return string
     */
    private function grades(): string {
        global $DB;
        return json_encode(array_values(array_map(
            fn($g) => [$g->itemid, $g->userid, $g->finalgrade],
            $DB->get_records('grade_grades', null, 'id', 'id, itemid, userid, finalgrade')
        )));
    }

    /**
     * What an answer body carries.
     *
     * @param string $body
     * @return string
     */
    private function body_class(string $body): string {
        global $DB;
        if ($body === '' || $body === '[]') {
            return $body === '' ? 'empty' : 'empty list';
        }
        $found = [];
        $w = $this->w;
        foreach ([$w->anna, $w->ben, $w->cara] as $user) {
            if (
                preg_match('/"(user_id|userId)":"?' . $user->id . '"?[,}]/', $body) ||
                    preg_match('/user_id=' . $user->id . '\b/', $body)
            ) {
                $found['local ids'] = true;
            }
            foreach ([$user->firstname, $user->lastname, $user->email] as $value) {
                if (strpos($body, $value) !== false) {
                    $found['names or e-mail'] = true;
                }
            }
        }
        foreach ($DB->get_fieldset_select('ltisource_ltipseudonym_map', 'token', '1 = 1') as $token) {
            if (strpos($body, $token) !== false) {
                $found['pseudonyms'] = true;
            }
        }
        return $found ? implode(', ', array_keys($found)) : 'no user data';
    }

    /**
     * Run one case and record it.
     *
     * @param string $id short id
     * @param string $attack what is tried
     * @param string $expected expected outcome
     * @param callable $run returns a response or a string outcome
     * @param bool $control true for a positive control (the request is legitimate)
     * @param string $allowed body classes that are allowed for this case
     */
    private function attempt(
        string $id,
        string $attack,
        string $expected,
        callable $run,
        bool $control = false,
        string $allowed = ''
    ): void {
        $before = $this->grades();
        $result = $run();
        $after = $this->grades();
        $case = ['id' => $id, 'attack' => $attack, 'expected' => $expected, 'control' => $control];
        if ($result instanceof \mod_lti\local\ltiservice\response) {
            $case['answered_by'] = client::$answeredby;
            $case['code'] = $result->get_code();
            $case['body'] = $this->body_class((string) $result->get_body());
        } else {
            $case['outcome'] = (string) $result;
        }
        $case['grades'] = ($before === $after) ? 'unchanged' : 'changed';
        $this->cases[] = $case;
        if (!$control) {
            $this->assertSame('unchanged', $case['grades'], $id . ': a refused request changed a grade');
            if (isset($case['body'])) {
                $this->assertContains($case['body'], array_merge(
                    ['empty', 'empty list', 'no user data'],
                    $allowed === '' ? [] : [$allowed]
                ), $id . ': the answer carries user data');
                if ($case['body'] !== 'empty list') {
                    $this->assertGreaterThanOrEqual(400, $case['code'], $id . ': must be refused');
                }
            } else {
                $this->assertStringStartsWith('refused', $case['outcome'], $id . ': must be refused');
            }
        }
    }

    /**
     * URL of the roster of a course for a tool, at the proxy or at core.
     *
     * @param string $endpoint proxy or core
     * @param int $courseid
     * @param int $typeid
     * @return string
     */
    private function roster_url(string $endpoint, int $courseid, int $typeid): string {
        $script = $endpoint === 'proxy' ? client::PROXY_SCRIPT : client::CORE_SCRIPT;
        return (new \moodle_url($script))->out(false) . "/CourseSection/{$courseid}/bindings/{$typeid}/memberships";
    }

    /**
     * URL of the scores of a line item.
     *
     * @param string $endpoint proxy or core
     * @param \grade_item $item
     * @param int $typeid
     * @return string
     */
    private function scores_url(string $endpoint, \grade_item $item, int $typeid): string {
        $script = $endpoint === 'proxy' ? client::PROXY_SCRIPT : client::CORE_SCRIPT;
        return (new \moodle_url($script))->out(false) .
            "/{$item->courseid}/lineitems/{$item->id}/lineitem/scores?type_id={$typeid}";
    }

    /**
     * The protocol: every case, then the comparison with the committed protocol (or its generation).
     */
    public function test_security_protocol(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectEvents();
        $this->w = $w = $this->world();
        $anna1 = $this->sub($w->ta, $w->anna);
        $ben1 = $this->sub($w->ta, $w->ben);
        $anna2 = $this->sub($w->tb, $w->anna);
        $annau = $this->sub($w->ua, $w->anna);

        $this->attempt(
            'C1',
            'legitimate score: the pseudonym of the launch, the line item of the launch, the token of the tool',
            'stored for the right person',
            function () use ($w, $anna1, $DB) {
                $r = client::call(
                    'POST',
                    $this->scores_url('proxy', $w->itemta, $w->t),
                    $w->token,
                    [],
                    client::score($anna1, 70),
                    client::SCORE
                );
                return $r->get_code() . ', grade of the launching person: ' . ((float) $DB->get_field(
                    'grade_grades',
                    'finalgrade',
                    ['itemid' => $w->itemta->id, 'userid' => $w->anna->id]
                ) === 70.0 ? 'stored' : 'missing');
            },
            true
        );
        $this->attempt(
            'C2',
            'legitimate roster of the tool in its course',
            'pseudonyms only, no names or e-mail',
            fn() => client::call('GET', $this->roster_url('proxy', $w->c1->id, $w->t), $w->token, [], '', client::NRPS),
            true
        );

        $this->attempt(
            'S01',
            'score with the pseudonym of the same person issued to ANOTHER tool (foreign token)',
            '404, nothing stored, audit reason "tool"',
            fn() => client::call(
                'POST',
                $this->scores_url('proxy', $w->itemta, $w->t),
                $w->token,
                [],
                client::score($annau, 11),
                client::SCORE
            )
        );
        $this->attempt(
            'S02',
            'score with the pseudonym of the same tool for ANOTHER course (scope mismatch)',
            '404, nothing stored, audit reason "scope"',
            fn() => client::call(
                'POST',
                $this->scores_url('proxy', $w->itemta, $w->t),
                $w->token,
                [],
                client::score($anna2, 12),
                client::SCORE
            )
        );
        $this->attempt(
            'S03',
            'score with a guessed LOCAL user id at the proxy URL',
            '404, nothing stored',
            fn() => client::call(
                'POST',
                $this->scores_url('proxy', $w->itemta, $w->t),
                $w->token,
                [],
                client::score((string) $w->ben->id, 13),
                client::SCORE
            )
        );
        $this->attempt(
            'S04',
            'score with a guessed LOCAL user id at the CORE URL (bypass of the proxy)',
            'request guard answers with the proxy: 404, nothing stored',
            fn() => client::call(
                'POST',
                $this->scores_url('core', $w->itemta, $w->t),
                $w->token,
                [],
                client::score((string) $w->ben->id, 14),
                client::SCORE
            )
        );
        $this->attempt(
            'S05',
            'roster at the CORE URL (bypass of the proxy and of the disclosure policy)',
            'request guard answers with the proxy: pseudonyms only, no names or e-mail',
            fn() => client::call('GET', $this->roster_url('core', $w->c1->id, $w->t), $w->token, [], '', client::NRPS),
            true
        );
        $this->attempt(
            'S06',
            'roster with rlid of an activity of ANOTHER tool in the same course',
            '404, empty body',
            fn() => client::call(
                'GET',
                $this->roster_url('proxy', $w->c1->id, $w->t),
                $w->token,
                ['rlid' => $w->ua->id],
                '',
                client::NRPS
            )
        );
        $this->attempt(
            'S07',
            'roster of course 1 with rlid of an activity in ANOTHER course',
            '404, empty body',
            fn() => client::call(
                'GET',
                $this->roster_url('proxy', $w->c1->id, $w->t),
                $w->token,
                ['rlid' => $w->tb->id],
                '',
                client::NRPS
            )
        );
        $this->attempt(
            'S08',
            'link roster of an activity of ANOTHER tool, at the proxy',
            'refused by core authentication (the link belongs to the other tool), empty body',
            fn() => client::call(
                'GET',
                (new \moodle_url(client::PROXY_SCRIPT))->out(false) . "/links/{$w->ua->id}/memberships",
                $w->token,
                [],
                '',
                client::LINK_MEMBERSHIPS
            )
        );
        $this->attempt(
            'S09',
            'link roster of an activity of ANOTHER tool, at the core URL',
            'refused (guard routes to the proxy, core authentication refuses)',
            fn() => client::call(
                'GET',
                (new \moodle_url(client::CORE_SCRIPT))->out(false) . "/links/{$w->ua->id}/memberships",
                $w->token,
                [],
                '',
                client::LINK_MEMBERSHIPS
            )
        );
        $this->attempt(
            'S10',
            'score on the line item of ANOTHER tool with a valid pseudonym of this tool',
            'refused, nothing stored',
            fn() => client::call(
                'POST',
                $this->scores_url('proxy', $w->itemua, $w->t),
                $w->token,
                [],
                client::score($anna1, 15),
                client::SCORE
            )
        );
        $this->attempt(
            'S11',
            'roster with the token of this tool for the path of ANOTHER tool',
            '401',
            fn() => client::call('GET', $this->roster_url('proxy', $w->c1->id, $w->u), $w->token, [], '', client::NRPS)
        );
        $this->attempt('S12', 'expired access token', '401', fn() => client::call(
            'GET',
            $this->roster_url('proxy', $w->c1->id, $w->t),
            client::access_token($w->t, null, -10),
            [],
            '',
            client::NRPS
        ));
        $this->attempt('S13', 'access token without the score scope', '401', fn() => client::call(
            'POST',
            $this->scores_url('proxy', $w->itemta, $w->t),
            client::access_token($w->t, [client::NRPS_SCOPE]),
            [],
            client::score($anna1, 16),
            client::SCORE
        ));
        $this->attempt('S14', 'no access token', '401', fn() => client::call(
            'GET',
            $this->roster_url('proxy', $w->c1->id, $w->t),
            null,
            [],
            '',
            client::NRPS
        ));
        $this->attempt(
            'S15',
            'results filtered by a pseudonym of ANOTHER tool',
            'empty list',
            fn() => client::call(
                'GET',
                str_replace('/scores', '/results', $this->scores_url('proxy', $w->itemta, $w->t)),
                $w->token,
                ['user_id' => $annau],
                '',
                client::RESULT
            )
        );
        $this->attempt(
            'S16',
            'score with userId as a number instead of the pseudonym string',
            '400, nothing stored',
            fn() => client::call(
                'POST',
                $this->scores_url('proxy', $w->itemta, $w->t),
                $w->token,
                [],
                json_encode(['userId' => (int) $w->ben->id] + json_decode(client::score('x', 17), true)),
                client::SCORE
            )
        );
        $this->attempt(
            'S17',
            'course tool of course 2 asks for the roster of course 1',
            'refused (not available in the course)',
            fn() => client::call('GET', $this->roster_url('proxy', $w->c1->id, $w->v), $w->vtoken, [], '', client::NRPS)
        );

        // Activity scope: the roster must name the activity.
        $DB->set_field('ltisource_ltipseudonym_pol', 'scopetype', 'cm', ['typeid' => $w->t]);
        $this->attempt(
            'S18',
            'roster without rlid while the policy of the tool uses the activity scope',
            '400 (pseudonyms would be ambiguous), empty body',
            fn() => client::call('GET', $this->roster_url('proxy', $w->c1->id, $w->t), $w->token, [], '', client::NRPS)
        );
        $this->attempt(
            'S18b',
            'the same at the core URL',
            'request guard answers with the proxy: 400, empty body',
            fn() => client::call('GET', $this->roster_url('core', $w->c1->id, $w->t), $w->token, [], '', client::NRPS)
        );
        $DB->set_field('ltisource_ltipseudonym_pol', 'scopetype', 'course', ['typeid' => $w->t]);

        // Rotation: the old pseudonyms are retired.
        migration_service::rotate($w->t, (int) get_admin()->id);
        $this->attempt(
            'S19',
            'score with a pseudonym retired by a rotation',
            '404, nothing stored, audit reason "retired"',
            fn() => client::call(
                'POST',
                $this->scores_url('proxy', $w->itemta, $w->t),
                $w->token,
                [],
                client::score($ben1, 18),
                client::SCORE
            )
        );
        $this->attempt('S20', 'results filtered by a retired pseudonym', 'empty list', fn() => client::call(
            'GET',
            str_replace('/scores', '/results', $this->scores_url('proxy', $w->itemta, $w->t)),
            $w->token,
            ['user_id' => $anna1],
            '',
            client::RESULT
        ));

        // Account merge with tool_mergeusers: Ben (merged away) into Cara (kept). Cara has her own pseudonym in course 1.
        $u1ben = $this->sub($w->ua, $w->ben);
        $this->sub($w->ua, $w->cara);
        $tb2ben = $this->sub($w->ta2, $w->ben);
        $DB->delete_records('ltisource_ltipseudonym_map', ['userid' => $w->cara->id, 'typeid' => $w->t]);
        $hook = new testing\after_merge_hook_standin((int) $w->cara->id, (int) $w->ben->id);
        merge_integration::after_merged_all_tables($hook);
        $this->attempt(
            'S21',
            'after a merge: score with the merged-away pseudonym where the kept account had its own',
            '404, audit reason "retired"',
            fn() => client::call(
                'POST',
                $this->scores_url('proxy', $w->itemua, $w->u),
                $w->utoken,
                [],
                client::score($u1ben, 19),
                client::SCORE
            )
        );
        $this->attempt(
            'S22',
            'after a merge: score with the moved pseudonym (only the merged-away account had one)',
            'stored for the KEPT account',
            function () use ($w, $tb2ben, $DB) {
                $r = client::call(
                    'POST',
                    $this->scores_url('proxy', $w->itemta2, $w->t),
                    $w->token,
                    [],
                    client::score($tb2ben, 20),
                    client::SCORE
                );
                return $r->get_code() . ', grade of the kept account: ' . ((float) $DB->get_field(
                    'grade_grades',
                    'finalgrade',
                    ['itemid' => $w->itemta2->id, 'userid' => $w->cara->id]
                ) === 20.0 ? 'stored' : 'missing') .
                    ', grade of the merged-away account: ' . ($DB->record_exists('grade_grades', ['itemid' => $w->itemta2->id,
                    'userid' => $w->ben->id]) ? 'stored' : 'none');
            },
            true
        );

        $byid = array_column($this->cases, null, 'id');
        $this->assertSame('200, grade of the launching person: stored', $byid['C1']['outcome']);
        $this->assertSame(['guard', 200, 'pseudonyms'], [$byid['S05']['answered_by'], $byid['S05']['code'],
            $byid['S05']['body']], 'S05: the core URL is answered by the proxy, without names or local ids');
        $this->assertSame('pseudonyms', $byid['C2']['body']);
        $this->assertStringContainsString(
            'grade of the kept account: stored, grade of the merged-away account: none',
            $byid['S22']['outcome']
        );

        // The read-only translator used by local_lticompletionsync.
        $translator = new translator();
        $type = $DB->get_record('lti_types', ['id' => $w->t]);
        $utype = $DB->get_record('lti_types', ['id' => $w->u]);
        $vtype = $DB->get_record('lti_types', ['id' => $w->v]);
        $refused = function (callable $call): string {
            try {
                $call();
                return 'accepted';
            } catch (refusal $e) {
                return 'refused ' . $e->getCode();
            }
        };
        $this->attempt(
            'T01',
            'translator: activity of ANOTHER tool given with this tool',
            'refused 404',
            fn() => $refused(fn() => $translator->context_for($type, (int) $w->c1->id, (int) $w->ua->cmid))
        );
        $this->attempt(
            'T02',
            'translator: activity of another course',
            'refused 404',
            fn() => $refused(fn() => $translator->context_for($type, (int) $w->c1->id, (int) $w->tb->cmid))
        );
        $this->attempt(
            'T03',
            'translator: course tool outside its course',
            'refused 404',
            fn() => $refused(fn() => $translator->context_for($vtype, (int) $w->c1->id, 0))
        );
        $this->attempt(
            'T04',
            'translator: pseudonym of another tool',
            'refused 404',
            fn() => $refused(fn() => $translator->user_of(
                $translator->context_for(
                    $type,
                    (int) $w->c1->id,
                    (int) $w->ta->cmid
                ),
                $annau,
                'lticompletionsync'
            ))
        );
        $this->attempt(
            'T05',
            'translator: retired pseudonym',
            'refused 404',
            fn() => $refused(fn() => $translator->user_of(
                $translator->context_for(
                    $type,
                    (int) $w->c1->id,
                    (int) $w->ta->cmid
                ),
                $anna1,
                'lticompletionsync'
            ))
        );
        $fresh = $this->sub($w->ta, $w->anna);
        $this->attempt(
            'T06',
            'translator: current pseudonym of the right tool, course and activity',
            'the right local user',
            fn() => ($translator->user_of(
                $translator->context_for($type, (int) $w->c1->id, (int) $w->ta->cmid),
                $fresh,
                'lticompletionsync'
            ) === (int) $w->anna->id) ? 'resolved to the launching person' : 'wrong person',
            true
        );
        $this->attempt(
            'T07',
            'translator: reverse lookup of the same person gives the pseudonym of the launch',
            'same pseudonym',
            fn() => ($translator->pseudonym_of(
                $translator->context_for(
                    $type,
                    (int) $w->c1->id,
                    (int) $w->ta->cmid
                ),
                (int) $w->anna->id
            ) === $fresh) ? 'same pseudonym' : 'different',
            true
        );
        $this->attempt(
            'T08',
            'translator: contract version for other plugins',
            'API_VERSION 2',
            fn() => 'API_VERSION ' . translator::API_VERSION,
            true
        );
        unset($utype);

        // No audit row carries a pseudonym or a local user id of a learner.
        $tokens = $DB->get_fieldset_select('ltisource_ltipseudonym_map', 'token', '1 = 1');
        $leak = false;
        foreach ($DB->get_records('ltisource_ltipseudonym_aud') as $row) {
            foreach ($tokens as $token) {
                $leak = $leak || strpos($row->oldvalue . $row->newvalue, $token) !== false;
            }
        }
        $reasons = array_count_values(array_map(
            fn($r) => json_decode($r->newvalue)->reason,
            $DB->get_records('ltisource_ltipseudonym_aud', ['action' => 'service_pseudonym_miss'])
        ));
        ksort($reasons);
        $this->cases[] = ['id' => 'A01', 'attack' => 'audit trail of all misses above', 'expected' =>
            'reasons recorded, no pseudonym in any audit row', 'control' => true,
            'outcome' => 'pseudonym in audit: ' . ($leak ? 'yes' : 'no') . '; miss reasons: ' . json_encode($reasons)];
        $this->assertFalse($leak);

        $this->compare_or_write();
    }

    /**
     * Compare with the committed protocol, or write it.
     */
    private function compare_or_write(): void {
        $protocol = ['format' => 1, 'cases' => $this->cases];
        $out = getenv('LTIPSEUDONYM_TRACE_OUT');
        if ($out) {
            file_put_contents(
                $out . '/security_protocol.json',
                json_encode($protocol, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
            file_put_contents($out . '/SECURITY_PROTOCOL.md', $this->report($protocol));
        }
        $expected = json_decode(file_get_contents(__DIR__ . '/fixtures/security_protocol.json'), true);
        $this->assertNotEmpty($expected, 'committed protocol missing: run with LTIPSEUDONYM_TRACE_OUT');
        $this->assertEquals($expected['cases'], $protocol['cases'], 'the security protocol changed: re-assess and regenerate');
    }

    /**
     * The Markdown protocol.
     *
     * @param array $protocol
     * @return string
     */
    private function report(array $protocol): string {
        global $CFG;
        $md = ['# Negative security protocol', ''];
        $md[] = 'Generated by ' . self::c('tests/security_protocol_test.php') . ' (PHPUnit, Moodle ' . $CFG->release .
            '); do not edit by hand. ' .
            'Machine-readable: ' . self::c('tests/fixtures/security_protocol.json') .
            '. Every request goes through the same code as a web ' .
            'request: the proxy dispatcher (' . self::c('service.php') .
            '), or for a core URL the request guard and then the dispatch of ' .
            self::c('mod/lti/services.php') .
            '. Invariants checked for every non-control case: the request is refused (HTTP >= 400 or ' .
            'an exception of the translator), no grade changes, and the answer carries no user data.';
        $md[] = '';
        $md[] = 'Setup: tools T and U (site tools) and V (course tool of course 2), all with AGS and NRPS and a policy ' .
            self::c('random_persisted') . ', scope ' . self::c('course') . ', names and e-mail ' . self::c('blank') .
            '. Learners Anna and Ben in both courses, Cara in ' .
            'course 1.';
        $md[] = '';
        $md[] = '| Case | Attempt | Expected | Answered by | Result | Body | Grades |';
        $md[] = '|---|---|---|---|---|---|---|';
        foreach ($protocol['cases'] as $c) {
            $md[] = '| ' . $c['id'] . ($c['control'] ? ' (control)' : '') . ' | ' . $c['attack'] . ' | ' . $c['expected'] . ' | ' .
                ($c['answered_by'] ?? '-') . ' | ' . (isset($c['code']) ? 'HTTP ' . $c['code'] : $c['outcome']) . ' | ' .
                ($c['body'] ?? '-') . ' | ' . ($c['grades'] ?? '-') . ' |';
        }
        $md[] = '';
        return implode("\n", $md) . "\n";
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
