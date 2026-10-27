# Documentation

Developer documentation of `ltisource_ltipseudonym` 0.2.0 (alpha). Status wording follows
[ACCEPTANCE.md](ACCEPTANCE.md). Index: [NAMING](NAMING.md), [SECURITY](SECURITY.md), [PRIVACY](PRIVACY.md),
[TESTING](TESTING.md), [ILIAS-COMPARISON](ILIAS-COMPARISON.md).

## Architecture

```
mod_lti lti_get_launch_data() / content-item request builder
   -> component_callback ltisource_ltipseudonym_before_launch()      lib.php
        -> launch_processor        (identify the tool, LTI 1.3 only, fail closed)
             -> policy_manager     (resolve site -> type -> course -> cm, clamping)
             -> services\proxy_path (active policy: service URLs of the id token move to the proxy)
             -> claim_guard        (claims that must be absent still built by core? refuse)
             -> token_service      (atomic get-or-create in ..._map, HMAC derivation)
        <- array of replaced request parameters (array_merge in core)
```

| Class (`classes/local`) | Responsibility |
|---|---|
| `catalogue` | Types, scopes, disclosure categories, ranks and identifiability ratings; maps launch parameters to categories. |
| `config` | Plugin settings and the generation counter per tool type. |
| `policy_manager` | Level resolution, clamping (never weaker), validation, save/delete with audit and event. |
| `token_service` | Mapping table access, `random_persisted` and `hmac_secret_v1`, secret handling. |
| `service_info` | Which user-identifying services (AGS/NRPS) a tool registration has enabled (information for the overview). |
| `proxy\dispatcher`, `proxy\translator`, `services\*` | The identity-aware service proxy (see below). |
| `claim_guard` | Which claims core builds for a tool registration; enforces the disclosure value `remove`. |
| `launch_processor` | The callback logic; wraps every unexpected error into `launchrefused`. |
| `migration_service` | Preview, rotation, re-binding after tool re-registration, orphan and retired purge. |
| `audit`, `overview_builder`, `observer` | Audit rows (never tokens), UI data, cleanup on user/course/activity deletion. |

Tables (names use the full component prefix, the xmldb limit is 28 characters, see [NAMING](NAMING.md)):
`ltisource_ltipseudonym_pol` (policy rows per level), `_map` (userid, typeid, scopetype, scopeid, ptype, token,
version, status, times; unique on the identity tuple and on the token), `_aud` (actor, context, old/new policy JSON, never tokens).

### Policy semantics

* Levels: site (type id 0), tool type, course (per tool type), activity. A lower level counts only if the levels above allow
  overrides; an absent type row blocks deeper rows.
* Clamping: pseudonym strength random (3) > hmac (2) > legacy/none (0); scope narrowness site < deployment < course < cm;
  disclosure `remove` beats `blank` beats `pass`. A weaker override is rejected on save and ignored (clamped) on resolve.
* A pseudonymising policy that nobody gave an explicit `discresult` empties the LTI 1.1 result id (it carries the local user id).
  An explicit `pass` keeps it (documented leak), `remove` refuses the launch while core builds it.

### Token semantics

* Mapping key: user, tool type, scope type/id, pseudonym type, generation. Switching the pseudonym type and back restores the
  same token (reversible rollback). Rotation retires the active rows and bumps the generation.
* HMAC input: JSON of plugin, `hmac_secret_v1`, generation, wwwroot, client id, type id, scope type/id, user id; the 32-byte
  secret is stored encrypted via `\core\encryption`.
* Race: read, insert, on `dml_exception` re-read the winner. The unique index is the arbiter.

## Gates and evidence

