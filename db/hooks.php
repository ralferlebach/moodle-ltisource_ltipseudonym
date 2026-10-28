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
 * Hook callbacks of ltisource_ltipseudonym.
 *
 * Core hook after_config: the request guard (service proxy for direct calls of mod/lti/services.php, prediction of refused
 * launches before the OIDC login initiation), see classes/local/request_guard.php.
 *
 * Hooks of tool_mergeusers. When tool_mergeusers is not installed the hook classes do not exist; core registers the
 * callbacks without checking the class and never dispatches them (see tests/merge_integration_test.php).
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\after_config::class,
        'callback' => [\ltisource_ltipseudonym\local\request_guard::class, 'after_config'],
    ],
    [
        'hook' => 'tool_mergeusers\hook\add_settings_before_merging',
        'callback' => [\ltisource_ltipseudonym\local\merge_integration::class, 'add_settings_before_merging'],
    ],
    [
        'hook' => 'tool_mergeusers\hook\after_merged_all_tables',
        'callback' => [\ltisource_ltipseudonym\local\merge_integration::class, 'after_merged_all_tables'],
    ],
];
