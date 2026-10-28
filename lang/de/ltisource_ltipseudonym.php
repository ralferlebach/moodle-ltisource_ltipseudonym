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
 * German strings for ltisource_ltipseudonym.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Keep the keys in alphabetical order: moodle.Files.LangFilesOrdering enforces it.
$string['actions'] = 'Aktionen';
$string['activityuigate'] = 'Das Einstellungsformular einer LTI-Aktivität kann von diesem Plugin-Typ nicht erweitert werden (Gate SP-G4). Aktivitätsüberschreibungen werden daher auf dieser Seite bearbeitet.';
$string['allowoverride'] = 'Überschreibungen auf unteren Ebenen erlauben';
$string['allowoverride_help'] = 'Untere Ebenen (Kurs, Aktivität) dürfen dann strengere Einstellungen ergänzen. Sie können nie abschwächen, was hier gesetzt ist.';
$string['audittrail'] = 'Änderungsprotokoll';
$string['boundary_desc'] = '<p><strong>Bekannte Einschränkung (Gate SP-G1, offen):</strong> Vor jedem LTI-1.3-Start sendet Moodle-Core die LOKALE Nutzer-ID als <code>login_hint</code> an das Tool (Formular-Post des Browsers an die Login-URL des Tools); der eigene Login-Endpunkt lehnt jeden anderen Wert ab. Dieses Plugin kann das nicht ändern. Die Pseudonymisierung schützt nur das ID-Token (<code>sub</code> und die Claims) und die Antworten der Dienste. Sie verbirgt die lokale Nutzer-ID nicht vor dem Betreiber des Tools; ein Start darf daher nicht als durchgängig pseudonym bezeichnet werden. Ein Start, den die Richtlinie ablehnt, wird nach Möglichkeit schon vor der Login-Initiierung vorhergesagt und abgelehnt, sodass nichts beim Tool ankommt.</p><p><strong>Dienste (Befunde SP-02, SP-03):</strong> Bei einer aktiven Richtlinie werden Teilnehmerverzeichnis (NRPS) und Notenservice (AGS) eines Tools vom Dienst-Proxy dieses Plugins beantwortet, der das Pseudonym in die lokale Nutzer-ID übersetzt und die Weitergabe-Richtlinie anwendet. Direkte Aufrufe des Core-Endpunkts (mod/lti/services.php) für ein solches Tool beantwortet ebenfalls der Proxy. LTI-1.1-Basic-Outcomes lassen sich nicht übersetzen: Ihre Ergebnis-ID enthält die lokale Nutzer-ID und wird daher leer gesendet, außer die Weitergabe der Ergebnis-ID steht auf "durchreichen".</p><p><strong>Nicht abgedeckt:</strong> Starts von LTI-1.0/1.1-Tools (siehe Einstellung unten). Was eine Person unter jeder Richtlinie weiterhin identifiziert, steht in docs/DISCLOSURE_TRACE.md des Plugins.</p>';
$string['boundaryhdr'] = 'Datenschutzgrenze';
$string['change'] = 'Änderung';
$string['cli:import:done'] = 'Der Import wurde geschrieben. Prüfen Sie einen Start eines betroffenen Tools und deinstallieren Sie danach ltisource_scopedidentity (Website-Administration > Plugins > Plugin-Übersicht oder admin/cli/uninstall_plugins.php).';
$string['cli:import:dryrun'] = 'Probelauf: Es wurde nichts geschrieben. Mit --execute erneut aufrufen, um den Import zu schreiben.';
$string['cli:import:notables'] = 'Keine Tabelle von ltisource_scopedidentity gefunden: nichts zu importieren (das alte Plugin ist nicht installiert oder wurde bereits deinstalliert).';
$string['cli:import:report'] = 'Gefundene Zuordnungen: {$a->found}. Importiert{$a->mode}: {$a->imported}. Bereits vorhanden (gleiches Pseudonym): {$a->already}. Konflikte (die vorhandene Zeile bleibt): {$a->conflicts}. Übersprungen, Person gelöscht oder fehlt: {$a->skippeduser}. Übersprungen, Tool-Registrierung fehlt: {$a->skippedtype}. Angehobene Identitätsgenerationen: {$a->generations}. Hier zurückgezogene Zeilen einer älteren Generation: {$a->retired}. Importierte Richtlinien: {$a->policies}. Übersprungene Richtlinien (auf der Ebene existiert schon eine): {$a->policyconflicts}.';
$string['cli:import:wouldbe'] = ' (würde)';
$string['confirmaction:purgeorphans'] = 'Alle Zuordnungen entfernter Tool-Registrierungen endgültig löschen?';
$string['confirmaction:rebind'] = 'Die Pseudonyme der entfernten Registrierung auf diese Registrierung übertragen?';
$string['confirmaction:rotate'] = 'Alle aktiven Pseudonyme dieses Tools stilllegen und neue ausgeben? Das Tool sieht alle als neue Nutzende.';
$string['confirmchange'] = 'Mir ist bewusst, dass dies die dem Tool übermittelte Identität ändert';
$string['confirmdelete'] = 'Diese Ebene auf Vererbung zurücksetzen? Dies kann die dem Tool übermittelte Identität ändern.';
$string['confirmhdr'] = 'Bestätigung';
$string['confirminfo'] = 'Eine Änderung von Pseudonymtyp oder Geltungsbereich ändert die dem Tool übermittelte Identität. Es gibt {$a->mappings} aktive Zuordnung(en). Das Tool sieht die betroffenen Nutzenden als neue Konten und kann deren Verlauf verlieren. Es wird nichts automatisch migriert.';
$string['conflict:coremaysend'] = '{$a}: Die Richtlinie verlangt Entfernen, aber die Tool-Registrierung überlässt die Entscheidung der Aktivität. Ein Start wird abgelehnt, wenn die Aktivität ihn sendet.';
$string['conflict:coresends'] = '{$a}: Die Richtlinie verlangt Entfernen, aber die Tool-Registrierung lässt Moodle-Core ihn senden. Jeder Start würde abgelehnt. Das Tool auf "nicht weitergeben" stellen oder "Leer senden" wählen.';
$string['courseoverrides'] = 'Kursüberschreibungen pro Tool';
$string['coursepolicy'] = 'Pseudonym-Richtlinie';
$string['delegationhdr'] = 'Delegation und Dienste';
$string['disc:blank'] = 'Leer senden (der Claim bleibt mit leerem Wert im Token)';
$string['disc:email'] = 'E-Mail-Adresse';
$string['disc:extra'] = 'Weitere personenbezogene Daten in Custom-Parametern';
$string['disc:name'] = 'Namen';
$string['disc:nonerestricted'] = 'nichts eingeschränkt';
$string['disc:pass'] = 'Wie im Tool konfiguriert übermitteln';
$string['disc:picture'] = 'Profilbild';
$string['disc:remove'] = 'Entfernen (Claim fehlt; der Start wird abgelehnt, solange Moodle-Core ihn noch erzeugt)';
$string['disc:result'] = 'Ergebnis-Kennung (enthält die lokale Nutzer-ID)';
$string['disc:sourcedid'] = 'Personenkennung (ID-Nummer)';
$string['disc:username'] = 'Nutzername';
$string['disclosurehdr'] = 'Offenlegung weiterer identifizierender Daten';
$string['disclosureinfo'] = 'Diese Einstellungen sind unabhängig vom Subject-Identifier. Es gibt vier Ergebnisse. Durchreichen: der Wert, wie Moodle-Core ihn erzeugt. Leer senden: Der Wert wird durch einen leeren String ersetzt, der Claim bleibt im Token. Entfernen: Der Claim fehlt; ein Plugin dieses Typs kann keinen Startparameter löschen, daher funktioniert das nur, wenn die Tool-Registrierung Moodle-Core davon abhält, ihn zu erzeugen (zum Beispiel Name oder E-Mail auf "nie weitergeben"). Ablehnen: das Ergebnis von "Entfernen", solange Moodle-Core den Claim noch erzeugt; der Start wird abgelehnt, statt unter dem Versprechen "entfernt" einen leeren Claim zu senden. Die Personen-Source-ID erzeugt Moodle-Core immer; sie kann nur leer gesendet werden.';
$string['disclosureoutcomes'] = 'Was ein Tool je Kategorie erhält. Durchreichen: der Wert, wie Moodle-Core ihn erzeugt. Leer senden: Der Claim wird mit leerem Wert gesendet (er ist weiterhin im Token). Entfernen: Der Claim fehlt; das ist nur möglich, wenn die Tool-Registrierung ihn nicht weitergibt. Ablehnen: das Ergebnis von "Entfernen", solange Moodle-Core den Claim noch erzeugt; der Start wird abgelehnt (nach Möglichkeit schon vor der OIDC-Login-Initiierung), statt unter dem Versprechen "entfernt" einen leeren Claim zu senden.';
$string['discshort:blank'] = 'leer senden';
$string['discshort:pass'] = 'durchreichen';
$string['discshort:remove'] = 'entfernen';
$string['edit'] = 'Bearbeiten';
$string['editactivity'] = 'Aktivitätsüberschreibung bearbeiten';
$string['editpolicy'] = 'Pseudonym-Richtlinie bearbeiten';
$string['effect:absent'] = 'fehlt (die Tool-Registrierung gibt ihn nicht weiter)';
$string['effect:depends'] = 'die Wirkung hängt von der jeweiligen Tool-Registrierung ab';
$string['effect:notsent'] = 'nicht gesendet (die Tool-Registrierung gibt ihn nicht weiter)';
$string['effect:refused'] = 'Start abgelehnt (Moodle-Core sendet diesen Claim weiterhin)';
$string['effect:refusedmaybe'] = 'Start abgelehnt, wenn die Aktivität ihn weitergibt';
$string['effect:sent'] = 'unverändert gesendet';
$string['effect:sentempty'] = 'mit leerem Wert gesendet (der Claim ist vorhanden)';
$string['effect:sentemptymaybe'] = 'mit leerem Wert gesendet, wenn die Aktivität ihn weitergibt';
$string['effect:sentmaybe'] = 'unverändert gesendet, wenn die Aktivität ihn weitergibt';
$string['effectline'] = '{$a->category}: {$a->mode}, {$a->effect}';
$string['event:policychanged'] = 'Pseudonym-Richtlinie geändert';
$string['identifiability'] = 'Identifizierbarkeit';
$string['identifiability:high'] = 'hoch';
$string['identifiability:low'] = 'niedrig';
$string['identifiability:medium'] = 'mittel';
$string['identityhdr'] = 'Identität (LTI-Subject)';
$string['inherit'] = 'Von der übergeordneten Ebene erben';
$string['launchrefused'] = 'Der Tool-Start wurde von der Pseudonym-Richtlinie abgelehnt ({$a}). Es wurden weder ID-Token noch Startdaten an das Tool gesendet.';
$string['legacyenabled'] = 'Legacy-Kennungsmodi aktivieren';
$string['legacyenabled_desc'] = 'Erlaubt das ausdrückliche Opt-in, die interne Nutzer-ID als Subject zu senden (Identifizierbarkeit hoch). Standardmäßig deaktiviert. Solange deaktiviert, lehnt eine Richtlinie mit Legacy-Modus den Start ab.';
$string['legacyprotocol'] = 'LTI-1.0/1.1-Tools bei aktiver Richtlinie';
$string['legacyprotocol:pass'] = 'Unverändert übermitteln (nicht abgedeckt, in der Übersicht aufgeführt)';
$string['legacyprotocol:refuse'] = 'Start ablehnen';
$string['legacyprotocol_desc'] = 'Die Richtlinien decken nur LTI 1.3 ab. Ein Start eines LTI-1.0/1.1-Tools (oder 2.0) enthält die lokale Nutzer-ID und die personenbezogenen Daten, die die Tool-Registrierung weitergibt, unabhängig von der Richtlinie. "Unverändert übermitteln" (Standard, Verhalten von 0.2.0) lässt solche Starts zu und führt die Registrierungen in der Richtlinienübersicht als nicht abgedeckt auf. "Start ablehnen" lehnt jeden Start eines solchen Tools ab, solange die Richtlinie seiner Position (Website-Standard oder eine für die Registrierung gespeicherte Richtlinie) aktiv ist.';
$string['legacytypes:pass'] = 'Nicht von den Richtlinien abgedeckt (LTI 1.0/1.1/2.0): {$a}. Ihre Starts enthalten die lokale Nutzer-ID und die personenbezogenen Daten, die ihre Registrierung weitergibt (Einstellung "LTI-1.0/1.1-Tools bei aktiver Richtlinie": unverändert übermitteln).';
$string['legacytypes:refuse'] = 'LTI-1.0/1.1/2.0-Registrierungen: {$a}. Ihre Starts werden abgelehnt, solange die Richtlinie ihrer Position aktiv ist (Einstellung "LTI-1.0/1.1-Tools bei aktiver Richtlinie": Start ablehnen).';
$string['level:cm'] = 'Aktivität';
$string['level:course'] = 'Kurs';
$string['level:site'] = 'Seitenstandard';
$string['level:type'] = 'Tool-Registrierung';
$string['loginhintboundaryhdr'] = 'Datenschutzgrenze';
$string['loginhintnotice'] = 'Bekannte Einschränkung (Gate SP-G1, offen): Die OIDC-Login-Initiierung vor jedem Start sendet die lokale Moodle-Nutzer-ID als login_hint an das Tool, und Moodle-Core bietet keinen Hook, sie zu ändern (der Login-Endpunkt lehnt jeden anderen Wert ab). Die Pseudonymisierung schützt nur das ID-Token (sub und Claims). Sie verbirgt die lokale Nutzer-ID nicht vor dem Betreiber des Tools; ein Start ist nicht durchgängig pseudonym.';
$string['ltipseudonym:managecoursepolicy'] = 'Kurs- und Aktivitätsüberschreibungen der Pseudonym-Richtlinie bearbeiten';
$string['ltipseudonym:managepolicy'] = 'Seiten- und Tool-Richtlinien des Pseudonym-Plugins bearbeiten';
$string['ltipseudonym:migrateidentity'] = 'An Tools ausgegebene Pseudonyme rotieren und migrieren';
$string['ltipseudonym:view'] = 'Pseudonym-Richtlinie eines Kurses ansehen';
$string['mappings'] = 'Aktive Zuordnungen';
$string['migrate'] = 'Migration';
$string['migratepreview'] = 'Vorschau';
$string['migratewarning'] = 'Eine Rotation vergibt allen Nutzenden beim nächsten Start ein neues Pseudonym. Das Tool behandelt sie als neue Konten. Nur bewusst einsetzen, am besten nach einem Test mit einem Test-Deployment.';
$string['migrationdone'] = 'Erledigt: {$a} Zuordnung(en) betroffen.';
$string['nolti13activities'] = 'Dieser Kurs hat keine LTI-1.3-Aktivitäten.';
$string['notlti13'] = 'Diese Tool-Registrierung ist kein LTI-1.3-Tool. Das Plugin behandelt nur LTI 1.3.';
$string['orphansfound'] = '{$a} Zuordnung(en) gehören zu Tool-Registrierungen, die nicht mehr existieren.';
$string['outcomeleak'] = 'Die Weitergabe der Ergebnis-ID steht auf "durchreichen", obwohl Core sie für dieses Tool erzeugt: die LTI-1.1-Result-Sourcedid enthält die lokale Nutzer-ID.';
$string['pluginname'] = 'LTI-Pseudonym (bereichsbezogene Pseudonyme)';
$string['policydeleted'] = 'Die Richtlinie wurde zurückgesetzt; die Ebene erbt wieder.';
$string['policyoverview'] = 'Pseudonym-Richtlinien';
$string['policyrejected'] = 'Die Richtlinie wurde abgelehnt: {$a}';
$string['policysaved'] = 'Die Richtlinie wurde gespeichert.';
$string['previewcounts'] = 'Aktive Zuordnungen: {$a->active}; Nutzende: {$a->users}; stillgelegte Zuordnungen: {$a->retired}; aktuelle Generation: {$a->generation}.';
$string['privacy:metadata'] = 'Das Plugin speichert eine lokale Zuordnung zwischen Nutzenden und den an externe LTI-Tools übermittelten Pseudonymen sowie ein Änderungsprotokoll der Richtlinien.';
$string['privacy:metadata:audit'] = 'Das Änderungsprotokoll der Richtlinien und Identitäten. Es enthält nie Pseudonyme.';
$string['privacy:metadata:audit:action'] = 'Was getan wurde.';
$string['privacy:metadata:audit:actorid'] = 'Die Person, die die Änderung vorgenommen hat.';
$string['privacy:metadata:audit:timecreated'] = 'Wann die Änderung vorgenommen wurde.';
$string['privacy:metadata:external'] = 'Das Pseudonym wird bei jedem Start an das externe LTI-Tool gesendet. Was das Tool unter dem Pseudonym speichert, liegt außerhalb dieser Plattform. Das Löschen der Zuordnung entfernt die einzige Verbindung zwischen Pseudonym und Person. Unabhängig von jedem Pseudonym sendet die OIDC-Login-Initiierung die lokale Nutzer-ID als login_hint an das Tool (Datenschutzgrenze, Gate SP-G1): Dieses Plugin kann das nicht verhindern.';
$string['privacy:metadata:external:pseudonym'] = 'Das Pseudonym, das die Nutzer-ID als LTI-Subject ersetzt.';
$string['privacy:metadata:external:userid'] = 'Die lokale Moodle-Nutzer-ID, die Moodle-Core vor jedem Start als login_hint der OIDC-Login-Initiierung sendet, unabhängig von der Richtlinie.';
$string['privacy:metadata:mapping'] = 'Die Zuordnung zwischen einer Person und dem Pseudonym (LTI-Subject), das einem Tool in einem Geltungsbereich übermittelt wird. Sie verbleibt auf dieser Plattform.';
$string['privacy:metadata:mapping:scope'] = 'Der Geltungsbereich (Seite, Tool-Registrierung, Kurs oder Aktivität), in dem das Pseudonym gilt.';
$string['privacy:metadata:mapping:status'] = 'Ob die Zuordnung aktiv ist oder durch eine Rotation stillgelegt wurde.';
$string['privacy:metadata:mapping:time'] = 'Wann die Zuordnung erstellt oder zuletzt verwendet wurde.';
$string['privacy:metadata:mapping:token'] = 'Das dem Tool übermittelte Pseudonym.';
$string['privacy:metadata:mapping:typeid'] = 'Die LTI-Tool-Registrierung, zu der das Pseudonym gehört.';
$string['privacy:metadata:mapping:userid'] = 'Die Person, zu der das Pseudonym gehört.';
$string['privacy:metadata:mapping:version'] = 'Die Identitätsgeneration des Pseudonyms (wird durch eine Rotation erhöht).';
$string['privacy:metadata:policy'] = 'Die Pseudonymisierungs- und Offenlegungsrichtlinien. Personenbezogen ist nur, wer eine Richtlinie zuletzt geändert hat.';
$string['privacy:metadata:policy:timemodified'] = 'Wann die Richtlinie zuletzt geändert wurde.';
$string['privacy:metadata:policy:usermodified'] = 'Die Person, die die Richtlinie zuletzt geändert hat.';
$string['privacy:path'] = 'LTI Pseudonym';
$string['problem:confirmrequired'] = 'Bitte bestätigen Sie die Identitätsänderung.';
$string['problem:coremaysend'] = 'Entfernen hängt von der Aktivität ab: Die Tool-Registrierung überlässt die Entscheidung der Aktivität. Ein Start wird abgelehnt, wenn die Aktivität diesen Claim sendet.';
$string['problem:coresends'] = 'Entfernen ist unmöglich: Die Tool-Registrierung lässt Moodle-Core diesen Claim senden, jeder Start würde abgelehnt. Das Tool auf "nicht weitergeben" stellen oder "Leer senden" wählen.';
$string['problem:invalidvalue'] = 'Ungültiger Wert.';
$string['problem:legacydisabled'] = 'Legacy-Modi sind in den Plugin-Einstellungen deaktiviert.';
$string['problem:parentforbids'] = 'Die übergeordnete Ebene erlaubt keine Überschreibungen.';
$string['problem:weakerthanparent'] = 'Dieser Wert ist schwächer, als die übergeordnete Ebene erlaubt.';
$string['pseudotype'] = 'Pseudonymtyp';
$string['pseudotype_help'] = 'Zufallstoken: ein Zufallswert von mindestens 128 Bit, lokal gespeichert, nicht ableitbar. HMAC: mit geheimem Schlüssel aus Tool, Geltungsbereich und Nutzer abgeleitet, nur von dieser Seite reproduzierbar. Legacy interne ID: sendet die Moodle-Nutzer-ID, nur wählbar, wenn Legacy-Modi in den Plugin-Einstellungen aktiviert sind. Eine untere Ebene darf nur einen mindestens gleich starken Typ wählen.';
$string['purgeorphans'] = 'Zuordnungen entfernter Tool-Registrierungen löschen';
$string['rebind'] = 'Zuordnungen an diese Tool-Registrierung binden';
$string['rebindconflicts'] = 'Die Neuzuordnung wurde abgelehnt, weil {$a} Zuordnung(en) mit bestehenden Zuordnungen der Ziel-Registrierung kollidieren würden.';
$string['rebindinvalid'] = 'Die Neuzuordnung ist nicht möglich: Das Ziel muss eine andere LTI-1.3-Tool-Registrierung sein.';
$string['rebindpreview'] = 'Zuordnungen der entfernten Registrierung {$a->from}: {$a->move} würden verschoben, {$a->conflicts} kollidieren.';
$string['resettoinherit'] = 'Auf Vererbung zurücksetzen';
$string['retireddays'] = 'Aufbewahrung stillgelegter Zuordnungen (Tage)';
$string['retireddays_desc'] = 'Durch eine Rotation stillgelegte Zuordnungen werden nach so vielen Tagen ohne Nutzung gelöscht. 0 behält sie dauerhaft.';
$string['rotate'] = 'Identitäten dieses Tools rotieren';
$string['scope:cm'] = 'Pro Tool-Registrierung und Aktivität';
$string['scope:course'] = 'Pro Tool-Registrierung und Kurs';
$string['scope:deployment'] = 'Pro Tool-Registrierung';
$string['scope:site'] = 'Gesamte Seite (eine Identität für alle Tools dieses Bereichs)';
$string['scopetype'] = 'Geltungsbereich';
$string['scopetype_help'] = 'Der Geltungsbereich legt fest, wo dieselbe Person dasselbe Pseudonym behält: seitenweit, pro Tool-Registrierung, pro Kurs oder pro Aktivität. Engere Bereiche sind nicht korrelierbar. Eine Änderung vergibt allen neue Pseudonyme; das Tool behandelt sie als neue Nutzende.';
$string['services'] = 'Dienste';
$string['servicesinfo'] = 'Für dieses Tool ist {$a} aktiviert. Bei einer pseudonymisierenden Richtlinie verweist der Start das Tool auf den Dienst-Proxy dieses Plugins, der das Pseudonym für diese Dienste in die lokale Nutzer-ID übersetzt. LTI-1.1-Basic-Outcomes lassen sich nicht übersetzen (siehe Weitergabe der Ergebnis-ID).';
$string['servicesproxy'] = 'Wird vom Dienst-Proxy dieses Plugins beantwortet (Pseudonyme in Teilnehmerverzeichnis und Notenrückgabe, Offenlegungsregel im Teilnehmerverzeichnis).';
$string['sitedefault'] = 'Seitenstandard';
$string['sitescopemigrate'] = 'Migration des Seiten-Geltungsbereichs';
$string['task:purgeretired'] = 'Stillgelegte Pseudonym-Zuordnungen löschen';
$string['tool'] = 'Tool';
$string['type:hmac_secret_v1'] = 'HMAC mit geheimem Schlüssel (v1)';
$string['type:legacy_userid'] = 'Legacy: interne Nutzer-ID';
$string['type:none'] = 'Nicht konfiguriert (Identität unverändert)';
$string['type:random_persisted'] = 'Zufallstoken (persistiert)';
