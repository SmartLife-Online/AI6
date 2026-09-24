# AI6-051: Zweite Verbesserungsliste zur kritischen Prüfung

Prüfstand: 24. September 2026. Gegenstand ist ausschließlich der uncommittete, bereits einmal überarbeitete Entwurf `tickets/AI6-051.md` (erste Runde: `docs/AI6-051_TICKET_REVIEW.md` mit V01–V22 und `docs/AI6-051_TICKET_REVIEW_BEWERTUNG.md`). Diese Liste ist eine Prüfvorlage für ein zweites LLM, keine beschlossene Anforderung. Jeder Punkt soll einzeln übernommen, eingeschränkt, zusammengeführt oder verworfen werden.

**Bezug und Grenzen**

- Repository-HEAD `399076a0df8ff98f4d10e0310cd0a1e136a65988`, Planarbeitsstand V1.7.10 (uncommittet).
- SHA-256 der geprüften Ticketdatei: `a2dceb306995b3ddddd52da91bf2351da30ab7de88d27aa797da0fa3c429e4e8`; des Plans: `60f0fa53a5648de32caaf58304e8ee8558d1fc5864df7d7a60158273e9ac24a7`.
- Ausgeführt: realer `TicketV1Parser` und `Ai6DetailV1TicketValidator` (0 Fehler), Struktur-Abgleich (AC-/TC-/MG-IDs, Coverage, `files` = Scope, `new`/`existing` gegen Dateibestand, LF/BOM) ohne Befund. Alle Migrationen in eine Wegwerf-SQLite-Datei im Scratchpad; daraus die Inventur der aktiven Trigger (Anhang A). Git-Verhalten beider Formate lokal mit Git 2.33.0.windows.1 geprüft (Anhang B); das ist kein Nachweis für das Git des Linux-Images.
- Nicht ausgeführt: Implementierung, Test-Suite, Linux-Läufe, manuelle Gates. Ticket, Plan, Status und Gates wurden nicht geändert; einzige neue Datei ist diese Liste.

**Kurzurteil.** Das Ticket ist formal gültig und hat die richtige Stoßrichtung. Offen sind vor allem drei Dinge: einige am Code belegte Verbraucher fehlen noch (darunter eine echte OID/Hash-Verwechslung im Reviewpfad), die teuerste Entscheidung — wie die Datenbank das Projektformat erzwingt — ist nicht getroffen, und mehrere Stellen verlangen mehr Aufwand als nötig (Altbestandsfälle, Rückbauprüfung, Testumfang). Die meisten Vorschläge machen das Ticket kleiner, nicht größer.

**Prioritäten.** P1 = vor Implementierungsfreigabe klären (belegte Lücke oder kostenbestimmende Entscheidung). P2 = Präzisierung oder Vereinfachung, die Nacharbeit oder Scheinnachweise verhindert. P3 = Lesbarkeit, Aufräumen.

---

## Vorgeschlagene Leitlinie: das Zielbild in fünf Sätzen

Das Ticket beschreibt viele Einzelregeln, aber nirgends in wenigen Sätzen, wie die Lösung aussieht. Vorschlag für den Anfang von `## Tasks` (siehe W13):

1. **Neu:** ein kleines Enum `GitObjectFormat` (`sha1`, `sha256`) in `app/AI6/Git/` mit OID-Länge, OID-Prüfung (vollständig, kleingeschrieben, nicht die Null-OID), formatlanger Null-OID und Git-Objekt-ID-Berechnung (`"<typ> <länge>\0<bytes>"`).
2. **Neu:** genau eine Spalte `projects.object_format` — `NULL` bis zum ersten bestätigten Clone, dann unveränderlich; einzige Formatquelle aller Projektbindungen.
3. Jede Stelle, die eine Git-OID prüft, berechnet oder als Sentinel erzeugt, richtet sich nach diesem Format; AI6-eigene SHA-256-Prüfsummen bleiben unverändert.
4. Genau eine Migration: Spalte, Backfill (`sha256` für jedes Projekt mit Control- oder Pending-OID), Umstellung der bestehenden OID-Guards, ein Guard auf `projects`; `down()` verweigert bei SHA-1-Daten.
5. Tests: kleine Formatmatrix, SHA-1-Varianten der formatabhängigen Integrationspfade, ein SHA-1-Gesamtablauf; die bestehenden SHA-256-Tests bleiben Regression.

---

## A. Belegte Lücken im Ticket

### W01 — P1: OID in einem SHA-256-Hashfeld (`workspace_tree_hash`)

**Ticketbezug:** Context-Tabelle, Task 3, AC-05, TC-05; Blueprint-Negativfall „Hash/OID-Verwechslung“.

**Beleg:** `workspace_tree_hash` ist eine AI6-Prüfsumme aus `CheckTreeBinding::hash()` (`ReviewRound.php:270`, `FindingVerificationRound.php:238`; `ReviewOnlyExecutionTest.php:99` vergleicht sie mit `runs.review_workspace_hash`). `FindingVerificationRound::invoke()` belegt das Feld aber vorab mit der Git-Tree-OID: `'workspace_tree_hash' => $run->checkpoint_tree_sha` (`FindingVerificationRound.php:211`). Scheitert danach `checkpoints->verify()` oder fehlt der Workspace, schreibt `ReviewResultStore::append()` diesen Wert per `...$bindings` in `review_results` (`ReviewResultStore.php:36–48`). Der Trigger `review_results_insert_guard` verlangt für einen gesetzten `workspace_tree_hash` genau 64 Hexzeichen. `ReviewRound` belegt dasselbe Feld dagegen mit `null` vor (`ReviewRound.php:194`).

**Auswirkung:** In einem SHA-1-Projekt scheitert der Fehlerpfad (`binding_error`, `workspace_error`) der Finding-Verifikation am DB-Guard statt das typisierte Ergebnis zu speichern. Unter SHA-256 ist es heute eine unbemerkte Verwechslung. Möglicher Folgefehler auch unter SHA-256: `ReviewResultStore::expectedWorkspaceHash()` nimmt den ersten nicht leeren Wert der Runde; ein so gespeicherter Git-OID würde spätere Versuche mit `review_workspace_hash_mismatch` scheitern lassen. Das ist nicht ausgeführt, nur aus dem Code abgeleitet.

