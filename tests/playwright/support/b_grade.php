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
 * Test support, not part of the plugin runtime: gives a user of the tool site (B) a grade in a manual grade item of the shared
 * course, so that the grade synchronisation of enrol_lti has something to send to the platform.
 *
 * Run from the root of the tool site: php /path/to/b_grade.php --userid=N --courseid=N --grade=N
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(getcwd() . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');

[$options] = cli_get_params(['userid' => 0, 'courseid' => 0, 'grade' => 0], []);
$userid = (int) $options['userid'];
$courseid = (int) $options['courseid'];
if ($userid <= 0 || $courseid <= 0) {
    cli_error('--userid and --courseid are required');
}
$name = 'E2E pseudonym';
$item = grade_item::fetch(['courseid' => $courseid, 'itemtype' => 'manual', 'itemname' => $name]);
if (!$item) {
    $item = new grade_item(['courseid' => $courseid, 'itemtype' => 'manual', 'itemname' => $name, 'grademax' => 100,
        'gradetype' => GRADE_TYPE_VALUE], false);
    $item->insert();
}
$item->update_final_grade($userid, (float) $options['grade'], 'e2e');
grade_regrade_final_grades($courseid);
echo "ok\n";
