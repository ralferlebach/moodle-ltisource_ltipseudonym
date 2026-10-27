<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for ltisource_ltipseudonym.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Keep the keys in alphabetical order: moodle.Files.LangFilesOrdering enforces it.
$string['actions'] = 'Actions';
$string['activityuigate'] = 'The settings form of an LTI activity cannot be extended by this plugin type (gate SP-G4). Activity overrides are therefore edited from this page.';
$string['allowoverride'] = 'Allow overrides on lower levels';
$string['allowoverride_help'] = 'Lower levels (course, activity) may then add stricter settings. They can never weaken what is set here.';
$string['audittrail'] = 'Audit trail';
$string['boundary_desc'] = '<p><strong>Known limitation (gate SP-G1, open):</strong> before every launch Moodle core sends the LOCAL user id to the tool as <code>login_hint</code> (a form post from the browser to the tool\'s login URL), and its own login endpoint rejects any other value. This plugin cannot change it. Pseudonymisation protects the id token (<code>sub</code> and the claims) only. It does not hide the local user id from the operator of the tool, so do not describe a launch as end-to-end pseudonymous.</p><p><strong>Services (finding SP-02):</strong> with a pseudonymising policy the roster (NRPS) and grade service (AGS) of a tool are answered by the service proxy of this plugin, which translates the pseudonym to the local user id. LTI 1.1 basic outcomes cannot be translated: their result id carries the local user id and is therefore sent empty unless the disclosure of the result id is set to "pass".</p>';
$string['boundaryhdr'] = 'Data protection boundary';
$string['change'] = 'Change';
$string['confirmaction:purgeorphans'] = 'Permanently delete all mappings of removed tool registrations?';
$string['confirmaction:rebind'] = 'Move the pseudonyms of the removed registration to this registration?';
$string['confirmaction:rotate'] = 'Retire all active pseudonyms of this tool and issue new ones? The tool will see everybody as a new user.';
$string['confirmchange'] = 'I understand that this changes the identity delivered to the tool';
$string['confirmdelete'] = 'Reset this level to inherit from the level above? This can change the identity delivered to the tool.';
$string['confirmhdr'] = 'Confirmation';
$string['confirminfo'] = 'Changing the pseudonym type or scope changes the identity delivered to the tool. {$a->mappings} active mapping(s) exist. The tool will see the affected users as new accounts and may lose their history. Nothing is migrated automatically.';
$string['conflict:coremaysend'] = '{$a}: the policy requires removal, but the tool registration lets the activity decide. A launch is refused when the activity sends it.';
$string['conflict:coresends'] = '{$a}: the policy requires removal, but the tool registration makes Moodle core send it. Every launch would be refused. Set the tool to not share it, or use "send empty".';
$string['courseoverrides'] = 'Course overrides per tool';
$string['coursepolicy'] = 'Pseudonym policy';
$string['delegationhdr'] = 'Delegation and services';
$string['disc:blank'] = 'Send empty (the claim stays in the token with an empty value)';
$string['disc:email'] = 'E-mail address';
$string['disc:extra'] = 'Other personal data in custom parameters';
$string['disc:name'] = 'Names';
$string['disc:nonerestricted'] = 'nothing restricted';
$string['disc:pass'] = 'Deliver as configured in the tool';
$string['disc:picture'] = 'Picture';
$string['disc:remove'] = 'Remove (claim absent; the launch is refused while Moodle core still builds it)';
$string['disc:result'] = 'Result source id (contains the local user id)';
$string['disc:sourcedid'] = 'Person source id (ID number)';
$string['disc:username'] = 'Username';
$string['disclosurehdr'] = 'Disclosure of other identifying data';
$string['disclosureinfo'] = 'These settings are independent of the subject identifier. "Send empty" replaces the value by an empty string: the claim is still present in the token. "Remove" requires the claim to be absent. A plugin of this type cannot delete a launch parameter, so "remove" works only when the tool registration makes core not build it (for example name or e-mail set to "never share"); otherwise the launch is refused instead of sending an empty claim under the promise "removed". The person source id is always built by core, so it can only be sent empty.';
$string['edit'] = 'Edit';
$string['editactivity'] = 'Edit activity override';
$string['editpolicy'] = 'Edit pseudonym policy';
$string['event:policychanged'] = 'Pseudonym policy changed';
$string['identifiability'] = 'Identifiability';
$string['identifiability:high'] = 'high';
$string['identifiability:low'] = 'low';
$string['identifiability:medium'] = 'medium';
$string['identityhdr'] = 'Identity (LTI subject)';
$string['inherit'] = 'Inherit from the level above';
$string['launchrefused'] = 'The tool launch was refused by the pseudonym policy ({$a}). No identifier was sent.';
$string['legacyenabled'] = 'Enable legacy identifier modes';
$string['legacyenabled_desc'] = 'Allows the explicit opt-in to send the internal user id as subject (identifiability high). Disabled by default. While disabled, a policy that selects a legacy mode refuses the launch.';
$string['level:cm'] = 'Activity';
$string['level:course'] = 'Course';
$string['level:site'] = 'Site default';
$string['level:type'] = 'Tool registration';
$string['loginhintboundaryhdr'] = 'Data protection boundary';
$string['loginhintnotice'] = 'Known limitation (gate SP-G1, open): the OIDC login initiation that precedes every launch sends the local Moodle user id to the tool as login_hint, and Moodle core offers no hook to change it (its login endpoint rejects any other value). Pseudonymisation protects the id token (sub and claims) only. It does not hide the local user id from the operator of the tool, and a launch is not end-to-end pseudonymous.';
$string['ltipseudonym:managecoursepolicy'] = 'Edit course and activity overrides of the pseudonym policy';
$string['ltipseudonym:managepolicy'] = 'Edit site and tool policies of the pseudonym plugin';
$string['ltipseudonym:migrateidentity'] = 'Rotate and migrate pseudonyms issued to tools';
$string['ltipseudonym:view'] = 'View the pseudonym policy of a course';
$string['mappings'] = 'Active mappings';
$string['migrate'] = 'Migration';
$string['migratepreview'] = 'Preview';
$string['migratewarning'] = 'A rotation gives every user a new pseudonym on the next launch. The tool will treat them as new accounts. Use it only deliberately, ideally after testing with a test deployment.';
$string['migrationdone'] = 'Done: {$a} mapping(s) affected.';
$string['nolti13activities'] = 'This course has no LTI 1.3 activities.';
$string['notlti13'] = 'This tool registration is not an LTI 1.3 tool. The plugin only handles LTI 1.3.';
$string['orphansfound'] = '{$a} mapping(s) belong to tool registrations that no longer exist.';
$string['outcomeleak'] = 'The disclosure of the result id is set to "pass" while core builds it for this tool: the LTI 1.1 result sourcedid carries the local user id.';
$string['pluginname'] = 'LTI Pseudonym';
$string['policydeleted'] = 'The policy was reset; the level inherits again.';
$string['policyoverview'] = 'Pseudonym policies';
$string['policyrejected'] = 'The policy was rejected: {$a}';
$string['policysaved'] = 'The policy was saved.';
$string['previewcounts'] = 'Active mappings: {$a->active}; users: {$a->users}; retired mappings: {$a->retired}; current generation: {$a->generation}.';
$string['privacy:metadata'] = 'The plugin stores a local mapping between users and the pseudonyms delivered to external LTI tools, and an audit trail of policy changes.';
$string['privacy:metadata:audit'] = 'The audit trail of policy and identity changes. It never contains pseudonyms.';
$string['privacy:metadata:audit:action'] = 'What was done.';
$string['privacy:metadata:audit:actorid'] = 'The user who made the change.';
$string['privacy:metadata:audit:timecreated'] = 'When the change was made.';
$string['privacy:metadata:external'] = 'The pseudonym is sent to the external LTI tool in every launch. What the tool stores under the pseudonym is outside this platform. Deleting the mapping here removes the only link between the pseudonym and the person. Independently of any pseudonym, the OIDC login initiation sends the local user id to the tool as login_hint (data protection boundary, gate SP-G1): this plugin cannot prevent it.';
$string['privacy:metadata:external:pseudonym'] = 'The pseudonym replacing the user id as LTI subject.';
$string['privacy:metadata:external:userid'] = 'The local Moodle user id, sent as login_hint of the OIDC login initiation before every launch by Moodle core, whatever the policy.';
$string['privacy:metadata:mapping'] = 'The mapping between a user and the pseudonym (LTI subject) delivered to a tool in a scope. It stays on this platform.';
$string['privacy:metadata:mapping:scope'] = 'The scope (site, tool registration, course or activity) the pseudonym is valid in.';
$string['privacy:metadata:mapping:status'] = 'Whether the mapping is active or was retired by a rotation.';
$string['privacy:metadata:mapping:time'] = 'When the mapping was created or last used.';
$string['privacy:metadata:mapping:token'] = 'The pseudonym delivered to the tool.';
$string['privacy:metadata:mapping:typeid'] = 'The LTI tool registration the pseudonym belongs to.';
$string['privacy:metadata:mapping:userid'] = 'The user the pseudonym belongs to.';
$string['privacy:metadata:mapping:version'] = 'The identity generation of the pseudonym (raised by a rotation).';
$string['privacy:metadata:policy'] = 'The pseudonymisation and disclosure policies. Only the person who last changed a policy is personal data.';
$string['privacy:metadata:policy:timemodified'] = 'When the policy was last changed.';
$string['privacy:metadata:policy:usermodified'] = 'The user who last changed the policy.';
$string['privacy:path'] = 'LTI Pseudonym';
$string['problem:confirmrequired'] = 'Please confirm the identity change.';
$string['problem:coremaysend'] = 'Removal depends on the activity: the tool registration lets the activity decide. A launch is refused when the activity sends this claim.';
$string['problem:coresends'] = 'Removal is impossible: the tool registration makes Moodle core send this claim, so every launch would be refused. Set the tool to not share it, or use "send empty".';
$string['problem:invalidvalue'] = 'Invalid value.';
$string['problem:legacydisabled'] = 'Legacy modes are disabled in the plugin settings.';
$string['problem:parentforbids'] = 'The level above does not allow overrides.';
$string['problem:weakerthanparent'] = 'This value is weaker than the level above allows.';
$string['pseudotype'] = 'Pseudonym type';
$string['pseudotype_help'] = 'Random token: a random value of at least 128 bit, stored locally; it cannot be derived from anything. HMAC: derived with a secret key and the tool, scope and user; reproducible by this site only. Legacy internal id: sends the Moodle user id, offered only when legacy modes are enabled in the plugin settings. A lower level may only choose a type at least as strong as the level above.';
$string['purgeorphans'] = 'Delete mappings of removed tool registrations';
$string['rebind'] = 'Re-bind mappings to this tool registration';
$string['rebindconflicts'] = 'The re-binding was refused because {$a} user mapping(s) would collide with existing ones of the target registration.';
$string['rebindinvalid'] = 'The re-binding is not possible: the target must be a different LTI 1.3 tool registration.';
$string['rebindpreview'] = 'Mappings of removed registration {$a->from}: {$a->move} would move, {$a->conflicts} collide.';
$string['resettoinherit'] = 'Reset to inherit';
$string['retireddays'] = 'Retention of retired mappings (days)';
$string['retireddays_desc'] = 'Mappings retired by a rotation are deleted after this many days without use. 0 keeps them forever.';
$string['rotate'] = 'Rotate identities of this tool';
$string['scope:cm'] = 'Per tool registration and activity';
$string['scope:course'] = 'Per tool registration and course';
$string['scope:deployment'] = 'Per tool registration';
$string['scope:site'] = 'Whole site (one identity for all tools using this scope)';
$string['scopetype'] = 'Scope';
$string['scopetype_help'] = 'The scope decides where the same person keeps the same pseudonym: per whole site, per tool registration, per course or per activity. Narrower scopes cannot be correlated. Changing the scope gives everyone new pseudonyms; the tool will treat them as new users.';
$string['services'] = 'Services';
$string['servicesinfo'] = 'This tool has {$a} enabled. With a pseudonymising policy the launch points the tool to the service proxy of this plugin, which translates the pseudonym to the local user id for these services. LTI 1.1 basic outcomes cannot be translated (see the disclosure of the result id).';
$string['servicesproxy'] = 'Answered by the service proxy of this plugin (pseudonyms in roster and grade return, disclosure policy in the roster).';
$string['sitedefault'] = 'Site default';
$string['sitescopemigrate'] = 'Migration of the site scope';
$string['task:purgeretired'] = 'Purge retired pseudonym mappings';
$string['tool'] = 'Tool';
$string['type:hmac_secret_v1'] = 'HMAC with secret key (v1)';
$string['type:legacy_userid'] = 'Legacy: internal user id';
$string['type:none'] = 'Not configured (identity untouched)';
$string['type:random_persisted'] = 'Random token (persisted)';
