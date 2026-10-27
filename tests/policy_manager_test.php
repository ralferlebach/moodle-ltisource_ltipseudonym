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
use ltisource_ltipseudonym\local\policy_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Tests of the policy hierarchy: site, tool, course, activity.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\policy_manager
 * @covers     \ltisource_ltipseudonym\local\audit
 */
final class policy_manager_test extends \advanced_testcase {
    /**
     * Without any stored policy the plugin does nothing.
     */
    public function test_default_is_inert(): void {
        $this->resetAfterTest();
        $eff = policy_manager::resolve(5, 3, 7);
        $this->assertSame(catalogue::TYPE_NONE, $eff->pseudotype);
        $this->assertFalse($eff->pseudonymising);
        $this->assertFalse(policy_manager::is_active($eff));
    }

    /**
     * A tool policy overrides the site default when the site allows it (default).
     */
    public function test_type_overrides_site_default(): void {
        $this->resetAfterTest();
        $typeid = testing\fixture::create_type();
        testing\fixture::set_policy(['pseudotype' => catalogue::TYPE_HMAC, 'scopetype' => 'deployment', 'allowoverride' => 1]);
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => catalogue::TYPE_RANDOM]);
        $this->assertSame(catalogue::TYPE_RANDOM, policy_manager::resolve($typeid)->pseudotype);
        // Another tool inherits the site default.
        $this->assertSame(catalogue::TYPE_HMAC, policy_manager::resolve($typeid + 100)->pseudotype);
    }

    /**
     * When the site forbids overrides the tool policy is ignored and the fact is traced.
     */
    public function test_site_can_forbid_type_override(): void {
        $this->resetAfterTest();
        testing\fixture::set_policy(['pseudotype' => catalogue::TYPE_HMAC, 'allowoverride' => 0]);
        testing\fixture::set_policy(['typeid' => 9, 'pseudotype' => catalogue::TYPE_RANDOM]);
        $eff = policy_manager::resolve(9);
        $this->assertSame(catalogue::TYPE_HMAC, $eff->pseudotype);
        $this->assertSame('ignored', $eff->trace[0]['note']);
    }

    /**
     * Course and activity overrides need an explicit tool policy that allows them.
     */
    public function test_lower_levels_require_explicit_permission(): void {
        $this->resetAfterTest();
        // No tool row: the course row is ignored.
        testing\fixture::set_policy(['typeid' => 9, 'courseid' => 4, 'pseudotype' => catalogue::TYPE_RANDOM]);
        $this->assertSame(catalogue::TYPE_NONE, policy_manager::resolve(9, 4)->pseudotype);

        // Tool row without allowoverride: still ignored.
        $id = testing\fixture::set_policy(['typeid' => 9, 'pseudotype' => catalogue::TYPE_HMAC, 'allowoverride' => 0]);
        $this->assertSame(catalogue::TYPE_HMAC, policy_manager::resolve(9, 4)->pseudotype);

        // Tool row with allowoverride: the stronger course type is honoured.
        $this->setAdminUser();
        global $DB;
        $DB->set_field('ltisource_ltipseudonym_pol', 'allowoverride', 1, ['id' => $id]);
        $this->assertSame(catalogue::TYPE_RANDOM, policy_manager::resolve(9, 4)->pseudotype);
    }

    /**
     * Lower levels can only make a value stronger, never weaker.
     *
     * @dataProvider clamp_provider
     * @param array $parent tool level fields
     * @param array $child course level fields
     * @param string $field field to inspect
     * @param string $expected effective value
     */
    public function test_never_weaker_than_parent(array $parent, array $child, string $field, string $expected): void {
        $this->resetAfterTest();
        testing\fixture::set_policy($parent + ['typeid' => 9, 'allowoverride' => 1]);
        testing\fixture::set_policy($child + ['typeid' => 9, 'courseid' => 4]);
        $this->assertSame($expected, policy_manager::resolve(9, 4)->$field);
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function clamp_provider(): array {
        return [
            'type may be strengthened' => [['pseudotype' => 'hmac_secret_v1'], ['pseudotype' => 'random_persisted'],
                'pseudotype', 'random_persisted'],
            'type may not be weakened' => [['pseudotype' => 'random_persisted'], ['pseudotype' => 'hmac_secret_v1'],
                'pseudotype', 'random_persisted'],
            'legacy cannot undo a pseudonym' => [['pseudotype' => 'hmac_secret_v1'], ['pseudotype' => 'legacy_userid'],
                'pseudotype', 'hmac_secret_v1'],
            'scope may be narrowed' => [['scopetype' => 'deployment'], ['scopetype' => 'cm'], 'scopetype', 'cm'],
            'scope may not be widened' => [['scopetype' => 'course'], ['scopetype' => 'site'], 'scopetype', 'course'],
            'disclosure may be blanked' => [['discemail' => 'pass'], ['discemail' => 'blank'], 'discemail', 'blank'],
            'disclosure may not be re-enabled' => [['discemail' => 'blank'], ['discemail' => 'pass'], 'discemail', 'blank'],
            'unset child inherits' => [['discname' => 'blank'], ['discemail' => 'blank'], 'discname', 'blank'],
        ];
    }

    /**
     * The activity level follows the course level and its allow-override flag.
     */
    public function test_activity_level_chain(): void {
        $this->resetAfterTest();
        testing\fixture::set_policy(['typeid' => 9, 'pseudotype' => 'hmac_secret_v1', 'allowoverride' => 1]);
        testing\fixture::set_policy(['typeid' => 9, 'courseid' => 4, 'discname' => 'blank', 'allowoverride' => 0]);
        testing\fixture::set_policy(['courseid' => 4, 'cmid' => 12, 'pseudotype' => 'random_persisted']);
        // The course row forbids activity overrides.
        $this->assertSame('hmac_secret_v1', policy_manager::resolve(9, 4, 12)->pseudotype);
        $this->assertSame('blank', policy_manager::resolve(9, 4, 12)->discname);
    }

    /**
     * An invalid stored value is ignored and traced, never applied.
     */
    public function test_invalid_stored_value_is_ignored(): void {
        $this->resetAfterTest();
        testing\fixture::set_policy(['typeid' => 9, 'pseudotype' => 'rot13', 'allowoverride' => 0]);
        $eff = policy_manager::resolve(9);
        $this->assertSame(catalogue::TYPE_NONE, $eff->pseudotype);
        $this->assertSame('invalid', $eff->trace[0]['note']);
    }

    /**
     * A pseudonymising policy empties the LTI 1.1 result id (it carries the local user id) unless somebody decided
     * otherwise explicitly; a policy that does not pseudonymise leaves it alone.
     */
    public function test_result_id_defaults_to_empty_when_pseudonymising(): void {
        $this->resetAfterTest();
        testing\fixture::set_policy(['typeid' => 9, 'pseudotype' => 'random_persisted', 'allowoverride' => 1]);
        $this->assertSame('blank', policy_manager::resolve(9)->discresult);
        $this->assertArrayNotHasKey('discresult', policy_manager::resolve(9)->sources, 'a default, not a stored decision');
        $this->assertSame('pass', policy_manager::resolve(8)->discresult, 'no pseudonymisation, no change');

        testing\fixture::set_policy(['typeid' => 9, 'courseid' => 4, 'discresult' => 'pass']);
        $this->assertSame('pass', policy_manager::resolve(9, 4)->discresult, 'an explicit decision wins');
        testing\fixture::set_policy(['typeid' => 7, 'pseudotype' => 'hmac_secret_v1', 'discresult' => 'remove']);
        $this->assertSame('remove', policy_manager::resolve(7)->discresult);
    }

    /**
     * Saving a weaker policy than the parent is rejected.
     */
    public function test_save_rejects_weaker_than_parent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        testing\fixture::set_policy(['typeid' => 9, 'pseudotype' => 'random_persisted', 'allowoverride' => 1]);
        $this->expectException(\moodle_exception::class);
        policy_manager::save(9, 4, 0, (object) ['pseudotype' => 'hmac_secret_v1'], get_admin()->id);
    }

    /**
     * Saving legacy is rejected while the site setting is off and accepted once it is on.
     */
    public function test_legacy_needs_the_site_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        try {
            policy_manager::save(9, 0, 0, (object) ['pseudotype' => catalogue::TYPE_LEGACY_USERID], get_admin()->id);
            $this->fail('Legacy must be rejected while disabled');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('legacydisabled', $e->a);
        }
        set_config('legacyenabled', 1, 'ltisource_ltipseudonym');
        $row = policy_manager::save(9, 0, 0, (object) ['pseudotype' => catalogue::TYPE_LEGACY_USERID], get_admin()->id);
        $this->assertSame(catalogue::TYPE_LEGACY_USERID, $row->pseudotype);
    }

    /**
     * Saving writes an audit record with actor, time, context, old and new policy and fires an event.
     */
    public function test_save_audits_and_fires_event(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $sink = $this->redirectEvents();
        policy_manager::save(9, 0, 0, (object) ['pseudotype' => 'hmac_secret_v1', 'scopetype' => 'course'], get_admin()->id);
        policy_manager::save(9, 0, 0, (object) ['pseudotype' => 'random_persisted', 'scopetype' => 'course'], get_admin()->id);

        $audit = array_values($DB->get_records('ltisource_ltipseudonym_aud', null, 'id'));
        $this->assertCount(2, $audit);
        $this->assertSame('policy_created', $audit[0]->action);
        $this->assertNull($audit[0]->oldvalue);
        $this->assertSame('policy_updated', $audit[1]->action);
        $this->assertSame((int) get_admin()->id, (int) $audit[1]->actorid);
        $this->assertSame('hmac_secret_v1', json_decode($audit[1]->oldvalue)->pseudotype);
        $this->assertSame('random_persisted', json_decode($audit[1]->newvalue)->pseudotype);
        $this->assertGreaterThan(0, $audit[1]->timecreated);
        $this->assertSame(9, (int) $audit[1]->typeid);

        $events = array_filter($sink->get_events(), fn($e) => $e instanceof event\policy_changed);
        $this->assertCount(2, $events);
        $sink->close();
    }

    /**
     * Deleting a policy is audited and the level inherits again.
     */
    public function test_delete_is_audited(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        policy_manager::save(9, 0, 0, (object) ['pseudotype' => 'hmac_secret_v1'], get_admin()->id);
        $this->assertTrue(policy_manager::delete(9, 0, 0, get_admin()->id));
        $this->assertFalse(policy_manager::delete(9, 0, 0, get_admin()->id));
        $this->assertSame(catalogue::TYPE_NONE, policy_manager::resolve(9)->pseudotype);
        $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_aud', ['action' => 'policy_deleted']));
    }

    /**
     * The audit trail never contains a pseudonym, even after launches (checked in launch_test too).
     */
    public function test_snapshot_contains_only_policy_fields(): void {
        $row = (object) ['pseudotype' => 'hmac_secret_v1', 'scopetype' => 'cm', 'token' => 'SECRET', 'allowoverride' => 1];
        $snapshot = policy_manager::snapshot($row);
        $this->assertStringNotContainsString('SECRET', $snapshot);
        $this->assertNull(policy_manager::snapshot(null));
    }
}
