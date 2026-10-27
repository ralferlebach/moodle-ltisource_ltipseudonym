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

namespace ltisource_ltipseudonym\local\services;

/**
 * Where the proxy answers, and how a launch parameter of core is moved there.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proxy_path {
    /** @var string Path of the services endpoint of core below the site root. */
    public const CORE = '/mod/lti/services.php';
    /** @var string Path of the proxy below the site root. */
    public const PROXY = '/mod/lti/source/ltipseudonym/service.php';

    /**
     * The URL of the proxy (without a path info).
     *
     * @return string
     */
    public static function url(): string {
        return (new \moodle_url(self::PROXY))->out(false);
    }

    /**
     * Move a service URL of core to the proxy; every other value is returned unchanged.
     *
     * The part after the script name (the path info that the resource templates match) is kept as it is.
     *
     * @param mixed $value a launch parameter value
     * @return mixed
     */
    public static function rewrite($value) {
        if (!is_string($value) || strpos($value, self::CORE . '/') === false) {
            return $value;
        }
        if (!preg_match('#^https?://#i', $value)) {
            return $value;
        }
        return preg_replace('#' . preg_quote(self::CORE, '#') . '(?=/)#', self::PROXY, $value, 1);
    }
}
