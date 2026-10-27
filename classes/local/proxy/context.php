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

namespace ltisource_ltipseudonym\local\proxy;

/**
 * The identity context of one service request: tool, course, activity and the effective policy that
 * the launch of that activity would have applied.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class context {
    /** @var int Tool type the bearer token was issued to. */
    public $typeid;
    /** @var int Course of the request. */
    public $courseid;
    /** @var int Course module of the addressed activity, 0 when the request is not bound to an activity. */
    public $cmid;
    /** @var \stdClass Effective policy (see policy_manager::resolve()). */
    public $policy;

    /**
     * Constructor.
     *
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @param \stdClass $policy
     */
    public function __construct(int $typeid, int $courseid, int $cmid, \stdClass $policy) {
        $this->typeid = $typeid;
        $this->courseid = $courseid;
        $this->cmid = $cmid;
        $this->policy = $policy;
    }

    /**
     * Whether the user identifiers of the services are replaced by pseudonyms.
     *
     * @return bool
     */
    public function pseudonymising(): bool {
        return !empty($this->policy->pseudonymising);
    }
}