**Vorschlag:** Stelle in die Verbrauchertabelle aufnehmen. Vorbelegung wie in `ReviewRound` auf `null`. In TC-05 genau diesen Fehlerpfad für SHA-1 prüfen (erfüllt zugleich den Blueprint-Negativfall). Den möglichen SHA-256-Folgefehler dem Menschen gesondert melden, nicht still mitreparieren.

**Klein halten:** Keine neue Spalte, keine Umbenennung; eine Zeile Produktcode plus Test.

### W02 — P1: Run-, Publish- und Securityprüfstellen fehlen in der Verbrauchertabelle

**Ticketbezug:** Context-Tabelle, Task 1/3, AC-04–AC-06.

**Beleg:** Diese Stellen prüfen Git-OIDs gegen 64 Zeichen, die meisten gemeinsam mit AI6-Hashes in *einer* Schleife bzw. mit *einem* Helfer:

| Stelle | OIDs | Hashes in derselben Prüfung |
|---|---|---|
| `RunOrchestrator::finalizeClaim()` (Z. 896) | Claim-Parent, bestätigter Commit | — |
| `RunOrchestrator::bindReviewCheckpoint()` (Z. 1000) | Base, Source, Tree | Diff-, Workspacehash |
| `RunOrchestrator::bindCheckpoint()` (Z. 1055) | Commit, Tree | Diffhash |
| `RunOrchestrator::bindCandidate()` (Z. 1113) | Tree, Base | Diffhash |
| `RunOrchestrator::prepareBranchPublication()` / `bindPublishIntent()` (Z. 1372/1405) | erwartete Remote-OID, Ziel-OID (Null-Sentinel möglich) | — |
| `RunOrchestrator::applyContractAmendment()` (Z. 2129) | neue Runbasis, Ticketblob | Contract-, Scope-, Config-, Prompthash |
| `RunPreflight::sha()` (Z. 177, genutzt Z. 39–49, 137, 162) | Control-, Blob-, Runbasis-, Claim-OIDs | Prompt-, Instruktions-, Profil-, Policyhashes |
| `PublishCandidateService::assertBindings()` (Z. 95–100) | Basis-, Checkpoint-OIDs | Checkpoint-Diffhash |
| `SecurityReviewStep::candidateComplete()` (Z. 389–397) | Candidate-Tree, -Basis | Diff-, Contract-, Scopehash |

`SecurityReviewStep::execute()` parkt bei `false` mit `security_candidate_binding_missing` (Z. 73–74). Ein SHA-1-Run bliebe also mit einem irreführenden Grund vor dem Securityreview stehen.

**Vorschlag:** Eine Tabellenzeile „Run-, Publish- und Securityprüfstellen“ mit diesen Ankern ergänzen. Die Review-Focus-Zeile „kein pauschales Ersetzen jeder 64-Zeichen-Regel“ bekommt damit konkrete Prüfpunkte.

**Klein halten:** Schleifen nur teilen (OIDs gegen Format, Hashes unverändert); keine neue Validierungsklasse.

### W03 — P1: Alle Schreiber der Control-Bindung benennen

**Ticketbezug:** Task 2, AC-02, AC-06.

**Beleg:** `projects.control_oid` wird an vier Stellen geschrieben: `ManagedCloneSynchronizer::finalizeBinding()` (Z. 332), `ManagedCloneSynchronizer::retryRecovery()` (Z. 539, auch über `adoptExternalState()`), `ControlBranchChanger` (setzt `control_oid = null` und `pending_control_oid`, Z. 170–172) und `TicketMutationExecutor` nach einer Control-Mutation (Z. 709). Das Ticket nennt nur den regulären Finalisierungspfad. Die menschliche Recoveryentscheidung setzt `control_oid` ebenfalls und müsste das Format gemeinsam finalisieren.

**Vorschlag:** Die vier Schreiber in Task 2 nennen. Der Projektguard aus W07 (Format nur `NULL` → Wert, danach unveränderlich; `control_oid`/`pending_control_oid` passend zur Länge) sichert alle vier an einer Stelle ab, statt vier Codepfade einzeln abzusichern.

### W04 — P1: Guardmuster und Spalten, die eine Textsuche übersieht

**Ticketbezug:** Task 1 (Inventur), Task 4, AC-03, TC-03.

**Beleg (Anhang A):**

- `findings.checkpoint_tree_sha` und `finding_statuses.checkpoint_tree_sha` werden nicht mit `length(...) <> 64` geprüft, sondern mit `NOT GLOB replace(hex(zeroblob(32)), '00', '[0-9a-f][0-9a-f]')` (`2026_08_22_000000_add_finding_contract.php:103`, `2026_08_23_000000_add_finding_status_history.php:42`).
- `projects.control_oid` und `pending_control_oid` haben heute **keinen** Längenguard; kein aktiver Trigger fragt `projects` ab.
- Namen führen in beide Richtungen in die Irre: `run_checkpoints.tree_sha` ist eine Git-Tree-OID, `check_results.tree_sha` eine Checker-Prüfsumme; `workspace_tree_hash` ist eine Prüfsumme, wird aber mit einer OID befüllt (W01).
- `ticket_approvals_insert_guard` enthält 13 Längenprüfungen, davon nur vier auf OIDs.

**Vorschlag:** Die verifizierte Inventur (Anhang A) oder mindestens ihre Eckzahlen ins Ticket übernehmen: 24 aktive Trigger in 12 Tabellen, 43 OID-Spalten mit 64-Guard, 10 OID-Spalten ohne Längenguard. Die Inventur aus Task 1 wird damit zu einem Abgleich statt einer Neusuche. Zusätzlich die beiden Guardmuster ausdrücklich nennen.

