# Documentation

Developer documentation of `ltisource_ltipseudonym` 0.3.0 (alpha). Status wording follows
[ACCEPTANCE.md](ACCEPTANCE.md). Index: [NAMING](NAMING.md), [SECURITY](SECURITY.md), [PRIVACY](PRIVACY.md),
[TESTING](TESTING.md), [ILIAS-COMPARISON](ILIAS-COMPARISON.md), [DISCLOSURE_TRACE](DISCLOSURE_TRACE.md) (generated),
[SECURITY_PROTOCOL](SECURITY_PROTOCOL.md) (generated).

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

lib/setup.php, end (every request) -> core hook \core\hook\after_config
   -> request_guard                (db/hooks.php)
        mod/lti/services.php     -> user-data resource + active policy? answer with the service proxy, end the request
        mod/lti/launch.php       -> predict the launch (launch_processor::dry_run()), refuse BEFORE the OIDC login
        mod/lti/contentitem.php  -> the same for the deep-linking request
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
| `launch_processor` | The callback logic; wraps every unexpected error into `launchrefused`; dry-run prediction; LTI 1.x rule. |
| `request_guard` | after_config hook: routes direct calls of core's services endpoint to the proxy, predicts refused launches. |
| `legacy_import` | Imports the pseudonym map (and policies) of the 0.1.0 alpha `ltisource_scopedidentity`; CLI `cli/import_scopedidentity.php`. |
| `migration_service` | Preview, rotation, re-binding after tool re-registration, orphan and retired purge. |
| `audit`, `overview_builder`, `observer` | Audit rows (never tokens), UI data, cleanup on user/course/activity deletion. |

Tables (names use the full component prefix, the xmldb limit is 53 characters in 4.5 and 5.3, see [NAMING](NAMING.md)):
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
| SP-G1 `login_hint` | **Core gate open, boundary declared; product promise limited (0.3.0, see "Product promise").** `lti_build_login_request()` sets `login_hint` to the local id without any extension point, and `mod/lti/auth.php` rejects every other value, so the hint cannot be decoupled by a plugin. See "Data protection boundary" below. | `login_hint_boundary_test::*`, `launch_test::test_login_hint_still_exposes_the_local_id`; Playwright trace. |
| SP-G2 AGS/NRPS | **Closed by the service proxy (0.2.0)** except for LTI 1.1 basic outcomes. Core resolves local ids (evidence); the proxy translates. See "Service proxy" below. | Core behaviour: `service_compat_test::*`. Proxy: `service_proxy_test::*`, `launch_test::test_services_launch_*`, `test_basic_outcomes_are_the_one_strict_boundary`; two-site run in `tests/playwright`. |
| SP-G3 callback reaches the plugin | Works on 4.5 and 5.3 (see TESTING for the combos run). | `launch_test::test_core_calls_the_callback_by_its_component_name`. |
| SP-G4 activity form hook | **No hook.** Fallback: policy page per course, linked from the course navigation. | `wiring_test::test_activity_form_has_no_plugin_hook`, `test_course_navigation`. |

## Product promise (decision 0.3.0, audit SP-02)

**Limited promise.** The plugin promises: the LTI 1.3 id token (`sub`, `for_user_id`, `$User.id` custom parameters), the
roster (NRPS) and the grade exchange (AGS) carry a pseudonym of the chosen scope instead of the local user id; the other
identifying data follow the disclosure policy (pass / blank / remove-or-refuse); a tool cannot use its access token to reach a
local user it was not given (foreign, retired or guessed identifiers are refused, also through the core URL). It does **not**
promise end-to-end pseudonymity towards the tool operator: the OIDC login initiation carries the local user id (`login_hint`,
core gate open), and LTI 1.0/1.1 launches are not covered at all. Wording to use: "pseudonymised id token and services";
never "anonymous" or "end-to-end pseudonymous".

Why limited and not fixed: there is no fork-free, upstream-able way to change `login_hint`.

* `lti_build_login_request()` (`mod/lti/locallib.php`, 4.5 to 5.3) builds `login_hint = $USER->id` with no callback, hook or
  event; `mod/lti/auth.php` refuses every other value (`$loginhint !== $USER->id` -> `access_denied`). Tripwires:
  `login_hint_boundary_test`.
