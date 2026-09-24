# AI6-051: Verbesserungsvorschläge zur kritischen Zweitprüfung

Prüfstand: 24. September 2026. Gegenstand ist ausschließlich der uncommittete Entwurf `tickets/AI6-051.md`, nicht seine Implementierung. Die Vorschläge sind eine Prüfliste für ein zweites LLM; sie sollen einzeln bestätigt, eingeschränkt, zusammengeführt oder verworfen werden. Sie sind keine zusätzlichen, bereits beschlossenen Anforderungen.

**Gesamturteil:** Das Ticket hat das richtige Ziel und eine brauchbare Grundstruktur. Die durchgängige Unterstützung beider Git-Objektformate ist ausdrücklich im Plan beschlossen. Der größte Verbesserungsbedarf liegt in einigen konkret nachweisbaren, bislang unzureichend benannten Verbrauchern sowie in der Präzisierung von Migration, Sentinelwerten und Nachweisen. Eine neue Plattformarchitektur oder eine generische Hash-Abstraktionsschicht ist dafür nicht erforderlich.

Besonders vor einer Implementierungsfreigabe zu klären sind V01 bis V04: eigene Git-Objektberechnungen, die Instruktionsauflösung, das bestehende Browserformular und der widersprüchliche Null-Sentinel-Vertrag. V05 bis V12 präzisieren die notwendige durchgängige Bindung und ihre Migration. Die weiteren Punkte machen Fehlerbehandlung, Tests und Durchführung konkreter, ohne einen zweiten fachlichen Auftrag hinzuzufügen.

**Bezug und Grenzen der Prüfung**

- Repository-HEAD: `399076a0df8ff98f4d10e0310cd0a1e136a65988`.
- Normative Grundlage: der vorhandene Arbeitsstand des Plans V1.7.10, insbesondere der [Blueprint AI6-051](G:/software_projekte/AI6/docs/AI6_IMPLEMENTATION_PLAN.md:4032), und das [Tickettemplate](G:/software_projekte/AI6/docs/AI6_TICKET_TEMPLATE_V1.md:173).
- SHA-256 der tatsächlich geprüften Ticketdatei: `0c23c2a84aa90ed45c99653ca883fd1c0270ef848f09682a8224f628bc80fa86`.
- SHA-256 des tatsächlich geprüften Plans: `60f0fa53a5648de32caaf58304e8ee8558d1fc5864df7d7a60158273e9ac24a7`.
- Plan, Manifest, Generator, Backlog und mehrere Laufzeitdateien hatten bereits lokale Änderungen. Die Prüfung bezieht sich auf diese gelesenen Arbeitsstände, nicht auf die Behauptung, sie seien bereits Bestandteil des HEAD-Commits.
- Der vorhandene `TicketV1Parser` und `Ai6DetailV1TicketValidator` akzeptieren die Ticketdatei ohne Fehler. Die acht ACs, acht TCs und MG-01 werden erkannt. Alle als `existing` bezeichneten Scopepfade existieren; die beiden als `new` bezeichneten Dateien fehlen erwartungsgemäß. Manifestprüfung und `git diff --check` waren erfolgreich.
- Titel, Zielabsatz, Abhängigkeiten und Requirement-Refs passen zum Blueprint. Die sechs Abhängigkeitstickets tragen `done`. Daraus folgt keine Schließung ihrer manuellen Gates.
- Es wurden keine Implementierungs-, Linux-End-to-End- oder manuellen Abnahmetests für AI6-051 ausgeführt. Die hier belegten Codebefunde beruhen auf Quellprüfung; die vorgeschlagenen neuen Tests sind noch zu erbringende Nachweise. Lokale Werkzeuge: PHP 8.5.5 und Git 2.33.0.windows.1. Ein lokaler Aufruf von `git rev-parse --show-object-format=storage` ergab für dieses Entwicklungsrepository `sha1`; das ist kein Nachweis für die Linux-Produktlaufzeit.
- Dieser Bericht ist die einzige durch diese Analyse hinzugefügte Datei. Ticket, Plan, Status, Gates und Produktcode werden durch die Analyse nicht geändert.

**Prioritäten:** P1 = vor Implementierung klären, weil ein konkreter Verbraucher oder widersprüchlicher Vertrag den Erfolg gefährdet. P2 = notwendige Präzisierung beziehungsweise gezielter Nachweis; häufig genügen wenige zusätzliche Sätze im vorhandenen AC/TC. P3 = Verbesserung von Verständlichkeit oder Arbeitsumfang. „Belegt“ bezeichnet eine am aktuellen Code überprüfte Aussage; „Präzisierung“ eine daraus abgeleitete Empfehlung, nicht einen bereits ausgeführten Fehlernachweis.

1. **V01 — P1, belegt: Die Berechnung von Git-Objekten ausdrücklich in den Auftrag aufnehmen.**

   **Ticketbezug:** Tasks 1 und 3; AC-01, AC-04, AC-05; TC-01, TC-04, TC-05. Der Kontext benennt vor allem Parser und Längenregeln. Tatsächlich erzeugt AI6 auch selbst die erwarteten Git-IDs.

   **Beleg:** [HardenedGitRunner](G:/software_projekte/AI6/app/AI6/Git/HardenedGitRunner.php:850) berechnet den Zielblob mit festem `hash('sha256', 'blob …')`; seine Methode `treeOid()` berechnet auch verschachtelte Trees mit festem SHA-256. Dasselbe Blobverfahren steht in [QueueTicketMutation](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueTicketMutation.php:478), [QueueRunStart](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueRunStart.php:132) und [QueueContractAmendment](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueContractAmendment.php:227). [QueueEligibility](G:/software_projekte/AI6/app/AI6/Runs/QueueEligibility.php:79) berechnet den Blob zum Konsistenzvergleich nochmals mit SHA-256.

   **Auswirkung:** Nach einer bloßen Erweiterung der Regexe würde ein SHA-1-Projekt spätestens beim Vergleich des geplanten Blobs mit dem von Git geschriebenen Objekt scheitern. Auch die Queue könnte ein korrektes SHA-1-Read-Model als inkonsistent zurückweisen.

   **Änderungsvorschlag:** Task 1 ausdrücklich auf „OID-Validierung, Git-Objektberechnung und persistierte Bindungen“ erweitern. Der zentrale Vertrag soll die vorhandenen Git-Blob-/Tree-Berechnungen anhand des servergebundenen Formats bedienen. Reine Inhaltsprüfsummen bleiben unverändert. Die vorhandene Abgrenzung „kein zweites Hashsystem“ sollte klarstellen, dass damit kein Verbot dieser zwingenden Anpassung gemeint ist.

   **Gezielter Nachweis:** Geplanter Blob und geplanter Tree stimmen für beide Formate mit den tatsächlich durch die gehärtete Git-Naht erzeugten Objekten überein; mindestens ein verschachtelter Ticketpfad. Ein regulärer Approval-/Runstart und eine Vertragsänderung dürfen nicht an einem abweichenden erwarteten Blob scheitern. Die bestehende Mutationsteststrecke bietet dafür den Ansatzpunkt in [HardenedGitRunnerTest](G:/software_projekte/AI6/tests/Feature/Git/HardenedGitRunnerTest.php:315).

   **Kleine Lösung:** Vorhandene Berechnungen zentralisieren und parametrisieren. Keine zweite Tree-Implementierung, kein externer Hashdienst und keine Hashstrategie-Registry.

