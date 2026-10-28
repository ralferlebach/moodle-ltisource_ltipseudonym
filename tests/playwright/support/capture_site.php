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

/**
 * Test support, not part of the plugin runtime: fixtures of the protocol capture (disclosure_capture.spec.js) on a platform
 * site of the two-site stack (A = 4.5 or B = 5.3).
 *
 * The capture tool is played by Playwright: its URLs live on the host capture.tool-c.test, which the browser never reaches
 * (Playwright intercepts every request to it). Its public key is the one of the key pair the spec generates.
 *
 * Run from the root of the site: php /path/to/capture_site.php --setup --course=N --publickey=/path/key.pem
 * Other modes: --policy='<json>' (rows for the capture registration, key "site" for a site row), --setting=name=value,
 * --lookup=<pseudonym>, --grade --cmid=N --userid=N, --misses (audit rows of the proxy), --cleanup.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(getcwd() . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/gradelib.php');

[$options] = cli_get_params([
    'setup' => false, 'course' => 0, 'publickey' => '', 'policy' => '', 'setting' => '', 'lookup' => '', 'grade' => false,
    'cmid' => 0, 'userid' => 0, 'misses' => false, 'cleanup' => false, 'cleargrades' => false,
], []);

/** Name prefix of everything this script creates. */
const PSCAP = 'pscap';
/** Password of the capture users (the stack has no password policy). */
const PSCAP_PASSWORD = 'Pscap!Pass-2026';

/**
 * The capture registrations (LTI 1.3 and LTI 1.1), by name.
 *
 * @return array name => lti_types record
 */
function pscap_types(): array {
    global $DB;
    $types = [];
    foreach ($DB->get_records_select('lti_types', $DB->sql_like('name', ':n'), ['n' => 'Pseudonym capture tool%']) as $type) {
        $types[$type->name] = $type;
    }
    return $types;
}

/**
 * Create or update a user of the capture.
 *
 * @param string $key
 * @param string $first
 * @param string $last
 * @return stdClass
 */
function pscap_user(string $key, string $first, string $last): stdClass {
    global $DB, $CFG;
    $username = PSCAP . $key;
    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $id = user_create_user((object) ['username' => $username, 'password' => PSCAP_PASSWORD, 'firstname' => $first,
            'lastname' => $last, 'email' => $username . '@example.com', 'idnumber' => 'ID-' . strtoupper($username),
            'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id], true, false);
        $user = $DB->get_record('user', ['id' => $id]);
    }
    return $user;
}

/**
 * Create an LTI activity in a course.
 *
 * @param stdClass $course
 * @param int $typeid
 * @param string $name
 * @return stdClass record with ->cmid
 */
function pscap_activity(stdClass $course, int $typeid, string $name): stdClass {
    global $DB;
    $existing = $DB->get_record('lti', ['course' => $course->id, 'name' => $name]);
    if ($existing) {
        $existing->cmid = get_coursemodule_from_instance('lti', $existing->id, $course->id, false, MUST_EXIST)->id;
        return $existing;
    }
    $moduleinfo = (object) [
        'modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti'], MUST_EXIST),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => $name,
        'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0], 'showdescription' => 0,
        'typeid' => $typeid, 'toolurl' => '', 'securetoolurl' => '',
        'instructorcustomparameters' => 'resultid=$Result.sourcedId',
        'launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW,
        'instructorchoicesendname' => 1, 'instructorchoicesendemailaddr' => 1, 'instructorchoiceacceptgrades' => 1,
        'instructorchoiceallowroster' => 1, 'instructorchoiceallowsetting' => 0, 'grade' => 100, 'resourcekey' => '',
        'password' => '', 'cmidnumber' => '', 'visibleoncoursepage' => 1, 'completion' => 0,
    ];
    $created = add_moduleinfo($moduleinfo, $course);
    $lti = $DB->get_record('lti', ['id' => $created->instance]);
    $lti->cmid = $created->coursemodule;
    return $lti;
}

/**
 * Create the fixtures (idempotent) and return their description.
 *
 * @param int $courseid
 * @param string $publickey PEM
 * @return array
 */