### W05 — P1: Sentinelstellen konkret benennen; 64 Nullen brechen Ref-Erzeugung und Erstpush unter SHA-1

**Ticketbezug:** AC-01, TC-01, TC-06.

**Beleg:** Formatlose 64-Nullen stehen in `HardenedGitRunner::updateRef()` (Vorgabe ohne erwartete OID, Z. 799), `HardenedGitRunner::createAttemptRef()` (Z. 1155), `PublishCompletionService::ZERO_OID` (Z. 47; genutzt in `publishBranch()` und `remoteOid()`), in der Lease-Erwartung von `pushCommitCas()` (Z. 1112) sowie als interner Platzhalter `ticket_mutations.expected_target_tree_oid` (`QueueTicketMutation.php:523`, `TicketMutationExecutor.php:529`). Lokal geprüft (Anhang B): In einem SHA-1-Repository scheitert `update-ref <ref> <neu> <64 Nullen>` mit „not a valid old SHA1“ und `--force-with-lease=<ref>:<64 Nullen>` mit „cannot parse expected object name“.

**Vorschlag:** Diese Liste in AC-01/TC-01 statt der abstrakten „drei Nullwertkontexte“ nennen. `isOid()` lehnt Null ab; genau diese Stellen akzeptieren die formatlange Null-OID bzw. den Platzhalter.

**Optionale Vereinfachung:** Für Git-Argumente mit der Bedeutung „Ref darf nicht existieren“ das von Git dokumentierte leere Argument verwenden (`update-ref <ref> <neu> ""`, `--force-with-lease=<ref>:`). Beides funktioniert lokal in beiden Formaten und lehnt eine vorhandene Ref ab. Der Runner braucht dann für Sentinels kein Format; persistierte „fehlt“-Werte (`branch_publication_expected_oid`) bleiben formatlange Null-OIDs. Vor Übernahme einmal mit dem Git des Images ausführen.

### W06 — P1: Publish-Probe übernimmt heute die erste Zeile, auch eine fremde Ref

**Ticketbezug:** Task 5, AC-06, TC-06 (verschärft V12 der ersten Runde mit Beleg).

**Beleg:** `git ls-remote --refs <remote> refs/heads/ai6/runs/x` meldet auch `refs/foo/refs/heads/ai6/runs/x` (Tail-Matching); im geprüften Beispiel steht die fremde Ref **vor** der gesuchten (Anhang B). `PublishCompletionService::remoteOid()` (Z. 336–351) nimmt die OID der ersten Zeile per `/\A([0-9a-f]{64})\s/`, ohne die Ref zu prüfen. Die Control-Probe (`HardenedControlRemoteProbe::resolve()`) weist mehrzeilige Antworten ab; Branchwechsel nutzt dieselbe Probe (`ControlBranchChanger.php:94`).

**Vorschlag:** Genau eine Auswertungsfunktion für Probe, Branchwechsel und Publish: Zeile mit exakt gleicher Ref, vollständige OID im Projektformat, Mehrdeutigkeit fail-closed. Die zwei Bedeutungen von Exitcode 2 (Control-Ref fehlt = Fehler; neuer Runbranch fehlt = zulässig) bleiben beim jeweiligen Aufrufer.

**Klein halten:** Eine Funktion, zwei Aufrufer; kein Git-Protokollparser.

---

## B. Entscheidungen, die das Ticket ausdrücklich treffen sollte

### W07 — P1: Die Datenbankvariante festlegen (größter Kostentreiber)

**Ticketbezug:** Task 4, AC-03, TC-03, TC-07; Sensitive path der Migration.

**Beleg:** AC-03 verlangt „projektgebundene OID-Regeln“. Heute fragt keiner der 77 aktiven Trigger die Tabelle `projects` ab. Betroffen sind 43 OID-Längenprüfungen in 24 Triggern (Anhang A). Ohne Festlegung sind sehr unterschiedliche Umsetzungen möglich, von zusätzlichen Formatspalten in jeder Tabelle bis zu generierten Spalten.

**Empfehlung (kleinste Variante, die „denselben Vertrag“ aus dem Blueprint erfüllt):**

1. `projects`: die eine neue Spalte aus W08 und ein Guard: `object_format` nur `NULL` → `sha1|sha256`, danach unveränderlich; `control_oid`/`pending_control_oid` nur mit gesetztem Format und passender Länge.
2. Die 43 bestehenden OID-Längenprüfungen vergleichen mit der Formatlänge des Projekts statt mit 64 — über die Projekt-, Run- bzw. Operationsreferenz, die die Zeile schon trägt. Der Vergleich muss NULL-sicher sein (`IS NOT` oder `NOT EXISTS` mit positiven Bedingungen); ein schlichtes `<>` gegen eine Unterabfrage ergibt bei ungebundenem Projekt `NULL` und lässt den Schreibvorgang durch.
3. Genau zwei benannte Ausnahmen: der interne 64-Nullen-Platzhalter von `ticket_mutations.expected_target_tree_oid`; `control_operations.target_control_oid` eines Erstclone-Intents bei noch ungebundenem Projekt (40 oder 64).
4. Keine neuen Guards für heute unbewachte OID-Spalten, keine Änderung an Hashguards, keine Formatspalten außerhalb von `projects`.

Technik wie im Bestand: aktuellen Triggertext aus `sqlite_master` lesen, gezielt ersetzen, Trefferzahl binden (Muster in `2026_08_31_000000_add_security_review_contract.php`), `down()` kehrt exakt um.

**Alternative mit weniger SQL:** nur `NOT IN (40, 64)` plus Projektguard. Das ist deutlich kleiner, weicht aber vom Blueprint-Satz „Neue und aktualisierte Datenbanken erzwingen denselben Vertrag“ ab: eine formatfremde, syntaktisch gültige OID würde nur noch die Anwendung abweisen. Diese Wahl braucht eine ausdrückliche menschliche Entscheidung.

### W08 — P1: Die Formatautorität konkret benennen; keine weitere Persistenz

