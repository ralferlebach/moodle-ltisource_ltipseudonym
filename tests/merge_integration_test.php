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
use ltisource_ltipseudonym\local\proxy\refusal;
use ltisource_ltipseudonym\local\proxy\translator;
use ltisource_ltipseudonym\local\token_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');
require_once(__DIR__ . '/fixtures/settings_hook_standin.php');
require_once(__DIR__ . '/fixtures/after_merge_hook_standin.php');

/**
 * Account merges with tool_mergeusers (gate finding F2).
 *
 * tool_mergeusers is not installed on the test sites (and must not be). The callbacks are called with stand-ins of its hook
 * objects; when the real hook class exists, the settings callback is also run against it. The merge itself runs on real
 * mapping rows created by real launches.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\merge_integration
 */
final class merge_integration_test extends \advanced_testcase {
    /** @var string Hook class of tool_mergeusers for the settings. */
    private const SETTINGS_HOOK = 'tool_mergeusers\\hook\\add_settings_before_merging';
    /** @var string Hook class of tool_mergeusers after all tables. */
    private const AFTER_HOOK = 'tool_mergeusers\\hook\\after_merged_all_tables';

    /**
     * Two users A (merged away) and B (kept), two courses with one activity of a pseudonymising tool (course scope).
     * A launched both activities, B only the first: the first course is the conflict, the second is A's alone.
     *
     * @return \stdClass
     */
    private function world(): \stdClass {
        $this->resetAfterTest();
        $w = new \stdClass();
        $w->c1 = $this->getDataGenerator()->create_course();
        $w->c2 = $this->getDataGenerator()->create_course();
        $w->a = $this->getDataGenerator()->create_user();
        $w->b = $this->getDataGenerator()->create_user();
        foreach ([$w->c1, $w->c2] as $course) {
            $this->getDataGenerator()->enrol_user($w->a->id, $course->id, 'student');
            $this->getDataGenerator()->enrol_user($w->b->id, $course->id, 'student');
        }
        $w->typeid = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $w->typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'course']);
        $w->act1 = testing\fixture::create_activity($w->c1, $w->typeid);
        $w->act2 = testing\fixture::create_activity($w->c2, $w->typeid);
        $w->a1 = testing\fixture::launch($w->act1, $w->a)[2]['sub'];
        $w->a2 = testing\fixture::launch($w->act2, $w->a)[2]['sub'];
        $w->b1 = testing\fixture::launch($w->act1, $w->b)[2]['sub'];
        $this->setAdminUser();
        return $w;
    }

    /**
     * Run the after-merge callback as tool_mergeusers would.
     *
     * @param int $toid
     * @param int $fromid
     * @return testing\after_merge_hook_standin
     */
    private function merge(int $toid, int $fromid): testing\after_merge_hook_standin {
        $hook = new testing\after_merge_hook_standin($toid, $fromid);
        merge_integration::after_merged_all_tables($hook);
        return $hook;
    }

    /**
     * The settings take the table out of the generic processing and declare the unique index.
     */
    public function test_settings_declare_the_table_and_the_index(): void {
        global $DB;
        $settings = merge_integration::db_settings();
        $this->assertSame(['ltisource_ltipseudonym_map'], array_values($settings['exceptions']));
        $index = $settings['compoundindexes']['ltisource_ltipseudonym_map'];
        $this->assertSame(['userid'], $index['userfield']);
        // The declared index is exactly the unique index of install.xml.
        $indexes = $DB->get_manager()->get_install_xml_schema()->getTable('ltisource_ltipseudonym_map')->getIndexes();
        $identity = array_values(array_filter($indexes, fn($i) => $i->getName() === 'identity'))[0];
        $this->assertTrue($identity->getUnique());
        $this->assertSame($identity->getFields(), array_merge($index['userfield'], $index['otherfields']));

        $standin = new testing\settings_hook_standin();
        merge_integration::add_settings_before_merging($standin);
        $this->assertSame($settings, $standin->get_raw());

        $class = self::SETTINGS_HOOK;
        if (class_exists($class)) {
            // With tool_mergeusers installed, the real hook object is used as well.
            $hook = new $class();
            merge_integration::add_settings_before_merging($hook);
            $this->assertContains('ltisource_ltipseudonym_map', $hook->get_settings()->exceptions);
            $this->assertArrayHasKey('ltisource_ltipseudonym_map', $hook->get_settings()->compoundindexes);
        }
    }

    /**
     * tool_mergeusers layers custom settings, hook settings and its defaults with array_replace_recursive(). The plugin's
     * entries must not replace a default entry (a numeric key would replace "user_preferences").
     */
    public function test_settings_do_not_replace_the_defaults_of_tool_mergeusers(): void {
        // Excerpt of tool_mergeusers' default_db_config (commit 61c13f0484986ba78898b1760464adfe2eb97aae).
        $defaults = [
            'exceptions' => ['user_preferences', 'user_private_key', 'user_info_data', 'my_pages'],
            'compoundindexes' => ['auth_lti_linked_login' => ['userfield' => ['userid'], 'otherfields' => ['issuer256', 'sub256']]],
        ];
        $layered = new testing\settings_hook_standin();
        $layered->add_raw_settings([]); // Custom admin settings (empty).
        merge_integration::add_settings_before_merging($layered); // Hook settings.
        $layered->add_raw_settings($defaults); // Defaults, lowest priority.
        $final = $layered->get_raw();
        $skip = array_flip($final['exceptions']); // As user_merger reads it.
        foreach (['user_preferences', 'user_private_key', 'user_info_data', 'my_pages', 'ltisource_ltipseudonym_map'] as $t) {
            $this->assertArrayHasKey($t, $skip, $t);
        }
        $this->assertArrayHasKey('auth_lti_linked_login', $final['compoundindexes']);
        $this->assertArrayHasKey('ltisource_ltipseudonym_map', $final['compoundindexes']);

        // Control: the same list with a numeric key would have lost a default.
        $numeric = new testing\settings_hook_standin();
        $numeric->add_raw_settings(['exceptions' => ['ltisource_ltipseudonym_map']]);
        $numeric->add_raw_settings($defaults);
        $this->assertNotContains('user_preferences', $numeric->get_raw()['exceptions']);
    }

    /**
     * Pair 3 of the gate: the non-conflicting pseudonym moves to B unchanged, the conflicting one of B wins and A's is
     * retired (not deleted); one audit row with counts only.
     */
    public function test_merge_moves_and_retires_but_never_deletes(): void {
        global $DB;
        $w = $this->world();
        $before = $DB->count_records('ltisource_ltipseudonym_map');
        $hook = $this->merge((int) $w->b->id, (int) $w->a->id);
        $this->assertSame([], $hook->errors);
        $this->assertSame($before, $DB->count_records('ltisource_ltipseudonym_map'), 'nothing deleted');

        $moved = token_service::lookup($w->a2);
        $this->assertEquals($w->b->id, $moved->userid);
        $this->assertSame('active', $moved->status);
        $kept = token_service::lookup($w->b1);
        $this->assertEquals($w->b->id, $kept->userid);
        $this->assertSame('active', $kept->status);
        $loser = token_service::lookup($w->a1);
        $this->assertEquals($w->a->id, $loser->userid);
        $this->assertSame('retired', $loser->status);
        // The unique index holds: B has one active pseudonym per scope.
        $this->assertSame(2, $DB->count_records('ltisource_ltipseudonym_map', ['userid' => $w->b->id, 'status' => 'active']));

        $audit = $DB->get_records('ltisource_ltipseudonym_aud', ['action' => 'user_merged']);
        $this->assertCount(1, $audit);
        $row = reset($audit);
        $this->assertEquals($w->typeid, $row->typeid);
        $this->assertEquals(get_admin()->id, $row->actorid);
        $this->assertSame(['moved' => 1, 'conflicts' => 1, 'retired' => 1], json_decode($row->newvalue, true));
        foreach ($DB->get_records('ltisource_ltipseudonym_aud') as $any) {
            foreach ([$w->a1, $w->a2, $w->b1] as $token) {
                $this->assertStringNotContainsString($token, $any->oldvalue . $any->newvalue, 'no pseudonym in the audit');
            }
        }
        $this->assertCount(1, $hook->logs);
        $this->assertStringContainsString('moved 1, conflicts 1', $hook->logs[0]);
        foreach ([$w->a1, $w->a2, $w->b1] as $token) {
            $this->assertStringNotContainsString($token, $hook->logs[0]);
        }
    }

    /**
     * What the tool sees afterwards: B gets A's pseudonym where only A had one (continuity), keeps its own where both had
     * one; the service proxy resolves A's moved pseudonym to B and refuses A's retired one.
     */
    public function test_launches_and_services_after_the_merge(): void {
        global $DB;
        $w = $this->world();
        $this->merge((int) $w->b->id, (int) $w->a->id);
        $this->assertSame($w->a2, testing\fixture::launch($w->act2, $w->b)[2]['sub'], 'B continues A in course 2');
        $this->assertSame($w->b1, testing\fixture::launch($w->act1, $w->b)[2]['sub'], 'B keeps its own in course 1');

        $translator = new translator();
        $type = $DB->get_record('lti_types', ['id' => $w->typeid]);
        $ctx2 = $translator->context_for($type, (int) $w->c2->id, 0);
        $this->assertSame((int) $w->b->id, $translator->user_of($ctx2, $w->a2, 'ags'));
        $ctx1 = $translator->context_for($type, (int) $w->c1->id, 0);
        try {
            $translator->user_of($ctx1, $w->a1, 'ags');
            $this->fail('a retired pseudonym is not resolved');
        } catch (refusal $e) {
            $this->assertSame(404, $e->getCode());
        }
        $miss = $DB->get_records('ltisource_ltipseudonym_aud', ['action' => 'service_pseudonym_miss']);
        $this->assertSame('retired', json_decode(end($miss)->newvalue)->reason);
    }

    /**
     * A repeated run changes nothing and writes no further audit row; a user without pseudonyms is a no-op.
     */
    public function test_merge_is_idempotent_and_quiet_without_rows(): void {
        global $DB;
        $w = $this->world();
        $this->merge((int) $w->b->id, (int) $w->a->id);
        $snapshot = $DB->get_records('ltisource_ltipseudonym_map', null, 'id');
        $again = $this->merge((int) $w->b->id, (int) $w->a->id);
        $this->assertSame([], $again->errors);
        $this->assertEquals($snapshot, $DB->get_records('ltisource_ltipseudonym_map', null, 'id'));
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_aud', ['action' => 'user_merged']));

        $c = $this->getDataGenerator()->create_user();
        $d = $this->getDataGenerator()->create_user();
        $none = $this->merge((int) $d->id, (int) $c->id);
        $this->assertSame([], $none->errors);
        $this->assertStringContainsString('moved 0, conflicts 0', $none->logs[0]);
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_aud', ['action' => 'user_merged']));
    }

    /**
     * Generations: an old (retired) generation of A moves as retired history unless B has the same generation; the current
     * generation follows the conflict rule. Rows of other users are never touched.
     */
    public function test_generations_and_other_users(): void {
        global $DB;
        $w = $this->world();
        $other = $this->getDataGenerator()->create_and_enrol($w->c1, 'student');
        $othersub = testing\fixture::launch($w->act1, $other)[2]['sub'];
        // Retire generation 1 of A only (as if A's rows were older), then A launches generation 2.
        $DB->set_field('ltisource_ltipseudonym_map', 'status', 'retired', ['userid' => $w->a->id]);
        $DB->set_field('ltisource_ltipseudonym_map', 'version', 0, ['userid' => $w->a->id]);
        $a1new = testing\fixture::launch($w->act1, $w->a)[2]['sub'];

        $hook = $this->merge((int) $w->b->id, (int) $w->a->id);
        $this->assertSame([], $hook->errors);
        // Old generation (version 0) of course 1 and 2: B has no version 0 row, so both move as retired history.
        $this->assertEquals($w->b->id, token_service::lookup($w->a1)->userid);
        $this->assertSame('retired', token_service::lookup($w->a1)->status);
        $this->assertEquals($w->b->id, token_service::lookup($w->a2)->userid);
        // Current generation of course 1: B's wins, A's new one is retired on A.
        $this->assertEquals($w->a->id, token_service::lookup($a1new)->userid);
        $this->assertSame('retired', token_service::lookup($a1new)->status);
        $this->assertSame('active', token_service::lookup($w->b1)->status);
        // Another user is untouched.
        $this->assertEquals($other->id, token_service::lookup($othersub)->userid);
        $this->assertSame('active', token_service::lookup($othersub)->status);
        $audit = json_decode($DB->get_field('ltisource_ltipseudonym_aud', 'newvalue', ['action' => 'user_merged']), true);
        $this->assertSame(['moved' => 2, 'conflicts' => 1, 'retired' => 1], $audit);
    }

    /**
     * A failure is reported as an error message of the merge (tool_mergeusers then rolls back the whole merge) and
     * nothing is changed.
     */
    public function test_failure_is_reported_so_the_merge_rolls_back(): void {
        global $DB;
        $w = $this->world();
        $snapshot = $DB->get_records('ltisource_ltipseudonym_map', null, 'id');
        $hook = $this->merge((int) $w->a->id, (int) $w->a->id);
        $this->assertCount(1, $hook->errors);
        $this->assertStringContainsString('ltisource_ltipseudonym', $hook->errors[0]);
        $this->assertSame([], $hook->logs);
        $this->assertEquals($snapshot, $DB->get_records('ltisource_ltipseudonym_map', null, 'id'));
    }

    /**
     * Without tool_mergeusers the hook classes do not exist. Core registers the callbacks without loading the class and
     * without any debugging message, never dispatches them, and the hook overview of the administration renders.
     */
    public function test_callbacks_are_harmless_without_tool_mergeusers(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $hooksfile = $CFG->dirroot . '/mod/lti/source/ltipseudonym/db/hooks.php';
        $manager = \core\hook\manager::phpunit_get_instance(['ltisource_ltipseudonym' => $hooksfile]);
        $this->assertDebuggingNotCalled();
        $settings = $manager->get_callbacks_for_hook(self::SETTINGS_HOOK);
        $this->assertCount(1, $settings);
        $this->assertSame(merge_integration::class . '::add_settings_before_merging', $settings[0]['callback']);
        $after = $manager->get_callbacks_for_hook(self::AFTER_HOOK);
        $this->assertSame(merge_integration::class . '::after_merged_all_tables', $after[0]['callback']);
        $this->assertSame([], \core\hook\manager::get_replaced_callbacks(self::SETTINGS_HOOK));

        // The site's own hook manager (all plugins) and the administration's hook overview.
        $site = \core\di::get(\core\hook\manager::class);
        // Other plugins of the site may register for the same hook; ours is among them.
        $this->assertContains(
            merge_integration::class . '::after_merged_all_tables',
            array_column($site->get_callbacks_for_hook(self::AFTER_HOOK), 'callback')
        );
        require_once($CFG->libdir . '/tablelib.php');
        $this->setAdminUser();
        $PAGE->set_url('/admin/hooks.php');
        ob_start();
        try {
            (new \core_admin\table\hook_list_table())->out();
        } finally {
            ob_end_clean();
        }
        $this->assertDebuggingNotCalled();
    }
}
