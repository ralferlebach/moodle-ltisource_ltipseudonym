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
 * Library functions and mod_lti callbacks of ltisource_ltipseudonym.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Callback of mod_lti: called for every basic launch and every deep-linking request.
 *
 * mod_lti merges the returned array over the launch parameters with array_merge() and signs the
 * result afterwards. The callback therefore can replace values (user_id becomes the JWT sub) but can
 * never remove a parameter; see docs/DOCUMENTATION.md for the evidence.
 *
 * @param object $instance LTI activity record (a bare object with ->course while selecting content)
 * @param string $endpoint tool endpoint URL
 * @param array $requestparams launch parameters assembled by core so far
 * @return array parameters overriding the core values
 * @throws moodle_exception when the launch must be refused (fail closed)
 */
function ltisource_ltipseudonym_before_launch($instance, $endpoint, $requestparams) {
    $processor = new \ltisource_ltipseudonym\local\launch_processor();
    return $processor->process($instance, (string) $endpoint, (array) $requestparams);
}

/**
 * Add the course level policy page to the course navigation.
 *
 * Core calls extend_navigation_course of every plugin type (get_plugins_with_function). There is
 * no equivalent hook for the settings form of a single LTI activity (gate SP-G4): activity
 * overrides are therefore reached from this course page.
 *
 * @param navigation_node $navigation course navigation node
 * @param stdClass $course
 * @param context_course $context
 */
function ltisource_ltipseudonym_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('ltisource/ltipseudonym:view', $context)) {
        return;
    }
    $url = new moodle_url('/mod/lti/source/ltipseudonym/course.php', ['id' => $course->id]);
    $navigation->add(
        get_string('coursepolicy', 'ltisource_ltipseudonym'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'ltisource_ltipseudonym',
        new pix_icon('i/permissions', '')
    );
}