**Ticketbezug:** Task 2 („Zusätzliche Persistenz nur für tatsächlich notwendige Intent-/Recoverybindungen“), Context-Absatz „Die Lösung bleibt klein“.

**Beleg:** Der Erstclone persistiert die Probe-OID in `control_operations.target_control_oid` vor dem ersten wirkenden Prozess (`ManagedCloneSynchronizer::stage()`, Phase `LAUNCH_INTENT`). Die Parameter von `managed_clone` enthalten keine OID (`ControlOperationType::PARAMETER_FIELDS`). Weil genau zwei Formate unterstützt werden, bestimmt die Länge dieser OID das vorläufige Format eindeutig.

**Vorschlag:** Im Ticket genau eine neue Spalte `projects.object_format` nennen (als `— new`-Artefakt der Migration) und den Satz über „zusätzliche Persistenz“ ersetzen durch: „Keine weitere Persistenz; das vorläufige Format eines Erstclone-Intents ist die Länge seiner `target_control_oid`.“ Das neue Enum aus dem Zielbild als neu einzuführende Klasse benennen.

**Erwogene Alternative:** ganz ohne Spalte, Format aus der Länge der gebundenen Control-OID ableiten. Das spart Backfill und Fixture-Umbau, ist aber impliziter und bei ausstehendem Branchwechsel (`control_oid = NULL`) auf `pending_control_oid` angewiesen. Nicht empfohlen.

---

## C. Vereinfachungen

### W09 — P2: Prüfprinzip „Grenze plus Gleichheit“ statt Formatdurchreichung

**Ticketbezug:** Task 1/3, Context-Zeilen zu Instruktionen und HumanLoop, `files` (Agents).

**Beleg:** Viele betroffene Klassen kennen kein Projekt: `InstructionSnapshotResolver` bekommt nur Kandidaten, `ReviewSubject`/`ReviewSubjectReference` sind Werteobjekte, die Gate-Bindungen parsen Zeichenketten, `HardenedGitRunner` kennt nur Pfade und OIDs. Jede Runner-Methode mit Ausgabeparser hat aber schon eine validierte Eingabe-OID: `listRunTreeEntries()`, `readRegularBlob()`, `listDirectTreeEntries()`, `planSingleFileMutation()`, `writeCandidateTree()`, `inspectSingleParentCommit()`.

**Vorschlag:** Im Ticket festschreiben:

- Wo Projekt oder Run ohnehin vorliegt (HTTP-Controller, Livewire-Seite, Queue-Actions, `RunOrchestrator`, Synchronizer, Refresher, Publish), prüft die Stelle gegen `projects.object_format`.
- Werteobjekte und Parser ohne Projektbezug prüfen nur die geschlossene Syntax: vollständige 40- oder 64-stellige Kleinbuchstaben-Hex-OID, gleiche Länge innerhalb eines Werts. Die Projektbindung entsteht an der verwendenden Grenze über Gleichheit mit bereits gebundenen Werten.
- Der Runner leitet Parser-, Objekt- und Sentinellänge aus seiner Eingabe-OID ab. Keine Formatabfrage je Git-Aufruf, keine neuen Formatparameter nur zum Durchreichen.

**Folgen:** `AgentResultValidator` (prüft `expected_blob_sha` schon auf 40 oder 64 und bindet über Gleichheit mit dem Snapshot-Blob) bleibt unverändert. Die M6-Fixtures mit 40-stelligen Instruktionsblobs bleiben gültig. Der Resolver behebt nebenbei, dass er heute echte SHA-256-Blobs abweist (`InstructionSnapshotResolver.php:38`, nur `{40}`). Falls stattdessen `ApprovalSnapshotFactory` die Bloblänge projektgebunden prüfen soll, entsteht der Fixture-Umbau aus W15; das ist abzuwägen.

### W10 — P2: Die Altbestandsregel auf eine Zeile reduzieren

**Ticketbezug:** Task 4 (sieben Altzustände), AC-03, TC-03 (verfeinert V08).

**Beleg:** Der alte Vertrag ließ an jeder Schreibstelle nur 64-stellige OIDs zu. Jede vorhandene OID ist daher SHA-256. Kein Codepfad setzt `control_oid` und `pending_control_oid` nach einer Bindung wieder beide auf `NULL` (Schreiber siehe W03).

**Vorschlag:** Task 4 ersetzen durch: „`object_format = 'sha256'` genau für Projekte mit `control_oid` oder `pending_control_oid`; alle anderen bleiben `NULL`.“ Das deckt alle sieben Fälle ab:

- Registrierung, provisioniert ohne Clone, früherer fehlgeschlagener SHA-1-Probe → `NULL`, Erstclone bleibt möglich.
- Bestätigte Bindung, ausstehender Branchwechsel → `sha256`.
- Laufender Erstclone-Intent → `NULL`; der Worker bestätigt beim Fortsetzen das Speicherformat (Task 2) und finalisiert dann.
- Historische und offene Runs, Approvals und Mutationsplatzhalter gehören zu gebundenen Projekten.

Einzige Konsistenzprüfung: nicht leere `control_oid`/`pending_control_oid` müssen 64-stellig sein, weil genau diese Spalten heute unbewacht sind. Sonst Abbruch mit Tabelle, Spalte und ID, ohne Wert. TC-03 schrumpft entsprechend auf vier Ausgangslagen (ungebunden, gebunden, nur ausstehend, laufender Intent) plus einen inkonsistenten Datensatz.

### W11 — P2: Die Rückbauprüfung auf zwei Bedingungen reduzieren

**Ticketbezug:** Task 7, AC-07, TC-07 („Projekt, Intent, Historie oder strukturierte Referenzen“; verfeinert V09).