* Evaluated and rejected, fork-free but not upstream-able: (a) buffering the output of `mod/lti/launch.php` in the after_config
  hook and rewriting the hidden `login_hint` field, plus rewriting `$_GET/$_POST['login_hint']` back on `mod/lti/auth.php`:
  works only by editing HTML and request superglobals of core's authentication flow, breaks silently when core changes its
  markup, and is security-critical code that Moodle would not accept upstream; (b) a relay endpoint registered as the tool's
  login URL and as the platform's authentication URL at the tool: requires reconfiguring every tool (breaks dynamic
  registration) and adds an authentication redirector. Neither is shipped.
* Upstream-able approach (proposal, not implemented; needs a core change): a hook `\mod_lti\hook\before_login_request`
  dispatched in `lti_build_login_request()` that may replace `login_hint` by an opaque value, and a matching resolution in
  `mod/lti/auth.php` (accept the opaque value when the hook resolves it to `$USER->id`). The plugin's token service would
  supply a per-launch handle. Until such a hook exists the gate stays open.

**`lti_message_hint`** (evaluated, audit SP-02): built by the same core function, also without an extension point, so the
plugin cannot change it. It carries no user identifier: `cmid` (the activity, also visible in the id token as resource link)
and `launchid` (`ltilaunch<lti instance id>_<random>` for a launch, `ltilaunch_<message type><random>` for deep linking), the
key under which core stores the launch in the session. Nothing to fix.

**What still identifies the user** (generated evidence: [DISCLOSURE_TRACE.md](DISCLOSURE_TRACE.md); PHPUnit capture on 4.5
and 5.3, real browser and HTTP capture on the two-site stack with A = 4.5 and B = 5.3 as platform):

| Carrier | Under the strongest policy (random, activity scope, everything blank) | Status |
|---|---|---|
| OIDC `login_hint` | local user id | **core gate open** (SP-G1) |
| `launch_presentation.return_url` | carries the Moodle session key (`sesskey`), the same in all launches of one browser session, so launches of different scopes can be linked within a session | **core gate open** (return.php requires the sesskey; irrelevant while `login_hint` leaks) |
| `lis_person_sourcedid` (ID number) | empty claim, never absent | **core gate open** (core always builds it) |
| Claims core builds although the policy says `remove` | launch refused, nothing sent (predicted before the login initiation) | by design (a callback cannot delete) |
| LTI 1.1 result id (`basicoutcome.lis_result_sourcedid`, `$Result.sourcedId`) | empty (default under pseudonymisation); with `pass` it carries the local id | documented leak when passed |
| Roles, context, resource link, `lti_message_hint` | course role, course and activity ids | no user identifier (attributes of the membership) |
| The pseudonym itself | stable within its scope: links all launches, scores and roster entries of the person in that scope | by design (choose the scope) |
| IP address, user agent, cookies of the browser at the tool | visible to the tool operator | outside the protocol, not controllable by a platform plugin |
| LTI 1.0/1.1 launches | local id and all shared data, unchanged (or refused, setting `legacyprotocol`) | not covered (spec SP-L05) |

## Request guard (0.3.0, audit SP-02/SP-03)

`classes/local/request_guard.php`, registered for `\core\hook\after_config` (dispatched at the end of `lib/setup.php` for every
request, 4.5 to 5.3). It returns at once unless `$SCRIPT` is one of three core scripts:

* `mod/lti/services.php`: the core endpoint answers rosters with local ids and full names and accepts scores for any gradable
  local id. A tool given pseudonyms could build the core URL itself and bypass the proxy (the PHPUnit negative control shows
  the full roster). When the request addresses a user-data resource (context or link memberships, scores, results) and the
  effective policy of the tool, course and activity is active, the guard answers the request with the service proxy and ends
  it. The tool is taken from every source a request can name it (path, `type_id`, link, bearer token); any doubt routes to the
  proxy, which authenticates exactly like core. Line items, other services, LTI 1.x tools and inactive policies stay with
  core (zero regression).
* `mod/lti/launch.php` and `mod/lti/contentitem.php`: for LTI 1.3 core posts the OIDC login initiation (with `login_hint`)
  before it builds the launch, so a launch the policy refuses would still disclose the local id. The guard runs the real core
  request builder in dry-run mode (`launch_processor::dry_run()`: all checks, no mapping created, ended before signing) and
  refuses before anything is sent. Only for a logged-in user who may launch (or add tools), only on the first pass; anything
  it cannot judge is left to core, and the callback still checks the real launch (fail closed).

