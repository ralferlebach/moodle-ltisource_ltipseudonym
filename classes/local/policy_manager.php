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
 * Policy storage and hierarchical resolution.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Stores policies per level and resolves the effective policy of a launch.
 *
 * Levels: site default, tool type, course (per tool type), course module. Resolution walks the
 * levels from the top. A lower level is honoured only when every level above allows overrides, and
 * even then it can only make the result stronger, never weaker (clamping).
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class policy_manager {
    /** @var string Level name. */
    public const LEVEL_SITE = 'site';
    /** @var string Level name. */
    public const LEVEL_TYPE = 'type';
    /** @var string Level name. */
    public const LEVEL_COURSE = 'course';
    /** @var string Level name. */
    public const LEVEL_CM = 'cm';

    /**
     * Name of the level a (typeid, courseid, cmid) triple addresses.
     *
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @return string
     */
    public static function level_of(int $typeid, int $courseid, int $cmid): string {
        if ($cmid > 0) {
            return self::LEVEL_CM;
        }
        if ($courseid > 0) {
            return self::LEVEL_COURSE;
        }
        return $typeid > 0 ? self::LEVEL_TYPE : self::LEVEL_SITE;
    }

    /**
     * Fetch the stored row of a level.
     *
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @return \stdClass|null
     */
    public static function get_row(int $typeid, int $courseid = 0, int $cmid = 0): ?\stdClass {
        global $DB;
        if ($cmid > 0) {
            // A course module belongs to exactly one tool, so the row is addressed by cmid alone.
            $record = $DB->get_record('ltisource_ltipseudonym_pol', ['cmid' => $cmid]);
        } else {
            $record = $DB->get_record('ltisource_ltipseudonym_pol', ['typeid' => $typeid, 'courseid' => $courseid, 'cmid' => 0]);
        }
        return $record ?: null;
    }

    /**
     * Neutral values of a hard-coded root policy: the plugin does nothing.
     *
     * @return \stdClass
     */
    public static function root_policy(): \stdClass {
        $eff = new \stdClass();
        $eff->pseudotype = catalogue::TYPE_NONE;
        $eff->scopetype = catalogue::SCOPE_DEPLOYMENT;
        foreach (catalogue::DISCLOSURES as $category) {
            $eff->{catalogue::disclosure_field($category)} = catalogue::DISC_PASS;
        }
        return $eff;
    }

    /**
     * Resolve the effective policy.
     *
     * @param int $typeid LTI tool type
     * @param int $courseid course (0 when unknown)
     * @param int $cmid course module (0 when none)
     * @param \stdClass|null $override a proposed row replacing the stored row of the addressed level
     * @return \stdClass effective policy with ->trace (list of notes) and ->sources (field => level)
     */
    public static function resolve(int $typeid, int $courseid = 0, int $cmid = 0, ?\stdClass $override = null): \stdClass {
        $target = ($override !== null) ? self::level_of(
            $typeid,
            (int) ($override->courseid ?? $courseid),
            (int) ($override->cmid ?? $cmid)
        ) : null;
        $eff = self::root_policy();
        $eff->trace = [];
        $eff->sources = [];
        $can = true;

        $rows = [self::LEVEL_SITE => self::get_row(0)];
        if ($typeid > 0) {
            $rows[self::LEVEL_TYPE] = self::get_row($typeid);
            if ($courseid > 0) {
                $rows[self::LEVEL_COURSE] = self::get_row($typeid, $courseid);
            }
        }
        if ($cmid > 0) {
            $rows[self::LEVEL_CM] = self::get_row(0, $courseid, $cmid);
        }
        if ($target !== null) {
            $rows[$target] = $override;
        }

        foreach ([self::LEVEL_SITE, self::LEVEL_TYPE, self::LEVEL_COURSE, self::LEVEL_CM] as $level) {
            $row = $rows[$level] ?? null;
            if ($row !== null) {
                if ($level !== self::LEVEL_SITE && !$can) {
                    $eff->trace[] = ['level' => $level, 'field' => '*', 'note' => 'ignored'];
                } else {
                    self::apply_row($eff, $row, $level);
                }
            }
            // Whether overrides further down are possible.
            if ($level === self::LEVEL_SITE) {
                $can = $row ? !empty($row->allowoverride) : true;
            } else if ($level === self::LEVEL_TYPE) {
                // Without an explicit type policy nothing below may override: the administrator
                // never decided anything for this tool.
                $can = $can && $row && !empty($row->allowoverride);
            } else if ($level === self::LEVEL_COURSE) {
                $can = $can && (!$row || !empty($row->allowoverride));
            }
        }
        $eff->pseudonymising = catalogue::is_pseudonymising($eff->pseudotype);
        if ($eff->pseudonymising && !isset($eff->sources['discresult'])) {
            // The LTI 1.1 result sourcedid carries the local user id (signed, but readable). A pseudonymising policy
            // empties it unless somebody explicitly decided otherwise (finding SP-02).
            $eff->discresult = catalogue::DISC_BLANK;
        }
        return $eff;
    }

    /**
     * Apply one stored row on top of the effective policy, clamping weaker values.
     *
     * @param \stdClass $eff effective policy being built
     * @param \stdClass $row stored row
     * @param string $level level of the row
     */
    private static function apply_row(\stdClass $eff, \stdClass $row, string $level): void {
        $strict = ($level !== self::LEVEL_SITE);
        $pairs = [
            'pseudotype' => fn($v) => catalogue::type_rank($v),
            'scopetype' => fn($v) => catalogue::scope_rank($v),
        ];
        foreach (catalogue::DISCLOSURES as $category) {
            $pairs[catalogue::disclosure_field($category)] = fn($v) => catalogue::disclosure_rank($v);
        }
        foreach ($pairs as $field => $rank) {
            $value = $row->$field ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!self::is_valid_value($field, $value)) {
                $eff->trace[] = ['level' => $level, 'field' => $field, 'note' => 'invalid'];
                continue;
            }
            if ($strict && $rank($value) < $rank($eff->$field)) {
                $eff->trace[] = ['level' => $level, 'field' => $field, 'note' => 'clamped'];
                continue;
            }
            $eff->$field = $value;
            $eff->sources[$field] = $level;
        }
    }

    /**
     * Whether a stored value is valid for a field.
     *
     * @param string $field
     * @param string $value
     * @return bool
     */
    public static function is_valid_value(string $field, string $value): bool {
        if ($field === 'pseudotype') {
            return catalogue::is_type($value);
        }
        if ($field === 'scopetype') {
            return array_key_exists($value, catalogue::scopes());
        }
        foreach (catalogue::DISCLOSURES as $category) {
            if (catalogue::disclosure_field($category) === $field) {
                return array_key_exists($value, catalogue::disclosure_values_of($category));
            }
        }
        return false;
    }

    /**
     * Whether the effective policy changes anything in a launch.
     *
     * @param \stdClass $eff
     * @return bool
     */
    public static function is_active(\stdClass $eff): bool {
        if ($eff->pseudotype !== catalogue::TYPE_NONE) {
            return true;
        }
        foreach (catalogue::DISCLOSURES as $category) {
            if ($eff->{catalogue::disclosure_field($category)} !== catalogue::DISC_PASS) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compact representation of a row for the audit trail (no secrets exist in a policy row).
     *
     * @param \stdClass|null $row
     * @return string|null JSON
     */
    public static function snapshot(?\stdClass $row): ?string {
        if ($row === null) {
            return null;
        }
        $keys = ['pseudotype', 'scopetype', 'allowoverride'];
        foreach (catalogue::DISCLOSURES as $category) {
            $keys[] = catalogue::disclosure_field($category);
        }
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = $row->$key ?? null;
        }
        return json_encode($data);
    }

    /**
     * Validate a proposed row against its parents and return human readable problems.
     *
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @param \stdClass $proposed
     * @return string[] lang string identifiers of the problems found
     */
    public static function validate(int $typeid, int $courseid, int $cmid, \stdClass $proposed): array {
        $problems = [];
        $level = self::level_of($typeid, $courseid, $cmid);
        $proposed->courseid = $courseid;
        $proposed->cmid = $cmid;
        $eff = self::resolve($typeid, $courseid, $cmid, $proposed);
        foreach ($eff->trace as $note) {
            if ($note['level'] === $level && $note['note'] === 'clamped') {
                $problems[] = 'err:weakerthanparent:' . $note['field'];
            } else if ($note['level'] === $level && $note['note'] === 'ignored') {
                $problems[] = 'err:parentforbids';
            } else if ($note['level'] === $level && $note['note'] === 'invalid') {
                $problems[] = 'err:invalidvalue:' . $note['field'];
            }
        }
        $type = $proposed->pseudotype ?? null;
        if ($type === catalogue::TYPE_LEGACY_USERID && !config::legacy_enabled()) {
            $problems[] = 'err:legacydisabled';
        }
        return $problems;
    }

    /**
     * Create or update the policy of a level, with audit and event.
     *
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @param \stdClass $data field values (NULL or empty inherits)
     * @param int $actorid acting user
     * @return \stdClass the stored row
     * @throws \moodle_exception when the proposal is weaker than its parent
     */
    public static function save(int $typeid, int $courseid, int $cmid, \stdClass $data, int $actorid): \stdClass {
        global $DB;
        $problems = self::validate($typeid, $courseid, $cmid, clone $data);
        if ($problems) {
            throw new \moodle_exception('policyrejected', config::COMPONENT, '', implode(', ', $problems));
        }
        $now = time();
        $old = self::get_row($typeid, $courseid, $cmid);
        $row = new \stdClass();
        $row->typeid = ($cmid > 0) ? 0 : $typeid;
        $row->courseid = $courseid;
        $row->cmid = $cmid;
        $fields = ['pseudotype', 'scopetype'];
        foreach (catalogue::DISCLOSURES as $category) {
            $fields[] = catalogue::disclosure_field($category);
        }
        foreach ($fields as $field) {
            $value = $data->$field ?? null;
            $row->$field = ($value === '' || $value === null) ? null : (string) $value;
        }
        $row->allowoverride = !empty($data->allowoverride) ? 1 : 0;
        $row->usermodified = $actorid;
        $row->timemodified = $now;
        if ($old) {
            $row->id = $old->id;
            $DB->update_record('ltisource_ltipseudonym_pol', $row);
            $row->timecreated = $old->timecreated;
        } else {
            $row->timecreated = $now;
            $row->id = $DB->insert_record('ltisource_ltipseudonym_pol', $row);
        }
        audit::log(
            $old ? 'policy_updated' : 'policy_created',
            $typeid,
            $courseid,
            $cmid,
            $actorid,
            self::snapshot($old),
            self::snapshot($row)
        );
        return $row;
    }

    /**
     * Remove the policy of a level (it inherits again).
     *
     * @param int $typeid
     * @param int $courseid
     * @param int $cmid
     * @param int $actorid
     * @return bool whether a row existed
     */
    public static function delete(int $typeid, int $courseid, int $cmid, int $actorid): bool {
        global $DB;
        $old = self::get_row($typeid, $courseid, $cmid);
        if (!$old) {
            return false;
        }
        $DB->delete_records('ltisource_ltipseudonym_pol', ['id' => $old->id]);
        audit::log('policy_deleted', $typeid, $courseid, $cmid, $actorid, self::snapshot($old), null);
        return true;
    }
}
