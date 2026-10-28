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

namespace ltisource_ltipseudonym\output;

/**
 * Renderer of ltisource_ltipseudonym.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Plugin renderer.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the policy overview.
     *
     * @param overview $overview
     * @return string
     */
    public function render_overview(overview $overview): string {
        $data = $overview->export_for_template($this);
        $component = 'ltisource_ltipseudonym';
        // Data protection boundary (SP-01) first: a warning, not a footnote.
        $out = $this->notification(
            get_string('loginhintnotice', $component),
            \core\output\notification::NOTIFY_WARNING
        );
        if ($data->haslegacytypes) {
            $out .= $this->notification($data->legacytypestext, \core\output\notification::NOTIFY_WARNING);
        }
        if ($data->hasorphans) {
            $out .= $this->notification($data->orphanstext, \core\output\notification::NOTIFY_WARNING);
        }
        $table = new \html_table();
        $table->attributes['class'] = 'table table-striped generaltable';
        $table->caption = get_string('policyoverview', $component);
        $table->captionhide = true;
        $table->head = [
            get_string('tool', $component),
            get_string('pseudotype', $component),
            get_string('scopetype', $component),
            get_string('disclosurehdr', $component),
            get_string('services', $component),
            get_string('mappings', $component),
            get_string('actions', $component),
        ];
        foreach ($data->rows as $row) {
            $services = s($row->services);
            if ($row->proxytext !== '') {
                $services .= \html_writer::div(s($row->proxytext), 'small text-muted');
            }
            if ($row->outcomeleaktext !== '') {
                $services .= $this->notification($row->outcomeleaktext, \core\output\notification::NOTIFY_WARNING);
            }
            $disclosure = s($row->disclosure);
            foreach ($row->conflicts as $conflict) {
                $disclosure .= $this->notification($conflict, \core\output\notification::NOTIFY_ERROR);
            }
            $actions = \html_writer::link($row->editurl, get_string('edit', $component));
            if ($row->canmigrate) {
                $actions .= ' | ' . \html_writer::link($row->migrateurl, get_string('migrate', $component));
            }
            $table->data[] = [
                s($row->name),
                s($row->pseudotype . ' (' . $row->identifiability . ')'),
                s($row->scope),
                $disclosure,
                $services,
                $row->mappings,
                $actions,
            ];
        }
        $out .= \html_writer::table($table);
        $out .= \html_writer::div(s($data->outcomestext), 'small text-muted', ['id' => 'ltisource_ltipseudonym-outcomes']);
        if ($data->canmigrate) {
            $out .= \html_writer::tag('p', \html_writer::link(
                $data->migrateurl,
                get_string('sitescopemigrate', $component)
            ));
        }
        return $out;
    }
}