| Gate | Result | Evidence |
|---|---|---|
| SP-G1 `login_hint` | **Open, boundary declared (0.2.0).** `lti_build_login_request()` sets `login_hint` to the local id without any extension point, and `mod/lti/auth.php` rejects every other value, so the hint cannot be decoupled by a plugin. See "Data protection boundary" below. | `login_hint_boundary_test::*`, `launch_test::test_login_hint_still_exposes_the_local_id`; Playwright trace. |
| SP-G2 AGS/NRPS | **Closed by the service proxy (0.2.0)** except for LTI 1.1 basic outcomes. Core resolves local ids (evidence); the proxy translates. See "Service proxy" below. | Core behaviour: `service_compat_test::*`. Proxy: `service_proxy_test::*`, `launch_test::test_services_launch_*`, `test_basic_outcomes_are_the_one_strict_boundary`; two-site run in `tests/playwright`. |
| SP-G3 callback reaches the plugin | Works on 4.5 and 5.3 (see TESTING for the combos run). | `launch_test::test_core_calls_the_callback_by_its_component_name`. |
| SP-G4 activity form hook | **No hook.** Fallback: policy page per course, linked from the course navigation. | `wiring_test::test_activity_form_has_no_plugin_hook`, `test_course_navigation`. |

### Service proxy (SP-02)

Core's memberships (NRPS) and grade (AGS) services identify users by the local id and have no extension point. The plugin therefore
ships `service.php`, an endpoint with the same path templates as `mod/lti/services.php`:

* `classes/local/services/{memberships,gradebookservices}.php` extend the core services and override `get_service_path()`. Core builds
  every endpoint, resource id and pagination link from `get_service()->get_service_path()`, so they all point to the proxy. Line
  items are served by the unchanged core resources; the four resources with user data are thin subclasses
  (`classes/local/services/resources`).
* `classes/local/proxy/dispatcher` replicates the dispatch of `services.php` for these two services. Authentication (bearer token in
  `lti_access_tokens`), scopes and the token endpoint are core's.
* Launch: when the effective policy is active in any way (pseudonymisation, or a disclosure value other than `pass`),
  `launch_processor` moves every `custom_*` service URL that starts with `/mod/lti/services.php/` (memberships, line items, ...)
  to the proxy. A disclosure-only policy keeps the sub (and the roster `user_id`) as the local id and applies the disclosure
  policy to the roster; a policy that changes nothing leaves the URLs to core (negative control in the tests).
* NRPS: the answer of core is translated: `user_id` becomes the pseudonym the user receives in a launch of that activity (same
  policy, same scope; with the scope "activity" the request must name the activity with `rlid`, else 400). Names, e-mail, user name
  and ID number are removed (not emptied) unless the policy passes them; the LTI 1.1 basic outcome of a member is removed unless
  `discresult` is `pass`.
* AGS scores: the pseudonym in `userId` is looked up in the mapping table. The row must be active, of the pseudonym type of the
  policy, and carry exactly the tool, scope and course/activity that the policy of the line item's activity requires. Otherwise the
  answer is 404 and an audit entry without the value is written. Error texts of core that quote the request are scrubbed of the local id.
* AGS results: the `user_id` filter takes the pseudonym (unknown: empty set), `userId` and the ids of the answer carry pseudonyms.
* Security: no new authentication; a token never resolves a pseudonym of another tool; the superglobal `$_GET['user_id']` that core
  reads is restored after the request; any failure of the translation answers 500 with an empty body, never the unfiltered answer.

Core boundary for the LTI 1.1 result id (SP-03): the `before_launch` results are merged with `array_merge()` in
`mod/lti/locallib.php` (`lti_get_launch_data()`, the `component_callback('ltisource_...', 'before_launch', ...)` loop, 4.5: line 624),
so a callback can replace a parameter but never delete one, and `lti_sign_jwt()` (same file, 4.5: line 3345) puts every key of the
parameters into the id token, empty or not. `lis_result_sourcedid` (built when the tool accepts grades) carries the local user id and
therefore can only be blanked; the plugin blanks it by default under a pseudonymising policy, `pass` is a documented leak (overview
warning "outcomeleak"), and `remove` refuses the launch while core builds it.

