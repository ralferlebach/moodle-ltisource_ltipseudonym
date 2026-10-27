# Naming

## Decision (owner, 2026-10-25): `ltisource_ltipseudonym`

The owner renamed the plugin from `ltisource_scopedidentity` to **`ltisource_ltipseudonym`** (install path `mod/lti/source/ltipseudonym`), effective with release 0.2.0.
The type stays `ltisource` (mandatory for the `before_launch` callback). The name says what the plugin does to the launch (a pseudonym); the `lti` part keeps it recognisable next to the other family members in a plugin list.

* Checked against the 4.5 and 5.3 sources: valid Frankenstyle name (lower-case letters and digits, starts with a letter, no
  underscore in the name part); tables `ltisource_ltipseudonym_pol`, `_map`, `_aud` (26 characters each). The table-name limit is `xmldb_table::NAME_MAX_LENGTH = 63 - PREFIX_MAX_LENGTH(10) = 53`
  in both trees; the historical 28-character limit no longer applies to Moodle 4.5+ and is quoted below only as history.
* No upgrade path: Moodle cannot upgrade one component into another, so 0.2.0 is a fresh install; a 0.1.0 alpha site uninstalls
  `ltisource_scopedidentity` first (see README, Installation, and CHANGELOG 0.2.0).
* Everything below this section is the earlier analysis (history), written while the name was provisional; its recommendation
  is superseded by this decision.

## History: earlier analysis

The name `ltisource_scopedidentity` is provisional. This note compares alternatives against Moodle's rules.

## Rules checked

* Frankenstyle: `<type>_<name>`, lowercase letters and digits only in the name (no underscore inside the name for new plugins), type must exist.
* Plugin type fit: the callback `<component>_before_launch()` is only called for plugins of type `ltisource`
  (`mod_lti` iterates `get_plugins_of_type('ltisource')`). A `local_` plugin would not be called without a patched core.
* XMLDB table names (without the DB prefix) must be at most 28 characters, and Moodle requires them to start with the
  component name (`moodle-plugin-ci validate` checks this). Therefore **table name = component + suffix <= 28**, which leaves
  `28 - len(component)` characters for the suffix.
* Plugin directory and component name must agree; the install path is `mod/lti/source/<name>`.

## Alternatives

| Name | Length | Longest suffix | Pros | Cons |
|---|---|---|---|---|
| `ltisource_scopedidentity` | 24 | 4 (`_map`, `_pol`, `_aud`) | Names the function (scope-bound identity), matches the spec; right type. | Only 4 characters for table suffixes; tables are terse; "identity" can read as authentication. |
| `ltisource_pseudonym` | 19 | 9 | Short, clear, good table names (`_mapping`). | Names only the pseudonym and hides the disclosure policy and the scope concept. |
| `ltisource_ltipseudonym` | 22 | 6 | Distinctive in a plugin list. | Redundant `lti` inside an LTI sub-plugin; 6 characters. |
| `ltisource_learnerscope` | 22 | 6 | Names the person and the scope. | Does not say what is done with the scope; unusual. |
| `ltisource_privacyscope` | 22 | 6 | Highlights the privacy goal. | Suggests a privacy-compliance claim the plugin must not make. |
| `local_ltiscopedidentity` | 23 | 5 | None. | Wrong type: the `before_launch` callback would never be invoked. Rejected. |

## Recommendation

Keep **`ltisource_scopedidentity`** for 0.x because the name is already used in the spec and in the family documents, the type
is mandatory, and the 28-character limit is met (tables `ltisource_scopedidentity_map`, `_pol`, `_aud` are exactly 28
characters). If a rename becomes desirable before a first public release, `ltisource_pseudonym` is the better name
technically (more room for readable table names) but describes less of what the plugin does. A rename after a release is
expensive (component name, tables, capabilities, config keys), so decide before publishing to the plugins directory.