**Vorschlag:** `down()` verweigert genau dann, wenn ein Projekt `sha1` trägt oder eine `managed_clone`-Operation eine 40-stellige `target_control_oid` hat. Jede andere OID gehört zu einem gebundenen Projekt, ein Scan von Historie und strukturierten Referenzen ist daher unnötig. Die Prüfung steht vor der ersten Änderung. Ein fehlgeschlagener SHA-1-Erstclone mit gespeichertem Intent verhindert den Rückbau ebenfalls; das ist gewollt und fail-safe.

### W12 — P3: Der erneute Clone ist der vorhandene Knopf „Clone starten“

**Ticketbezug:** Task 7, TC-07, MG-01 (verfeinert V14).

**Beleg:** `ProjectController` erzeugt je Seitenaufruf eine neue UUID (`'cloneOperationId' => (string) Str::uuid()`, `app/AI6/Projects/Http/ProjectController.php:81`). Die Projektseite zeigt „Clone starten“ für provisionierte Projekte ohne Control- und Pending-OID (`resources/views/projects/show.blade.php:174`). `QueueManagedCloneOperation` gibt bei bekannter ID nur den alten Auftrag zurück (Z. 104–110).

**Vorschlag:** In Task 7, TC-07 und MG-01 in einem Satz so benennen. „Neue Operations-ID“, „freie Sperre“ und „intakter Deploy-Key“ sind dann Nebenbedingungen des vorhandenen Wegs, keine eigenen Schritte.

### W13 — P2: Zielbild voranstellen, Texte entflechten

**Ticketbezug:** gesamtes Ticket.

**Vorschlag:** Das Zielbild aus der Leitlinie oben als ersten Absatz unter `## Tasks` aufnehmen. Danach lassen sich Task 2, Task 4 und AC-01 kürzen, weil sie auf das Zielbild verweisen können statt die Regeln in Nebensätzen zu wiederholen. Das Ticket soll für einen Menschen ohne Vorwissen in wenigen Minuten verständlich sein.

---

## D. Tests und Nachweise

### W14 — P2: Die richtigen Fixtures benennen

**Ticketbezug:** Task 6, Absatz „Bestehende Testanker“, TC-02/TC-04/TC-05/TC-06.

**Beleg:** Fest auf SHA-256 stehen `BuildsManagedControlRuntimeFixture` (Z. 47 und 58; echte Remotes für Clone/Fetch → gehört zu TC-02), `BuildsRunWorkspaceGitFixture` (Z. 130), `BuildsReviewOnlyRunFixture` (Z. 207) sowie Inline-Inits in `HardenedGitRunnerTest` (Z. 266, 320), `ReviewOnlyCheckpointIsolationTest`, `TicketReadModelRefreshTest` und `RunWorkspaceLifecycleTest`. Das Ticket nennt `BuildsRunWorkspaceGitFixture` „die vorhandene parametrisierbare Git-Fixture“; sie ist heute nicht parametrisierbar. `BuildsImplementationTurnFixture::initWorktreeGit()` hat einen Schalter, legt ohne `coherentGitBinding` aber ein SHA-1-Worktree mit künstlichen 64-stelligen Bindungen an (Z. 254 und 275).

**Vorschlag:** Je Fixture einen optionalen Formatparameter mit Vorgabe `sha256`; im Ticket je TC die zuständige Fixture nennen. Der nicht kohärente Pfad von `BuildsImplementationTurnFixture` zählt ausdrücklich nicht als SHA-1-Nachweis.

### W15 — P2: Umbau bestehender Tests einplanen und begrenzen

**Ticketbezug:** Task 6, TC-08.

**Beleg:**

- 41 direkte `control_oid`-Schreibvorgänge in 23 Testdateien. Mit dem Projektguard (W07) brauchen diese Projekte ein Format.
- `ControlOperationPersistenceTest.php:300` nutzt `str_repeat('c', 40)` nur als „anderen Wert“. Mit Guard scheitert schon das Speichern, und der Test träfe die Provenienzprüfung nicht mehr.
- `workspace_tree_hash => $run->checkpoint_tree_sha` in `BuildsObservedRunFixture.php:194`, `ReportOnlyCompletionExecutorTest.php:511` und `ReviewOnlyRunContractTest.php:726` (siehe W01).
- `PublishCandidateTest.php:251` berechnet selbst einen SHA-256-Blob.

**Vorschlag:** Das Format zentral in den vorhandenen Helfern setzen (`ControlOperationTestCase::registeredProject()`, `AuthFeatureTestCase::createProject()`, Fixture-Traits). Die rund 400 künstlichen 64-stelligen Testwerte der Form `str_repeat('a', 64)` (OIDs und Hashes) bleiben unverändert. Den Test in Z. 300 auf einen anderen 64-stelligen Wert umstellen. Keine pauschale Parametrisierung aller Tests.

### W16 — P2: Testebenen schärfen, Doppelungen vermeiden

**Ticketbezug:** TC-02, TC-05, TC-06 (verfeinert V11, V18, V19).

**Vorschlag:**

- Parser-Negativfälle (unbrauchbare Ausgabe, Mehrfachantwort, falsche oder per Tail-Matching mitgelieferte Ref, Zusatzinhalt, Null-OID, gemischte Längen) als Unit-Tests mit festen Ausgaben. Echte Linux-Git-Integration nur für Erfolgswege, Formatdrift (Probe 40, Repository `sha256` und umgekehrt) und Wiederaufnahme.
- SHA-256 ist durch bestehende Tests belegt. Neue Durchläufe braucht SHA-1; wo ein bestehender Test per Datenprovider beide Formate fahren kann, genügt das. Keine zweite Testimplementierung.
- Die Crash-Injection in TC-06 („nach Dateisystem-Publish vor DB-Finalisierung …“) betrifft die Clone-Finalisierung (`ManagedCloneSynchronizer::publishOutcome()` → `finalizeBinding()`), nicht den Runbranch-Push. Nach TC-02 verschieben, für einen SHA-1-Erstclone über `ControlOperationCrashInjectionTest`. Ist eine Crash-Naht im Publishpfad gemeint, sie dort gesondert benennen.
- Anker ergänzen: `PublishCandidateTest::test_the_final_commit_and_publish_intent_keep_the_exact_candidate_parent_and_remote_binding` für Publish; `ProviderArtifactExecutionTest::test_migration_roundtrip_preserves_legacy_artifacts_and_all_prior_guards` als vorhandenes Muster für `down()`/`up()` einer einzelnen Migration mit Altzeilen.

