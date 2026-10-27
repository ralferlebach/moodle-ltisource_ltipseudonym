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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture.php');

/**
 * Fresh installation of release 0.2.0 (first release under this component name; no upgrade path from the 0.1.0 alpha of the
 * old component): the upgrade function is a no-op, the installed schema equals install.xml, release information is consistent,
 * and a policy for a tool with AGS and NRPS launches through the service proxy.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_ltisource_ltipseudonym_upgrade
 * @covers     \ltisource_ltipseudonym\local\proxy\dispatcher
 */
final class install_test extends \advanced_testcase {
    /**
     * The upgrade function of the renamed plugin has no steps: it returns true for any old version and leaves the schema alone.
     */
    public function test_upgrade_function_is_a_noop(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/lti/source/ltipseudonym/db/upgrade.php');
        $before = array_keys($DB->get_columns('ltisource_ltipseudonym_pol'));
        $this->assertTrue(xmldb_ltisource_ltipseudonym_upgrade(0));
        $this->assertTrue(xmldb_ltisource_ltipseudonym_upgrade(2026102502));
        $this->assertSame($before, array_keys($DB->get_columns('ltisource_ltipseudonym_pol')));
        $this->assertSame('2026102502', (string) get_config('ltisource_ltipseudonym', 'version'));
    }

    /**
     * A fresh installation has exactly the tables of install.xml, and none of the 0.1.0 override (column ackservices,
     * setting servicesmode, its strings and its guard class).
     */
    public function test_schema_equals_install_xml(): void {
        global $DB, $CFG;
        $xml = simplexml_load_file($CFG->dirroot . '/mod/lti/source/ltipseudonym/db/install.xml');
        $count = 0;
        foreach ($xml->TABLES->TABLE as $table) {
            $fields = [];
            foreach ($table->FIELDS->FIELD as $field) {
                $fields[] = (string) $field['NAME'];
            }
            $actual = array_keys($DB->get_columns((string) $table['NAME']));
            sort($fields);
            sort($actual);
            $this->assertSame($fields, $actual, 'table ' . $table['NAME']);
            $count++;
        }
        $this->assertSame(3, $count);
        $this->assertArrayNotHasKey('ackservices', $DB->get_columns('ltisource_ltipseudonym_pol'));
        $this->assertFalse(get_config('ltisource_ltipseudonym', 'servicesmode'));
        $this->assertFalse(get_string_manager()->string_exists('ackservices', 'ltisource_ltipseudonym'));
        $this->assertFalse(class_exists('\\ltisource_ltipseudonym\\local\\service_guard'));
    }

    /**
     * Version, release, change log and documents tell the same story (FAM-01), including the rename.
     */
    public function test_release_information_is_consistent(): void {
        global $CFG;
        $plugin = new \stdClass();
        include($CFG->dirroot . '/mod/lti/source/ltipseudonym/version.php');
        $this->assertSame('ltisource_ltipseudonym', $plugin->component);
        $this->assertSame('0.2.0', $plugin->release);
        $this->assertSame(2026102502, $plugin->version);
        $info = \core_plugin_manager::instance()->get_plugin_info('ltisource_ltipseudonym');
        $this->assertSame('0.2.0', $info->release);
        $this->assertSame((string) $plugin->version, (string) $info->versiondisk);

        $root = $CFG->dirroot . '/mod/lti/source/ltipseudonym/';
        $changelog = file_get_contents($root . 'docs/CHANGELOG.md');
        $this->assertMatchesRegularExpression('/^# Changelog\s+## 0\.2\.0/m', $changelog);
        $this->assertStringContainsString('no upgrade path from 0.1.0 alpha', $changelog);
        $this->assertStringContainsString('0.2.0', file_get_contents($root . 'README.md'));
        $this->assertStringContainsString('0.2.0', file_get_contents($root . 'docs/ACCEPTANCE.md'));
        $install = file_get_contents($root . 'db/install.xml');
        $this->assertStringContainsString('VERSION="' . $plugin->version . '"', $install);
        $this->assertStringContainsString('PATH="mod/lti/source/ltipseudonym/db"', $install);
    }

