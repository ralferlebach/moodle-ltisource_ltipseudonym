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

namespace ltisource_ltipseudonym\task;

/**
 * Scheduled task purging retired mappings.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Deletes mappings retired by a rotation once the retention period has passed.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_retired extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:purgeretired', 'ltisource_ltipseudonym');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        $days = \ltisource_ltipseudonym\local\config::retired_days();
        if ($days <= 0) {
            mtrace('Retention is unlimited (retireddays = 0): nothing to purge.');
            return;
        }
        $count = \ltisource_ltipseudonym\local\migration_service::purge_retired(time() - $days * DAYSECS);
        mtrace("Purged $count retired mapping(s) older than $days day(s).");
    }
}
