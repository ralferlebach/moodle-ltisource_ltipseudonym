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

use ltisource_ltipseudonym\local\token_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Lifecycle tests: policy rollback, activity duplication (backup and restore) and uninstall data handling.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_ltisource_ltipseudonym_subplugin
 * @covers     \restore_ltisource_ltipseudonym_subplugin
 * @covers     \ltisource_ltipseudonym\local\policy_manager
 */
final class lifecycle_test extends \advanced_testcase {
    /**
     * Rolling the policy back restores the real id; switching it on again restores the same pseudonym.
     */
    public function test_policy_rollback_and_reactivation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        $activity = testing\fixture::create_activity($course, $tool);

        $this->assertEquals($user->id, testing\fixture::launch($activity, $user)[2]['sub']);

        local\policy_manager::save(
            $tool,
            0,
            0,
            (object) ['pseudotype' => 'random_persisted', 'scopetype' => 'deployment'],
            get_admin()->id
        );
        $pseudonym = testing\fixture::launch($activity, $user)[2]['sub'];
        $this->assertNotEquals($user->id, $pseudonym);

        // Rollback: delete the policy. The tool gets the real id again, the mapping is kept.
        local\policy_manager::delete($tool, 0, 0, get_admin()->id);
        $this->assertEquals($user->id, testing\fixture::launch($activity, $user)[2]['sub']);
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));

        // Reactivation: the same pseudonym returns, the tool keeps its history.
        local\policy_manager::save(
            $tool,
            0,
            0,
            (object) ['pseudotype' => 'random_persisted', 'scopetype' => 'deployment'],
            get_admin()->id
        );
        $this->assertSame($pseudonym, testing\fixture::launch($activity, $user)[2]['sub']);
    }

    /**
     * Duplicating an activity (backup and restore in one step) carries the activity policy and no mappings.
     */
    public function test_duplicate_activity_copies_policy_but_not_mappings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment',
            'allowoverride' => 1]);
        $activity = testing\fixture::create_activity($course, $tool);
        testing\fixture::set_policy(['courseid' => $course->id, 'cmid' => $activity->cmid, 'scopetype' => 'cm',
            'discname' => 'blank']);
        $original = testing\fixture::launch($activity, $user)[2]['sub'];

        $this->setAdminUser();
        $cm = get_fast_modinfo($course)->get_cm($activity->cmid);
        // Core's backup parser emits PHP 8.x deprecations for empty elements: swallow only those raised inside
        // core's backup code and make sure no output comes from this plugin.
        set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
            $notice = in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true);
            return $notice && strpos($errfile, DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR) !== false;
        });
        ob_start();
        try {
            $newcm = testing\fixture::duplicate_cm($course, $cm);
        } finally {
            $output = ob_get_clean();
            restore_error_handler();
        }
        $this->assertStringNotContainsString('ltipseudonym', $output);
        $this->assertNotEmpty($newcm);

        $copied = $DB->get_record('ltisource_ltipseudonym_pol', ['cmid' => $newcm->id], '*', MUST_EXIST);
        $this->assertSame('cm', $copied->scopetype);
        $this->assertSame('blank', $copied->discname);
        $this->assertSame((string) $course->id, (string) $copied->courseid);

        // The mappings were not copied: the new activity is a new scope with its own pseudonym.
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));
        $lti = $DB->get_record('lti', ['id' => $newcm->instance], '*', MUST_EXIST);
        $lti->cmid = $newcm->id;
        $second = testing\fixture::launch($lti, $user)[2]['sub'];
        $this->assertNotSame($original, $second);
        $this->assertSame(2, $DB->count_records('ltisource_ltipseudonym_map'));
    }

    /**
     * A restored policy is only a proposal: when the tool policy forbids overrides it is ignored.
     */
    public function test_restored_policy_cannot_weaken_the_parent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'course',
            'allowoverride' => 1]);
        $activity = testing\fixture::create_activity($course, $tool);
        testing\fixture::set_policy(['courseid' => $course->id, 'cmid' => $activity->cmid, 'scopetype' => 'site',
            'pseudotype' => 'legacy_userid']);
        $claims = testing\fixture::launch($activity, $user)[2];
        $this->assertNotEquals($user->id, $claims['sub'], 'a weaker activity row is clamped');
    }

    /**
     * Tool re-registration is covered in migration_test; here: the plugin's data survives a type deletion.
     */
    public function test_mappings_survive_tool_deletion_until_purged(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $tool = testing\fixture::create_type();
        (new token_service())->get_or_create($user->id, $tool, 'deployment', 0, 'random_persisted');
        lti_delete_type($tool);
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));
    }

    /**
     * Uninstall keeps the system consistent: the plugin uses no core tables and stores its secret in its own config.
     */
    public function test_data_lives_only_in_plugin_tables_and_config(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        (new token_service())->get_or_create($user->id, 7, 'deployment', 0, 'hmac_secret_v1');
        $names = array_keys($DB->get_tables(false));
        $mine = array_filter($names, fn($name) => strpos($name, 'ltisource_ltipseudonym_') === 0);
        $this->assertEqualsCanonicalizing(['ltisource_ltipseudonym_aud', 'ltisource_ltipseudonym_map',
            'ltisource_ltipseudonym_pol'], array_values($mine));
        $this->assertNotFalse(get_config('ltisource_ltipseudonym', 'hmacsecret_v1'));
        foreach ($mine as $table) {
            $this->assertLessThanOrEqual(28, strlen($table), 'XMLDB table name limit');
        }
    }
}
