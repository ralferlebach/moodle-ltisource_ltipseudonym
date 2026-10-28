# Testing

## Layers

| Layer | Where | Run | Executed locally? |
|---|---|---|---|
| Static checks | whole plugin | `ltirun <45\|53> <pgsql\|mariadb> ltisource_ltipseudonym static` (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings) | yes, see matrix |
| PHPUnit (unit and integration against real core flows) | `tests/*_test.php`, suite `ltisource_ltipseudonym_testsuite` | `ltirun <tree> <db> ltisource_ltipseudonym phpunit` | yes, see matrix |
| Concurrency | `tests/load/race.php` (`make race`) | parallel PHP processes against an installed site, asserts exactly one mapping | no (see below) |
| Behat (non-JS) | `tests/behat/ltipseudonym.feature` | moodle behat runner | **yes**: `ltibehat <45|53> <pgsql|mariadb> ltisource_ltipseudonym` after `ltirun ... mirror`; 3 scenarios, 24 steps, all four combos green on 2026-10-25 (local, not CI). Exception pages cannot be asserted by Behat, so the teacher scenario checks the navigation and PHPUnit covers the denial |
| Playwright two-site E2E | `tests/playwright/ltipseudonym.spec.js` | `NODE_PATH=/home/claude/e2e/node_modules /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js` (seed: `LTI_SEED`, default `/home/claude/e2e/state/seed.json`) | **yes, locally**: executed 2026-10-25, Chromium, 7 tests: 7 passed. Tests 1 to 4: stable and separated pseudonymised `sub` as received by B, `login_hint` leak recorded (SP-G1). Tests 5 to 7 (SP-02 reference): B's own `sync_members` and `sync_grades` tasks call the proxy of A with the pseudonymous `sub`; the grade arrives at the real user on A; the web server log shows the proxy served the calls. The spec writes a policy for the seeded tool and deletes it afterwards; no setting has to be changed any more |
| Playwright protocol capture (0.3.0) | `tests/playwright/disclosure_capture.spec.js` + `support/capture_site.php` | see "Protocol capture" below | yes, see ACCEPTANCE.md |
| CI | `.github/workflows/moodle-plugin-ci-*.yml` | GitHub Actions (owner) | not by us; CI results are the owner's evidence |

The template workflows for Playwright and the JMeter/k6 load tests were removed: they depended on a FlexAccess seed script and on
endpoints this plugin does not have.

## New in 0.3.0

* `disclosure_trace_test` - protocol capture (OIDC login, id token, deep linking, NRPS incl. paging and the core URL, AGS
  score/results incl. guessed local ids, LTI 1.1 launch) per LTI version, scope and policy mode, redacted into classes; compared
  with `tests/fixtures/disclosure_trace.json` (golden file: red when what leaves the platform changes).
* `security_protocol_test` - negative security protocol (S01 to S22, T01 to T08, A01); compared with
  `tests/fixtures/security_protocol.json`; invariants per case: refused, no grade change, no user data in the answer.
* `request_guard_test` - after_config wiring, routing of the core services URL (with negative control: core alone leaks), zero
  regression for everything else, prediction of refused launches and deep-linking requests, LTI 1.x rule.
* `claim_diff_test` - exactly the promised claims change per policy mode, none added or removed; survives backup/restore.
* `legacy_import_test` - import from the 0.1.0 alpha with fabricated old tables (its own install.xml), dry run, conflicts,
  generations, policies, launch afterwards.
* `install_test` - upgrade step from `2026102502`.

Regenerating the generated documents (after a deliberate change of what leaves the platform):

```bash
mkdir -p /tmp/trace && LTIPSEUDONYM_TRACE_OUT=/tmp/trace ltirun 45 pgsql ltisource_ltipseudonym phpunit \
    --filter='disclosure_trace_test|security_protocol_test'
cp /tmp/trace/disclosure_trace.json /tmp/trace/security_protocol.json tests/fixtures/
cp /tmp/trace/DISCLOSURE_TRACE.md /tmp/trace/SECURITY_PROTOCOL.md docs/
```

The report generator includes `tests/fixtures/disclosure_trace_e2e.json` (written by the Playwright capture) when it exists, so
run the Playwright capture first and copy its JSON into the plugin before regenerating.

## Protocol capture on the two-site stack (Playwright)

`disclosure_capture.spec.js` uses each site of the stack as LTI 1.3 platform in turn (A = Moodle 4.5, B = Moodle 5.3). The tool
is played by Playwright on the host `capture.tool-c.test` (every request intercepted; nothing leaves the machine): it answers
the login initiation like a tool, receives the id token, gets an access token from `mod/lti/token.php` with a freshly generated
RS256 key, and calls NRPS/AGS through the id-token URLs and through the core URL. `support/capture_site.php` creates and removes
the fixtures (prefix `pscap`) and sets the policies. Run under the stack lock:

```bash
flock /tmp/e2e_stack.lock env NODE_EXTRA_CA_CERTS=/home/claude/e2e/pki/ca.crt NODE_PATH=/home/claude/e2e/node_modules \
  /home/claude/e2e/node_modules/.bin/playwright test -c tests/playwright/playwright.config.js
```

## PHPUnit files (counts per file and combination are in ACCEPTANCE.md)

