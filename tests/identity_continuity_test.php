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
 * Finding SP-04: which pseudonym a person keeps, and which one is new, when an activity moves through its life (deep linking
 * before the activity exists, duplication, move, course copy). The expected behaviour is a specification of the plugin and is
 * tabulated in docs/DOCUMENTATION.md; these tests enforce the table on real core flows.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\launch_processor
 * @covers     \ltisource_ltipseudonym\local\token_service
 */
final class identity_continuity_test extends \advanced_testcase {
    /**
     * Scopes with the expectation: does an activity-bound change (new activity id) change the pseudonym?
     *
     * @return array
     */
    public static function scope_provider(): array {
        return [
            'site' => ['site', false, false],
            'deployment' => ['deployment', false, false],
            'course' => ['course', false, true],
            'cm' => ['cm', true, true],
        ];
    }

    /**
     * Build the scenario: teacher in a course with a tool that supports deep linking and a pseudonymising policy.
     *
     * @param string $scope
     * @return array [course, teacher, typeid, activity]
     */
    private function scenario(string $scope): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $typeid = testing\fixture::create_type(['lti_toolurl_ContentItemSelectionRequest' => 'https://dl.example.com/select']);
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => $scope]);
        $activity = testing\fixture::create_activity($course, $typeid);
        return [$course, $teacher, $typeid, $activity];
    }

    /**
     * The sub of a deep-linking selection request.
     *
     * @param \stdClass $course
     * @param \stdClass $teacher
     * @param int $typeid
     * @return string
     */
    private function deep_linking_sub(\stdClass $course, \stdClass $teacher, int $typeid): string {
        $this->setUser($teacher);
        $built = lti_build_content_item_selection_request(
            $typeid,
            $course,
            new \moodle_url('/mod/lti/contentitem_return.php'),
            '',
            '',
            [],
            [],
            false,
            true,
            false,
            false,
            false,
            'nonce1'
        );
        return testing\fixture::decode($built->params['id_token'])['sub'];
    }

    /**
     * Deep linking happens before the activity exists. Site, deployment and course scope: the tool sees the same person in the
     * selection request and in the later launch. Activity scope: no activity exists yet, so the selection request uses the
     * course scope - a different pseudonym than the launch (documented, intended: the narrowest scope that exists).
     *
     * @dataProvider scope_provider
     * @param string $scope
     * @param bool $activitychanges whether a new activity id changes the pseudonym (unused here, shared provider)
     * @param bool $coursechanges whether another course changes the pseudonym (unused here, shared provider)
     */
    public function test_deep_linking_to_resource_link_continuity(string $scope, bool $activitychanges, bool $coursechanges): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $typeid, $activity] = $this->scenario($scope);

        $dlsub = $this->deep_linking_sub($course, $teacher, $typeid);
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));
        $launchsub = testing\fixture::launch($activity, $teacher)[2]['sub'];

        if ($scope === 'cm') {
            $this->assertNotSame($dlsub, $launchsub, 'activity scope: the selection request cannot know the future activity');
            $this->assertSame(2, $DB->count_records('ltisource_ltipseudonym_map'));
            $expected = (new token_service())->get_or_create($teacher->id, $typeid, 'course', $course->id, 'random_persisted');
            $this->assertSame($expected, $dlsub, 'the selection request used exactly the course scope pseudonym');
            $this->assertSame('cm', $DB->get_field('ltisource_ltipseudonym_map', 'scopetype', ['token' => $launchsub]));
        } else {
            $this->assertSame($dlsub, $launchsub, "$scope scope: the same person in selection request and launch");
            $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'), 'no second identity was created');
        }
    }

    /**
     * Duplicating an activity gives the copy a new course module id: only the activity scope changes the pseudonym.
     *
     * @dataProvider scope_provider
     * @param string $scope
     * @param bool $activitychanges
     * @param bool $coursechanges
     */
    public function test_duplicate_activity(string $scope, bool $activitychanges, bool $coursechanges): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $teacher, , $activity] = $this->scenario($scope);
        $original = testing\fixture::launch($activity, $teacher)[2]['sub'];
        $this->setAdminUser();
        $copy = testing\fixture::duplicate_quietly($course, $activity->cmid);
        $this->assertNotEquals($activity->cmid, $copy->cmid);
        $copied = testing\fixture::launch($copy, $teacher)[2]['sub'];
        if ($activitychanges) {
            $this->assertNotSame($original, $copied);
        } else {
            $this->assertSame($original, $copied);
        }
    }

    /**
     * Moving an activity to another section keeps its course module id, so no scope changes the pseudonym.
     *
     * @dataProvider scope_provider
     * @param string $scope
     * @param bool $activitychanges
     * @param bool $coursechanges
     */
    public function test_move_activity_between_sections(string $scope, bool $activitychanges, bool $coursechanges): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/course/lib.php');
        [$course, $teacher, , $activity] = $this->scenario($scope);
        $before = testing\fixture::launch($activity, $teacher)[2]['sub'];
        $section = course_create_section($course, 3);
        testing\fixture::move_cm_to_section($course, $activity->cmid, $section);
        $this->assertEquals($section->id, $DB->get_field('course_modules', 'section', ['id' => $activity->cmid]));
        $this->assertSame($before, testing\fixture::launch($activity, $teacher)[2]['sub']);
        $this->assertSame(1, $DB->count_records('ltisource_ltipseudonym_map'));
    }

    /**
     * Copying the activities into another course (backup and restore): the site and deployment scope keep the person, the
     * course and activity scope give the copy a new, non-correlatable pseudonym. The tool registration is kept.
     *
     * @dataProvider scope_provider
     * @param string $scope
     * @param bool $activitychanges
     * @param bool $coursechanges
     */
    public function test_import_into_another_course(string $scope, bool $activitychanges, bool $coursechanges): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $teacher, $typeid, $activity] = $this->scenario($scope);
        $target = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $target->id, 'editingteacher');
        $original = testing\fixture::launch($activity, $teacher)[2]['sub'];

        $this->setAdminUser();
        testing\fixture::import_course($course, $target);
        $cms = get_fast_modinfo($target)->get_instances_of('lti');
        $this->assertCount(1, $cms, 'the activity was restored into the target course');
        $cm = reset($cms);
        $lti = $DB->get_record('lti', ['id' => $cm->instance], '*', MUST_EXIST);
        $lti->cmid = $cm->id;
        $this->assertEquals($typeid, lti_get_instance_type($lti)->id, 'the tool registration is kept');
        $copied = testing\fixture::launch($lti, $teacher)[2]['sub'];
        if ($coursechanges) {
            $this->assertNotSame($original, $copied, "$scope scope: the copy in another course is a new identity");
        } else {
            $this->assertSame($original, $copied, "$scope scope: the person is recognised in the other course");
        }
    }

    /**
     * Another user never shares a pseudonym with the teacher in any of the moves above (no false merging).
     *
     * @dataProvider scope_provider
     * @param string $scope
     * @param bool $activitychanges
     * @param bool $coursechanges
     */
    public function test_no_false_merge_between_users(string $scope, bool $activitychanges, bool $coursechanges): void {
        $this->resetAfterTest();
        [$course, $teacher, , $activity] = $this->scenario($scope);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertNotSame(
            testing\fixture::launch($activity, $teacher)[2]['sub'],
            testing\fixture::launch($activity, $student)[2]['sub']
        );
    }

    /**
     * Rotation is the one explicit way to change the identity of everybody; the preview names the extent first, and the old
     * pseudonym is retired, not reused.
     */
    public function test_rotation_with_preview_changes_every_identity(): void {
        global $DB;
        $this->resetAfterTest();
        [, $teacher, $typeid, $activity] = $this->scenario('deployment');
        $before = testing\fixture::launch($activity, $teacher)[2]['sub'];
        $preview = local\migration_service::preview($typeid);
        $this->assertSame(1, $preview['active']);
        $this->assertSame(1, $preview['users']);
        $this->assertSame(1, local\migration_service::rotate($typeid, get_admin()->id));
        $after = testing\fixture::launch($activity, $teacher)[2]['sub'];
        $this->assertNotSame($before, $after);
        $this->assertSame('retired', $DB->get_field('ltisource_ltipseudonym_map', 'status', ['token' => $before]));
    }
}
