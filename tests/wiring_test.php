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
 * Wiring tests: everything Moodle discovers by name.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::ltisource_ltipseudonym_extend_navigation_course
 * @covers     \ltisource_ltipseudonym\event\policy_changed
 */
final class wiring_test extends \advanced_testcase {
    /**
     * Gate SP-G3 evidence: mod_lti iterates all ltisource plugins; this plugin is among them.
     */
    public function test_plugin_is_an_installed_ltisource_plugin(): void {
        $plugins = \core_component::get_plugin_list('ltisource');
        $this->assertArrayHasKey('ltipseudonym', $plugins);
        $this->assertSame(
            'ltisource_ltipseudonym_before_launch',
            component_callback_exists('ltisource_ltipseudonym', 'before_launch')
        );
        $info = \core_plugin_manager::instance()->get_plugin_info('ltisource_ltipseudonym');
        $this->assertNotNull($info);
    }

    /**
     * Every capability exists with the expected type, context level and risks.
     */
    public function test_capabilities(): void {
        global $DB;
        $expected = [
            'ltisource/ltipseudonym:view' => [CONTEXT_COURSE, 0],
            'ltisource/ltipseudonym:managecoursepolicy' => [CONTEXT_COURSE, RISK_PERSONAL],
            'ltisource/ltipseudonym:managepolicy' => [CONTEXT_SYSTEM, RISK_PERSONAL | RISK_CONFIG],
            'ltisource/ltipseudonym:migrateidentity' => [CONTEXT_SYSTEM, RISK_PERSONAL | RISK_DATALOSS],
        ];
        foreach ($expected as $name => [$level, $risk]) {
            $cap = $DB->get_record('capabilities', ['name' => $name], '*', MUST_EXIST);
            $this->assertEquals($level, $cap->contextlevel, $name);
            $this->assertEquals($risk, $cap->riskbitmask, $name);
            $this->assertNotSame($name, get_capability_string($name), $name . ' has a language string');
        }
    }

    /**
     * Archetype defaults: only managers edit anything; nobody but an explicit assignment may migrate.
     */
    public function test_default_role_assignments(): void {
        global $DB;
        $this->resetAfterTest();
        $teacher = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $manager = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        $system = \context_system::instance();
        $allowed = fn($role, $cap) => $DB->record_exists(
            'role_capabilities',
            ['roleid' => $role->id, 'capability' => $cap, 'permission' => CAP_ALLOW, 'contextid' => $system->id]
        );
        $this->assertTrue($allowed($teacher, 'ltisource/ltipseudonym:view'));
        $this->assertFalse($allowed($teacher, 'ltisource/ltipseudonym:managecoursepolicy'));
        $this->assertTrue($allowed($manager, 'ltisource/ltipseudonym:managecoursepolicy'));
        $this->assertTrue($allowed($manager, 'ltisource/ltipseudonym:managepolicy'));
        $this->assertFalse($allowed($manager, 'ltisource/ltipseudonym:migrateidentity'));
    }

    /**
     * Capabilities are checked in the right context: a course manager cannot touch site policies.
     */
    public function test_capability_contexts(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');
        $coursectx = \context_course::instance($course->id);
        $this->assertTrue(has_capability('ltisource/ltipseudonym:view', $coursectx, $teacher));
        $this->assertFalse(has_capability('ltisource/ltipseudonym:managecoursepolicy', $coursectx, $teacher));
        $this->assertTrue(has_capability('ltisource/ltipseudonym:managecoursepolicy', $coursectx, $manager));
        $this->assertFalse(has_capability('ltisource/ltipseudonym:managepolicy', \context_system::instance(), $manager));
    }

    /**
     * The course navigation hook is discovered by core and adds a node only for users with the view capability.
     */
    public function test_course_navigation(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $functions = get_plugins_with_function('extend_navigation_course', 'lib.php', false);
        $this->assertArrayHasKey('ltipseudonym', $functions['ltisource'] ?? [], 'core discovers the callback');

        $context = \context_course::instance($course->id);
        $this->setUser($student);
        $node = \navigation_node::create('root');
        ltisource_ltipseudonym_extend_navigation_course($node, $course, $context);
        $this->assertFalse($node->get('ltisource_ltipseudonym'));

        $this->setUser($teacher);
        $node = \navigation_node::create('root');
        ltisource_ltipseudonym_extend_navigation_course($node, $course, $context);
        $child = $node->get('ltisource_ltipseudonym');
        $this->assertNotFalse($child);
        $this->assertStringContainsString('/mod/lti/source/ltipseudonym/course.php', $child->action->out(false));
    }

