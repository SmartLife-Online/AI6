# AI6-042 – Entscheidungsanfrage zur nativen Copilot-Sessionablage

Stand: 10. September 2026. Kein Abnahmeprotokoll, kein bestandenes Gate und keine Änderung des Ticketstatus.

## Ergebnis und bereits umgesetzter Umfang

Die ausdrücklich freigegebene Konfigurationsweitergabe ist umgesetzt: Binary, Versionspin und Credentialrevision erreichen ausschließlich Worker und Agent. Für App existiert noch kein Copilot-Doctor. `tickets/AI6-042.md` führt `docker-compose.yml` und den zugehörigen Compose-Vertragstest in `files` und im Initial Scope. Mounts und Isolationskontrollen wurden nicht verändert.

Der Copilot-Adapter ist noch nicht implementiert. Für die untersuchte Version 1.0.83 fehlt ein vertragskonformer Nachweis, dass der programmatische Promptmodus mit vollständig schreibgeschütztem `COPILOT_HOME` funktioniert und ausschließlich im Ergebnisverzeichnis schreibt. Ein leerer Versionspin bleibt leer; die untersuchte Version wird nicht als freigegeben eingetragen.

**Evidenzsperre:** Ebenso fehlt der Nachweis, dass ein realer Linux-Reviewturn wegen der schreibgeschützten Sessionablage tatsächlich scheitert. Die Windows-Pfadauflösung begründet diese Notwendigkeit nicht. Die Abschnitte zum Home-Vertrag sind ausschließlich eine bedingte Untersuchung, kein Vorschlag zur Planübernahme. Insbesondere §4 bleibt bis zum unten beschriebenen Laufzeitnachweis gesperrt. AC-04 und AC-14 gelten unverändert.

## Reproduzierbare Evidenz

