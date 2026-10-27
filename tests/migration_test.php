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

use ltisource_ltipseudonym\local\migration_service;
use ltisource_ltipseudonym\local\token_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Tests of preview, rotation, re-binding and purging.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\migration_service
 * @covers     \ltisource_ltipseudonym\local\config
 * @covers     \ltisource_ltipseudonym\local\observer
 */
final class migration_test extends \advanced_testcase {
    /**
     * The preview counts what a migration would touch.
     */
    public function test_preview(): void {
        $this->resetAfterTest();
        $service = new token_service();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $service->get_or_create($a->id, 7, 'deployment', 0, 'random_persisted');
        $service->get_or_create($a->id, 7, 'course', 3, 'random_persisted');
        $service->get_or_create($b->id, 7, 'deployment', 0, 'random_persisted');
        $service->get_or_create($b->id, 8, 'deployment', 0, 'random_persisted');
        $preview = migration_service::preview(7);
        $this->assertSame(3, $preview['active']);
        $this->assertSame(2, $preview['users']);
        $this->assertSame(0, $preview['retired']);
        $this->assertSame(1, $preview['generation']);
    }

    /**
     * Rotation retires every active mapping, raises the generation and issues new tokens.
     */
    public function test_rotation_is_explicit_and_audited(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $service = new token_service();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $old = $service->get_or_create($user->id, 7, 'deployment', 0, 'random_persisted');
        $untouched = $service->get_or_create($other->id, 8, 'deployment', 0, 'random_persisted');

        // Nothing happens implicitly.
        $this->assertSame($old, $service->get_or_create($user->id, 7, 'deployment', 0, 'random_persisted'));

        $this->assertSame(1, migration_service::rotate(7, get_admin()->id));
        $new = $service->get_or_create($user->id, 7, 'deployment', 0, 'random_persisted');
        $this->assertNotSame($old, $new);
        $this->assertSame('retired', $DB->get_field('ltisource_ltipseudonym_map', 'status', ['token' => $old]));
        $this->assertSame('active', $DB->get_field('ltisource_ltipseudonym_map', 'status', ['token' => $new]));
        $this->assertSame(2, (int) $DB->get_field('ltisource_ltipseudonym_map', 'version', ['token' => $new]));
        // Another tool keeps its identity.
        $this->assertSame($untouched, $service->get_or_create($other->id, 8, 'deployment', 0, 'random_persisted'));
        $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_aud', ['action' => 'identity_rotated', 'typeid' => 7]));
    }

    /**
     * Rotation changes the HMAC token as well because the generation is part of the domain.
     */
    public function test_rotation_changes_hmac_tokens(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $old = $service->get_or_create($user->id, 7, 'deployment', 0, 'hmac_secret_v1');
        migration_service::rotate(7, 2);
        $this->assertNotSame($old, $service->get_or_create($user->id, 7, 'deployment', 0, 'hmac_secret_v1'));
    }

    /**
     * After the tool was removed and registered again, the new registration shows new pseudonyms
     * until the administrator explicitly re-binds the old mappings.
     */
    public function test_tool_reregistration_and_rebind(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $old = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $old, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $activity = testing\fixture::create_activity($course, $old);
        $before = testing\fixture::launch($activity, $user)[2]['sub'];

        lti_delete_type($old);
        $this->assertSame(1, migration_service::count_orphans());

        $new = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $new, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $activity2 = testing\fixture::create_activity($course, $new);
        $fresh = testing\fixture::launch($activity2, $user)[2]['sub'];
        $this->assertNotSame($before, $fresh, 'a new registration is a new deployment: no silent carry-over');

        // Preview shows the collision of the user's fresh mapping; re-binding is refused.
        $preview = migration_service::rebind_preview($old, $new);
        $this->assertSame(1, $preview['move']);
        $this->assertSame(1, $preview['conflicts']);
        try {
            migration_service::rebind($old, $new, get_admin()->id);
            $this->fail('conflicts must block the re-binding');
        } catch (\moodle_exception $e) {
            $this->assertSame('rebindconflicts', $e->errorcode);
        }

        // Remove the fresh mapping, now the re-binding works and the tool sees the old pseudonym again.
        $DB->delete_records('ltisource_ltipseudonym_map', ['token' => $fresh]);
        $this->assertSame(1, migration_service::rebind($old, $new, get_admin()->id));
        $this->assertSame($before, testing\fixture::launch($activity2, $user)[2]['sub']);
        $this->assertSame(0, migration_service::count_orphans());
        $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_aud', ['action' => 'mappings_rebound']));
    }

