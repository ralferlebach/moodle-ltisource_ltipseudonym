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

namespace ltisource_ltipseudonym\form;

use ltisource_ltipseudonym\local\catalogue;
use ltisource_ltipseudonym\local\config;
use ltisource_ltipseudonym\local\policy_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Policy editor form.
 *
 * Custom data: typeid, courseid, cmid, stored (row or null), effectivebefore (effective policy before the change),
 * activemappings (number of active pseudonyms that a change of type or scope affects).
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class policy_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;
        $level = policy_manager::level_of($custom['typeid'], $custom['courseid'], $custom['cmid']);

        foreach (['typeid', 'courseid', 'cmid'] as $hidden) {
            $mform->addElement('hidden', $hidden, $custom[$hidden]);
            $mform->setType($hidden, PARAM_INT);
        }

        // Identity: type and scope.
        $mform->addElement('header', 'identityhdr', get_string('identityhdr', 'ltisource_ltipseudonym'));
        // Data protection boundary (SP-01): shown right where the identity is chosen.
        $notice = \html_writer::div(
            get_string('loginhintnotice', 'ltisource_ltipseudonym'),
            'alert alert-warning mb-0',
            ['role' => 'note']
        );
        $mform->addElement('static', 'loginhintboundary', get_string('loginhintboundaryhdr', 'ltisource_ltipseudonym'), $notice);

        $types = ['' => get_string('inherit', 'ltisource_ltipseudonym')];
        foreach (catalogue::pseudonym_types() as $key => $meta) {
            if ($meta['legacy'] && !config::legacy_enabled()) {
                continue;
            }
            $types[$key] = get_string('type:' . $key, 'ltisource_ltipseudonym') . ' - ' .
                get_string('identifiability', 'ltisource_ltipseudonym') . ': ' .
                get_string('identifiability:' . $meta['identifiability'], 'ltisource_ltipseudonym');
        }
        $mform->addElement('select', 'pseudotype', get_string('pseudotype', 'ltisource_ltipseudonym'), $types);
        $mform->addHelpButton('pseudotype', 'pseudotype', 'ltisource_ltipseudonym');

        $scopes = ['' => get_string('inherit', 'ltisource_ltipseudonym')];
        foreach (array_keys(catalogue::scopes()) as $scope) {
            $scopes[$scope] = get_string('scope:' . $scope, 'ltisource_ltipseudonym');
        }
        $mform->addElement('select', 'scopetype', get_string('scopetype', 'ltisource_ltipseudonym'), $scopes);
        $mform->addHelpButton('scopetype', 'scopetype', 'ltisource_ltipseudonym');

        // Disclosure: independent of the subject identifier.
        $mform->addElement('header', 'disclosurehdr', get_string('disclosurehdr', 'ltisource_ltipseudonym'));
        $mform->addElement('static', 'disclosureinfo', '', get_string('disclosureinfo', 'ltisource_ltipseudonym'));
        foreach (catalogue::DISCLOSURES as $category) {
            $field = catalogue::disclosure_field($category);
            $values = ['' => get_string('inherit', 'ltisource_ltipseudonym')];
            foreach (array_keys(catalogue::disclosure_values_of($category)) as $value) {
                $values[$value] = get_string('disc:' . $value, 'ltisource_ltipseudonym');
            }
            $mform->addElement('select', $field, get_string('disc:' . $category, 'ltisource_ltipseudonym'), $values);
        }

        // Delegation and services.
        $mform->addElement('header', 'delegationhdr', get_string('delegationhdr', 'ltisource_ltipseudonym'));
        if ($level !== policy_manager::LEVEL_CM) {
            $mform->addElement('advcheckbox', 'allowoverride', get_string('allowoverride', 'ltisource_ltipseudonym'));
            $mform->addHelpButton('allowoverride', 'allowoverride', 'ltisource_ltipseudonym');
        }

        // Explicit confirmation of an identity change.
        $mform->addElement('header', 'confirmhdr', get_string('confirmhdr', 'ltisource_ltipseudonym'));
        $mform->addElement('static', 'confirminfo', '', get_string(
            'confirminfo',
            'ltisource_ltipseudonym',
            (object) ['mappings' => $custom['activemappings']]
        ));
        $mform->addElement('advcheckbox', 'confirmchange', get_string('confirmchange', 'ltisource_ltipseudonym'));

        $this->add_action_buttons();
        $this->set_data($custom['stored'] ?? new \stdClass());
    }

    /**
     * Validate: hierarchy, legacy switch, services guard and the identity change confirmation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $custom = $this->_customdata;
        $typeid = (int) $data['typeid'];
        $courseid = (int) $data['courseid'];
        $cmid = (int) $data['cmid'];

        $proposed = (object) $data;
        $problems = policy_manager::validate($typeid, $courseid, $cmid, clone $proposed);
        foreach ($problems as $problem) {
            $parts = explode(':', $problem);
            $field = $parts[2] ?? 'pseudotype';
            $errors[$field] = get_string('problem:' . $parts[1], 'ltisource_ltipseudonym');
        }

        $after = policy_manager::resolve($typeid, $courseid, $cmid, $this->as_row($proposed));

        // Claims that must be absent while core still builds them for this tool registration (SP-03).
        if ($typeid > 0) {
            $typeconfig = lti_get_type_config($typeid);
            foreach (\ltisource_ltipseudonym\local\claim_guard::conflicts($after, $typeconfig) as $category => $verdict) {
                $errors[catalogue::disclosure_field($category)] = get_string(
                    $verdict === \ltisource_ltipseudonym\local\claim_guard::SENDS ? 'problem:coresends' : 'problem:coremaysend',
                    'ltisource_ltipseudonym'
                );
            }
        }

        // Identity change: needs explicit confirmation (SP-P04).
        $before = $custom['effectivebefore'];
        if (
            ($after->pseudotype !== $before->pseudotype || $after->scopetype !== $before->scopetype) &&
                empty($data['confirmchange'])
        ) {
            $errors['confirmchange'] = get_string('problem:confirmrequired', 'ltisource_ltipseudonym');
        }
        return $errors;
    }

    /**
     * Convert submitted data to a policy row for resolution.
     *
     * @param \stdClass $data
     * @return \stdClass
     */
    private function as_row(\stdClass $data): \stdClass {
        $row = new \stdClass();
        foreach (
            array_merge(
                ['pseudotype', 'scopetype', 'allowoverride'],
                array_map([catalogue::class, 'disclosure_field'], catalogue::DISCLOSURES)
            ) as $field
        ) {
            $row->$field = ($data->$field ?? '') === '' ? null : $data->$field;
        }
        $row->courseid = (int) $data->courseid;
        $row->cmid = (int) $data->cmid;
        return $row;
    }
}
