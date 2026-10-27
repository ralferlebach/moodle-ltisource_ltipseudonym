# Privacy

This document describes the personal data the plugin handles. It is an engineering description, not a statement of
GDPR conformity; the controller decides on legal basis and retention.

## Data stored

| Table / key | Personal data | Purpose | Removal |
|---|---|---|---|
| `ltisource_ltipseudonym_map` | user id, pseudonym, tool type, scope, generation, status, times | deliver the same pseudonym on every launch | Privacy API deletion; `user_deleted`, `course_deleted`, `course_module_deleted` observers; scheduled retention task for retired rows; manual orphan purge |
| `ltisource_ltipseudonym_pol` | `usermodified`, times | who changed a policy | anonymised (set to 0) on user deletion, the policy itself is configuration and stays |
| `ltisource_ltipseudonym_aud` | `actorid`, old/new policy snapshot | accountability of policy changes | anonymised on user deletion; contains no pseudonyms |
| config `hmacsecret_v1` | none (site secret, encrypted) | HMAC derivation | removed with the plugin |

The plugin never copies profile data (names, e-mail, picture) of the user.

## Privacy API

`classes/privacy/provider.php` declares the metadata (including the external link: the pseudonym and, independent of the policy, the local user id as `login_hint` are sent to the tool),
finds contexts, exports the mappings and the configuration actions of a user, and deletes per user, per context and per user list.
Tests: `privacy_provider_test`.

## Retention

* Active mappings stay while the user, the course/activity and the tool registration exist.
* A rotation retires the old mappings; they are kept for `retireddays` (default 365, setting) so that an erroneous rotation can be investigated,
  then purged by `purge_retired`.
* Mappings of a deleted tool registration are kept (orphans) so that a re-registration can be re-bound; they are removed by the explicit
  orphan purge. Decide on a period in your data protection concept.

## Consequences outside this platform

* Claims set to "send empty" remain in the token with an empty value; only "remove" makes them absent (and refuses the launch while core still builds them).

* The tool keeps whatever it stored under the pseudonym (account, grades, progress). Deleting the mapping here does not delete that data. The
  export and the Privacy API metadata say so.
* Grade references: grades the tool returned (AGS, addressed with the pseudonym; the service proxy translates it) are written to the
  local gradebook of the **local** user. Deleting the mapping does not remove them, and afterwards the proxy cannot resolve the old
  pseudonym any more (the late score is refused); core's own privacy handling of grades applies.
* Roster (NRPS): the tool receives the pseudonyms of the course members through the proxy, and names, e-mail addresses, user names
  and ID numbers only as far as the policy passes them. The LTI 1.1 basic outcome carries the local id and is removed unless the
  policy passes it.
* Changing or rotating an identity makes the tool see a new person: it may create a new account and detach history. The UI requires an explicit confirmation
  and offers a preview; there is no silent rotation.
* Deletion of a mapping followed by a new launch creates a new pseudonym: the person is no longer linkable to the old one.
* Account merge (tool_mergeusers): the kept account continues the pseudonyms of the merged-away account where it had none of its
  own; where both had one, the merged-away account's pseudonym is retired. The tool is not told that two pseudonyms belong to one
  person; what it stored under the retired pseudonym stays there, unlinked. The audit entry holds counts only.

## Residual identification

**Data protection boundary (gate SP-G1).** Before every launch Moodle core posts the local user id as `login_hint` to the tool's login URL, whatever the policy; this plugin cannot prevent it and the privacy metadata says so (external field `userid`). The tool operator therefore learns the local id even when the id token carries a pseudonym. `lis_result_sourcedid` carries it too unless blanked or removed. Pseudonymisation of `sub`
alone is therefore not anonymisation. The identifiability ratings in the UI are orientation, not a compliance proof.
