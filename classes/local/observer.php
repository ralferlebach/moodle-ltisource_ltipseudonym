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

namespace ltisource_ltipseudonym\local;

/**
 * Event observers cleaning up local data.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Observers for deleted users, courses and course modules.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A user was deleted: their mappings are meaningless now and are removed.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        $DB->delete_records('ltisource_ltipseudonym_map', ['userid' => $event->objectid]);
    }

    /**
     * A course was deleted: its policies and the course scoped mappings are removed.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $DB->delete_records('ltisource_ltipseudonym_pol', ['courseid' => $event->objectid]);
        $DB->delete_records('ltisource_ltipseudonym_map', [
            'scopetype' => catalogue::SCOPE_COURSE,
            'scopeid' => $event->objectid,
        ]);
    }

    /**
     * A course module was deleted: its policy and the module scoped mappings are removed.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        $DB->delete_records('ltisource_ltipseudonym_pol', ['cmid' => $event->objectid]);
        $DB->delete_records('ltisource_ltipseudonym_map', ['scopetype' => catalogue::SCOPE_CM, 'scopeid' => $event->objectid]);
    }
}