Untersucht wurde das offizielle Windows-x64-Archiv der [GitHub-Copilot-CLI-Version v1.0.83](https://github.com/github/copilot-cli/releases/tag/v1.0.83). Der SHA-256-Wert des heruntergeladenen Archivs stimmt mit der veröffentlichten Prüfsumme überein: `0e07221a275fdf7e61619c53566e3a421fd646d74d8e9ca491dbbff221f22945`. `copilot --version` meldet `GitHub Copilot CLI 1.0.83.` Die Untersuchung verwendete temporäre Home-, Konfigurations- und Cacheverzeichnisse, keinen Login und keine bereitgestellte Testauthprojektion.

Nach der nativen Paketextraktion lässt sich die reine Pfadauflösung mit Node prüfen. `packageRoot` bezeichnet das extrahierte Paketverzeichnis der Version, nicht das Repository:

```javascript
const path = require('node:path');
const native = require(path.join(packageRoot, 'prebuilds/win32-x64/runtime.node'));
console.log(native.pathResolvedSessionsHome(null, 'C:/sealed/home', 'C:/parent/home'));
console.log(native.pathResolvedSessionEventsPath(
  null, 'C:/sealed/home', 'C:/parent/home', 'probe-session'
));
```

Die Ergebnisse liegen unter `C:\sealed\home\session-state` beziehungsweise dessen Session-Unterverzeichnis mit `events.jsonl`. Getrennte Werte für `TMPDIR` und `COPILOT_CACHE_HOME` ändern die Sessionpfadauflösung nicht.

Das ausgelieferte `app.js` übergibt der Pfadauflösung ausschließlich den optionalen `configDir`, `COPILOT_HOME` und das Betriebssystem-Home. Beim lokalen Sessionaufbau registriert es einen Sessionereignisschreiber mit `sessionFs.sessionStatePath`. Der Schreiber ruft die nativen Funktionen `sessionEventWriterEnsureRegistered` und `sessionEventWriterFlush` auf. Der untersuchte CLI-Hilfetext bietet getrennte Ziele für Logs und Nutzungswerte, aber keinen getrennten Zielpfad für diese Sessionablage. `configDir` verschiebt das gesamte native Home und löst deshalb die geforderte Trennung nicht. SDK-Optionen sind keine Evidenz für den im Ticket vorgeschriebenen CLI-Prompttransport.

Eine zusätzliche, turnfreie Erweiterungsinventur meldete drei aktivierte eingebaute Skills. Das allein beweist keine wirksame Toolfreigabe; Discovery und tatsächliche Toolverweigerung sind weiterhin getrennt nachzuweisen.

Die Windows-Pfadprobe und Paketinspektion sind weder ein erfolgreicher Reviewturn noch ein Linux-Isolationsnachweis. Zwei lokale Offline-Startversuche ohne erreichbaren Modellendpunkt lieferten keine belastbare Turnevidenz; daraus wird kein bestimmter Startfehler abgeleitet. Es wurde kein realer Smoke bestanden. Der Befund behauptet auch nicht, dass jede Copilot-Version grundsätzlich ungeeignet ist.

## Betroffener Vertrag

`AI6-042/AC-04` verlangt das versiegelte native Home; `AC-14` beschränkt sämtliche Schreibzugriffe auf das Ergebnisverzeichnis. `ExecutionHomeManager` versiegelt den Eingabebaum und trennt den beschreibbaren Ausgabebaum. Eine zusätzliche beschreibbare Sessionablage innerhalb des nativen Homes ist in dieser Naht nicht vorgesehen. Eine Umleitung durch einen selbst angelegten Symlink oder eine Abschwächung der zentralen Isolationsprüfung ist keine zulässige Adapterlösung.

## Offener Linux-Nachweis vor einer Entscheidung

Bei der Nachprüfung am 10. September 2026 war auch außerhalb der Sandbox kein Docker-Daemon erreichbar (`dockerDesktopLinuxEngine`: Pipe nicht vorhanden). Die WSL-Inventur nennt nur `docker-desktop`, keine unabhängige Linux-Testdistribution. Ein realer Reviewturn wurde nicht ausgeführt. Es liegt keine für diesen Nachweis bereitgestellte Testauthprojektion vor; persönliche Provideranmeldungen werden dafür nicht übernommen. Ergebnis der Startfrage: **offen**, weder erfolgreicher Turn noch nachgewiesener Fehler durch Schreibschutz.

Vor einem Vorschlag zur Planübernahme ist folgendes Protokoll mit tatsächlichen Ergebnissen zu ergänzen:

1. Linux-Plattform, Architektur, Image-/Runtimebindung und Repositorycommit festhalten; das offizielle Linux-Binary 1.0.83 samt Archivprüfsumme und tatsächlicher Versionsausgabe binden. Der Windows-Archivhash ist kein Linux-Binarynachweis.
2. Frisches `COPILOT_HOME` mit ausdrücklich bereitgestellter read-only Testauthprojektion und vorhandenem `home/session-state` vollständig read-only einbinden. Unter derselben unprivilegierten Turnidentität Schreibversuche an Homewurzel und Sessionablage nachweislich verweigern; Mounts und Rechte protokollieren. Ergebnisverzeichnis separat beschreibbar halten. Keine Sessionprojektion aus diesem Entwurf aktivieren.
3. Einen echten programmatischen Reviewturn der Version 1.0.83 mit erreichbarem Modellendpunkt und den im Ticket verlangten Tool-/Discoverygrenzen auf einem synthetischen, credentialfreien Reviewworkspace ausführen. Tatsächliche Argumentliste, erlaubte Environment-Namen ohne Geheimniswerte, Promptbindung, Exitcode, redigierte Fehlerdiagnose und Turnabschluss protokollieren; keine GitHub-Mutation durchführen.
4. Festhalten, ob der Start tatsächlich am Schreibschutz der Sessionablage scheitert, ob der Turn trotz verweigerter Schreibversuche funktioniert oder ob ein anderer Fehler die Aussage verhindert. Auth-, Netzwerk- oder Toolkonfigurationsfehler belegen keinen erforderlichen Session-Schreibzugriff. Nur ein ursächlich belegter Schreibschutzfehler eröffnet die Prüfung einer Vertragsänderung; ein erfolgreicher Turn spricht gegen diese Begründung. Ein unklarer Ausgang hält die Evidenzsperre geschlossen.

Dieser technische Nachweis ersetzt weder die übrigen Isolationsnachweise noch die menschliche Abnahme AI6-042/MG-01.

## Bedingte Vertragsuntersuchung

Nur falls der Linux-Nachweis eine notwendige Session-Schreibablage belegt, kommen folgende Grenzen zur weiteren Entscheidung in Betracht:

- Nur die pro Turn frisch angelegte Sessionablage darf beschreibbar sein; Authprojektion, Konfiguration und Instruktionssnapshot bleiben schreibgeschützt.
- Die Sessionbytes gehören zum flüchtigen Ausgabebaum, werden zentral isoliert und bereinigt und niemals zwischen Turns übernommen. Natives Resume bleibt ausgeschaltet.
- Die bestehende Home- und Prozessisolationsnaht erhält eine explizite Bindung dieser Ablage; der Adapter baut keine zweite Isolation.
- Linux-Negativtests müssen fremde Sessions, Schreibzugriffe auf Auth und Eingaben sowie Ausbrüche aus dem Ausgabebaum nachweislich abweisen.

Die Freigabe vom 10. September 2026 umfasst ausschließlich die dokumentarische Untersuchung des erweiterten Home-Vertrags und deren Erfassung im Files-Scope, keine Laufzeitumsetzung. Die nachfolgenden Abschnitte 1 bis 4 stehen unter dem Vorbehalt des ausstehenden Linux-Nachweises; bis dahin bleiben sie eine bedingte Untersuchung und sind nicht zur Planübernahme vorgeschlagen. Die Freigabe ist weder ein Laufzeitnachweis noch eine Freigabe der CLI-Version. Alternativ kann eine konkret benannte CLI-Version mit nachgewiesenem getrenntem oder deaktiviertem Session-Schreibpfad den bestehenden Vertrag ohne diese Erweiterung erfüllen.

Diese Dokumentdatei ist mit der zweiten Scope-Freigabe im Ticket erfasst. Der normative Plan bleibt unverändert. Die unten vorgeschlagenen AC-/TC-Präzisierungen stehen hier als Entwurf; sie sind noch nicht in den geltenden Akzeptanzvertrag übernommen.

## Ausgearbeiteter Home-Vertrag

### 1. Geltungsbereich und physische Ablage

Die einzige neue Ausnahme ist eine flüchtige native Sessionablage für `github_copilot_cli` in einem freigegebenen Reviewturn. Andere Provider, Checker sowie Implementierungs- und Fixturns erhalten diese Ausnahme nicht. Die bestehende Einschränkung der Verifierrolle bleibt bestehen.

Der Worker legt vor der Versiegelung einen leeren, echten Mountzielordner `home/session-state` im Eingabebaum an. Die Schreibquelle ist ein ebenfalls neuer, leerer Ordner `result/copilot-session-state` unter dem Ausgabebaum desselben Execution-Homes. Diese relativen Namen sind vorgeschlagene neue Vertragspfade, keine heute implementierte Naht. Beide werden ausschließlich aus den zentral erzeugten Homewurzeln abgeleitet; weder Ticket, Projektkonfiguration, Providerantwort noch freie CLI-Flags dürfen Quelle oder Ziel bestimmen.

Im privaten Mount-Namespace des Turns wird genau diese Quelle auf genau dieses Ziel eingebunden. `COPILOT_HOME` bleibt auf dem ursprünglichen Homepfad. Der übrige Eingabebaum bleibt schreibgeschützt; Auth, Runtimekonfiguration, Snapshot und Reviewworkspace bleiben unveränderlich. Auch die Elternverzeichnisse des Mountziels bleiben schreibgeschützt. Die Ausnahme erlaubt keine zusätzlichen Konfigurations-, Cache- oder Authdateien an der Homewurzel. Benötigt der Pin solche Schreibzugriffe ebenfalls, bleibt er gesperrt, bis eine eigene Prüfung eine vertragskonforme Lösung belegt.

Die Mountquelle gehört physisch zum Ergebnisverzeichnis. Der native Zielpfad ist ausschließlich eine zusätzliche Sicht auf dieselben Bytes. Dies präzisiert AC-14: Schreibrechte werden anhand der gebundenen Quelle und tatsächlichen Mountidentität geprüft, nicht anhand eines bloßen Pfadpräfixes.

### 2. Bindung und Lebenszyklus

`ExecutionHomeManager::create()` ist der einzige Erzeuger. `ExecutionHome` erhält eine explizite optionale Beschreibung der Sessionprojektion; konkrete neue Feldnamen werden bei der Implementierung festgelegt. Ohne Beschreibung besteht keine Schreibausnahme. Die Beschreibung wird an Execution-ID, den zufälligen Homenamen, Slot, Versuch, Provideralias und Runtimeprofil gebunden. Zwei Versuche derselben AI6-Session erhalten getrennte Verzeichnisse. Es werden keine Sessionbytes kopiert oder von einem Vorgänger übernommen.

`AgentExecutionRunner` und `AgentExecutionProcessor::home()` müssen dieselbe Beschreibung herstellen beziehungsweise aus den servergebundenen Angaben rekonstruieren. Das ist wesentlich: Der Processor konstruiert `ExecutionHome` heute erneut aus dem Mailboxauftrag. Ein nur im Worker ergänztes Objektfeld würde auf dem Produktionsweg verloren gehen. Der Worker-/Agent-Vertrag muss eine fehlende, fremde oder abweichende Projektion vor dem Providerstart verweigern. Ein älterer Auftrag darf nicht nachträglich allein aus dem Alias eine neue Berechtigung erhalten; die aktivierte Projektionsvariante muss Teil der unveränderlichen Runtime-/Kontextbindung sein.

Die zentrale Laufzeit hält die Projektion während des Turns gegen Austausch geschützt. Pfadprüfung und spätere Einbindung dürfen keine austauschbare Quelle offenlassen: Quelle und Ziel werden unter der bestehenden Lifecycle-Koordination gebunden; vor Freigabe des Providers wird ihre Identität im Kind erneut geprüft. Fremde Ausführungen, Elternpfade, Symlinks, Spezialdateien und bereits gefüllte Quellen sind unzulässig. Die Quelle darf bis zur Einbindung für andere Providerprozesse weder erreichbar noch austauschbar sein.

Erst nach bestätigter Einrichtung und Prüfung der Isolation darf der Provider Prozessargumente und Promptbytes erhalten. Bei einer fehlgeschlagenen Einrichtung startet kein Copilot-Prozess; keine Teilübertragung des Prompts ist erlaubt. Bestehende Fehlernormalisierung und Heartbeat-/Cancelwege werden konsumiert.

Erfolg, Providerfehler, Startfehler, Timeout, Cancel und Workerabbruch beenden die Prozessgruppe einschließlich Namespacehelfer. Der private Mount verschwindet mit dieser Prozessumgebung. Anschließend entfernt die vorhandene zentrale Homebereinigung beide Wurzeln einschließlich Sessionquelle. Es gibt keinen gemeinsamen Hostmount, der vom Adapter ausgehängt werden müsste. Bereinigung darf nicht durch einen noch aktiven Provider laufen; die bestehende Ergebnis-/Cleanupkoordination wird für diesen Fall geprüft. Späte Antworten bleiben gesperrt. Die Sessiondateien werden weder als Antwort importiert noch pauschal als Artefakte veröffentlicht oder in einen Credential-Store zurückgeschrieben.

### 3. Zentrale Prozessisolation

`ControlProcessRunner::start()` hat bereits einen Namespacepfad für den Checker; die Agentpolicy startet heute ohne entsprechenden Wrapper. Falls die Erweiterung nach Laufzeitnachweis normativ freigegeben wird, wird genau dieser vorhandene Namespacepfad über servergebundene Rolle und Prozesspolicy parametrisiert und von Checker und Agent gemeinsam verwendet. Rollenabhängige Eingabe-, Ausgabe-, Workspace- und Mountbindungen sind Daten desselben Pfads. Argumentlisten, Startverweigerung, Prozessgruppenführung und Cleanup bleiben zentral. Es entsteht weder ein zweiter Agent-Namespacewrapper noch ein eigener ProcessRunner. Die bestehende Checker-Isolation ist bei dieser Verallgemeinerung unverändert durch ihre Regressionen nachzuweisen.

Die Einrichtung erfolgt in einem eigenen unprivilegierten User-/Mount-/PID-Namespace. Ein für diese Einrichtung nötiges Seccomp-Profil wird eng begrenzt; `privileged`, Host-PID-Namespace, Docker-Socket und zusätzliches `CAP_SYS_ADMIN` im äußeren Agentcontainer sind ausgeschlossen. Nach der Einrichtung verliert der Provider alle Mountfähigkeiten, auch über die erneute Erzeugung eines User-Namespace. Dieser letzte Punkt braucht einen echten Negativnachweis; das bloße Entfernen vorhandener Capabilities reicht dafür nicht als Beweis.

Der Provider darf nur die für seinen Turn benötigten Eingaben, Ausgaben und Laufzeitdateien erreichen. Insbesondere bleiben andere Homes, Sessionquellen, Mailbox-Steuerdateien, Agent-Heartbeats, primäre Datenbank und Produktionsschlüssel unerreichbar. Bereits geöffnete Deskriptoren dürfen diese Grenze nicht umgehen. Die neue Projektion verleiht keine Schreibrechte auf den übrigen gemeinsamen Ausgabemount.

`ProcessIsolationVerifier` führt die bestehende Vorprüfung weiter aus. Zusätzlich prüft eine zentrale Prüfung im fertig eingerichteten Kind-Namespace, unmittelbar vor dem Providerstart:

- Exakt ein erlaubter Sessionmount liegt an dem servergebundenen Ziel; Quelle, Ziel und Execution-Bindung stimmen tatsächlich überein.
- Der Zielmount ist beschreibbar und trägt `nosuid,nodev,noexec`; alle geschützten Eingaben und die Containerwurzel bleiben schreibgeschützt.
- Das native Home und seine Eltern sind nicht austauschbar; es existiert kein weiterer beschreibbarer Unter-Mount in den Eingaben.
- Fremde Turns und Steuerdateien sind unerreichbar; Providerfähigkeiten reichen nicht aus, die Mounts oder diese Prüfung zu verändern.

Ein allein im Supervisor vor der Namespaceeinrichtung ausgeführter Check kann diese Eigenschaften nicht belegen. Eine Selbstbehauptung des Adapters oder ein freies Environmentflag ersetzt die Prüfung nicht. Der Doctor trennt Versionsfähigkeit, vor Ort geprüfte Laufzeitfähigkeit und den realen Copilot-Smoke weiterhin.

### 4. Bedingter Formulierungsentwurf — nicht zur Planübernahme vorgeschlagen

Plan §10.4 beschreibt das isolierte Home bisher als ausschließlich versiegelte Runtimekonfiguration und Authprojektion; Blueprint AI6-042 verlangt das versiegelte `COPILOT_HOME`. Solange der reale Linux-Nachweis fehlt, darf die folgende Formulierung nicht zur Übernahme vorgeschlagen werden. Sie dokumentiert ausschließlich die untersuchte Alternative für den Fall eines belegten notwendigen Session-Schreibzugriffs, ohne vorhandene Requirement-IDs neu zu vergeben:

> Für Copilot darf genau die native Sessionablage pro Turn eine beschreibbare Projektion eines frisch angelegten Unterverzeichnisses des Ergebnisausgangs sein. Die Projektion ist servergebunden, wird ausschließlich in der zentral geprüften privaten Prozessumgebung eingerichtet und nach dem Turn verworfen. Konfiguration, Authprojektion, Instruktionen und Reviewworkspace bleiben schreibgeschützt. Fremde und persistente Sessiondaten bleiben unerreichbar; natives Resume und eine Wiederverwendung der Ablage sind ausgeschlossen. Fehlende Laufzeitevidenz sperrt das Profil.

Eine abweichende Produktimplementierung bleibt verboten. Erst nach dem Linux-Nachweis kann ein Mensch über einen begründeten Planänderungsantrag entscheiden; der aktuelle Auftrag ändert die Planquelle nicht. Dabei ist ein Split nach Plan §13.7 zu prüfen, weil die gemeinsame Agent-Isolation über einen reinen Providertransport hinausgeht. Dieser Entwurf erfindet keine neue Ticket-ID und setzt keine Abhängigkeit auf ein noch nicht erzeugtes Ticket.

### 5. Vorgeschlagene Ticketpräzisierungen mit stabilen IDs

| Bestehender Eintrag | Vorgeschlagene Präzisierung |
|---|---|
| Tasks 3 und 11 | Die zentrale Sessionprojektion ist die einzige Schreibausnahme an einem nativen Homepfad. Ihre physische Quelle liegt im Ergebnisverzeichnis. Der Adapter erzeugt weder Mounts noch eine zweite Bereinigung. |
| AC-04 | Home, Auth und Konfiguration bleiben versiegelt; ausschließlich die servergebundene native Sessionprojektion ist pro Turn frisch und beschreibbar. Fremde und persistente Home-/Cache-/Historydaten bleiben unerreichbar. |
| AC-10 | Jede Copilot-Invocation beginnt mit leerer nativer Sessionablage; keine Wiederverwendung und kein natives Resume. AI6-Sessions bleiben weiterhin getrennt gebunden. |
| AC-14 | Schreibzugriffe erreichen ausschließlich den erlaubten Ergebnisausgang, einschließlich dessen zentral gebundener nativer Sessionprojektion. Nach Timeout/Cancel existieren weder Providerprozesse noch deren private Mountumgebung weiter. |
| TC-04 | Erfolgreicher Schreibzugriff auf die frisch eingebundene Sessionquelle; tatsächliche Schreibverweigerung für Auth, Konfiguration, Homewurzel und Snapshot. |
| TC-05 und TC-12 | Sessionquelle darf keine fremden Instruktionen oder Credentialbytes einschleusen; Herkunfts-/Profil-/Revisionsdrift sowie zusätzliche Mounts werden verweigert. |
| TC-10 | Zwei Runs, Slots und Versuche sehen getrennte leere Quellen; manipulierte Fremdzuordnung und native Resume-Anforderung starten keinen Provider. |
| TC-14 | Cleanup nach allen terminalen Wegen einschließlich Fehler vor Providerstart; kein Zugriff auf fremde Outputs und keine Veröffentlichung roher Sessiondateien. |
| MG-01 | Zusätzlich tatsächliche Projektion und geschützte Schreibziele in der Agentrolle am gebundenen Implementierungscommit prüfen. |

Keine vorhandene AC-/TC-/MG-ID wird umnummeriert oder als erfüllt markiert. AC-05/AC-06 bleiben vollständig verbindlich: Die Sessionprojektion ist keine Freigabe für Skills, Hooks, MCP oder Schreibtools.

### 6. Implementierungs- und Verifikationsscope

Die folgenden vorhandenen Pfade sind ausschließlich Untersuchungsgegenstand für eine mögliche spätere Freigabe, kein aktuell freigegebener Implementierungsscope. `Dockerfile` und `docker/` bleiben unverändert; für `docker-compose.yml` ist allein die Copilot-Konfigurationsweitergabe freigegeben:

- `app/AI6/Agents/`: Homeerzeugung, explizite Projektionsbeschreibung und Rekonstruktion über die Agentmailbox. Dieser Modulpfad war bereits enthalten.
- `app/AI6/Shared/Process/`: zentraler Start, Vorprüfung, Prüfung in der Kindumgebung und Laufzeitsonde. Neue Implementierungsdateien werden innerhalb dieses bestehenden Moduls angelegt, nicht als neuer Abstraktionslayer.
- `docker-compose.yml`, `Dockerfile`, `docker/`: erforderliche Agent-Laufzeitvoraussetzungen und deren enger Seccomp-Vertrag; bestehende Checker-, Credential- und Rollenregeln bleiben erhalten.
- `tests/Unit/Shared/Process/`: Request-, Start-, Fehler- und Architekturverträge einschließlich Regressionen der bestehenden Prozesswege.
- `tests/Unit/Shared/Runtime/RuntimeScriptsTest.php`, `tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php`, `tests/Feature/Shared/Runtime/RuntimeComposeSmokeTest.php`: ausgelieferte Wrapper-/Rollenbindung und echte Linux-Laufzeit.
- Die bereits erfassten `tests/Unit/Agents/` und `tests/Feature/Agents/`: Home-, Bindungs-, Mailbox- und Adapterverbrauchertests. Insbesondere sind `ExecutionHomeManagerTest`, `AgentExecutionDocumentTest`, `AgentExecutionMailboxTest` und `AgentExecutionBoundaryTest` vorhandene Einstiegspunkte.

Die zentrale Negativmatrix umfasst: falsche Quelle, falsches Ziel, fremder Slot/Versuch, fehlende Runtimebindung, nicht leere Quelle, Symlink-/Mountaustausch, zusätzlicher beschreibbarer Eingabemount, fehlende Mountoptionen, Zugriff auf andere Outputs, erneutes Mounten durch den Provider, fehlende Namespacefähigkeit sowie jedes terminale Cleanupereignis. Ein gezielt falsch eingebundener Mount muss die echte Prüfung scheitern lassen. Der Erfolgsfall muss über die ausgelieferte Agentmailbox und Containerbindung laufen; getrennte Unit-Erfolge ersetzen ihn nicht.

Windowsprüfungen können die Daten- und Vertragsbindung testen. Linux-Mount-/Prozessnachweise und der reale Copilot-Smoke bleiben separat erforderlich und sind durch diese Ausarbeitung nicht erbracht.
