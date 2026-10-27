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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Finding: mod_lti merges the callback result with array_merge() and lti_sign_jwt() turns every key
 * present into a claim. An empty string therefore yields an empty claim; the only way to have no
 * claim is for the parameter never to be built by core. This test file pins that behaviour on every
 * Moodle version the plugin supports, so that a change in core is noticed.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class claims_evidence_test extends \advanced_testcase {
    /**
     * Sign a parameter set the way mod_lti does and decode the claims.
     *
     * @param array $params
     * @return array
     */
    private function sign(array $params): array {
        $params += ['lti_message_type' => 'basic-lti-launch-request', 'roles' => 'Learner', 'user_id' => '42'];
        $signed = lti_sign_jwt($params, 'https://tool.example.com/launch', 'client', 7, 'nonce');
        return testing\fixture::decode($signed['id_token']);
    }

    /**
     * An empty string produces an empty claim, not a missing one.
     */
    public function test_empty_string_yields_an_empty_claim(): void {
        $claims = $this->sign(['lis_person_contact_email_primary' => '', 'lis_person_name_given' => '']);
        $this->assertArrayHasKey('email', $claims);
        $this->assertSame('', $claims['email']);
        $this->assertArrayHasKey('given_name', $claims);
        $this->assertSame('', $claims['given_name']);
    }

    /**
     * A parameter that is not passed produces no claim at all.
     */
    public function test_absent_parameter_yields_no_claim(): void {
        $claims = $this->sign([]);
        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('given_name', $claims);
        $this->assertArrayNotHasKey('picture', $claims);
    }

    /**
     * Null does not remove the claim either: it becomes a JSON null.
     */
    public function test_null_yields_a_null_claim(): void {
        $claims = $this->sign(['lis_person_contact_email_primary' => null]);
        $this->assertArrayHasKey('email', $claims);
        $this->assertNull($claims['email']);
    }

    /**
     * Grouped claims behave the same: the group appears with an empty member.
     */
    public function test_empty_grouped_claim_keeps_the_group(): void {
        $claims = $this->sign(['lis_person_sourcedid' => '']);
        $this->assertSame(['person_sourcedid' => ''], $claims['https://purl.imsglobal.org/spec/lti/claim/lis']);
    }

    /**
     * Merging a patch over the parameters (what mod_lti does with our result) cannot remove a key.
     */
    public function test_array_merge_cannot_remove_a_parameter(): void {
        $base = ['lis_person_contact_email_primary' => 'a@example.com', 'user_id' => '42'];
        $merged = array_merge($base, ['lis_person_contact_email_primary' => '']);
        $this->assertArrayHasKey('lis_person_contact_email_primary', $merged);
        $merged = array_merge($base, []);
        $this->assertSame('a@example.com', $merged['lis_person_contact_email_primary']);
    }

    /**
     * The sub claim comes from the user_id parameter and from nothing else.
     */
    public function test_sub_comes_from_user_id(): void {
        $this->assertSame('PSEUDONYM', $this->sign(['user_id' => 'PSEUDONYM'])['sub']);
    }

    /**
     * Core exposes no hook other than the ltisource callbacks between building the parameters and
     * signing them, which is why removal is impossible from a ltisource plugin.
     */
    public function test_launch_data_is_built_in_one_function(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/mod/lti/locallib.php');
        $this->assertStringContainsString("component_callback('ltisource_'.\$plugin, 'before_launch'", $source);
        $this->assertStringContainsString('array_merge($requestparams, $pluginparams)', $source);
        $this->assertStringContainsString('lti_sign_jwt($requestparams, $endpoint, $key, $typeid, $nonce)', $source);
    }
}
