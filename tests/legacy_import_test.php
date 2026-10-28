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

namespace ltisource_ltipseudonym;

use ltisource_ltipseudonym\local\config;
use ltisource_ltipseudonym\local\legacy_import;
use ltisource_ltipseudonym\testing\fixture;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Finding SP-04: safe exit path from the 0.1.0 alpha (component ltisource_scopedidentity).
 *
 * The tables of the old plugin are fabricated from its own install.xml (tests/fixtures/scopedidentity_install.xml, copied
 * unchanged from the 0.1.0 alpha) and dropped again after each test.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\legacy_import
 */
final class legacy_import_test extends \advanced_testcase {
    /**
     * Drop the fabricated tables.
     */
    protected function tearDown(): void {
        $this->drop_old_tables();
        parent::tearDown();
    }

    /**
     * Drop the tables of the old plugin (what its uninstall does).
     */
    private function drop_old_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (['ltisource_scopedidentity_pol', 'ltisource_scopedidentity_map', 'ltisource_scopedidentity_aud'] as $name) {
            $table = new \xmldb_table($name);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
    }

    /**
     * Create the tables of the old plugin.
     */
    private function fabricate_old_tables(): void {
        global $DB;
        $this->preventResetByRollback();
        $DB->get_manager()->install_from_xmldb_file(__DIR__ . '/fixtures/scopedidentity_install.xml');
    }

    /**
     * An old mapping row.
     *
     * @param int $userid
     * @param int $typeid
     * @param string $token
     * @param array $extra
     * @return \stdClass
     */
    private function old_row(int $userid, int $typeid, string $token, array $extra = []): \stdClass {
        global $DB;
        $row = (object) ($extra + ['userid' => $userid, 'typeid' => $typeid, 'scopetype' => 'deployment', 'scopeid' => 0,
            'ptype' => 'random_persisted', 'token' => $token, 'version' => 1, 'status' => 'active',
            'timecreated' => 1000, 'timelastused' => 2000]);
        $row->id = $DB->insert_record('ltisource_scopedidentity_map', $row);
        return $row;
    }

    /**
     * Without the tables of the old plugin the import does nothing and says so.
     */
    public function test_nothing_without_old_tables(): void {
        $this->resetAfterTest();
        $this->assertFalse(legacy_import::old_tables_exist());
        $report = legacy_import::run(true, true, 2);
        $this->assertFalse($report->tablefound);
        $this->assertFalse($report->executed);
        $this->assertSame(0, $report->found);
    }