    /**
     * Invalid re-binding requests are refused.
     */
    public function test_rebind_validation(): void {
        $this->resetAfterTest();
        $lti11 = testing\fixture::create_type(['lti_ltiversion' => LTI_VERSION_1]);
        foreach ([[0, 5], [5, 0], [5, 5], [5, $lti11], [5, 99999]] as [$from, $to]) {
            try {
                migration_service::rebind($from, $to, 2);
                $this->fail("re-binding $from => $to must be refused");
            } catch (\moodle_exception $e) {
                $this->assertSame('rebindinvalid', $e->errorcode);
            }
        }
    }

    /**
     * Orphaned mappings are counted and can be purged.
     */
    public function test_purge_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $real = testing\fixture::create_type();
        $service = new token_service();
        $service->get_or_create($user->id, $real, 'deployment', 0, 'random_persisted');
        $service->get_or_create($user->id, 88888, 'deployment', 0, 'random_persisted');
        $service->get_or_create($user->id, 0, 'site', 0, 'random_persisted');
        $this->assertSame(1, migration_service::count_orphans());
        $this->assertSame(1, migration_service::purge_orphans(2));
        $this->assertSame(2, $DB->count_records('ltisource_ltipseudonym_map'));
        $this->assertSame(0, migration_service::purge_orphans(2));
    }

    /**
     * Retired mappings are purged only after the retention period.
     */
    public function test_purge_retired(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $service->get_or_create($user->id, 7, 'deployment', 0, 'random_persisted');
        migration_service::rotate(7, 2);
        $DB->set_field('ltisource_ltipseudonym_map', 'timelastused', time() - 10 * DAYSECS, ['status' => 'retired']);
        $this->assertSame(0, migration_service::purge_retired(time() - 20 * DAYSECS));
        $this->assertSame(1, migration_service::purge_retired(time() - 5 * DAYSECS));
        $this->assertSame(0, $DB->count_records('ltisource_ltipseudonym_map', ['status' => 'retired']));
    }

    /**
     * The scheduled task purges according to the setting; zero keeps retired mappings.
     */
    public function test_scheduled_task(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        (new token_service())->get_or_create($user->id, 7, 'deployment', 0, 'random_persisted');
        migration_service::rotate(7, 2);
        $DB->set_field('ltisource_ltipseudonym_map', 'timelastused', time() - 400 * DAYSECS, ['status' => 'retired']);

        $task = new task\purge_retired();
        $this->assertNotEmpty($task->get_name());
        set_config('retireddays', 0, 'ltisource_ltipseudonym');
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map', ['status' => 'retired']));

        set_config('retireddays', 365, 'ltisource_ltipseudonym');
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertSame(0, $DB->count_records('ltisource_ltipseudonym_map', ['status' => 'retired']));
    }

    /**
     * Deleting a user, a course or an activity removes the mappings and policies that belong to it.
     */
    public function test_observers_clean_up(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        $activity = testing\fixture::create_activity($course, $tool);
        $service = new token_service();
        $service->get_or_create($user->id, $tool, 'cm', $activity->cmid, 'random_persisted');
        $service->get_or_create($user->id, $tool, 'course', $course->id, 'random_persisted');
        $service->get_or_create($user->id, $tool, 'deployment', 0, 'random_persisted');
        testing\fixture::set_policy(['courseid' => $course->id, 'cmid' => $activity->cmid, 'pseudotype' => 'random_persisted']);
        testing\fixture::set_policy(['typeid' => $tool, 'courseid' => $course->id, 'discname' => 'blank']);

        testing\fixture::delete_cm($activity->cmid);
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['scopetype' => 'cm']));
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_pol', ['cmid' => $activity->cmid]));
        $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_pol', ['courseid' => $course->id]));

        delete_course($course, false);
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['scopetype' => 'course']));
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_pol', ['courseid' => $course->id]));

        delete_user($user);
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['userid' => $user->id]));
    }
}