2. **V02 — P1, belegt: Die Instruktionsauflösung als betroffenen Verbraucher berücksichtigen.**

   **Ticketbezug:** `files`, Context, Tasks 1/3/5; AC-05; TC-05. `Agents` fehlt derzeit im erwarteten Anwendungsscope.

   **Beleg:** [InstructionCandidateCollector](G:/software_projekte/AI6/app/AI6/Runs/InstructionCandidateCollector.php:68) übergibt die echte Git-Blob-ID an `InstructionCandidate`. [InstructionSnapshotResolver](G:/software_projekte/AI6/app/AI6/Agents/InstructionSnapshotResolver.php:38) akzeptiert dagegen ausschließlich 40 kleingeschriebene Hexzeichen. [ApprovalSnapshotFactory](G:/software_projekte/AI6/app/AI6/Runs/ApprovalSnapshotFactory.php:109) verwendet Collector und Resolver beim Aufbau des Approvals.

   **Auswirkung:** Ein Ablauf ohne entdeckte Instruktionsdateien übersieht diese Grenze. Ein SHA-256-Projekt mit einer tatsächlich eingesammelten `AGENTS.md` erreicht die vorhandene 40-Zeichen-Ablehnung. Diese Grenze besteht bereits im Ausgangscode; sie ist kein erst durch AI6-051 verursachter Fehler. Für das versprochene durchgängige Ergebnis muss sie dennoch behandelt werden.

   **Änderungsvorschlag:** Den konkreten Resolver und seine Tests in die Scopeabschätzung aufnehmen. Auch hier gilt das tatsächliche Git-Objektformat; `instruction_snapshot_hash` bleibt ein eigenständiger SHA-256-Hash. Ein leeres Kandidatenarray genügt nicht als Nachweis der Instruktionskompatibilität.

   **Gezielter Nachweis:** Für beide Formate eine tatsächlich aus dem Managed-Clone gelesene, zulässige Instruktionsdatei über Collector, Resolver und Approval-Snapshot verarbeiten. Blob-ID, effektive Bytes und Snapshotbindung prüfen. Falsches Format bleibt abgewiesen; bestehende freigegebene Snapshotbytes werden nicht neu geschrieben.

   **Kleine Lösung:** Die bestehende Auflösungsnaht an den zentralen Vertrag anbinden. Keine neue Instruktionssuche, kein zweites Snapshotformat. Beim Zweitreview zusätzlich die bereits permissive 40/64-Prüfung für `expected_blob_sha` in [AgentResultValidator](G:/software_projekte/AI6/app/AI6/Agents/AgentResultValidator.php:422) auf ihre zuständige Grenze prüfen: Parser dürfen Syntax erkennen; die wirkende Verbrauchergrenze muss die Projektbindung durchsetzen.

3. **V03 — P1, belegt: Das vorhandene Review-only-Formular in den Scope aufnehmen.**

   **Ticketbezug:** `files`, AC-04/05, TC-04/05 und Out of Scope. Der Ausschluss neuer UI-Abläufe ist sinnvoll, darf aber die Anpassung vorhandener Eingaben nicht verdecken.

   **Beleg:** [approvals/ticket.blade.php](G:/software_projekte/AI6/resources/views/approvals/ticket.blade.php:49) setzt für Basis-, Quell- und Tree-OID ein Browser-`pattern` mit exakt 64 Zeichen. Direkt daneben ist der ebenfalls 64-stellige Diff-Hash ein anderer Datentyp. `resources/views/` ist im Ticket noch nicht genannt.

   **Auswirkung:** Der Server könnte SHA-1 unterstützen, während das Formular die Eingabe vor dem Absenden blockiert. Ein direkter Feature-Test mit HTTP-POST umgeht diese Browserprüfung.

   **Änderungsvorschlag:** Die konkrete vorhandene View als `existing` ergänzen und die drei OID-Eingaben aus dem zentralen beziehungsweise servergebundenen Formatvertrag ableiten. Den Diff-Hash unverändert lassen. Kein Formatauswahlschalter im Browser.

   **Gezielter Nachweis:** Die gerenderte SHA-1-Eingabe erlaubt 40 Stellen; SHA-256 erlaubt 64; der Diff-Hash verlangt weiterhin 64. Dazu ein erfolgreicher Antrag über die registrierte Route mit den regulären Rollen- und Step-up-Bindungen. [ReviewOnlyApprovalUiTest](G:/software_projekte/AI6/tests/Feature/Runs/ReviewOnlyApprovalUiTest.php:65) ist ein vorhandener Ansatzpunkt, ersetzt allein aber keine Browser-Constraint-Prüfung.

   **Kleine Lösung:** Bestehende Felder korrigieren. Kein UI-Umbau, kein neuer Browser-Testapparat allein für dieses Ticket; vorhandene Möglichkeiten zum Render- und Formularnachweis nutzen.

4. **V04 — P1, belegt: Den Null-Sentinel-Vertrag korrigieren; die Diff-Ausnahme ist zu eng.**

   **Ticketbezug:** AC-01 und TC-01 erlauben Null-Sentinels derzeit ausschließlich an Git-Diff-Positionen. Das passt nicht zu mehreren vorhandenen Protokollzuständen.

   **Beleg:** [HardenedGitRunner::updateRef](G:/software_projekte/AI6/app/AI6/Git/HardenedGitRunner.php:799) und `createAttemptRef()` verwenden eine Null-OID für die Erwartung „Ref existiert noch nicht“. [PublishCompletionService](G:/software_projekte/AI6/app/AI6/Runs/PublishCompletionService.php:47) repräsentiert einen fehlenden Remote-Runbranch ebenfalls mit einer Null-OID. [QueueTicketMutation](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueTicketMutation.php:523) reserviert eine noch nicht bestimmte Ziel-Tree-OID mit 64 Nullen; [TicketMutationExecutor](G:/software_projekte/AI6/app/AI6/Git/TicketMutationExecutor.php:529) ersetzt diesen Wert per CAS. Die veröffentlichte [Approvalmigration](G:/software_projekte/AI6/database/migrations/2026_08_14_000000_add_ticket_approval_contract.php:171) schützt genau diesen einmaligen Übergang.

   **Auswirkung:** Eine wörtliche Umsetzung des ACs würde legitime Ref-Erzeugung und vorbereitete Mutationen ablehnen. Eine pauschale Zulassung aller Null-OIDs würde umgekehrt echte Objektidentitäten verwässern. Eine Migration könnte gültige vorbereitete Altzustände fälschlich als beschädigt einstufen.

   **Änderungsvorschlag:** Drei Fälle benennen: echte Objektidentität; Git-Protokollsentinel für fehlende Objekte/Refs; bestehender interner Platzhalter für eine noch nicht berechnete Bindung. Zulässige Stellen und Übergänge ausdrücklich auflisten. Der reale Identitätsvalidator bleibt strikt. Für neue SHA-1-Abläufe die Sentinelbehandlung bewusst festlegen, statt die heutige 64 blind zu übernehmen.

   **Gezielter Nachweis:** Erstmalige Ref-Erzeugung und erstmaliger Push in beiden Formaten; vorbereitete Ticketmutation bis zum einmaligen Ersetzen des Platzhalters; Ablehnung von Nullwerten als Commit/Tree/Blob-Identität; Upgrade eines gültigen vorbereiteten SHA-256-Altdatensatzes.

   **Kleine Lösung:** Vorhandene Protokollsemantik erhalten und kontextgebunden prüfen. Nicht sämtliche Platzhalter nachträglich in `NULL` umschreiben und keine universelle Option „beliebige Null-OIDs zulassen“ an alle Validatoren hängen.