Boundaries (stated in the settings page, ACCEPTANCE and SECURITY): LTI 1.1 basic outcomes (`mod/lti/service.php`, result sourcedid with
the local id) cannot be translated; a pseudonymised launch sends the result id empty unless `discresult` is `pass`. Policies that
differ for single activities of a course with a context-level roster request (no `rlid`) give pseudonyms of the course-level policy.
A standalone line item that is not linked to an activity cannot be answered with the activity scope for result queries (400).
Retired pseudonyms
(after a rotation) are not resolved: a late score of the old identity is refused.

### Data protection boundary: login_hint (SP-01, gate SP-G1)

What the tool operator receives before the id token: the browser posts a form to the tool's login URL containing `iss`,
`target_link_uri`, `lti_message_hint` and **`login_hint` = the local Moodle user id** (`lti_initiate_login()`). It is independent of
any policy. Why the plugin cannot change it: `lti_build_login_request()` contains no callback, hook or event, and
`mod/lti/auth.php` answers `access_denied` unless the returned `login_hint` equals `$USER->id`. Decoupling would need a core change
(a hook in the builder plus a matching translation in `auth.php`).

Consequences, stated everywhere the plugin is configured: pseudonymisation protects the id token (`sub`, `for_user_id`, claims)
and the data the policy controls; it does **not** hide the local user id from the tool operator, from proxies and server logs on
the tool side. Do not describe a launch as end-to-end pseudonymous. Where declared: README, this document, PRIVACY, SECURITY,
ACCEPTANCE, settings page, overview warning, policy form, course page, privacy metadata (`userid` external field).
Evidence: `login_hint_boundary_test` (form content, policy independence, absence of any extension point, `auth.php` check,
declarations in UI/privacy/docs) and the Playwright request trace. The tests go red when core changes this, which means the
gate must be re-assessed.

### Disclosure: empty claim versus removed claim (SP-03)

The callback result is merged with `array_merge`, so a parameter can be replaced but never deleted. Core signs what it gets:
an empty string yields an **empty claim**, `null` a JSON `null`, only an absent key yields no claim (`claims_evidence_test`).
Therefore:

* `pass`: core value unchanged.
* `blank`: the claim **stays** in the token with value `""` (if core builds it; a claim core does not build is never added).
  Texts say "send empty", never "not sent".
* `remove`: the claim must be **absent**. The plugin cannot delete it; it checks the parameters after core built them and
  **refuses the launch** if the parameter is still there (even with an empty value). It is satisfied when the tool registration
  makes core not build the claim. `claim_guard::core_sends()` predicts this from the type configuration for the editor
  (error) and the overview (error box); an activity-delegated setting is reported as "depends on the activity".
* The ID number (`lis_person_sourcedid`) is always built by core, so `remove` is not a valid value for it.

Enforced matrix (`disclosure_matrix_test::test_matrix`, 60 cells on real signed tokens; "built" = core builds the claim):

| Tool registration | pass | blank | remove |
|---|---|---|---|
| shares name, e-mail, grades (always) | claim with value | claim, `""` | launch refused |
| shares nothing | claim absent | claim absent (nothing added) | claim absent |
| activity decides, activity shares | claim with value | claim, `""` | launch refused |
| activity decides, activity shares nothing | claim absent | claim absent | claim absent |
| ID number (all registrations) | value | `""` | invalid value, ignored |

Custom claims (`custom_*` resolving to user variables) and deep-linking requests follow the same rules
(`test_custom_claims`, `test_deep_linking_request_claims`). Categories and claims: names = `given_name`, `family_name`, `name`;
e-mail = `email`; username = `ext.user_username`; result id = `basicoutcome.lis_result_sourcedid`; picture = `user_image` (core
never builds it).

### Other identity carriers

