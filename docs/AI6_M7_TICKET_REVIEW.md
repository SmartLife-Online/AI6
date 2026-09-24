# Kritische Prüfliste zu AI6-036, AI6-037 und AI6-038

Stand: 19. September 2026. Geprüfte Codebasis: a7d83e908b24002b8f29f8d605ab79243757fde6. Normative Grundlage: Plan V1.7.8, insbesondere §10.6, §12, §13, §15.8, §18 und §20 sowie das Ticket-Template. Die drei Detailtickets liegen als uncommittete Entwürfe vor. Diese Analyse verändert weder Tickets noch Plan, Status oder Gate-Ergebnisse.

**Gesamturteil:** Die Entwürfe besitzen eine brauchbare Grundstruktur und nutzen überwiegend vorhandene Nähte. Sie sind inhaltlich noch nicht durchgehend umsetzungsreif. Besonders AI6-036 verspricht Betriebs- und Restore-Eigenschaften, die seine konkreten Aufgaben nicht gewährleisten. AI6-037 enthält widersprüchliche Annahmen über seine eigenen Eingabedaten. AI6-038 setzt zusätzlich einen realen Securityreview voraus, den der vorhandene Code ausdrücklich verweigert.

Die wichtigste Vereinfachung wäre, die unterstützten Betriebsfälle enger und ehrlicher zu beschreiben: ein begrenzter Wartungsablauf für Backup/Restore, eine deterministische Migration bekannter Felder mit sichtbaren Ablehnungen und ein Pilot mit klar getrennten Prüfphasen. Dafür braucht es keine zusätzliche Orchestrierungsplattform, kein allgemeines Migrationsframework und keine neue Policy-Schicht.

## Verwendung durch das prüfende LLM

Die folgenden Punkte sind **Prüfvorschläge, keine bereits freigegebenen Zusatzanforderungen**. Jeder Punkt ist erneut gegen den dann aktuellen Code und Plan zu prüfen. Vorschläge können begründet verworfen oder zusammengeführt werden. Insbesondere darf aus einem Sicherheitsbefund nicht automatisch ein großes neues Subsystem entstehen.

Prioritäten:

- **P1:** Vor Umsetzung beziehungsweise Pilotfreigabe auflösen; der Entwurf ist widersprüchlich, der Ablauf nicht erreichbar oder ein zugesagter Schutz fehlt.
- **P2:** Konkrete Präzisierung oder fehlender Nachweis mit erkennbarem Nutzen.
- **P3:** Vereinfachung oder redaktionelle Verbesserung; nur übernehmen, wenn sie tatsächlich Aufwand spart.

Die Kennungen B01–B20, M01–M13, P01–P12 und Q01–Q03 sind ausschließlich Reviewreferenzen. Sie ersetzen keine AC-/TC-/MG-/EXT-IDs. Bei einer Änderung eines veröffentlichten Blueprints ist zuerst die menschliche Planentscheidung erforderlich. Die Detailticket-IDs sind gegenwärtig noch Entwurfs-IDs; trotzdem sollte eine spätere Umnummerierung die Zuordnung aus dieser Liste nachvollziehbar erhalten.

## AI6-036 — Installation, Backup/Restore und Security-Release-Gate

### B01 — P1: Der Installationsassistent muss vor seinen eigenen Prüfungen überhaupt starten können

**Betroffen:** Task 1, AC-01, TC-01.

**Befund:** AI6ServiceProvider löst den Redaction-Schlüsselring bereits beim Bootstrap auf. Die Ausnahmen in mayBootstrapWithoutRedactionKeyring() betreffen key:generate, package:discover, test und die feste init-Migration; ai6:install gehört nicht dazu. Mit leerem Schlüsselring in production erreicht das Kommando seinen eigenen FEHLT-Zweig daher nicht. Ein Featuretest, der nach dem Testbootstrap die Konfiguration ändert, würde einen anderen Ablauf prüfen als der reale CLI-Aufruf.

**Kleinste Verbesserung:** Den Assistenten ausdrücklich als Prüfung nach der dokumentierten Schlüsselbereitstellung definieren und den frühen Bootstrapfehler als erwarteten Installationsbefund behandeln. Falls die Schritt-für-Schritt-Ausgabe schon ohne Schlüssel zwingend sein soll, ist das eine explizite Entscheidung über die Bootstrapgrenze, keine beiläufige Kommandoimplementierung. Die bestehende Sicherheitsgrenze nicht mit einer allgemeinen Ausnahme umgehen.

**Nachweis:** Ein frischer PHP-Prozess je fehlendem Schlüssel, ungültiger Policy und vorbereiteter Installation. Quelle: app/AI6/Shared/AI6ServiceProvider.php:699 und :777.

### B02 — P1: Einen erreichbaren Bootstrap für leere Storage- und Git-Verzeichnisse festlegen

**Betroffen:** Tasks 1, 5, 10, MG-01.

**Befund:** Der Doctor soll ein vorhandenes RunArtifactRoot und im Worker eine known_hosts-Datei verlangen. RunArtifactStore legt seinen Baum beim Speichern an; das Ticket nennt keinen vorbereitenden Erzeugungsschritt. Die leere Neuinstallation darf damit nicht erst einen Run benötigen, um ihren vor dem ersten Run verlangten Doctor zu bestehen. Auch die Einrichtung von Git-Hostkeys und Allowlists muss vor der jeweiligen Prüfung stattfinden.

**Kleinste Verbesserung:** Im Installationsablauf eine kurze, vollständige Reihenfolge festlegen: vertrauenswürdige Konfiguration, Volume-/Verzeichnisvorbereitung, Schema, Administrator, Hostkeys, Provider, Betriebsprüfung. Der lesende Assistent bleibt lesend; fehlende Voraussetzungen nennt er zusammen mit einem tatsächlich ausführbaren Vorbereitungsschritt. Ein bewusst noch unbenutzter, aber korrekt vorbereiteter Storage ist kein Fehler.

**Nachweis:** Fresh-install-Test mit wirklich leeren Volumes, ohne zuvor gespeicherte Testartefakte. Quellen: config/ai6.php:122, app/AI6/Runs/RunArtifactStore.php:207, docker/entrypoint.sh.

### B03 — P1: Doctor und Rotation an die tatsächliche Rollen- und Umgebungsübergabe binden

**Betroffen:** Tasks 4–7, 9–10, AC-06 bis AC-10.

**Befund:** Mail gehört zum Worker, die Login-Bestätigungsadresse wird in Compose aber nur der App übergeben. Die App besitzt wiederum nicht die Checker-Volumes des Workers. APP_PREVIOUS_KEYS wird keinem Dienst übergeben. Eine zusätzliche Zeile in .env.example ermöglicht deshalb noch keine Rotation im Container. Das Ticket verbietet zugleich Änderungen an docker-compose.yml.

**Kleinste Verbesserung:** Je Prüfung die zuständige Rolle und den konkreten Aufruf benennen. Erforderliche, minimale Compose-Weitergaben als sensitive Scopeentscheidung ausweisen. APP_PREVIOUS_KEYS nur an die Rollen geben, die den Produktions-APP_KEY benötigen. Keine vollständige .env-Datei in alle Container reichen und keine fehlenden Credentials in andere Rollen kopieren, um einen Doctor grün zu machen.

