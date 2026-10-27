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

namespace ltisource_ltipseudonym\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * The mapping table is exported to and deleted for the user it describes. Deleting a mapping does
 * not touch the external tool: the tool keeps whatever it stored under the pseudonym, and grades
 * already returned to the gradebook stay with the (local) user record. After deletion nobody can
 * link the tool's records back to the person on this platform.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('ltisource_ltipseudonym_map', [
            'userid' => 'privacy:metadata:mapping:userid',
            'typeid' => 'privacy:metadata:mapping:typeid',
            'scopetype' => 'privacy:metadata:mapping:scope',
            'scopeid' => 'privacy:metadata:mapping:scope',
            'token' => 'privacy:metadata:mapping:token',
            'version' => 'privacy:metadata:mapping:version',
            'status' => 'privacy:metadata:mapping:status',
            'timecreated' => 'privacy:metadata:mapping:time',
            'timelastused' => 'privacy:metadata:mapping:time',
        ], 'privacy:metadata:mapping');
        $collection->add_database_table('ltisource_ltipseudonym_pol', [
            'usermodified' => 'privacy:metadata:policy:usermodified',
            'timemodified' => 'privacy:metadata:policy:timemodified',
        ], 'privacy:metadata:policy');
        $collection->add_database_table('ltisource_ltipseudonym_aud', [
            'actorid' => 'privacy:metadata:audit:actorid',
            'timecreated' => 'privacy:metadata:audit:timecreated',
            'action' => 'privacy:metadata:audit:action',
        ], 'privacy:metadata:audit');
        // The pseudonym, and - whatever the policy - the local user id as login_hint (data protection boundary, SP-01).
        $collection->add_external_location_link('lti_provider', [
            'pseudonym' => 'privacy:metadata:external:pseudonym',
            'userid' => 'privacy:metadata:external:userid',
        ], 'privacy:metadata:external');
        return $collection;
    }

    /**
     * SQL fragments selecting, for a table alias, the rows that belong to each context level.
     *
     * @return array[] list of [context level, join condition on the context alias ctx, row filter]
     */
    private static function config_levels(): array {
        return [
            [CONTEXT_MODULE, 'ctx.instanceid = %1$s.cmid', '%1$s.cmid > 0'],
            [CONTEXT_COURSE, 'ctx.instanceid = %1$s.courseid', '%1$s.cmid = 0 AND %1$s.courseid > 0'],
            [CONTEXT_SYSTEM, '1 = 1', '%1$s.cmid = 0 AND %1$s.courseid = 0'],
        ];
    }

    /**
     * Contexts that contain data of the user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id FROM {context} ctx JOIN {ltisource_ltipseudonym_map} m ON m.userid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND m.userid = :userid",
            ['userlevel' => CONTEXT_USER, 'userid' => $userid]
        );
        $i = 0;
        $tables = ['ltisource_ltipseudonym_pol' => 'usermodified', 'ltisource_ltipseudonym_aud' => 'actorid'];
        foreach ($tables as $table => $column) {
            foreach (self::config_levels() as [$level, $join, $filter]) {
                $i++;
                $contextlist->add_from_sql(
                    "SELECT ctx.id FROM {context} ctx JOIN {{$table}} t ON " . sprintf($join, 't') .
                    " WHERE ctx.contextlevel = :lvl$i AND t.$column = :uid$i AND " . sprintf($filter, 't'),
                    ["lvl$i" => $level, "uid$i" => $userid]
                );
            }
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_USER) {
            if ($DB->record_exists('ltisource_ltipseudonym_map', ['userid' => $context->instanceid])) {
                $userlist->add_user($context->instanceid);
            }
            return;
        }
        [$where, $params] = self::context_filter($context);
        if ($where === null) {
            return;
        }
        $userlist->add_from_sql(
            'usermodified',
            "SELECT usermodified FROM {ltisource_ltipseudonym_pol} WHERE $where AND usermodified > 0",
            $params
        );
        $userlist->add_from_sql(
            'actorid',
            "SELECT actorid FROM {ltisource_ltipseudonym_aud} WHERE $where AND actorid > 0",
            $params
        );
    }

    /**
     * Row filter of the configuration tables for a non-user context.
     *
     * @param \context $context
     * @return array [sql, params] or [null, []] when the context cannot hold configuration
     */
    private static function context_filter(\context $context): array {
        switch ($context->contextlevel) {
            case CONTEXT_MODULE:
                return ['cmid = :ctxid', ['ctxid' => $context->instanceid]];
            case CONTEXT_COURSE:
                return ['cmid = 0 AND courseid = :ctxid', ['ctxid' => $context->instanceid]];
            case CONTEXT_SYSTEM:
                return ['cmid = 0 AND courseid = 0', []];
        }
        return [null, []];
    }

    /**
     * Export the data of a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $base = [get_string('pluginname', 'ltisource_ltipseudonym')];
            if ($context->contextlevel == CONTEXT_USER) {
                if ($context->instanceid != $userid) {
                    continue;
                }
                $rows = $DB->get_records('ltisource_ltipseudonym_map', ['userid' => $userid], 'id');
                $data = [];
                foreach ($rows as $row) {
                    $data[] = (object) [
                        'tooltypeid' => $row->typeid,
                        'scope' => $row->scopetype,
                        'scopeid' => $row->scopeid,
                        'pseudonym' => $row->token,
                        'pseudonymtype' => $row->ptype,
                        'version' => $row->version,
                        'status' => $row->status,
                        'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                        'timelastused' => \core_privacy\local\request\transform::datetime($row->timelastused),
                    ];
                }
                writer::with_context($context)->export_data($base, (object) ['mappings' => $data]);
                continue;
            }
            [$where, $params] = self::context_filter($context);
            if ($where === null) {
                continue;
            }
            $uparams = $params + ['uid' => $userid];
            $policies = $DB->get_records_select('ltisource_ltipseudonym_pol', "$where AND usermodified = :uid", $uparams);
            $audits = $DB->get_records_select('ltisource_ltipseudonym_aud', "$where AND actorid = :uid", $uparams);
            $data = (object) [
                'policies' => array_map(fn($p) => (object) [
                    'tooltypeid' => $p->typeid,
                    'timemodified' => \core_privacy\local\request\transform::datetime($p->timemodified),
                ], array_values($policies)),
                'audit' => array_map(fn($a) => (object) [
                    'action' => $a->action,
                    'tooltypeid' => $a->typeid,
                    'time' => \core_privacy\local\request\transform::datetime($a->timecreated),
                ], array_values($audits)),
            ];
            writer::with_context($context)->export_data($base, $data);
        }
    }

    /**
     * Delete all personal data of all users in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel == CONTEXT_USER) {
            $DB->delete_records('ltisource_ltipseudonym_map', ['userid' => $context->instanceid]);
            return;
        }
        [$where, $params] = self::context_filter($context);
        if ($where !== null) {
            self::anonymise($where, $params);
        }
    }

    /**
     * Delete the data of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER) {
                if ($context->instanceid == $userid) {
                    $DB->delete_records('ltisource_ltipseudonym_map', ['userid' => $userid]);
                }
                continue;
            }
            [$where, $params] = self::context_filter($context);
            if ($where !== null) {
                self::anonymise($where, $params, [$userid]);
            }
        }
    }

    /**
     * Delete the data of several users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        if ($context->contextlevel == CONTEXT_USER) {
            if (in_array($context->instanceid, $userids)) {
                $DB->delete_records('ltisource_ltipseudonym_map', ['userid' => $context->instanceid]);
            }
            return;
        }
        [$where, $params] = self::context_filter($context);
        if ($where !== null) {
            self::anonymise($where, $params, $userids);
        }
    }

    /**
     * Detach configuration records from the people who made them. The configuration itself and the
     * audit entries stay, because they document how identities were issued.
     *
     * @param string $where row filter
     * @param array $params its parameters
     * @param int[]|null $userids restrict to these users, null for all
     */
    private static function anonymise(string $where, array $params, ?array $userids = null): void {
        global $DB;
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
            $params += $inparams;
            $policyfilter = " AND usermodified $insql";
            $auditfilter = " AND actorid $insql";
        } else {
            $policyfilter = ' AND usermodified > 0';
            $auditfilter = ' AND actorid > 0';
        }
        $DB->set_field_select('ltisource_ltipseudonym_pol', 'usermodified', 0, $where . $policyfilter, $params);
        $DB->set_field_select('ltisource_ltipseudonym_aud', 'actorid', 0, $where . $auditfilter, $params);
    }
}