`catalogue_test`, `policy_manager_test`, `token_service_test`, `launch_test`, `claims_evidence_test`, `service_compat_test`,
`migration_test`, `privacy_provider_test`, `lifecycle_test`, `wiring_test`, `ui_test` (0.1.0), and new in 0.2.0:
`service_proxy_test` (identity-aware service proxy: roster, scores, results, refusals, id-token URLs end to end),
`disclosure_matrix_test` (decoded signed tokens: every mode x category x tool registration, custom claims, deep linking),
`identity_continuity_test` (deep linking to resource link, duplicate, move, import, rotation), `login_hint_boundary_test`,
`install_test` (fresh install after the rename: no-op upgrade function, schema equals install.xml, release information, a policy for
a tool with AGS/NRPS launches through the proxy),
`page_contract_test` (capability, sesskey, state change), `quality_test` (no identifiers in refusals, query budget),
`merge_integration_test` (tool_mergeusers hooks with stand-ins of its hook objects in `tests/fixtures`; tool_mergeusers is not
installed on the test sites, the real merge is the lead's Gate 2).
Fixtures: `tests/fixtures/fixture.php`. PHPUnit 9 style: `@covers` and `@dataProvider` docblocks, no attributes (PHPUnit 11 on 5.3 reports
them as deprecations, which is accepted).

Mutation checks (M1 to M13, each red, see ACCEPTANCE.md) were run by applying a one-line change to the source, running
`ltirun 45 pgsql ltisource_ltipseudonym phpunit --filter='service_proxy_test|launch_test|disclosure_matrix_test|quality_test|policy_manager_test'`
and restoring the source.

Evidence tests worth naming:

* >= 100 launches give one stable `sub`: `launch_test::test_sub_is_stable_over_100_launches`.
* Scope and tool separation: `launch_test::test_scopes_and_tools_yield_separate_tokens`, `token_service_test::test_tokens_are_separated_by_user_tool_and_scope`.
* Race: `token_service_test::test_concurrent_first_launch_creates_exactly_one_mapping` (simulates the competing insert via a protected seam) and `test_unique_indexes_hold_without_the_service`.
* HMAC: `token_service_test::test_hmac_requires_the_secret_and_the_domain`.
* Empty versus removed claim: `claims_evidence_test`, `disclosure_matrix_test`.
* Deep linking: `launch_test::test_deep_linking_*`.
* AGS/NRPS: `service_compat_test` (what core does), `service_proxy_test` and `launch_test::test_services_*` (what the proxy does).
* login_hint: `login_hint_boundary_test`, `launch_test::test_login_hint_still_exposes_the_local_id`.
* Rollback, re-registration, backup/restore, deletion: `lifecycle_test`, `migration_test`, `privacy_provider_test`.

## Notes for running

* The first PHPUnit run for a new plugin or a changed schema re-initialises the test site (about five minutes).
* A newly mirrored plugin is only detected by `ltirun ... upgrade` after the PHPUnit initialisation has registered it; run `phpunit` before `static` on a fresh tree.
* On Moodle 5.3 the PHPUnit scripts live under `public/`. During development the phpdoc step sporadically aborted with the core error "Invalid component specified in renderer request" (also for other plugins, caused by the plugin cache of the shared tree); a rerun passed. Treat a phpdoc "did not really run" message as an environment failure, not as a pass.
* Several core functions used by tests are deprecated since 5.2 (`duplicate_module`, `course_delete_module`); `tests/fixtures/fixture.php` picks the API of the running version.
* Another plugin's `tool_ltidiagnostics` observer can throw during the AGS score test; the test redirects events (`$this->redirectEvents()`).
* The mustache lint needs a remote validator; the plugin therefore renders its tables with `html_writer` and ships no templates.

## Manual concurrency check

```
make race RACE_PROCS=8        # on an installed development site
```

Expected output ends with `PASS` and `mapping rows: 1`.

## Matrix

See the table at the top of ACCEPTANCE.md (tree/database combinations actually executed, with dates and counts).

## Fresh install check (rename)

0.2.0 is installed fresh (renamed component, no upgrade path from the 0.1.0 alpha). On the four test databases the old plugin was
uninstalled with `admin/cli/uninstall_plugins.php --plugins=<old component> --run`, its directory removed, and this plugin installed
by `php admin/cli/upgrade.php`; afterwards no `config_plugins` rows, tables or capabilities of the old component remained.

## Shared two-site stack

The Playwright spec uses the shared stack (`/home/claude/e2e`). It sets and removes the policy of the seeded tool itself. Do not
reseed during a run. A mutation of the plugin source that changes what B receives leaves accounts on B (the E2E run with the URL
rewrite removed created three accounts with local ids as `sub`; they were deleted).

## CI workflows

`.github/workflows/moodle-plugin-ci-dev.yml` (branches except main, reduced matrix 4.5/5.0/5.3) and
`moodle-plugin-ci-main.yml` (main, full matrix 4.5 to 5.3 x PostgreSQL/MariaDB, release artefact, coverage floor, stale
files). None has run (no GitHub access). Checked locally 2026-10-25 with `/home/claude/bin/ltici-check.py` (PyYAML
parse, job graph and `needs`, matrix cells, Behat tag, archive prefix, every referenced plugin path, no clone of plugin
repositories): 0 errors for all five family plugins. No workflow clones another plugin repository (the template's sibling
lockstep job was removed: the family plugins are independent).
