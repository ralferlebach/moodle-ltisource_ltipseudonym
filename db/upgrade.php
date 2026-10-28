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
 * Upgrade steps for ltisource_ltipseudonym.
 *
 * Release 0.2.0 (2026102502) is the first release under this component name (renamed, see docs/CHANGELOG.md). There is no
 * upgrade path from the 0.1.0 alpha of the old component ltisource_scopedidentity; its pseudonym map can be imported with
 * cli/import_scopedidentity.php before the old plugin is uninstalled (docs/DOCUMENTATION.md, "Leaving the 0.1.0 alpha").
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the ltisource_ltipseudonym plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_ltisource_ltipseudonym_upgrade($oldversion) {
    if ($oldversion < 2026102700) {
        // 0.3.0: new setting "legacyprotocol" (LTI 1.x launches under an active policy). Written explicitly with the value
        // that keeps the behaviour of 0.2.0 (pass: LTI 1.x launches are delivered unchanged), so that an upgraded site does
        // not start refusing launches until an administrator decides so. No schema change in 0.3.0.
        if (get_config('ltisource_ltipseudonym', 'legacyprotocol') === false) {
            set_config('legacyprotocol', 'pass', 'ltisource_ltipseudonym');
        }
        upgrade_plugin_savepoint(true, 2026102700, 'ltisource', 'ltipseudonym');
    }
    return true;
}