### W17 — P2: DB-Negativtests nicht aus der Migrationsliste ableiten

**Ticketbezug:** TC-03.

**Beleg:** Erzeugt der Test seine Fälle aus derselben Spaltenliste wie die Migration, fällt eine vergessene Spalte nie auf (tautologischer Nachweis).

**Vorschlag:** Der Test liest aus `sqlite_master` jede Spalte mit Längenprüfung und verlangt, dass sie im Test ausdrücklich als OID oder Prüfsumme eingeordnet ist; eine nicht eingeordnete Spalte lässt ihn scheitern. Je umgestelltem Trigger ein formatfremder Direktwrite; für Hashspalten je Tabelle eine Stichprobe mit 40 Zeichen.

### W18 — P2: AC-07 und TC-07 auf prüfbares Verhalten beschränken

**Ticketbezug:** AC-07, TC-07, Task 7.

**Beleg:** „Upgrade und Rückbau erfolgen … ohne konkurrierende Altversionswrites“ ist eine Betriebsvoraussetzung; die Software erzwingt sie nicht beobachtbar. Der TC-07-Satz „Den dokumentierten ruhenden Ablauf auf einer Wegwerfinstanz nachvollziehen“ ist weder eindeutig automatisiert noch ein manuelles Gate.

**Vorschlag:** AC-07 auf atomaren Abbruch, verweigerten Rückbau und wiederhergestellte Guards beschränken. Den ruhenden Ablauf in Task 7 als README-Inhalt fordern und über den vorhandenen Dokumentationstest belegen. Den TC-07-Satz streichen (durch TC-03/TC-07 abgedeckt) oder ausdrücklich in MG-01 aufnehmen.

---

## E. Scope, Gates, Dokumentation, Lesbarkeit

### W19 — P2: „Sensitive paths“ korrigieren, Migrationsfreigabe zeitlich festlegen

**Beleg:** Plan §8.2 nennt als sensible Kategorien Instruktions-, Ticket-, Migrations-, Abhängigkeits-, CI-, Deploy- und Authpfade sowie Löschungen. `app/AI6/Runs/` und `app/AI6/HumanLoop/` gehören nicht dazu. Als „Sensitive path“ gelesen, verlangen sie vor jeder Änderung eine menschliche Entscheidung — genau dort liegt aber ein Großteil der Arbeit.

**Vorschlag:** Die Grenzaussage („nur Format bestehender OID-Bindungen, keine Autorisierung, kein Anti-Replay, keine Gateentscheidung“) nach Review Focus oder Out of Scope verschieben. Beim Migrationseintrag den Zeitpunkt präzisieren: erst die Feld-/Guardliste aus Task 1 menschlich freigeben, dann den Triggerumbau schreiben, den Migrationscode vor dem Merge freigeben.

### W20 — P2: `files` und Scope korrigieren

**Beleg und Vorschlag:**

- **Ergänzen:** `tests/Feature/Shared/Runtime/RuntimeDocumentationTest.php` — er verlangt wörtlich „ausschließlich Remotes im SHA-256-Objektformat“ (Z. 138). Die README-Absätze Z. 608 (Probe, 64-stelliger OID, SHA-256-only, `AI6-006D/MG-01` mit SHA-256-Remote) und Z. 616 („exakten 64-stelligen SHA-256-OID“) müssen sich ändern. Außerdem `tests/Feature/Reviews/` (`FindingVerificationRoundTest`, `SecurityReviewIsolationTest`).
- **Streichen oder begründen:** `tests/Unit/Reviews/SecurityReviewContractTest.php` prüft nur die zulässigen Securitystatuswerte, keine OIDs. `tests/Feature/Checks/CheckRunnerTest.php`: Das Modul `Checks` verarbeitet keine Git-OIDs, `CheckRunner` nutzt durchgehend `CheckTreeBinding`; eine 40-Zeichen-Probe für Checker-Hashes gehört in den DB-Test aus W17. `app/AI6/Agents/AgentResultValidator.php` samt Test bleibt nach W09 unverändert.
- **Nur bei W22 (Anzeige):** `resources/views/projects/show.blade.php`.

### W21 — P2: AI6-Softwarecommits ausdrücklich ausnehmen

**Beleg:** `RecoveryEvidenceReference::bind()` (Z. 38–39) und `ControlOperationController::recover()` (Z. 167–168) akzeptieren für `evidence_commit`/`evidence_base_commit` bereits 40 oder 64 Stellen. Das Recoveryformular beschriftet das Feld mit „Geprüfter Git-Commit“ (`resources/views/projects/operation.blade.php:59`). Gemeint ist der geprüfte AI6-Softwarecommit, keine Projekt-OID.

**Vorschlag:** Im Ticket (Context oder Do Not Change) festhalten: Diese Felder bleiben unverändert und werden nicht an das Projektformat gebunden. Sonst liegt es nahe, sie „konsequent“ mitzubinden und damit Recoverynachweise für AI6 selbst zu brechen.

### W22 — P2: MG-01 praktisch durchführbar machen

**Ticketbezug:** MG-01, Context-Absatz 1.

**Vorschlag:**