`lis_result_sourcedid` is a JSON blob containing the local user id; it leaks the id unless its disclosure is `blank`
(`test_result_sourcedid_leaks_the_id_unless_blanked`). Blanking it breaks classic (LTI 1.1 style) outcomes, which
is the compatibility trade-off the administrator decides. `for_user_id` is pseudonymised (`test_for_user_claim_is_pseudonymised`).

### Deep linking

The deep-linking request has a bare course object without a type id. The tool is identified by matching the endpoint
against the registered tool URLs. Several registrations with the same endpoint: a pseudonymising policy fails closed (the
deployment, hence the token, is unknown); a disclosure-only policy is evaluated per candidate and passes when the patches agree.
With an active site policy an unidentifiable request also fails closed. Activity scope falls back to course scope there because
no activity exists yet.

### Identity continuity (SP-04)

Specification, enforced by `identity_continuity_test` on real core flows (the person is the same teacher or learner):

| Event | site | deployment | course | cm (activity) |
|---|---|---|---|---|
| Deep-linking request, then launch of the created activity | same | same | same | **different**: the request uses the course scope pseudonym, the launch the activity one |
| Launch again / 100 launches | same | same | same | same |
| Move the activity to another section | same | same | same | same (same course module id) |
| Duplicate the activity | same | same | same | **new** (new course module id) |
| Copy the activity into another course (backup/restore) | same | same | **new** | **new** |
| Delete and recreate the activity | same | same | same | **new**; old mapping removed |
| Re-register the tool (new type id) | new | new | new | new; `rebind` with preview keeps the issued ones |
| Rotation (explicit, previewed, audited) | new | new | new | new; old rows retired, never reused |
| Merge of two users with tool_mergeusers (A merged into B) | scopes where only A had a pseudonym: B continues with **A's** pseudonym; scopes where both had one: B keeps **its own**, A's is retired (see "Account merges") |

Documented limit: with activity scope a tool that stores the person at deep linking sees a second identity at launch. Choose
course scope or wider when the tool correlates deep-linking and launch users. Restoring a backup on another site cannot keep
pseudonyms (different tool registration ids, mappings are never backed up).

## Account merges (tool_mergeusers, gate finding F2)

Without integration, tool_mergeusers moved `ltisource_ltipseudonym_map.userid` with its generic table merger. The unique index
`identity (userid, typeid, scopetype, scopeid, ptype, version)` was not declared to it, so on the first conflict its fallback ran
`DELETE ... WHERE userid = <merged-away user>` and removed **all** pseudonyms of that user, also those without a conflict, without
an audit entry (Gate 2, pair 3, all four combinations).

0.2.0 registers two callbacks for the hooks of tool_mergeusers (`db/hooks.php`, `classes/local/merge_integration.php`):

* `add_settings_before_merging`: the mapping table is listed in `exceptions` (taken out of the generic processing) and its unique
  index is declared in `compoundindexes` (a fallback for a site that re-enables the table through tool_mergeusers' setting
  `excluded_exceptions` or its custom settings; then tool_mergeusers resolves each conflicting row itself by deleting one side, which
  side depends on its setting `uniquekeynewidtomaintain`). String keys are used, because tool_mergeusers layers the settings with
  `array_replace_recursive()` and a numeric key would replace one of its default exceptions (`user_preferences`).
* `after_merged_all_tables` (inside the merge transaction) merges the table deterministically. For every row of the merged-away
  user A, keyed by tool, scope, pseudonym type and generation:
  * B has no row with that key: the row moves to B, the pseudonym is unchanged;
  * B has a row with that key: B's row wins and is unchanged; A's row stays on A and is set to `retired` if it was active.
    Nothing is deleted.
  One audit row `user_merged` per affected tool type holds the counts (`moved`, `conflicts`, `retired`), never a pseudonym; the
  log of tool_mergeusers gets one line with the totals. A repeated run changes nothing and writes no audit row. Any failure is
  reported as an error message of the merge, so tool_mergeusers rolls back the whole merge (fail closed).

What this means for the tool (the platform cannot change what the tool stored):