    /**
     * A pseudonymising policy for a tool with AGS and NRPS launches without refusal: all service URLs point to the proxy,
     * the roster is translated and a score sent for the pseudonym reaches the real user.
     */
    public function test_policy_with_services_launches_through_the_proxy(): void {
        global $DB, $CFG, $COURSE;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $anna = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Anna', 'lastname' => 'Alpha']);
        $ben = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $typeid = testing\fixture::create_type(['ltiservice_gradesynchronization' => 2, 'ltiservice_memberships' => 1]);
        $now = time();
        $DB->insert_record('ltisource_ltipseudonym_pol', (object) ['typeid' => $typeid, 'courseid' => 0, 'cmid' => 0,
            'pseudotype' => 'random_persisted', 'scopetype' => 'deployment', 'allowoverride' => 0,
            'usermodified' => 2, 'timecreated' => $now, 'timemodified' => $now]);
        $activity = testing\fixture::create_activity($course, $typeid);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'lti',
            'iteminstance' => $activity->id]);
        if (!$item) {
            $item = new \grade_item(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'lti',
                'iteminstance' => $activity->id, 'itemnumber' => 0, 'itemname' => 'lti', 'grademax' => 100], false);
            $item->insert();
        }

        // Launch: no refusal, pseudonymised, all service URLs on the proxy.
        $COURSE = $course;
        $_GET['id'] = $activity->cmid;
        $claims = testing\fixture::launch($activity, $anna)[2];
        unset($_GET['id']);
        $this->assertNotEquals($anna->id, $claims['sub']);
        $script = '/mod/lti/source/ltipseudonym/service.php';
        $nrps = $claims['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url'];
        $ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'];
        foreach ([$nrps, $ags['lineitems'], $ags['lineitem']] as $url) {
            $this->assertStringContainsString($script, $url);
        }

        // Roster and score through the proxy with the bearer token of the tool.
        $token = bin2hex(random_bytes(16));
        $DB->insert_record('lti_access_tokens', (object) ['typeid' => $typeid, 'token' => $token, 'validuntil' => time() + 3600,
            'timecreated' => time(), 'lastaccess' => null, 'scope' => json_encode([
                'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly',
                'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            ])]);
        $roster = $this->proxy('GET', $nrps, $token, '', 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json');
        $this->assertSame(200, $roster->get_code(), $roster->get_reason());
        $ids = array_map(fn($m) => (string) $m->user_id, json_decode($roster->get_body())->members);
        $this->assertContains($claims['sub'], $ids);
        $this->assertNotContains((string) $anna->id, $ids);
        $this->assertNotContains((string) $ben->id, $ids);
        $body = json_encode(['userId' => $claims['sub'], 'timestamp' => date('c', time() + 60), 'scoreGiven' => 73,
            'scoreMaximum' => 100, 'gradingProgress' => 'FullyGraded', 'activityProgress' => 'Completed']);
        $score = $this->proxy('POST', $ags['lineitem'], $token, $body, 'application/vnd.ims.lis.v1.score+json', '/scores');
        $this->assertSame(200, $score->get_code(), $score->get_reason());
        $this->assertEquals(73, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $item->id, 'userid' => $anna->id]));
    }

    /**
     * Send a request to the service proxy for a URL of the id token.
     *
     * @param string $method
     * @param string $url URL from the id token
     * @param string $token bearer token
     * @param string $body
     * @param string $type Accept (GET) or Content-Type (POST)
     * @param string $suffix path appended to the URL path (for example /scores)
     * @return \mod_lti\local\ltiservice\response
     */
    private function proxy(string $method, string $url, string $token, string $body, string $type, string $suffix = '') {
        $script = '/mod/lti/source/ltipseudonym/service.php';
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path = substr($parts['path'], strpos($parts['path'], $script) + strlen($script)) . $suffix;
        foreach (['HTTP_ACCEPT', 'CONTENT_TYPE'] as $key) {
            unset($_SERVER[$key]);
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['QUERY_STRING'] = http_build_query($query);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_GET = $query;
        $_SERVER[$method === 'GET' ? 'HTTP_ACCEPT' : 'CONTENT_TYPE'] = $type;
        return \ltisource_ltipseudonym\local\proxy\dispatcher::handle($path, $body);
    }
}
