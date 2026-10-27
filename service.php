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
 * Identity-aware LTI service endpoint (memberships and grade service) for pseudonymised launches.
 *
 * Same paths as mod/lti/services.php. The launch of a pseudonymising policy points the tool here instead of core,
 * so that the tool keeps using the pseudonym it was given (finding SP-02). Authentication, scopes and the
 * resources are those of core; only the user identifiers are translated.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../../config.php');

\ltisource_ltipseudonym\local\proxy\dispatcher::handle()->send();