    /**
     * Gate SP-G4 evidence: the settings form of an LTI activity has no extension point for ltisource plugins.
     */
    public function test_activity_form_has_no_plugin_hook(): void {
        global $CFG;
        $form = file_get_contents($CFG->dirroot . '/mod/lti/mod_form.php');
        $this->assertStringNotContainsString("'definition_after_data'", $form);
        $this->assertStringNotContainsString("get_plugin_list('ltisource')", $form);
        // The only call into ltisource plugins from the form is the add_instance_hook of the selected source.
        $this->assertStringContainsString("component_callback(\"ltisource_\$this->type\", 'add_instance_hook')", $form);
    }

    /**
     * English and German language files define the same keys and every used string exists.
     */
    public function test_language_files_are_complete(): void {
        global $CFG;
        $string = [];
        include($CFG->dirroot . '/mod/lti/source/ltipseudonym/lang/en/ltisource_ltipseudonym.php');
        $en = $string;
        $string = [];
        include($CFG->dirroot . '/mod/lti/source/ltipseudonym/lang/de/ltisource_ltipseudonym.php');
        $de = $string;
        $this->assertEqualsCanonicalizing(array_keys($en), array_keys($de), 'en and de define the same keys');
        foreach ($de as $key => $value) {
            $this->assertNotSame('', trim($value), $key);
        }

        // Every literal key used in the source exists.
        $root = $CFG->dirroot . '/mod/lti/source/ltipseudonym';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $missing = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false || strpos($path, '/lang/') !== false) {
                continue;
            }
            $code = file_get_contents($path);
            preg_match_all('/get_string\(\s*\'([^\']+)\'\s*,\s*\'ltisource_ltipseudonym\'/', $code, $m);
            foreach ($m[1] as $key) {
                if (!isset($en[$key])) {
                    $missing[] = $key . ' (' . basename($path) . ')';
                }
            }
            preg_match_all(
                '/\'(?:problem|type|scope|disc|level|identifiability|confirmaction|conflict):[a-z_0-9]+\'/',
                $code,
                $m2
            );
            foreach ($m2[0] as $quoted) {
                $key = trim($quoted, "'");
                if (!isset($en[$key])) {
                    $missing[] = $key . ' (' . basename($path) . ')';
                }
            }
        }
        $this->assertSame([], $missing);
    }

    /**
     * Every category and catalogue entry has labels.
     */
    public function test_catalogue_labels_exist(): void {
        foreach (array_keys(local\catalogue::pseudonym_types()) as $type) {
            $this->assertNotEmpty(get_string('type:' . $type, 'ltisource_ltipseudonym'));
        }
        foreach (array_keys(local\catalogue::scopes()) as $scope) {
            $this->assertNotEmpty(get_string('scope:' . $scope, 'ltisource_ltipseudonym'));
        }
        foreach (local\catalogue::DISCLOSURES as $category) {
            $this->assertNotEmpty(get_string('disc:' . $category, 'ltisource_ltipseudonym'));
        }
        foreach (['high', 'medium', 'low'] as $rating) {
            $this->assertNotEmpty(get_string('identifiability:' . $rating, 'ltisource_ltipseudonym'));
        }
    }

    /**
     * The event has a name, a description and does not accept incomplete data.
     */
    public function test_event(): void {
        $this->resetAfterTest();
        $event = event\policy_changed::create([
            'context' => \context_system::instance(),
            'objectid' => 1,
            'other' => ['action' => 'policy_created', 'typeid' => 5, 'courseid' => 0, 'cmid' => 0],
        ]);
        $this->assertNotEmpty($event::get_name());
        $this->assertStringContainsString('policy_created', $event->get_description());
        $this->assertFalse(event\policy_changed::get_objectid_mapping());
        $this->expectException(\coding_exception::class);
        event\policy_changed::create(['context' => \context_system::instance(), 'objectid' => 1, 'other' => ['typeid' => 5]]);
    }
}
