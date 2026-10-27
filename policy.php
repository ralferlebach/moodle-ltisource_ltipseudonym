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
 * Site administration overview of pseudonym policies and the audit trail.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login(null, false);
$context = context_system::instance();
require_capability('ltisource/ltipseudonym:managepolicy', $context);

$PAGE->set_url(new moodle_url('/mod/lti/source/ltipseudonym/policy.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('policyoverview', 'ltisource_ltipseudonym'));
$PAGE->set_heading(get_string('policyoverview', 'ltisource_ltipseudonym'));

$canmigrate = has_capability('ltisource/ltipseudonym:migrateidentity', $context);
$rows = \ltisource_ltipseudonym\local\overview_builder::build();
$orphans = \ltisource_ltipseudonym\local\migration_service::count_orphans();

/** @var \ltisource_ltipseudonym\output\renderer $renderer */
$renderer = $PAGE->get_renderer('ltisource_ltipseudonym');
echo $OUTPUT->header();
echo $renderer->render_overview(new \ltisource_ltipseudonym\output\overview($rows, $canmigrate, $orphans));

// Audit trail: who changed what, never a pseudonym.
echo $OUTPUT->heading(get_string('audittrail', 'ltisource_ltipseudonym'), 3);
$table = new html_table();
$table->head = [
    get_string('time'),
    get_string('user'),
    get_string('action'),
    get_string('tool', 'ltisource_ltipseudonym'),
    get_string('course'),
    get_string('change', 'ltisource_ltipseudonym'),
];
$records = $DB->get_records('ltisource_ltipseudonym_aud', null, 'id DESC', '*', 0, 50);
foreach ($records as $record) {
    $actor = $record->actorid ? $DB->get_record('user', ['id' => $record->actorid]) : null;
    $table->data[] = [
        userdate($record->timecreated),
        $actor ? fullname($actor) : '-',
        s($record->action),
        (int) $record->typeid,
        (int) $record->courseid . ($record->cmid ? ' / ' . (int) $record->cmid : ''),
        s(($record->oldvalue ?? '') . ' => ' . ($record->newvalue ?? '')),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
