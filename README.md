moodle-ltisource_ltipseudonym
===============================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-ltisource_ltipseudonym/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-ltisource_ltipseudonym/actions?query=workflow%3A%22Moodle+Plugin+CI%22+branch%3Amain)

Pseudonymises the learner identity that Moodle (as an LTI 1.3 platform) sends to external tools: the `sub` claim
is replaced by an opaque, persisted, scope-bound token, and names, e-mail address, picture and other identity-carrying
launch parameters can be disclosed or blanked independently of it.

Status: **alpha (0.2.0)**. Read [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md) before relying on any statement here:
two known limitations (the OIDC `login_hint` and the AGS/NRPS services, see below) cannot be solved without changing
Moodle core, and this plugin says so instead of hiding it.

> **Data protection boundary (gate SP-G1, open).** Before every launch, Moodle core sends the *local* user id to the tool as
> `login_hint` (a browser form post to the tool's login URL), and core's own login endpoint rejects any other value. This plugin
> cannot change that. It pseudonymises the id token (`sub`, `for_user_id`) and controls identity-carrying claims; it does **not**
> hide the local user id from the operator of the tool. A launch is never "end-to-end pseudonymous".


Requirements
------------

This plugin requires Moodle 4.5+ (`$plugin->requires = 2024100700`) and is written for Moodle 4.5 to 5.3
(`supported = [405, 503]`), PHP 8.1+. It is a sub-plugin of the External tool activity (`mod_lti`), type `ltisource`.


Motivation for this plugin
--------------------------

Moodle sends the local numeric user id as `sub`. Any tool therefore learns a stable, guessable, platform-wide
identifier of every learner, and two tools can correlate the same person. Other platforms (for example ILIAS) offer
several identity modes; this plugin implements the two that are defensible (a persisted random token and a keyed
HMAC) and documents why the others are rejected ([docs/ILIAS-COMPARISON.md](docs/ILIAS-COMPARISON.md)).


Installation
------------

Install the plugin like any other plugin to folder

    /mod/lti/source/ltipseudonym

(on Moodle 5.1+ below the `public/` directory) and run the upgrade. See http://docs.moodle.org/en/Installing_plugins
for details on installing Moodle plugins. The plugin does nothing until a policy is saved.

**Renamed in 0.2.0, no upgrade path.** Release 0.2.0 is the first release under the name `ltisource_ltipseudonym`; the 0.1.0 alpha was
published under a different component name (see [docs/CHANGELOG.md](docs/CHANGELOG.md)). Moodle treats the two as different
plugins and cannot upgrade one into the other. On a site that runs the 0.1.0 alpha: uninstall the old plugin first (Site
administration -> Plugins -> Plugins overview -> Uninstall, or `php admin/cli/uninstall_plugins.php --plugins=<old component> --run`),
which deletes its policies, pseudonym mappings and audit trail, remove its directory, then install this plugin as a new plugin. Nothing is carried over.


Usage & Settings
----------------

After installing the plugin, it does not do anything to Moodle yet. Policies are created on
Site administration -> Plugins -> Activity modules -> External tool -> Pseudonym policies.

Policy resolution: site default -> tool type -> course (per tool type) -> activity. A lower level is honoured only if
the levels above allow overrides, and a lower level can never be weaker than its parent.

* Pseudonym type: `random_persisted` (CSPRNG, 128 bit or more, stored mapping) or `hmac_secret_v1` (HMAC-SHA256 with a
  server-side secret, versioned domain separation). The legacy mode `legacy_userid` (the local id) is an explicit opt-in
  that is disabled by default.
* Scope: `deployment` (one tool registration), `site`, `course`, `cm` (activity).
* Disclosure per category (names, e-mail, picture, source id, username, result id, other): `pass`, `blank` (the claim stays in the
  token with an empty value) or `remove` (the claim must be absent from the token; a callback cannot delete a parameter, so the
  launch is refused while Moodle core still builds it, see below). The ID number is always built by core and can only be blanked.
* Migration and rotation: always a separate, previewed and audited action; never silent.

Settings (Site administration -> Plugins -> Activity modules -> External tool -> the plugin's settings page):

* **Enable legacy identity modes** - off by default.
* **Retention of retired mappings** - days before retired mappings are purged by the scheduled task (default 365).

If you want to learn more about using ltisource plugins in Moodle, please see https://docs.moodle.org/dev/External_tool_source.


Capabilities
------------

* **ltisource/ltipseudonym:view** - see the course policy overview (editing teacher, manager).
* **ltisource/ltipseudonym:managecoursepolicy** - edit course and activity policies (risk: personal data; manager only by default).
* **ltisource/ltipseudonym:managepolicy** - edit site and tool type policies (risk: personal data, configuration; manager only by default).
* **ltisource/ltipseudonym:migrateidentity** - rotate or migrate issued identities (risk: personal data, data loss; no default role, grant explicitly).


Scheduled Tasks
---------------

This plugin also introduces these additional scheduled tasks:

* **\ltisource_ltipseudonym\task\purge_retired** - removes retired mappings older than the configured retention (daily, 03:17).


How this plugin works / Pitfalls
--------------------------------

The plugin implements `ltisource_ltipseudonym_before_launch()`, which mod_lti calls for basic launches and for the
deep-linking request. Known limits, all proven by tests (see [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md)):

* **login_hint (gate SP-G1, open, declared):** the OIDC login initiation still carries the local user id. No core hook exists and
  core's login endpoint requires exactly that value (`mod/lti/auth.php`). The boundary is shown on the settings page, the overview
  (warning), the policy form, the course page and in the privacy metadata.
* **AGS / NRPS work with pseudonyms (finding SP-02, service proxy):** core answers service calls with local user ids. With an
  active policy (pseudonymisation, or any disclosure value other than `pass`) the launch therefore points the tool to this plugin's service endpoint
  (`/mod/lti/source/ltipseudonym/service.php`, same paths as `mod/lti/services.php`) for the roster (NRPS) and the grade service
  (AGS). The proxy runs the unchanged core resources and the unchanged core authentication and scopes, translates the pseudonym
  to the local user id for scores and result queries, and replaces local ids by pseudonyms in rosters and results. Boundaries:
  **LTI 1.1 basic outcomes cannot be translated** (their result id carries the local user id; a pseudonymising policy sends it
  empty unless the result id is explicitly set to `pass`, and `remove` refuses the launch while core builds it); with the activity
  scope a roster request must name the activity (`rlid`); a score for an unknown, retired or foreign pseudonym is answered 404 and
  audited. A disclosure-only policy (the sub stays the local id) uses the proxy too: the roster applies the disclosure policy and
  keeps the local user ids. A policy that changes nothing leaves the URLs to core.
* **Account merges (tool_mergeusers):** the plugin merges its pseudonym table itself through the hooks of tool_mergeusers. Where
  only the merged-away account had a pseudonym, the kept account continues it; where both had one, the kept account's wins and
  the other is retired (never deleted). The tool is not told that two pseudonyms are one person (see DOCUMENTATION).
* **Claims cannot be deleted by a callback**, only replaced: "blank" sends an empty claim. "Remove" therefore works only when the
  tool registration makes core not build the parameter (for example name/e-mail set to never share); otherwise the launch is
  refused instead of sending an empty claim under the promise "removed". The policy editor and the overview show the conflict
  beforehand. Disclosure matrix and evidence: [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md).
* No hook exists in the activity form (gate SP-G4); activity policies are edited on the course page.

Changing an issued identity can create a new account at the tool and detach grade history. Read
[docs/PRIVACY.md](docs/PRIVACY.md).


Theme support
-------------

This plugin acts behind the scenes and renders only plain Moodle admin pages. It is developed and tested on Moodle Core's
Boost theme.


The Moodle LTI plugin family
----------------------------

This plugin is one of six independent plugins around Moodle's LTI support. Each is installable alone; they talk to
each other only through Moodle events and read-only services, never through a hard dependency.

| Plugin | Purpose |
|---|---|
| `local_ltiidentity` | Tool side: identity profile handling on launch (spec 01). |
| `ltisource_ltipseudonym` (this plugin) | Scoped pseudonymous identity and separate disclosure policies on the platform side. |
| `local_ltiaccountmerge` | Tool side: account reconciliation (spec 03). |
| `local_ltixsembed` | Cross-site embedding (spec 04). |
| `tool_ltidiagnostics` | Launch and service diagnostics (spec 06). |
| `tool_ltigroupautoenrol` (existing) | Automatic group enrolment for LTI users. |

Independence statement: this plugin has no dependency on any other plugin of the family; `tool_ltidiagnostics` can
display its effect, but nothing here requires it.


Plugin repositories
-------------------

This plugin is not published in the Moodle plugins repository.

The latest development version can be found on Github:
https://github.com/ralferlebach/moodle-ltisource_ltipseudonym


Bug and problem reports / Support requests
------------------------------------------

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-ltisource_ltipseudonym/issues

Due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Please issue feature proposals and pull requests on Github. Feature proposals are welcome as proposals, not requests.


Moodle release support
----------------------

The plugin is developed against Moodle 4.5 (LTS) to 5.3. Local test execution is listed per Moodle version in
[docs/ACCEPTANCE.md](docs/ACCEPTANCE.md).


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be
managed through AMOS (https://lang.moodle.org).


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.


Maintainers
-----------

Ralf Erlebach


Copyright
---------

Ralf Erlebach. Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
Licensed under the GNU GPL v3 or later.