    /**
     * Dry run by default: the report is complete and nothing is written. Execute: exactly the rows the report named are
     * imported, existing rows are never changed, conflicts are reported, the generations follow, one audit row without values.
     */
    public function test_dry_run_then_execute(): void {
        global $DB;
        $this->resetAfterTest();
        $this->fabricate_old_tables();
        $gen = $this->getDataGenerator();
        [$u1, $u2, $u3, $u4, $u5] = [$gen->create_user(), $gen->create_user(), $gen->create_user(), $gen->create_user(),
            $gen->create_user()];
        $deleted = $gen->create_user();
        delete_user($deleted);
        $typeid = fixture::create_type();
        $other = fixture::create_type();

        $this->old_row((int) $u1->id, $typeid, 'OLD-U1-AAAAAAAAAAAAAAAAAAAAAAAA', ['version' => 2]);
        $this->old_row((int) $u2->id, $typeid, 'OLD-U2-RETIRED-AAAAAAAAAAAAAAAA', ['status' => 'retired']);
        $this->old_row((int) $deleted->id, $typeid, 'OLD-DELETED-AAAAAAAAAAAAAAAAAAA');
        $this->old_row((int) $u3->id, 999999, 'OLD-NOTYPE-AAAAAAAAAAAAAAAAAAAA');
        $this->old_row((int) $u4->id, $other, 'OLD-U4-CONFLICT-AAAAAAAAAAAAAAA');
        $this->old_row((int) $u5->id, $other, 'SAME-TOKEN-U5-AAAAAAAAAAAAAAAAA');
        $this->old_row((int) $u5->id, 0, 'OLD-U5-SITE-AAAAAAAAAAAAAAAAAAA', ['scopetype' => 'site']);
        set_config('generation_' . $typeid, 2, legacy_import::OLD_COMPONENT);
        // Rows of this plugin: u4 already has another pseudonym for the same key (the tool may know it), u5 the same one,
        // u1 an active row of the older generation 1.
        $here = fn($userid, $typeid, $token, $version = 1) => $DB->insert_record('ltisource_ltipseudonym_map', (object) [
            'userid' => $userid, 'typeid' => $typeid, 'scopetype' => 'deployment', 'scopeid' => 0, 'ptype' => 'random_persisted',
            'token' => $token, 'version' => $version, 'status' => 'active', 'timecreated' => 5, 'timelastused' => 5]);
        $here($u4->id, $other, 'NEW-U4-BBBBBBBBBBBBBBBBBBBBBBBBB');
        $here($u5->id, $other, 'SAME-TOKEN-U5-AAAAAAAAAAAAAAAAA');
        $here($u1->id, $typeid, 'NEW-U1-GEN1-BBBBBBBBBBBBBBBBBBBB');
        $before = $DB->get_records('ltisource_ltipseudonym_map', null, 'id');
        $auditbefore = $DB->count_records('ltisource_ltipseudonym_aud');

        $dry = legacy_import::run(false, false, 2);
        $expected = ['found' => 7, 'imported' => 3, 'already' => 1, 'conflicts' => 1, 'skippeduser' => 1, 'skippedtype' => 1,
            'generations' => 1, 'retired' => 1];
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $dry->$key, 'dry run: ' . $key);
        }
        $this->assertFalse($dry->executed);
        $this->assertEquals($before, $DB->get_records('ltisource_ltipseudonym_map', null, 'id'), 'dry run writes nothing');
        $this->assertSame(1, config::generation($typeid));
        $this->assertSame($auditbefore, $DB->count_records('ltisource_ltipseudonym_aud'));

        $done = legacy_import::run(true, false, 2);
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $done->$key, 'execute: ' . $key);
        }
        $this->assertTrue($done->executed);
        $this->assertSame(2, config::generation($typeid));
        $this->assertSame('retired', $DB->get_field(
            'ltisource_ltipseudonym_map',
            'status',
            ['token' => 'NEW-U1-GEN1-BBBBBBBBBBBBBBBBBBBB']
        ), 'the older generation here is retired like after a rotation');
        $this->assertSame('active', $DB->get_field(
            'ltisource_ltipseudonym_map',
            'status',
            ['token' => 'OLD-U1-AAAAAAAAAAAAAAAAAAAAAAAA']
        ));
        $this->assertSame('retired', $DB->get_field(
            'ltisource_ltipseudonym_map',
            'status',
            ['token' => 'OLD-U2-RETIRED-AAAAAAAAAAAAAAAA']
        ), 'retired stays retired');
        $this->assertTrue(
            $DB->record_exists('ltisource_ltipseudonym_map', ['token' => 'NEW-U4-BBBBBBBBBBBBBBBBBBBBBBBBB']),
            'the existing row of a conflict is kept'
        );
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['token' => 'OLD-U4-CONFLICT-AAAAAAAAAAAAAAA']));
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['token' => 'OLD-DELETED-AAAAAAAAAAAAAAAAAAA']));
        $this->assertFalse($DB->record_exists('ltisource_ltipseudonym_map', ['token' => 'OLD-NOTYPE-AAAAAAAAAAAAAAAAAAAA']));
        $this->assertEquals(1000, $DB->get_field(
            'ltisource_ltipseudonym_map',
            'timecreated',
            ['token' => 'OLD-U5-SITE-AAAAAAAAAAAAAAAAAAA']
        ));

        $audit = $DB->get_records('ltisource_ltipseudonym_aud', ['action' => 'legacy_import']);
        $this->assertCount(1, $audit);
        $newvalue = reset($audit)->newvalue;
        $this->assertSame(3, json_decode($newvalue)->imported);
        foreach ($DB->get_fieldset_select('ltisource_ltipseudonym_map', 'token', '1 = 1') as $token) {
            $this->assertStringNotContainsString($token, $newvalue, 'no pseudonym in the audit');
        }

        // Idempotent: a second run imports nothing more.
        $again = legacy_import::run(true, false, 2);
        $this->assertSame(0, $again->imported);
        $this->assertSame(4, $again->already);
        $this->assertSame(0, $again->generations);
    }

    /**
     * What it is for: after the import (with the policies) and the uninstall of the old plugin, a launch delivers the
     * pseudonym the tool already knows.
     */
    public function test_tools_keep_seeing_the_old_pseudonym(): void {
        global $DB;
        $this->resetAfterTest();
        $this->fabricate_old_tables();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $typeid = fixture::create_type();
        $activity = fixture::create_activity($course, $typeid);
        $this->old_row((int) $user->id, $typeid, 'KNOWN-BY-THE-TOOL-AAAAAAAAAAAAAA', ['scopetype' => 'course',
            'scopeid' => $course->id]);
        $DB->insert_record('ltisource_scopedidentity_pol', (object) ['typeid' => $typeid, 'courseid' => 0, 'cmid' => 0,
            'pseudotype' => 'random_persisted', 'scopetype' => 'course', 'discname' => 'blank', 'discresult' => 'bogus',
            'allowoverride' => 0, 'ackservices' => 1, 'usermodified' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        // A level that already has a policy here is not touched.
        $DB->insert_record('ltisource_scopedidentity_pol', (object) ['typeid' => 0, 'courseid' => 0, 'cmid' => 0,
            'pseudotype' => 'hmac_secret_v1', 'scopetype' => 'site', 'allowoverride' => 1, 'ackservices' => 0,
            'usermodified' => 0, 'timecreated' => 1, 'timemodified' => 1]);
        fixture::set_policy(['typeid' => 0, 'allowoverride' => 1]);

        $this->assertEquals($user->id, fixture::launch($activity, $user)[2]['sub'], 'control: no policy for the tool yet');
        $report = legacy_import::run(true, true, 2);
        $this->assertSame(1, $report->imported);
        $this->assertSame(1, $report->policies);
        $this->assertSame(1, $report->policyconflicts);
        $row = $DB->get_record('ltisource_ltipseudonym_pol', ['typeid' => $typeid, 'courseid' => 0, 'cmid' => 0]);
        $this->assertSame('random_persisted', $row->pseudotype);
        $this->assertSame('blank', $row->discname);
        $this->assertNull($row->discresult, 'a value that is not valid any more is left empty (inherits)');

        $this->drop_old_tables();
        $this->assertFalse(legacy_import::old_tables_exist(), 'the old plugin is uninstalled');
        $claims = fixture::launch($activity, $user)[2];
        $this->assertSame('KNOWN-BY-THE-TOOL-AAAAAAAAAAAAAA', $claims['sub']);
        $this->assertSame('', $claims['name']);
    }

    /**
     * The CLI script exists, is a CLI script, and runs as a dry run unless --execute is given.
     */
    public function test_cli_script(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/mod/lti/source/ltipseudonym/cli/import_scopedidentity.php');
        $this->assertStringContainsString("define('CLI_SCRIPT', true);", $source);
        $this->assertStringContainsString("'execute' => false", $source);
        $this->assertStringContainsString('legacy_import::run(', $source);
        $this->assertStringContainsString("!empty(\$options['execute'])", $source);
    }
}
