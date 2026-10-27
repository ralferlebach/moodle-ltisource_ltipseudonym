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
 * FAM-04 production quality checks: no identifiers in refusal messages, constant database cost per launch.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\launch_processor
 */
final class quality_test extends \advanced_testcase {
    /**
     * Every refusal path: the message is a technical hint and contains none of the identifying values of the person.
     */
    public function test_refusals_leak_no_identifiers(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student', [
            'username' => 'secretlogin77', 'email' => 'secret.person@example.com', 'firstname' => 'Zebulon',
            'lastname' => 'Quuxington',
            'idnumber' => 'IDN-31337',
        ]);
        $cases = [];

        $legacy = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $legacy, 'pseudotype' => 'legacy_userid']);
        $cases['legacy disabled'] = testing\fixture::create_activity($course, $legacy);

        $remove = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $remove, 'discemail' => 'remove', 'discname' => 'remove']);
        $cases['remove conflict'] = testing\fixture::create_activity($course, $remove);

        foreach ($cases as $name => $activity) {
            try {
                testing\fixture::launch($activity, $user);
                $this->fail("$name must be refused");
            } catch (\moodle_exception $e) {
                $text = $e->getMessage() . '|' . ($e->debuginfo ?? '') . '|' . json_encode($e->a);
                foreach (['secretlogin77', 'secret.person', 'Zebulon', 'Quuxington', 'IDN-31337'] as $needle) {
                    $this->assertStringNotContainsString($needle, $text, "$name: $needle");
                }
                $this->assertDoesNotMatchRegularExpression('/\b' . $user->id . '\b/', $e->getMessage(), "$name: user id");
            }
        }
        $this->assertSame(0, $DB->count_records('ltisource_ltipseudonym_map'), 'no pseudonym for refused launches');
    }

    /**
     * Reads per launch with an active policy do not grow with the number of users, courses or tools (no N+1).
     */
    public function test_query_budget_is_constant(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $tool = testing\fixture::create_type();
        testing\fixture::set_policy(['typeid' => $tool, 'pseudotype' => 'random_persisted', 'scopetype' => 'cm',
            'allowoverride' => 1,
            'discemail' => 'blank']);
        $activity = testing\fixture::create_activity($course, $tool);
        testing\fixture::launch($activity, $first);

        $measure = function () use ($DB, $activity, $first) {
            $before = $DB->perf_get_reads();
            testing\fixture::launch($activity, $first);
            return $DB->perf_get_reads() - $before;
        };
        $small = $measure();

        // Many more users, tools, courses and policy rows around.
        for ($i = 0; $i < 25; $i++) {
            $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $other = testing\fixture::create_type();
            testing\fixture::set_policy(['typeid' => $other, 'pseudotype' => 'random_persisted', 'scopetype' => 'course']);
            testing\fixture::launch(testing\fixture::create_activity($course, $other), $user);
        }
        $large = $measure();
        $this->assertLessThanOrEqual($small + 2, $large, 'the number of reads per launch does not depend on the site size');
        $this->assertLessThanOrEqual(60, $large, 'budget of database reads for one launch including core (' . $large . ')');
        $this->assertGreaterThan(0, $large);
    }
}
