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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use ltisource_ltipseudonym\local\token_service;
use ltisource_ltipseudonym\privacy\provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Privacy provider tests: export, deletion and anonymisation, including the grade reference note.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Create the data: two users with mappings, an administrator with a policy and an audit entry.
     *
     * @return array [user a, user b, admin]
     */
    private function data(): array {
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $service = new token_service();
        $service->get_or_create($a->id, 7, 'deployment', 0, 'random_persisted');
        $service->get_or_create($a->id, 7, 'course', 3, 'hmac_secret_v1');
        $service->get_or_create($b->id, 7, 'deployment', 0, 'random_persisted');
        $this->setAdminUser();
        local\policy_manager::save(7, 0, 0, (object) ['pseudotype' => 'random_persisted'], get_admin()->id);
        return [$a, $b, get_admin()];
    }

    /**
     * The metadata describes all three tables and the external location.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new collection('ltisource_ltipseudonym'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertContains('ltisource_ltipseudonym_map', $names);
        $this->assertContains('ltisource_ltipseudonym_pol', $names);
        $this->assertContains('ltisource_ltipseudonym_aud', $names);
        $this->assertContains('lti_provider', $names);
    }

    /**
     * A user has data in the own user context; the administrator in the system context.
     */
    public function test_contexts(): void {
        $this->resetAfterTest();
        [$a, $b, $admin] = $this->data();
        $contexts = provider::get_contexts_for_userid($a->id)->get_contextids();
        $this->assertEquals([\context_user::instance($a->id)->id], $contexts);
        $adminctx = provider::get_contexts_for_userid($admin->id)->get_contextids();
        $this->assertContains((int) \context_system::instance()->id, array_map('intval', $adminctx));
    }

    /**
     * The export contains the pseudonyms of the user, and only theirs.
     */
    public function test_export(): void {
        $this->resetAfterTest();
        [$a, $b] = $this->data();
        $context = \context_user::instance($a->id);
        $this->export_context_data_for_user($a->id, $context, 'ltisource_ltipseudonym');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'ltisource_ltipseudonym')]);
        $this->assertCount(2, $data->mappings);
        $tokens = array_map(fn($m) => $m->pseudonym, $data->mappings);
        $others = (new token_service())->get_or_create($b->id, 7, 'deployment', 0, 'random_persisted');
        $this->assertNotContains($others, $tokens);
    }

    /**
     * The export of the administrator lists the policy change and the audit entry.
     */
    public function test_export_of_configuration_actor(): void {
        $this->resetAfterTest();
        [, , $admin] = $this->data();
        $context = \context_system::instance();
        $this->export_context_data_for_user($admin->id, $context, 'ltisource_ltipseudonym');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'ltisource_ltipseudonym')]);
        $this->assertCount(1, $data->policies);
        $this->assertSame('policy_created', $data->audit[0]->action);
    }

    /**
     * Deleting for one user removes their mappings only.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b] = $this->data();
        $context = \context_user::instance($a->id);
        provider::delete_data_for_user(new approved_contextlist($a, 'ltisource_ltipseudonym', [$context->id]));
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['userid' => $a->id]));
        $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_map', ['userid' => $b->id]));
    }

    /**
     * Deleting everything in a user context removes that user's mappings.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b] = $this->data();
        provider::delete_data_for_all_users_in_context(\context_user::instance($b->id));
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['userid' => $b->id]));
        $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_map', ['userid' => $a->id]));
    }

    /**
     * Configuration records are detached from the person, but stay as documentation of what was issued.
     */
    public function test_configuration_is_anonymised_not_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        [, , $admin] = $this->data();
        $context = \context_system::instance();
        provider::delete_data_for_user(new approved_contextlist($admin, 'ltisource_ltipseudonym', [$context->id]));
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_pol'));
        $this->assertSame(0, (int) $DB->get_field('ltisource_ltipseudonym_pol', 'usermodified', []));
        $this->assertSame(0, (int) $DB->get_field('ltisource_ltipseudonym_aud', 'actorid', []));
        $this->assertSame([], provider::get_contexts_for_userid($admin->id)->get_contextids());
    }

    /**
     * The userlist variants find and delete the same data.
     */
    public function test_userlist(): void {
        global $DB;
        $this->resetAfterTest();
        [$a, $b, $admin] = $this->data();
        $userlist = new userlist(\context_user::instance($a->id), 'ltisource_ltipseudonym');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$a->id], $userlist->get_userids());

        $syslist = new userlist(\context_system::instance(), 'ltisource_ltipseudonym');
        provider::get_users_in_context($syslist);
        $this->assertEquals([$admin->id], $syslist->get_userids());

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($a->id),
            'ltisource_ltipseudonym',
            [$a->id]
        ));
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['userid' => $a->id]));
        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'ltisource_ltipseudonym',
            [$admin->id]
        ));
        $this->assertSame(0, (int) $DB->get_field('ltisource_ltipseudonym_pol', 'usermodified', []));
    }

    /**
     * The grade reference note is part of the metadata text shown to the data protection officer.
     */
    public function test_metadata_states_the_external_consequence(): void {
        $this->assertStringContainsString('outside this platform', get_string(
            'privacy:metadata:external',
            'ltisource_ltipseudonym'
        ));
    }
}
