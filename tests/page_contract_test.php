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
 * FAM-04: the entry point scripts are executed for real (include, CLI context) and must enforce capability, context and sesskey.
 * A successful state change ends in redirect(), which throws "redirecterrordetected" in the CLI/PHPUnit context; that exception
 * is the proof that the script got past all checks.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class page_contract_test extends \advanced_testcase {
    /**
     * Run a plugin script with the given request parameters and return its output.
     *
     * @param string $script file name below the plugin directory
     * @param array $params request parameters
     * @return string output
     */
    private function run_page(string $script, array $params): string {
        global $CFG, $DB, $USER, $PAGE, $OUTPUT, $SESSION, $SITE, $COURSE;
        $_GET = $_REQUEST = $params;
        $_POST = [];
        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        ob_start();
        try {
            include($CFG->dirroot . '/mod/lti/source/ltipseudonym/' . $script);
            return ob_get_contents();
        } finally {
            ob_end_clean();
            $_GET = $_REQUEST = $_POST = [];
        }
    }

    /**
     * Assert that a page ends in the redirect that a successful state change performs.
     *
     * @param string $script
     * @param array $params
     */
    private function assert_completes(string $script, array $params): void {
        try {
            $this->run_page($script, $params);
            $this->fail('the page should have ended in a redirect');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode);
        }
    }

    /**
     * The overview needs the system capability; the administrator sees the boundary warning.
     */
    public function test_overview_page(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        try {
            $this->run_page('policy.php', []);
            $this->fail('no capability, no page');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('ltisource/ltipseudonym:managepolicy'), $e->getMessage());
        }
        $this->setAdminUser();
        $html = $this->run_page('policy.php', []);
        $this->assertStringContainsString(get_string('loginhintnotice', 'ltisource_ltipseudonym'), $html);
        $this->assertMatchesRegularExpression('/alert-warning|!! /', $html);
    }

    /**
     * Rotation: capability, then sesskey, then the state change.
     */
    public function test_migration_page_checks(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        try {
            $this->run_page('migrate.php', ['typeid' => 5, 'action' => 'rotate', 'sesskey' => sesskey(), 'confirm' => 1]);
            $this->fail('no capability, no rotation');
        } catch (\required_capability_exception $e) {
            $this->assertSame(1, local\config::generation(5), 'nothing happened without the capability');
        }
    }

    /**
     * A missing or wrong sesskey stops every state changing action; with sesskey and confirmation it is carried out.
     */
    public function test_migration_page_sesskey(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['rotate', 'purgeorphans', 'rebind'] as $action) {
            foreach ([[], ['sesskey' => 'wrong']] as $extra) {
                try {
                    $this->run_page('migrate.php', ['typeid' => 5, 'action' => $action, 'confirm' => 1] + $extra);
                    $this->fail("$action without a valid sesskey must fail");
                } catch (\moodle_exception $e) {
                    // Core answers a missing sesskey with missingparam and a wrong one with invalidsesskey.
                    $this->assertContains($e->errorcode, ['invalidsesskey', 'missingparam']);
                }
            }
        }
        $this->assertSame(1, local\config::generation(5));
        $this->assert_completes('migrate.php', ['typeid' => 5, 'action' => 'rotate', 'confirm' => 1, 'sesskey' => sesskey()]);
        $this->assertSame(2, local\config::generation(5), 'rotation was carried out');
    }

    /**
     * Resetting a policy: sesskey is required, the capability decides the context.
     */
    public function test_policy_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $tool = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted']);
        $params = ['typeid' => $tool, 'delete' => 1, 'confirm' => 1];

        $this->setUser($this->getDataGenerator()->create_user());
        try {
            $this->run_page('editpolicy.php', $params + ['sesskey' => sesskey()]);
            $this->fail('no capability');
        } catch (\required_capability_exception $e) {
            $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_pol', ['typeid' => $tool]));
        }

        $this->setAdminUser();
        try {
            $this->run_page('editpolicy.php', $params);
            $this->fail('no sesskey');
        } catch (\moodle_exception $e) {
            $this->assertContains($e->errorcode, ['invalidsesskey', 'missingparam']);
            $this->assertTrue($DB->record_exists('ltisource_ltipseudonym_pol', ['typeid' => $tool]));
        }
        $this->assert_completes('editpolicy.php', $params + ['sesskey' => sesskey()]);
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_pol', ['typeid' => $tool]));
    }

    /**
     * Course and activity overrides need the course capability; an editing teacher can look but not edit by default.
     */
    public function test_course_level_pages(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        $activity = testing\fixture::create_activity($course, $tool);

        $this->setUser($student);
        try {
            $this->run_page('course.php', ['id' => $course->id]);
            $this->fail('a student has no view capability');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->setUser($teacher);
        $html = $this->run_page('course.php', ['id' => $course->id]);
        $this->assertStringContainsString(get_string('loginhintnotice', 'ltisource_ltipseudonym'), $html);
        $this->assertStringNotContainsString('editpolicy.php', $html, 'the teacher may not edit by default');
        try {
            $this->run_page('editpolicy.php', ['cmid' => $activity->cmid]);
            $this->fail('the teacher must not edit the activity policy');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->setAdminUser();
        $html = $this->run_page('editpolicy.php', ['cmid' => $activity->cmid]);
        $this->assertStringContainsString(get_string('loginhintnotice', 'ltisource_ltipseudonym'), $html);
    }

    /**
     * The policy of a tool that is not an LTI 1.3 registration cannot be edited.
     */
    public function test_policy_of_unknown_tool_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        $this->run_page('editpolicy.php', ['typeid' => 987654]);
    }
}