## Request binding (0.3.0, audit SP-03)

Every proxy request is bound before a pseudonym is translated: the bearer token to the tool (core `check_tool()`), the tool to
the course (site tool or a tool of that course), the addressed activity (`rlid`, link, line item) to the course and to the
same tool (`translator::assert_binding()`), and the mapping row to the tool, scope, pseudonym type and status (`mismatch()`).
Context rosters with an `rlid` of another course or tool are refused with 404 before core runs (core would answer with an
invalid status 0). Mismatches answer with an empty body (no roles, names or ids). Evidence: [SECURITY_PROTOCOL.md](SECURITY_PROTOCOL.md).

### Translation contract for other plugins (local_lticompletionsync)

`\ltisource_ltipseudonym\local\proxy\translator` is the read-only service other plugins use without a hard dependency:
`context_for(type, courseid, cmid)` (null when the policy changes nothing; refusal 404 when the coordinates do not belong
together), `context::pseudonymising()`, `user_of(context, pseudonym, service)` (refusal 404 for unknown, retired, foreign or
other-scope pseudonyms, audited without the value) and `pseudonym_of(context, userid)`. `translator::API_VERSION` = 2
(0.3.0). `local_lticompletionsync` (receiver `identity_resolver`) uses exactly `context_for()` and `user_of()` behind
`class_exists`/`method_exists` and treats every throwable as "unresolved" (fail closed); its use is compatible with 0.3.0
(checked by reading `classes/local/receiver/identity_resolver.php` of local_lticompletionsync 0.2.0 work tree; the cases are
T01 to T08 of the security protocol). Suggestion for that plugin: also check `API_VERSION >= 2`.

## LTI 1.0/1.1 launches (setting `legacyprotocol`)

The policies cover LTI 1.3 only (spec SP-L05: no new features for legacy protocols). An LTI 1.x launch carries the local id
and everything the registration shares. Setting `legacyprotocol`: `pass` (default, behaviour of 0.2.0, written explicitly by
the 0.3.0 upgrade step) lists such registrations in the overview as not covered; `refuse` refuses their launches (basic and
deep linking) while the policy of their position is active. The guard never routes their service calls.

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

Note: the site scope is chosen at the site level. A tool level row cannot select it, because the default scope is
`deployment` and a lower level cannot be weaker (it is clamped and the editor rejects it).

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

Settings: `legacyenabled` (default off), `legacyprotocol` (`pass` | `refuse`, default `pass`), `retireddays` (365). (`servicesmode` and the policy field `ackservices` of the 0.1.0 alpha do not exist in this component.)

Upgrade: from 0.2.0 (`2026102502`) to 0.3.0 (`2026102700`) one step writes `legacyprotocol = pass` unless an administrator set
it already; no schema change (`install_test::test_upgrade_from_2026102502`, `test_upgrade_keeps_an_existing_setting`).

### Leaving the 0.1.0 alpha (`ltisource_scopedidentity`, audit SP-04)

0.2.0 was renamed by owner decision; core has no upgrade path between two components. To keep the pseudonyms the tools know:

1. Install `ltisource_ltipseudonym` while the old plugin is still installed. Both callbacks run in this window; core lists
   ltisource plugins by name (`ksort()` in `core_component::fetch_plugins()`), so the old plugin's patch is merged last and
   tools keep receiving the old pseudonym. A fresh install of this plugin has no policy and changes nothing yet.
2. Dry run: `php mod/lti/source/ltipseudonym/cli/import_scopedidentity.php` (counts only, nothing written).
3. `... --execute` (optionally `--policies`): copies the old mapping rows unchanged, raises the identity generations to the old
   ones (retiring older active rows here, as a rotation does), never changes an existing row (conflicts are reported), skips
   deleted users and missing registrations, writes one audit row with counts (no pseudonym). Idempotent.
4. Check a launch, then uninstall the old plugin (this deletes its tables).

Not imported: the old HMAC secret (the old derivation used another domain string, so it could not reproduce old tokens; every
issued token is in the imported rows) and the old audit trail. Tests: `legacy_import_test` (fabricated old tables from the
alpha's own install.xml).
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