function pscap_setup(int $courseid, string $publickey): array {
    global $DB, $CFG;
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $admin = get_admin();
    \core\session\manager::set_user($admin);
    $users = ['learner' => pscap_user('learner', 'Paula', 'Learner'), 'mate' => pscap_user('mate', 'Peter', 'Mate'),
        'teacher' => pscap_user('teacher', 'Petra', 'Teacher')];
    $enrol = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    foreach ($users as $key => $user) {
        $role = $DB->get_field('role', 'id', ['shortname' => $key === 'teacher' ? 'editingteacher' : 'student']);
        $enrol->enrol_user($instance, $user->id, $role);
    }
    $custom = "userid=\$User.id\nusername=\$User.username\nemail=\$Person.email.primary\nname=\$Person.name.full\n" .
        "idnumber=\$Person.sourcedId";
    $types = pscap_types();
    $base = 'https://capture.tool-c.test';
    $defs = [
        'Pseudonym capture tool' => ['lti_ltiversion' => LTI_VERSION_1P3, 'lti_keytype' => LTI_RSA_KEY,
            'lti_publickey' => $publickey, 'lti_initiatelogin' => $base . '/login', 'lti_redirectionuris' => $base . '/launch',
            'lti_toolurl' => $base . '/launch', 'lti_contentitem' => 1, 'lti_toolurl_ContentItemSelectionRequest' => $base . '/dl'],
        'Pseudonym capture tool 1.1' => ['lti_ltiversion' => LTI_VERSION_1, 'lti_toolurl' => $base . '/lti11',
            'lti_resourcekey' => 'pscapkey', 'lti_password' => 'pscapsecret'],
    ];
    $out = ['users' => [], 'site' => $CFG->wwwroot, 'release' => $CFG->release, 'courseid' => (int) $course->id];
    foreach ($defs as $name => $def) {
        $config = (object) ($def + [
            'lti_typename' => $name, 'lti_coursevisible' => LTI_COURSEVISIBLE_PRECONFIGURED,
            'lti_sendname' => LTI_SETTING_ALWAYS, 'lti_sendemailaddr' => LTI_SETTING_ALWAYS,
            'lti_acceptgrades' => LTI_SETTING_ALWAYS, 'lti_customparameters' => $custom,
            'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW, 'lti_forcessl' => 0,
            'ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1,
        ]);
        if (isset($types[$name])) {
            $typeid = (int) $types[$name]->id;
            if ($def['lti_ltiversion'] === LTI_VERSION_1P3) {
                // A new key pair per run: update the public key.
                $DB->set_field('lti_types_config', 'value', $publickey, ['typeid' => $typeid, 'name' => 'publickey']);
            }
        } else {
            $type = (object) ['state' => LTI_TOOL_STATE_CONFIGURED, 'name' => $name, 'baseurl' => $def['lti_toolurl'],
                'coursevisible' => LTI_COURSEVISIBLE_PRECONFIGURED, 'description' => 'Protocol capture of ltisource_ltipseudonym'];
            $typeid = (int) lti_add_type($type, $config);
        }
        $type = $DB->get_record('lti_types', ['id' => $typeid]);
        $activity = pscap_activity($course, $typeid, $name . ' activity');
        $key = ($def['lti_ltiversion'] === LTI_VERSION_1P3) ? 'lti13' : 'lti11';
        $out[$key] = ['typeid' => $typeid, 'clientid' => $type->clientid, 'deploymentid' => (string) $typeid,
            'cmid' => (int) $activity->cmid, 'instance' => (int) $activity->id];
    }
    foreach ($users as $key => $user) {
        $out['users'][$key] = ['id' => (int) $user->id, 'username' => $user->username, 'password' => PSCAP_PASSWORD,
            'firstname' => $user->firstname, 'lastname' => $user->lastname, 'email' => $user->email,
            'idnumber' => $user->idnumber];
    }
    $out['token_url'] = $CFG->wwwroot . '/mod/lti/token.php';
    $out['auth_url'] = $CFG->wwwroot . '/mod/lti/auth.php';
    purge_caches(['muc' => true]);
    return $out;
}

/**
 * Replace the policies of the capture registrations (and optionally the site row).
 *
 * @param array $policy columns of the tool-level row; "site" => columns of a site row; empty array = no policy
 */
