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

use ltisource_ltipseudonym\local\catalogue;

/**
 * Policy overview for site administrators.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overview implements \renderable, \templatable {
    /** @var array[] rows from overview_builder */
    private $rows;
    /** @var bool whether the user may migrate identities */
    private $canmigrate;
    /** @var int number of orphaned mappings */
    private $orphans;
    /** @var string[] LTI 1.x registrations (id => name), not covered by the policies */
    private $legacytypes;

    /**
     * Constructor.
     *
     * @param array[] $rows
     * @param bool $canmigrate
     * @param int $orphans
     * @param string[]|null $legacytypes LTI 1.x registrations (id => name); null reads them from the database
     */
    public function __construct(array $rows, bool $canmigrate, int $orphans, ?array $legacytypes = null) {
        $this->rows = $rows;
        $this->canmigrate = $canmigrate;
        $this->orphans = $orphans;
        $this->legacytypes = $legacytypes ?? \ltisource_ltipseudonym\local\overview_builder::legacy_types();
    }

    /**
     * Export for the mustache template.
     *
     * @param \renderer_base $output
     * @return \stdClass
     */
    public function export_for_template(\renderer_base $output) {
        $component = 'ltisource_ltipseudonym';
        $data = new \stdClass();
        $data->rows = [];
        foreach ($this->rows as $row) {
            $meta = catalogue::pseudonym_types()[$row['pseudotype']];
            $item = new \stdClass();
            $item->name = $row['name'];
            $item->pseudotype = get_string('type:' . $row['pseudotype'], $component);
            $item->identifiability = get_string('identifiability:' . $meta['identifiability'], $component);
            $item->scope = get_string('scope:' . $row['scopetype'], $component);
            $restricted = [];
            foreach ($row['effects'] as $category => $effect) {
                // What the launch really carries, never "removed" for a claim core still sends (SP-04).
                $restricted[] = get_string('effectline', $component, (object) [
                    'category' => get_string('disc:' . $category, $component),
                    'mode' => get_string('discshort:' . $effect['mode'], $component),
                    'effect' => get_string('effect:' . $effect['effect'], $component),
                ]);
            }
            $item->disclosure = $restricted ? implode('; ', $restricted) : get_string('disc:nonerestricted', $component);
            $item->conflicts = [];
            foreach ($row['conflicts'] as $category => $verdict) {
                $item->conflicts[] = get_string(
                    $verdict === \ltisource_ltipseudonym\local\claim_guard::SENDS ? 'conflict:coresends' : 'conflict:coremaysend',
                    $component,
                    get_string('disc:' . $category, $component)
                );
            }
            $item->services = implode(', ', array_map('strtoupper', $row['services']));
            $item->proxytext = $row['viaproxy'] ? get_string('servicesproxy', $component) : '';
            $item->outcomeleaktext = $row['outcomeleak'] ? get_string('outcomeleak', $component) : '';
            $item->overrides = $row['overrides'];
            $item->mappings = $row['activemappings'];
            $params = ['typeid' => $row['typeid']];
            $item->editurl = (new \moodle_url('/mod/lti/source/ltipseudonym/editpolicy.php', $params))->out(false);
            $item->migrateurl = (new \moodle_url('/mod/lti/source/ltipseudonym/migrate.php', $params))->out(false);
            $item->canmigrate = $this->canmigrate;
            $data->rows[] = $item;
        }
        $data->haslegacytypes = !empty($this->legacytypes);
        $data->legacytypestext = get_string(
            \ltisource_ltipseudonym\local\config::legacy_protocol() === 'refuse' ? 'legacytypes:refuse' : 'legacytypes:pass',
            $component,
            implode(', ', $this->legacytypes)
        );
        $data->outcomestext = get_string('disclosureoutcomes', $component);
        $data->orphans = $this->orphans;
        $data->hasorphans = $this->orphans > 0;
        $data->orphanstext = get_string('orphansfound', $component, $this->orphans);
        $data->migrateurl = (new \moodle_url('/mod/lti/source/ltipseudonym/migrate.php', ['typeid' => 0]))->out(false);
        $data->canmigrate = $this->canmigrate;
        return $data;
    }
}
