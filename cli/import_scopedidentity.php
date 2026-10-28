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
 * CLI: import the pseudonym map of the 0.1.0 alpha (ltisource_scopedidentity) before the old plugin is uninstalled.
 *
 * Dry run by default. See docs/DOCUMENTATION.md, "Leaving the 0.1.0 alpha".
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['execute' => false, 'policies' => false, 'help' => false],
    ['h' => 'help']
);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($options['help']) {
    // Help texts of CLI scripts are not translated in core either.
    echo "Imports the pseudonym map of the 0.1.0 alpha (component ltisource_scopedidentity) into ltisource_ltipseudonym,
so that tools keep seeing the pseudonyms they already know. Run it while the old plugin is still installed (its tables
are needed), then uninstall the old plugin. Rows that exist here are never changed; conflicts are reported.

Options:
--execute     Write the import (default: dry run, nothing is written).
--policies    Also import the policies of the old plugin (a level that already has a policy here is skipped).
-h, --help    Print this help.

Example:
\$ sudo -u www-data /usr/bin/php mod/lti/source/ltipseudonym/cli/import_scopedidentity.php
\$ sudo -u www-data /usr/bin/php mod/lti/source/ltipseudonym/cli/import_scopedidentity.php --execute --policies
";
    exit(0);
}

$component = 'ltisource_ltipseudonym';
$admin = get_admin();
$report = \ltisource_ltipseudonym\local\legacy_import::run(
    !empty($options['execute']),
    !empty($options['policies']),
    $admin ? (int) $admin->id : 0
);
if (!$report->tablefound) {
    cli_writeln(get_string('cli:import:notables', $component));
    exit(0);
}
$report->mode = $report->executed ? '' : get_string('cli:import:wouldbe', $component);
cli_writeln(get_string('cli:import:report', $component, $report));
cli_writeln(get_string($report->executed ? 'cli:import:done' : 'cli:import:dryrun', $component));
exit(0);
