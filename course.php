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
 * Course level view of the pseudonym policy of the LTI activities.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

use ltisource_ltipseudonym\local\catalogue;
use ltisource_ltipseudonym\local\policy_manager;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('ltisource/ltipseudonym:view', $context);
$canedit = has_capability('ltisource/ltipseudonym:managecoursepolicy', $context);

$PAGE->set_url(new moodle_url('/mod/lti/source/ltipseudonym/course.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursepolicy', 'ltisource_ltipseudonym'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursepolicy', 'ltisource_ltipseudonym'));
echo $OUTPUT->notification(get_string('loginhintnotice', 'ltisource_ltipseudonym'), \core\output\notification::NOTIFY_WARNING);
echo html_writer::tag('p', get_string('activityuigate', 'ltisource_ltipseudonym'));

$table = new html_table();
$table->head = [get_string('activity'), get_string('tool', 'ltisource_ltipseudonym'),
    get_string('pseudotype', 'ltisource_ltipseudonym'), get_string('scopetype', 'ltisource_ltipseudonym'),
    get_string('actions', 'ltisource_ltipseudonym')];
$modinfo = get_fast_modinfo($course);
foreach ($modinfo->get_instances_of('lti') as $cm) {
    $lti = $DB->get_record('lti', ['id' => $cm->instance]);
    $type = $lti ? lti_get_instance_type($lti) : null;
    if (!$type || $type->ltiversion !== LTI_VERSION_1P3) {
        continue;
    }
    $eff = policy_manager::resolve((int) $type->id, $courseid, (int) $cm->id);
    $actions = '';
    if ($canedit) {
        $actions = html_writer::link(new moodle_url(
            '/mod/lti/source/ltipseudonym/editpolicy.php',
            ['cmid' => $cm->id]
        ), get_string('editactivity', 'ltisource_ltipseudonym'));
    }
    $table->data[] = [
        format_string($cm->name),
        format_string($type->name),
        get_string('type:' . $eff->pseudotype, 'ltisource_ltipseudonym'),
        get_string('scope:' . $eff->scopetype, 'ltisource_ltipseudonym'),
        $actions,
    ];
}
echo html_writer::table($table);

// Course level overrides, one per tool registration used in this course.
echo $OUTPUT->heading(get_string('courseoverrides', 'ltisource_ltipseudonym'), 3);
$typeids = [];
foreach ($modinfo->get_instances_of('lti') as $cm) {
    $lti = $DB->get_record('lti', ['id' => $cm->instance]);
    $type = $lti ? lti_get_instance_type($lti) : null;
    if ($type && $type->ltiversion === LTI_VERSION_1P3) {
        $typeids[(int) $type->id] = format_string($type->name);
    }
}
$list = [];
foreach ($typeids as $typeid => $name) {
    $link = $name;
    if ($canedit) {
        $link = html_writer::link(new moodle_url(
            '/mod/lti/source/ltipseudonym/editpolicy.php',
            ['typeid' => $typeid, 'courseid' => $courseid]
        ), $name);
    }
    $list[] = $link;
}
echo $list ? html_writer::alist($list) : html_writer::tag('p', get_string('nolti13activities', 'ltisource_ltipseudonym'));
echo $OUTPUT->footer();
