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
 * Settings of ltisource_ltipseudonym.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Loaded by mod_lti\plugininfo\ltisource::load_settings() with $settings, $ADMIN and $parentnodename in scope.
// Changes to these settings are recorded by core in the config log (actor, time, old and new value).
if ($ADMIN->fulltree) {
    $component = 'ltisource_ltipseudonym';
    // Data protection boundary (SP-01) and the service proxy rules (SP-02) belong at the top of the settings.
    $settings->add(new admin_setting_heading(
        'ltisource_ltipseudonym/boundary',
        get_string('boundaryhdr', $component),
        get_string('boundary_desc', $component)
    ));
    $settings->add(new admin_setting_heading(
        'ltisource_ltipseudonym/policylink',
        '',
        html_writer::link(
            new moodle_url('/mod/lti/source/ltipseudonym/policy.php'),
            get_string('policyoverview', $component)
        )
    ));
    $settings->add(new admin_setting_configcheckbox(
        'ltisource_ltipseudonym/legacyenabled',
        get_string('legacyenabled', $component),
        get_string('legacyenabled_desc', $component),
        0
    ));
    $settings->add(new admin_setting_configselect(
        'ltisource_ltipseudonym/legacyprotocol',
        get_string('legacyprotocol', $component),
        get_string('legacyprotocol_desc', $component),
        'pass',
        [
            'pass' => get_string('legacyprotocol:pass', $component),
            'refuse' => get_string('legacyprotocol:refuse', $component),
        ]
    ));
    $settings->add(new admin_setting_configtext(
        'ltisource_ltipseudonym/retireddays',
        get_string('retireddays', $component),
        get_string('retireddays_desc', $component),
        365,
        PARAM_INT
    ));
}

$ADMIN->add($parentnodename, new admin_externalpage(
    'ltisourceltipseudonympolicy',
    get_string('policyoverview', 'ltisource_ltipseudonym'),
    new moodle_url('/mod/lti/source/ltipseudonym/policy.php'),
    'ltisource/ltipseudonym:managepolicy'
));
