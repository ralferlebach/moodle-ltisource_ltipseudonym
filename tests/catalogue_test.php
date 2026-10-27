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

use ltisource_ltipseudonym\local\catalogue;

/**
 * Unit tests of the catalogue of pseudonym types, scopes and disclosure categories.
 *
 * @package    ltisource_ltipseudonym
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \ltisource_ltipseudonym\local\catalogue
 */
final class catalogue_test extends \advanced_testcase {
    /**
     * Random persisted is the strongest, legacy and none are the weakest.
     */
    public function test_type_ranking(): void {
        $this->assertGreaterThan(catalogue::type_rank(catalogue::TYPE_HMAC), catalogue::type_rank(catalogue::TYPE_RANDOM));
        $this->assertGreaterThan(catalogue::type_rank(catalogue::TYPE_NONE), catalogue::type_rank(catalogue::TYPE_HMAC));
        $this->assertSame(catalogue::type_rank(catalogue::TYPE_NONE), catalogue::type_rank(catalogue::TYPE_LEGACY_USERID));
    }

    /**
     * Only the two real pseudonym types replace the subject.
     */
    public function test_only_two_types_pseudonymise(): void {
        $pseudonymising = array_keys(array_filter(catalogue::pseudonym_types(), fn($meta) => $meta['pseudonymising']));
        $this->assertEqualsCanonicalizing([catalogue::TYPE_RANDOM, catalogue::TYPE_HMAC], $pseudonymising);
        $this->assertFalse(catalogue::is_pseudonymising(catalogue::TYPE_LEGACY_USERID));
        $this->assertFalse(catalogue::is_pseudonymising(catalogue::TYPE_NONE));
    }

    /**
     * The legacy entry is marked as legacy and rated highly identifiable.
     */
    public function test_legacy_is_flagged_and_identifiable(): void {
        $meta = catalogue::pseudonym_types()[catalogue::TYPE_LEGACY_USERID];
        $this->assertTrue($meta['legacy']);
        $this->assertSame('high', $meta['identifiability']);
        $this->assertSame('low', catalogue::pseudonym_types()[catalogue::TYPE_RANDOM]['identifiability']);
    }

    /**
     * The scopes are ordered from broad to narrow.
     */
    public function test_scope_narrowness(): void {
        $this->assertLessThan(catalogue::scope_rank(catalogue::SCOPE_DEPLOYMENT), catalogue::scope_rank(catalogue::SCOPE_SITE));
        $this->assertLessThan(catalogue::scope_rank(catalogue::SCOPE_COURSE), catalogue::scope_rank(catalogue::SCOPE_DEPLOYMENT));
        $this->assertLessThan(catalogue::scope_rank(catalogue::SCOPE_CM), catalogue::scope_rank(catalogue::SCOPE_COURSE));
    }

    /**
     * Substitution variables are mapped to the disclosure category that governs them.
     *
     * @dataProvider variable_provider
     * @param string $variable
     * @param string|null $expected
     */
    public function test_category_of_variable(string $variable, ?string $expected): void {
        $this->assertSame($expected, catalogue::category_of_variable($variable));
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function variable_provider(): array {
        return [
            'user id' => ['User.id', 'sub'],
            'username' => ['User.username', 'username'],
            'email' => ['Person.email.primary', 'email'],
            'given name' => ['Person.name.given', 'name'],
            'sourcedid' => ['Person.sourcedId', 'sourcedid'],
            'result id' => ['Result.sourcedId', 'result'],
            'phone' => ['Person.phone.mobile', 'extra'],
            'course title' => ['Context.title', null],
            'unknown' => ['Foo.bar', null],
        ];
    }

    /**
     * Every disclosure category has a database column name and a parameter list.
     */
    public function test_disclosure_categories_are_complete(): void {
        $params = catalogue::disclosure_params();
        foreach (catalogue::DISCLOSURES as $category) {
            $this->assertArrayHasKey($category, $params);
            $this->assertSame('disc' . $category, catalogue::disclosure_field($category));
        }
    }
}