function pscap_policy(array $policy): void {
    global $DB;
    $ids = array_map(fn($t) => (int) $t->id, pscap_types());
    $DB->delete_records_list('ltisource_ltipseudonym_pol', 'typeid', array_merge($ids, [0]));
    foreach ($DB->get_records_select('lti', 'typeid IN (' . implode(',', $ids ?: [0]) . ')') as $lti) {
        $cm = get_coursemodule_from_instance('lti', $lti->id, $lti->course, false, IGNORE_MISSING);
        if ($cm) {
            $DB->delete_records('ltisource_ltipseudonym_pol', ['cmid' => $cm->id]);
        }
    }
    $site = $policy['site'] ?? null;
    unset($policy['site']);
    $now = time();
    $defaults = ['courseid' => 0, 'cmid' => 0, 'allowoverride' => 0, 'usermodified' => 0, 'timecreated' => $now,
        'timemodified' => $now];
    if ($site !== null) {
        $DB->insert_record('ltisource_ltipseudonym_pol', (object) (['typeid' => 0] + $site + $defaults));
    }
    if ($policy) {
        foreach (pscap_types() as $name => $type) {
            if ($type->ltiversion === LTI_VERSION_1P3) {
                $DB->insert_record('ltisource_ltipseudonym_pol', (object) (['typeid' => $type->id] + $policy + $defaults));
            }
        }
    }
}

/**
 * Remove everything this script created.
 */
function pscap_cleanup(): void {
    global $DB;
    pscap_policy([]);
    foreach (pscap_types() as $type) {
        foreach ($DB->get_records('lti', ['typeid' => $type->id]) as $lti) {
            $cm = get_coursemodule_from_instance('lti', $lti->id, $lti->course, false, IGNORE_MISSING);
            if ($cm) {
                course_delete_module($cm->id);
            }
        }
        $DB->delete_records('ltisource_ltipseudonym_map', ['typeid' => $type->id]);
        lti_delete_type($type->id);
    }
    foreach ($DB->get_records_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 0', ['u' => PSCAP . '%']) as $user) {
        delete_user($user);
    }
    unset_config('legacyprotocol', 'ltisource_ltipseudonym');
    set_config('legacyprotocol', 'pass', 'ltisource_ltipseudonym');
}

$result = [];
if ($options['setup']) {
    $result = pscap_setup((int) $options['course'], (string) file_get_contents($options['publickey']));
} else if ($options['policy'] !== '') {
    pscap_policy(json_decode($options['policy'], true) ?? []);
    $result = ['ok' => true];
} else if ($options['setting'] !== '') {
    [$name, $value] = explode('=', $options['setting'], 2);
    set_config($name, $value, 'ltisource_ltipseudonym');
    $result = ['ok' => true];
} else if ($options['lookup'] !== '') {
    $row = $DB->get_record('ltisource_ltipseudonym_map', ['token' => $options['lookup']]);
    $result = $row ? ['exists' => true, 'userid' => (int) $row->userid, 'status' => $row->status, 'scopetype' => $row->scopetype,
        'typeid' => (int) $row->typeid] : ['exists' => false];
} else if ($options['grade']) {
    $cm = get_coursemodule_from_id('lti', (int) $options['cmid'], 0, false, MUST_EXIST);
    $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'lti', 'iteminstance' => $cm->instance,
        'courseid' => $cm->course]);
    $grade = $item ? $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => (int) $options['userid']]) :
        false;
    $result = ['grade' => ($grade === false || $grade === null) ? null : (float) $grade];
} else if ($options['cleargrades']) {
    // Remove the grades of the capture activity, so that every case starts from "no grade".
    $cm = get_coursemodule_from_id('lti', (int) $options['cmid'], 0, false, MUST_EXIST);
    $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'lti', 'iteminstance' => $cm->instance,
        'courseid' => $cm->course]);
    if ($item) {
        $DB->delete_records('grade_grades', ['itemid' => $item->id]);
    }
    $result = ['ok' => true];
} else if ($options['misses']) {
    $result = ['reasons' => array_count_values(array_map(
        fn($r) => json_decode($r->newvalue)->reason ?? '?',
        $DB->get_records('ltisource_ltipseudonym_aud', ['action' => 'service_pseudonym_miss'])
    ))];
} else if ($options['cleanup']) {
    pscap_cleanup();
    $result = ['ok' => true];
}
echo json_encode($result) . "\n";
