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
 * Catalogue of pseudonym types, scopes and disclosure categories.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Static catalogue of everything an administrator can choose from.
 *
 * Pseudonym type and scope are orthogonal. Every entry carries a strength rank used by the policy
 * resolver: a lower level may only choose an entry of an equal or higher rank than its parent.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalogue {
    /** @var string The plugin leaves the identity alone (nothing configured). */
    public const TYPE_NONE = 'none';
    /** @var string Legacy: the internal Moodle user id is sent as sub (explicit opt-in). */
    public const TYPE_LEGACY_USERID = 'legacy_userid';
    /** @var string HMAC-SHA256 with a secret key, domain separated and versioned. */
    public const TYPE_HMAC = 'hmac_secret_v1';
    /** @var string CSPRNG token (at least 128 bit) persisted in the local mapping table. */
    public const TYPE_RANDOM = 'random_persisted';

    /** @var string One identity per user across all tools using this scope (weakest scope). */
    public const SCOPE_SITE = 'site';
    /** @var string One identity per user and tool registration (deployment). */
    public const SCOPE_DEPLOYMENT = 'deployment';
    /** @var string One identity per user, tool registration and course. */
    public const SCOPE_COURSE = 'course';
    /** @var string One identity per user, tool registration and course module. */
    public const SCOPE_CM = 'cm';

    /** @var string The core value is delivered unchanged. */
    public const DISC_PASS = 'pass';
    /** @var string The value is replaced by an empty string: the claim stays PRESENT in the token, with an empty value. */
    public const DISC_BLANK = 'blank';
    /**
     * @var string The claim must be ABSENT from the token. A ltisource callback cannot delete a launch parameter, so this
     * is enforced by checking that core does not build it and by refusing the launch otherwise (see claim_guard).
     */
    public const DISC_REMOVE = 'remove';

    /** @var string[] Disclosure categories, independent of the subject identifier. */
    public const DISCLOSURES = ['name', 'email', 'picture', 'sourcedid', 'username', 'result', 'extra'];

    /**
     * Pseudonym types selectable in the policy editor, with their metadata.
     *
     * rank: privacy strength (higher is stronger); identifiability: hi, med or low as orientation
     * only (no GDPR conformity statement); legacy: disabled unless explicitly enabled by the site.
     *
     * @return array[] type => metadata
     */
    public static function pseudonym_types(): array {
        return [
            self::TYPE_NONE => ['rank' => 0, 'identifiability' => 'high', 'legacy' => false, 'pseudonymising' => false],
            self::TYPE_LEGACY_USERID => ['rank' => 0, 'identifiability' => 'high', 'legacy' => true, 'pseudonymising' => false],
            self::TYPE_HMAC => ['rank' => 2, 'identifiability' => 'medium', 'legacy' => false, 'pseudonymising' => true],
            self::TYPE_RANDOM => ['rank' => 3, 'identifiability' => 'low', 'legacy' => false, 'pseudonymising' => true],
        ];
    }

    /**
     * Scopes with their narrowness rank (higher means less correlatable).
     *
     * @return int[] scope => rank
     */
    public static function scopes(): array {
        return [
            self::SCOPE_SITE => 0,
            self::SCOPE_DEPLOYMENT => 1,
            self::SCOPE_COURSE => 2,
            self::SCOPE_CM => 3,
        ];
    }

    /**
     * Disclosure values with their strength rank.
     *
     * @return int[] value => rank
     */
    public static function disclosure_values(): array {
        return [self::DISC_PASS => 0, self::DISC_BLANK => 1, self::DISC_REMOVE => 2];
    }

    /**
     * Whether the claims of a category can be removed at all.
     *
     * Core always builds lis_person_sourcedid (the ID number, possibly empty) for a basic launch, so its claim can
     * be emptied but never be made absent.
     *
     * @param string $category
     * @return bool
     */
    public static function is_removable(string $category): bool {
        return $category !== 'sourcedid';
    }

    /**
     * Disclosure values valid for a category, with their rank.
     *
     * @param string $category
     * @return int[] value => rank
     */
    public static function disclosure_values_of(string $category): array {
        $values = self::disclosure_values();
        if (!self::is_removable($category)) {
            unset($values[self::DISC_REMOVE]);
        }
        return $values;
    }

    /**
     * Whether a pseudonym type is known.
     *
     * @param string $type
     * @return bool
     */
    public static function is_type(string $type): bool {
        return array_key_exists($type, self::pseudonym_types());
    }

    /**
     * Whether a type replaces the subject identifier by a pseudonym.
     *
     * @param string $type
     * @return bool
     */
    public static function is_pseudonymising(string $type): bool {
        return self::pseudonym_types()[$type]['pseudonymising'] ?? false;
    }

    /**
     * Strength rank of a type.
     *
     * @param string $type
     * @return int
     */
    public static function type_rank(string $type): int {
        return self::pseudonym_types()[$type]['rank'] ?? 0;
    }

    /**
     * Narrowness rank of a scope.
     *
     * @param string $scope
     * @return int
     */
    public static function scope_rank(string $scope): int {
        return self::scopes()[$scope] ?? 0;
    }

    /**
     * Strength rank of a disclosure value.
     *
     * @param string $value
     * @return int
     */
    public static function disclosure_rank(string $value): int {
        return self::disclosure_values()[$value] ?? 0;
    }

    /**
     * Database column of a disclosure category.
     *
     * @param string $category
     * @return string
     */
    public static function disclosure_field(string $category): string {
        return 'disc' . $category;
    }

    /**
     * Launch parameters whose value is governed by each disclosure category.
     *
     * @return array[] category => launch parameter names
     */
    public static function disclosure_params(): array {
        return [
            'name' => ['lis_person_name_given', 'lis_person_name_family', 'lis_person_name_full'],
            'email' => ['lis_person_contact_email_primary'],
            'picture' => ['user_image'],
            'sourcedid' => ['lis_person_sourcedid'],
            'username' => ['ext_user_username'],
            'result' => ['lis_result_sourcedid'],
            'extra' => [],
        ];
    }

    /**
     * Category of a custom parameter substitution variable (the part after the dollar sign).
     *
     * 'sub' marks the user id, which is replaced by the pseudonym instead of being blanked.
     *
     * @param string $variable e.g. User.id or Person.email.primary
     * @return string|null category, 'sub', or null when the variable carries no user data
     */
    public static function category_of_variable(string $variable): ?string {
        $map = [
            'User.id' => 'sub',
            'User.username' => 'username',
            'Person.name.full' => 'name',
            'Person.name.given' => 'name',
            'Person.name.family' => 'name',
            'Person.name.middle' => 'name',
            'Person.email.primary' => 'email',
            'Person.sourcedId' => 'sourcedid',
            'Result.sourcedId' => 'result',
            'BasicOutcome.sourcedId' => 'result',
            'Person.address.street1' => 'extra',
            'Person.address.locality' => 'extra',
            'Person.address.country' => 'extra',
            'Person.address.timezone' => 'extra',
            'Person.phone.primary' => 'extra',
            'Person.phone.mobile' => 'extra',
            'Person.webaddress' => 'extra',
        ];
        return $map[$variable] ?? null;
    }
}