- Die lokalen Laufzeitkorrekturen (`Dockerfile`, `config/ai6.php` samt Tests: fester PHP-CLI-Pfad unter Apache, Rechtereihenfolge im Image) sind nicht Teil des Auftrags, werden für einen lauffähigen Container aber vermutlich gebraucht. Voraussetzung ergänzen: vor MG-01 gesondert entschieden und committed. Sonst lässt sich kein sauberer Candidate prüfen, was MG-01 selbst verlangt.
- „Bestätigtes Format“ muss ohne Datenbankzugriff ablesbar sein: entweder eine Anzeigezeile im vorhandenen `<dl>` der Projektseite neben „Aktive Control-OID“ oder ausdrücklich „OID-Länge 40 bedeutet SHA-1“.
- Ticket- und Config-Refresh am realen Remote nur, wenn `refs/heads/neues_design_v1` eine Ticketdatei bzw. `.ai6/config.yaml` enthält. Fehlt die Config, belegt der Refresh nur den `absent`-Zweig (`ProjectConfigRefresher.php:69`). Sonst diesen Teil auf dem Wegwerfremote prüfen. Das ist vorab mit dem Menschen zu klären.
- Organisatorisch kann dieselbe Sitzung `AI6-006D/MG-01` (Clone/Fetch an realem Remote) und `AI6-006E/MG-01` (Branchwechsel) mit abdecken — mit getrennten Protokollen und Signaturen.

### W23 — P3: README auf formatbezogene Betriebsschritte begrenzen

**Beleg:** Die allgemeine Upgrade-Dokumentation liefert `AI6-036` (Status `todo`, AC-06), Backup und Restore liefert `AI6-049`.

**Vorschlag:** AI6-051 ergänzt nur im vorhandenen Managed-Clone-Abschnitt (README Z. 608/616): Rollen vor der `init`-Migration stoppen (die Rollen hängen zwar per `service_completed_successfully` an `init`, beim Neuerstellen können alte Container aber noch laufen, während `init` migriert — das ist beim Umsetzen einmal zu bestätigen), verweigerter Rückbau mit seiner Meldung, erneuter Clone über „Clone starten“. Kein allgemeines Upgradekapitel. Optional ein Satz: SQLite-Datei bei gestoppten Rollen vorher kopieren.

### W24 — P3: Kleine Textkorrekturen

- Goal-Absatz 2, Satz 3 („Versuchseigene Stagingeffekte …“) ist Implementierungsdetail und steht schon in AC-02 → streichen.
- AC-04 verweist auf „Task 5“; ACs sollten ohne Taskverweis lesbar sein.
- Context: Die Modulausnahme steht im Revisionseintrag V1.7.10 und in §21; der Text von §13.2 wurde nicht geändert. So zitieren.
- Formular: neben `pattern` auch `maxlength="64"` der drei OID-Felder formatabhängig machen.
- „parametrisierbare Git-Fixture“ → „zu parametrisierende“ (siehe W14).

---

## F. Plan- und Nachbarfragen (nicht in der Ticketdatei lösbar)

### W25 — P1 (Entscheidungsfrage): Einordnung vor dem Piloten

**Beleg:** AI6-051 steht in §14.1 an letzter Stelle (Nr. 57), und kein Blueprint hängt von ihm ab. Das gewählte Testprojekt ist SHA-1. Ist das Pilotprojekt von `AI6-038` ebenfalls SHA-1, kann der Pilot ohne AI6-051 nicht laufen. Außerdem verlangt die README heute, `AI6-006D/MG-01` mit einem SHA-256-Remote durchzuführen; mit AI6-051 wird dieser Satz hinfällig.

**Vorschlag:** Den Menschen fragen, ob AI6-051 in §14.1 vor `AI6-036`/`AI6-038` gehört und `AI6-038` es als Abhängigkeit bekommt, oder ob der Pilot ausdrücklich ein SHA-256-Remote nutzt. Das ist eine Planrevision, keine Ticketänderung.

### W26 — P3: Backlogansicht `tickets/README.md`

**Beleg:** Die neue Zeile für AI6-051 steht nach einer Leerzeile außerhalb der Tabelle (Z. 203/204) und wird nicht als Tabellenzeile dargestellt. „Stand der abgeleiteten Ansicht … Planrevision V1.7.9“ (Z. 106) ist veraltet.

**Vorschlag:** Leerzeile entfernen, Ableitungsstand auf V1.7.10 setzen. Reine Ansichtskorrektur, braucht aber wie jede Änderung unter `tickets/` eine Freigabe.

---

## Anhang A — Verifizierte OID-Inventur des aktiven Schemas

Methode: alle Migrationen bei HEAD in eine leere SQLite-Datei, dann `sqlite_master` und `PRAGMA table_info` ausgewertet. Ergebnis: 77 aktive Trigger, davon 24 mit OID-Längenprüfungen in 12 Tabellen; kein Trigger fragt `projects` ab. Semantik je Spalte am Code bestätigt, wo der Name mehrdeutig ist.

**OID-Spalten mit 64-Guard (43):**

| Tabelle | Spalten |
|---|---|
| `control_branch_audit_entries` | `old_control_oid`, `new_control_oid` |
| `control_operations` | `expected_control_commit`, `target_control_oid` |
| `findings` | `checkpoint_tree_sha` (zeroblob-GLOB) |
| `finding_statuses` | `checkpoint_tree_sha` (zeroblob-GLOB) |
| `project_config_drafts` | `control_commit`, `blob_sha` |
| `project_config_snapshots` | `control_commit`, `blob_sha` |
| `review_results` | `checkpoint_commit_sha`, `checkpoint_tree_sha`, `candidate_tree_sha`, `candidate_base_sha` |
| `run_gates` | `checkpoint_commit_sha`, `evidence_candidate_tree_sha` |
| `runs` | `claim_parent_control_sha`, `initial_run_base_sha`, `run_base_sha`, `checkpoint_commit_sha`, `checkpoint_tree_sha`, `ticket_blob_sha`, `confirmed_branch_publication_oid`, `review_subject_base_sha`, `review_subject_source_sha`, `candidate_tree_sha`, `candidate_base_sha`, `candidate_checkpoint_commit_sha`, `final_commit_oid`, `final_commit_tree_oid`, `final_commit_parent_oid`, `branch_publication_target_oid`, `branch_publication_expected_oid` (Null-Sentinel für fehlenden Runbranch) |
| `ticket_approvals` | `reviewed_ticket_blob_sha`, `reviewed_control_sha`, `approved_ticket_blob_sha`, `approved_control_sha` |
| `ticket_mutations` | `expected_ticket_blob_sha`, `expected_target_blob_sha`, `expected_target_tree_oid` (64-Nullen-Platzhalter), `prepared_commit_oid` |
| `ticket_read_models` | `control_commit`, `blob_sha` |

