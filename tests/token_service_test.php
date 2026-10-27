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
use ltisource_ltipseudonym\local\token_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Tests of the token service: stability, separation, atomicity and non-reconstructibility.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\token_service
 */
final class token_service_test extends \advanced_testcase {
    /**
     * Random tokens carry at least 128 bit of entropy.
     */
    public function test_random_token_has_at_least_128_bit(): void {
        $token = token_service::random_token();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        $binary = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4), true);
        $this->assertNotFalse($binary);
        $this->assertGreaterThanOrEqual(16, strlen($binary));
        $this->assertNotSame($token, token_service::random_token());
    }

    /**
     * The same user, tool and scope yield the identical token on 120 calls and exactly one mapping.
     *
     * @dataProvider type_provider
     * @param string $ptype
     */
    public function test_token_is_stable_over_many_calls(string $ptype): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $first = $service->get_or_create($user->id, 7, 'deployment', 0, $ptype);
        for ($i = 0; $i < 120; $i++) {
            $this->assertSame($first, $service->get_or_create($user->id, 7, 'deployment', 0, $ptype));
        }
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map', ['userid' => $user->id]));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function type_provider(): array {
        return [
            'random' => [catalogue::TYPE_RANDOM],
            'hmac' => [catalogue::TYPE_HMAC],
        ];
    }

    /**
     * Different users, tools, scopes, courses and activities get different tokens.
     *
     * @dataProvider type_provider
     * @param string $ptype
     */
    public function test_tokens_are_separated_by_user_tool_and_scope(string $ptype): void {
        $this->resetAfterTest();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $tokens = [
            'a/tool7/deployment' => $service->get_or_create($a->id, 7, 'deployment', 0, $ptype),
            'b/tool7/deployment' => $service->get_or_create($b->id, 7, 'deployment', 0, $ptype),
            'a/tool8/deployment' => $service->get_or_create($a->id, 8, 'deployment', 0, $ptype),
            'a/tool7/course3' => $service->get_or_create($a->id, 7, 'course', 3, $ptype),
            'a/tool7/course4' => $service->get_or_create($a->id, 7, 'course', 4, $ptype),
            'a/tool7/cm10' => $service->get_or_create($a->id, 7, 'cm', 10, $ptype),
            'a/tool7/cm11' => $service->get_or_create($a->id, 7, 'cm', 11, $ptype),
            'a/site' => $service->get_or_create($a->id, 0, 'site', 0, $ptype),
            'b/site' => $service->get_or_create($b->id, 0, 'site', 0, $ptype),
        ];
        $this->assertCount(count($tokens), array_unique($tokens), 'every scope has its own token');
        foreach ($tokens as $label => $token) {
            $this->assertNotSame((string) $a->id, $token, $label);
            $this->assertNotSame((string) $b->id, $token, $label);
        }
        // The site scope is the one deliberate exception: one identity per user across tools.
        $this->assertSame($tokens['a/site'], $service->get_or_create($a->id, 0, 'site', 0, $ptype));
    }

    /**
     * Switching the type and back returns the original token: a policy rollback restores the identity.
     */
    public function test_type_switch_is_reversible(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $random = $service->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_RANDOM);
        $hmac = $service->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_HMAC);
        $this->assertNotSame($random, $hmac);
        $this->assertSame($random, $service->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_RANDOM));
    }

    /**
     * A user id of zero and a non-pseudonymising type are refused.
     */
    public function test_invalid_requests_are_refused(): void {
        $this->resetAfterTest();
        $service = new token_service();
        try {
            $service->get_or_create(0, 7, 'deployment', 0, catalogue::TYPE_RANDOM);
            $this->fail('user 0 must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('launchrefused', $e->errorcode);
        }
        $this->expectException(\coding_exception::class);
        $service->get_or_create(2, 7, 'deployment', 0, catalogue::TYPE_LEGACY_USERID);
    }

    /**
     * Scopes that cannot be evaluated fail instead of falling back to a wider scope.
     */
    public function test_scope_coordinates_fail_closed(): void {
        $this->assertSame([7, 'course', 3], token_service::scope_coordinates('course', 7, 3, 10));
        $this->assertSame([7, 'cm', 10], token_service::scope_coordinates('cm', 7, 3, 10));
        $this->assertSame([0, 'site', 0], token_service::scope_coordinates('site', 7, 3, 10));
        $this->assertSame([7, 'deployment', 0], token_service::scope_coordinates('deployment', 7, 3, 10));
        foreach ([['cm', 7, 3, 0], ['course', 7, 0, 0], ['galaxy', 7, 3, 4]] as [$scope, $type, $course, $cm]) {
            try {
                token_service::scope_coordinates($scope, $type, $course, $cm);
                $this->fail("scope $scope must fail");
            } catch (\moodle_exception $e) {
                $this->assertSame('launchrefused', $e->errorcode);
            }
        }
    }

    /**
     * Two first launches at the same moment create exactly one mapping.
     *
     * The competing request is simulated at the worst moment: after our read found nothing and
     * before our insert. The unique index rejects our insert, the service re-reads and returns the
     * winner's token.
     *
     * @dataProvider type_provider
     * @param string $ptype
     */
    public function test_concurrent_first_launch_creates_exactly_one_mapping(string $ptype): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $service = new class extends token_service {
            /** @var string Token written by the simulated competing request. */
            public $winner = 'TOKEN-OF-THE-COMPETING-REQUEST';

            /**
             * Insert the competitor's row just before our insert.
             *
             * @param array $key
             */
            protected function before_insert(array $key): void {
                global $DB;
                $DB->insert_record('ltisource_ltipseudonym_map', (object) ($key + [
                    'token' => $this->winner, 'status' => 'active', 'timecreated' => time(), 'timelastused' => time(),
                ]));
            }
        };
        $token = $service->get_or_create($user->id, 7, 'deployment', 0, $ptype);
        $this->assertSame($service->winner, $token, 'the loser returns the winner\'s token');
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map', ['userid' => $user->id]));
        // A later call without any race keeps returning the same token.
        $this->assertSame($service->winner, (new token_service())->get_or_create($user->id, 7, 'deployment', 0, $ptype));
    }

    /**
     * The database itself rejects a second mapping for the same identity and a reused token.
     */
    public function test_unique_indexes_hold_without_the_service(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $row = (object) [
            'userid' => $user->id, 'typeid' => 7, 'scopetype' => 'deployment', 'scopeid' => 0, 'ptype' => 'random_persisted',
            'token' => 'AAAA', 'version' => 1, 'status' => 'active', 'timecreated' => time(), 'timelastused' => time(),
        ];
        $DB->insert_record('ltisource_ltipseudonym_map', $row);
        $duplicate = clone $row;
        $duplicate->token = 'BBBB';
        try {
            $DB->insert_record('ltisource_ltipseudonym_map', $duplicate);
            $this->fail('identity must be unique');
        } catch (\dml_exception $e) {
            $this->assertInstanceOf(\dml_exception::class, $e);
        }
        $other = $this->getDataGenerator()->create_user();
        $reuse = clone $row;
        $reuse->userid = $other->id;
        try {
            $DB->insert_record('ltisource_ltipseudonym_map', $reuse);
            $this->fail('token must be unique');
        } catch (\dml_exception $e) {
            $this->assertInstanceOf(\dml_exception::class, $e);
        }
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));
    }

    /**
     * The HMAC token can be reproduced only with the secret key and the exact domain.
     */
    public function test_hmac_requires_the_secret_and_the_domain(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $typeid = testing\fixture::create_type();
        $clientid = $DB->get_field('lti_types', 'clientid', ['id' => $typeid]);
        $user = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $token = $service->get_or_create($user->id, $typeid, 'course', 5, catalogue::TYPE_HMAC);

        $secret = $service->hmac_secret();
        $this->assertGreaterThanOrEqual(32, strlen($secret));
        $reproduced = token_service::derive_hmac($secret, 1, $CFG->wwwroot, $clientid, $typeid, 'course', 5, $user->id);
        $this->assertSame($token, $reproduced, 'with key and domain the token is reproducible');

        // Without the key nothing known about the user helps.
        $guesses = [
            hash('sha256', (string) $user->id),
            hash('sha256', $user->id . $user->timecreated),
            hash('sha256', $CFG->wwwroot . $user->id),
            hash('sha256', $user->username),
            md5((string) $user->id),
            token_service::derive_hmac(
                'wrong-key-wrong-key-wrong-key-123',
                1,
                $CFG->wwwroot,
                $clientid,
                $typeid,
                'course',
                5,
                $user->id
            ),
        ];
        foreach ($guesses as $guess) {
            $this->assertNotSame($token, $guess);
            // The same guess in the encoding the token uses.
            $binary = ctype_xdigit($guess) ? hex2bin($guess) : $guess;
            $this->assertNotSame($token, rtrim(strtr(base64_encode($binary), '+/', '-_'), '='));
        }

        // Each domain component separates.
        $variants = [
            [2, $CFG->wwwroot, $clientid, $typeid, 'course', 5, $user->id],
            [1, 'https://other.example.com', $clientid, $typeid, 'course', 5, $user->id],
            [1, $CFG->wwwroot, 'other-client', $typeid, 'course', 5, $user->id],
            [1, $CFG->wwwroot, $clientid, $typeid + 1, 'course', 5, $user->id],
            [1, $CFG->wwwroot, $clientid, $typeid, 'cm', 5, $user->id],
            [1, $CFG->wwwroot, $clientid, $typeid, 'course', 6, $user->id],
            [1, $CFG->wwwroot, $clientid, $typeid, 'course', 5, $user->id + 1],
        ];
        foreach ($variants as $variant) {
            $this->assertNotSame($token, token_service::derive_hmac($secret, ...$variant));
        }
    }

    /**
     * The HMAC secret is generated once, stored encrypted and never appears in plain text.
     */
    public function test_hmac_secret_is_stored_encrypted(): void {
        $this->resetAfterTest();
        $service = new token_service();
        $secret = $service->hmac_secret();
        $stored = get_config('ltisource_ltipseudonym', 'hmacsecret_v1');
        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString(base64_encode($secret), $stored);
        $this->assertStringNotContainsString(bin2hex($secret), $stored);
        $this->assertStringStartsWith('sodium:', $stored);
        $this->assertSame($secret, (new token_service())->hmac_secret(), 'the secret is stable');
    }

    /**
     * A corrupted secret makes the HMAC type fail instead of using a weak key.
     */
    public function test_unusable_secret_fails(): void {
        $this->resetAfterTest();
        set_config('hmacsecret_v1', 'garbage', 'ltisource_ltipseudonym');
        $user = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        (new token_service())->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_HMAC);
    }

    /**
     * Retrieving a token refreshes the last-used time at most every five minutes.
     */
    public function test_last_used_is_throttled(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $service->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_RANDOM);
        $record = $DB->get_record('ltisource_ltipseudonym_map', ['userid' => $user->id]);
        $DB->set_field('ltisource_ltipseudonym_map', 'timelastused', time() - 100, ['id' => $record->id]);
        $service->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_RANDOM);
        $this->assertEqualsWithDelta(time() - 100, $DB->get_field(
            'ltisource_ltipseudonym_map',
            'timelastused',
            ['id' => $record->id]
        ), 2, 'not refreshed within five minutes');
        $DB->set_field('ltisource_ltipseudonym_map', 'timelastused', time() - 1000, ['id' => $record->id]);
        $service->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_RANDOM);
        $this->assertEqualsWithDelta(time(), $DB->get_field(
            'ltisource_ltipseudonym_map',
            'timelastused',
            ['id' => $record->id]
        ), 2, 'refreshed after five minutes');
    }

    /**
     * Reverse lookup works for diagnostics.
     */
    public function test_lookup(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = (new token_service())->get_or_create($user->id, 7, 'deployment', 0, catalogue::TYPE_RANDOM);
        $this->assertSame((int) $user->id, (int) token_service::lookup($token)->userid);
        $this->assertNull(token_service::lookup('does-not-exist'));
    }
}
