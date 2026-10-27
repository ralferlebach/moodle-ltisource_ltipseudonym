# Comparison with ILIAS (LTIConsumer)

Basis: the spec's "Ergaenzung V2" table (ILIAS repository `components/ILIAS/LTIConsumer`, visible constants and form definitions).
The table lists offered options, not a verified behaviour of every ILIAS service path. ILIAS itself reported partly unreliable
deep-linking and AGS options before 11.4; a UI option there is no proof that the service path works.

| ILIAS option | Decision | Reason |
|---|---|---|
| `PRIVACY_IDENT_IL_UUID_USER_ID` (internal id) | **Implemented as opt-in legacy mode** `legacy_userid` | Needed to model a migration away from the Moodle default. Disabled by default (`legacyenabled`), rated highly identifiable, refused unless enabled. Test: `launch_test::test_legacy_userid_mode`, `policy_manager_test::test_legacy_needs_the_site_setting`. |
| `PRIVACY_IDENT_IL_UUID_LOGIN` | **Rejected** | A login name is personal data, guessable and often equal to an e-mail address or an external account. Re-identification is trivial and it adds nothing the opt-in legacy mode does not already cover. |
| `PRIVACY_IDENT_IL_UUID_EXT_ACCOUNT` | **Rejected** | Moodle has no reliable, unique external account field (idnumber is free text, not guaranteed unique); unstable and re-identifying. |
| `PRIVACY_IDENT_REAL_EMAIL` | **Rejected as an identifier** | An e-mail address is mutable and shareable. Disclosure of the e-mail as a *claim* is possible and separate (disclosure category "email"), logged and policy-controlled; it is not used as `sub`. |
| `PRIVACY_IDENT_IL_UUID_SHA256` | **Rejected** | A plain SHA-256 over predictable fields (user id, creation date) can be recomputed by anybody who knows the inputs. Replaced by HMAC with a secret. Test: `token_service_test::test_hmac_requires_the_secret_and_the_domain`. |
| `PRIVACY_IDENT_IL_UUID_SHA256URL` | **Rejected** | Same weakness, plus an e-mail-like format that fakes an address. The plugin does not forge e-mail addresses. |
| Random id option | **Implemented** as `random_persisted` (default) | CSPRNG token of at least 128 bit with a persisted mapping; stable, not derivable. Tests: `token_service_test::test_random_token_has_at_least_128_bit`, `launch_test::test_sub_is_stable_over_100_launches`. |
| HMAC (not in ILIAS) | **Implemented** as `hmac_secret_v1` | Deterministic without per-user storage choice, versioned domain separation, secret stored encrypted. Needs the mapping row here too, so that rotation and deletion work. |
| `PRIVACY_NAME_NONE/FIRSTNAME/LASTNAME/FULLNAME` | **Implemented** as disclosure category "name" (pass or blank) | Granularity is coarser: given and family name are one category because the launch parameters are produced together. Independent of `sub`. Test: `launch_test::test_disclosure_is_independent_of_sub`. |
| E-mail and picture sharing | **Implemented** as categories "email" and "picture" | Separate from `sub` and from each other. |
| Provider-level privacy settings | **Implemented** as policy per tool type, course and activity | Hierarchy with "allow override" and clamping instead of one global value. |

## Differences to ILIAS that matter

* The policy is a hierarchy that can only get stricter towards the leaves.
* Rotation and migration are explicit, previewed actions.
* AGS/NRPS consistency is checked and refused by default; it is not silently assumed.
* No interoperability test against a versioned ILIAS instance has been done (acceptance item open, see ACCEPTANCE.md).