* Scopes where only A had a pseudonym: the tool keeps seeing the same person (A's pseudonym), now launched by B. A's history
  there continues.
* Scopes where both had one: the tool saw two people and keeps both records. B launches with B's pseudonym; A's pseudonym is
  retired, so the service proxy no longer resolves it (a late score or result query for A's pseudonym gets 404 and an audit
  entry with reason `retired`). A's history at the tool stays under A's pseudonym and is not linked to B. There is no
  cross-platform merge: the plugin does not tell the tool that two pseudonyms are one person, because that would disclose a
  correlation the pseudonymisation is meant to prevent. A merge at the tool, if wanted, is a decision of the tool operator.
* If tool_mergeusers later deletes account A, the `user_deleted` observer removes A's remaining (retired) rows; the proxy then
  answers 404 with reason `unknown` instead of `retired`.

Without tool_mergeusers the hook classes do not exist; core registers the callbacks without loading the class or printing a
debugging message and never dispatches them (`merge_integration_test::test_callbacks_are_harmless_without_tool_mergeusers`, run on
4.5 and 5.3). Other tables of the plugin: `pol.usermodified` is moved by tool_mergeusers' generic processing; `aud.actorid` is not in
its list of user columns and stays (append-only audit).

## Configuration

Settings: `legacyenabled` (default off), `retireddays` (365). (`servicesmode` and the policy field `ackservices` of the 0.1.0 alpha do not exist in this component.)

Installation and rename: 0.2.0 is the first release of `ltisource_ltipseudonym` (renamed by owner decision 2026-10-25; the former
component name is recorded in docs/CHANGELOG.md and docs/NAMING.md). There is no upgrade path from the 0.1.0 alpha: uninstall the
old plugin (its policies, mappings and audit rows are deleted), then install this one. `db/upgrade.php` has no steps.
Internal config: `hmacsecret_v1` (encrypted), `generation_<typeid>`. Setting changes are audited by Moodle's config log;
policy changes by the plugin's audit table and the `policy_changed` event.

## Backup, restore, deletion

The activity policy row is backed up and restored (audited, clamped against the new parent).
Mappings are never backed up. Deleting a user, course or activity removes the related rows (observers). Merging two users with
tool_mergeusers moves or retires the mappings (see "Account merges"). Details: [PRIVACY](PRIVACY.md).

## Quality checklist (FAM-04)

| Item | Result | Evidence |
|---|---|---|
| Capabilities and contexts on every page | site pages need `managepolicy`/`migrateidentity` in the system context, course pages `view`/`managecoursepolicy` in the course context; the tool and course of an activity are derived server side | `page_contract_test` |
| CSRF | every state change needs `require_sesskey()` (delete, rotate, rebind, purge) plus a confirmation step; the policy form is a Form API form | `page_contract_test` |
| SSRF | not applicable: the plugin makes no outbound request | none needed |
| Sensitive data in logs and messages | refusal messages name the technical reason only; audit/events carry no token | `quality_test::test_refusals_leak_no_identifiers`, `launch_test::test_audit_and_events_never_contain_tokens` |
| API instead of SQL special paths | DML API with parameters; the few set-based statements (rebind, purge) use `{table}` placeholders and run on PostgreSQL and MariaDB | all four combinations |
| Language strings | en and de complete, no hard-coded UI text | `wiring_test::test_language_files_are_complete` |
| Accessibility (automated) | forms from Form API, table headers and a caption, warnings are text (no colour-only meaning); no axe run | `ui_test` (structure only); manual and screen reader check: not verified |
| Race / concurrency | unique-index arbitration; simulated in PHPUnit; multi-process script `tests/load/race.php` | see ACCEPTANCE |
| Test data clean-up | every test calls `resetAfterTest()`; DDL tests use `preventResetByRollback()` | suite |
| Performance / N+1 | constant number of reads per launch, independent of the number of users, courses and tools | `quality_test::test_query_budget_is_constant` |
