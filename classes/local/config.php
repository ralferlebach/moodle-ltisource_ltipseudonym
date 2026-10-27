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
 * Access to the plugin settings and identity generations.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Plugin settings with safe defaults.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string Plugin component. */
    public const COMPONENT = 'ltisource_ltipseudonym';

    /**
     * Whether legacy catalogue entries (internal id passthrough) may be selected and used.
     *
     * @return bool
     */
    public static function legacy_enabled(): bool {
        return !empty(get_config(self::COMPONENT, 'legacyenabled'));
    }

    /**
     * Days after which retired mappings are purged by the scheduled task (0 keeps them forever).
     *
     * @return int
     */
    public static function retired_days(): int {
        $days = get_config(self::COMPONENT, 'retireddays');
        return ($days === false || $days === null || $days === '') ? 365 : max(0, (int) $days);
    }

    /**
     * Current identity generation of a tool type (0 is the site scope).
     *
     * @param int $typeid
     * @return int
     */
    public static function generation(int $typeid): int {
        $value = get_config(self::COMPONENT, 'generation_' . $typeid);
        return max(1, (int) $value);
    }

    /**
     * Raise the identity generation of a tool type. Only the migration service calls this.
     *
     * @param int $typeid
     * @return int the new generation
     */
    public static function bump_generation(int $typeid): int {
        $new = self::generation($typeid) + 1;
        set_config('generation_' . $typeid, $new, self::COMPONENT);
        return $new;
    }
}
