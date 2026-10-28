# Changelog

## 0.3.0 (alpha), 2026-10-27

Version `2026102700`. Family audit 2026-10-27 (development branch), items SP-01 to SP-04.

### Added
* **Request guard** (core hook `\core\hook\after_config`, `classes/local/request_guard.php`):
  * direct calls of core's `mod/lti/services.php` for the roster, scores or results of a tool with an active policy are
    answered by the service proxy (closes the bypass: a tool could read the full roster with names and local ids, or score a
    guessed local id, through the predictable core URL);
  * a launch or deep-linking request that the policy will refuse is predicted (dry run of the real core request builder, no
    mapping created) and refused **before** core posts the OIDC login initiation, so no `login_hint` reaches the tool.
* **Request binding** (SP-03): the translator refuses coordinates that do not belong together (tool not available in the
  course, activity of another course or tool); context rosters with an `rlid` of another course/tool answer 404 before core
  runs; invalid status codes of core (0) are answered 500 with an empty body.
* `translator::API_VERSION` (2) and `translator::pseudonym_of()` (contract for local_lticompletionsync and diagnostics).
* Setting **`legacyprotocol`** (`pass` | `refuse`): LTI 1.0/1.1 launches are not covered by the policies; `refuse` refuses them
  while the policy of their position is active. The overview lists LTI 1.x registrations as not covered.
* **Safe exit path from the 0.1.0 alpha** (SP-04): `cli/import_scopedidentity.php` (dry run by default, `--execute`,
  `--policies`) imports the pseudonym map of `ltisource_scopedidentity` before the old plugin is uninstalled.
* Overview: per category the real effect (sent / sent empty / absent / launch refused / depends on the activity); never
  "removed" for a claim core still sends. The four outcomes pass / blank / remove / refuse are explained in the form and the
  overview (en, de).
* Generated evidence: `docs/DISCLOSURE_TRACE.md` + `tests/fixtures/disclosure_trace.json` (PHPUnit protocol capture per LTI
  version, scope and policy mode) and `tests/fixtures/disclosure_trace_e2e.json` (browser/HTTP capture on the two-site stack,
  A = 4.5 and B = 5.3 as platform); `docs/SECURITY_PROTOCOL.md` + `tests/fixtures/security_protocol.json` (negative security
  protocol).
* Tests: `disclosure_trace_test`, `security_protocol_test`, `request_guard_test`, `claim_diff_test`, `legacy_import_test`,
  upgrade tests; Playwright `disclosure_capture.spec.js`.

### Changed
* Product promise documented as limited (DOCUMENTATION "Product promise"): pseudonymised id token and services, not end-to-end
  pseudonymity (`login_hint` core gate open; evaluated fork-free workarounds rejected; upstream hook proposed).
* `launchrefused` no longer claims that "no identifier was sent" (the login initiation may have been sent when the refusal
  could not be predicted); it says that no id token or launch data was sent.
* A deep-linking request of an identified LTI 1.x registration follows the LTI 1.x rule instead of the "unidentifiable tool"
  rule.

### Upgrade
* From `2026102502` (0.2.0): writes `legacyprotocol = pass` unless set; no schema change.

## 0.2.0 (alpha), 2026-10-25

### Post-rename cleanup (2026-10-25, version unchanged)
* CI workflows: removed the template job `ecosystem-lockstep`, which cloned `moodle-{auth,enrol,mod,tool}_<name>` repositories that do not exist and failed every tagged build; `maturity-gate` no longer waits for it. The family plugins are independent and versioned separately.
* CI workflows: the reduced and full matrices (4.5 to 5.3, PostgreSQL and MariaDB) are unchanged. The dev pipeline's code checker runs without an installed Moodle and now gets the plugin path (`moodle-plugin-ci codechecker ... plugin`); without it the blocking step stopped with "Not enough arguments (missing: plugin)". Header comments state the real matrix; template wording about sibling plugins was removed. Validated with a YAML parser and a path/name check (see TESTING.md).

### Renamed (owner decision 2026-10-25)
* Renamed from `ltisource_scopedidentity` (install path `mod/lti/source/scopedidentity`) to `ltisource_ltipseudonym`
  (install path `mod/lti/source/ltipseudonym`); no upgrade path from 0.1.0 alpha. 0.2.0 is a fresh install: uninstall the old
  plugin (this deletes its policies, pseudonym mappings and audit rows) and install this one. Everything carrying the name changed
  with it: tables `ltisource_ltipseudonym_pol|_map|_aud`, capabilities `ltisource/ltipseudonym:*`, settings, language files,
  namespaces, the service proxy URL (`/mod/lti/source/ltipseudonym/service.php`), Behat tag `@ltisource_ltipseudonym`; the
  display name is now "LTI Pseudonym".
