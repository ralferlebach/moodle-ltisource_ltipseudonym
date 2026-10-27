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

namespace ltisource_ltipseudonym\testing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/lti/locallib.php');

/**
 * Creates LTI 1.3 tool registrations and activities and performs real launches through core.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixture {
    /**
     * Create a tool registration.
     *
     * @param array $config overrides of the lti_* configuration
     * @param array $type overrides of the lti_types columns
     * @return int tool type id
     */
    public static function create_type(array $config = [], array $type = []): int {
        static $counter = 0;
        $counter++;
        $typeobj = (object) ($type + [
            'state' => LTI_TOOL_STATE_CONFIGURED,
            'name' => 'Pseudonym test tool ' . $counter,
            'description' => 'Test tool',
            'baseurl' => 'https://tool' . $counter . '.example.com/launch',
        ]);
        $cfg = (object) ($config + [
            'lti_ltiversion' => LTI_VERSION_1P3,
            'lti_toolurl' => 'https://tool' . $counter . '.example.com/launch',
            'lti_typename' => 'Pseudonym test tool ' . $counter,
            'lti_initiatelogin' => 'https://tool' . $counter . '.example.com/login',
            'lti_redirectionuris' => 'https://tool' . $counter . '.example.com/launch',
            'lti_keytype' => 'JWK_KEYSET',
            'lti_publickeyset' => 'https://tool' . $counter . '.example.com/jwks',
            'lti_sendname' => LTI_SETTING_ALWAYS,
            'lti_sendemailaddr' => LTI_SETTING_ALWAYS,
            'lti_acceptgrades' => LTI_SETTING_NEVER,
            'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
        ]);
        return (int) lti_add_type($typeobj, $cfg);
    }

    /**
     * Create an LTI activity.
     *
     * @param \stdClass $course
     * @param int $typeid
     * @param array $extra further activity fields
     * @return \stdClass activity record with ->cmid
     */
    public static function create_activity(\stdClass $course, int $typeid, array $extra = []): \stdClass {
        global $DB;
        $type = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
        $instance = \phpunit_util::get_data_generator()->create_module('lti', $extra + [
            'course' => $course->id,
            'typeid' => $typeid,
            'toolurl' => $type->baseurl,
            'instructorchoicesendname' => 1,
            'instructorchoicesendemailaddr' => 1,
            'instructorchoiceacceptgrades' => 0,
        ]);
        return $instance;
    }

    /**
     * Perform a real basic launch through mod_lti and return the decoded id token payload.
     *
     * @param \stdClass $instance LTI activity record (with ->cmid)
     * @param \stdClass $user launching user
     * @param int $foruserid optional for_user
     * @return array [endpoint, parameters, payload]
     */
    public static function launch(\stdClass $instance, \stdClass $user, int $foruserid = 0): array {
        global $PAGE, $DB;
        \core\session\manager::set_user($user);
        $course = $DB->get_record('course', ['id' => $instance->course], '*', MUST_EXIST);
        // A fresh page per launch: the theme of a page cannot be re-initialised for another course.
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/lti/view.php');
        $PAGE->set_course($course);
        $launch = clone $instance;
        [$endpoint, $params] = lti_get_launch_data($launch, 'nonce123', 'basic-lti-launch-request', $foruserid);
        return [$endpoint, $params, self::decode($params['id_token'])];
    }

    /**
     * Decode the payload of a JWT without verifying it (the tests inspect what is sent, not who signed it).
     *
     * @param string $jwt
     * @return array claims
     */
    public static function decode(string $jwt): array {
        $parts = explode('.', $jwt);
        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    }

    /**
     * Store a policy row directly, bypassing the validation (for setting up scenarios).
     *
     * @param array $fields column values; typeid/courseid/cmid select the level
     * @return int row id
     */
    public static function set_policy(array $fields): int {
        global $DB;
        $row = (object) ($fields + [
            'typeid' => 0, 'courseid' => 0, 'cmid' => 0, 'allowoverride' => 0,
            'usermodified' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        return (int) $DB->insert_record('ltisource_ltipseudonym_pol', $row);
    }

    /**
     * Duplicate an activity with the API of the running Moodle version (course_modules actions moved in 5.2).
     *
     * @param \stdClass $course course
     * @param \cm_info $cm activity
     * @return \cm_info|null the copy
     */
    public static function duplicate_cm($course, $cm) {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $actions = \core_courseformat\formatactions::cm($course->id);
        if (method_exists($actions, 'duplicate')) {
            return $actions->duplicate($cm->id);
        }
        return duplicate_module($course, $cm);
    }

    /**
     * Delete an activity with the API of the running Moodle version.
     *
     * @param int $cmid course module id
     */
    public static function delete_cm(int $cmid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $courseid = (int) get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST)->course;
        $actions = \core_courseformat\formatactions::cm($courseid);
        if (method_exists($actions, 'delete')) {
            $actions->delete($cmid);
        } else {
            course_delete_module($cmid);
        }
    }

    /**
     * Move an activity to another section with the API of the running Moodle version.
     *
     * @param \stdClass $course course
     * @param int $cmid course module id
     * @param \stdClass $section target section record
     */
    public static function move_cm_to_section(\stdClass $course, int $cmid, \stdClass $section): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $actions = \core_courseformat\formatactions::cm($course->id);
        if (method_exists($actions, 'move_end_section')) {
            $actions->move_end_section($cmid, $section->id);
            return;
        }
        moveto_module(get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST), $section);
    }

    /**
     * Duplicate an activity quietly (core backup code emits deprecations for empty elements, which are swallowed
     * only when raised inside core backup code).
     *
     * @param \stdClass $course course
     * @param int $cmid course module id
     * @return \stdClass lti record of the copy with ->cmid
     */
    public static function duplicate_quietly(\stdClass $course, int $cmid): \stdClass {
        global $DB;
        set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
            $notice = in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true);
            return $notice && strpos($errfile, DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR) !== false;
        });
        ob_start();
        try {
            $newcm = self::duplicate_cm($course, get_fast_modinfo($course)->get_cm($cmid));
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
        $lti = $DB->get_record('lti', ['id' => $newcm->instance], '*', MUST_EXIST);
        $lti->cmid = $newcm->id;
        return $lti;
    }

    /**
     * Copy the activities of a course into another existing course (backup and restore, "import").
     *
     * @param \stdClass $from source course
     * @param \stdClass $into target course (empty)
     */
    public static function import_course(\stdClass $from, \stdClass $into): void {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
            $notice = in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true);
            return $notice && strpos($errfile, DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR) !== false;
        });
        ob_start();
        try {
            $backup = new \backup_controller(
                \backup::TYPE_1COURSE,
                $from->id,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_IMPORT,
                $USER->id
            );
            $backupid = $backup->get_backupid();
            $backup->execute_plan();
            $backup->destroy();
            $restore = new \restore_controller(
                $backupid,
                $into->id,
                \backup::INTERACTIVE_NO,
                \backup::MODE_IMPORT,
                $USER->id,
                \backup::TARGET_CURRENT_ADDING
            );
            $restore->execute_precheck();
            $restore->execute_plan();
            $restore->destroy();
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
    }
}