**OID-Spalten ohne Längenguard (10):** `projects.control_oid`, `projects.pending_control_oid`, `run_checkpoints.commit_sha`, `run_checkpoints.tree_sha`, `run_checkpoints.predecessor_commit_sha`, `finding_dispositions.checkpoint_tree_sha`, `ticket_approval_previews.reviewed_control_sha`, `ticket_approval_previews.reviewed_ticket_blob_sha`, `ticket_approvals.intended_commit_sha`, `human_requests.bound_checkpoint` (je nach Anfrage Checkpoint-Commit, Checkpoint-Tree oder Candidate-Tree; `HumanRequestService.php:143/426/523/611/681`).

**Ähnlich benannte Nicht-OIDs mit 64-Guard (unverändert lassen):** `check_results.tree_sha`, `check_results.result_tree_sha`, `review_results.workspace_tree_hash`, `runs.review_workspace_hash` (alle `CheckTreeBinding`), sämtliche `*_diff_hash`, `*_hash`, `*_sha256`, `fingerprint`, `digest`, `execution_id`.

**Nicht per DB geprüfte OIDs in JSON oder Text:** `operation_parameters_jcs` (`old_control_oid`, `pending_control_oid`), Approval-Snapshot (Instruktions-`blob_sha`), `ReviewSubjectReference` (`base_oid`, `source_oid`, `tree_oid`), `human_requests.bound_requested_effect` der Gatearten, Metadaten des Checkpoint-Diffartefakts (`from_oid`, `to_oid`).

## Anhang B — Lokal geprüftes Git-Verhalten (Git 2.33.0.windows.1)

| Prüfung | SHA-1-Repository | SHA-256-Repository |
|---|---|---|
| `rev-parse --show-object-format=storage` | `sha1` | `sha256` |
| `update-ref <ref> <neu> ""` auf fehlende Ref | erfolgreich | erfolgreich |
| dasselbe auf vorhandene Ref | abgelehnt („reference already exists“) | abgelehnt |
| `update-ref <ref> <neu> <64 Nullen>` | **scheitert** („not a valid old SHA1“) | erfolgreich |
| `update-ref <ref> <neu> <40 Nullen>` | erfolgreich | scheitert |
| `push --force-with-lease=<ref>:` auf fehlende Remote-Ref | erfolgreich | erfolgreich |
| dasselbe auf vorhandene Remote-Ref | abgelehnt („stale info“) | nicht separat geprüft |
| `push --force-with-lease=<ref>:<64 Nullen>` | **scheitert** („cannot parse expected object name“) | erfolgreich |
| `ls-remote --refs --exit-code` außerhalb eines Repositorys | 40-stellige OID | 64-stellige OID |
| `ls-remote` auf fehlende Ref | Exitcode 2 | Exitcode 2 |
| `ls-remote … refs/heads/ai6/runs/x` bei zusätzlicher Ref `refs/foo/refs/heads/ai6/runs/x` | beide Zeilen, fremde Ref zuerst | nicht separat geprüft |

Diese Ergebnisse gelten für die lokale Windows-Version. Vor Übernahme von W05 (leeres Argument) und für TC-02 sind sie mit dem Git des Linux-Images zu wiederholen.

## Anhang C — Was ausdrücklich klein bleiben soll

- Ein Enum, eine Spalte, eine Migration; keine Formatspalten in weiteren Tabellen, keine Formatfelder in signierten Payloads oder Requesthashes.
- Keine Formatparameter durch Signaturen reichen, die ein Projekt nicht brauchen (W09); keine Formatabfrage je Git-Aufruf.
- Keine neuen Guards für heute unbewachte OID-Spalten; Hashguards unverändert.
- Keine Altbestandsmatrix über sieben Fälle, wenn eine Regel sie abdeckt (W10); kein Tabellenscan für den Rückbau (W11).
- Kein neuer Retrycommand, keine Adminseite; „Clone starten“ genügt (W12).
- Keine neue Testfactory, keine Verdopplung der SHA-256-Tests, keine Browser-Testinfrastruktur.
- Kein allgemeines Upgradekapitel, kein Backupprodukt (W23).

## Arbeitsauftrag für das kritisch prüfende LLM

> Prüfe jeden Punkt W01–W26 am aktuellen Ticket, am Plan V1.7.10 und am realen Code; die Zeilenangaben beziehen sich auf HEAD `399076a`. Entscheide je Punkt: übernehmen, präzisieren/zusammenführen oder verwerfen — mit kurzer Begründung und bei Abweichung mit Gegenbeleg. Vorrang haben die belegten Lücken W01–W06 und die beiden Entscheidungen W07/W08; W07 und W25 sind menschliche Entscheidungen, die du vorbereitest, aber nicht selbst triffst. Ziehe Vereinfachungen (W09–W12) einer zusätzlichen Regel vor; übernimm keine neue Abstraktion, Persistenz, Testebene oder Gatestufe ohne konkret benannten Bedarf. Eine bereits enthaltene Anforderung wird durch Anker und gezielten Test präzisiert, nicht als weiteres AC dupliziert. Behalte die IDs AC-01–AC-08, TC-01–TC-08 und MG-01, weil die Reviewdokumente sie zitieren; hänge neue Einträge nur an. Status, Gateergebnisse, Plan und `AGENTS.md` bleiben unverändert; W25 und W26 betreffen Dateien außerhalb des Tickets und brauchen eine eigene Freigabe. Die Tests und Git-Beobachtungen dieser Liste sind Hinweise, keine bestandene Evidenz; prüfe nach der Überarbeitung das Ticket erneut mit dem realen `TicketV1Parser`/`Ai6DetailV1TicketValidator`.