* `db/upgrade.php` has no steps any more (the 0.1.0 -> 0.2.0 steps referred to the old tables); the upgrade tests of the 0.1.0
  path were replaced by fresh-install checks (`install_test`: no-op upgrade function, schema equals install.xml, release
  information, a policy for a tool with AGS/NRPS launches through the service proxy).

Re-audit rework (findings FAM-01, SP-01, SP-02, SP-03, SP-04, FAM-04).

### Added
* **Identity-aware service proxy (SP-02):** `service.php` answers the NRPS roster and the AGS scores/results (and serves line items)
  with the unchanged core resources, bound to the proxy URL. Pseudonyms in, local ids to core, pseudonyms out. A pseudonymising
  launch moves the service URLs of the id token to the proxy; so does a disclosure-only policy (the roster applies the disclosure
  policy and keeps the local user ids); a policy that changes nothing leaves the URLs to core. No strict block any more; the pseudonym of one tool is never resolved
  for another tool; misses are answered 404 and audited without the value.
* Disclosure value `remove`: the claim must be absent from the id token. A callback cannot delete a parameter, so the launch is
  refused while core still builds it (`claim_guard`); the policy form and the overview report the conflict beforehand. The ID number
  can only be blanked.
* Data protection boundary for `login_hint` (SP-01): warning in the overview, notice in the policy form, text on the settings page,
  external link in the privacy metadata, tests that document the core behaviour.
* Tests: disclosure matrix on decoded signed tokens (every mode x category x tool registration, custom claims, deep linking),
  identity continuity (deep linking to resource link, duplicate, move, course import, rotation), fresh install, page contracts
  (capability, sesskey), refusal messages without identifiers, query budget.

### Fixed (release gates, 2026-10-25)
* **F2 (Medium), account merges with tool_mergeusers:** the generic merge of tool_mergeusers deleted all pseudonyms of the
  merged-away user on a conflict of the undeclared unique index. The plugin now registers for the hooks of tool_mergeusers
  (`db/hooks.php`): the mapping table is excluded from the generic processing (its index declared as a fallback) and merged by the
  plugin inside the merge transaction (move without conflict; on a conflict the kept account wins and the other row is retired,
  never deleted), with an audit row of counts. Harmless when tool_mergeusers is not installed. No schema change, version unchanged.
* Verified: a pseudonymising policy for a tool with AGS/NRPS launches through the service proxy (no refusal, roster and scores
  translated).

### Changed
* Release 0.2.0, version `2026102502` (fresh install, see "Renamed").
* A pseudonymising policy now sends the LTI 1.1 result id empty by default (it carries the local user id) unless the policy sets
  `pass` explicitly. Tools that rely on LTI 1.1 basic outcomes on a pseudonymised launch must be set to `pass` (documented leak).
* "Send empty" is described as what it is: the claim stays in the token with an empty value.
* A disclosure-only policy no longer aborts a deep-linking request when several registrations share the endpoint and their
  patches agree; pseudonymising policies still fail closed there.
* Restored activity policies drop values that are not valid for their field.
* The overview table has a (visually hidden) caption.

### Fixed
* Fewer database reads per launch (tool configuration loaded once).

### Removed
* The override "acknowledge that AGS and NRPS do not work with pseudonyms": policy field `ackservices`, the site setting
  `servicesmode`, the strict launch block for tools with AGS/NRPS and the corresponding strings. They are not needed any more:
  a pseudonymising policy for such a tool now simply works through the service proxy.

## 0.1.0 (alpha), 2026-10-24

First MVP.

* `before_launch` pseudonymisation of the LTI 1.3 `sub` (basic launch and deep-linking request), fail closed.
* Policy model with site, tool type, course and activity levels; never weaker than the parent; legacy modes opt-in.
* Pseudonym types `random_persisted` and `hmac_secret_v1`; scopes deployment, site, course, cm; mapping table with atomic get-or-create.
* Separate disclosure policy for name, e-mail, picture, source id, username, result id and other parameters.
* AGS/NRPS compatibility guard (fail closed or acknowledged) with evidence tests of the core behaviour.
* Admin UI: policy overview, editor, course page, migration/rotation/re-binding with preview, audit trail.
* Privacy API provider, backup/restore of the activity policy, scheduled retention task, observers for deletions.
* Documents: ACCEPTANCE, NAMING, SECURITY, PRIVACY, TESTING, ILIAS-COMPARISON, DOCUMENTATION.
* Open gates: SP-G1 (login_hint), SP-G2 (AGS/NRPS), SP-G4 (no activity-form hook).
