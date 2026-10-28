# Security

Scope: what the plugin protects, what it deliberately does not, and how its own surface is secured.

## What it protects

* The LTI 1.3 `sub` (and `for_user_id`) towards tools: persisted CSPRNG token (>= 128 bit) or HMAC-SHA256 with a server-side secret.
* Identity-carrying parameters by category (names, e-mail, picture, source id, username, result id, custom user parameters).

## What it does not protect (open gates)

* `login_hint` of the OIDC login initiation contains the local user id (SP-G1). The tool operator receives it in a browser form post
  and anybody who can read that request or the tool's logs sees it. The plugin cannot change it (no core hook, and `mod/lti/auth.php`
  rejects other values) and declares this on the settings page, every policy page and in the privacy metadata.
* AGS and NRPS answer with local ids in core (SP-G2). With an active policy the tool is pointed to the plugin's service proxy,
  which translates pseudonyms (pseudonymising) and applies the disclosure policy to the roster (also without pseudonymisation). Not translated: LTI 1.1 basic outcomes (their result id carries the local id; sent empty unless the policy
  passes it explicitly), and the `login_hint` above.
* The callback cannot delete a claim, only blank it (empty claim). `remove` is enforced by refusing the launch while core still builds it;
  the refusal is predicted before the OIDC login initiation (request guard), so a refused launch does not send `login_hint` either.
* `launch_presentation.return_url` carries the Moodle session key, identical for all launches of a browser session (core requires it
  on `mod/lti/return.php`); a tool can link launches of different scopes within one session. Irrelevant while `login_hint` leaks.
* LTI 1.0/1.1 launches (not covered; setting `legacyprotocol` can refuse them).

The full, generated list of what a tool receives: [DISCLOSURE_TRACE.md](DISCLOSURE_TRACE.md). The negative security protocol of the
services (every attack below executed, with the answer and the grade state): [SECURITY_PROTOCOL.md](SECURITY_PROTOCOL.md).

## Threats and controls

| Threat | Control | Evidence |
|---|---|---|
| Reconstruct a pseudonym from a known user id | Random tokens are unrelated to the id; HMAC needs the secret (stored encrypted via `\core\encryption`, never logged). | `token_service_test::test_hmac_requires_the_secret_and_the_domain`, `test_hmac_secret_is_stored_encrypted` |
| Cross-tool correlation | Scope and tool type are part of the mapping key and of the HMAC domain. | `launch_test::test_scopes_and_tools_yield_separate_tokens` |
| Duplicate mappings under concurrency | Unique indexes; insert error is caught and the winner re-read. | `token_service_test::test_concurrent_first_launch_creates_exactly_one_mapping`, `tests/load/race.php` |
| Launch with the real id after an error | Fail closed: any error after a launch is identified as LTI 1.3 becomes `launchrefused`. | `launch_test::test_launch_fails_closed_*` |
| A teacher weakens the site policy | Overrides must be allowed by the parent and are clamped to be at least as strong. | `policy_manager_test::test_never_weaker_than_parent` |
| Forged policy requests (CSRF) | Form API with sesskey; confirmation required for identity changes; deletion requires `require_sesskey()` and confirmation. | `ui_test` |
| Privilege escalation | Separate capabilities with risk bitmasks; context checks on every page; the activity is mapped to its tool and course on the server, URL parameters are ignored. | `wiring_test` |
| Secrets or identifiers in logs | The audit table and the event carry policy snapshots only; no token, no secret. | `launch_test::test_audit_and_events_never_contain_tokens` |
| Missing or forged sesskey, missing capability on the admin pages | `require_sesskey()` plus confirmation; `require_capability` in the right context | `page_contract_test` |
| A tool resolves pseudonyms of another tool, or guesses local ids, through the service proxy | The mapping must be active and carry the tool of the bearer token and the scope of the addressed activity; a local id is no pseudonym; misses answer 404 and are audited without the value. | `service_proxy_test::test_a_token_never_resolves_the_pseudonym_of_another_tool`, `test_score_with_unknown_or_foreign_user_is_refused` |
| Bypass of the proxy: the tool calls core's `mod/lti/services.php` itself (predictable URL) to read the full roster with names and local ids, or to score a guessed local id | Request guard (core hook `after_config`): user-data resources of a tool with an active policy are answered by the proxy; the tool is taken from the path, `type_id`, link and bearer token, any doubt routes to the proxy | `request_guard_test::test_direct_core_*`, security protocol S04, S05, S09, S18b; E2E `disclosure_capture.spec.js` (core URL, both platforms) |
| A request names an activity or line item of another course or tool (`rlid`, link, line item) to get pseudonyms or the policy of another scope | Binding of tool, course, activity and scope before translation (`translator::assert_binding()`), 404 with an empty body | security protocol S06 to S10, S17, T01 to T03 |
| Refused launch still discloses the local id through the OIDC login initiation | Prediction of the refusal before the login initiation (dry run of the core request builder, no side effects) | `request_guard_test::test_refused_launch_is_predicted_before_the_login`, E2E "remove while the tool shares names" |
| Local id in error texts of the proxy | The reason of a refused or failed request is scrubbed of the translated body and the local id. | `service_proxy_test::test_error_texts_do_not_carry_the_local_id` |
| Unfiltered roster delivered after a failure | The proxy answers 500 with an empty body. | `service_proxy_test::test_roster_fails_closed` |
| Account merge deletes or mixes pseudonyms (tool_mergeusers' generic fallback deleted all rows of the merged-away user) | The table is merged by the plugin inside the merge transaction: move without conflict, otherwise the kept account wins and the other row is retired; never deleted; failure rolls the merge back; audit with counts only. | `merge_integration_test::*` |
| Policy restored from a backup of another site | Restored policy is clamped against the new parent. | `lifecycle_test::test_restored_policy_cannot_weaken_the_parent` |

## Reporting

Please report vulnerabilities privately to the maintainer (Ralf Erlebach) before opening a public issue.

## Not covered

No penetration test and no external review have been done; CI results are the owner's (see ACCEPTANCE.md). The request guard
relies on `$SCRIPT` as computed by core (`initialise_fullme()`); a web server that serves `mod/lti/services.php` under another
script name would bypass the guard (not the proxy) - none is known.
