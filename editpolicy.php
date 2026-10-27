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
 * Editor for the policy of the site, a tool type, a course or a course module.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

use ltisource_ltipseudonym\local\catalogue;
use ltisource_ltipseudonym\local\config;
use ltisource_ltipseudonym\local\policy_manager;

$typeid = optional_param('typeid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_BOOL);

require_login(null, false);

// A course module belongs to exactly one tool and course: derive both and ignore what the URL claims.
if ($cmid > 0) {
    $cm = get_coursemodule_from_id('lti', $cmid, 0, false, MUST_EXIST);
    $courseid = (int) $cm->course;
    $lti = $DB->get_record('lti', ['id' => $cm->instance], '*', MUST_EXIST);
    $type = lti_get_instance_type($lti);
    $typeid = $type ? (int) $type->id : 0;
    if (!$typeid) {
        throw new moodle_exception('policyrejected', 'ltisource_ltipseudonym', '', 'activity without tool registration');
    }
}
$level = policy_manager::level_of($typeid, $courseid, $cmid);
if ($level === policy_manager::LEVEL_COURSE || $level === policy_manager::LEVEL_CM) {
    $course = get_course($courseid);
    require_login($course, false);
    $context = context_course::instance($courseid);
    require_capability('ltisource/ltipseudonym:managecoursepolicy', $context);
    $returnurl = new moodle_url('/mod/lti/source/ltipseudonym/course.php', ['id' => $courseid]);
} else {
    $context = context_system::instance();
    require_capability('ltisource/ltipseudonym:managepolicy', $context);
    $returnurl = new moodle_url('/mod/lti/source/ltipseudonym/policy.php');
}
if ($typeid > 0 && !$DB->record_exists('lti_types', ['id' => $typeid, 'ltiversion' => LTI_VERSION_1P3])) {
    throw new moodle_exception('notlti13', 'ltisource_ltipseudonym');
}

$url = new moodle_url(
    '/mod/lti/source/ltipseudonym/editpolicy.php',
    ['typeid' => $typeid, 'courseid' => $courseid, 'cmid' => $cmid]
);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('editpolicy', 'ltisource_ltipseudonym'));
$PAGE->set_heading(get_string('editpolicy', 'ltisource_ltipseudonym'));

if ($delete) {
    require_sesskey();
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        policy_manager::delete($typeid, $courseid, $cmid, $USER->id);
        redirect(
            $returnurl,
            get_string('policydeleted', 'ltisource_ltipseudonym'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'ltisource_ltipseudonym'),
        new moodle_url($url, ['delete' => 1, 'confirm' => 1, 'sesskey' => sesskey()]),
        $returnurl
    );
    echo $OUTPUT->footer();
    exit;
}

$stored = policy_manager::get_row($typeid, $courseid, $cmid);
$form = new \ltisource_ltipseudonym\form\policy_form($url, [
    'typeid' => $typeid,
    'courseid' => $courseid,
    'cmid' => $cmid,
    'stored' => $stored,
    'effectivebefore' => policy_manager::resolve($typeid, $courseid, $cmid),
    'activemappings' => $DB->count_records(
        'ltisource_ltipseudonym_map',
        ['status' => 'active'] + ($typeid ? ['typeid' => $typeid] : [])
    ),
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    try {
        policy_manager::save($typeid, $courseid, $cmid, $data, $USER->id);
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(
        $returnurl,
        get_string('policysaved', 'ltisource_ltipseudonym'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
$name = get_string('sitedefault', 'ltisource_ltipseudonym');
if ($typeid) {
    $name = format_string($DB->get_field('lti_types', 'name', ['id' => $typeid]));
}
echo $OUTPUT->heading(get_string('level:' . $level, 'ltisource_ltipseudonym') . ': ' . $name, 3);
if ($typeid > 0) {
    $services = \ltisource_ltipseudonym\local\service_info::services_of_type($typeid);
    if ($services) {
        echo $OUTPUT->notification(get_string(
            'servicesinfo',
            'ltisource_ltipseudonym',
            implode(', ', array_map('strtoupper', $services))
        ), \core\output\notification::NOTIFY_INFO);
    }
}
$form->display();
if ($stored) {
    echo $OUTPUT->single_button(new moodle_url($url, ['delete' => 1]), get_string('resettoinherit', 'ltisource_ltipseudonym'));
}
echo $OUTPUT->footer();