5. **V05 — P1, Präzisierung: Autorität, Unveränderlichkeit und Lebensdauer der Formatbindung festlegen.**

   **Ticketbezug:** Tasks 2/3, AC-02/03/05. „Projekt beziehungsweise unveränderliche Operations-/Runbindung“ lässt noch offen, welche Stelle tatsächlich entscheidet.

   **Beleg:** [ManagedCloneSynchronizer](G:/software_projekte/AI6/app/AI6/Git/ManagedCloneSynchronizer.php:113) persistiert bereits vor dem Prozessstart einen zielgebundenen Intent. Die Projektbindung wird erst später in `finalizeBinding()` geschrieben. [QueueManagedCloneOperation](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueManagedCloneOperation.php:72) erstellt zuvor einen Auftrag aus serverseitigen Projektdaten.

   **Änderungsvorschlag:** In wenigen Sätzen festlegen: Vor dem ersten bestätigten Clone ist das Projektformat ungebunden; der Probe liefert einen versuchsgebundenen vorläufigen Wert; der Worker bestätigt ihn am Repository; die endgültige Projektbindung entsteht gemeinsam mit der Control-Bindung unter dem vorhandenen CAS. Ein bestätigtes Format wird weder aus späteren Eingabe-OIDs erneut gewählt noch bei Branchwechsel oder Retry zurückgesetzt. Browser, Ticket und Provider können es nicht setzen. An welchem Ort ein historischer Auftrag das Format bezieht, muss eindeutig und unveränderlich sein.

   **Gezielter Nachweis:** Zwei Projekte mit unterschiedlichen Formaten in demselben Prozess nacheinander bearbeiten, einschließlich Wiederholung. Außerdem eine fremde Bindung aus einem anderen Projekt desselben Formats ablehnen. Unterschiedliche Länge allein ist kein Projektprovenienznachweis.

   **Kleine Lösung:** Eine autoritative Projektbindung und nur die für bestehende Intent-/Recoveryverträge notwendigen zusätzlichen Bindungen. Nicht vorsorglich in jede Tabelle und jedes JSON-Dokument eine weitere Formatkopie aufnehmen. Kein globales „aktuelles Objektformat“ und kein pfadbasierter Cache, der einen später ersetzten Clone ungeprüft wiedererkennt.