**Nachweis:** Prüfung der tatsächlich gestarteten Rollen mit synthetischen Konfigurationswerten und ein TOTP-Restore/Rotationsfall über die ausgelieferte Compose-Verdrahtung. Quellen: docker-compose.yml:100, :141, :237; Laravels vorhandene previous_keys-Auflösung in vendor/laravel/framework/config/app.php:131. Die Unterscheidung zwischen Compose-Interpolation und Containerumgebung bestätigt die [Docker-Dokumentation](https://docs.docker.com/compose/how-tos/environment-variables/set-environment-variables/).

### B04 — P1: Prozesspräsenz nicht als Sandboxnachweis ausgeben

**Betroffen:** Task 6, AC-07, TC-09.

**Befund:** ProviderCapabilityReport::boot() prüft Boot-ID und Heartbeat. Es beweist weder Sandboxfunktion noch Toolgrenzen, Instruktionsisolation oder Credentialtrennung. Der Entwurf erklärt genau diese Präsenz zur Evidenz für die aktive Agentensandbox. Ebenso beweist die Existenz eines Profils mit security_review-Rolle noch nicht dessen ausführbaren Securityreview.

**Kleinste Verbesserung:** Die bereits vorhandene Providerdiagnose mit den gebundenen Capability- und menschlichen Laufzeitnachweisen konsumieren. Präsenz ausschließlich als Lebendigkeitsnachweis anzeigen. SecurityReviewerProfileResolver und den wirklichen Verbraucher berücksichtigen; die Grenze zu einem echten Securityreview ist zusätzlich in P01 beschrieben. Kein zweiter Capability-Parser und keine zweite Sandboxprüfung im Doctor.

**Nachweis:** Frischer Heartbeat bei fehlender beziehungsweise unpassender Sandboxevidenz muss scheitern; gleiches gilt für ein registriertes, aber nicht ausführbares Securityprofil. Quellen: app/AI6/Agents/ProviderCapabilityReport.php:89, :117, :171; app/AI6/Agents/SecurityReviewerProfileResolver.php:12.

### B05 — P1: Die Aussage von --all-processes muss zu den tatsächlich geprüften Rollen passen

**Betroffen:** Task 7, AC-08, TC-10, MG-01.

**Befund:** Ein Hinweis auf ein Healthcheck-Kommando ist keine Prüfung eines laufenden Workers oder Schedulers. Der Entwurf kann diese Rollen nicht von außen lesen und würde trotzdem einen vollständig grünen Gesamtcheck zulassen. RuntimeHealthCommand wertet seine eigene Containerumgebung aus; ein bloß anders gesetztes --role ersetzt keinen Aufruf im richtigen Container. Die App hat zudem einen HTTP-Healthcheck statt desselben Heartbeatvertrags.

**Kleinste Verbesserung:** Die erreichbaren Belege prüfen und nicht beobachtbare Rollen ausdrücklich als ungeprüft ausweisen. Der Installationsnachweis führt ergänzend die vorhandenen Healthchecks in den zuständigen Containern aus. Falls das eine Kommando zwingend alle Rollen abschließend prüfen soll, ist dieser Widerspruch zuerst zu entscheiden. Kein Docker-Socket im Worker und kein neues Heartbeat-Netz nur für diese Option.

**Nachweis:** Angehaltenen Scheduler erkennen; ein alter Agentbericht darf nicht genügen. Quellen: app/AI6/Shared/Runtime/RuntimeHealthCommand.php:16, RuntimeHeartbeat.php:53 und docker-compose.yml.

### B06 — P1: Den Ausführungsort des Release-Gates korrigieren

**Betroffen:** Goal, Tasks 8–10, AC-09; zusätzlich AI6-038/TC-01 und TC-02.

**Befund:** Das Produktionsimage installiert mit --no-dev und schließt tests und phpunit.xml aus. FakeAgentReleaseGateCommand benötigt PHPUnit, Tests-Autoloading und sogar Reflection auf eine Testklasse. Drei Ausnahmen für Plan, Manifest und Generator machen deshalb nur die Manifestprüfung im Image möglich. Sie machen das Release-Gate oder reale PHPUnit-Smokes dort nicht ausführbar. Außerdem läuft das Gate mit festem APP_ENV=testing; es ist kein Ersatz für die strikte Prüfung der produktiven Instanz.

**Kleinste Verbesserung:** Release-Evidenz in einem Linux-Checkout desselben Kandidaten mit den gebundenen Entwicklungsabhängigkeiten erheben; den Runtime-Doctor am gebauten Image ausführen. Commit und Image gemeinsam protokollieren. Die ausführbare Installationsdokumentation muss diese beiden Orte nennen. Nicht allein für Tests sämtliche Entwicklungswerkzeuge ins Produktionsimage aufnehmen.

**Nachweis:** Beide dokumentierten Aufrufe einmal in ihrer vorgesehenen Umgebung ausführen. Quellen: Dockerfile:111 und :123, .dockerignore, app/AI6/Runs/Console/FakeAgentReleaseGateCommand.php:21 und testSelections().

### B07 — P1: Die Backupmenge am Wiederherstellungsziel ausrichten

**Betroffen:** Tasks 2–3, 10, AC-02 bis AC-05, Out of Scope.

**Befund:** SQLite und Runartefakte stellen die Instanz nicht vollständig wieder her. Projektmetadaten verweisen auf Managed-Clones, Gitobjekte und Deploy-Keys; weitere Ausführungsdaten liegen außerhalb des Artefaktbaums. Ein entfernter Gitserver enthält nicht zwingend jeden lokalen Checkpoint. Der Blueprint nennt zusätzlich APP_KEY-Erhalt, während das Detailticket das Schlüsselmaterial vollständig ausschließt.

**Kleinste Verbesserung:** Eine kleine Wiederherstellungstabelle festlegen: was im Backup enthalten ist, was aus Git rekonstruierbar ist, was separat sicher verwahrt wird und welche Zustände danach tatsächlich weiter nutzbar sind. APP_KEY samt noch erforderlichen alten Schlüsseln und Redaction-Ring gehören in ein benanntes, separat geschütztes Notfallpaket oder eine bereits vorhandene Betreiberablage. Eine verschlüsselte Probe ersetzt keine Schlüsselsicherung. Kein eigenes Vault- oder Archivsystem.

**Nachweis:** Restore auf einer frischen Instanz ohne die ursprünglichen Volumes und ohne heimlich weiter vorhandene Schlüsseldateien. Quellen: Blueprint AI6-036 in docs/AI6_IMPLEMENTATION_PLAN.md:3791; docker-compose.yml; app/AI6/Git/ManagedProjectPath.php.

### B08 — P1: Die versprochene Deploy-Key-Neuprovisionierung ist noch kein vorhandener Recoverypfad

**Betroffen:** Task 10, Out of Scope „Backup von Provider-Store und Deploy-Keys“.

**Befund:** QueueDeployKeyProvisioning benutzt claimInitialDeployKeyProvisioning(). Diese Naht akzeptiert nur NOT_PROVISIONED und PROVISIONING_FAILED. Ein wiederhergestelltes, bereits provisioniertes Projekt wird durch den Verlust seiner privaten Schlüssel nicht automatisch zu einem dieser Zustände. „Nach Restore neu provisionieren“ ist daher ohne weiteren Nachweis keine ausführbare Anweisung.

**Kleinste Verbesserung:** Zuerst entscheiden, ob die bestehende Identität aus einer getrennten sicheren Sicherung wiederhergestellt wird oder ein expliziter, autorisierter Recoverypfad nötig ist. Zweiteres gehört als klar begrenzte Voraussetzung in die Planung. Keine manuelle Datenbankmanipulation zum Zurücksetzen des Provisionierungsstatus und keine zweite Provisionierungsklasse nur für Restore.

**Nachweis:** Ein bereits provisioniertes Projekt mit verlorenem Key muss den dokumentierten Weg bis zu einem erfolgreichen Git-Zugriff durchlaufen. Quellen: app/AI6/Git/Actions/QueueDeployKeyProvisioning.php:34; app/AI6/Git/ProjectOperationLease.php:16.

### B09 — P1: SQLite-Snapshot und Artefaktkopie benötigen einen gemeinsamen Konsistenzpunkt

**Betroffen:** Task 2, AC-02, TC-02, Notes.

**Befund:** VACUUM INTO erzeugt einen konsistenten Datenbanksnapshot. Das nachfolgende Kopieren eines laufend veränderten Dateibaums gehört nicht zu dieser Transaktion. Der Retention-Sweep kann eine im Snapshot referenzierte Datei löschen; parallel neu entstehende Dateien können ohne zugehörige Snapshotzeile in die Sicherung gelangen. „Aus demselben Lauf“ löst das nicht. Die [SQLite-Dokumentation](https://www2.sqlite.org/lang_vacuum.html) bestätigt die Snapshotgarantie für die Datenbank, nicht für externe Dateien.

**Kleinste Verbesserung:** Für das MVP bevorzugt ein dokumentiertes Wartungsfenster mit ruhenden Schreibern und genau einem Backupaufruf. Aus dem Snapshot nur die zugehörigen regulären Storageobjekte übernehmen und deren Bindungen prüfen. Eine echte Online-Sicherung über Datenbank und Dateibaum wäre ein eigener Anspruch und sollte nicht unbemerkt entstehen.

**Nachweis:** Fehlende referenzierte Datei, zusätzliche verwaiste Datei und ein Lösch-/Schreibversuch während der Sicherung. Das Backup darf bei Inkonsistenz nicht als abgeschlossen erscheinen.

### B10 — P1: Unterstützte Run- und Queuezustände bei Backup und Restore ausdrücklich begrenzen

**Betroffen:** Tasks 2–3, AC-03, Restore-/Disaster-Recovery-Dokumentation.

**Befund:** Ein Snapshot enthält auch Runs, Approvals, Human Requests, Jobs, Leases und ausstehende Control Operations. Nach Rücksetzen der Datenbank können externe Gitwirkungen bereits erfolgt sein, während die Sicherung noch ihren Vorzustand enthält. Sessions zu löschen verhindert weder die erneute Zustellung eines Jobs noch den Umgang mit einem bereits veränderten Remote. Ausführungsdateien und Mailboxen können gleichzeitig fehlen oder einen neueren Stand tragen.

**Kleinste Verbesserung:** Als einfachsten Ausgangspunkt Sicherungen nur in einem ausdrücklich ruhenden Betriebszustand unterstützen und den unterstützten Zustand vor Backup prüfen. Nach Restore Dienste erst nach der dokumentierten Prüfung von Gitstand und ausstehenden Wirkungen wieder freigeben. Wenn aktive Runs wiederaufnehmbar gesichert werden sollen, ist das ein zu entscheidender zusätzlicher Vertrag. Kein pauschales Löschen von Queue- oder Runmetadaten als Abkürzung.

**Nachweis:** Ein aktiver Run beziehungsweise eine offene Control Operation wird entweder vor der Sicherung benannt abgewiesen oder über den ausdrücklich freigegebenen Recoverypfad geprüft. Ein Restore erzeugt keine unbeabsichtigte zweite Außenwirkung.

**Weitere konkrete Rücksetzwirkung:** Ein nach dem Backup verbrauchter Recoverycode erscheint nach Restore wieder unverbraucht; auch spätere Benutzer-/Rechteänderungen werden zurückgesetzt. Sessionwiderruf allein verhindert das nicht. Die Wiederanlaufanleitung sollte deshalb die Prüfung der wiederhergestellten Zugangsrechte und nötigenfalls die Neuausgabe von Recoverycodes über den vorhandenen autorisierten Weg enthalten. Dafür ist kein zusätzliches Auth-Audit-System nötig. Quelle: app/AI6/Auth/RecoveryCodeManager.php:55 und app/AI6/Auth/Console/ReissueRecoveryCodesCommand.php.

### B11 — P1: „Atomarer Restore ohne Teilwirkung“ ist mit der beschriebenen Reihenfolge nicht zugesichert

**Betroffen:** Task 3, AC-03, Review Focus.

**Befund:** Ein rename der SQLite-Datei macht den späteren Artefakttausch, Sessionwiderruf und Sweep nicht atomar. Ein Fehler zwischen diesen Schritten hinterlässt einen Teilzustand. Bereits geöffnete SQLite-Verbindungen und WAL-/SHM-Dateien sind zusätzlich relevant; das Ticket baut die Verbindung erst nach dem Dateitausch neu auf. Auch der laufende Restoreprozess kann die alte Datenbank bereits geöffnet haben.

**Kleinste Verbesserung:** Vorbereitungsfehler und Fehler während der Umschaltung unterscheiden. Offline-Betrieb, Schließen aller Verbindungen, vollständig vorbereitete Ersatzdaten und ein nachvollziehbarer Rückweg für den bisherigen Zustand gehören in den Ablauf. Bei einem abgebrochenen Wechsel bleibt die Instanz gesperrt, bis der dokumentierte Zustand wiederhergestellt ist. Keine Behauptung allgemeiner Atomarität über mehrere Dateien und keine verteilte Transaktionsinfrastruktur.

**Nachweis:** Fehler beim Datenbankwechsel, Artefaktwechsel und Sweep getrennt injizieren; anschließend sowohl Datenzustand als auch Wiederanlauf beurteilen. Ein Test ausschließlich auf vorab beschädigte Backupdateien reicht nicht.

### B12 — P1: Restoreeingaben und Zielpfade brauchen einen geschlossenen, kleinen Vertrag

**Betroffen:** Tasks 2–3, AC-02 und AC-03, TC-03.

**Befund:** Ein SHA-256-Abgleich sagt nichts über erlaubte Pfade, Symlinks, Dateitypen, Doppelbelegungen oder ein Backupziel innerhalb des Quellbaums. „Reguläre Dateien“ ist für eine sichere Kopie zu ungenau, wenn Symlinks verfolgt werden. Ein manipuliertes Manifest darf keine Datei außerhalb des gewählten Baums lesen oder überschreiben. Die Manifestdatei selbst braucht eine definierte Behandlung; ihre eigene Prüfsumme kann nicht einfach Teil der Liste aller Dateiprüfsummen sein.

**Kleinste Verbesserung:** Zulässige feste Backupbestandteile und relative Dateireferenzen definieren, Duplikate und Pfadausbrüche ablehnen, Symlinks nicht verfolgen, bestehende Ziele nicht ungefragt überschreiben und Quell-/Zielüberlappung abweisen. Prüfsummen auf die Nutzdateien beziehen. Vor einer destruktiven Umschaltung die tatsächlich geprüften Daten verwenden. Prüfsummen als Integritätsprüfung beschreiben; sie authentifizieren kein fremdes Backup.

**Nachweis:** Parentpfad, absoluter Pfad, Symlink, doppelte Referenz, zusätzliche Datei, belegtes Ziel und verschachteltes Ziel. Begrenzte Datei-/Gesamtgrößen nutzen, ohne ein allgemeines Importframework einzuführen.

### B13 — P1: Schema- und Versionsverträglichkeit vor dem Austausch prüfen

**Betroffen:** BackupManifest, Task 3, Upgrade-Dokumentation.

**Befund:** Ein korrekt gehashtes Backup kann eine beschädigte SQLite-Struktur oder einen nicht passenden Migrationsstand enthalten. Das Manifest nennt keine ausreichende Bindung an den Anwendungs-/Schema-Stand; Restore soll ausdrücklich keine Migration ausführen. Ein älterer Snapshot kann daher mit neuerem Code erst nach dem destruktiven Austausch scheitern.

**Kleinste Verbesserung:** Zunächst Restore für denselben freigegebenen Software-/Schema-Stand unterstützen. Im Manifest die dafür nötige Bindung speichern und die Snapshotdatenbank vorab mit SQLite selbst auf Integrität und den erwarteten Migrationsstand prüfen. Upgrade anschließend als getrennten, dokumentierten Schritt behandeln. Keine allgemeine Rückwärtskompatibilitätsmatrix für beliebige Releases bauen.

**Nachweis:** Falscher Schema-Stand und beschädigte Datenbank mit neu berechneter Dateiprüfsumme werden vor Änderung des Ziels verweigert. Ein gleicher, korrekter Stand bleibt wiederherstellbar.

### B14 — P1: Vorhandene Key-ID und eine einzige APP_KEY-Probe reichen für Entschlüsselbarkeit nicht

**Betroffen:** Tasks 2–3, AC-03 und AC-04, TC-03 bis TC-05.

**Befund:** Derselbe Name im Redaction-Ring kann mit anderem Schlüsselmaterial oder anderer Version konfiguriert sein. has() beweist keine korrekte Fingerprintbindung. Ebenso kann die feste Probe mit dem beim Backup aktiven APP_KEY entschlüsselbar sein, während ein älteres TOTP-Geheimnis noch einen inzwischen fehlenden Vorgängerschlüssel benötigt. Die bisherige Positivrotation nach Restore deckt diesen Fall nicht ab.

**Kleinste Verbesserung:** Erforderliche Key-IDs samt Version und tatsächlicher Verwendbarkeit prüfen; dafür die vorhandene zentrale Kryptonaht verwenden. Bei den wenigen verschlüsselten Datenklassen den Entschlüsselungsnachweis im vorbereiteten Snapshot führen, ohne Klartext auszugeben. Historische Schlüssel nur so lange behalten, wie Daten und aufbewahrte Backups sie benötigen; eine einfache dokumentierte Aufbewahrungsregel genügt.

**Nachweis:** Gleiche Key-ID mit falschen Bytes, falscher Version sowie ein schon vor dem Backup rotiertes TOTP-Geheimnis mit fehlendem alten Schlüssel. Quellen: app/AI6/Shared/Redaction/RedactionKeyringFactory.php; app/AI6/Runs/RunArtifactStore.php, assertNotRemoved(); app/AI6/Auth/TotpSecretCipher.php.

### B15 — P1: Wiederauferstehungsschutz für alle Rohdaten und für ältere Backups prüfen

**Betroffen:** AC-05, TC-06, Blueprint-Akzeptanzvertrag.

**Befund:** Der Test fokussiert Artefakte. Der Vertrag nennt auch Rohlogs und Provideroutputs. RunRetentionSweep behandelt getrennt run_artifacts, run_events und check_results. Außerdem ist „Tombstone war schon im Backup“ der leichte Fall: Ein vor der Löschung erzeugter Snapshot enthält den Tombstone gerade nicht. Der vorhandene Schutz über persistierte Ablaufzeiten hilft bei inzwischen abgelaufenen Daten; eine davon unabhängige spätere Löschentscheidung ist aus einem alten Snapshot allein nicht rekonstruierbar.

**Kleinste Verbesserung:** Die Garantie zeitlich präzisieren: Welche Löschungen folgen zwingend aus der gespeicherten Frist, welche werden bereits als Tombstone gesichert, und ob spätere vorzeitige Löschungen überhaupt unterstützt werden müssen. Alle tatsächlich vom Blueprint erfassten Rohdatenklassen in eine kompakte Testmatrix aufnehmen. Nicht vorschnell ein externes Löschregister erfinden; einen darüber hinausgehenden Anspruch zuerst als Planentscheidung klären.

**Nachweis:** Backup vor Ablauf, Löschung nach Backup, Restore danach; zusätzlich schon gesicherte Tombstones. Kein erneuter Rohtext in Ansicht, Download, Speicher oder Jobredelivery. Quelle: app/AI6/Runs/RunRetentionSweep.php:54.

### B16 — P2: Sweep-Ergebnis, Active-Run-Frist und Restore-Erfolg nicht vermischen

**Betroffen:** Task 3, AC-05, TC-06.

**Befund:** sweep() liefert auch failed und deferred. Ein einmaliger Aufruf ist deshalb keine Garantie sofortiger physischer Löschung. Bei aktiven Runs ist eine begrenzte Verzögerung vorgesehen; Speicherfehler werden gezählt, ohne den gesamten Sweep abzubrechen. AC-05 verlangt dagegen uneingeschränkt Tombstone und entfernte Storagebytes nach genau einem Restore-Sweep.

**Kleinste Verbesserung:** Den Test ausdrücklich mit terminalen Runs ausführen, wenn Restore nur ruhende Sicherungen unterstützt. Fehlgeschlagene Pflichtbereinigung muss Restore sichtbar unvollständig lassen; der Betrieb startet nicht unbemerkt wieder. Die zentrale Active-Run-Frist und die eine Sweep-Implementierung unverändert konsumieren. Falls aktive Sicherungen später unterstützt werden, deren Frist separat und ehrlich beschreiben.

**Nachweis:** Ein gezielter Löschfehler sowie ein zulässiger Aufschub. Nicht nur Exitcode oder Aufrufanzahl des Sweeps prüfen. Quellen: app/AI6/Runs/RunRetentionSweep.php; app/AI6/Runs/RetentionPolicy.php:87.

### B17 — P2: Den Schutz und die Lebensdauer der Backupkopien selbst dokumentieren

**Betroffen:** Standardziel, AC-02, Out of Scope.

**Befund:** Das Backup enthält weiterhin sensible Anwendungsdaten, verschlüsselte TOTP-Geheimnisse, Sitzungsdaten und noch gültige redigierte Rohoutputs. Die Aussage „keine Schlüssel im Backup“ bedeutet nicht, dass das Backup öffentlich wäre. Die Live-Retention löscht nicht automatisch alle älteren Backupverzeichnisse. Das Standardziel liegt außerdem auf demselben Instanzstorage und bietet allein keinen Schutz gegen dessen Verlust.

**Kleinste Verbesserung:** Datei- und Verzeichnisrechte, den Off-Host-Kopiervorgang durch den Betreiber, einen einfachen Aufbewahrungszeitraum und den Schutz der getrennten Schlüsselablage dokumentieren. Eine Sicherung erscheint erst nach erfolgreichem Abschluss als verwendbar. Backup-Scheduling und Verschlüsselungsdienste bleiben wie geplant außerhalb des Tickets.

**Nachweis:** Unvollständige Sicherung wird nicht als erfolgreich ausgewiesen; Dateien sind nur im vorgesehenen geschützten Verzeichnis lesbar. Die Betriebsanleitung erklärt ausdrücklich die Grenze zwischen Live-Retention und Backupaufbewahrung.

### B18 — P1: Der vorgeschlagene SSH-Schlüssel ist noch kein reiner Tunnelzugang

**Betroffen:** Task 10, AC-10, TC-11, MG-01.

**Befund:** restrict verhindert unter anderem PTY und Weiterleitungen; port-forwarding schaltet Weiterleitungen wieder frei. permitopen begrenzt lokale TCP-Ziele, verbietet aber weder beliebige Remote-Kommandos ohne PTY noch automatisch sämtliche anderen Weiterleitungsarten. Das verkürzte ssh-Kommando enthält zudem keinen Zielhost. Die Einschränkungen folgen aus den offiziellen Beschreibungen zu [authorized_keys](https://man.openbsd.org/sshd.8) und [sshd_config](https://man.openbsd.org/sshd_config.5).

**Kleinste Verbesserung:** Genau ein vollständiges, versionsgeprüftes Rezept für einen dedizierten Tunnelzugang dokumentieren, einschließlich Zielhost und getrenntem lokalen/Zielport. Shell-/Subsystem-Sessions sowie unerwünschte Weiterleitungen müssen tatsächlich gesperrt sein; dafür vorhandene OpenSSH-Konfiguration verwenden. Keine AI6-eigene SSH-Abstraktion.

**Nachweis:** Erlaubter Tunnel funktioniert; Remote-Kommando, SFTP, anderes Weiterleitungsziel und unerwünschtes Remote-Forwarding scheitern. Ein Test, der nur das Vorkommen der Optionszeichenkette in README prüft, genügt nicht.

### B19 — P1: VPN/HTTPS und localhost-Tunnel mit der tatsächlichen WebAuthn-Origin abstimmen

**Betroffen:** Task 10, AC-10, MG-01.

**Befund:** PasskeyRelyingPartyFactory bindet genau eine Origin und RP-ID aus APP_URL. Compose setzt APP_URL fest auf http://localhost mit dem AI6-Port. Ein vorgeschalteter HTTPS-Hostname und ein localhost-Tunnel sind deshalb nicht automatisch zwei austauschbare Passkey-Zugänge derselben Konfiguration. „Browser vertrauen nur localhost“ ist als allgemeine Begründung ebenfalls falsch: Die [Secure-Contexts-Spezifikation](https://www.w3.org/TR/secure-contexts/#is-origin-trustworthy) behandelt auch Loopback-IP-Adressen als potenziell vertrauenswürdig; AI6 hat darüber hinaus einen konkreten WebAuthn-Vertrag.

**Kleinste Verbesserung:** Einen kanonischen HTTPS-Zugang einschließlich APP_URL, Trusted Hosts und Proxykette vorgeben. Den Tunnel entweder auf dieselbe Origin ausrichten oder als ausdrücklich getrennt konfigurierten Fallback testen. Cookieverhalten, WebAuthn-Origin und Transportverschlüsselung getrennt erklären. Nötige kleine Compose-/Deployänderungen als Scopeentscheidung benennen; keine pauschale Auth-Lockerung.

**Nachweis:** Anmeldung, Enrollment und Step-up über die tatsächlich dokumentierten URLs; auch den Wechsel vom eingerichteten HTTPS-Zugang zum Fallback prüfen. Quelle: app/AI6/Auth/PasskeyRelyingPartyFactory.php:9, docker-compose.yml:143, deploy/Caddyfile.

### B20 — P2: Konfigurationsprüfung, echte Funktionsprüfung und sichere Diagnose klar benennen

**Betroffen:** Tasks 4–7, AC-06 und AC-07, TC-07 bis TC-10.

**Befund:** Ein konfigurierter SMTP-Host beweist keine zustellbare Login-Mail. Eine registrierte Scheduleraufgabe beweist keinen laufenden Scheduler. Andererseits ist die Forderung „niemals ein gelesener Wert“ zu weit: Profil, Policyhash, Key-ID und Heartbeat-Alter sollen bewusst sichtbar sein. Ferner fordert TC-09 ohne neue Optionen unverändertes Verhalten, obwohl Task 5 neue immer laufende Prüfungen einführt.

**Kleinste Verbesserung:** Statische Prüfung, vorhandene Laufzeitevidenz und manueller Funktionstest eindeutig unterscheiden. Verbotene Secret-/Rohwerte von erlaubten Diagnosewerten abgrenzen. Die E-Mail-Zustellung im vorhandenen Installationsgate mit einer echten Loginbestätigung prüfen. Für jeden tatsächlich vorhandenen Optionszweig genaues Exitverhalten festlegen; vorhandene Prüfungen wiederverwenden und doppelte Ausgabe vermeiden.

**Nachweis:** SMTP-Konfiguration formal korrekt, Zustellung jedoch unmöglich; custom-Profil; fehlende Checker-Evidenz; erlaubte Diagnosen ohne Secretwerte. Keine universelle Netzwerk-Testplattform einführen.

## AI6-037 — Migration des bisherigen Ticket-Prompt-Tools

### M01 — P1: Das aktuelle M169-Fixture kann das geforderte Goal nicht liefern

**Betroffen:** Task 1, AC-01, TC-01.

**Befund:** tests/Fixtures/Tickets/legacy-m169.md enthält nur id, title, status, owner, tags und metadata. Ein goal fehlt. GenericV1TicketValidator verlangt jedoch einen nicht leeren Goal-Abschnitt. Das Ticket fordert trotzdem eine verlustfreie, gültige Migration dieses Fixtures. Ein aus dem Titel erfundenes Ziel würde die fehlende Fachentscheidung kaschieren.

**Kleinste Verbesserung:** Das bestehende Fixture als Negativfall für fehlendes Ziel erhalten. Für den Positivfall ein zusätzliches, fachlich bestätigtes Legacy-Beispiel mit Ziel verwenden; idealerweise eine freigegebene, bereinigte Fassung des echten M169. Fehlende Pflichtinformationen werden vor dem Apply vom Menschen ergänzt. Kein automatischer LLM-Reparaturschritt.

**Nachweis:** Ohne Ziel benannte Ablehnung ohne Schreibwirkung; mit echtem Ziel semantisch gleichwertiger Golden-Diff. Quellen: tests/Fixtures/Tickets/legacy-m169.md; app/AI6/Tickets/GenericV1TicketValidator.php:55.

### M02 — P1: Die Detailprofil-Migration muss alle erforderlichen Abschnitte erhalten können

**Betroffen:** Tasks 1–2 und 7, AC-05, TC-04.

**Befund:** Task 1 erzeugt Goal, Tasks, Acceptance Criteria, Test Cases und gegebenenfalls files; Task 2 ergänzt Notes. Ai6DetailV1TicketValidator verlangt zusätzlich Context, AC Coverage, Initial Scope and Sensitive Paths, Do Not Change, Out of Scope, Manual and External Gates und Review Focus. Die behauptete gültige „vollständige“ Legacy-Datei in TC-04 enthält diese Informationen laut Beschreibung nicht. Platzhalter wie None. würden fehlende fachliche Aussagen nicht nachträglich beweisen.

**Kleinste Verbesserung:** Eine kleine Feld-/Abschnittstabelle mit den tatsächlich unterstützten Eingaben und Datentypen festlegen. Vollständige bestehende Inhalte übernehmen; fehlende Inhalte mit den vorhandenen Validierungsfehlern ablehnen. Für den Detail-Positivfall muss die Quelle alle fachlich erforderlichen Angaben enthalten. Kein zweiter Validator und kein Auffüllen mit erfundenen Gates oder Coveragebeziehungen.

**Nachweis:** Ein wirklich vollständiger Detailfall sowie je ein fehlender Coverage- und Gateabschnitt. Quelle: app/AI6/Tickets/Ai6DetailV1TicketValidator.php:7.

### M03 — P1: files und spec_refs dürfen im generischen Profil nicht ihre Wirksamkeit verlieren

**Betroffen:** Tasks 1–2, AC-05 und AC-06.

**Befund:** Task 1 schreibt files und spec_refs nur „im Detailprofil zusätzlich“ ins Frontmatter. Task 2 verlangt normalisierte spec_refs allgemein; der Blueprint verlangt die Übernahme von files. Der generische Validator erlaubt beide Felder ausdrücklich. Eine Ablage nur in Prosa/Notes erhält zwar lesbaren Text, aber nicht den maschinenlesbaren Scope oder Referenzvertrag.

**Kleinste Verbesserung:** Vorhandene gültige gemeinsame Frontmatterfelder in beiden Profilen übernehmen. Profilwahl bestimmt zusätzliche Pflichten, nicht den Verlust vorhandener zulässiger Angaben. Einen fehlenden Wert nicht mit einem fachlich stärkeren Standard erfinden. Das gilt sinngemäß auch für bereits vorhandene gültige kind-/risk-/milestone-Werte.

**Nachweis:** Generisches Legacy-Ticket mit files, spec_refs und depends_on migrieren; Werte nach Parser und Contract-Hash-Bildung ausdrücklich vergleichen. Quelle: app/AI6/Tickets/TicketV1Parser.php:10 und GenericV1TicketValidator.php.

### M04 — P1: Kriterien, Tests, Gates und ihre IDs semantisch erhalten

**Betroffen:** Task 1, AC-01 und AC-06, TC-01 und TC-04.

**Befund:** „AC-xx-Zeilen“ und „TC-xx-Zeilen“ sagen nicht, welche Legacyform erwartet wird: einzelne Zeichenketten, Listen, mehrzeilige Markdownblöcke oder strukturierte Objekte. Für MG-/EXT-IDs, Coverageverweise, Scopebegründungen und bereits vorhandene Abschnittsstruktur fehlt eine klare Übernahme. Eine automatische Neunummerierung kann externe Reviews entkoppeln; eine Übernahme von Gates nur als Notes ändert ihre Laufzeitwirkung.

**Kleinste Verbesserung:** Die am echten Bestand benötigten wenigen Formen festlegen. Vorhandene IDs und Referenzen erhalten, fehlende oder widersprüchliche Zuordnungen sichtbar ablehnen. Zeilenumbrüche und Codeblöcke dürfen nicht aus Versehen neue strukturelle Abschnitte erzeugen. Eine neue ID-Vergabe nur dort vorsehen, wo sie ausdrücklich fachlich freigegeben ist.

**Nachweis:** Mehrzeiliges AC, ein vorhandenes MG und EXT, lückenhafte aber bestehende IDs sowie eine Coveragebeziehung. Nicht allein prüfen, dass irgendeine AC-Zeile im Ergebnis steht. Quellen: TicketV1Parser.php und Plan §13.7.

### M05 — P2: „Wörtliche“ Erhaltung nicht mit semantischer Erhaltung verwechseln

**Betroffen:** Task 2, AC-01 und AC-06, Review Focus.

**Befund:** LegacyTicketReader liefert dekodierte fields sowie den gesamten YAML-Rohtext. Die Dekodierung enthält keine Quellbereiche je Feld. Kommentare, Quote-Stil und Blockskalardarstellung sind daraus nicht bytegleich rekonstruierbar. Eine neue zeilenbasierte Teil-YAML-Zerlegung würde gerade die verbotene zweite Parsernaht erzeugen.

**Kleinste Verbesserung:** Entscheiden, ob für unbekannte Felder semantischer Werterhalt genügt. Falls exakte Originalbytes benötigt werden, den unveränderten Rohtext als eindeutig abgegrenzte historische Evidenz erhalten oder auf den menschlichen Git-Commit verweisen; nicht einzelne YAML-Fragmente mit einem neuen Parser herausoperieren. Historische Inhalte dürfen keine zweite aktive Aufgaben- oder Statusquelle werden.

**Nachweis:** Unbekanntes verschachteltes Feld, Kommentar, leere Werte, mehrzeiliger String und Markdown-Fence. Die erwartete Form der Erhaltung muss im Test eindeutig sein. Quelle: app/AI6/Tickets/LegacyTicketReader.php:18.

### M06 — P1: Referenzen nicht durch eine pauschale Suche in Prosa umdeuten

**Betroffen:** Task 2, AC-06, TC-04.

**Befund:** Eine Requirement-ID in Prosa ist nicht automatisch eine normative Referenz. Sie kann negiert, historisch oder Teil eines Beispiels sein. Fremde generische Tickets können außerdem zu einem anderen Plan gehören. Die Forderung, alle alten Referenzen auf den AI6-Plan umzubiegen, ist bereits im Blueprint angelegt; sie darf deshalb weder still korrigiert noch blind auf fremde Quellen angewendet werden. Eine korrekt formatierte erfundene ID besteht zudem die syntaktische spec_ref-Prüfung des Detailvalidators.

**Kleinste Verbesserung:** Nur eindeutig identifizierte, fachlich bestätigte Referenzfelder normalisieren; Prosa erhalten. Bekannte AI6-IDs gegen den tatsächlichen Planbestand abgleichen, ohne eine zweite Planparserlogik zu bauen. Für fremde Pläne eine ausdrückliche Planentscheidung einholen: auf den bestätigten M169-Bestand begrenzen oder dessen eigene Referenzsemantik erhalten. Keine heuristische Bedeutungsanalyse.

**Nachweis:** Doppelte bekannte Referenz, unbekannte ID, negierte Erwähnung im Fließtext und fremder Dokumentpfad. Quellen: Blueprint AI6-037, app/AI6/Tickets/Ai6DetailV1TicketValidator.php:39.

### M07 — P1: Den Rollbackvertrag auf eine tatsächlich leistbare Garantie begrenzen

**Betroffen:** Task 6, AC-03, TC-05.

**Befund:** Originalbytes im Arbeitsspeicher garantieren kein Alles-oder-nichts bei Prozessabbruch, voller Platte, verlorenem Schreibrecht oder fehlgeschlagenem Rückschreiben. Wenn das Überschreiben einer Datei ihre Bytes bereits teilweise zerstört hat, ist auch „bereits geschriebene Dateien zurückschreiben“ zu ungenau. Gleichzeitige menschliche Änderungen können durch diesen Rollback zusätzlich verloren gehen.

**Kleinste Verbesserung:** Alle Konvertierungen vorab validieren; Dateien über vorbereitete temporäre Dateien ersetzen; vor dem Ersetzen den unveränderten Quellstand prüfen. Die Beschränkung auf einen ruhenden, gesicherten lokalen Checkout dokumentieren. Bei einem nicht vollständig rücknehmbaren Fehler exakt melden, welche Pfade betroffen sind und wie die gesicherte Fassung wiederhergestellt wird. Eine absolute Mehrdatei-Atomarität nur nach ausdrücklicher Entscheidung verlangen.

**Nachweis:** Fehler bei erster und späterer Datei, Fehler beim Rückschreiben sowie zwischenzeitliche Änderung einer Quelldatei. Kein Transaktionsjournal-Framework für eine einmalige Migration bauen.

### M08 — P1: UTF-8- und Redactiongrenze vor Parsing und Bericht übernehmen

**Betroffen:** Tasks 1–5, AC-02 und AC-06, Review Focus.

**Befund:** Das neue Kommando liest untrusted lokale Bytes direkt. RestrictedYaml allein ersetzt nicht die zentrale UTF-8-/Redactiongrenze. Der Bericht soll außerdem Quellstatus, unbekannte Schlüssel und Referenzen ausgeben; auch diese Werte können Steuerzeichen oder Secrets tragen. Eine stille Maskierung der Zieldatei würde wiederum dem Versprechen verlustfreier Migration widersprechen.

**Kleinste Verbesserung:** Vor Parsing/Hashing die vorhandene UTF-8-Grenze verwenden; Berichtswerte sicher redigieren beziehungsweise als geschlossene Steuerwerte validieren. Vorab festlegen, wie ein Secretfund behandelt wird: Für den einfachen sicheren Weg Apply benannt verweigern und menschliche Bereinigung verlangen. Keine Rohdaten in Fehlerausgaben und keine zweite Redactionlogik.

**Nachweis:** Ungültiges UTF-8, Secret in unbekanntem Feld und Zeilen-/Terminalsteuerzeichen in einem Diagnosewert. Quelle für die vorhandene Reihenfolge: app/AI6/Tickets/TicketInventory.php:49.

### M09 — P2: Kandidaten, ungültige Tickets und harmlose andere Dateien unterscheiden

**Betroffen:** Tasks 4–5, AC-07, TC-06.

**Befund:** Ein <TICKET-ID>.md mit kaputtem YAML ist kein harmloser Index. Umgekehrt ist nicht jede andere Datei automatisch eine „nicht autoritative Ansicht“. Symlinks, ID-/Dateinamensabweichungen, Case-Kollisionen und ungültige vorhandene V1-Dateien sind im Entwurf nicht sauber eingeordnet. Die Ankündigung vollständiger Migration darf solche Kandidaten nicht als ignoriert verschwinden lassen.

**Kleinste Verbesserung:** Wenige klare Klassen verwenden: gültiges V1, migrierbares Legacy, fehlerhafter Ticketkandidat, bekannte Statusansicht und sonstige unangetastete Datei. Fehlklassifizierte oder unlesbare Ticketkandidaten sichtbar und mit Fehlerstatus melden. Die vorhandene Standard-ID-Regel konsumieren. Direkte Kinddateien reichen; keine automatische rekursive Suche über beliebige Repositories.

**Nachweis:** Kaputtes Legacy, ungültiges V1, Symlink, Dateiname/ID-Mismatch, README und gewöhnliche Textdatei. Zusätzlich den Gesamtbestand mit der vorhandenen TicketDependencyGraph-Naht auf fehlende Abhängigkeiten, Selbstbezüge und Zyklen prüfen: einzeln valide Dateien garantieren noch keinen nutzbaren Ticketbestand. Quellen: app/AI6/Tickets/GenericV1TicketValidator.php:9, TicketInventory.php:30 und app/AI6/Tickets/TicketDependencyGraph.php:10.

### M10 — P2: CLI-Optionen, Reihenfolge und Bericht eindeutig machen

**Betroffen:** Tasks 3–6, AC-02 bis AC-04 und AC-07.

**Befund:** Mehrfach widersprüchliches --map-status, ungültiges Zielprofil und Mapping unbekannter Quellstatus sind nicht spezifiziert. Auch „verbleibende Legacy-Kandidaten“ meint im Dry-run etwas anderes als nach Apply. Die Dateiverarbeitungsreihenfolge und der eigentliche Änderungsdiff fehlen, obwohl der menschliche Review nicht nur Metadaten, sondern den migrierten Inhalt bewerten muss.

**Kleinste Verbesserung:** Ungültige/widersprüchliche Optionen vor jedem Lesen oder Schreiben verweigern; Dateien deterministisch sortieren. Bestandszahl und Zahl nach erfolgreichem Apply getrennt ausweisen. Einen begrenzten, sicher dargestellten Inhaltsdiff oder eine gleichwertige Prüfmöglichkeit bereitstellen. Bei unverändertem gültigem V1 einen klaren No-op melden. Ein Textbericht genügt; kein zusätzlicher Reportdienst.

**Nachweis:** Doppelte Statuszuordnung, ungültiges Profil, leerer Ordner, gemischter Bestand und zweiter identischer Lauf. Quellstatus bleibt bei einer verweigerten Zuordnung sichtbar, Zielstatus ist ausdrücklich nicht gesetzt.

### M11 — P2: Profilwahl an der Projektpolicy erklären, nicht als Eigenschaft einzelner Dateien

**Betroffen:** Goal, Task 7, AC-05; zusätzlich AI6-038/TC-08.

**Befund:** Im Dateiformat entsteht kein besonderes „generic_v1-Dokument“ oder eingebettetes Profil. Es entsteht ai6.ticket.v1, das unter einem gewählten serverseitigen Profil geprüft wird. Das Pilotprojekt erhält sein Profil aus freigegebener Projektkonfiguration. Eine Migration mit generic_v1 garantiert daher nicht die spätere Freigabefähigkeit eines Projekts mit ai6_detail_v1.

**Kleinste Verbesserung:** Die Formulierungen auf „unter Profil X validiert“ vereinheitlichen. Migration und spätere Projektkonfiguration müssen dieselbe beabsichtigte Mindeststrenge besitzen. Für Tests unterschiedlicher Profile getrennte Projektkonfigurationen verwenden; keine implizite Profilwahl je Ticketinhalt und kein neues Frontmatterfeld.

**Nachweis:** Dasselbe gültige generische Dokument besteht generic_v1 und scheitert bei fehlendem Detailinhalt unter ai6_detail_v1; der spätere Panelzustand entspricht der freigegebenen Policy. Quellen: TKT-011, TicketReadModelProjector.php:17.

### M12 — P2: Promptzuordnung als fachliche Zuordnung prüfen und die vorhandene UI schlicht erweitern

**Betroffen:** Task 8, AC-08, TC-07, Notes.

**Befund:** Die drei alten Implementierungsprompts sind nicht automatisch inhaltsgleich mit dem knappen zentralen implementation-Eintrag. Eine Zuordnungstabelle kann den Wechsel erklären, beweist aber keine verlustfreie Textübernahme. Die Katalogversion beeinflusst außerdem Prompt-Snapshots; „es gibt vor dem Pilot keine realen Läufe“ ist keine dauerhafte Upgradegarantie. Positiv: Eine dritte statische Karte passt tatsächlich zur bestehenden Seite mit zwei statischen und einer dynamischen Karte.

**Kleinste Verbesserung:** Pro Altprompt benennen, ob sein Inhalt bereits übernommen, nur sein Anwendungsfall abgelöst oder bewusst nicht übernommen wurde. Nur manual_review ergänzen; bestehende Run-Promptbytes unverändert prüfen. Vorhandene Kopieraktion und Renderer verwenden. Die erwartete Snapshotinvalidierung dokumentieren, ohne jetzt die globale Versionierung umzubauen.

**Nachweis:** Reale Route als berechtigter Benutzer rendern und die neue Karte einmal über den bestehenden Clipboardpfad prüfen; vorhandene Katalogeinträge bytegleich halten. Quellen: app/AI6/Prompts/PromptCatalog.php:33, PromptRenderer.php:94, Livewire/PromptHelp.php, resources/views/prompts/help.blade.php.

### M13 — P1: Den echten M169-Nachweis nicht durch ein selbst erzeugtes Golden-Fixture ersetzen

**Betroffen:** Context, AC-01, TC-01, Manual and External Gates: None.

**Befund:** Das reale M169 liegt nicht hier. Der Entwurf erklärt zusätzliche Legacyfeldnamen selbst zur Festlegung und verschiebt deren Bestätigung in AI6-038. Ein Golden-Fixture, dessen Eingabe und Ausgabe beide vom Implementierer erfunden werden, beweist Determinismus, aber nicht die vom Blueprint zugesagte semantische Gleichwertigkeit von M169. Ein unbekanntes Feld in Notes kann als historische Information erhalten sein und trotzdem als aktive Anforderung fehlen.

**Kleinste Verbesserung:** Vor der fachlichen Fertigmeldung eine vom Menschen bestätigte, bereinigte repräsentative Eingabe verlangen oder den noch offenen M169-Nachweis ausdrücklich als externes/manuelles Gate ausweisen. Im Pilot darf nicht erst entdeckt werden, dass der grundlegende Eingabevertrag unbekannt ist. README-/docs-Statusindizes über eine begrenzte, dokumentierte Bestandsprüfung erfassen; keine semantische Repo-Weit-Suche nach beliebigen Statuswörtern.

**Nachweis:** Menschlich überprüfter Vorher-/Nachher-Diff mit Ziel, Aufgaben, Kriterien, Tests, Scope, Gates und Referenzen. Der technische Golden-Test ergänzt diese Bestätigung, ersetzt sie aber nicht.

## AI6-038 — Realer M169-Pilot und MVP-Abnahme

### P01 — P1: Der reale Securityreview ist eine zusätzliche fehlende Pilotvoraussetzung

**Betroffen:** Context „Zwei Voraussetzungen“, Task 4, AC-05, TC-04, MG-03.

**Befund:** SecurityReviewStep verweigert jeden Adapter außer fake ausdrücklich mit security_adapter_not_available. Das ist stärker als ein noch nicht konfiguriertes Profil. Die realen Standardprofile tragen zudem keine security_review-Rolle; ai6.agent_security_review_profile ist standardmäßig fake. Ein grüner Doctor, der nur diese Profilrolle prüft, könnte folglich einen Fake-Securityreview als vermeintlich produktionsbereite LLM-Kontrolle durchlassen.

**Kleinste Verbesserung:** Diesen dritten Blocker in die Voraussetzungen aufnehmen und den benötigten realen Securityreview-Vertrag zuerst menschlich klären. Eine reale Providerintegration beziehungsweise Nachlieferung darf weder heimlich in den Pilot gezogen noch durch einen Fake als bestanden dargestellt werden. Ein möglicher manueller Override ist eine autorisierte Ausnahme am Candidate; er ist kein Nachweis, dass der geforderte reale Securityreview stattgefunden hat.

**Nachweis:** Vor Pilotstart die vollständige Kette Profilauflösung → realer Adapter → SecurityReviewStep auf dem Candidate prüfen. Quellen: app/AI6/Reviews/SecurityReviewStep.php:106; config/ai6.php:36 und :272; app/AI6/Agents/CodexCliAdapter.php:99, GrokCliAdapter.php:129 und GitHubCopilotCliAdapter.php:99.

### P02 — P1: Für jeden echten Startblocker einen benannten Entsperrungspunkt festhalten

**Betroffen:** Context, Task 1, TC-01 und TC-02, Notes.

**Befund:** Das Ticket hält Release-Lücken und Grok-Blocker ehrlich offen, benennt aber keinen ausführbaren Weg bis zum verlangten grünen Start. Die frühere Annahme, AI6-046 schließe AI6-032/AC-04, gilt nicht mehr: Plan V1.7.6 und das aktuelle AI6-046 halten den menschlichen Nachweisverzicht fest und schließen ausdrücklich nicht das Release-Gate. Ein Rebase beseitigt diese inhaltliche Lücke nicht von selbst.

**Kleinste Verbesserung:** Eine kurze Voraussetzungstabelle im Pilotprotokoll: Blocker, zuständige menschliche Entscheidung oder bereits vorhandener Folgeauftrag, erforderliche Evidenz, gebundener Stand. Release-Lücken, Grok-Laufzeit, realen Securityreview und relevante offene Installations-/Provider-Gates vollständig aufführen. Die Ausnahme für AI6-046 unverändert respektieren; keine dort aufgehobene Pflicht nachträglich als offen markieren.

**Nachweis:** Jede Voraussetzung hat vor dem ersten realen Turn aktuelle Evidenz. Offener Punkt bedeutet Pilot noch nicht freigegeben. Quellen: Plan §12.2 und §18; tickets/AI6-046.md:39; FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS.

### P03 — P1: Die erlaubten Git-Ref-Änderungen korrekt unterscheiden

**Betroffen:** Tasks 3–4 und 6, AC-06 und AC-09, TC-03 und TC-04.

**Befund:** TC-04 fordert, dass außer dem Testbranch alle Remote-Refs unverändert bleiben. PublishCompletionService veröffentlicht jedoch den Runbranch und startet danach die Ticketstatus-Synchronisierung auf dem Control-Branch. Auch der Review-only-Claim und -Abschluss sind Git-native Statusänderungen. „Ohne Push“ im Review-only-Modus meint daher keinen Code-/Runbranch-Publish; ein absolutes Verbot jeder Gitübertragung würde dem bestehenden Statusvertrag widersprechen.

**Kleinste Verbesserung:** Im Protokoll eine feste Ref-Liste mit Zweck und erwarteter Wirkung führen: Run-/Testbranch für Candidatecode, Control-Branch für autorisierte Ticketstatus- und Recorded-Scope-Änderungen, übrige Refs unverändert. Die zulässigen Status-CAS-Effekte vom Codepublish unterscheiden. Einen bestehenden beliebigen Zielbranch nicht als frei auswählbar voraussetzen; den tatsächlich von AI6 gebundenen Runbranch verwenden.

**Nachweis:** Vorher-/Nachher-OIDs beider autorisierter Refs sowie der übrigen Refs vergleichen. Quellen: app/AI6/Runs/PublishCompletionService.php:307 und :354; ReportOnlyCompletionService.php:192; app/AI6/Git/TicketMutationExecutor.php:608.

### P04 — P1: Den Legacy-Cutoff erst nach seiner Voraussetzung ausliefern

**Betroffen:** Tasks 7–9, AC-07, AC-10 und AC-11, MG-03.

**Befund:** Ein Implementierer könnte den bedingungslosen Cutoff gleich mit dem noch leeren Pilotprotokoll integrieren. Der Plan erlaubt ihn erst nach erfolgreichem Pilot. Zugleich verlangt das finale MG-03-Protokoll bereits den Cutoffnachweis; wird genau dieses finale Gate als Voraussetzung des Cutoffs verstanden, entsteht ein Kreis. Ein einziges unpräzises Commitfeld genügt nicht für Code vor und nach dem Cutoff.

**Kleinste Verbesserung:** Zwei zeitliche Abschnitte innerhalb des einen Pilotauftrags festlegen: Pilot auf gebundenem Kandidaten durchführen und den Erfolg menschlich dokumentieren; anschließend Cutoff in derselben Release-Lineage integrieren und Bestandsvalidierung ergänzen. Das Protokoll bindet beide Stände und die jeweilige Entscheidung. Keine neue dauerhafte Legacy-Featureflag und kein automatischer Signaturmechanismus.

**Nachweis:** Vor der menschlichen Pilotbestätigung kein ausgerollter Cutoff; danach reguläre Ablehnung des Legacyformats und erneute Validierung. Ergebnisfreie Vorlage und später unterschriebene Evidenz klar trennen.

### P05 — P1: Die Restoreprobe darf Pilotbelege und Gitbindungen nicht zurücksetzen

**Betroffen:** Tasks 2 und 6, AC-11 und AC-13, TC-06, MG-03.

**Befund:** Das Vor-Pilot-Backup wird nach dem Publish zurückgespielt. Dadurch verschwinden die danach erzeugten Run-/Approval-/Gate-Datensätze aus der Datenbank, während der Remote den Publish behält. Bei unmittelbar anschließendem Neustart kann die Instanz auf einen anderen Gitstand treffen. Wird auch das Protokoll nur in der wiederhergestellten Umgebung verwahrt, kann die eigene Abnahmeevidenz verloren gehen. Ein Backup vor Projektregistrierung beweist außerdem keine Wiederherstellung des Pilotprojekts.

**Kleinste Verbesserung:** Den Backupzeitpunkt und den beabsichtigten Restoreumfang genau nennen. Die Probe in einer getrennten Wiederherstellungsinstanz mit eigenen Volumes durchführen; bestehende Schlüssel/Volumes dürfen fehlende Sicherungsbestandteile nicht verdecken. Vorher notwendige redigierte Pilotbelege außerhalb des Rücksetzbereichs sichern. Wiederhergestellte Worker erst nach der Prüfung der Remote-Bindungen starten.

**Nachweis:** Die vollständige Pilotbeweiskette bleibt lesbar, die Originalinstanz unverändert, und die Restoreprobe prüft tatsächlich die in B07–B16 zugesagten Daten.

### P06 — P2: Reale LLM-Ausgaben nicht auf zufällig notwendige Fixturns festlegen

**Betroffen:** Task 4, TC-04, AC-03 und AC-12.

**Befund:** Ein realer Implementierungslauf erzeugt nicht garantiert ein Finding, das eine unabhängige Verifikation und einen Fixturn auslöst. Ebenso erzeugt der Agent nicht garantiert eine Rückfrage. Wenn der Test diese Ereignisse zwingend erwartet, scheitert ein fachlich guter Lauf oder verleitet zur nachträglichen Datenmanipulation. Wiederholte kostenpflichtige Runs bis zum gewünschten Zufall sind ebenfalls kein guter Nachweis.

**Kleinste Verbesserung:** Vorab einen ehrlichen fachlichen Pilotfall mit bekanntem Änderungsbedarf und einem definierten Interventionsanlass wählen. Fehlt eine verlangte reale Phase, sie ausdrücklich als nicht beobachtet behandeln und einen separat autorisierten, begrenzten Ergänzungslauf planen. Deterministische Fehler- und Wiederholungszweige bleiben Aufgabe der Fake-/Integrationstests; fehlende reale Phasen werden nicht als passiert eingetragen.

**Nachweis:** Pro Phase Auslöser, tatsächlicher Run/Slot und Ergebnis. Keine absichtlich eingebauten Produktfehler, keine gefälschten Findings und kein direkter DB-Eingriff, nur um den Ablauf zu erzwingen.

### P07 — P2: Reviewer-, Verifier- und Securityslots vollständig binden

**Betroffen:** Tasks 3–4, AC-02 und AC-03, TC-02 und TC-04.

**Befund:** Die Namen der zwei Reviewer allein legen noch nicht den quellenabhängigen Verifierpool fest. Im Standardprofil ist Grok als Verifier vorgesehen; sein eigenes Finding darf es nicht verifizieren. Der Copilot-Adapter unterstützt die technische Verifierrolle, das genannte Standardprofil deklariert sie jedoch nicht. Eine zweite Copilot-Modellvariante erzeugt keine zusätzliche Providerunabhängigkeit. Dazu kommt der fehlende echte Securityslot aus P01.

**Kleinste Verbesserung:** Eine kompakte, aus dem aktuellen Code abgeleitete Slot-Tabelle ins Protokoll aufnehmen: Rolle, Profil, Provideralias, Modell, Effort, Promptprofil und zulässige Verifierquelle. Wenn für ein Finding kein unabhängiger Verifier vorhanden ist, den vorgesehenen menschlichen Weg dokumentieren. Keine neuen Provider oder Routingalgorithmen allein für eine symmetrische Tabelle hinzufügen.

**Nachweis:** Ein Finding aus jedem tatsächlich verwendeten Quellprofil wird dem erlaubten Pfad zugeordnet; Originalblocker bleiben bis zur autorisierten Disposition wirksam. Quellen: config/ai6.php:272; app/AI6/Reviews/VerifierSlotSelector.php:13 und VerifierCandidatePoolFactory.php:14.

### P08 — P2: Konfiguration und beobachtetes Modell sauber auseinanderhalten

**Betroffen:** AC-02, TC-02, Protokollbindung.

**Befund:** Ein Eintrag in run_agents beweist zunächst die serverseitige Auswahl. Er beweist allein nicht, welches Modell der Provider tatsächlich ausgeführt hat. Außerdem heißt das Codex-Profil codex-gpt-5.6-terra, bindet im aktuellen Code aber gpt-5.3-codex. Grok verwendet provider_default; daraus darf kein konkreter, unbekannter Modellname oder Aufwand erfunden werden.

**Kleinste Verbesserung:** Auswahl aus Approval, gebundene Übergabe an den Adapter und vom Provider tatsächlich gemeldete Metadaten getrennt protokollieren. Versions-/Capabilitybericht und vorhandene Metadaten nutzen. Bei provider_default oder fehlender Providerangabe die Unsicherheit ausdrücklich bewahren. Einen vollständigen Rohtranskript-Export dafür nicht verlangen.

**Nachweis:** Abgleich der autorisierten Auswahl mit dem wirklichen Aufruf und den verfügbaren Rückgabemetadaten. Profile nach einem Rebase neu aus dem Code übernehmen; den historischen Anzeigenamen nicht als Modellbeweis werten. Quelle: config/ai6.php:263.

### P09 — P1: Den tatsächlichen Bestandsneuaufbau statt einer wirkungslosen Reprojektion prüfen

**Betroffen:** Task 8, AC-10, TC-08.

**Befund:** reproject-unparsed --project-config überspringt Datensätze, deren Profil- und Configbindung bereits aktuell ist. Eine Codeänderung am Legacy-Leser allein macht diese Bindungen nicht veraltet. Das Kommando kann deshalb einen alten legacy_format-Befund unverändert lassen. Der Entwurf nennt zwar zusätzlich ticket_refresh, aber keinen konkreten Bedienpfad, vollständigen Kandidatenbestand oder Nachweis, dass jeder Refresh wirklich abgeschlossen wurde. ticket_refresh ist ein Operationstyp, kein eigenständiges Shellkommando.

**Kleinste Verbesserung:** Den vorhandenen Refreshpfad über seine tatsächliche Bedienung ausführen, auf Abschluss warten und danach die vollständige Liste der Ticketkandidaten am gebundenen Commit mit den Projektionen vergleichen. --project-config nur dort einsetzen, wo tatsächlich eine Profil-/Configabweichung zu korrigieren ist. Für den kleinen Pilotbestand reicht eine nachvollziehbare Liste; kein neuer generischer Reindexdienst.

**Nachweis:** Ein Legacy-Read-Model mit schon aktueller Configbindung wechselt nach dem echten Refresh zu legacy_format_unsupported. Gemischte Profiltests verwenden getrennte Projektkonfigurationen. Quellen: app/AI6/Tickets/Console/ReprojectUnparsedTicketsCommand.php:57 und :174; app/AI6/Git/TicketReadModelRefresher.php:93.

### P10 — P2: Die Pilotvorbereitung bis zur tatsächlich freigegebenen V1-Datei ausschreiben

**Betroffen:** Task 2, AC-01, TC-03, MG-03.

**Befund:** Task 2 spricht von Migration mit dem Dry-run-Kommando und anschließendem Commit. Ein Dry-run ändert keine Datei. Zudem führt der Review-only-Abschluss wieder zu ready; vor dem anschließenden Implementierungslauf sind der aktuelle Controlstand, die richtige Laufart und ein neues passendes Approval zu binden. „Danach Implementierung“ darf nicht als Wiederverwendung des Review-only-Approvals gelesen werden.

**Kleinste Verbesserung:** Die kurze Reihenfolge explizit machen: Dry-run, menschlicher Inhaltsreview, Apply, Prüfung des Diffs, menschlicher Commit/Push, Fetch/Refresh im Panel, Prüfung des gültigen Tickets und eigenes Approval für jede Laufart. Projekt-/Instruction-Snapshot und tatsächlich benötigte Gate-IDs vor dem jeweiligen Start bestätigen.

**Nachweis:** Review-only und Implementation tragen unterschiedliche, jeweils aktuelle Approvals; der Implementierungslauf startet von dem vorgesehenen Stand. Kein manuelles Setzen von ready in der Datenbank und keine Abkürzung über ein altes Approval.

### P11 — P2: Negativnachweise und echte Mobilintervention gezielt zuordnen

**Betroffen:** AC-03 bis AC-05 und AC-12, AC Coverage, TC-01, TC-04 und TC-05.

**Befund:** Ein erfolgreicher End-to-End-Lauf beweist nicht, dass ein offenes oder stale Gate Candidate, Commit und Push verhindert. Ebenso beweist ein final bestandener Review nicht automatisch eine neue vollständige Runde nach einer Codeänderung. Der vorhandene RunObservationMobileBrowserSmokeTest prüft vor allem Darstellung und horizontales Scrollen; die echte Antwort-/Gatewirkung wird erst durch den ausdrücklich manuellen Teil abgedeckt.

**Kleinste Verbesserung:** Für die negativen Verträge die bereits vorhandenen passenden Integrationsnachweise konkret zuordnen und die im Pilot tatsächlich beobachteten Situationen getrennt protokollieren. Auf dem Smartphone die autorisierte Aktion, anschließenden Reload und den unveränderten Einmaleffekt prüfen. Ein vollständiger erfolgreicher Gate-Eintrag ist mehr als ein sichtbarer Button.

**Nachweis:** Mindestens ein offen/stale verweigerter Gatefall sowie ein nach Codeänderung neu gebundener Reviewstand über die jeweils geeignete Testebene. Keine Wiederholung der gesamten Fehler-Matrix mit kostenpflichtigen Providern und kein Ausbau des Mobile-Smokes zu einem neuen E2E-Framework. Quellen: tests/Feature/Runs/PublishCandidateGateTest.php, tests/Feature/Reviews/ReReviewCompletenessTest.php, tests/Feature/Runs/RunObservationMobileBrowserSmokeTest.php:50.

### P12 — P2: Pilotgrenzen, Messwerte und Cleanup mit wenig Zusatzmechanik prüfbar machen

**Betroffen:** Tasks 2–6 und 9, AC-11 und AC-13, MG-03.

**Befund:** Das Protokoll nennt Messwerte, aber keine klaren Laufgrenzen, keine kleine Auswertungsregel und keinen präzisen Cleanup-Zeitpunkt. „Keine Worktrees oder Exporte mehr vorhanden“ kann versehentlich fremde beziehungsweise andere aktive Läufe einschließen. Ein Testbranch allein schützt außerdem den Control-Branch des verwalteten Pilotprojekts nicht vor den legitimen Statusschreibvorgängen aus P03.

**Kleinste Verbesserung:** Eine dedizierte Pilotkopie beziehungsweise ausdrücklich freigegebene Pilot-Remote verwenden. Vorab die vorhandenen Grenzen für Zeit, Runden und Interventionen sowie ein menschliches Kostenbudget setzen. Je Slot wenige Werte erfassen: Ergebnis, bestätigte/verworfen/unklare Findings, Dauer, Providerfehler, gemeldete Nutzung/Kosten oder unknown. Cleanup nach abgeschlossenem Status-CAS und dem vorgesehenen Reconciler auf die konkreten Pilot-Run-IDs beziehen; aufbewahrte Auditdaten von temporären Workspaces unterscheiden.

**Nachweis:** Budget und Stopppunkt sind vor dem Lauf dokumentiert, unbekannte Kosten werden nicht zu null, eigene temporäre Pfade sind entfernt und andere Läufe bleiben unberührt. Quellen: Plan §18–§19; app/AI6/Runs/PublishCompletionService.php:140. Eine Tabelle und die vorhandenen Limits reichen; kein Dashboard, Benchmarksystem oder automatische Routingoptimierung.

## Übergreifende Verbesserungen

### Q01 — P1: Echte Vertragsentscheidungen von gewöhnlicher Präzisierung trennen

**Betroffen:** Alle drei Tickets; insbesondere AI6-036/Do Not Change und AI6-038/ahead-derived.

**Befund:** Mehrere Aufgaben benötigen etwas, das derselbe Entwurf ausschließt: Rollen-/Envänderungen bei unveränderlichem Compose, Recovery für bereits provisionierte Projekte ohne entsprechende Naht, realer Securityreview bei unberührbarem SecurityReviewStep. Solche Konflikte kann ein Implementierer nicht durch besonders gründliches Arbeiten auflösen. Ein Rebase-Gate verschiebt außerdem nur die nach Plan erlaubten Existenz-/Nahtprüfungen, nicht heutige Architekturentscheidungen.

**Kleinste Verbesserung:** Zuerst die tatsächlich erforderlichen menschlichen Entscheidungen sammeln und danach die betroffenen Tasks, ACs, Tests und Scopegrenzen gemeinsam korrigieren. Quellen: Plan §13.3 und §13.6, Template C12/C13/C16. Das nächste LLM soll offene Fragen nicht mit neuen Klassen oder vermeintlichen Defaults auffüllen.

**Entscheidungsbedarf aus dieser Analyse:** Backupumfang und zulässige Runzustände; Key-/Projekt-Recovery; Offline-Umschaltung und garantierter Fehlerzustand; Rollenverdrahtung und Zugangsorigin; echter Securityreview; Umgang mit fremden Referenzplänen; externe M169-Evidenz.

### Q02 — P3: Größe und neue Abstraktionen konsequent begrenzen

**Betroffen:** Besonders AI6-036; ergänzend AI6-037.

**Befund:** AI6-036 umfasst Installation, mehrere Doctorprüfungen, Schlüsselrotation, Backup/Restore, Zugangsrezepte und Disaster Recovery. Das ist erheblich mehr als eine kleine Dokumentationsaufgabe. Plan §13.2 verlangt die Splitprüfung bereits bei der Ableitung. Umgekehrt wäre es unnötig, jede Doctorprüfung oder jedes Kommando in einen eigenen Blueprint zu zerlegen.

**Kleinste Verbesserung:** Zuerst auf den kleinsten tragfähigen Betriebsumfang reduzieren. Wenn danach Betriebsprüfung/Installation und Backup/Restore weiterhin unabhängig umfangreiche Änderungen sind, genau diesen einen Schnitt als Planvorschlag bewerten. Backup und Restore bleiben gemeinsam an derselben technischen Grenze. Die Blueprint-ID wird nicht umgewidmet und keine neue ID eigenmächtig vergeben.

**Abstraktionsgrenze:** Neue DTOs oder Helfer nur bei einem konkreten eigenen Vertrag. Keine Backup-Provider, generische Restore-Sagas, Migrationsplugins, zusätzliche Statusdatenbank, zweite YAML-/Manifestparser, neuer Renderer oder automatische Remediation im Doctor. Neue Klassen werden als neue Artefakte benannt, nicht als vermeintlich vorhandene APIs.

### Q03 — P2: Tests auf Verhalten ausrichten und Nachweise ehrlich abgrenzen

**Betroffen:** Test Cases und AC Coverage aller drei Tickets.

**Befund:** Die Coverage-Tabellen sind formal geschlossen. Das beweist noch nicht die inhaltliche Eignung ihrer Testfälle. Einige vorgeschlagene Tests prüfen nur Zeichenketten, Aufrufanzahlen oder selbst geschaffene Fixtures. Besonders Bootstrap, Docker, Restore, SSH und Gateblockaden brauchen Belege am wirksamen Verbraucher.

**Kleinste Verbesserung:** Vorhandene relevante Tests fortschreiben und gezielte neue Fälle für die bestätigten Lücken hinzufügen. Kein neuer Testharness ohne Bedarf. Dokumentationstests nur für kritische Befehle, Links und Sicherheitsversprechen verwenden; redaktionelle Überschriften und starre Dateizahlen nicht zusätzlich zementieren. Eine Fehlbehauptung darf nicht durch einen Test, der dieselbe Behauptung wiederholt, „bewiesen“ werden.

**Nachweisregeln:** PHP-Featuretests ersetzen keinen frischen Bootstrap; ein Windowslauf ersetzt keine POSIX-/Containerprobe; ein Fake ersetzt keinen echten Provider; eine Vorlage ersetzt keine Signatur. Bei bloßer Ticketüberarbeitung genügt die passende Vertrags-/Strukturprüfung. Die vollständige reguläre Suite bleibt später das Abschlussgate einer Implementierung, nicht eine Schleife nach jedem redaktionellen Fix.

## Empfohlene Reihenfolge für die Überarbeitung

1. **Zuerst die nicht erfüllbaren Zusagen korrigieren:** B01, B03–B06, B07–B11, B18–B19, M01–M03, M06–M08, M13 und P01–P05. Dabei Planentscheidungen von lokalen Ticketpräzisierungen trennen.
2. **Danach Ein-/Ausgabe und Fehlerzustände schließen:** B12–B16, M04–M05, M09–M11, P07–P10. Die einfachste ausdrücklich unterstützte Variante wählen.
3. **Anschließend Testfälle und Protokolle konkretisieren:** B02, B17, B20, M12, P06, P11–P12 und Q03. Passende bestehende Evidenz weiterverwenden.
4. **Zuletzt Umfang und Redaktion straffen:** Q01–Q02; Wiederholungen aus Context, Tasks, ACs und Notes reduzieren, ohne einen Vertrag nur noch in Notes zu verstecken.

Für die unabhängige Prüfung genügt pro Kennung eine Zeile:

| Vorschlag | Urteil | Beleg/Gegenbeleg | Kleinste übernommene Änderung | Betroffene AC/TC/MG | Planentscheidung nötig? |
|---|---|---|---|---|---|
| Kennung | übernehmen / teilweise / verwerfen / Entscheidung offen | konkrete Quelle | Text oder Verweis auf Änderung | vorhandene IDs | ja / nein mit Grund |

Neue Anforderungen nicht allein deshalb aufnehmen, weil sie theoretisch nützlich sind. Zu jedem übernommenen Vorschlag muss erkennbar bleiben, welches konkrete Problem er löst und weshalb die gewählte Lösung für dieses kleine Produkt genügt.

## Tatsächlich ausgeführte Prüfungen und Grenzen dieser Analyse

- Alle drei Detailtickets vollständig gelesen und mit ihren aktuellen M7-Blueprints sowie den relevanten Plan-/Templateverträgen verglichen.
- Bestehende Nähte zu Bootstrap, Doctor, Providerpräsenz, Securityreview, Rollen/Volumes, Retention, Artefaktspeicher, Provisionierung, Ticketparsern, Profilen, Migrationseingang, Promptkatalog, Read-Model-Neuaufbau und Publish-/Report-only-Abschluss im Code geprüft.
- Alle drei Tickets mit dem vorhandenen TicketV1Parser und Ai6DetailV1TicketValidator geprüft: keine Validierungsfehler.
- AC-Coverage auf nicht referenzierte deklarierte TC-/MG-/EXT-IDs geprüft: keine gefunden. Das ist eine Inventur, kein inhaltlicher Testnachweis.
- Frontmatter-files gegen die geordnete Scope-Liste und new/existing gegen den gegenwärtigen Dateibestand geprüft: jeweils übereinstimmend.
- Byteprüfung: UTF-8-Dateien ohne BOM, ohne CR und mit genau einem abschließenden LF.
- Der vorhandene Manifestgenerator lief mit --check und meldete: Ticket manifest is current.
- Externe Sachfragen gezielt anhand der oben verlinkten offiziellen SQLite-, Docker-, OpenSSH- und W3C-Dokumentation gegengeprüft. Der lokale Code bleibt maßgeblich für die tatsächlich ausgelieferte Integration.

Nicht ausgeführt wurden die Implementierung der neuen Kommandos, ein echter Restore, die Docker-/SSH-/Browserabnahme, reale Providerturns oder der M169-Pilot. Diese Fähigkeiten sind teilweise gerade erst Gegenstand der zu prüfenden Tickets. Ebenso wurde kein fremdes Gate geschlossen und keine vollständige reguläre Testsuite behauptet. Das reale M169 lag zur inhaltlichen Prüfung nicht vor; daraus folgende Vorschläge sind ausdrücklich als fehlende Eingabe/Evidenz gekennzeichnet.

Die folgende Bindung ermöglicht dem nächsten Reviewer zu erkennen, ob sich der untersuchte Entwurf inzwischen geändert hat:

| Datei | SHA-256 der geprüften Bytes |
|---|---|
| tickets/AI6-036.md | 0de67d0f8408b721aed1d37310a0cbd9b53a9453a1129de5c4682275c2f97e77 |
| tickets/AI6-037.md | 995b04c4a76f90535eaee1887206d9b5473d0f5a0801716427c58c81badb8049 |
| tickets/AI6-038.md | 11a28ab4acb8b4d4be9c3da7ff24b62369ce8f767f034852351a4521a1592276 |
