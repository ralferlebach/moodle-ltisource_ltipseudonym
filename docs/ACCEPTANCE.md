# Acceptance

Date of this assessment: 2026-10-27. Plugin release **0.3.0** (alpha, `2026102700`), work tree delivered as ZIP; the owner pushes
it to `development`. Audit basis: AUDIT_2026-10-27 (development branches), items SP-01 to SP-04 and FAM.

Status classes (used strictly): **code present** | **automated tests (local)** | **CI passed (not executed by us)** |
**functionally accepted (owner)** | **not verified** | **core gate open**.

* **Local evidence** = the named tests ran green in the combinations listed under "Runs 0.3.0" below, on this machine.
* **CI passed: not claimed.** GitHub Actions is run by the owner; the development CI run of the pushed commit is the primary CI
  evidence and is not part of this document. The 5.3 CI setup (SP-01) is fixed by the CI agent in `.github/workflows` (not
  edited here).
* **Functionally accepted: nothing.** No owner acceptance has taken place.
* Skipped tests are never counted; there are none in this suite. Fixtures/mocks alone are never counted for an E2E item.

## Audit 2026-10-27: items of this plugin

| ID | Requirement | Status | Code | Tests (local) | Result / boundary |
|---|---|---|---|---|---|
| SP-01 | CI 5.3 gate | code present; automated tests (local) on 5.3 pgsql + mariadb; CI: not executed by us | no code blocks 5.3: PHP 8.1 syntax, PHPUnit 9 docblocks (PHPUnit 11 reports them as PHPUnit deprecations, accepted) | full suite on 5.3 pgsql/mariadb, see Runs | green locally; the CI container fix is the CI agent's |
| SP-02a | Protocol capture LTI 1.3 per scope and policy mode: OIDC login (`login_hint`, `lti_message_hint`), id token incl. custom claims, legacy result id, NRPS, AGS, deep linking | automated tests (local) + real two-site capture | `tests/disclosure_trace_test.php`, `tests/playwright/disclosure_capture.spec.js` | `disclosure_trace_test::test_disclosure_trace` (31 cells, golden file `tests/fixtures/disclosure_trace.json`, identical on 4.5/5.0-5.3); Playwright capture on A (4.5) and B (5.3) as platform | report `docs/DISCLOSURE_TRACE.md` (generated) |
| SP-02b | Protocol capture LTI 1.1 | automated tests (local) + real two-site capture | same | LTI 1.1 cells (no policy, site policy pass, site policy refuse) | not covered by the policies (spec SP-L05); refusable by setting `legacyprotocol` |
| SP-02c | `login_hint` carries the local id | **core gate open** | none possible (`lti_build_login_request()` has no hook; `mod/lti/auth.php` accepts only `$USER->id`) | `login_hint_boundary_test::*` (tripwires), trace rows "OIDC login", Playwright assertions on both platforms | product promise **limited** (DOCUMENTATION "Product promise"); fork-free workarounds evaluated and rejected (not upstream-able); upstream hook proposed |
| SP-02d | `lti_message_hint` | **core gate open** (cannot be affected) | none | trace + Playwright: `cmid` = activity id, `launchid` = instance id + random | carries no user identifier |
| SP-02e | Refusal rules for identifying claims | code present; automated tests (local); E2E | `classes/local/request_guard.php:219` (`precheck_launch`), `:258` (`precheck_deep_linking`), `classes/local/launch_processor.php:89` (`dry_run`), `:436` (LTI 1.x rule) | `request_guard_test::test_refused_launch_is_predicted_before_the_login`, `test_prediction_only_where_it_applies`, `test_deep_linking_prediction`, `test_lti1_rule`; trace cells `random_remove_shared`; Playwright "remove while the tool shares names" (no request reaches the tool) | a refused launch is refused before the OIDC login initiation (no `login_hint` sent) |
| SP-02f | No false privacy guarantee | code present; automated tests (local) | lang `launchrefused`, `boundary_desc`, `legacyprotocol*`, docs | `login_hint_boundary_test::*`, `ui_test::test_overview_never_claims_removed_for_a_sent_claim` | residual identifiers named in DOCUMENTATION / DISCLOSURE_TRACE |
| SP-03a | Every proxy request bound to tool/deployment, course, resource link and scope | code present; automated tests (local) | `classes/local/proxy/translator.php:101` (`assert_binding`), `:295` (`mismatch`), `classes/local/services/resources/contextmemberships.php:53` | `security_protocol_test` S06-S10, S17, T01-T03 | 404 with empty body |
| SP-03b | Core URL bypass closed | code present; automated tests (local); E2E | `classes/local/request_guard.php:102` (`core_service_response`), `:143`; `db/hooks.php` | `request_guard_test::test_direct_core_roster_is_answered_by_the_proxy` (with negative control: core alone leaks local ids and names), `test_direct_core_score_with_local_id_is_refused`, `test_core_answers_everything_else` (zero regression); protocol S04, S05, S09, S18b; Playwright core-URL calls on A and B | |
| SP-03c | Translation for local_lticompletionsync | code present; automated tests (local) | `translator.php:45` (`API_VERSION` 2), `:79` (`context_for`), `:242` (`pseudonym_of`), `:264` (`user_of`) | protocol T01-T08; use in `local_lticompletionsync` `classes/local/receiver/identity_resolver.php` read: compatible (context_for + user_of, all throwables fail closed) | the completionsync suite itself was not run by this agent |
| SP-03d | Mismatched / retired token fails closed, no role or name disclosure | automated tests (local) | `translator.php:264`, `services/proxy_resource.php` (`fail`, `normalise_code`) | protocol S01-S03, S15, S16, S19-S21 (body empty or empty list, grades unchanged, audit reasons without values, A01) | |
| SP-03e | Real AGS score/results, NRPS context and link memberships incl. paging, deep linking, reverse lookup, course and cm scope | automated tests (local); E2E (real reference tool enrol_lti on B for A; capture tool on A and B) | proxy resources | trace (paging with `limit=1`, next links on the proxy; results ids; cm scope 400 without `rlid`), `service_proxy_test::*`, protocol T07 (reverse lookup), Playwright | |
| SP-03f | Token rotation, account merge (tool_mergeusers hooks) | automated tests (local) | `migration_service`, `merge_integration` | protocol S19-S22, `merge_integration_test::*` (stand-ins of the hook objects; tool_mergeusers not installed on the test sites) | the real tool_mergeusers run is the lead's gate |
| SP-03g | A foreign token cannot affect a local user | automated tests (local); E2E | as SP-03a/b | protocol S01, S04, S10, S11; trace column "guessed local id" (404, not stored, under every pseudonymising mode, via proxy and core URL); Playwright guesses on A and B | under non-pseudonymising policies the tool knows local ids anyway (core behaviour, recorded in the trace) |
| SP-03h | Two-site suite 4.5 and 5.3, PHPUnit 5.0-5.2 | see Runs | | | |
| SP-03i | Negative security protocol in the docs | automated tests (local) | `tests/security_protocol_test.php` | golden file `tests/fixtures/security_protocol.json` | `docs/SECURITY_PROTOCOL.md` (generated) |
| SP-04a | pass/blank/remove/refuse understandable, no false "removed" | code present; automated tests (local); Behat | `classes/local/overview_builder.php:61` (`effect`), `classes/output/overview.php`, lang `disclosureoutcomes`, `disclosureinfo`, `effect:*` | `ui_test::test_overview_never_claims_removed_for_a_sent_claim`, Behat "Remove is shown as absent only where ..." | |
| SP-04b | Safe exit path from the 0.1.0 alpha | code present; automated tests (local) | `classes/local/legacy_import.php:79`, `cli/import_scopedidentity.php` | `legacy_import_test::*` (fabricated old tables from the alpha's install.xml) | dry run by default; not run on a real alpha site (none exists any more) |
| SP-04c | Claim-diff tests | automated tests (local) | | `claim_diff_test::test_claim_diff` (8 modes), `test_remove_is_absent_or_refused` | no claim is ever added or removed by the plugin |
| SP-04d | Upgrade and restore tests | automated tests (local) | `db/upgrade.php:36` | `install_test::test_upgrade_from_2026102502`, `test_upgrade_keeps_an_existing_setting`, `claim_diff_test::test_claim_diff_survives_backup_and_restore`, `lifecycle_test::*` | |
| FAM-00 | Branch policy documented | code present | README "Branch policy" | | |
| FAM-02 | No lockstep, versioned contract | code present | `translator::API_VERSION`; `$plugin->dependencies = []` | protocol T08 | |
| FAM-05 | Evidence separation | this document | | | local / CI / owner separated |

### Mutation and negative controls (0.3.0)

Run 2026-10-28 on 4.5/pgsql with a focused filter; each mutation applied to the source, tests run, source restored (script
in the scratchpad of this round; the source was verified free of the mutation afterwards). All red:

| Mutation | Red tests |
|---|---|
| M18 request guard never routes the core services URL | `request_guard_test` (3: direct roster, direct score, routing), `security_protocol_test` (S04, S05, S18b) |
| M19 `assert_binding()` accepts any coordinates | `security_protocol_test` (S06, S07, S10, T01-T03) |
| M20 dry run does not run the core builder (no prediction) | `request_guard_test` (3), `disclosure_trace_test` (cells `random_remove_shared`) |
| M21 legacy import ignores existing rows | `legacy_import_test::test_dry_run_then_execute` |
| M22 overview reports "absent" for every `remove` | `ui_test::test_overview_never_claims_removed_for_a_sent_claim` |

Negative controls that are part of the tests: `request_guard_test::test_direct_core_roster_is_answered_by_the_proxy` first shows
that core alone answers the core URL with local ids and e-mail addresses; `security_protocol_test` asserts for every non-control
case that the request is refused, no grade changes and the answer carries no user data; the trace records that without a
pseudonymising policy a guessed local id is accepted by core (control). Earlier mutations M1-M17 (0.2.0) are listed in the
history section below.

## Runs 0.3.0 (2026-10-27)

Local evidence only (this machine, `ltirun` / `ltibehat` / Playwright); GitHub CI is the owner's and is not claimed.

| Cell | Static (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings) | PHPUnit | Date |
|---|---|---|---|
| 4.5 PostgreSQL 16, PHP 8.3 (`ltirun 45 pgsql all`) | OK | OK, 312 tests, 1920 assertions | 2026-10-27 |
| 4.5 MariaDB 11.4, PHP 8.3 (`ltirun 45 mariadb all`) | OK | OK, 312 tests, 1920 assertions | 2026-10-27 |
| 5.3 PostgreSQL 17, PHP 8.3 (`ltirun 53 pgsql all`) | OK | OK, 312 tests, 1920 assertions; 38 PHPUnit deprecations (PHPUnit 9 docblocks, accepted) | 2026-10-27 |
| 5.3 MariaDB 11.4, PHP 8.3 (`ltirun 53 mariadb all`) | OK | OK, 312 tests, 1920 assertions; 38 PHPUnit deprecations | 2026-10-27 |
| 5.0 PostgreSQL 16, PHP 8.3 (`ltirun 50 pgsql all`) | OK | OK, 312 tests, 1920 assertions; 38 PHPUnit deprecations | 2026-10-27 |
| 5.1 PostgreSQL 16, PHP 8.3 (`ltirun 51 pgsql all`, then `phpunit`) | OK | first run: 1 failure (`install_test::test_release_information_is_consistent`: ACCEPTANCE.md not yet updated, docs only); re-run 2026-10-28: OK, 312 tests, 1920 assertions; 38 PHPUnit deprecations | 2026-10-27/28 |
| 5.2 PostgreSQL 16, PHP 8.3 (`ltirun 52 pgsql all`, then `phpunit`) | OK | same as 5.1: re-run OK, 312 tests, 1920 assertions | 2026-10-27/28 |

Not run by us: 5.0-5.2 MariaDB, PHP 8.2/8.4 cells (the lead's `ltifullmatrix`), GitHub CI.

PHPUnit tests per new file: disclosure_trace 1 (31 capture cells: 9 LTI 1.3 modes x scopes + 3 LTI 1.1), security_protocol 1 (34
recorded cases with per-case assertions), request_guard 8, claim_diff 10, legacy_import 4, install 5 (upgrade 2), ui +1.
The disclosure capture is identical on 4.5 and 5.0-5.3 (same golden file passes on every cell).

Behat (non-JS, `ltibehat <tree> pgsql ltisource_ltipseudonym`): 4.5: 4 scenarios / 47 steps passed; 5.3: 4 scenarios / 47 steps
passed (2026-10-27). New scenario: "Remove is shown as absent only where the tool does not share the claim, and LTI 1.x tools as
not covered" (form refuses `remove` for a sharing tool; overview shows "absent" for the quiet tool; LTI 1.x notice).

Playwright two-site stack, Chromium, one run under `/tmp/e2e_stack.lock` (2026-10-28): **27 passed, 0 failed**.
* `disclosure_capture.spec.js` (20): A (Moodle 4.5.15+) and B (Moodle 5.3+) each as LTI 1.3 platform for a capture tool:
  no policy, random/deployment, random/course/blank, random/cm/blank (roster needs `rlid`), hmac/site, remove while the tool
  shares names (refused before the login initiation: no request reached the tool), deep linking, LTI 1.1 (no policy, site
  policy pass, site policy refuse). Real access tokens from `token.php`; NRPS through the id-token URL, with `rlid`, paged
  (`limit=1`) and through the core URL; AGS score and results; guessed local ids via proxy and core URL (404, other person's
  grade untouched). Result: `tests/fixtures/disclosure_trace_e2e.json`, section "Real two-site capture" of DISCLOSURE_TRACE.md.
* `ltipseudonym.spec.js` (7): A (4.5) platform -> B (5.3) `enrol_lti` tool: pseudonymous `sub` stable and scope/type
  separated, `login_hint` tripwire, B's own `sync_members` (NRPS) and `sync_grades` (AGS) through the proxy with the grade at
  the real user on A.
* Earlier runs of this round failed for infrastructure reasons (database restarts of the shared machine) and for two test
  defects that were fixed: grades of a previous case not cleared; a substring check that matched the role URI
  `...membership#Learner` against the last name "Learner"; and an assertion of the 0.2.0 spec that assumed no other suite ever
  launched the same student without a policy on the shared stack (now compares before/after).
* The spec removes its fixtures (`pscap*` registrations, activities, users, policies) and restores `legacyprotocol = pass`;
  checked on both site databases afterwards.

## History: assessment of 0.2.0 (2026-10-25)

## Post-rename cleanup re-run (2026-10-25, version 2026102502 unchanged)

| Run | 4.5 PostgreSQL | 4.5 MariaDB | 5.3 PostgreSQL | 5.3 MariaDB |
|---|---|---|---|---|
| `ltirun ... ltisource_ltipseudonym all` (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings, PHPUnit) | OK, 286 tests, 1518 assertions | OK, 286 tests, 1518 assertions | OK, 286 tests, 1518 assertions; 32 deprecations (accepted) | OK, 286 tests, 1518 assertions; 32 deprecations (accepted) |
| `ltibehat ... all` (non-JS) | 3 scenarios / 24 steps passed | 3 scenarios / 24 steps passed | 3 scenarios / 24 steps passed | 3 scenarios / 24 steps passed |

* Uninstall: no gap. The plugin keeps state only in its own tables and settings and in its scheduled task, which core removes. Uninstall leftovers, checked for real: on separate scratch databases (`moodle_upg` 4.5/PostgreSQL and `moodle53_upg` 5.3/PostgreSQL, gate data plus seeded plugin state) all five family plugins were uninstalled with `admin/cli/uninstall_plugins.php --run` and the core tables that can hold plugin state were searched for rows naming the component (config, user preferences, files, scheduled and ad hoc tasks, capabilities and role capabilities, message providers, external functions, events, tables, cache definitions); then reinstalled. Result on both: nothing left except `local_ltixsembed/consumed` in the cache configuration, which core rebuilds from the `db/caches.php` files on disk at the next upgrade (its data is purged by the uninstall).
* CI workflows: validated locally (YAML parser, job graph, matrix 4.5 to 5.3 x PostgreSQL/MariaDB, names and referenced paths; see
  TESTING.md "CI workflows"). CI itself: **not executed**.
* Playwright: no behaviour change; not re-run.

## Combinations actually executed (`ltirun <45|53> <pgsql|mariadb> ltisource_ltipseudonym all`: static checks + PHPUnit), 2026-10-25, after the rename (fresh install)

| Moodle | Database | Static (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings) | PHPUnit |
|---|---|---|---|
| 4.5 | PostgreSQL 16 | OK (first run: codechecker warned about the order of the renamed capability strings; fixed, re-run green) | OK, 286 tests, 1518 assertions |
| 4.5 | MariaDB 11.4 | OK | OK, 286 tests, 1518 assertions |
| 5.3 | PostgreSQL 17 | OK | OK, 286 tests, 1518 assertions (32 PHPUnit 11 deprecations about `@covers`/`@dataProvider` docblocks, accepted: PHPUnit 9 style is required for 4.5) |
| 5.3 | MariaDB 11.4 | OK | OK, 286 tests, 1518 assertions (same 32 deprecations) |

PHPUnit tests per file (4.5/pgsql): catalogue 14, claims_evidence 7, disclosure_matrix 67, identity_continuity 21, launch 44,
lifecycle 5, login_hint_boundary 8, merge_integration 8, migration 9, page_contract 6, policy_manager 20, privacy_provider 9, quality 2,
service_compat 4, service_proxy 25, token_service 16, ui 8, install 4 (replaces upgrade 5), wiring 9 (0.1.0 had 129 tests, 812 assertions).
Family run (`ltifamily`, all five suites in one PHPUnit run): 1369 tests OK on all four combinations, 2026-10-25.

Behat (non-JS, 3 scenarios, 24 steps), `ltibehat` on all four combinations, 2026-10-25 after the rename: all passed. The teacher denial asserts
navigation (an exception page cannot be asserted by Behat; PHPUnit `page_contract_test` covers the capability and sesskey checks).

Playwright two-site E2E (A = 4.5 platform, B = 5.3 tool, Chromium), 2026-10-25 after the rename (fresh install on both sites,
re-seeded stack): 7 passed, 0 failed; the stack's smoke specs passed as well. Before the rename: 7 passed in four runs in a row.
One earlier run failed in the grade test (`Success - The grade` missing); suspected cause: the random grade equalled the last grade
that `enrol_lti` had sent (it does not resend equal grades). The test now picks a different value; the cause was not proven. The
stack's own smoke spec passed afterwards; the spec deletes its policy rows again. The E2E leaves linked logins and mappings for
`studentA1` on the shared stack (they are reused on the next run).

Rename (owner decision 2026-10-25): 0.2.0 is a fresh install under `ltisource_ltipseudonym`; there is no upgrade path from the
0.1.0 alpha (former component name: see CHANGELOG.md). The earlier real-site upgrade check 0.1.0 -> 0.2.0 no longer applies and was
dropped together with the upgrade steps. On the four test databases the old plugin was uninstalled with
`admin/cli/uninstall_plugins.php` and this plugin installed fresh (no tables, settings or capabilities of the old component left).

Not executed: `make race` (real multi-process concurrency), GitHub Actions CI, Moodle 5.0 to 5.2 (not installed), a screen reader /
accessibility audit, any reference tool other than Moodle's own `enrol_lti`.

## Re-audit 0.2.0: findings

| Finding | Result | Status | Evidence | Mutation / negative control |
|---|---|---|---|---|
| FAM-01 release (fresh install after the rename) | Release 0.2.0, version `2026102502`; renamed, no upgrade path from 0.1.0 alpha; `db/upgrade.php` without steps; fresh schema equals install.xml | automated tests (local); fresh install on 4 databases | `install_test::*` (no-op upgrade function, schema equals install.xml, version/release/changelog consistent) | |
| SP-01 `login_hint` boundary | Cannot be decoupled by a plugin (no hook in `lti_build_login_request()`, `mod/lti/auth.php` rejects other values). Declared on the settings page, overview (warning), policy form, course page, privacy metadata (en/de) and in the docs; tests document the core behaviour | automated tests (local) + Playwright trace; **gate stays open** | `login_hint_boundary_test::*`, `launch_test::test_login_hint_still_exposes_the_local_id`, spec test 4 (turns red once core stops leaking) | the Playwright assertion is a tripwire by design |
| SP-02 AGS/NRPS with pseudonyms | **Done** by the identity-aware service proxy `service.php` (design A to G): roster, scores and results translate pseudonym and local id; disclosure policy applied to the roster; service URLs of the id token moved to the proxy for every active policy (pseudonymisation or any disclosure value other than `pass`); misses are 404 and audited; token of one tool never resolves another tool's pseudonym; fail closed (500, empty body). **Limited:** LTI 1.1 basic outcomes cannot be translated (see SP-03) | automated tests (local), 4 combinations; two-site E2E: B's own `sync_members` and `sync_grades` tasks match members by the pseudonymous sub and the grade arrives at the real user on A | `service_proxy_test::*` (25), `launch_test::test_services_*`, `test_disclosure_only_policy_moves_the_service_urls_to_the_proxy`, `ui_test::test_overview_rows`; E2E tests 5 to 7 | Mutations M1 to M11 below, all red; negative controls: no pseudonymisation = core answers, inert policy = core URLs, foreign token 401, retired/unknown/foreign/local-id userId 404, E2E with the URL rewrite removed: red (5 numeric subs reached B) |
| SP-03 claims removed, not blanked | `remove` means absent from the JWT/params, tested on decoded signed tokens for every mode x category x tool registration, custom claims and deep linking. A callback cannot delete a parameter, so `remove` is enforced by refusing the launch while core still builds the claim (`claim_guard`, no identifier in the refusal); `blank` keeps an empty claim (named as such in the UI). **Limited by core:** the LTI 1.1 `lis_result_sourcedid` is merged by `array_merge()` after the callbacks (`mod/lti/locallib.php`, `lti_get_launch_data()`, 4.5 line 624) and `lti_sign_jwt()` (4.5 line 3345) emits every key, so it can only be blanked; default under pseudonymisation is blank, `pass` is a documented leak (overview warning), `remove` refuses | automated tests (local) | `disclosure_matrix_test::*` (67), `claims_evidence_test::*`, `launch_test::test_result_sourcedid_leaks_the_id_unless_blanked`, `test_basic_outcomes_are_the_one_strict_boundary`, `policy_manager_test::test_result_id_defaults_to_empty_when_pseudonymising` | M12 (remove not refused): red; M13 (result id default not blank): red |
| SP-04 scope / migration / deep-linking cases | Identity continuity checked for deep linking to resource link, duplicate, move between sections, course import, no false merge between users, rotation with preview; deep linking with AGS/NRPS and disclosure-only policies | automated tests (local) | `identity_continuity_test::*` (21), `launch_test::test_deep_linking_*`, `disclosure_matrix_test::test_deep_linking_request_claims`, `migration_test::*` | |
| Gate F2 (Medium) account merge with tool_mergeusers | Mapping table excluded from tool_mergeusers' generic processing and merged by the plugin in `after_merged_all_tables`: move without conflict, kept account wins on a conflict, the other row retired (never deleted), audit `user_merged` with counts only, failure rolls the merge back; unique index declared as a fallback; string keys so tool_mergeusers' default exceptions survive; harmless without tool_mergeusers (core registers, never dispatches, no debugging) | automated tests (local) with stand-ins of the hook objects; with the real tool_mergeusers: Gate 2 re-run after the rename (`/home/claude/gates`, run `rename2-20261025-1834`, real `tool_mergeusers`, all four combinations): green - the conflicting row of the merged-away user is retired and kept, the non-conflicting one follows, no user holds two active pseudonyms (the gate check was revised to the documented retire-not-delete rule first) | `merge_integration_test::*` (8) | M14 numeric exceptions key (1 failure), M15 delete instead of retire (3), M16 no move (4), M17 failure swallowed (1): all red |
| Policy for a tool with AGS/NRPS (former Gate 1 item, now without the 0.1.0 upgrade) | Launch goes through, all service URLs on the proxy, roster pseudonymised, score by pseudonym reaches the real user; column, setting, strings and guard class of the 0.1.0 acknowledgement do not exist | automated tests (local) | `install_test::test_policy_with_services_launches_through_the_proxy`, `install_test::test_schema_equals_install_xml` | M5 (no URL rewrite): red (measured on the former upgrade test before the rename) |
| FAM-04 quality checklist | Refusal messages carry no identifier; query budget constant; page contracts (capability, then sesskey, then state change); privacy metadata; German strings complete; language, settings and capability wiring tested; static checks 0 warnings; PHPUnit 9 style, PHP 8.1 syntax | automated tests (local) | `quality_test::*`, `page_contract_test::*`, `wiring_test::*`, `privacy_provider_test::*`, static step | |

### Mutation checks (4.5/pgsql, filter `service_proxy_test|launch_test|disclosure_matrix_test|quality_test|policy_manager_test`)

Each mutation was applied to the source, the tests were run, the result was red, and the source was restored (the full suite is green
again afterwards). M14 to M17 were run the same way with the filter `merge_integration_test`.

| Mutation | Red tests (count) |
|---|---|
| M1 score `userId` passed through untranslated | 7 failures (e.g. results round trip, id-token URLs) |
| M2 roster keeps local ids | 4 failures |
| M3 no tool/scope check of the mapping row | 3 failures (foreign token, activity scope, unknown/foreign user) |
| M4 error texts not scrubbed of the local id | 1 failure |
| M5 service URLs of the id token not moved to the proxy | 17 failures; also the E2E NRPS test is red |
| M6 results keep local ids | 1 failure |
| M7 disclosure fields not removed from the roster | 3 failures |
| M8 result filter takes the pseudonym as user id | 2 failures |
| M9 unknown user returns all results | 1 failure |
| M10 basic outcome kept in the roster | 2 failures |
| M11 disclosure-only policy not routed through the proxy | 3 failures |
| M12 `remove` not refused while core builds the claim | 13 failures |
| M13 result id not blanked by default | 4 failures |

## Deviations from the design and open items (honest boundaries)

* **Result id default:** the design wanted the LTI 1.1 result id removed; core cannot delete it (see SP-03). Default is `blank`, `pass`
  leaks, `remove` refuses. Accepted by the lead on 2026-10-25.
* **LTI 1.1 basic outcomes** (`mod/lti/service.php`, the result sourcedid carries the local user id) are not translated by the
  proxy. A tool that needs them on a pseudonymised launch must be set to `pass` (documented leak).
* **Activity scope:** a roster request must name the activity (`rlid`), otherwise 400. A standalone line item that is not linked to
  an activity cannot be answered with the activity scope for result queries (400).
* **Pseudonyms created by the roster:** the roster creates the pseudonym of a member who never launched (needed to hand out a
  stable value); the tool's own provisioning mode then decides whether an account is created (seen in the E2E: "not eligible").
* **Core quirk:** on PHP 8.3 the core memberships resource raises a deprecation for its own 401 (`new Exception(null, 401)`); the
  test collects it and asserts it is nothing else.
* **Gate SP-G1** (`login_hint`) remains open. **SP-G4** (activity form hook) remains without a hook; fallback course page.
* Pseudonymisation protects the id token and the service answers; a launch is **not** end-to-end pseudonymous.

## Spec acceptance criteria (section E)

| # | Criterion | Status | Evidence |
|---|---|---|---|
| E1 | Same user, same scope/tool: identical `sub` over >= 100 launches | automated tests (local) | `launch_test::test_sub_is_stable_over_100_launches`, `token_service_test::test_token_is_stable_over_many_calls` |
| E2 | Different course, activity, tool: separated, non-correlatable tokens | automated tests (local); E2E: course scope and hmac vs random differ | `launch_test::test_scopes_and_tools_yield_separate_tokens`, `test_cm_scope_separates_activities`, `token_service_test::test_tokens_are_separated_by_user_tool_and_scope` |
| E3 | Two simultaneous first launches create exactly one mapping | automated tests (local; simulated on the unique-index path); real multi-process check `tests/load/race.php` written, **not run** | `token_service_test::test_concurrent_first_launch_creates_exactly_one_mapping`, `test_unique_indexes_hold_without_the_service` |
| E4 | Trace shows all disclosed identifiers incl. `login_hint`, claims, roster, grade return | automated tests (local) for login_hint, claims, roster and grade return; browser trace via Playwright (login_hint leak recorded, pseudonymised sub observed on B, roster and grade through the proxy) | `login_hint_boundary_test::*`, `service_proxy_test::*`, `tests/playwright/ltipseudonym.spec.js` |
| E5 | AGS/NRPS/deep linking with real reference tools, no ids confused | `enrol_lti` of Moodle 5.3 as the reference tool: automated (E2E, local). Other reference tools: **not verified** | E2E tests 5 and 6, `launch_test::test_deep_linking_*` |
| E6 | Rollback, tool re-registration, backup/restore, privacy deletion tested | automated tests (local) | `lifecycle_test::*`, `migration_test::test_tool_reregistration_and_rebind`, `privacy_provider_test::*` |
| E7 | No-fork gates documented and released by the architecture review | gates documented (code present: DOCUMENTATION.md); architecture review: not verified | docs/DOCUMENTATION.md |

## Spec acceptance criteria (V2, ILIAS comparison)

| # | Criterion | Status | Evidence |
|---|---|---|---|
| V1 | All relevant ILIAS modes evaluated, implemented or rejected with reason | code present (documented), no test needed | docs/ILIAS-COMPARISON.md |
| V2 | Name/e-mail/picture disclosure controllable separately from `sub`, effective in launch and service endpoints | automated tests (local) for the launch and, since 0.2.0, the roster (names, e-mail, user name, ID number removed unless passed); the picture has no service endpoint | `disclosure_matrix_test::*`, `service_proxy_test::test_roster_applies_the_disclosure_policy`, `test_disclosure_only_policy_routes_the_services_through_the_proxy` |
| V3 | System/course/activity scoping separates identities stably and collision-free per tool/deployment | automated tests (local) | `launch_test::test_site_scope_is_shared_across_tools`, `test_activity_override_changes_the_scope`, `token_service_test::*` |
| V4 | Legacy id and synthetic e-mail-like identifiers are not issued by default | automated tests (local) | `policy_manager_test::test_default_is_inert`, `test_legacy_needs_the_site_setting`, `ui_test::test_form_rejects_legacy_when_disabled`; no synthetic e-mail mode exists (rejected) |
| V5 | Deterministic tokens not reconstructible without the secret; random tokens from a CSPRNG | automated tests (local) | `token_service_test::test_hmac_requires_the_secret_and_the_domain`, `test_hmac_secret_is_stored_encrypted`, `test_random_token_has_at_least_128_bit` |
| V6 | AGS/NRPS consistency and `login_hint` explicitly tested and reported by observed evidence | automated tests (local): AGS/NRPS consistent through the proxy; `login_hint` gate open | `service_proxy_test::*`, `service_compat_test::*`, `login_hint_boundary_test::*` |
| V7 | ILIAS interoperability against a versioned counterpart | not verified | none |

## MVP scope (brief P2)

| Item | Status | Evidence |
|---|---|---|
| `before_launch` pseudonymisation, LTI 1.3 only, fail closed | automated tests (local) | `launch_test::test_random_pseudonym_replaces_sub`, `test_lti11_tool_is_untouched`, `test_launch_fails_closed_*` |
| Policy hierarchy site, type, course, activity; never weaker; overrides opt-in | automated tests (local) | `policy_manager_test::*` |
| Types `random_persisted`, `hmac_secret_v1`; scopes deployment, site, course, cm | automated tests (local) | `token_service_test::*`, `launch_test::*` |
| Legacy modes opt-in, disabled by default; others rejected | automated tests (local) (legacy), documented (rejections) | `launch_test::test_legacy_userid_mode`, ILIAS-COMPARISON.md |
| Mapping table, unique indexes, atomic get-or-create | automated tests (local) | `token_service_test::test_concurrent_first_launch_creates_exactly_one_mapping` |
| Separate disclosure policy; empty versus removed claim | automated tests (local) | `claims_evidence_test::*`, `disclosure_matrix_test::*` |
| Identity-aware service proxy for AGS/NRPS (replaces the 0.1.0 guard and its acknowledgement override) | automated tests (local); E2E (local) | `service_proxy_test::*`, `launch_test::test_services_*` |
| `login_hint` gate test | automated tests (local); gate open | `login_hint_boundary_test::*` |
| Admin UI: policy overview, editor, course page, audit, scope-change confirmation, migration/rotation/re-binding with preview | automated tests (local) for the logic, the forms and the page contracts; Behat (local) for the rendered pages | `ui_test::*`, `page_contract_test::*`, `migration_test::*`, `wiring_test::*`, `tests/behat/ltipseudonym.feature` |
| Activity-level UI inside the activity form | not possible: no hook (SP-G4); fallback is the course page | `wiring_test::test_activity_form_has_no_plugin_hook` |
| Privacy API, retention | automated tests (local) | `privacy_provider_test::*`, `migration_test::test_purge_retired`, `test_scheduled_task` |
| Backup/restore of the activity policy | automated tests (local) | `lifecycle_test::test_duplicate_activity_copies_policy_but_not_mappings`, `test_restored_policy_cannot_weaken_the_parent` |
| Capabilities with risk bitmasks | automated tests (local) | `wiring_test::test_capabilities` |
| English and German language strings complete | automated tests (local) | `wiring_test::test_language_files_are_complete` |
| Fresh install 0.2.0 (renamed; no upgrade path from 0.1.0 alpha) | automated tests (local); fresh install on 4 databases | `install_test::*` |
| Accessibility check, security review | not verified | none |

## Gates

| Gate | Result |
|---|---|
| SP-G1 `login_hint` | open: still the local id; declared, tested, shown in the UI and the privacy metadata |
| SP-G2 AGS/NRPS | closed by the service proxy for LTI 1.3 services; LTI 1.1 basic outcomes remain a core boundary |
| SP-G3 callback reaches the plugin on every version | automated tests (local) on the combinations above |
| SP-G4 activity form hook | no hook; fallback course page |