6. **V06 — P2, Präzisierung: Das tatsächlich zu bestätigende Repositoryformat und den Prüfzeitpunkt benennen.**

   **Ticketbezug:** Task 2, AC-02, TC-02. „Tatsächliches Repositoryformat“ sollte den Speicheralgorithmus des konkret gestagten beziehungsweise verwalteten Repositorys meinen.

   **Beleg:** [stagedEffectMatches](G:/software_projekte/AI6/app/AI6/Git/ManagedCloneSynchronizer.php:757) prüft heute den aufgelösten Ref und die erlaubten Refs. Diese Stelle ist ein konkreter Anschluss für den zusätzlichen Formatnachweis. Git dokumentiert `rev-parse --show-object-format=storage` für das Speicherformat; `input` kann mehrere Algorithmen nennen. [Git-Dokumentation](https://git-scm.com/docs/git-rev-parse).

   **Änderungsvorschlag:** Den Nachweis worker-seitig über die vorhandene gehärtete Prozessnaht ausführen, nach Clone vor Veröffentlichung und bei Wiederaufnahme vor Übernahme eines vorhandenen Effekts. Ausgabe und unterstützte Werte geschlossen validieren. Kein Rückfall auf SHA-256 oder eine erneut aus Fremdeingaben abgeleitete Länge bei fehlgeschlagener Formatermittlung. Die verwendete Git-Option einmal mit der tatsächlich ausgelieferten Linux-Version prüfen.

   **Gezielter Nachweis:** Probe und Repository stimmen überein; abweichendes Repository oder unbrauchbare Probeausgabe verhindern die Übernahme. Ein Wiederaufnahmefall muss diese Prüfung ebenfalls erreichen.

   **Kleine Lösung:** Eine kleine zusätzliche Lesemethode in der bestehenden Git-Naht. Keine Protokollanalyse des Git-Wireformats, kein Konverter zwischen kompatiblen Objektformaten und kein allgemeines Capability-Subsystem. Eine zusätzliche Unterstützung von Übersetzungs-/Kompatibilitätsformaten ist aus diesem Auftrag nicht abzuleiten.

7. **V07 — P1, belegt: Für die Migration eine semantische Feldliste statt einer allgemeinen 64-Zeichen-Suche verlangen.**

   **Ticketbezug:** Tasks 1/4, AC-03/05, TC-03. Die drei im Kontext genannten Migrationen sind Beispiele, keine hinreichende Liste.

   **Beleg:** OID-Guards finden sich auch in Workspace-/Checkpoint-, Review-, Candidate- und Publishverträgen. Zugleich fasst die [Approvalmigration](G:/software_projekte/AI6/database/migrations/2026_08_14_000000_add_ticket_approval_contract.php:187) OIDs und AI6-Prüfsummen in einer gemeinsamen Variablen zusammen. Besonders leicht zu verwechseln sind `check_results.tree_sha` und `result_tree_sha`: [CheckRunner](G:/software_projekte/AI6/app/AI6/Checks/CheckRunner.php:507) speichert dort Werte aus [CheckTreeBinding](G:/software_projekte/AI6/app/AI6/Checks/CheckTreeBinding.php:20), also eigenständige Dateibaum-Prüfsummen, keine Git-Tree-OIDs.

   **Änderungsvorschlag:** Vor dem Migrationsentwurf eine kompakte, überprüfbare Tabelle erstellen: betroffene Tabelle/Feld oder JSON-Feld, Semantik, bisheriger Guard, Formatquelle, erforderliche Änderung. Sie muss die wirkenden OIDs vollständig erfassen und die ähnlich benannten Nicht-OIDs ausdrücklich abgrenzen. Die weiter unten aufgeführten Anker sind der Startpunkt, keine behauptete Vollinventur.

   **Gezielter Nachweis:** Direkte Inserts/Updates scheitern bei Fremdformat und ungültigen Identitäten, während die geänderten Tabellen weiterhin ihre bisherigen Unveränderlichkeits-, Zustands- und Provenienzbedingungen erzwingen. Für die nahegelegenen echten SHA-256-Felder weiterhin 40-stellige Werte ablehnen. SQL-`NULL` und fehlende Bezugssätze bewusst prüfen; eine SQL-Bedingung, die nur `NULL` ergibt, ist kein verlässlicher Ablehnungsbeweis.

   **Kleine Lösung:** Eine handhabbare Feld-/Guardliste und gezielte Datenprovider. Kein generischer Migrationscompiler, kein DB-weites `64 → 40|64`, keine PHP-Callback-Abhängigkeit für Integritätsregeln, die auch bei direkten DB-Schreibversuchen gelten sollen. Alte Migrationen bleiben unverändert.

8. **V08 — P1, belegt/Präzisierung: Backfill anhand der realen Altzustände definieren.**

   **Ticketbezug:** Task 4, AC-03, TC-03. „Gebundene SHA-256-Projekte“ und „noch unprovisionierte Projekte“ reichen als Fallunterscheidung nicht aus.

   **Beleg:** Ein [Control-Branch-Wechsel](G:/software_projekte/AI6/tests/Feature/Git/ManagedCloneSynchronizerTest.php:101) kann `control_oid = NULL` und gleichzeitig eine gültige `pending_control_oid` hinterlassen. Auch ein bereits provisionierter Deploy-Key bedeutet noch keinen erfolgreich bestätigten Clone: [QueueManagedCloneOperation](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueManagedCloneOperation.php:51) verlangt `PROVISIONED`, bevor der erste Clone überhaupt gestartet werden kann. Hinzu kommen bereits persistierte Clone-Intents vor endgültiger Projektbindung und die gültigen Platzhalter aus V04.

   **Änderungsvorschlag:** Mindestens diese Altzustände unterscheiden: reine Registrierung; Deploy-Key provisioniert, noch kein Clone; bestätigtes SHA-256-Projekt; ausstehender Branchwechsel; gestagter/veröffentlichter Clone vor finaler DB-Bindung; ältere Runs/Approvals. `control_oid IS NULL` darf nicht pauschal „ungebunden“ bedeuten. Fehlgeschlagene SHA-1-Probes vor der ersten persistierten OID dürfen andererseits nicht durch einen pauschalen SHA-256-Default dauerhaft blockiert werden.

   **Gezielter Nachweis:** Jeder tatsächlich zulässige Altzustand erhält die richtige Behandlung und kann anschließend seinen vorgesehenen nächsten Schritt ausführen. Ein absichtlich inkonsistenter Altbestand wird mit identifizierbarem Datensatz-/Feldbezug, ohne sensible Inhalte, abgewiesen. Unveränderte Legacywerte mit zulässiger Sentinel-Semantik werden nicht als Korruption bezeichnet.

   **Kleine Lösung:** Eine feste Fallunterscheidung anhand der vorhandenen persistierten Bindungen. Keine automatische Reparatur erratener Daten. Keine Git-/SSH-Zugriffe aus der Migration: Die [dokumentierte Init-Rolle](G:/software_projekte/AI6/README.md:105) führt die Schemaänderung aus; der tatsächliche Repositorynachweis gehört in den Worker.

9. **V09 — P2, Präzisierung: Migration und Rollback als atomare Vertragsänderung nachweisen.**

   **Ticketbezug:** AC-03/07, TC-03/07. Das Ticket fordert bereits einen verweigerten verlustbehafteten Rückbau; offen bleibt die konkrete Reichweite der Prüfung.

   **Änderungsvorschlag:** Ein zurückgewiesenes Upgrade darf ebenso wenig halbfertig bleiben wie ein zurückgewiesenes Downgrade. Die Prüfung auf nicht darstellbare SHA-1-Bindungen muss sämtliche neu zugelassenen dauerhaften Bindungen erfassen, auch offene Operations-Intents, historische Daten und relevante strukturierte Referenzen. Nur `projects.control_oid` zu prüfen reicht nicht. Bei erfolgreichem SHA-256-Rollback müssen auch die alten Guards wieder gelten.

   **Gezielter Nachweis:** Vor und nach einem verweigerten Schritt Schema, Trigger, Daten und Migrationsregistrierung vergleichen. Beim erlaubten `up → down → up` zusätzlich eine repräsentative reguläre SHA-256-Schreiboperation ausführen und einen zuvor verbotenen Direktwrite erneut abweisen. Ein reiner Vergleich von Spaltennamen beweist den wiederhergestellten Vertrag nicht.

   **Kleine Lösung:** Den vorhandenen Laravel-/SQLite-Migrationsmechanismus mit explizitem Preflight und überprüfter Transaktionswirkung verwenden. Kein eigenes Upgrade-Orchestrierungssystem. Falls bestehende Trigger per Textanpassung fortgeschrieben werden, Zielstellen und erwartete Trefferzahl fest binden; der Code nutzt dieses defensive Muster bereits in der [Securityreview-Migration](G:/software_projekte/AI6/database/migrations/2026_08_31_000000_add_security_review_contract.php:165).

10. **V10 — P2, belegt/Präzisierung: Bestehende serialisierte Bindungen nicht durch neue Pflichtfelder unlesbar machen.**

    **Ticketbezug:** Task 3, AC-03/05/06; TC-03/05/06. „Keine Neusignierung“ ist richtig, prüft aber noch nicht, ob der neue Code die alten Bytes weiter konsumieren kann.

    **Beleg:** [ControlOperationType::PARAMETER_FIELDS](G:/software_projekte/AI6/app/AI6/Git/ControlOperationType.php:23) definiert geschlossene Parameterfelder. [ReviewSubjectReference](G:/software_projekte/AI6/app/AI6/Git/ReviewSubjectReference.php) akzeptiert ebenfalls nur einen begrenzten Feldsatz. `ReviewSubject::jsonSerialize()` ist Teil einer bestehenden Bindung. Zusätzliche Formatfelder in solchen Dokumenten können ihre Hashes oder Decoderverträge verändern.

    **Änderungsvorschlag:** Vorhandene SHA-256-Operationsparameter, Approval-/Instruktionssnapshots, Reviewgegenstände und HumanRequests müssen mit ihren ursprünglichen Bytes und Hashes weiter gelesen und an ihren vorhandenen Grenzen geprüft werden. Das gilt auch für vor dem Upgrade angelegte, noch nicht abgeschlossene Arbeit. Die Formatquelle für Legacydaten explizit benennen; keine allgemeine Regel „fehlendes Format bedeutet immer SHA-256“ für neue oder untrusted Daten.

    **Gezielter Nachweis:** Vor dem Upgrade erzeugte Beispiele nach dem Upgrade über die tatsächlichen Leser verarbeiten, einschließlich eines weiterlaufenden Operations-/Runzustands und einer noch offenen menschlichen Gateantwort. Bytegleichheit allein und erfolgreiche Neuobjekterzeugung allein sind jeweils unzureichend.

    **Kleine Lösung:** Neue Formatdaten möglichst außerhalb bereits signierter Payloads binden, sofern die unveränderliche Projekt-/Operationsreferenz das eindeutig erlaubt. Versionierung nur dort hinzufügen, wo sie wirklich benötigt wird; keine vorsorgliche V2 aller Dokumente.

11. **V11 — P2, belegt/Präzisierung: Den neuen Formatwert in die bestehenden Crash- und Wiederaufnahmegrenzen einbeziehen.**

    **Ticketbezug:** Tasks 2/3, AC-02/06, TC-02/06. „Recovery bleibt wirksam“ sollte an den durch diese Änderung betroffenen Übergängen nachgewiesen werden.

    **Beleg:** [publishOutcome](G:/software_projekte/AI6/app/AI6/Git/ManagedCloneSynchronizer.php:265) veröffentlicht das Repository beziehungsweise die Ref, bevor [finalizeBinding](G:/software_projekte/AI6/app/AI6/Git/ManagedCloneSynchronizer.php:311) die Datenbankbindung abschließt. Die Recovery muss bereits veröffentlichte eigene Effekte erkennen können.

    **Änderungsvorschlag:** Kontroll-OID und endgültiges Format werden gemeinsam finalisiert. Nach einem Absturz darf weder ein bestätigter Control-OID ohne Format noch ein fälschlich neu interpretierter vorhandener Clone entstehen. Prüfungen müssen sowohl den noch gestagten als auch den bereits veröffentlichten eigenen Effekt berücksichtigen.

    **Gezielter Nachweis:** Ein Absturz nach Dateisystemveröffentlichung vor DB-Finalisierung; ein Absturz nach DB-Finalisierung vor Terminalisierung. Wiederaufnahme aus einem tatsächlich unvollständigen Zustand mit abgelaufener Lease, nicht bloß erneute Zustellung eines bereits terminalen Jobs. Endzustand und unveränderte Effektanzahl prüfen.

    **Kleine Lösung:** Vorhandene Phasen, Leases und Crash-Injection-Nähte erweitern. Keine zweite Saga nur für das Format und keine zusätzliche verteilte Sperre.

12. **V12 — P2, belegt: Die strenge Remote-Antwortprüfung auch beim finalen Push anwenden.**

    **Ticketbezug:** AC-02/06, TC-02/06. Task 2 nennt bereits ein einziges vollständig validiertes Ref/OID-Paar; derselbe Sicherheitsvertrag ist am Ende des Ablaufs erforderlich.

    **Beleg:** [HardenedControlRemoteProbe](G:/software_projekte/AI6/app/AI6/Git/HardenedControlRemoteProbe.php:52) prüft Einzeiligkeit und exakte Ref. [PublishCompletionService::remoteOid](G:/software_projekte/AI6/app/AI6/Runs/PublishCompletionService.php:334) akzeptiert bisher dagegen einen passenden OID-Präfix vor Whitespace. Die vollständige Refantwort wird dort nicht gleich streng ausgewertet.

    **Änderungsvorschlag:** Für initiale Probe, Branchwechsel und Publish dieselbe strenge Auswertung eines angeforderten Ref/OID-Paars verwenden. Der fachlich erlaubte Fall „Runbranch fehlt noch“ bleibt separat vom Fall „Control-Ref fehlt“ und von einer fehlerhaften Probe.

    **Gezielter Nachweis:** Falsche Ref, mehrere Antworten, gemischte Formate und Zusatzinhalt werden auch am finalen Publishpfad abgewiesen. Ein korrekt fehlender neuer Runbranch bleibt möglich; ein Transport-/Protokollfehler darf nicht als fehlende Ref interpretiert werden.

    **Kleine Lösung:** Gemeinsame kleine Parserlogik beziehungsweise Wiederverwendung der vorhandenen Naht. Kein generischer Git-Protokollparser. Die zwei unterschiedlichen Bedeutungen einer fehlenden Ref nicht künstlich gleichsetzen.

13. **V13 — P2, Präzisierung: Deterministische Formatfehler von temporären Fehlern unterscheiden.**

    **Ticketbezug:** AC-02/04/06, TC-02/04/06. „Abgewiesen“ und „bestehender Fehlerpfad“ sagen noch nicht, ob ein Auftrag terminal endet, erneut versucht wird oder Recovery braucht.

    **Beleg:** [ControlOperationExecutor](G:/software_projekte/AI6/app/AI6/Git/ControlOperationExecutor.php) unterscheidet terminale Konflikte, wiederholbare Konflikte, Recovery und sonstige Fehler. Der Ticketauslöser selbst berichtet drei Versuche mit derselben fehlerhaften Bindung.

    **Änderungsvorschlag:** Für unbekanntes Format, eindeutigen Formatwiderspruch und manipulierte Bindung die vorhandene passende typisierte Fehlerfamilie benennen. Unveränderte deterministische Eingaben sollten nicht lediglich das allgemeine Retrybudget verbrauchen. Echte Remote-Head-Rennen, Leaseverluste und Transportfehler behalten ihren jeweils bestehenden fachlichen Pfad. Ein schon veröffentlichter Effekt erfordert eine andere Behandlung als eine Ablehnung vor Prozessstart.

    **Gezielter Nachweis:** Für die neu eingeführten Fehlerzweige Zustand, wiederholte Zustellung, erhaltene Projektbindung und redigierte Diagnose prüfen; bei HTTP weiterhin eine generische Außenantwort. Nicht nur auf eine Exception oder einen beliebigen Nonzero-Exit prüfen.

    **Kleine Lösung:** Vorhandene Fehlerklassen und Zustandsübergänge verwenden; lediglich klar begründete neue Reason-Werte ergänzen, falls nötig. Kein neues Fehlerframework.

14. **V14 — P2, belegt: Den Retry nach dem ursprünglichen SHA-1-Clonefehler konkretisieren.**

    **Ticketbezug:** Task 6. Der kontrollierte Retry steht in der Dokumentationsaufgabe, besitzt aber keinen ausdrücklich nachvollziehbaren Erfolgsnachweis in den TCs.

    **Beleg:** [QueueManagedCloneOperation](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueManagedCloneOperation.php:102) gibt bei einer bereits bekannten Operations-ID die bestehende Operation zurück. Eine erneute Anfrage mit derselben ID erzeugt daher nicht automatisch einen neuen erfolgreichen Clone. Provisionierung und Clone sind zudem unterschiedliche Zustände.

    **Änderungsvorschlag:** Den vorhandenen autorisierten Bedienpfad nach Upgrade beschreiben: Voraussetzung ist ein erfolgloser beziehungsweise terminaler früherer Clone, intakte Deploy-Key-Provisionierung und eine frei beziehungsweise regulär wiederhergestellte Operationssperre. Für einen neuen Auftrag wird eine neue Operations-ID verwendet; für unvollständige veröffentlichte Effekte greift der bestehende Recoverypfad. Keine manuellen SQL-Änderungen, kein Zurücksetzen von Auditdaten und keine erneute Schlüsselprovisionierung als Standardlösung.

    **Gezielter Nachweis:** Einen dem Anlass entsprechenden fehlgeschlagenen Erstclonezustand übernehmen, nach Upgrade den regulären neuen Auftrag ausführen und bis zu erfolgreichem Refresh gelangen. Der alte Fehlernachweis bleibt lesbar.

    **Kleine Lösung:** Vorhandene Aktion und Bedienung dokumentieren und prüfen. Kein neuer Retrycommand und keine zusätzliche Adminseite, solange der aktuelle Ablauf ausreicht.

15. **V15 — P2, Präzisierung: „Ohne Wirkung“ auf die richtige Grenze beziehen.**

    **Ticketbezug:** Goal Absatz 2, AC-02/04/06 und TC-02/04. Die Formulierungen können als vollständiges Verbot jeder lokalen Nebenwirkung verstanden werden.

    **Beleg:** Der vorhandene Clone-/Fetchablauf arbeitet mit Staging, versuchseigenen Refs und teilweise gemeinsamem Objektspeicher. Ein Widerspruch zwischen Probe und Clone kann erst nach dem temporären Clone erkannt werden. [ManagedCloneSynchronizer](G:/software_projekte/AI6/app/AI6/Git/ManagedCloneSynchronizer.php:55) trennt diese Vorbereitung von der Veröffentlichung.

    **Änderungsvorschlag:** Beobachtbare Garantien konkret nennen: keine Änderung der autoritativen Projekt-/Control-Bindung, kein unfreigegebener Remote-Push, keine neue Freigabe; versuchseigene Artefakte nur entsprechend dem bestehenden Cleanup-/Recoveryvertrag. Schon vor einem wirkenden Prozess erkennbare Formatfehler müssen davor scheitern. Erst später erkennbare Drift darf keine Veröffentlichung erreichen.

    **Gezielter Nachweis:** Die autoritativen Werte und die laut bestehendem Vertrag geschützten Git-Metadaten vergleichen. Zulässige Staging-/Objektspeichereffekte von unzulässigen Änderungen unterscheiden.

    **Kleine Lösung:** Bestehende Garantien präziser formulieren. Keine umfassende Dateisystemtransaktion, nur um einen zu absoluten Satz im Ticket erfüllen zu können.

16. **V16 — P2, belegt/Präzisierung: Raw-Diff-Fälle und die Bedeutung unveränderter Goldenwerte schärfen.**

    **Ticketbezug:** AC-01/05, TC-01/05. Ein Diff enthält Objekt-IDs, Moduswerte und Null-Sentinels; sein AI6-Hash hat eine andere Semantik als diese IDs.

    **Beleg:** [CanonicalDiffHasher](G:/software_projekte/AI6/app/AI6/Git/CanonicalDiffHasher.php:13) verwendet die bestehende Domäne `AI6-RUN-DIFF-V1`, sortierte Einträge und SHA-256. Die OIDs sind Bestandteil der gehashten Daten. [RunWorkspaceContractTest](G:/software_projekte/AI6/tests/Unit/Git/RunWorkspaceContractTest.php:103) prüft bereits Bindungen an Modus, Pfad und Objekt.

    **Änderungsvorschlag:** Ergänzen: Hinzufügen, Ändern, Löschen und leerer Diff werden in beiden Formaten verarbeitet; Nullwerte sind mit dem jeweiligen Status/Modus verträglich und keine realen Objektidentitäten. Gemischte OID-Längen innerhalb eines Diffs werden abgewiesen. Bestehende SHA-256-Testvektoren bleiben bytegleich, wenn ihr kanonischer Eingang unverändert ist.

    **Wichtige Präzisierung:** Derselbe fachliche Dateiunterschied in einem SHA-1- und einem SHA-256-Repository muss nicht denselben AI6-Diffhash ergeben, weil unterschiedliche OIDs in seine Eingabe eingehen. Unverändert bleiben Algorithmus und bestehende Vektoren, nicht zwangsläufig Werte über unterschiedliche Objektformate hinweg. Dasselbe Prinzip gilt für Snapshots, deren legitime Eingabedaten unterschiedlich sind.

    **Gezielter Nachweis:** Kleine parametrisierte Diffmatrix sowie ein fest gespeicherter Altvektor. Erwartete Werte nicht allein durch dieselbe neue Hilfsfunktion erzeugen, die gerade geprüft wird.

    **Kleine Lösung:** Den vorhandenen Serializer und die bestehende Domäne beibehalten. Keine Formatkonvertierung und kein zusätzliches Formatfeld im Diffhash, sofern der bisherige eindeutige Vertrag dies nicht benötigt.

17. **V17 — P2, belegt: Menschliche Gatebindungen bis zur ausgeführten Antwort prüfen.**

    **Ticketbezug:** AC-06, TC-06. Candidate- und Securitygates werden genannt; die konkrete gemischte Bindungsform sollte sichtbar werden.

    **Beleg:** [GateEvidenceHumanRequestBinding](G:/software_projekte/AI6/app/AI6/HumanLoop/GateEvidenceHumanRequestBinding.php:24) zerlegt `tree_oid:diff_hash`. [SecurityGateHumanRequestBinding](G:/software_projekte/AI6/app/AI6/HumanLoop/SecurityGateHumanRequestBinding.php:33) zerlegt Tree, Diff, Base, Instruktionshash, Policyhash und Profil. Heute sind alle fünf numerischen Felder pauschal auf 64 Hexzeichen festgelegt.

    **Änderungsvorschlag:** Die OID-Positionen formatabhängig prüfen, während die Hashpositionen unverändert SHA-256 verlangen. Die resultierende HumanRequest muss weiterhin dem richtigen vorhandenen Resolver zugeordnet werden. Alte SHA-256-Anfragen behalten ihre gebundenen Bytes und bleiben beantwortbar.

    **Gezielter Nachweis:** Eine echte, regulär erzeugte Gateanfrage für einen SHA-1-Candidate über die bestehende Antwortaktion bis zum autorisierten Effekt ausführen; Replay und fremde Candidatebindung bleiben abgewiesen. Zusätzlich ein Legacy-SHA-256-Beispiel weiterverarbeiten. Ein erfolgreicher Aufruf von `binding()` allein beweist keine erreichbare Wiederaufnahme.

    **Kleine Lösung:** Bestehende Bindungsparser, Dispatcher und Tests erweitern. Keine neuen Gatearten, kein alternatives Freigabeverfahren und keine Änderung der Rollen oder Step-up-Regeln.

18. **V18 — P2, Präzisierung: Den End-to-End-Nachweis klar vom bestehenden Release-Gate abgrenzen.**

    **Ticketbezug:** AC-08, TC-05/06/08. Der Entwurf grenzt fremde Gate-Lücken bereits richtig ab. Zusätzlich sollte klar sein, welcher tatsächlich ausgeführte Test die neue Fähigkeit nachweist.

    **Beleg:** [FakeAgentReleaseGateCommand](G:/software_projekte/AI6/app/AI6/Runs/Console/FakeAgentReleaseGateCommand.php:17) führt deklarierte blockierende Lücken. [FakeAgentReleaseGateContractTest](G:/software_projekte/AI6/tests/Feature/Runs/FakeAgentReleaseGateContractTest.php:193) prüft unter anderem die autoritativen Schreibstellen seiner Fixtures. Eine grüne Auswahl aus dem vorhandenen Testset ist deshalb nicht automatisch der fehlende vollständige Nachweis.

    **Änderungsvorschlag:** Für AI6-051 die tatsächlich ausgeführten Szenarien und ihre Endzustände benennen: je Format ein durchgängiger Implementierungsablauf bis zum bestätigten Runbranch-Push und Ticketstatus-CAS; der Review-only-Ablauf bis zum bestehenden report-only-Abschluss. Quellarten werden dort parametrisiert, wo der Quellvertrag geprüft wird. Setup darf Ausgangsdaten herstellen, aber nicht den jeweils behaupteten Approval-, Candidate-, Publish- oder Completioneffekt direkt in die DB schreiben.

    **Gezielter Nachweis:** Testname, Format, tatsächlich erreichte Endzustände und ausgeführte Linux-Fälle dokumentieren. Skips und bekannte fremde Lücken separat ausweisen. Ein allgemeiner Release-Gate-Fehler darf weder verschwiegen noch als Ausrede für fehlende neue Evidenz benutzt werden.

    **Kleine Lösung:** Vorhandene FakeAgent- und Git-Fixtures nutzen und erforderliche Verbindungen ergänzen. Keine neue Releaseplattform und keine nebenläufige Providerintegration für diesen Formatnachweis.

19. **V19 — P3, Vereinfachung: Die Tests an Verträgen parametrisieren, nicht die gesamte Suite verdoppeln.**

    **Ticketbezug:** Task 5 und TC-01 bis TC-08. Die Formulierung „beide Formate“ könnte zu einer unnötigen Verdopplung sämtlicher historischer Prozess-, Sicherheits- und UI-Szenarien führen.

    **Beleg:** [BuildsRunWorkspaceGitFixture](G:/software_projekte/AI6/tests/Feature/Git/BuildsRunWorkspaceGitFixture.php:126) erzeugt derzeit ausdrücklich ein SHA-256-Repository. Sie ist ein geeigneter Ansatzpunkt für eine schmale Formatparametrisierung.

    **Änderungsvorschlag:** Drei Ebenen vorsehen: eine kleine Format-/Feldmatrix für den zentralen Vertrag; parametrisierte Integrationstests für die tatsächlich formatabhängigen Grenzen; wenige vollständige Abläufe pro Format. Unveränderte Security- und Prozessmechanismen laufen weiterhin als Regression, werden aber nur dort zusätzlich mit beiden Formaten geprüft, wo ihre OID-Bindung betroffen ist. Die vorhandenen SHA-256-Standardfälle beibehalten.

    **Gezielter Nachweis:** Bestehende Testnamen als Anker zu den TCs nennen und nach Implementierung die konkreten neuen/erweiterten Methoden in der Evidenz aufführen. Formatabhängige positive und negative Fälle müssen erreichbar sein; keine nur künstlich gekürzten 64-stelligen Fixtures als Ersatz für echte SHA-1-Git-Ausgaben.

    **Kleine Lösung:** Option beziehungsweise Datenprovider an vorhandenen Fixtures, keine neue universelle Testfactory. Die vollständige reguläre Suite bleibt das Abschlussgate und wird nicht nach jedem einzelnen Reviewpunkt erneut gestartet.

20. **V20 — P3, Verständlichkeit: Scope und ACs mit einer kleinen Verbrauchertabelle konkretisieren.**

    **Ticketbezug:** `files`, Tasks 1/3/5 und AC-04 bis AC-06. Die breiten Modulpfade sind zulässig, helfen einem umsetzenden LLM aber wenig bei den nicht offensichtlichen Verbrauchern.

    **Änderungsvorschlag:** Eine kurze Tabelle mit den konkreten Ankern aus diesem Bericht in Context oder Tasks aufnehmen. Den Scope mindestens um die in V02/V03 belegten Dateien ergänzen; relevante Review-/Checker-Tests nach ihrem tatsächlichen Änderungsbedarf nennen. Die Scopebestimmung bleibt eine Schätzung, kein Anspruch auf eine dauerhaft vollständige Dateiliste. Bereits bekannte Dateien zu nennen ist hilfreicher, als sämtliche Klassen aus sechs Modulen abzuschreiben.

    **AC-Präzisierung:** Die vorhandenen ACs können bleiben. Ihre mehreren Teilbehauptungen jeweils durch kurze Teilfälle im zugeordneten TC prüfbar machen. Zusätzliche IDs nur für tatsächlich neue getrennte Nachweise. Die Einträge dieses Berichts sind keine Aufforderung, 22 neue ACs zu erzeugen.

    **Kleine Lösung:** Ein Ticket beibehalten. Die Mehrmodulausnahme ist in V1.7.10 ausdrücklich entschieden; ein erneuter Split allein wegen der Modulzahl wäre unbegründet. Falls die konkrete Umsetzung eine tatsächlich unabhängige zweite Fähigkeit verlangt, ist das gesondert zu bewerten. Neue Architektur lässt sich aus der Ausnahme nicht ableiten.

21. **V21 — P2, Präzisierung: Den Upgrade-Betriebsablauf knapp und ausführbar beschreiben.**

    **Ticketbezug:** Task 6, AC-07, TC-07. Eine korrekte Migration allein verhindert keine gleichzeitigen Writes alter Prozesse mit dem alten Formatvertrag.

    **Änderungsvorschlag:** Den unterstützten Upgradefall in der README eindeutig festlegen: keine gleichzeitig schreibenden alten App-/Worker-/Schedulerprozesse während der Schemaumstellung; persistierte offene Arbeit bleibt erhalten und wird nach dem Upgrade vom neuen Stand fortgesetzt. Migrationsfehler und verweigerter Rollback erhalten einen klaren Betreiberhinweis. Das betrifft die Durchführung, nicht die Erfindung einer unterbrechungsfreien Mischversionskompatibilität.

    **Gezielter Nachweis:** Die Anleitung an einer wegwerfbaren Upgrade-Datenbank nachvollziehen und mindestens einen erhaltenen offenen Auftrag mit dem neuen Code fortsetzen. Die reguläre Produktionsdatenbank wird durch automatisierte Tests nicht verändert. Wenn dieser Nachweis bereits durch V08 bis V11 erbracht wird, genügt ein Verweis; kein doppelter Test.

    **Kleine Lösung:** Bestehenden Installations-/Upgradeablauf ergänzen. Kein Online-Schemamigrationssystem, keine zusätzliche Infrastruktur und keine automatische Datenreparatur. Die konkrete Migrationsfreigabe bleibt eine menschliche Entscheidung nach den bereits geltenden Repositoryregeln.

22. **V22 — P3, Präzisierung: MG-01 und Prüfgrundlage genauer an den tatsächlich getesteten Stand binden.**

    **Ticketbezug:** Context, AC-08 und MG-01. Die vorhandene Beschränkung auf autorisierte Remotes und die Trennung fremder Gates sind bereits gut.

    **Änderungsvorschlag:** Das resultatsfreie Formular soll den getesteten Softwarecommit, tatsächlich laufenden Image-/Git-Stand, Projektformat, konkrete Operations-IDs und deren Endzustände sowie das beobachtete Control-Ref/OID-Paar erfassen. Testobjekt-OID und AI6-Softwarecommit klar unterschiedlich beschriften. Den Retrypfad aus V14 aufnehmen, wenn genau der dokumentierte Anlass geprüft wird.

    **Wichtig für den aktuellen Arbeitsstand:** Die bereits vorhandenen lokalen Runtimekorrekturen sind laut Ticket kein Teil dieses Auftrags. Ein späterer Abnahmebericht darf trotzdem nicht einen sauberen Candidate behaupten, wenn der laufende Container zusätzliche uncommittete Implementierungsbytes enthält. Die getesteten Bytes müssen zum benannten Stand passen.

    **Kleine Lösung:** Das eine vorgesehene MG-01-Formular präzisieren. Keine zusätzlichen pauschalen manuellen Gates. Lesender SSH-Smoke auf dem freigegebenen Projekt; schreibender Nachweis nur auf dem gesondert autorisierten Wegwerfremote. Niemals eine Signatur vorwegnehmen.

**Konkrete Verbraucher als Startpunkt für die Ticketüberarbeitung**

Diese Tabelle ergänzt die Belege oben. Sie ist bewusst keine behauptete vollständige Datenbank- oder API-Inventur. Alle genannten Bestandsdateien wurden im Repository gefunden und die jeweils maßgebliche Naht gelesen.

| Bereich | Bestehender Anker | Für AI6-051 entscheidende Unterscheidung |
|---|---|---|
| Probe | [HardenedControlRemoteProbe](G:/software_projekte/AI6/app/AI6/Git/HardenedControlRemoteProbe.php:52) | Exakte angeforderte Ref und vollständige OID; Initialerkennung versus spätere gebundene Prüfung. |
| Clone/Fetch | [ManagedCloneSynchronizer](G:/software_projekte/AI6/app/AI6/Git/ManagedCloneSynchronizer.php:55) | Vorläufiger Intent, gestagtes Repository, veröffentlichter Effekt und endgültige DB-Bindung. |
| Git-Objektberechnung | [HardenedGitRunner](G:/software_projekte/AI6/app/AI6/Git/HardenedGitRunner.php:805) | Git-Blob/Tree-Hash nach Repositoryformat; andere SHA-256-Hashes derselben Klasse bleiben unverändert. |
| Mutation/Start | [QueueRunStart](G:/software_projekte/AI6/app/AI6/Git/Actions/QueueRunStart.php:132) | Erwarteter Git-Blob und interner Tree-Platzhalter haben unterschiedliche Bedeutungen. |
| Queue | [QueueEligibility](G:/software_projekte/AI6/app/AI6/Runs/QueueEligibility.php:77) | Nachberechnung des Git-Blobs darf SHA-1 nicht pauschal als inkonsistent behandeln. |
| Configfreigabe | [ProjectConfigurationController](G:/software_projekte/AI6/app/AI6/Projects/Http/ProjectConfigurationController.php:47) | Control-/Blob-OID formatabhängig; Config-Hash weiterhin SHA-256. |
| Approvaleingaben | [TicketApprovalController](G:/software_projekte/AI6/app/AI6/Runs/TicketApprovalController.php:41) | Control-, Blob-, Basis-, Quell- und Tree-OID versus Diff-/Approvalhash. |
| Approvalformular | [ticket.blade.php](G:/software_projekte/AI6/resources/views/approvals/ticket.blade.php:49) | Browserpattern der OIDs anpassen; Hashpattern erhalten. |
| Instruktionen | [InstructionSnapshotResolver](G:/software_projekte/AI6/app/AI6/Agents/InstructionSnapshotResolver.php:38) | Bisherige 40-only-Blobprüfung versus unveränderter Snapshot-SHA-256. |
| Reviewgegenstand | [ReviewSubject](G:/software_projekte/AI6/app/AI6/Git/ReviewSubject.php:19) | Reale OIDs von `expectedDiffHash` trennen; Serialisierung kompatibel halten. |
| Reviewprovenienz | [ReviewSubjectVerifier](G:/software_projekte/AI6/app/AI6/Git/ReviewSubjectVerifier.php:112) | Projekt-/Quellrunbindung bleibt zusätzlich zur OID-Syntax erforderlich. |
| Kanonischer Diff | [CanonicalDiffHasher](G:/software_projekte/AI6/app/AI6/Git/CanonicalDiffHasher.php:36) | OID-Felder ändern sich mit dem Format; AI6-Domäne, kanonische Darstellung und SHA-256-Verfahren bleiben bestehen. |
| Checker | [CheckTreeBinding](G:/software_projekte/AI6/app/AI6/Checks/CheckTreeBinding.php:20) | Eigene Dateibaumprüfsumme, keine Git-Tree-OID. |
| Gates | [SecurityGateHumanRequestBinding](G:/software_projekte/AI6/app/AI6/HumanLoop/SecurityGateHumanRequestBinding.php:38) | In derselben Zeichenkette stehen Git-OIDs und drei andersartige Hashwerte. |
| Publish | [PublishCompletionService](G:/software_projekte/AI6/app/AI6/Runs/PublishCompletionService.php:334) | Vollständige Remoteantwort und legitimer Sentinel für den noch fehlenden Runbranch. |
| DB: Mutation/Approval | [Approvalmigration](G:/software_projekte/AI6/database/migrations/2026_08_14_000000_add_ticket_approval_contract.php:134) | Gemischte Feldklassen, einmaliger Tree-Platzhalterübergang, unveränderliche Freigabebindungen. |
| DB: Runbasis | [Runmigration](G:/software_projekte/AI6/database/migrations/2026_08_15_000000_add_run_contract.php:84) | Formatabhängige Basis-OIDs bei unveränderten Zustands-/Versionsguards. |
| DB: Checkpoint | [Workspace-/Checkpointmigration](G:/software_projekte/AI6/database/migrations/2026_08_16_000000_add_run_workspace_checkpoint_contract.php:29) | Commit-/Tree-OID versus Checkpoint-Diffhash. |
| DB: Review | [Reviewresultatmigration](G:/software_projekte/AI6/database/migrations/2026_08_21_000000_add_review_result_contract.php:60) | Checkpoint-OIDs versus zahlreiche Approval-/Snapshot-/Workspacehashes. |
| DB: Security | [Securityreview-Migration](G:/software_projekte/AI6/database/migrations/2026_08_31_000000_add_security_review_contract.php:94) | Candidate-Tree/Basis versus Diff-, Contract-, Scope-, Policy- und Instruktionshashes. |
| DB: Publish | [Publishabschlussmigration](G:/software_projekte/AI6/database/migrations/2026_09_01_000000_add_publish_completion_contract.php:28) | Endcommit, Parent, Branch-CAS und Publicationbindung versus Recorded-Scope-SHA-256. |

**Was bei der Überarbeitung ausdrücklich klein bleiben sollte**

- Ein geschlossener Vertrag für exakt `sha1` und `sha256`, beispielsweise ein kleiner Enum oder eine konkrete Klasse mit den wirklich benötigten Methoden. Die Wahl ist eine Implementierungsfrage, kein Anlass für Strategy-, Factory- oder Registry-Schichten.
- Bestehende String-OIDs können bleiben, wenn ihre Grenzen eindeutig geprüft sind. Keine flächendeckende Umstellung aller DTOs und Datenbankfelder auf neue Value Objects allein aus Stilgründen.
- Ein gemeinsamer produktiver Algorithmusvertrag. SQL-Guards dürfen keine beliebigen Algorithmen zur Laufzeit nachladen; alte Migrationen dürfen nicht von einer künftig veränderlichen Anwendungsklasse abhängig gemacht werden.
- Keine OID-Konvertierung, keine zusätzliche Unterstützung beliebiger zukünftiger Hashalgorithmen, kein neues Projektformat-Auswahlfeld.
- Kein massenhaftes Umbenennen historischer `_sha`-Felder zu `_oid`. Ihre Semantik im neuen Vertrag richtig behandeln; kosmetische Migrationen würden zusätzliche Risiken erzeugen.
- Keine neue vollständige Git-Integritätsprüfung bei jeder Operation, keine Rekonstruktion der gesamten Historie. Vorhandene Objekt-, Provenienz- und CAS-Prüfungen behalten und um das fehlende Formatwissen ergänzen.
- Nicht jede Struktur mit einem neuen `object_format`-Feld versehen. Persistieren, wo eine unabhängige unveränderliche Bindung nötig ist; ansonsten aus der bereits gebundenen Autorität beziehen.
- Keine neue CI-/Deploy-/Doctorfähigkeit allein für dieses Ticket. Die Linux-Optionen und beide Git-Formate sind konkret zu prüfen; ein zusätzliches Subsystem ist daraus nicht abzuleiten.
- Kein umfassendes Backup-/Restoreprodukt unter „Rollback“ verstecken. Der bestehende Betriebsvertrag genügt, ergänzt um den hier notwendigen sicheren Migrationsrückweg.
- Keine pauschale Aufweichung der vorhandenen Tests. Ein bisheriger reiner SHA-1-Ablehnungstest erhält einen inhaltlich gleichwertigen neuen Negativfall, während der nun erlaubte SHA-1-Erfolgsfall separat nachgewiesen wird.
- Keine neue Ticketaufteilung allein wegen sechs oder sieben konsumierender Module. Keine Änderung an Ticketstatus, Gateergebnissen oder normativen Dokumenten aus diesem Review heraus.

**Vorschlag für die Reihenfolge der Überarbeitung**

Zuerst V01–V04 und den Formatlebenszyklus aus V05 klären. Danach die konkrete Feld-/Guardliste und Legacyfallmatrix aus V07–V10 erstellen; damit wird die neue Migration überhaupt erst sinnvoll prüfbar. Anschließend V06 und V11–V18 in die bestehenden ACs/TCs einarbeiten. Zum Schluss Scope, Testumfang, Betriebsbeschreibung und MG-01 mit V19–V22 glätten. Diese Reihenfolge beschreibt die Ticketüberarbeitung, keine Implementierungsfreigabe.

**Arbeitsauftrag für das kritisch prüfende LLM**

> Prüfe jeden Vorschlag V01–V22 am aktuellen Ticket, dem normativen Plan und dem realen Code. Behandle diesen Bericht als prüfbare Empfehlung, nicht als zusätzliche Autorität. Entscheide je Vorschlag: übernehmen, präzisieren/zusammenführen oder verwerfen, mit kurzer Begründung und Gegenbeleg bei Abweichung. Priorisiere die konkret belegten Verbraucherlücken und den widersprüchlichen Sentinelvertrag aus V01–V04. Übernimm keine neue Abstraktion, Persistenzkopie, Testkombination oder manuelle Freigabestufe ohne einen konkret benannten Bedarf. Eine bereits allgemein enthaltene Anforderung soll vorzugsweise durch einen Codeanker und einen gezielten Test präzisiert werden, nicht als weiteres AC dupliziert werden. Die Tests dieses Berichts sind Vorschläge, keine bestandene Evidenz. Prüfe vor Ticketänderungen die dafür erteilte menschliche Freigabe; Status, Gateergebnisse und normative Dokumente bleiben davon getrennt. Bestehende AC-/TC-/MG-IDs möglichst erhalten und veröffentlichte IDs niemals neu zuordnen.
