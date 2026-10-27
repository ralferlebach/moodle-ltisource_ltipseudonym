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
 * Explicit migration: preview, rotation, re-binding and purging of orphaned mappings.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

use ltisource_ltipseudonym\local\migration_service;

$typeid = optional_param('typeid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$fromtypeid = optional_param('fromtypeid', 0, PARAM_INT);

require_login(null, false);
$context = context_system::instance();
require_capability('ltisource/ltipseudonym:migrateidentity', $context);

$url = new moodle_url('/mod/lti/source/ltipseudonym/migrate.php', ['typeid' => $typeid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('migrate', 'ltisource_ltipseudonym'));
$PAGE->set_heading(get_string('migrate', 'ltisource_ltipseudonym'));
$back = new moodle_url('/mod/lti/source/ltipseudonym/policy.php');

if ($action !== '') {
    // Every state changing action needs sesskey and an explicit confirmation flag.
    require_sesskey();
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        echo $OUTPUT->header();
        $message = get_string('confirmaction:' . $action, 'ltisource_ltipseudonym');
        echo $OUTPUT->confirm(
            $message,
            new moodle_url($url, ['action' => $action, 'fromtypeid' => $fromtypeid, 'confirm' => 1, 'sesskey' => sesskey()]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }
    try {
        if ($action === 'rotate') {
            $count = migration_service::rotate($typeid, $USER->id);
        } else if ($action === 'rebind') {
            $count = migration_service::rebind($fromtypeid, $typeid, $USER->id);
        } else if ($action === 'purgeorphans') {
            $count = migration_service::purge_orphans($USER->id);
        } else {
            throw new moodle_exception('invalidaction', 'error');
        }
        redirect(
            $url,
            get_string('migrationdone', 'ltisource_ltipseudonym', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
$preview = migration_service::preview($typeid);
echo $OUTPUT->heading(get_string('migratepreview', 'ltisource_ltipseudonym'), 3);
echo html_writer::tag('p', get_string('previewcounts', 'ltisource_ltipseudonym', (object) $preview));
echo $OUTPUT->notification(get_string('migratewarning', 'ltisource_ltipseudonym'), \core\output\notification::NOTIFY_WARNING);

echo $OUTPUT->single_button(
    new moodle_url($url, ['action' => 'rotate', 'sesskey' => sesskey()]),
    get_string('rotate', 'ltisource_ltipseudonym')
);

if ($typeid > 0) {
    // Candidates for re-binding: mappings whose tool registration no longer exists.
    $orphantypes = $DB->get_fieldset_sql(
        "SELECT DISTINCT m.typeid FROM {ltisource_ltipseudonym_map} m
          WHERE m.typeid > 0 AND NOT EXISTS (SELECT 1 FROM {lti_types} t WHERE t.id = m.typeid)"
    );
    foreach ($orphantypes as $old) {
        $rb = migration_service::rebind_preview((int) $old, $typeid);
        echo html_writer::tag('p', get_string(
            'rebindpreview',
            'ltisource_ltipseudonym',
            (object) ['from' => (int) $old, 'move' => $rb['move'], 'conflicts' => $rb['conflicts']]
        ));
        if ($rb['conflicts'] == 0) {
            echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'rebind', 'fromtypeid' => $old,
                'sesskey' => sesskey()]), get_string('rebind', 'ltisource_ltipseudonym'));
        }
    }
}
if (migration_service::count_orphans() > 0) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'purgeorphans', 'sesskey' => sesskey()]),
        get_string('purgeorphans', 'ltisource_ltipseudonym')
    );
}
echo $OUTPUT->single_button($back, get_string('back'), 'get');
echo $OUTPUT->footer();
