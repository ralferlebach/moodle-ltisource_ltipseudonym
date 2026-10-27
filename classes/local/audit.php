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
 * Audit trail writer.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Writes the audit trail and triggers the matching event. Neither ever contains a pseudonym.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit {
    /**
     * Record one change.
     *
     * @param string $action machine name of the action
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @param int $actorid acting user
     * @param string|null $old JSON snapshot before
     * @param string|null $new JSON snapshot after
     * @return int audit record id
     */
    public static function log(
        string $action,
        int $typeid,
        int $courseid,
        int $cmid,
        int $actorid,
        ?string $old,
        ?string $new
    ): int {
        global $DB;
        $record = (object) [
            'timecreated' => time(),
            'actorid' => $actorid,
            'typeid' => $typeid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'action' => $action,
            'oldvalue' => $old,
            'newvalue' => $new,
        ];
        $record->id = $DB->insert_record('ltisource_ltipseudonym_aud', $record);

        $context = \context_system::instance();
        if ($cmid > 0) {
            $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id', IGNORE_MISSING);
            $context = $cm ? \context_module::instance($cmid) : $context;
        } else if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
            $context = \context_course::instance($courseid);
        }
        $event = \ltisource_ltipseudonym\event\policy_changed::create([
            'context' => $context,
            'objectid' => $record->id,
            'userid' => $actorid,
            'other' => ['action' => $action, 'typeid' => $typeid, 'courseid' => $courseid, 'cmid' => $cmid],
        ]);
        $event->trigger();
        return (int) $record->id;
    }
}
