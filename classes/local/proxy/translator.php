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

namespace ltisource_ltipseudonym\local\proxy;

use ltisource_ltipseudonym\local\audit;
use ltisource_ltipseudonym\local\catalogue;
use ltisource_ltipseudonym\local\config;
use ltisource_ltipseudonym\local\policy_manager;
use ltisource_ltipseudonym\local\token_service;

/**
 * Translation between the pseudonym a tool knows and the local user id that the core services use (finding SP-02).
 *
 * Moodle core identifies users by their local id in the memberships (NRPS) and grade (AGS) services and has no
 * extension point for a translation. The service proxy runs the unchanged core resources and uses this class
 * to translate what goes in (the user of a score, the user filter of a result query) and what comes out (members,
 * results). It never resolves a pseudonym for a tool other than the one the bearer token was issued to: the
 * mapping row must be active and must carry exactly the coordinates (tool, scope, pseudonym type) that the policy
 * of the requested activity would have used for a launch.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class translator {
    /**
     * @var int Version of the read-only translation contract used by other plugins (local_lticompletionsync):
     * context_for(), user_of(), pseudonym_of() and context::pseudonymising(). 1 = 0.2.0; 2 = 0.3.0 (context_for() refuses
     * coordinates that do not belong together, pseudonym_of() added). Raised only when the contract changes.
     */
    public const API_VERSION = 2;

    /** @var int A local user id that does not exist, used to produce an empty result set. */
    public const NO_USER = 2147483647;

    /** @var string Claim of a LTI 1.1 basic outcome in a NRPS member message. */
    private const BASICOUTCOME_CLAIM = 'https://purl.imsglobal.org/spec/lti-bo/claim/basicoutcome';

    /** @var token_service Pseudonym source. */
    private $tokens;

    /**
     * Constructor.
     *
     * @param token_service|null $tokens
     */
    public function __construct(?token_service $tokens = null) {
        $this->tokens = $tokens ?? new token_service();
    }

    /**
     * The identity context of a request, or null when the policy changes nothing for it.
     *
     * The coordinates are bound to each other before any policy is evaluated (finding SP-03): the tool registration must
     * be usable in the course (a site tool or a tool of that course), and an addressed activity must be an LTI activity
     * of that course that launches exactly this tool registration. Coordinates that do not belong together are refused,
     * so that a request can never be answered with the policy, the scope or the pseudonyms of another tool or course.
     *
     * @param \stdClass|null $type lti_types record of the authenticated tool
     * @param int $courseid
     * @param int $cmid course module of the addressed activity (0 when none)
     * @return context|null
     * @throws refusal 404 when the course or the activity does not belong to the tool
     */
    public function context_for(?\stdClass $type, int $courseid, int $cmid): ?context {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        if (!$type || empty($type->id) || $type->ltiversion !== LTI_VERSION_1P3) {
            return null;
        }
        $this->assert_binding($type, $courseid, $cmid);
        $policy = policy_manager::resolve((int) $type->id, $courseid, $cmid);
        if (!policy_manager::is_active($policy)) {
            return null;
        }
        return new context((int) $type->id, $courseid, $cmid, $policy);
    }

    /**
     * Refuse coordinates that do not belong together: tool, course and activity.
     *
     * @param \stdClass $type lti_types record
     * @param int $courseid
     * @param int $cmid 0 when no activity is addressed
     * @throws refusal 404
     */
    public function assert_binding(\stdClass $type, int $courseid, int $cmid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            throw new refusal(404, 'Not Found: unknown course');
        }
        $typecourse = (int) ($type->course ?? SITEID);
        if ($typecourse !== (int) SITEID && $typecourse !== $courseid) {
            throw new refusal(404, 'Not Found: the tool is not available in the course');
        }
        if ($cmid <= 0) {
            return;
        }
        $cm = get_coursemodule_from_id('lti', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || (int) $cm->course !== $courseid) {
            throw new refusal(404, 'Not Found: LTI link is not part of the course');
        }
        $lti = $DB->get_record('lti', ['id' => $cm->instance], '*', IGNORE_MISSING);
        $linktype = $lti ? lti_get_instance_type($lti) : null;
        if (!$linktype || (int) $linktype->id !== (int) $type->id) {
            throw new refusal(404, 'Not Found: LTI link does not belong to the tool');
        }
    }

    /**
     * Course module of an LTI activity instance, checked against the course of the request.
     *
     * @param int $ltiid lti instance id (0 for none)
     * @param int $courseid course of the request
     * @return int cmid, 0 when the instance has no course module
     * @throws refusal 404 when the activity belongs to another course
     */
    public function cmid_of_link(int $ltiid, int $courseid): int {
        if ($ltiid <= 0) {
            return 0;
        }
        $cm = get_coursemodule_from_instance('lti', $ltiid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return 0;
        }
        if ((int) $cm->course !== $courseid) {
            throw new refusal(404, 'Not Found: LTI link is not part of the course');
        }
        return (int) $cm->id;
    }

    /**
     * Course module a line item belongs to (coupled to an activity, or a standalone item linked to one).
     *
     * @param int $itemid grade item id
     * @param int $courseid course of the request
     * @return int cmid, 0 when the line item is not linked to an activity
     */
    public function cmid_of_lineitem(int $itemid, int $courseid): int {
        global $DB;
        $item = $DB->get_record('grade_items', ['id' => $itemid], 'id,courseid,itemtype,itemmodule,iteminstance');
        if (!$item || (int) $item->courseid !== $courseid) {
            return 0;
        }
        $ltiid = 0;
        if ($item->itemtype === 'mod' && $item->itemmodule === 'lti') {
            $ltiid = (int) $item->iteminstance;
        } else {
            $ltiid = (int) $DB->get_field('ltiservice_gradebookservices', 'ltilinkid', ['gradeitemid' => $itemid]);
        }
        try {
            return $this->cmid_of_link($ltiid, $courseid);
        } catch (refusal $e) {
            return 0;
        }
    }

    /**
     * Refuse a request that cannot be answered unambiguously with the scope of the policy.
     *
     * With the scope "cm" the pseudonym of a user depends on the activity, so a request must name the activity.
     *
     * @param context $ctx
     * @throws refusal 400
     */
    public function require_activity_for_scope(context $ctx): void {
        if ($ctx->pseudonymising() && $ctx->policy->scopetype === catalogue::SCOPE_CM && $ctx->cmid <= 0) {
            throw new refusal(400, 'The pseudonym scope is the activity: name the activity (rlid) in the request');
        }
    }

    /**
     * Pseudonyms of several users in the scope of a context.
     *
     * @param context $ctx
     * @param int[] $userids
     * @return string[] local user id => pseudonym
     */
    public function pseudonyms_for(context $ctx, array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        $policy = $ctx->policy;
        [$mtype, $mscope, $mscopeid] = token_service::scope_coordinates(
            $policy->scopetype,
            $ctx->typeid,
            $ctx->courseid,
            $ctx->cmid
        );
        $result = [];
        foreach (array_chunk($userids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params += [
                'mtype' => $mtype, 'mscope' => $mscope, 'mscopeid' => $mscopeid,
                'ptype' => $policy->pseudotype, 'version' => config::generation($mtype),
            ];
            $rows = $DB->get_records_select(
                'ltisource_ltipseudonym_map',
                "userid $insql AND typeid = :mtype AND scopetype = :mscope AND scopeid = :mscopeid
                 AND ptype = :ptype AND version = :version AND status = 'active'",
                $params,
                '',
                'id, userid, token'
            );
            foreach ($rows as $row) {
                $result[(int) $row->userid] = $row->token;
            }
        }
        foreach ($userids as $userid) {
            if (!isset($result[$userid])) {
                $result[$userid] = $this->tokens->get_or_create($userid, $mtype, $mscope, $mscopeid, $policy->pseudotype);
            }
        }
        return $result;
    }

    /**
     * The pseudonym of one local user in the scope of a context (forward translation; created on first use like a launch).
     *
     * @param context $ctx a pseudonymising context
     * @param int $userid
     * @return string
     * @throws \coding_exception when the context does not pseudonymise
     */
    public function pseudonym_of(context $ctx, int $userid): string {
        if (!$ctx->pseudonymising()) {
            throw new \coding_exception('The policy of this context does not pseudonymise');
        }
        $this->require_activity_for_scope($ctx);
        return $this->pseudonyms_for($ctx, [$userid])[$userid];
    }

    /**
     * Resolve a pseudonym to the local user id of the tool in this context.
     *
     * The mapping must be active, be of the pseudonym type of the policy, belong to the tool the bearer token was
     * issued to (or to the site scope when the policy of this tool uses it) and be of the scope that the policy
     * applies to the addressed course or activity. Everything else is a miss: it is audited without the value and
     * answered as "unknown user".
     *
     * @param context $ctx
     * @param string $pseudonym value received from the tool
     * @param string $service ags or nrps, for the audit
     * @return int local user id
     * @throws refusal 404 on a miss
     */
    public function user_of(context $ctx, string $pseudonym, string $service): int {
        global $DB;
        $row = ($pseudonym === '') ? null : token_service::lookup($pseudonym);
        $reason = 'unknown';
        $userid = 0;
        if ($row) {
            $reason = $this->mismatch($ctx, $row);
            $userid = (int) $row->userid;
        }
        if ($reason !== '') {
            audit::log(
                'service_pseudonym_miss',
                $ctx->typeid,
                $ctx->courseid,
                $ctx->cmid,
                0,
                null,
                json_encode(['service' => $service, 'reason' => $reason])
            );
            throw new refusal(404, 'Not Found: unknown user');
        }
        return $userid;
    }

    /**
     * Why a mapping row cannot be used for a context (empty string when it can).
     *
     * @param context $ctx
     * @param \stdClass $row mapping row
     * @return string reason code, empty when the row matches
     */
    private function mismatch(context $ctx, \stdClass $row): string {
        global $DB;
        $policy = $ctx->policy;
        if (!$ctx->pseudonymising()) {
            return 'policy';
        }
        if ($row->status !== 'active') {
            return 'retired';
        }
        if ($row->ptype !== $policy->pseudotype || $row->scopetype !== $policy->scopetype) {
            return 'policy';
        }
        $cmid = $ctx->cmid;
        if ($policy->scopetype === catalogue::SCOPE_CM && $cmid <= 0) {
            // A request without an activity: the mapping names it, and it must belong to this tool and course.
            $cmid = (int) $row->scopeid;
            $cm = $cmid > 0 ? get_coursemodule_from_id('lti', $cmid, 0, false, IGNORE_MISSING) : false;
            $cmtype = $cm ? (int) $DB->get_field('lti', 'typeid', ['id' => $cm->instance]) : 0;
            if (!$cm || (int) $cm->course !== $ctx->courseid || $cmtype !== $ctx->typeid) {
                return 'scope';
            }
        }
        try {
            $expected = token_service::scope_coordinates($policy->scopetype, $ctx->typeid, $ctx->courseid, $cmid);
        } catch (\moodle_exception $e) {
            return 'scope';
        }
        if ((int) $row->typeid !== $expected[0] || $row->scopetype !== $expected[1] || (int) $row->scopeid !== $expected[2]) {
            return ((int) $row->typeid !== $expected[0]) ? 'tool' : 'scope';
        }
        return '';
    }

    /**
     * Whether a disclosure category keeps its values (pass) under the policy of a context.
     *
     * @param context $ctx
     * @param string $category catalogue disclosure category
     * @return bool
     */
    private function passes(context $ctx, string $category): bool {
        return $ctx->policy->{catalogue::disclosure_field($category)} === catalogue::DISC_PASS;
    }

    /**
     * Replace the local ids and apply the disclosure policy in a NRPS response body.
     *
     * Names, e-mail address, user name and organisation id are removed from the members (not emptied) unless the
     * policy passes them, and the LTI 1.1 basic outcome of a member, which carries the local user id, is removed
     * unless the policy passes it. Both NRPS representations of core are handled.
     *
     * @param context $ctx
     * @param string $body JSON written by the core memberships service
     * @return string JSON for the tool
     * @throws refusal
     */
    public function filter_members(context $ctx, string $body): string {
        $data = json_decode($body);
        if (!is_object($data)) {
            throw new refusal(500, 'Unreadable membership container');
        }
        $this->require_activity_for_scope($ctx);

        if (isset($data->members) && is_array($data->members)) {
            $members = $data->members;
            $fields = [
                'userid' => 'user_id', 'name' => ['name', 'given_name', 'family_name'], 'email' => ['email'],
                'username' => ['ext_user_username'], 'sourcedid' => ['lis_person_sourcedid'],
            ];
        } else if (isset($data->pageOf->membershipSubject->membership) && is_array($data->pageOf->membershipSubject->membership)) {
            $members = array_map(fn($membership) => $membership->member, $data->pageOf->membershipSubject->membership);
            $fields = [
                'userid' => 'userId', 'name' => ['name', 'givenName', 'familyName'], 'email' => ['email'],
                'username' => ['ext_user_username'], 'sourcedid' => ['sourcedId'],
            ];
        } else {
            throw new refusal(500, 'Unknown membership container');
        }

        $pseudonyms = [];
        if ($ctx->pseudonymising()) {
            $userids = [];
            foreach ($members as $member) {
                $userids[] = (int) $member->{$fields['userid']};
            }
            $pseudonyms = $this->pseudonyms_for($ctx, $userids);
        }
        foreach ($members as $member) {
            if ($ctx->pseudonymising()) {
                $member->{$fields['userid']} = $pseudonyms[(int) $member->{$fields['userid']}];
            }
            foreach (['name', 'email', 'username', 'sourcedid'] as $category) {
                if (!$this->passes($ctx, $category)) {
                    foreach ($fields[$category] as $field) {
                        unset($member->$field);
                    }
                }
            }
        }
        if (!$this->passes($ctx, 'result')) {
            $this->remove_outcomes($data);
        }
        return json_encode($data);
    }

    /**
     * Remove the LTI 1.1 basic outcome (result sourcedid and outcome service url) of every member message.
     *
     * @param \stdClass $data decoded membership container
     */
    private function remove_outcomes(\stdClass $data): void {
        foreach ($data->members ?? [] as $member) {
            foreach ($member->message ?? [] as $message) {
                unset($message->{self::BASICOUTCOME_CLAIM});
            }
        }
        foreach ($data->pageOf->membershipSubject->membership ?? [] as $membership) {
            foreach ($membership->message ?? [] as $message) {
                unset($message->lis_result_sourcedid);
            }
            unset($membership->member->resultSourcedId);
        }
    }

    /**
     * Translate the body of a score submission: the pseudonym in userId becomes the local user id.
     *
     * @param context $ctx
     * @param string $body request body
     * @return array|null null when the body is not a score the translation applies to (core then answers the error),
     *         else ['translated' => new body, 'localid' => int, 'pseudonym' => string]
     * @throws refusal 400 for a malformed userId, 404 for an unknown pseudonym
     */
    public function translate_score(context $ctx, string $body): ?array {
        $score = json_decode($body);
        if (!is_object($score) || !isset($score->userId)) {
            return null;
        }
        if (!is_string($score->userId) || $score->userId === '') {
            throw new refusal(400, 'userId must be the subject of the launch');
        }
        $pseudonym = $score->userId;
        $localid = $this->user_of($ctx, $pseudonym, 'ags');
        $score->userId = (string) $localid;
        return ['translated' => json_encode($score), 'localid' => $localid, 'pseudonym' => $pseudonym, 'original' => $body];
    }

    /**
     * Remove the local id from the answer of core to a translated score (error texts quote the request).
     *
     * @param \mod_lti\local\ltiservice\response $response
     * @param array $translation result of translate_score()
     */
    public function scrub_reason($response, array $translation): void {
        if ($response->get_code() < 400) {
            return;
        }
        $reason = $response->get_reason();
        $reason = str_replace($translation['translated'], $translation['original'], $reason);
        $reason = preg_replace('/(user )' . preg_quote((string) $translation['localid'], '/') . '\b/', '${1}[user]', $reason);
        $response->set_reason($reason);
    }

    /**
     * Replace the local ids in the body of a result query by pseudonyms.
     *
     * @param context $ctx
     * @param string $body JSON array written by the core results resource
     * @return string
     * @throws refusal
     */
    public function filter_results(context $ctx, string $body): string {
        $results = json_decode($body);
        if (!is_array($results)) {
            throw new refusal(500, 'Unreadable result container');
        }
        $this->require_activity_for_scope($ctx);
        $pseudonyms = $this->pseudonyms_for($ctx, array_map(fn($r) => (int) $r->userId, $results));
        foreach ($results as $result) {
            $pseudonym = $pseudonyms[(int) $result->userId];
            $result->id = preg_replace('/([?&]user_id=)\d+/', '${1}' . $pseudonym, $result->id);
            $result->userId = $pseudonym;
        }
        return json_encode($results);
    }
}
