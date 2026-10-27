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

use ltisource_ltipseudonym\local\service_info;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Evidence for finding SP-02: core addresses users by the local id in AGS and NRPS, so a pseudonymised sub cannot be
 * matched by the core services themselves. These tests pin that behaviour of the unchanged core resources; it is the reason
 * for the service proxy, whose behaviour is tested in service_proxy_test.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\service_info
 */
final class service_compat_test extends \advanced_testcase {
    /**
     * NRPS returns the local user id as user_id of every member.
     */
    public function test_nrps_lists_local_user_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $typeid = testing\fixture::create_type(['ltiservice_memberships' => 1]);
        testing\fixture::set_policy(['typeid' => $typeid, 'pseudotype' => 'random_persisted', 'scopetype' => 'deployment']);
        $token = (new local\token_service())->get_or_create($student->id, $typeid, 'deployment', 0, 'random_persisted');

        $service = new \ltiservice_memberships\local\service\memberships();
        $service->set_type(lti_get_type($typeid));
        $service->set_typeconfig(lti_get_type_config($typeid));
        $resource = new class {
            /**
             * Endpoint stub.
             *
             * @return string
             */
            public function get_endpoint() {
                return 'https://moodle.example.com/mod/lti/services.php/CourseSection/1/bindings/1/memberships';
            }
        };
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $response = new \mod_lti\local\ltiservice\response();
        $json = $service->get_members_json(
            $resource,
            \context_course::instance($course->id),
            $course,
            '',
            0,
            0,
            null,
            null,
            $response
        );
        $members = json_decode($json)->members;
        $ids = array_map(fn($m) => (string) $m->user_id, $members);
        $this->assertContains((string) $student->id, $ids, 'finding: the roster carries the local id');
        $this->assertNotContains($token, $ids, 'finding: the pseudonym is unknown to the roster');
    }

    /**
     * AGS accepts scores for local user ids only; the pseudonym is not a gradable user.
     */
    public function test_ags_accepts_only_local_user_ids(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $typeid = testing\fixture::create_type(['ltiservice_gradesynchronization' => 1]);
        $token = (new local\token_service())->get_or_create($student->id, $typeid, 'deployment', 0, 'random_persisted');
        $service = '\\ltiservice_gradebookservices\\local\\service\\gradebookservices';
        $this->assertTrue($service::is_user_gradable_in_course($course->id, $student->id));
        try {
            $this->assertFalse(
                $service::is_user_gradable_in_course($course->id, $token),
                'finding: a score for the pseudonym is rejected'
            );
        } catch (\coding_exception $e) {
            // Also a valid outcome: core refuses a non-numeric user id outright.
            $this->assertStringContainsString('isguestuser', $e->getMessage());
        }
    }

    /**
     * The score endpoint stores the grade under the local user id taken from the userId of the payload.
     */
    public function test_ags_scores_endpoint_writes_to_the_local_user(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/gradelib.php');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $typeid = testing\fixture::create_type(['ltiservice_gradesynchronization' => 1]);
        $activity = testing\fixture::create_activity($course, $typeid);

        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'lti',
            'iteminstance' => $activity->id]);
        if (!$item) {
            $item = new \grade_item(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'lti',
                'iteminstance' => $activity->id, 'itemname' => 'lti', 'grademax' => 100], false);
            $item->insert();
        }
        $service = new \ltiservice_gradebookservices\local\service\gradebookservices();
        $service->set_type(lti_get_type($typeid));
        $resource = new \ltiservice_gradebookservices\local\resources\scores($service);
        $method = new \ReflectionMethod($resource, 'get_json_for_post_request');
        $method->setAccessible(true);
        // Keep other plugins' event observers out of this evidence test.
        $sink = $this->redirectEvents();
        $score = fn($userid) => json_encode([
            'userId' => (string) $userid, 'timestamp' => date('c', time() + 60), 'scoreGiven' => 50, 'scoreMaximum' => 100,
            'gradingProgress' => 'FullyGraded', 'activityProgress' => 'Completed',
        ]);

        $token = (new local\token_service())->get_or_create($student->id, $typeid, 'deployment', 0, 'random_persisted');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = new \mod_lti\local\ltiservice\response();
        $rejected = false;
        try {
            $method->invoke($resource, $response, $score($token), $item, $course->id, (string) $typeid);
        } catch (\Throwable $e) {
            // Finding: AGS rejects (HTTP 400) or fails on the pseudonym; it never resolves it to a user.
            $rejected = true;
        }
        $this->assertTrue($rejected, 'a score addressed to the pseudonym must be rejected');
        $this->assertFalse($DB->record_exists('grade_grades', ['itemid' => $item->id, 'userid' => $student->id]));

        $method->invoke($resource, $response, $score($student->id), $item, $course->id, (string) $typeid);
        $this->assertTrue(
            $DB->record_exists('grade_grades', ['itemid' => $item->id, 'userid' => $student->id]),
            'finding: the grade is stored under the local user id'
        );
    }

    /**
     * The overview information recognises exactly the tools that have AGS or NRPS enabled.
     */
    public function test_service_info_detects_services(): void {
        $this->resetAfterTest();
        $plain = testing\fixture::create_type();
        $ags = testing\fixture::create_type(['ltiservice_gradesynchronization' => 2]);
        $nrps = testing\fixture::create_type(['ltiservice_memberships' => 1]);
        $both = testing\fixture::create_type(['ltiservice_gradesynchronization' => 1, 'ltiservice_memberships' => 1]);
        $this->assertSame([], service_info::services_of_type($plain));
        $this->assertSame(['ags'], service_info::services_of_type($ags));
        $this->assertSame(['nrps'], service_info::services_of_type($nrps));
        $this->assertSame(['ags', 'nrps'], service_info::services_of_type($both));
    }
}
