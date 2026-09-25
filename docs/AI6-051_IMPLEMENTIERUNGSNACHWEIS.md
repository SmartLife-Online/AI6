# AI6-051 — Implementierungs- und Prüfstand

Arbeitsstand vom 26. September 2026 auf Basis `4ed8927` (`Rebase AI6-051`), ohne Commit oder Deployment. Dieser Bericht enthält keine Statusfreigabe und keine manuelle Abnahme. Bereits vorhandene Nutzeränderungen an `.gitignore` und `AGENTS.md` gehören nicht zur Implementierung.

Git-OIDs verwenden einen zentralen Vertrag für SHA-1 und SHA-256. Das Projektformat wird beim ersten bestätigten Clone zusammen mit der Control-Bindung finalisiert und bleibt danach unveränderlich. Eine neue Migration passt die bestehenden OID-Guards an; AI6-Prüfsummen und historische Payloadbytes behalten ihren SHA-256-Vertrag.

## Konfigurationsgebundene Prozessregression vom 26. September 2026

Das Finding ist berechtigt: `BlockedControlProcessTest` und `EffectLockRuntimeSecurityTest` erbten von `PHPUnit\Framework\TestCase`, obwohl `ControlProcessRunner` für die Rollenentscheidung den Laravel-Config-Container benötigt. Beide Klassen verwenden jetzt wie `ControlProcessRunnerTest` die bestehende Basis `Tests\TestCase`. Der Diff der beiden Testdateien ändert ausschließlich diesen Import. Testkörper, Assertions, Timeouts, Lockfixtures, Prozesszahlgrenzen und Gruppenkontrollen bleiben bytegleich; Änderungen an Produktcode oder `AGENTS.md` waren nicht erforderlich. Der konkrete Reviewauftrag erlaubt die beiden Ergänzungen in `files` und im initialen Scope von `tickets/AI6-051.md`; diese Freigabe ist dort mit Datum dokumentiert.

Die beiden Klassen enthalten zusammen neun Tests. Sechs Fälle aus `BlockedControlProcessTest` und einer aus `EffectLockRuntimeSecurityTest` gehörten zu den sieben gespeicherten Fehlern `Target class [config] does not exist.`; zwei weitere Fälle waren bereits ohne diesen Fehler. Der neue Linux-Lauf führt beide Klassen vollständig sowie die unveränderten benachbarten Regressionen aus:

| Prüfung | Tests | Assertions | Fehler / Skips |
|---|---:|---:|---|
| `BlockedControlProcessTest` | 7 | 88 | 0 / 0 |
| `EffectLockRuntimeSecurityTest` | 2 | 38 | 0 / 0 |
| `ControlProcessRunnerTest` | 19 | 127 | 0 / 0 |
| `ProcessPolicyAndLimitTest` | 5 | 23 | 0 / 0 |
| `ProcessArchitectureTest` | 2 | 2 | 0 / 0 |
| **Linux gesamt** | **35** | **278** | **0 / 0** |

Exitcode 0, Konsolenlaufzeit 12,90 Sekunden. Die Konsolendarstellung meldet wegen vorhandener Warnungen zur fehlenden optionalen `tests/.env` 33 Warnungsfälle und zwei bestandene Fälle; sämtliche 35 JUnit-Fälle sind ohne Fehler oder Skip. Erreicht sind die PID-Bindung nach `exec`, die Freigabe blockierter Prozesse, unveränderte Nutzdaten ohne Wrapperpräfix, direkte und blockierte Lockserialisierung, vollständiger Gruppenabbruch einschließlich TERM-ignorierendem Kind, Lockfreigabe nach SIGKILL, unveränderliche Lockobjekte und die beiden bewusst unsicheren Gegenbeispiele. Die neue Warte-/Präfixlogik zeigt dabei keine Abweichung.

Der Nachweis läuft im unveränderten PHP-8.5.5-/Git-2.39.5-Testimage als UID 10001 mit `network=none`, zwei CPUs, 2 GiB Speicher, 512 PIDs, `no-new-privileges` und dem unveränderten Repository-Seccomp-Profil. Die Quellen sind schreibgeschützt, die Lockfixtures root-eigen; nur eigene Eingaben, Ergebnisse und temporäre Dateien sind eingebunden. Alle 1.213 übertragenen Quelldateien stimmen nach dem Lauf mit ihrem Manifest überein. Es wurden keine Betriebsvolumes oder echten Credentials verwendet.

Die lokalen Struktur-, Prozess- und Release-Inventare bestehen mit 33 Tests und 1.009 Assertions. Pint, PHPStan, Manifestprüfung und `git diff --check` sind grün; der Detailticketvalidator bestätigt keine Fehler sowie unverändert `ready`, AC-01–AC-08, TC-01–TC-08 und MG-01. Ein anfänglicher Inventaraufruf mit falschem Testpfad bleibt als Aufruffehler im Rohbeleg erhalten; der korrigierte Aufruf lief vollständig. Für diese reine Testbasisänderung wurde die vollständige Suite gemäß `AGENTS.md` §4 nicht erneut ausgeführt. Der letzte vollständige Linux-Lauf bleibt der historische rote Nachweis vom 25. September; es wird kein neues grünes Gesamtgate behauptet.

**Aktuelle Baseline-/DoD-Abgrenzung:** Alle sieben konkreten früheren Fehlerfälle sind anhand ihrer vollständigen Fallnamen mit den jetzt bestandenen Fällen abgeglichen. Sie sind aus der offenen Liste und der unten aktualisierten DoD-Entscheidungsvorlage entfernt. Es verbleiben **96** zuvor gemeinsam nachgewiesene Baselinefälle; diese Zahl ist die um sieben behobene Fälle bereinigte Bestandsliste, kein neuer vollständiger Suitebefund. Die gesonderte menschliche Entscheidung zu diesen übrigen Fällen bleibt offen. Frühere Rohbelege und vollständige Laufzahlen werden nicht rückwirkend verändert.

Die Rohbelege liegen außerhalb von Git unter `storage/app/private/ai6-051-review/review5/`. `verified-results.json` prüft Ergebnisse, unveränderte Assertions, Laufzeitisolation und Quellbindung; `resolved-baseline-failures.json` bindet die sieben vorherigen Meldungen an die jetzigen Assertionzahlen. `remaining-baseline-failures.json` enthält nur noch die 96 offenen Fälle. Nach Sicherung und Prüfung der Belege wurden der exakt identifizierte Wegwerfcontainer und das eigene aufgelöste Testverzeichnis entfernt; ihre Abwesenheit ist bestätigt.

| Rohbeleg | SHA-256 |
|---|---|
| `current.tar.gz` | `d22e1057d556b10e7b28f70d9c7326a1b88f5bb012e4cb797e2c43aed48b782a` |
| `results.tar.gz` | `6f0e36faa084cc09736e802d3cf72e993fc67556cf67598d391344dc1199670f` |
| `targeted.xml` im Ergebnisarchiv | `4236434ae0332c2571e4bc8686bd5db72c365336695b7db9c911d42738f64a43` |
| `remaining-baseline-failures.json` | `2cfa10af981b7b7f24f77c7fe3b158e4ce3d294d9dd75b16d7b535aa4223c5a9` |

**Nicht gefixte Findings dieses Reviewauftrags: Keine.** Die gesonderte DoD-Entscheidung für die übrigen 96 Bestandsfälle und die manuellen Gates bleiben offen.

## Freigegebene Prozessstartkorrektur vom 25. September 2026 — vorheriger Prüfzyklus

Der Mensch hat die konkretisierte Shared-Scope-Erweiterung mit „Freigabe hiermit erteilt.“ ausdrücklich beauftragt. `tickets/AI6-051.md` nennt jetzt die drei betroffenen Prozessdateien und den vorhandenen Regressionstest in `files` und im initialen Scope. Der Status bleibt `ready`; Kriterien-IDs, manuelle Gates und die gesonderte Saga-/Recorded-Scope-Vertragsentscheidung bleiben unverändert. Dieser Abschnitt ersetzt die darunter dokumentierte zuvor offene PID-Scopeanfrage.

### PID und verbleibende Prozessgruppe

Der vertrauenswürdige Direct-Wrapper schreibt vor `exec` genau ein `__AI6_PROCESS_STARTED_V1__:<PID>`-Präfix. Der Runner liest und validiert diese erste Zeile auch dann, wenn Symfony den Elternprozess bereits als beendet meldet und keine laufende PID mehr liefert. Eine noch beobachtbare PID muss übereinstimmen; fehlende, ungültige oder nicht darstellbare Kennungen werden weiterhin geschlossen abgewiesen. `processId` bleibt eine echte, nicht optionale Ganzzahl. Es gibt weder Ersatz-PID noch einen Erfolgspfad ohne Prozessidentität.

`discardOutputPrefixBytes` entfernt ausschließlich die Wrapperzeile aus dem Ergebnis und aus Beobachterausgaben. Nutzdaten, die selbst wie eine solche Zeile aussehen, bleiben erhalten. Die regulären Ausgabe-, Ressourcen- und Redaktionsprüfungen entscheiden weiterhin über das Ergebnis, einschließlich bereits beendeter Prozesse und der exakten Ausgabegrenze.

`RunningControlProcess` überwacht nach dem Ende des Elternprozesses verbleibende ausführbare Mitglieder der gebundenen Prozessgruppe weiter. Die bestehende Prozesszählung bleibt an dieselbe PID gebunden und zählt auch weiterhin vorhandene beendete Gruppenmitglieder; nur die Laufenderkennung unterscheidet diese von noch ausführbaren Prozessen. Ressourcenprüfung, Laufzeitgrenze und Gruppenabbruch bleiben wirksam. Auch eine erst bei der abschließenden Ressourcenprüfung erkannte Überschreitung löst die Gruppenbeendigung aus. Nach bestätigtem Abschluss wird die Gruppe nicht bei einem späteren Aufruf erneut als aktiv behandelt.

### Deterministische Regression und identische Lastmessung

Die neuen Tests warten den Elternprozess ausdrücklich vollständig ab und bestätigen zunächst `getPid() === null`. Erst dann übernimmt derselbe private Übernahmepfad, den `start()` verwendet, den real ausgeführten Prozess. Geprüft werden Exitcode 0, Exitcode 7 mit redigiertem Fehlertext, genau 64 erlaubte Ausgabebytes, 65 verbotene Bytes sowie Nutzdaten mit protokollähnlichem Inhalt. Vier weitere Fälle verweigern fehlende, nullwertige, negative und überlaufende Kennungen.

Die beiden Hintergrundkindfälle verwenden reale, TERM-ignorierende Kinder in der durch `setsid` gebundenen Gruppe. Nach dem bestätigten Elternende sind zwei überlebende Kinder bei einer Prozessgrenze von eins weiterhin verboten; ein einzelnes verbleibendes Kind kann weiterhin als Gruppe abgebrochen werden. Beide Fälle prüfen die echte PID des per `exec` gestarteten Elternprozesses und anschließend den beendeten Zustand jedes Kindes. Der Test räumt seine Gruppe auch bei einem absichtlich roten Gegenbeweis auf.

| Prüfung | Ergebnis |
|---|---|
| Neue deterministische Linux-Fälle | 11 Fälle, 89 Assertions, keine Fehler oder Skips |
| Gesamte Prozess-/Policy-/Architekturregression | 26 Fälle, 152 Assertions, keine Fehler oder Skips, 10,89 Sekunden |
| Alter Null-PID-Fehler wieder eingesetzt | Alle fünf Abschlussfälle werden rot, Exitcode 2 |
| Gruppen-KILL entfernt | Beide Hintergrundkindfälle werden rot, Exitcode 1 |
| Prozesszählung durch eins ersetzt | Der Prozesszahl-Grenzfall wird rot, Exitcode 1 |
| Identische Lastmessung am Produktcode | 4 × 5.000 Aufrufe mit zwei CPU-Lastprozessen: **20.000 `succeeded`, Exitcode 0, keine Fehlklassifikation**; 64,51–65,09 Sekunden je Messprozess |
| Lokale Prozessregression | 12 bestanden, 54 Assertions; 14 POSIX-Fälle ausdrücklich übersprungen |
| Struktur-/Prozess-/Schreibinventare | 33 bestanden, 1.009 Assertions |
| Pint, PHPStan, Manifest, Composer-Validierung/Plattform, Diffprüfung | Grün |

Die Negativkontrollen liefen über gesonderte Diagnosekopien; die Produktquellen blieben unverändert. Die vorhandenen Linux-Warnungen zur fehlenden optionalen `tests/.env` bleiben sichtbar. Gegenüber der identischen Messung vor dem Fix sinkt die beobachtete Fehlklassifikation von 25/20.000 (0,125 %) auf 0/20.000. Dies belegt die konkrete Race-Korrektur; frühere einzelne Fehler ohne damalige Ursachenkette werden dadurch nicht rückwirkend zugeordnet.

Das vollständige Linux-Gate am selben Quellstand ist abgeschlossen: **2.066 Tests, 72.468 Assertions, 94 Assertionfehler, 9 Fehler und 14 Skips, Exitcode 2**, Konsolenlaufzeit 1.981,90 Sekunden. JUnit führt 1.949 Fälle ohne Fehler oder Skip. Die Konsolendarstellung mit ihren überlappenden Warnungs-/Skipzählungen und sämtliche Warnungen bleiben im Rohbeleg erhalten; das vollständige Gate ist rot.

Der Vergleich mit den 103 gemeinsamen Fehlerfällen der wiederholten Baselineprüfungen an `4ed8927` ergibt **genau dieselben 103 Fälle und keinen zusätzlichen Fehlerfall**. Auch die führenden Fehlermeldungen stimmen überein: Acht rohe Abweichungen beschränken sich auf variable Test-UUIDs, Zeitstempel beziehungsweise Laufzeitfingerprints. Die zuvor sporadisch roten Clone-, Finalisierungs- und Security-Candidate-Fälle sind in diesem vollständigen Lauf ohne Fehler. Eine rückwirkende Ursachenbehauptung für diese früheren Einzelereignisse wird daraus nicht abgeleitet.

| Im vollständigen Linux-Gate enthaltene Regression | Tests / Assertions | Fehler / Skips |
|---|---|---|
| `ControlProcessRunnerTest` | 19 / 127 | 0 / 0 |
| `ProcessPolicyAndLimitTest` | 5 / 23 | 0 / 0 |
| `ReportOnlyCompletionExecutorTest` | 5 / 224 | 0 / 0 |
| `SecurityReviewCandidateIsolationTest` | 1 / 263 | 0 / 0 |
| `RunFinalizationStepTest` | 19 / 3.631 | 0 / 0 |
| `TicketMutationExecutorTest` | 18 / 889 | 0 / 0 |

Die Rohbelege liegen außerhalb von Git unter `storage/app/private/ai6-051-review/review4/`. `current-manifest.json` bindet den übertragenen Quellstand. `verified-pre-gate.json` prüft alle Lastzähler, elf neue Fälle und das Anschlagen der drei Gegenproben; `verified-full.json` und `normalized-baseline-comparison.json` dokumentieren den vollständigen Lauf und den Baselinevergleich.

Nach allen Prüfungen stimmen sämtliche 1.211 übertragenen Quelldateien im Container weiterhin mit dem Manifest überein. Nach Sicherung der Rohbelege wurden ausschließlich der anhand seiner exakten ID und Kennzeichnung geprüfte Wegwerfcontainer sowie das eigene, exakt aufgelöste Testverzeichnis entfernt; ihre Abwesenheit ist bestätigt. Die Umsetzung bleibt uncommittet. Dieser nachträglich vervollständigte Bericht verändert keine geprüften Produkt- oder Testbytes.

| Rohbeleg | SHA-256 |
|---|---|
| `current.tar.gz` / identisches `initial-source.tar.gz` | `0dd14ffb80ba994cd5a2e8a055138a6d7af16559b1bcbdef409c19656dc0af50` |
| `pre-gate-results.tar.gz` | `97ee311cabac7f48541b2cebfca68cc81bf46074585fa0ee8288c33066e6c903` |
| `focused.xml` im Ergebnisarchiv | `c80cea124bf4e3d5d32857dcad156dec5893c9729ef5355c1b548cfc6437bef9` |
| `full-results.tar.gz` | `1fbf6e341fccc89ce68be1fa1acbbe2e7e8244ac4292934ca02a561de4b57050` |
| `full.xml` im Ergebnisarchiv | `9c42a620a614d73a551b2558618ae88c12a34c36a8116587b536fcdd22643130` |

### Nicht gefixte Findings

- **Mittel — TC-08/DoD und vorbestehende Linux-Fehler:** Die verlangte Fehlerdiagnostik, die wiederholten Baselinevergleiche sowie die bestätigte Prozessstartkorrektur einschließlich Lastmessung und vollständigem Linux-Gate sind umgesetzt. Am Ende dieses Prüfzyklus bestanden noch 103 gemeinsame Baselinefehler; sieben davon sind im oben dokumentierten Folgeauftrag vom 26. September behoben. Die menschliche DoD-Entscheidung betrifft jetzt die übrigen 96 Fälle. Die Shared-Scopefreigabe ersetzt diese Entscheidung nicht. TC-08/DoD bleibt deshalb offen; Status und manuelle Gateergebnisse bleiben unverändert. Das PID-/Prozessgruppenfinding selbst ist vollständig behoben und geprüft.

## Prozessstart-Race und Candidate-Diagnose vom 25. September 2026 — vorheriger Prüfzyklus

Beide damaligen Findings sind berechtigt. Zu diesem früheren Prüfzeitpunkt war die Candidate-Diagnose behoben und die Shared-Scope-Freigabe noch offen. Die oben dokumentierte ausdrückliche Freigabe und Korrektur ersetzen diesen damaligen Stand; die folgenden Rohbelege bleiben als Historie erhalten.

### Bestätigte Race Condition und offene Scopeanfrage

Im unveränderten Baseline-Testcontainer wurden unter zwei parallelen CPU-Lastprozessen vier PHP-Testprozesse mit jeweils 5.000 Aufrufen von `ControlProcessRunner::run()` ausgeführt, jeweils mit einem `ProcessRequest` für `['/usr/bin/true']`. Alle Prozesse blieben innerhalb derselben Containergrenzen: zwei CPUs, 2 GiB Arbeitsspeicher, 512 PIDs, kein Netzwerk, unverändertes Seccomp-Profil, keine Betriebsvolumes oder echten Credentials. Laufzeit der vier Messprozesse: 80,08–80,40 Sekunden.

**25 von 20.000 Aufrufen lieferten fälschlich `start_failed` statt `succeeded`: 0,125 %.** Die Verteilung war 3/7/7/8. In jedem dieser 25 Fälle war der Kindprozess vor dem bisherigen `stop(0)` bereits beendet und sein Exitcode war 0. Symfony liefert für solche Prozesse bei `getPid()` tatsächlich `null`. Der Runner ist nach Normalisierung der Zeilenenden identisch zu `4ed8927`.

Die Messung nutzte eine gesonderte, über den Autoloader eingebundene Kopie des Runners. Ausschließlich im bereits erreichten Null-PID-Zweig wurde vor `stop(0)` der Zustand beobachtet; Ausnahme und Ergebnis blieben unverändert. Erfasst wurden nur Messprozess-ID, laufender Zustand, Exitcode und die feste Ausnahmemeldung. Das ist eine instrumentierte Reproduktion, kein unveränderter vollständiger Baseline-Gatelauf. Der Befund belegt den Fehler im gemeinsamen Prozessrunner; er ordnet frühere einzelne Clone-/Review-/Security-Ausfälle ohne damalige Ursachenkette nicht rückwirkend diesem Fehler zu.

**Scopeanfrage vom 25. September 2026:** Freigabe für `app/AI6/Shared/Process/ControlProcessRunner.php`, erforderlichenfalls `RunningControlProcess.php`, zugehörige Regressionstests und die Ticketdokumentation. Ein bereits beendeter Prozess mit bekanntem Exitcode soll den regulären Ergebnispfad verwenden, einschließlich finaler Ausgabe-/Ressourcengrenzen und Redaktion; ein fehlender PID ohne abgeschlossenes Ergebnis bleibt ein Fehler. Vorgesehen sind deterministische Fälle für erfolgreichen und erfolglosen schnellen Abschluss sowie fortgeltende Ausgabegrenzen, danach dieselbe Lastmessung und das vollständige Linux-Gate. Die Frage ist gestellt, eine Antwort liegt bisher nicht vor. Deshalb wurden Shared-Produktcode und dessen Ticket-Files-Scope noch nicht erweitert. Weder ein PID-Fix noch ein vollständiges Gate nach diesem Fix wird behauptet.

### Erhaltene und redigierte Candidate-Ursache

`PublishCandidateException` übernimmt optional `?Throwable $previous`; `PublishCandidateService` reicht die tatsächlich gefangene Ausnahme weiter. `reason`, Exceptionmeldung und Laufentscheidung bleiben unverändert. Da `RunFinalizationStep` diese Ausnahme vor der Fixture-Assertion konsumiert, protokolliert dieser Abfangpunkt zusätzlich `cause_class` und die zentral redigierte `cause_message`, gebunden an die Run-ID. Ungültiges UTF-8 erhält einen wertfreien Ersatztext; keine rohe Exception, Traceargumente oder Secrets werden zusätzlich protokolliert. `BuildsSecurityReviewFixture` übernimmt genau dieses Ereignis des betroffenen Runs in die weiterhin unveränderte Statusassertion.

Ein echter, gezielt fehlschlagender Git-`read-tree` prüft die Ursachenkette. Zwei Finalisierungsfälle prüfen Secret-Redaktion und ungültiges UTF-8 zusammen mit unverändertem `candidate_generation_failed`, Jobstatus `failed`, Runstatus `failed` und fehlender Candidate-Bindung. Die isolierten Negativkontrollen entfernen einmal die Ursachenweitergabe und lösen einmal in der Security-Fixture eine synthetische Candidate-Ausnahme aus: Beide schlagen wie erwartet fehl. Die zweite Assertion enthält `RuntimeException` und `secret=[REDACTED:SECRET]`, nicht den synthetischen Secretwert. Diese Kontrollen liefen mit getrennten Diagnosekopien; die Produktquellen des Containers blieben unverändert.

| Prüfung | Ergebnis |
|---|---|
| Neue Linux-Diagnosefälle | 3 Fälle, 264 Assertions, keine Fehler, 8,19 Sekunden |
| Vollständige Klassen `PublishCandidateTest`, `SecurityReviewCandidateIsolationTest`, `SecurityReviewIsolationTest`, `RunFinalizationStepTest` unter Linux | 40 Fälle, 5.269 Assertions, keine Fehler oder Skips, 149,14 Sekunden |
| Ursachenassertion ohne Weitergabe / Fixture mit erzwungener Ursache | Je ein erwarteter Fehler; ursprüngliche Ursache beziehungsweise redigierter Assertiontext nachgewiesen |
| Neue lokale Finalisierungsfälle | 2 bestanden, 226 Assertions |
| Struktur-/Prozessinventar und Release-/Schreibinventar | 24 + 9 bestanden, 329 + 680 Assertions |
| Pint, PHPStan, Manifest, Composer-Validierung/Plattform, `git diff --check` | Grün |

Die Linux-Warnungen zur fehlenden optionalen `tests/.env` bleiben sichtbar. Das vollständige Linux-Gate wurde in dieser Iteration noch nicht wiederholt: Der dafür vorgesehene PID-Fix wartet auf seine Scopefreigabe. Die früheren roten Gateläufe und die offene menschliche DoD-Entscheidung werden dadurch nicht geschlossen.

Die Rohbelege und Diagnosequellen liegen unter `storage/app/private/ai6-051-review/review3/` außerhalb von Git. `verified-results.json` prüft Zählung, konkrete Assertiontexte und die Hashbindung aller sechs in dieser Iteration geänderten Produkt-/Testdateien.

Nach Sicherung der Rohbelege wurden alle 1.211 aktuellen und 1.199 Baseline-Quelldateien im Container gegen ihre Manifeste geprüft: keine Abweichung. Ausschließlich die zwei anhand ihrer IDs und Kennzeichnung geprüften Wegwerfcontainer und das eigene, exakt aufgelöste Testverzeichnis wurden anschließend entfernt; ihre Abwesenheit ist bestätigt. Bereits vorhandene beziehungsweise parallel hinzugekommene Änderungen am Plan, an `docs/AI6-052_PROMPT_TOOLS_ENTSCHEIDUNGSANTRAG.md`, `tickets/AI6-052.md`, `tickets/README.md`, Manifest und Manifestgenerator gehören nicht zu dieser Reviewkorrektur. Die damalige Dateitabelle erfasste 90 AI6-051-Pfade; diese parallelen Änderungen wurden nicht überschrieben oder dieser Prüfung zugerechnet. Der oben genannte Manifestcheck beschreibt seinen Ausführungszeitpunkt vor diesen späteren Änderungen.

| Rohbeleg | SHA-256 |
|---|---|
| `diagnostic-fix-source.tar.gz` | `8c863c1b0a6bf7b9b76d9ed7728363a885352e55f93cf63a1f946e63c675da6a` |
| `stress-before-results.tar.gz` | `4e8991c868fd4d940da6c5c32e5295e822805ee6f98c43d39f74137e2619c32c` |
| `candidate-results.tar.gz` | `4655e6c0b9b3affa826c63d685d95c6e4c4ae9a9ec51cd40b66457d8e08c50cb` |
| `candidate-regression.xml` im Ergebnisarchiv | `592046892f009c52cfc3e503744914525dab6ba3b7dfdf8193c02245b9a219a5` |

### Nicht gefixte Findings

- **Mittel — schneller Prozessabschluss ohne PID:** Ursache mit 25 Fehlklassifikationen bei 20.000 Aufrufen bestätigt. Der Fix einschließlich deterministischem Regressionstest, anschließender Lastmessung und vollständigem Linux-Gate ist noch offen, weil die vom Finding ausdrücklich verlangte Shared-Scope-Freigabe angefragt, aber noch nicht erteilt wurde. Die konkrete Scopeanfrage steht auch im Ticket. Das niedrige Finding zur verlorenen Candidate-Ursache ist vollständig behoben und geprüft.

## Erneute Reviewkorrektur vom 25. September 2026 — vorheriger Prüfzyklus

Die vier damaligen zusätzlichen Findings sind berechtigt. Dieser Abschnitt dokumentiert den vorherigen Prüfzyklus; dessen damals offene Ursachendiagnose wird durch den neueren Abschnitt oben ergänzt.

### Abschlussfixtures und wertfreie Fehlerdiagnostik

Beide Helfer von `ReportOnlyCompletionExecutorTest` reihen die fertige Freigabe über `ApprovalQueue::enqueue()` ein, bevor `ApprovalClaimStarter::start()` aufgerufen wird. Der dadurch erreichte Testverlauf zeigte außerdem zwei veraltete Testannahmen: Die Implementierungsprüfung las `prepared_commit_oid` aus dem vor dem Worker geladenen Modell; sie aktualisiert es jetzt vor dem Vergleich. Die Replayprüfung erwartet jetzt exakt `The approval is not eligible: approval_not_queued` aus der vorhandenen Eligibility-Prüfung statt der alten Meldung. Weder die erwartete Ablehnung noch der Nachweis genau einer Runlinie wurde gelockert. Der Implementierungsfall vergleicht zusätzlich die veröffentlichten Ticketbytes mit den validierten Zielbytes und deren SHA-256 mit `recorded_scope_sha256`.

**Linux: alle fünf Abschlussfälle ohne Fehler oder Skips, 224 Assertions, Exitcode 0, 20,21 Sekunden Konsolenlaufzeit.** Erreicht sind Implementierungsabschluss mit Recorded Scope und freigegebener Runsperre, Crash an jeder Statusphase, Reconciler-Redelivery sowie beide Varianten eines fremden Control-Commits. Die vorhandenen Warnungen zur fehlenden optionalen Test-`.env` bleiben sichtbar. Diese fünf Fälle werden im korrigierten Stand nicht mehr als offene Baselinefehler geführt. Der vorausgehende gezielte Lauf bleibt mit seinen zwei erst nach Queue-Aufnahme sichtbaren Fixturefehlern erhalten; die übrigen 48 Fälle waren dort ohne Fehler.

`TicketMutationExecutorTest::recoveryMutationFixture()` prüft den frischen Clone-Endzustand auch beim Werfen einer Ausnahme und gibt den bereits zentral redigierten `last_error` aus. Die Review-/Fix-Assertions in `RunFinalizationStepTest` nennen den jeweiligen `failure_code`; die Security-Assertion nennt zusätzlich `failure_code` und `why_needed` mit dem serverseitigen technischen Grundcode. Keine Assertions, Timeouts, Sicherheitskontrollen oder Laufzeitgrenzen wurden gelockert. Die zwei dadurch geänderten Audit-Kontexte behalten exakt ihre bisherigen Schreibausdrücke, Ziele, Bewertungen und Begründungen.

Die Nachweise dieser Iteration liegen dauerhaft unter `storage/app/private/ai6-051-review/review2/`, außerhalb von Git. Das Quellarchiv des korrigierten Linux-Laufs ist `current.tar.gz` mit SHA-256 `e9618d674a1770ae4fe555ce3434f977b2f40dcb1a12292cdcdd0b049d864559`; Einzeldateien sind in `current-manifest.json` gebunden. Der anfängliche Fixturestand ist separat als `focused-initial-source.tar.gz` erhalten. `.env`, lokaler VPS-Kontext, echte Credentials und Repositoryhistorie sind nicht enthalten. Baselinearchiv und Abhängigkeiten bleiben bytegleich zum unten dokumentierten ersten Baselinevergleich.

| Abgeschlossener Rohbeleg unter `review2/` | SHA-256 |
|---|---|
| `focused-results.tar.gz`, beidseitig geprüfter Transfer | `ac399449ad37fd89e1f5d137f905f59b6aebe42b6bc5e1bcc027c687b4bf0b48` |
| `focused/focused.xml`, erster gezielter Lauf mit zwei Fixturefehlern | `d747fe0e17371cb0515b27cfd6109d844ceb44a0b8906e69793104afb81a2794` |
| `focused/report-only.xml`, korrigierte fünf Abschlussfälle | `909a9a89bebb9e3ce62421e650bf9e790135ad16ba27a1ad9e1cfdc44bf0facb` |

### Vertrag und Dateiscope

Die neue Fix-Liste erlaubt nötige Files-Scope-Änderungen. Deshalb sind `ReportOnlyCompletionExecutorTest` und `RunFinalizationStepTest` zusätzlich ausdrücklich im Frontmatter und im initialen Scope aufgeführt. Für die darüber hinausgehende Saga-/Recorded-Scope-Präzisierung wurde gemäß Finding 3 und `AGENTS.md` §10 eine gesonderte Bestätigung angefragt. Sie liegt bislang nicht vor. Task 9, der Saga-Zusatz im Review Focus und die entsprechende vertragerweiternde Notiz wurden deshalb zurückgenommen; die genaue offene Vertragsanfrage steht stattdessen datiert in `## Notes`. Dies ist weder eine verweigerte noch eine erteilte Freigabe. Die nachgewiesenen Codekorrekturen ersetzen diese Entscheidung nicht.

Die Dateitabelle am Ende wurde vollständig gegen den Diff abgeglichen: 87 Implementierungsdateien, kein fehlender oder nicht vorhandener Pfad; vorhandene Nutzeränderungen an `.gitignore` und `AGENTS.md` sind gesondert ausgenommen. Sie nennt jetzt Gateversionsentscheidung, Saga-Zuordnung, erhaltene Zielbytes, Abschluss-/Abbruchfixtures, Diagnostik und Ticketänderung. Der falsche Satz über unveränderte Ticketdateien ist entfernt.

### Vollständige Wiederholung und DoD-Entscheidung

Die ausdrücklich freigegebenen vollständigen Linux-Läufe für den korrigierten Arbeitsstand und die unveränderte Baseline `4ed8927` werden jeweils zweimal unter denselben unten dokumentierten Grenzen ausgeführt. Jede Ausführung schreibt ihre eigenen Rohprotokolle nach `first/` beziehungsweise `repeat/`; die zweite startet erst nach Ende der ersten. Der Befehl lautet jeweils `php artisan test --compact --log-junit /results/<Lauf>/full.xml --log-events-text /results/<Lauf>/full-events.log`, mit ausdrücklich gesetztem `APP_ENV=testing` und dem öffentlichen PHPUnit-Testschlüssel.

| Vollständiger Lauf | Tests | Assertions | Assertion-Fehlschläge | Fehler | Skips | Ohne Fehler-/Skip-Eintrag | Exitcode | Summierte Testzeit |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Baseline, erster neuer Lauf | 1.969 | 67.772 | 95 | 22 | 14 | 1.838 | 2 | 1.653,55 s |
| Baseline, zweiter neuer Lauf | 1.969 | 67.870 | 94 | 22 | 14 | 1.839 | 2 | 1.662,84 s |
| Arbeitsstand, erster neuer Lauf | 2.052 | 72.105 | 94 | 9 | 14 | 1.935 | 2 | 1.863,56 s |
| Arbeitsstand, zweiter neuer Lauf | 2.052 | 72.025 | 95 | 9 | 14 | 1.934 | 2 | 1.877,11 s |

Der erste neue Arbeitsstandlauf enthält **keinen zusätzlichen Fehler gegenüber der ursprünglichen unveränderten Baseline**. In beiden neuen Arbeitsstandläufen schlagen dieselben 103 Baselinefälle fehl; 13 frühere Fixturefehler sind behoben: vier Amendment-, vier Abbruch- und die fünf jetzt korrigierten Abschlussfälle. Alle drei zuvor sporadisch fehlschlagenden Fälle bestehen in beiden neuen Gesamtläufen. Der erste neue Baselinelauf reproduziert zusätzlich `ReReviewCompletenessTest::test_an_earlier_nothing_to_fix_is_never_reused_for_a_changed_tree` mit `verification_slot_failed` in `BuildsFixLoopFixture:143`; der zweite reproduziert exakt die ursprünglichen 116 Fehlerfälle. Das belegt eine weitere Instabilität am unveränderten Altstand, aber noch nicht die Ursache oder Vorbestehendheit genau der drei früheren Zusatzfehler.

Der zweite neue Arbeitsstandlauf enthält außerdem **einen neuen Zusatzfehler**: `SecurityReviewCandidateIsolationTest::test_candidate_export_has_no_git_metadata_hooks_or_writable_ref_and_index_paths` scheitert in der Candidate-Vorbereitung mit `candidate_generation_failed` (`BuildsSecurityReviewFixture:80`, Aufruf im Test:18). Er wird ausdrücklich nicht als Baselinefehler eingeordnet. Alle vier vollständigen Läufe sind abgeschlossen; ihre roten Ergebnisse bleiben sichtbar.

| Vollständiger Rohbeleg unter `review2/` | SHA-256 |
|---|---|
| `baseline-first-results.tar.gz` | `9dae55a226a5aa335b6d01ff693dd95de859c4b73e883ef7cd1c7f81f9b1b866` |
| `baseline-first/full.xml` | `ba223ac6640579ea93b728d9d2657fa3451e04ab630452a7e9dbc64ea1176b3a` |
| `baseline-first/full-events.log` | `2058669f892788f8051831c8d1dc55018e8f77430238082affa20d4e30066ccd` |
| `baseline-first/full.log` | `566d17c644f29af5ba6c8e104d2e1e2b3cd5aebee444c356bdc581d63b0d115e` |
| `baseline-repeat-results.tar.gz` | `7fd007d55ad67ddf5be9cb16911754400ea8e7ce21fbefb01fb58b6a38bfd67a` |
| `baseline-repeat/full.xml` | `aa9bcc8030be537347552e87686efd51c20c936dce67e021faf479c8c222fef3` |
| `baseline-repeat/full-events.log` | `098b4ca1d0eed43f09620481f7b001ebf327f6c3437b874ba5cd1306471b6a8c` |
| `baseline-repeat/full.log` | `85d17411794afe517d063f91f782846ad0926b2144126210a2da799c964f4a86` |
| `current-first-results.tar.gz` | `4419b50f156a78a148272ce5b620c2e5acc96b4159d1bef3393334c0d641743e` |
| `current-first/full.xml` | `22bc9735154b9ad15f517c7ea9cef0face8f65f465732807de8ea76bc71736c6` |
| `current-first/full-events.log` | `c85f222c327d8771b75e7b61b56f32052f38623fd1d23d6891ea46f62f449a37` |
| `current-first/full.log` | `653a9b87e1264df2014367810f173dad6296099f7ac836b4fbcfc458cfc5a197` |
| `current-final/repeat/full.xml` | `402dc6f7a6e1a7946cd06bfe62720432a42cda5a78fbf7e5358f89e4e245b55c` |
| `current-final/repeat/full-events.log` | `4011493cf724aef29d2df99ae14bbdf0577fa0a24a350c9d682b7b8d952c04dd` |
| `current-final/repeat/full.log` | `380925bd892271e75bd7f0c31177f898a7548069a19188d0551bdd02b6cea2d6` |
| `baseline-final-results.tar.gz`, beide Baselineläufe und sämtliche Diagnosen | `67c5d4cc8242eaefb12f783a5bf7b9dd20e507404a2ca86c4adee384c15f812b` |
| `current-final-results.tar.gz`, beide Arbeitsstandläufe und gezielte Vorläufe | `43884dba64f53c700e711bc5e47ba72e8598cdebecea31cee284f5345bbc00f8` |

### Getrennte Ursachendiagnose

Nach den beiden regulären Baselineläufen wurden die drei ursprünglich strittigen Fälle und der zusätzlich beobachtete Re-Reviewfall gemeinsam 20-mal ausgeführt: **80 Fälle, 14.180 Assertions, keine Fehler oder Skips**, summierte Testzeit 333,67 Sekunden. Anschließend wurde der neue Candidate-Isolationsfall zehnmal geprüft: **zehn Fälle, 2.630 Assertions, keine Fehler oder Skips**, summierte Testzeit 56,87 Sekunden. Jeder Lauf besitzt eigene JUnit-, Ereignis- und Konsolenprotokolle unter `baseline-final/diagnostic/` beziehungsweise `baseline-final/candidate-diagnostic/`; `diagnostic-summary.json` bindet jeden Rohhash und die Einzelzähler. Diese erfolgreichen Wiederholungen ersetzen keinen Ursachennachweis.

Diese gesonderten Versuche sind **instrumentierte Diagnosen**, keine weiteren unveränderten regulären Baselineläufe. Die Baselinequelldateien blieben bytegleich. Der zusätzlich über `--bootstrap /input/diagnostic-bootstrap.php` geladene Beobachter liest bei Fehlern ausschließlich bereits redigierte Operationsmeldungen, technische Job-/Reviewcodes und den serverseitigen Gategrund vor dem Test-Cleanup. Er verwendet außerdem eine gesonderte Kopie des im Baselinecode identischen `ControlProcessRunner`: Ausschließlich vor dessen bereits vorhandener Ausnahme bei fehlender Prozess-ID werden Laufzustand, Exitcode und Funktionsnamen ohne Argumente protokolliert. Der originale Fehlerpfad, alle Kontrollen und alle Timeouts bleiben bestehen. Die Hypothese eines bereits beendeten Kurzprozesses wurde dadurch nicht bestätigt: In den 90 Diagnosefällen trat weder der Zielausfall noch dieser Prozessfehlerpfad auf. Es gibt daher keine darauf gestützte Produktkorrektur und keine Behauptung einer gemeinsamen Ursache.

| Instrument der getrennten Diagnose unter `review2/` | SHA-256 |
|---|---|
| `diagnostic-bootstrap.php` | `84abf128641d6de4e58256ac6080a04c9ba2836ce12e41419c3617f30edbab41` |
| `diagnostic-ControlProcessRunner.php` | `6dc37c22f1baa72659911d74b21bc60dbe78e90d93890eef919dee9e43d0a0d2` |

### Menschliche DoD-Entscheidung

**Am 26. September 2026 aktualisierte DoD-Entscheidungsvorlage, noch ohne menschliche Entscheidung:** Die folgende Liste grenzt ausschließlich die **96 noch offenen** Fälle aus den auch an `4ed8927` nachgewiesenen Fehlern ab. Sie ist in `review5/remaining-baseline-failures.json` mit vollständigen Fallnamen und Meldungen gebunden, SHA-256 `2cfa10af981b7b7f24f77c7fe3b158e4ce3d294d9dd75b16d7b535aa4223c5a9`. Die sieben jetzt vollständig bestandenen Config-Bindungsfälle aus `BlockedControlProcessTest` und `EffectLockRuntimeSecurityTest` sind entfernt; die ursprüngliche Liste in `review2/common-baseline-failures.json` bleibt als Rohhistorie unverändert. Zu entscheiden ist, ob die übrigen 96 Fälle TC-08 für AI6-051 weiter blockieren oder ausdrücklich als gesondert zu bearbeitender Bestandsbefund abgegrenzt werden. Die zuvor gestellte Frage ist weiterhin unbeantwortet. Frühere sporadische Zusatzfehler ohne eigene Ursachenzuordnung, manuelle Gates und bestehende Sicherheitskontrollen sind nicht Gegenstand einer solchen Ausnahme. Ohne Antwort bleibt die DoD-Entscheidung offen; kein roter Test gilt als bestanden.

| Bestehende Testklasse | Gemeinsame Fehlerfälle |
|---|---:|
| `ClaudeCopilotCliExecutionTest` | 12 |
| `CodexCliExecutionTest` | 21 |
| `GitHubCopilotCliExecutionTest` | 12 |
| `GrokCliExecutionTest` | 22 |
| `ProviderLoginTest` | 7 |
| `ProjectQueueManagedGitTest` | 1 |
| `FindingVerificationRoundTest` | 1 |
| `ReviewOnlyExecutionTest` | 5 |
| `CodexCliDoctorCheckTest` | 3 |
| `GitHubCopilotCliDoctorCheckTest` | 1 |
| `GrokCliDoctorCheckTest` | 7 |
| `ProviderProcessScopeTest` | 4 |

Die lokale Inventur besteht mit 31 Tests und 1.007 Assertions. Pint, PHPStan, Manifest, Composer und `git diff --check` sind grün. Der Detailticketvalidator meldet keine Fehler und bestätigt unverändert `status: ready`, AC-01–AC-08, TC-01–TC-08 und MG-01. Änderungen nach dem gebundenen Testarchiv betreffen nur den vorliegenden Bericht und die dokumentierte Rücknahme der strittigen Ticketpräzisierung; Produkt- und Testbytes bleiben gebunden. MG-01 bleibt ergebnis- und signaturfrei.

Nach den Übertragungen wurden alle bereits gesicherten vollständigen Rohprotokolle erneut byteweise mit den finalen Archiven verglichen; die Hashes stimmen überein. Beide Containerquellbestände wurden erneut gegen ihre vollständigen Manifeste geprüft. Ausschließlich die zwei anhand ihrer Kennzeichnung geprüften Wegwerfcontainer und ihr exakt aufgelöstes temporäres Testverzeichnis wurden entfernt; ihre Abwesenheit ist bestätigt. Dauerhafte Nachweise und die eingesetzten Diagnoseskripte bleiben lokal erhalten. Es wurde weder committed noch gepusht.

### Nicht gefixte Findings

- **Mittel — sporadische Linux-Zusatzfehler und TC-08/DoD:** Die Ursachen der drei ursprünglich gemeldeten sporadischen Fehler sind weiterhin nicht belegt; zusätzlich bleibt der neue `candidate_generation_failed`-Fehler offen. Der weitere Re-Reviewausfall am unveränderten Baselinecode beweist nicht ihre gemeinsame Ursache. Statt einer unbelegten Produktänderung wurden die verlangten Assertiondiagnosen ergänzt, Arbeitsstand und Baseline jeweils zweimal vollständig ausgeführt und 90 weitere Diagnosefälle samt Rohbelegen gesichert. Noch nötig sind ein belastbarer Ursachennachweis mit entsprechender Behebung sowie die ausdrücklich angefragte menschliche DoD-Entscheidung für die 103 übrigen Bestandsfehler. Die beiden niedrigen Findings sind durch die dokumentierte Rücknahme/Vertragsanfrage beziehungsweise die vollständige Dateitabelle bearbeitet; das hohe Finding ist durch die fünf jetzt erreichten und bestandenen Abschlussfälle behoben.

## Freigegebene Gatekorrektur vom 25. September 2026 — Stand vor der erneuten Reviewkorrektur

Der Mensch hat die vorgelegte Candidate-Gate-Korrektur ausdrücklich freigegeben und nötige Scopeänderungen im Ticket erlaubt. Diese Freigabe ersetzt die zuvor ausstehende konkrete Entscheidung; die unten dokumentierte frühere Zurückweisung und die roten Vorläufe bleiben als Historie erhalten.

`RunOrchestrator::invalidateStaleCandidateGateEvidence()` verlangt vor Candidate-Bindung weiterhin exakt die nächste Runversion. Nach erfolgreicher Bindung darf die ursprüngliche Evidenzversion höchstens der aktuellen Runversion entsprechen, solange sämtliche bisherigen Provenienzbindungen gelten und die Evidenz nicht invalidiert wurde. Die Kandidatenerkennung vergleicht zusätzlich die Basis-OID. Reine Phasen- und Publikationsfortschritte schreiben keine Evidenz um. Rollen, Step-up und die exakte Versions-/Replaybindung menschlicher Antworten bleiben unverändert.

Der gezielte Linux-Lauf besteht mit **59 Tests und 2.713 Assertions**. Er umfasst `GitObjectFormatWorkflowTest`, `PublishCandidateGateTest`, `CandidateGateInteractionTest`, `HumanRequestAnswerTest`, `PublishCandidateTest` und `RunCancellationExecutorTest`. Die 18 Gatefälle prüfen unter anderem bytegleiche Evidenz über Phasenwechsel, zukünftige Versionen, veraltete prospektive Autorisierung und einzelne Tree-/Diff-/Basisabweichungen vor und nach Bindung. Der prospektive Basisabweichungsfall wurde zunächst rot reproduziert und durch den zusätzlichen Vergleich mit `run_base_sha` behoben. Alle bestehenden Vertrags-, Provenienz- und Replayprüfungen bleiben erhalten.

Der danach vollständig erreichte Publishablauf legte zwei weitere Produktfehler offen. Der Mutationsabschluss rief alle drei Sagas auf: Eine Implementierungsabschlussoperation erreichte die Abbruchsaga, eine Abbruchoperation anschließend den Publikationsabschluss. `TicketMutationExecutor` wählt nun bei Erfolg und Konflikt anhand der bestehenden, validierten `status_operation` genau den zuständigen Dienst. `RunCancellationService` grenzt beide vorhandenen Abschlussoperationen auch an seinen direkten Einstiegspunkten ab. Die vier echten Abbruch-/Recoverytests erreichen nach Ergänzung der produktiv vorgeschriebenen Queue-Aufnahme erstmals diese Pfade und bestehen.

Außerdem überschrieb `QueueTicketMutation` den bereits validierten Implementierungszielinhalt mit einer reinen Statusänderung des Ausgangstexts. Dadurch fehlte der zuvor gerenderte Recorded Scope im veröffentlichten Ticket. Der Implementierungspfad bewahrt jetzt den validierten Zielinhalt; Status-, Projekt-, Approval-, Vertrags- und CAS-Prüfungen bleiben erhalten. Die Queueregression verlangt exakt diese Zielbytes, und beide realen Formatdurchläufe vergleichen den veröffentlichten Inhalt mit dem gebundenen `recorded_scope_sha256`.

Die sechs echten Formatdurchläufe bestehen: Implementierung bis Runbranch-Push und Ticketstatus-CAS in SHA-1/SHA-256, Report-only-Abschluss in beiden Formaten sowie die beiden irreführenden Refantworten. Der Implementierungsfall erreicht trotz INFO-Zeile auf stderr den formatlangen Null-Sentinel und den Create-only-Push, behält gültige Gateevidenz und weist die wiederholte menschliche Antwort vor Publish ab.

Die abschließenden vollständigen Windows- und Linux-Läufe verwenden identische Implementierungs- und Testbytes. Der Linux-Stand ist durch das Quellarchiv `982e2b0d61d91f54a10c44785dcbefda1c2866e8ce56b0807b855e16fd81f498` gebunden. Die unveränderte und bereits vollständig ausgeführte Baseline bleibt `4ed89279b58b6a776d5cfec3c915c8b457364cc5` mit den unten gesicherten Rohprotokollen.

In `tickets/AI6-051.md` dokumentieren `files`, Scope, Context, Tasks, AC-06/TC-06, Review Focus und Notes die ausdrücklich freigegebene Änderung. Ergänzt sind auch die bisher außerhalb der Scopeabschätzung nötige HTTP-OID-Regel, Scaffold-/Release-Inventar und der Nachweisbericht. Veröffentlichte IDs, Ticketstatus und manuelle Gateergebnisse bleiben unverändert. `AGENTS.md` und der Plan benötigen für diese konkrete Korrektur keine Änderung.

Die zusätzlichen Scopepfade sind ausdrücklich aufgeführt: `RunOrchestrator` für die Gatebindung; `RunCancellationService`, `TicketMutationExecutor` und `Actions/QueueTicketMutation` für die korrekte Fortsetzung des bestehenden Statusabschlusses; `GitObjectFormatWorkflowTest`, `RunCancellationExecutorTest` und `PublishCandidateGateTest` für die echten Abläufe und Negativfälle. Die vier bestehenden Schreibvorgänge im ergänzten Publish-Konflikttest behalten unveränderten Code, Ziel, Entscheidung und Begründung in der Release-Inventur; nur die Hashes ihres durch zusätzliche Assertions veränderten Methodenkontexts wurden aktualisiert. Keine offene Release-Lücke wurde dadurch geschlossen.

Detailticketvalidator, Manifest-Driftprüfung und `composer validate --strict` bestehen. Die vollständige PHPStan-Analyse meldet keine Fehler. Scaffold- und Release-Inventar bestehen mit **31 Tests und 1.007 Assertions**. Sämtliche neuen Rohbelege liegen dauerhaft und ignoriert unter `storage/app/private/ai6-051-review/gate-final/`; der Bericht enthält weder den lokalen VPS-Alias noch benutzerspezifische absolute Pfade.

### Erster vollständiger Linux-Abschlusslauf nach Freigabe

Der unveränderte reguläre Befehl `php artisan test --compact --log-junit /results/full.xml --log-events-text /results/full-events.log` endet mit **Exitcode 2: 2.052 Tests, 71.979 Assertions, 94 Assertion-Fehlschlägen, 15 Fehlern und 14 Skips; summierte Testzeit 1.844,80 Sekunden**. 1.929 Fälle haben keinen Fehler-/Skip-Eintrag im JUnit-Protokoll; enthaltene Warnungen bleiben davon unberührt. Der maschinenlesbare Vergleich in `gate-final/current-first/baseline-comparison.json` findet **108 gemeinsame Fehlerfälle, acht ausschließlich in der Baseline fehlschlagende Fälle und einen zusätzlichen Fehler**. Die acht behobenen Altfehler gehören zu den vier Amendment- und vier Abbruchfixtures.

Der zusätzliche Fehler lautet `TicketMutationExecutorTest::test_precommit_recovery_can_be_abandoned_and_releases_the_project_lock`. Sein Stacktrace zeigt auf den Clone im Fixtureaufbau (`recoveryMutationFixture`), noch vor dem eigentlichen Recoveryablauf; die sichtbare Exception lautet `The control operation attempt failed and remains retryable.` Eine genauere Ursache wurde in diesem Rohprotokoll nicht gespeichert. Der unveränderte Einzeltest besteht anschließend mit 38 Assertions, die ganze unveränderte Klasse mit 18 Tests/887 Assertions. Auch im zweiten vollständigen Lauf besteht dieser Fall.

Zusätzliche Diagnosevorläufe mit 30 beziehungsweise 100 Wiederholungen und ein erster Diagnoselauf der Finalisierungsklasse wurden durch eine falsche Zeichenkodierung ihrer gesonderten Testkopien verändert. Sie sind getrennt erhalten, werden aber **nicht als belastbarer Nachweis des Originalstands gewertet**. Der Fehler trat ausschließlich beim Herstellen dieser Diagnosekopien auf; Repositorydateien, Quellarchiv und beide vollständigen Linux-Läufe sind unverändert. Die nachfolgende Diagnosekopie liest und schreibt UTF-8 ausdrücklich und ergänzt nur den schon vorhandenen technischen Grundcode in der Assertionmeldung.

Die folgenden betroffenen Klassen sind im vollständigen ersten Linux-Lauf ohne Fehler abgeschlossen:

| Klasse | Tests | Assertions |
|---|---:|---:|
| `GitObjectFormatWorkflowTest` | 6 | 940 |
| `PublishCandidateGateTest` | 18 | 517 |
| `GitObjectFormatMigrationTest` | 16 | 167 |
| `ManagedCloneSynchronizerTest` | 28 | 1.212 |
| `TicketMutationWebTest` | 6 | 82 |
| `PublishCandidateTest` | 16 | 663 |
| `RunCancellationExecutorTest` | 4 | 256 |

Der einmalige Clonefehler wird weder als behoben noch als Baselinefehler umgedeutet. Vor der zweiten vollständigen Linux-Prüfung wurde die Original-Testdatei wiederhergestellt und ihr SHA-256 gegen das Repository geprüft. Der folgende zweite Gesamtlauf ist deshalb ein regulärer Lauf des identischen Quellstands.

| Neuer dauerhafter Nachweis unter `gate-final/` | SHA-256 |
|---|---|
| `current-first/full.xml` | `6281b5687b91bfefee3f6a0e5031c6a994146244981cfddc21bad75e9ad50246` |
| `current-first/full-events.log` | `8526345011bfc3f8d923116538ce8901732d5929ee8fb7e4a69be0b35733fcc9` |
| `current-first/full.log` | `a74515c7a4adb6894bb03c18647622dd6b39bdcaa12e55547c6aad992aa6bad4` |
| `current-first-results.tar.gz`, Transferhash beidseitig geprüft | `322e2ee3bac6a477528f834e426734410a84769717f82e81a0cd6fb742075d4a` |

### Zweiter vollständiger Linux-Abschlusslauf und verbleibende Zusatzfehler

Derselbe reguläre Befehl endet mit **Exitcode 2: 2.052 Tests, 71.828 Assertions, 96 Assertion-Fehlschlägen, 14 Fehlern und 14 Skips; summierte Testzeit 1.857,95 Sekunden**. 1.928 Fälle haben keinen Fehler-/Skip-Eintrag im JUnit-Protokoll. `gate-final/current-repeat/baseline-comparison.json` bestätigt wieder **108 gemeinsame Baselinefehler und acht behobene Altfehler**, diesmal mit **zwei zusätzlichen Finalisierungsfällen**. Der Clonefehler des ersten Laufs tritt nicht erneut auf.

- `RunFinalizationStepTest::test_bound_candidate_receives_a_fresh_clear_security_review_before_publish`: Der Sicherheitsreview wartet am Sicherheitsgate, statt erfolgreich abzuschließen; zwei bisherige Agentturns, ein Security-Slot und noch kein Security-Ergebnis sind gespeichert. Die Assertion erreicht nur die allgemeine Sicherheitsgate-Meldung. Der unveränderte Einzeltest besteht anschließend mit 247 Assertions; im ersten vollständigen Linux-Lauf war dieser Fall ebenfalls erfolgreich.
- `RunFinalizationStepTest::test_every_non_clear_security_outcome_parks_at_the_security_gate`, Datensatz `provider failure status`: Bereits der erste reguläre Review im Aufbau von `prepareSecurityCandidate` endet `failed` statt `succeeded`, bevor der eigentliche Security-Negativfall erreicht wird. Auch dieser Fall war im ersten vollständigen Lauf erfolgreich.

Diese drei über beide Gesamtläufe verteilten Zusatzfehler besitzen damit noch keinen belastbaren Ursachennachweis. Sie werden ausdrücklich offengelassen; erfolgreiche Teilwiederholungen ersetzen keine Fehlerbehebung. Sicherheitskontrollen, Timeouts und Assertions wurden dafür nicht gelockert. **TC-08/DoD bleibt offen.** Die übrigen zehn eingereichten Findings sind umgesetzt und durch die hier gebundenen Nachweise geprüft. Das menschliche MG-01 bleibt unabhängig davon offen.

Die abschließende, korrekt als UTF-8 übernommene Diagnoseklasse besteht mit **17 Tests/3.405 Assertions**; 20 zusätzliche Wiederholungen des Security-Clear-Falls bestehen mit **4.940 Assertions**. Die einzige Erweiterung dieser Testkopie ist der schon vorhandene technische Grundcode in der Assertionmeldung. Beide vollständigen Linux-Rohprotokolle bleiben nach diesen getrennten Versuchen bytegleich. Zur Schließung von TC-08 fehlen weiterhin die zuverlässig zugeordnete Ursache der drei Zusatzfälle, ihre gezielte Behebung und ein erneutes vollständiges Abschlussgate; insbesondere wäre eine wertfreie Ursachenerfassung im gesamten Linux-Lauf erforderlich, da die isolierten Wiederholungen die Fehler nicht erzeugen.

| Neuer dauerhafter Nachweis unter `gate-final/` | SHA-256 |
|---|---|
| `current-repeat/full.xml` | `2d10477f4df503eaaf489bd8478ea1bfbc9ce08303b83d70f43996b03d9736f0` |
| `current-repeat/full-events.log` | `a8fe4c4d5772ff30aac808c6ae9718c0e12aedbd745a262745d6713eba63be0d` |
| `current-repeat/full.log` | `8c7370addd84a38ab72829e0d4382ca192bc05515e3ae67ff69ca3d273e84989` |
| `current-repeat-results.tar.gz`, Transferhash beidseitig geprüft | `51da8610c98f2d8274aae4005b79ffc4c976a86af8f20d57346e9eec7d9709d2` |
| `current-final-results.tar.gz`, einschließlich gesondert benannter ungültiger Diagnosevorläufe und korrigierter Diagnoseklasse | `4dc9de717b9a1d637014ab19391b85fdff70ce58c9d52bb619707a9aff59b1f9` |
| `repeat-final-results.tar.gz`, einschließlich der 20 korrekt kodierten Security-Wiederholungen | `057f93feffbe7428d216771c32d67dc59a9b66d470cc75ac854ba46d9dd86937` |

### Vollständiger Windows-Abschlusslauf nach Freigabe

```powershell
php artisan test --compact --log-junit storage/app/private/ai6-051-review/gate-final/windows-full.xml --log-events-text storage/app/private/ai6-051-review/gate-final/windows-full-events.log > storage/app/private/ai6-051-review/gate-final/windows-full.log 2>&1
```

**Exitcode 0: 2.052 Tests, 1.701 bestanden, 351 Skips, keine Fehler, 60.465 Assertions.** Die Konsolenlaufzeit beträgt 3.435,93 Sekunden, die summierte JUnit-Testzeit 3.413,87 Sekunden. `AI6_PHP85_BINARY` und `AI6_COMPOSER_PHAR` waren ausdrücklich nicht gesetzt. Die POSIX-Skips werden durch diesen Windows-Lauf nicht als geprüft ausgegeben; dafür stehen die getrennten Linux-Protokolle.

| Neuer dauerhafter Nachweis unter `gate-final/` | SHA-256 |
|---|---|
| `windows-full.xml` | `644b6c43ae429b5605f2f1ed4df0ddd23dd3730d9bff4ea26e85225f977e1353` |
| `windows-full-events.log` | `084ae08a83b163e735c069dc70761dd4970f2f7a086eeb74ee036c13e5deabdc` |
| `windows-full.log` | `19fe7f1cc810474ad9758fd878f670726d7237f9e3b3dcd544853de925e2b55f` |

Der vollständige Pint-Check besteht ebenfalls. PHPStan, Manifest, Composer, Detailticketvalidator und die oben ausgewiesenen Inventare sind am finalen Implementierungsstand geprüft; die abschließende Diffprüfung umfasst den ergänzten Bericht. Die externe Locked-Install-Suite wurde nicht ausgeführt, da Abhängigkeiten, Plattformanforderungen und Installation unverändert sind. Die Quellmanifestprüfung erlaubt als einzige Abweichung gegenüber dem geprüften Archiv diesen danach ergänzten Bericht. Ticketstatus, manuelle Abnahmeergebnisse, Plan und vorhandene Nutzeränderungen bleiben unangetastet; es wurde weder committed noch gepusht.

Nach beidseitiger Prüfung der finalen Archivhashes und erneutem Vergleich sämtlicher vollständiger Rohprotokolle wurden ausschließlich die zwei anhand ihrer Kennzeichnung geprüften Wegwerfcontainer und ihr exakt aufgelöstes temporäres Testverzeichnis entfernt. Ihre Abwesenheit wurde bestätigt. Alle dauerhaften lokalen Nachweise bleiben erhalten und sind von Git ausgeschlossen.

## Reviewkorrektur vom 24. September 2026 — vorheriger Stand

Alle elf eingereichten Findings sind relevant. Der Reviewauftrag erlaubt die nötigen Scope- und Ticketpräzisierungen sowie ausdrücklich die isolierten vollständigen Linux-Läufe für Arbeitsstand und Baseline `4ed8927`. Der folgende ursprüngliche Implementierungsnachweis bleibt als Historie erhalten; aktuelle Testergebnisse werden getrennt ausgewiesen.

- Export, Tree-Hashberechnung und Workspacevergleich der Finding-Verifikation besitzen einen eigenen Fehlerpfad. Ein fehlgeschlagener Export wird als `workspace_error` ohne Checkerhash gespeichert; der neue Test bindet einen tatsächlich werfenden `IsolatedTreeExport` und prüft den terminalen Job sowie einen anschließend erfolgreichen neuen Lauf.
- Ein fehlender neuer Remote-Runbranch verlangt weiterhin Exitcode 2 und leeres stdout; informative SSH-Ausgaben auf stderr sind zulässig. Der echte Formatdurchlauf prüft die INFO-Ausgabe, den fehlenden Remote-Branch und nach Publish den formatlangen Null-Sentinel des Create-only-Intents.
- `ProjectGitOidRule` ersetzt alle neun duplizierten HTTP-OID-Regeln durch den projektgebundenen `GitObjectFormat::validOid()`-Vertrag. Die realen Edit- und Statusrouten werden für SHA-1 mit Step-up, gültigen 40 Stellen sowie abgewiesenen 64 Stellen und Null-OIDs geprüft. Config- und Review-only-Routen behalten ihre bestehende Formatmatrix.
- Die Direktwrite-Matrix verlangt je Probe die exakte SQLite-RAISE-Meldung des vorgesehenen Triggers. Zwei überlagernde Provenienzprüfungen werden durch einen zulässigen Vorzustand getrennt: Candidateinvalidierung bei einer veränderten Runbasis und Prüfung der Publikations-OID vor dem Bestätigungszeitpunkt. Kein Produkttrigger wird entfernt oder abgeschwächt.
- Die neue Crashfixture veröffentlicht das gestagte Repository wirklich, lässt die Phase auf `effect_staged`, lässt die Lease ablaufen und liefert erneut aus. Beide Formate erreichen `completed`; ein tatsächlich fremdes Speicherformat wird zuerst an der typisierten Synchronisierernaht und danach durch die echte Worker-Recovery geprüft. Der terminale Erstclonefehler prüft zusätzlich die gespeicherte, an `git_object_format_mismatch` gebundene Ergebnisursache.
- Der neue Pending-Test erzeugt Clone und Control-Branch-Wechsel über die Produktnähte, führt mit ausschließlich Pending-Control-Bindung `down → up` aus und setzt den offenen Fetch durch den Worker fort. Operationsbytes bleiben gleich; Format, Control-OID, Bindungsversion und geleerte Pending-Felder werden gemeinsam geprüft.
- Die alten Rohprotokolle sind mit unveränderten Hashes dauerhaft im ignorierten Nachweisbereich gesichert. Der lokale VPS-Alias und benutzerspezifische Pfade wurden aus diesem Dokument entfernt.

Zusätzliche Testschreibvorgänge zur Simulation einer abweichenden Candidate-Approvalbindung und einer zukünftigen Evidenzversion sind einzeln als negative Fremdeffekte in der bestehenden Release-Inventur dokumentiert. Bestehende `requires_service`-Einträge und offene Release-Lücken bleiben erhalten. `AGENTS.md`, Plan, Ticketstatus, manuelle Gateergebnisse und Abhängigkeiten werden durch diese Korrektur nicht geändert.

Die konkrete Änderung der Gateversionsentscheidung wurde von der automatischen Freigabeprüfung zurückgewiesen und zur ausdrücklichen menschlichen Entscheidung vorgelegt. Bis zu dieser Entscheidung bleibt der Produktvergleich unverändert; der neue Phasenwechseltest reproduziert das Finding rot. Seine nachgelagerten Assertions für unveränderte Evidenzbytes und zukünftige Evidenzversionen werden dadurch noch nicht erreicht. Separate Tests prüfen veraltete prospektive Autorisierung sowie Scope-/Approvaldrift; der End-to-End-Test wiederholt den Replayversuch unmittelbar vor Publish.

### Ausgeführte Baseline der Reviewkorrektur

Der ausdrücklich freigegebene Vergleich verwendet den unveränderten Git-Archivstand `4ed89279b58b6a776d5cfec3c915c8b457364cc5`. Baseline und Korrekturstand erhalten getrennte Wegwerfcontainer mit dem unten gebundenen Image, derselben Repository-seccomp-Policy, UID 10001, Netzmodus `none`, 2 GiB RAM, zwei CPUs und den gleichen Lock-/Heartbeat-Fixtures. `APP_ENV=testing` und der öffentliche PHPUnit-Testschlüssel sind ausdrücklich gesetzt. Eine zunächst geerbte Produktionsumgebung verursachte einen nicht auswertbaren Vorlauf; dessen Protokolle liegen separat unter `baseline/invalid-environment/` und werden nicht als Baselinebeleg verwendet.

Der eigentliche Baselinebefehl lautet unverändert:

```bash
php artisan test --compact --log-junit /results/full.xml --log-events-text /results/full-events.log
```

Ergebnis: **Exitcode 2; 1.969 Tests, 67.870 Assertions, 94 Assertion-Fehlschläge, 22 Fehler, 14 Skips; summierte Testzeit 1.669,36 Sekunden.** Die verbleibenden 1.839 Fälle haben keinen Fehler-/Skip-Eintrag; vorhandene Warnungen werden dadurch nicht als erledigt erklärt. Der Vergleich mit dem ursprünglichen Arbeitsstandprotokoll bestätigt 112 identische fehlschlagende Testfälle. Vier alte Fehler in `ContractAmendmentExecutorTest` sind durch die bereits dokumentierte Queue-Fixturekorrektur behoben; ausschließlich die vier neuen `GitObjectFormatWorkflowTest`-Gatefälle sind gegenüber dieser Baseline zusätzlich rot.

Die dauerhafte Ablage aller neuen Nachweise ist `storage/app/private/ai6-051-review/`, durch die bestehende private Storage-Regel von Git ausgeschlossen. Sie enthält Quell- und Dependency-Manifeste, Rohprotokolle und maschinenlesbare Fallvergleiche; die Quelldateien `.env`, `.ai6-local-context.md` und die Repositoryhistorie wurden nicht übertragen.

| Nachweis | SHA-256 |
|---|---|
| Unverändertes Baseline-Quellarchiv | `3a8be2c2229d9df9d469eb4597b3653bb17e5a50665b198905bc3a86157197b2` |
| Gemeinsames Dependency-Archiv | `93ab936c45adeeed8eb8ae25e533b159b11204881f74877b2081ae0a9547931d` |
| `baseline/full.xml` | `9d05abe24627f042326a29f9d807257a24dbffcb2a414492a93e519d965818a1` |
| `baseline/full-events.log` | `686e8ca1951571a95d8d5b7d42a121616f7c7371668089561ee4138271dcae0b` |
| `baseline/full.log` | `f8ca940383897d4b27062c6a28a1f73cfc8324fe7e42d99c0ae9bee05e4dd2f3` |
| `baseline-results.tar.gz`, nach Transfer beidseitig geprüft | `91c180c515062550ae01a9f49ff190589ed49f674809eec0a97a134722b61145` |
| `phpstan.log`, vollständige Analyse nach `clear-result-cache`, Exitcode 0 | `00df51c9c8ffa9dac7cd9e7f27a635e63c26b2d31106a0093f5fdf2b1e76b76f` |
| `release-inventory.xml`, 9 Tests/680 Assertions, grün | `438ce8f7cf331f7d194cff9412e12feb2dbe03a752a888574e3ac8c943aaa63b` |

### Vollständiger Linux-Lauf des Korrekturstands

Der Korrekturstand wurde mit demselben regulären Testbefehl vollständig ausgeführt: **Exitcode 2; 2.046 Tests, 71.546 Assertions, 99 Assertion-Fehlschläge, 18 Fehler und 14 Skips; summierte Testzeit 1.850,40 Sekunden.** 1.915 Fälle besitzen keinen Fehler-/Skip-Eintrag. Der vollständige Vergleich `current/baseline-comparison.json` bestätigt **112 gemeinsame Fehlerfälle, vier ausschließlich in der Baseline fehlschlagende Amendmentfälle und fünf zusätzliche Gatefälle**. Andere zusätzliche Fehlschläge gibt es nicht.

Die fünf zusätzlichen Fälle sind genau:

- `GitObjectFormatWorkflowTest::test_fake_agent_reaches_real_runbranch_push_and_ticket_status_cas_after_gate_answer`, jeweils SHA-1 und SHA-256: vor Publish Runversion 15, unverändert geschlossene Gateevidenz für Version 13, anschließend Abweisung `MG-01`.
- `GitObjectFormatWorkflowTest::test_a_tail_matching_publish_response_is_rejected_before_any_push`, jeweils SHA-1 und SHA-256: erwartet `remote_probe_failed`, tatsächlich vorherige Abweisung `MG-01`.
- `PublishCandidateGateTest::test_bound_candidate_evidence_survives_phase_progress_without_rewriting_its_authorization`: nach dem ersten regulären Phasenwechsel wird MG-01 entgegen dem gewünschten Vertrag geöffnet.

Damit sind die regulären Phasenwechsel als gemeinsame Ursache reproduziert. Der INFO-stderr-Fall bestätigt im echten SSH-Wrapper bereits Exitcode 2 und leeres stdout; sein nachfolgender Create-only-Publish und die irreführende Refantwort bleiben wegen derselben Gateabweisung ohne vollständigen End-to-End-Nachweis. Die bisherigen Baselinefehler und die neuen roten Regressionstests bleiben sichtbar. TC-06 und das grüne Abschluss-Gate aus TC-08/DoD sind nicht erfüllt.

Aus demselben vollständigen Linux-Protokoll stammen die folgenden getrennten Nachweise:

| Betroffene Klasse / Fälle | Ergebnis |
|---|---|
| `GitObjectFormatTest` | 7 Tests, 77 Assertions, grün; zentrale HTTP-Regel einschließlich ungültiger Typen und ungebundenem Format. |
| `GitObjectFormatMigrationTest` | 16 Tests, 167 Assertions, grün; davon der neue Pending-only-Worker-Fetch nach `down → up` mit 44 Assertions. |
| `ManagedCloneSynchronizerTest` | 28 Tests, 1.212 Assertions, grün; vier neue echte Publish-vor-Phasenwechsel-Crashfälle und formatgebundene terminale Fehlerursache. |
| `TicketMutationWebTest` | 6 Tests, 82 Assertions, grün; beide neuen SHA-1-Routenfälle jeweils mit 22 Assertions. |
| Neue frühe Fehlerfälle in `FindingVerificationRoundTest` | Bindingfehler: 237, Workspacefehler: 236, tatsächlicher Exportfehler: 236 Assertions; alle drei grün. Der separate Mailboxfehler der Klasse tritt auch in der Baseline auf. |
| `PublishCandidateTest` | 16 Tests, 659 Assertions, grün; genaue Zieltrigger-Meldungen statt beliebiger SQLite-Constraints. |
| `PublishCandidateGateTest` | 11 Fälle grün, der oben benannte Phasenwechseltest rot; Scope-/Approvaldrift und veraltete prospektive Autorisierung werden abgewiesen. |
| `RunRetentionSweepTest` | 18 Tests, 515 Assertions, grün; der frühere Windows-Rückbaufehler ist in diesem Gesamtlauf behoben. |

| Nachweis | SHA-256 |
|---|---|
| Geprüftes Korrektur-Quellarchiv | `f0417acacf03a174eb263008c9d6bfbf36439b2cf9bbf2dad336c61b35ef8ee0` |
| `current/full.xml` | `a7d18f20edd62c28cf804282c2666590c3cd66ad24d4b95b770c19af67ee068c` |
| `current/full-events.log` | `7fffb54e8bba668649daa5e5483bd3bd3f236af1f9e42505f51cbf61594a60bf` |
| `current/full.log` | `4a1c96676143357beb395e1513a886ef9c9e0d161e0fdc8890b9407c8f5672d1` |
| `current-results.tar.gz` | `6832d657ad03f405171e0c59c7e2049124713c33880bc7922667fa8c72f1da04` |

Archiv und alle drei unveränderten Rohprotokolle wurden nach dem Transfer gegen die auf dem Testhost berechneten Hashes geprüft. Anschließend wurden ausschließlich die beiden anhand ihrer Kennzeichnung überprüften Testcontainer und ihr zuvor absolut aufgelöstes temporäres Verzeichnis entfernt; ihre Abwesenheit wurde bestätigt. Der lokale Quellmanifestvergleich findet nur diesen ergänzten Bericht als Abweichung; sämtliche geprüften Implementierungs- und Testbytes stimmen mit `current-manifest.json` überein.

### Vollständiger Windows-Lauf des Korrekturstands

Die reguläre Suite wurde nach den Korrekturen vollständig wiederholt, ohne gesetzte `AI6_PHP85_BINARY` oder `AI6_COMPOSER_PHAR`:

```powershell
php artisan test --compact --log-junit storage/app/private/ai6-051-review/windows-full.xml --log-events-text storage/app/private/ai6-051-review/windows-full-events.log > storage/app/private/ai6-051-review/windows-full.log 2>&1
```

**Exitcode 1; 2.046 Tests, 60.291 Assertions: 1.694 bestanden, 351 übersprungen, ein Fehlschlag, keine Fehler; Artisan-Laufzeit 3.720,10 Sekunden, summierte JUnit-Testzeit 3.695,50 Sekunden.** Der einzige Fehlschlag ist `PublishCandidateGateTest::test_bound_candidate_evidence_survives_phase_progress_without_rewriting_its_authorization`: erwartet keine offenen Gates, tatsächlich `MG-01`. Alle 18 Retentionfälle einschließlich des korrigierten Migrationsrundlaufs bestehen in diesem vollständigen Lauf. POSIX-/Linux-Skips werden nicht als Windows-Erfolgsnachweis gezählt; die entsprechenden Linux-Ergebnisse stehen oben.

| Dauerhaftes Rohprotokoll | SHA-256 |
|---|---|
| `windows-full.xml` | `ffd8447acd5db562f72c28e7b1835994ba4c8dc2f1f176012ec5c162438aa912` |
| `windows-full-events.log` | `046eebe1d6706c37e76f6975c9c5ed0b36410bfd078b8283df7590754defffbf` |
| `windows-full.log` | `11c5fb374c7be11ab34a36fb7dd692cfb7153fd69a9993fb13f8e75ac946c63a` |

Die maschinenlesbare Auswertung liegt unter `windows-full.summary.json`; `windows-SHA256SUMS` bindet die drei Rohprotokolle und den gespeicherten Exitcode. Pint, vollständige PHPStan-Analyse nach geleertem Cache, Composer-Validierung und Ticketmanifestprüfung bestanden am geprüften Korrekturstand. Die abschließende Diffprüfung umfasst auch diesen Bericht. Die externe Locked-Install-Suite ist weiterhin nicht ausgeführt, weil keine Abhängigkeiten, Plattformanforderungen oder Installationspfade geändert wurden.

### Damals nicht vollständig behobene Reviewfindings — historischer Stand

Die folgenden drei Punkte beschreiben den Abschluss vor der ausdrücklichen Freigabe vom 25. September. Sie sind keine erneute Freigabeanforderung; die oben dokumentierte Korrektur und ihre neuen Prüfergebnisse ersetzen diesen Stand.

- **Blocker Candidate-Gate über Phasenwechsel:** Die konkrete Produktänderung wurde von der automatischen Freigabeprüfung als mögliche Aufweichung der Versionsbindung ohne ausdrückliche Zustimmung zurückgewiesen. Die separate menschliche Freigabefrage ist noch unbeantwortet. Die Tests reproduzieren die Ursache; die Entscheidung in `RunOrchestrator` bleibt unverändert. Zur Freigabe steht: vor Candidate-Bindung genau die nächste Runversion verlangen, nach erfolgreicher Bindung die ursprüngliche Evidenzversion bis einschließlich der aktuellen Runversion zulassen, solange Candidate, Basis, Checkpoint, Ticket, Approval, Scope, Evidenzepoche und alle bestehenden Provenienzbindungen unverändert und nicht invalidiert sind. Menschliche Antworten bleiben exakt versionsgebunden und gegen Replay geschützt.
- **SSH-INFO-Ausgabe beim Fehlend-Sentinel:** Der fehlerhafte stderr-Vorbehalt ist entfernt; Exitcode 2 und leeres stdout werden im echten SSH-Wrapper-Fall nachgewiesen. Der geforderte anschließende Create-only-Publish und die irreführende Refantwort sind durch den vorstehenden Gatefehler blockiert. Ihre bestehenden End-to-End-Assertions bleiben rot und müssen nach der Gatekorrektur vollständig erreicht werden.
- **TC-08/DoD-Abschluss:** Beide regulären Gesamtläufe und die ausdrücklich freigegebene unveränderte Linux-Baseline sind vollständig ausgeführt, mit Befehlen, Zählern und Hashes dokumentiert. Das Finding ist dennoch nicht vollständig geschlossen: fünf zusätzliche Linuxfälle und ein Windowsfall bleiben wegen der Gateursache rot. Die 112 gemeinsamen Linuxfehler sind als vorhandene Fehler nachgewiesen, nicht als behoben oder bestanden umgedeutet. Nach Freigabe und Gatekorrektur sind die betroffenen Nachweise und das vollständige Abschluss-Gate erneut zu erbringen.

Die übrigen acht Findings sind umgesetzt und durch die genannten gezielten beziehungsweise vollständigen Läufe geprüft. Das menschliche MG-01 bleibt offen. Diese Reviewkorrektur enthält weder einen Commit noch eine Änderung an Ticketstatus oder Abnahmeergebnissen.

## Ursprünglicher Akzeptanz- und Nachweisstand vor der Reviewkorrektur

| Kriterium | Stand |
|---|---|
| AC-01 | Enum, Parser, Null-Sentinels, Git-Objektberechnung und unveränderter SHA-256-Goldenvektor geprüft. Echte Linux-Tree-/Blobberechnung, Ref-CAS, Create-only-Push und Mutationen für beide Formate grün. |
| AC-02 | Gemeinsame Format-/Control-Finalisierung, Speicherformatbestätigung, Clone/Fetch, drei Clone-Crashgrenzen sowie Retry/Adopt-Recovery unter Linux für beide Formate grün. |
| AC-03 | Migration, unabhängige Matrix aller 24 geänderten Trigger, Prüfsummen-Negativfälle und Fortsetzung einer alten menschlichen Antwort lokal grün. Der offene SHA-256-Altclone wurde zusätzlich nach `down → up` durch den echten Linux-Worker fortgesetzt. |
| AC-04 | Projektgebundene OIDs in Config, HTTP, Queue und Mutationen implementiert; lokale Config-/Formularfälle sowie echte Linux-Ticket-/Approval-/Runstart-/Amendment-CAS grün. |
| AC-05 | OID-/Prüfsummentrennung sowie `workspace_tree_hash = null` vor tatsächlicher Berechnung geprüft. Echte Instruktionssammlung und vollständiger Review-only-Lauf einschließlich Report und Ticketstatus-CAS erreichen unter Linux in beiden Formaten `completed`. |
| AC-06 | Nicht erfüllt: Der echte Implementierungsablauf erreicht in beiden Formaten die gültige menschliche Gateantwort, scheitert aber vor Publish an erneut invalidierter MG-01-Evidenz. Dadurch erreicht auch der Publish-Negativfall seine Refprobe noch nicht. Ursache und erforderliche Scopeentscheidung stehen unten. |
| AC-07 | Atomare Upgrade-/Rückbauverweigerung und erlaubter Rundlauf lokal grün. Terminaler Erstclonefehler mit anschließendem HTTP-Neuauftrag bis Refresh sowie die unveränderte Fehlerhistorie unter Linux für beide Formate grün. |
| AC-08 | Offen: grünes vollständiges Linux-Abschluss-Gate, erfolgreicher Implementierungs-Endzustand aus TC-06 und menschliches MG-01 am finalen Implementierungscommit fehlen. |

## Ursprünglich ausgeführte Prüfungen

Der erste Windows-Gesamtlauf deckte eine fehlende SHA-256-Bindung der Zweitprojekt-Fixture und veraltete Quellbindungen der Release-Gate-Inventur auf. Beide Ursachen wurden korrigiert; die gezielte Wiederholung bestand. Der alte Gesamtlauf wurde daraufhin beendet und die vollständige reguläre Suite mit JUnit- und Ereignisprotokollierung erneut gestartet.

Dieser vollständige Wiederholungslauf endete mit Exitcode 1: 1.685 bestanden, 346 übersprungen, 1 fehlgeschlagen, 59.805 Assertions in 3.710,45 Sekunden. Der einzige Fehler lag in der historischen Retention-Upgradefixture: Sie rollte weiterhin zwei Migrationen zurück und erreichte wegen der hinzugekommenen Objektformatmigration ihren vorausgesetzten Altstand nicht mehr. Die Rückbautiefe wurde auf drei korrigiert; alle bisherigen Legacy-Assertions bleiben erhalten. Der bereits gestartete Gesamtlauf hatte die alte Testklasse geladen. Die gezielte Wiederholung der korrigierten Methode bestand mit 44 Assertions, die anschließend vollständig wiederholte Retention-Klasse mit 18 Tests und 516 Assertions. Der Windows-Gesamtlauf wird deshalb nicht nachträglich als grün bezeichnet; der spätere vollständige Linux-Lauf ist unten separat ausgewiesen.

| Befehl / Auswahl | Tatsächliches Ergebnis |
|---|---|
| `php artisan test --compact --log-junit "$env:TEMP/ai6-051-final-windows.xml" --log-events-text "$env:TEMP/ai6-051-final-windows-events.log"` | Vollständig ausgeführt, Exitcode 1: 1.685 bestanden, 346 Skips, 1 Retention-Fixturefehler, 59.805 Assertions; 3.710,45 Sekunden. Fehler anschließend wie oben beschrieben korrigiert und gezielt geprüft. |
| `php vendor/bin/phpunit tests/Feature/Runs/RunRetentionSweepTest.php --filter=test_the_retention_migration_binds_legacy_rows_once_under_the_upgrade_configuration` | Nach Korrektur 1 Test, 44 Assertions, grün. Pint und PHPStan für die geänderte Datei ebenfalls grün. |
| `php vendor/bin/phpunit tests/Feature/Runs/RunRetentionSweepTest.php` | Vollständige betroffene Klasse nach Korrektur: 18 Tests, 516 Assertions, grün. |
| `php vendor/bin/phpunit tests/Unit/Git` | 104 Tests, 7.501 Assertions, 9 Skips; ausgeführte Tests grün. |
| Gezielt `RunWorkspaceContractTest`, `RunWorkspaceGitTest`, `InstructionSnapshotResolverTest` | 25 Tests, 351 Assertions, 2 Skips; ausgeführte Tests grün. |
| Gezielt Review-only-Formularroute und Refresh-Webfälle | 5 Tests, 132 Assertions, grün. |
| `php vendor/bin/phpunit --filter='test_the_final_commit\|test_approval_is_cas\|test_candidate_gate_evidence\|test_mutation_guards\|test_review_only_insert_and_update_guards\|test_early_failures\|test_every_status_entry\|test_apply_contract_amendment_moves\|test_a_matching_binding_is_accepted\|GitObjectFormatMigrationTest'` | 28 Tests, 1.223 Assertions, grün. Unabhängige Trigger-/Hashmatrix und Upgrade-/Fortsetzungsfälle. Die Backslashes vor `\|` in dieser Tabellenzelle dienen nur dem Markdown-Escaping; die ausgeführte Regex verwendet einfache Pipes. |
| Gezielte PHPStan-Analyse des Guardhelfers, `PublishCandidateTest` und `ReviewOnlyExecutionTest` | Keine Fehler nach Korrektur der Testtypen. Die abschließende Gesamtanalyse ersetzt diesen Teilnachweis. |
| `php vendor/bin/phpstan clear-result-cache`, anschließend `php vendor/bin/phpstan analyse --no-progress` | Vollständige Analyse mit geleertem Ergebniscache: keine Fehler. |
| `php vendor/bin/pint --test` | Bestanden. |
| `composer validate --strict` | `./composer.json is valid`. |
| `php scripts/generate-ticket-manifest.php --check` | `Ticket manifest is current.` |
| `git diff --check` | Bestanden. |
| `php vendor/bin/phpunit tests/Unit/Shared/Redaction/RunArtifactTombstoneFingerprintTest.php tests/Feature/Runs/FakeAgentReleaseGateContractTest.php` | Nach Korrektur 11 Tests, 738 Assertions, grün. |
| `php vendor/bin/phpunit tests/Feature/Runs/RunArtifactDownloadTest.php` | Nach Korrektur der gemeinsamen Zweitprojekt-Fixture 4 Tests, 96 Assertions, grün. |
| `php vendor/bin/phpunit tests/Feature/Git/GitObjectFormatWorkflowTest.php tests/Feature/Git/ManagedCloneSynchronizerTest.php tests/Feature/Git/HardenedGitRunnerTest.php tests/Feature/Runs/ApprovalInstructionSnapshotTest.php --display-skipped` | 45 Tests, 329 Assertions, 40 ausdrücklich gemeldete POSIX-/Linux-Skips. Die fünf ausgeführten Tests bestehen; kein Linux-Nachweis. |

Die gezielten Gruppen überlappen; ihre Testzahlen werden nicht zu einer vermeintlichen Gesamtsuite addiert. Die externe Locked-Install-Suite wurde nicht ausgeführt, da Abhängigkeiten, Plattformanforderungen und Installationsverhalten unverändert bleiben.

## Ursprüngliche Linux-Verifikation auf dem freigegebenen VPS

Der Mensch hat den isolierten Linux-Testlauf auf dem Test-VPS ausdrücklich freigegeben. Der lokale SSH-Alias bleibt ausschließlich im git-ignorierten Kontext. Lokales Docker/WSL war mit `HCS_E_HYPERV_NOT_INSTALLED` nicht verfügbar. Verwendet wurden ausschließlich ein eigener temporärer Testbereich und gekennzeichnete Wegwerfcontainer; bestehende Betriebscontainer, Betriebsvolumes, Hostkonfiguration und externe Git-Remotes wurden nicht verändert.

Der Lauf verwendet das vorhandene Image `ai6-runtime:php-8.5.5_sqlite-3.53.4`, gebunden an `sha256:674a6d9f0d2ce967d7f4abb81eeb5588b72b708d21192f7e3a6b0269ef19669e`. Tatsächlich ausgeführt: PHP 8.5.5, Git 2.39.5, UID/GID 10001. Der Container hat `--network none`, 2 GiB Arbeitsspeicher, zwei CPUs, PID-Limit 512 und `no-new-privileges`. Er erhält keine Betriebsvolumes oder echten Credentials. Root richtet ausschließlich die temporären Quelldatei-, Lock- und Heartbeat-Fixtures ein; die Tests laufen unprivilegiert. Quelltext und ausgelieferte Wrapper sind root-eigen und nicht beschreibbar; der SSH-Wrapper bleibt ausführbar. Primäre und sekundäre Lock-Fixtures liegen außerhalb des separaten ausführbaren `/tmp`-Mounts.

Die erste vollständige Übertragung einschließlich lokaler Testabhängigkeiten hatte SHA-256 `801c13e834f2b5eb6f2d2caaf62fc5bf82afb05a50b39f02e6646c455afd5de9`; der Hash wurde nach Transfer verglichen. Das finale Quellarchiv mit 1.208 Dateien hat SHA-256 `73a6ee30e8a278133f4add1bb8fbec62daa657eaf6b43752be3e30b7630cbc9b`; ein Manifest bindet jede Quelldatei separat. `.git`, die lokale Kontextdatei und die Root-`.env` wurden nicht übertragen. Die spätere Ergänzung dieses Berichts ändert keine geprüften Implementierungsbytes.

Ein anfänglicher gezielter Lauf mit unbeabsichtigt `noexec` eingebundenem `/tmp` war wegen der Setupfehler ungültig und wurde nach explizitem `exec` wiederholt. Der erste Gesamtlauf wurde nach festgestellten Umgebungsfehlern abgebrochen (Exit 137): Docker-Default-seccomp sperrte die von Providerprüfungen benötigten Namespaces, und zwei Inventartests benötigten Git-Metadaten. Im korrigierten Wegwerfcontainer wird unverändert `docker/agent-seccomp-moby-29.6.1.json` geladen; ein leeres, ausschließlich lokal erzeugtes Git-Verzeichnis ermöglicht `ls-files`/`check-ignore`, ohne Repositoryhistorie zu übertragen oder einen Commit zu erzeugen. Beide Inventarklassen bestehen dort. Der Provider-Umgebungsvorlauf bleibt mit vier Fehlern rot: Bubblewrap meldet nun `Failed to make / slave: Permission denied`. Es wurden keine Hostschutzmechanismen deaktiviert.

### Tatsächlich ausgeführte gezielte Fälle

Die folgenden Werte stammen aus den JUnit-Dateien `targeted.xml`, `workflows.xml` und `workflows-final.xml`; überlappende Gruppen werden nicht addiert. Alle hier genannten Git-Fälle wurden unter Linux ausgeführt, ohne POSIX-Skips.

| Klasse / Fall | Ergebnis |
|---|---|
| `ManagedCloneSynchronizerTest` | 24 Tests, 1.050 Assertions, grün. Einschließlich SHA-1/SHA-256-Clone/Fetch im gemeinsamen Prozess, Branchwechsel, Speicherformatfehler, drei Clone-Crashgrenzen pro Format, Retry/Adopt und neuem Cloneauftrag nach terminalem Fehler. |
| `HardenedGitRunnerTest` | 12 Tests, 203 Assertions, grün; davon sechs negative Speicherformatantworten ohne Fallback. |
| `RunWorkspaceGitTest` | 10 Tests, 216 Assertions, grün; reale verschachtelte Blob-/Treeobjekte, A/M/D-Raw-Diffs und Ref-CAS beider Formate. |
| `TicketMutationExecutorTest` | 18 Tests, 887 Assertions, grün; reale Edit-/Status-/Approval-Effekte beider Formate, Runstart, Recovery und unveränderter interner 64-Nullen-Platzhalter bis zum CAS. |
| `ContractAmendmentExecutorTest` | Nach Reparatur der Fixture 5 Tests, 210 Assertions, grün. Die Fixture führt jetzt die vorhandene `ApprovalQueue::enqueue()` vor dem Runstart aus; es werden keine Approval-/Claim-Effekte direkt gesetzt. |
| `ApprovalInstructionSnapshotTest` | 3 Tests, 80 Assertions, grün; davon zwei echte Instruktionsblobs durch Collector, Resolver und Approval. |
| `ReviewOnlyExecutionTest` | 22 Tests, 1.293 Assertions; 17 bestanden, 2 Fehler, 3 Fehlschläge. Die neuen Format-Guardfälle und beide parametrisierten Checkpoint-/Review-/Reportfälle bestehen. Bestehende abweichende Fälle sind unten benannt. |
| `GitObjectFormatWorkflowTest::test_review_only_reaches_completed_after_real_report_status_cas` | Beide Formate bestanden, 162 Assertions: echter Worker, Report, Remote-Ticketstatus `in_progress → ready`, bestätigte Control-OID und Runzustand `completed`. |
| Übrige vier Fälle in `GitObjectFormatWorkflowTest` | Fehlgeschlagen: Implementierung und Publish-Negativprobe werden für beide Formate vor dem Push durch MG-01 blockiert. Letzter gezielter Klassenlauf: 6 Tests, 794 Assertions, 4 Fehlschläge. |

Der SHA-256-Clone-Crashfall bei `outcome_published` führte vor der echten Worker-Wiederaufnahme `down → up` aus und bestätigte bytegleiche Operationsdaten. Der Erstclone blieb bis zur tatsächlichen Formatbestätigung ungebunden. Der Clone-/Fetch-Fall führte für beide Formate einen echten `pushCommitCas` mit formatlangem Null-Sentinel aus; der zweite Create-only-Push wurde bei unveränderter bestehender Ref abgewiesen.

Die neue End-to-End-Fixture wurde anhand der echten Verträge korrigiert: Workspace und Checkpoint entstehen durch `RunWorkspaceLifecycle` und `RunCheckpointService`; die Gateantwort bindet den tatsächlich prospektierten Candidate. Beim Review-only-Abschluss wird der vorhandene Statusübergang nach `ready` und dessen realer Remote-CAS geprüft. Kein behaupteter Check-, Review-, Gate- oder Publisheffekt wird als Fixture gesetzt. Der Formatdurchlauf verwendet die vorhandene `BuildsCheckFixture` mit ihrer ausdrücklich bestätigten und im Policyhash sichtbaren Custom-Checker-Konfiguration; das ist kein Nachweis strikter Checker-Isolation.

### Vollständiges Linux-Abschluss-Gate

Ausgeführt als UID 10001 im korrigierten Container:

```bash
php artisan test --compact --log-junit /results/linux-full.xml --log-events-text /results/linux-full-events.log
```

**Exitcode 2; 2.032 Tests, 70.855 Assertions, 1.829,83 Sekunden.** JUnit weist 98 Assertion-Fehlschläge, 18 Fehler und 14 Skips aus; 1.902 Fälle haben keinen Fehler-/Skip-Eintrag, enthalten jedoch Warnungen. Die unveränderte Artisan-Kurzansicht meldet `116 failed, 1534 warnings, 1 skipped, 381 passed`: Dort werden 13 zusätzlich übersprungene Tests unter Warnungen eingeordnet. Der Gesamtlauf ist rot und wird nicht als bestandene Regression gewertet.

| Fehlergruppe | Anzahl fehlgeschlagener Fälle |
|---|---:|
| Provider-Prozessisolation, vier CLI-Execution-Klassen, Providerlogin, ein Mailboxfall der Finding-Verifikation sowie drei Doctor-Klassen | 90 |
| Reine Prozess-Unit-Tests ohne Laravel-Config-Container | 7 |
| Report-only-/Abbruch-Fixtures ohne Queue-Aufnahme | 9 |
| Queue-Fast-forward/Dependency-Fixture | 1 |
| Bestehende Review-only-Profil-/VERIFY-/Gate-Fixtures | 5 |
| Neue Implementierungs-/Publish-Negativdurchläufe, an MG-01 blockiert | 4 |
| **Gesamt** | **116** |

Die elf Doctor-Fehlschläge melden `codex_version_probe_failed`, nicht erbrachte Copilot-Evidenz beziehungsweise `agent_grok_version_drift` anstelle der jeweils erwarteten Folgeentscheidung. Die Provider-/Doctorgruppe wird daher nicht pauschal als ein erfolgreich geprüfter Fehlerpfad bezeichnet. Die Warnungsereignisse stammen überwiegend aus dem unterdrückten Leseversuch auf die nicht vorhandene `tests/.env` sowie fehlgeschlagener Bereinigung versiegelter Provider-Testverzeichnisse; sie wurden nicht unterdrückt oder wegkonfiguriert.

Die 14 Skips betreffen sechs Browser-Smokes, fünf reale Provider-/Onboarding-Smokes, einen nativen Grok-Doctor-Smoke, den Compose-Smoke und den historischen Composer-Provenienzvergleich (`b29d802` fehlt im bewusst historienfreien Testverzeichnis). Keiner davon gilt als bestanden. Die getrennte Locked-Install-Suite wurde nicht ausgeführt.

Der vollständige Lauf bestätigt erneut die obigen Formatfälle. Zusätzlich: `GitObjectFormatMigrationTest` mit 15 Tests/123 Assertions ohne Fehler oder Skip; `ContractAmendmentExecutorTest` mit 5/210; `ManagedCloneSynchronizerTest` mit 24/1.050; `RunRetentionSweepTest` mit 18/515. Die sechs neuen Workflowfälle behalten das konkrete Ergebnis: zwei Review-only-Endzustände bestanden, vier Implementierungs-/Publishfälle rot.

Die unveränderten Rohprotokolle sind dauerhaft unter dem repositoryrelativen, git-ignorierten Ablageort `storage/app/private/ai6-051-review/previous-linux/` gesichert. Maschinenbezogene Benutzer- und Temporärpfade werden nicht veröffentlicht. Nach dem Transfer wurden folgende SHA-256-Werte auf beiden Seiten verglichen:

| Datei | SHA-256 |
|---|---|
| `linux-full.xml` | `b82a19ac3e32324d7c09edc5f6edb538a55aa0e005ab562308e695e21b6f4fa8` |
| `linux-full-events.log` | `fc76a7eb9a08f33cacb6b52f0a58cb874a57d48ab92f1b882465a8b67ce0b1a5` |

Ein vorhandener Parser-Negativdatensatz trägt U+0001 im Testnamen; PHPUnit schreibt dieses Zeichen unescaped in die JUnit-Datei. Für die Auswertung wurde ausschließlich die im Speicher gelesene Kopie an dieser einen Stelle als `[U+0001]` dargestellt. Die Originaldatei bleibt unverändert und hashgebunden. Die bisherige Auswertung wird im selben dauerhaften Ablagebereich als `previous-linux-summary.json` aufbewahrt.

Nach Sicherung wurden beide damals verwendeten Testcontainer und ihr temporärer Testbereich entfernt; die Abwesenheit wurde geprüft. Der damalige abschließende Quellmanifestvergleich fand nur diesen ergänzten Bericht als Abweichung vom geprüften Archiv; sämtliche Implementierungs- und Testbytes waren identisch. Pint, vollständige PHPStan-Analyse, Composer-Validierung, Ticketmanifest und Diffprüfung bestanden lokal. Keine Änderung wurde committet, gepusht oder deployt. Die nachfolgende Reviewkorrektur erhält eigene Quellmanifeste und Testprotokolle.

### Offene Fehler und Scopeentscheidung

Der neue kohärente Implementierungslauf beantwortet MG-01 regulär über die HTTP-Route, einschließlich Step-up, Candidatebindung und abgewiesener Manipulations-/Replayversuche. Nach der Finalisierung erhöhen die regulären Phasenwechsel zu Security Review und Publish die Runversion. Unmittelbar vor Publish protokolliert der vollständige Linux-Lauf für beide Formate `run.version = 15`, `MG-01.state = closed` und `evidence_expected_run_version = 13`. `RunOrchestrator::invalidateStaleCandidateGateEvidence()` verlangt für den unveränderten gebundenen Candidate jedoch weiterhin Gleichheit zwischen der Evidenzversion und der aktuellen Runversion. `PublishCompletionService::assertCurrentEvidence()` ruft diese Entscheidung erneut auf; MG-01 wird dadurch geöffnet und der Schritt vor der Refprobe mit `MG-01` abgewiesen. Dies tritt für SHA-1 und SHA-256 auf.

Die drei beteiligten Methoden `invalidateStaleCandidateGateEvidence`, `candidateBindingIsCurrent` und `assertCurrentEvidence` sind nach lokalem normalisiertem Bytevergleich unverändert gegenüber HEAD `4ed8927`. Auch `ControlProcessRunner` und die beiden unten benannten Prozess-Testklassen sind gegenüber HEAD unverändert. Das ist Quellvergleichsevidenz, kein ausgeführter Baseline-Lauf: Die automatische Freigabeprüfung hat die zusätzliche Übertragung des vollständigen HEAD-Stands für einen separaten Vergleichslauf abgelehnt, weil sie die Linux-Freigabe dafür nicht als ausreichend bewertet hat. Der Transfer wurde nicht ausgeführt.

**Erforderliche menschliche Scope-/Vertragsentscheidung:** Die Gatebindung über reine Phasenwechsel hinweg korrigieren, während Candidate-, Ticket-, Scope-, Approval- und Checkpointdrift sowie Replay weiterhin geschlossen abgewiesen werden. Die konkrete Reproduktion liegt in `GitObjectFormatWorkflowTest` vor. Der Review-Fokus des beauftragten Tickets schreibt ausdrücklich vor: „Runs und HumanLoop ändern nur das Format bestehender OID-Bindungen; Autorisierung, Anti-Replay und Gateentscheidungen bleiben unverändert.“ Deshalb wurde diese fachliche Gateentscheidung im Objektformatauftrag nicht geändert. AC-06/TC-06 bleiben rot; ein Entfernen des Gates oder direktes Neuschreiben der Evidenz wäre kein zulässiger Nachweis.

Weitere beobachtete Fehler im bestehenden Linuxpfad:

- `ReviewOnlyExecutionTest` erwartet in drei Profilfällen ein Checkergebnis, obwohl die verwendete Fixture standardmäßig keine Checks bindet. Der Findings-Fall fordert unmittelbar `REPORT` an, obwohl der aktuelle Ablauf zunächst `VERIFY` einplant. Der Blockadefall setzt als Gate-Vertrag den im unveränderten Run noch leeren Wert statt der gebundenen Approval-Vertragsquelle; der bestehende Guard weist ihn ab. Die betreffenden Assertions wurden nicht abgeschwächt.
- Historisch fehlte `BlockedControlProcessTest` und `EffectLockRuntimeSecurityTest` der Laravel-Config-Container. Dieser Befund ist durch die Testbasisänderung und den vollständigen Linux-Nachweis beider Klassen vom 26. September behoben und zählt nicht mehr zu den offenen Bestandsfehlern.
- `ProjectQueueManagedGitTest::test_foreign_fast_forward_sequence_ends_in_one_atomic_claim_from_the_fresh_parent` scheitert am erwarteten Blockiergrund `dependency_unsatisfied:AI6-QUEUE-DEPENDENCY`. Die Testdatei und `QueueEligibility::dependencyState()` sind unverändert gegenüber HEAD. Die Fixture projiziert die Dependency vor dem Approval-Commit; die Entscheidung liest nur Projektionen am aktuellen Control-Commit. Eine ausgeführte Baseline-Gegenprobe liegt nicht vor; der Befund bleibt offen.
- Fünf Fälle in `ReportOnlyCompletionExecutorTest` und vier in `RunCancellationExecutorTest` starten ihren Claim ohne vorherige Queue-Aufnahme und scheitern an `approval_not_queued`. Beide Testdateien sowie `ApprovalClaimStarter` sind unverändert gegenüber HEAD; diese separaten Fixturefehler wurden nicht in den Objektformat-Diff aufgenommen.
- Die vollständige Provider-Isolation ist in der temporären Laufzeit auch mit der vorhandenen Repository-seccomp-Policy nicht nachgewiesen; der Bubblewrap-Mountfehler bleibt sichtbar.

MG-01 bleibt ergebnis- und signaturfrei. Das Formular `AI6-051_MG-01_ABNAHMEPROTOKOLL.md` verlangt den finalen Softwarecommit, laufendes Image, Git-Version, SSH-Remote, Ref/OID, Format, Operations-IDs und Endzustände, Ticket-/Config-Refresh sowie Datum und menschliche Signatur.

## Geänderte Dateien und Zweck

Pfade sind relativ zum Repository. Jede Zeile benennt genau eine Datei; vorhandene Nutzeränderungen sind oben ausgenommen.

| Datei | Zweck |
|---|---|
| `app/AI6/Git/GitObjectFormat.php` | Zentraler geschlossener Format-, OID-, Sentinel- und Git-Objekthashvertrag. |
| `app/AI6/Git/ProjectGitOidRule.php` | Eine gemeinsame HTTP-Validierungsregel delegiert an das gebundene Projektformat. |
| `app/AI6/Git/GitRemoteRefResponse.php` | Genau eine vollständige Antwortzeile zur exakt angeforderten Ref. |
| `app/AI6/Git/HardenedGitRunner.php` | Speicherformatabfrage, beide OID-Längen, formatpassende Tree-/Blobberechnung und Ref-CAS. |
| `app/AI6/Git/CanonicalDiffHasher.php` | Formatgleiche Raw-Diff-OIDs und kontextgebundene Null-Sentinels. |
| `app/AI6/Git/ManagedCloneSynchronizer.php` | Formatbestätigung und gemeinsame unveränderliche Format-/Control-Finalisierung einschließlich Recovery. |
| `app/AI6/Git/HardenedControlRemoteProbe.php` | Gemeinsame exakte Refantwortprüfung. |
| `app/AI6/Git/ControlBranchChanger.php` | Probe und bestehende Pending-Bindung gegen das Projektformat prüfen. |
| `app/AI6/Git/TicketMutationExecutor.php` | Formatgebundene Mutations-OIDs und lokale Refauswertung; Erfolg und Konflikt anhand von `status_operation` genau der zuständigen Abschluss- oder Abbruchsaga zuordnen. |
| `app/AI6/Git/PublishCandidateService.php` | Candidate-OIDs projektgebunden, Diffhash weiterhin SHA-256; ursprüngliche Ausnahme bei `candidate_generation_failed` weiterreichen. |
| `app/AI6/Git/PublishCandidateException.php` | Optionale Ursachenkette unter unverändertem fachlichem `reason` erhalten. |
| `app/AI6/Git/RunCheckpointService.php` | Checkpoint-Commit und -Tree nach geschlossenem OID-Vertrag. |
| `app/AI6/Git/ReviewSubject.php` | Gleiches Format zusammengehöriger Review-OIDs; Diffhash getrennt. |
| `app/AI6/Git/ReviewSubjectVerifier.php` | Review-Basis projektgebunden und Tree passend zur Quell-OID. |
| `app/AI6/Git/Actions/QueueControlBranchChange.php` | Bestehende Control-OID projektgebunden validieren. |
| `app/AI6/Git/Actions/QueueTicketReadModelRefresh.php` | Refresh nur an gültiger Projektbindung starten. |
| `app/AI6/Git/Actions/QueueTicketMutation.php` | Ticketblobs im gebundenen Git-Format berechnen; validierte Implementierungszielbytes einschließlich Recorded Scope bis zur Statusmutation bewahren. |
| `app/AI6/Git/Actions/QueueRunStart.php` | Claim-Blob im gebundenen Git-Format berechnen. |
| `app/AI6/Git/Actions/QueueContractAmendment.php` | Amendment-Blob im gebundenen Git-Format berechnen. |
| `app/AI6/Projects/Models/Project.php` | Nullable Enum-Cast und Modellvertrag für `object_format`. |
| `app/AI6/Projects/PendingControlBinding.php` | Pending-OID gegen das unveränderliche Projektformat prüfen. |
| `app/AI6/Projects/Http/ProjectConfigurationController.php` | HTTP-Control-/Blob-OIDs formatgebunden validieren. |
| `app/AI6/Runs/QueueEligibility.php` | Git-Blobidentität statt festem SHA-256-Objekthash. |
| `app/AI6/Runs/RunOrchestrator.php` | Claim-, Checkpoint-, Candidate-, Amendment- und Publish-OIDs projektgebunden halten; nach Candidate-Bindung unveränderte Gateevidenz über reine Versionsfortschritte bewahren, vor Bindung exakt prospektive Autorisierung verlangen. |
| `app/AI6/Runs/RunCancellationService.php` | Regulären Implementierungs- und Report-only-Abschluss anhand von `status_operation` von der Abbruchsaga ausschließen. |
| `app/AI6/Runs/RunFinalizationStep.php` | Die konsumierte Candidate-Ursache mit Klasse und zentral redigierter Meldung diagnostizierbar machen; Job-/Runentscheidung und Fehlercode beibehalten. |
| `app/AI6/Shared/Process/ControlProcessRunner.php` | Direct-Wrapper-PID auch nach schnellem Prozessende übernehmen und validieren; fehlende Identität weiterhin geschlossen verweigern. |
| `app/AI6/Shared/Process/RunningControlProcess.php` | Wrapperpräfix aus Nutz-/Beobachterausgaben entfernen; verbleibende Gruppenmitglieder nach Elternende weiter unter Prozesszählung, Laufzeitgrenze und Gruppenabbruch halten. |
| `app/AI6/Shared/Process/control-process-wrapper.sh` | Vor dem unveränderten Direct-`exec` die eigene PID als festes Protokollpräfix ausgeben. |
| `app/AI6/Runs/RunPreflight.php` | Git-Bindungen vom unveränderten Prüfsummenvertrag trennen. |
| `app/AI6/Runs/InstructionCandidateCollector.php` | Instruktionssammlung nur an bestätigter Projekt-Control-OID. |
| `app/AI6/Runs/PublishCompletionService.php` | Exakte Publishprobe, Projektformatbestätigung und formatlanger Fehlend-Sentinel. |
| `app/AI6/Runs/TicketApprovalController.php` | Approval-/Review-OIDs im HTTP-Vertrag an Projektformat binden. |
| `app/AI6/Tickets/TicketMutationController.php` | Erwartete Control-/Blob-OIDs im HTTP-Vertrag an Projektformat binden. |
| `app/AI6/Agents/InstructionSnapshotResolver.php` | Syntax und gleiches Format zusammengehöriger Instruktions-OIDs. |
| `app/AI6/Reviews/SecurityReviewStep.php` | Candidate-OIDs projektgebunden, Prüfsummen unverändert. |
| `app/AI6/Reviews/FindingVerificationRound.php` | Vor Berechnung keinen Git-Tree als Checkerhash speichern. |
| `app/AI6/HumanLoop/GateEvidenceHumanRequestBinding.php` | Candidate-Tree beider Formate bei unverändertem Diffhash. |
| `app/AI6/HumanLoop/SecurityGateHumanRequestBinding.php` | Formatgleiche Tree-/Basis-OIDs bei unveränderten Prüfsummen. |
| `resources/views/approvals/ticket.blade.php` | `pattern` und `maxlength` der drei OID-Felder projektgebunden. |
| `database/migrations/2026_09_24_000000_add_git_object_format_contract.php` | Eine neue Spalte, atomarer Altwertcheck/Backfill, 24 bestehende Trigger, zwei Projektguards und verweigerbarer Rückbau. |
| `tests/Unit/Git/GitObjectFormatTest.php` | Feste Git-Hashvektoren, Syntax-/Sentinelmatrix und strikte Refantworten. |
| `tests/Unit/Git/RunWorkspaceContractTest.php` | Raw-Diff-Negativfälle und fester unveränderter SHA-256-Goldenwert. |
| `tests/Unit/Git/TicketMutationDatabaseContractTest.php` | Mutationsguard in unabhängige Direktwrite-Matrix aufnehmen. |
| `tests/Unit/Git/TicketReadModelContractTest.php` | Bestehende künstliche Control-Bindung ausdrücklich als SHA-256 markieren. |
| `tests/Unit/Agents/InstructionSnapshotResolverTest.php` | Beide Instruktions-OID-Formate und gemischte/ungültige Bindungen. |
| `tests/Unit/HumanLoop/HumanRequestBindingTest.php` | OID-/Hashtrennung und tatsächliche Antwortfortsetzung nach Altbindungs-Migrationsrundlauf. |
| `tests/Unit/ScaffoldStructureTest.php` | Exaktes Klassen-/Migrationsinventar um die beauftragten Dateien erweitern. |
| `tests/Feature/Git/AssertsGitObjectGuards.php` | Unabhängige 24-Trigger-Matrix mit negativen Direktwrites bei gültigen Anwendungsschreibvorgängen. |
| `tests/Feature/Git/GitObjectFormatMigrationTest.php` | Backfill, atomare Fehler, enges Erstcloneprivileg und erlaubter/verweigerter Rückbau. |
| `tests/Feature/Git/GitObjectFormatWorkflowTest.php` | Echte FakeAgent-/Review-only-Durchläufe für beide Formate bis Publish und Status-CAS, einschließlich Recorded-Scope-Hash, INFO-stderr, Replayabweisung und irreführender Refantwort. |
| `tests/Feature/Git/BuildsManagedControlRuntimeFixture.php` | Optionales Git-Format und frische Laufzeitbindungen je Fixture. |
| `tests/Feature/Git/BuildsRunWorkspaceGitFixture.php` | Optionales Format realer Testrepositories. |
| `tests/Feature/Git/ControlOperationTestCase.php` | Mehrere temporäre Repositories und eindeutige Projekte für den gemeinsamen Formatprozess. |
| `tests/Feature/Git/HardenedGitRunnerTest.php` | Fehlerhafte oder fehlgeschlagene Speicherformatausgabe ohne Fallback. |
| `tests/Feature/Git/ManagedCloneSynchronizerTest.php` | Beide Formate, gemeinsamer Prozess, Crash-/Recoverygrenzen und neuer Cloneauftrag nach terminalem Fehler. |
| `tests/Feature/Git/RunWorkspaceGitTest.php` | Beide Raw-Diffformate, reale verschachtelte Gitobjekte und create-only Ref-CAS. |
| `tests/Feature/Git/TicketMutationExecutorTest.php` | Edit-/Status-/Approval-Effekte beider Formate und 64-Nullen-Platzhalter-CAS; bei fehlgeschlagenem Recovery-Fixture-Clone den bereits redigierten `last_error` in der Assertion ausgeben. |
| `tests/Feature/Git/ReportOnlyCompletionExecutorTest.php` | Beide Abschlussfixtures vor Runstart über `ApprovalQueue` einreihen, damit Implementierungsabschluss mit Recorded Scope, Phasencrashes, Redelivery und fremde Control-Commits erreicht werden. |
| `tests/Feature/Git/RunCancellationExecutorTest.php` | Echte Abbruch- und Recoveryfälle mit der produktiv erforderlichen Queue-Aufnahme vor dem Runstart prüfen. |
| `tests/Feature/Git/ContractAmendmentExecutorTest.php` | Echte Amendment-Effekte beider Formate und Guardprobe. |
| `tests/Feature/Git/ContractAmendmentTest.php` | Amendment-Guard an gültiger Fachoperation negativ prüfen. |
| `tests/Feature/Git/PublishCandidateTest.php` | Publish-/Run-/Approval-/Read-Model-Guards an gültigen Übergängen prüfen; unveränderte Implementierungszielbytes und die Abgrenzung des regulären Abschlusses von der Abbruchsaga nachweisen; Ursachenkette an echtem Git-Fehler prüfen. |
| `tests/Feature/Git/ControlBranchChangeTest.php` | Format der bereits gebundenen SHA-256-Fixture explizit setzen. |
| `tests/Feature/Git/ControlOperationCrashInjectionTest.php` | Format der bereits gebundenen SHA-256-Fixture explizit setzen. |
| `tests/Feature/Git/ControlOperationPersistenceTest.php` | Provenienzkonflikt mit formatgültiger fremder SHA-256-OID erhalten. |
| `tests/Feature/Git/ManagedCloneControlOperationTest.php` | Bestehende Control-Bindungen explizit als SHA-256 markieren. |
| `tests/Feature/Git/TicketReadModelRefreshTest.php` | Format der vorhandenen Refresh-Bindung explizit setzen. |
| `tests/Feature/Git/TicketReadModelRefreshWebTest.php` | Format der vorhandenen HTTP-Refresh-Bindungen explizit setzen. |
| `tests/Feature/Projects/ProjectConfigurationSnapshotTest.php` | Config-CAS und Step-up für beide Formate, Config-/Auditguardmatrix. |
| `tests/Feature/Projects/ProjectRegistrationSchemaTest.php` | Neue Spalte und ungebundene Neuregistrierung prüfen. |
| `tests/Feature/Reviews/FindingVerificationRoundTest.php` | Frühe Fehler ohne Checkerhash und danach wirklich berechneter Hash; Review-/Findingguards. |
| `tests/Feature/Reviews/ReReviewCompletenessTest.php` | Findingstatus-Guard im tatsächlichen Folgereview prüfen. |
| `tests/Feature/Runs/ApprovalInstructionSnapshotTest.php` | Echte Instruktionsdatei durch Collector, Resolver und Approval für beide Formate. |
| `tests/Feature/Runs/BuildsFinalizedRunFixture.php` | Ticketblobberechnung im jeweiligen Projektformat. |
| `tests/Feature/Runs/BuildsImplementationTurnFixture.php` | Bestehende künstliche SHA-256-Control-Bindung ausdrücklich markieren. |
| `tests/Feature/Runs/BuildsObservedRunFixture.php` | Bestehende SHA-256-Control-Bindung des Zweitprojekts ausdrücklich markieren. |
| `tests/Feature/Runs/BuildsReviewOnlyRunFixture.php` | Reale Review-only-Repositories und Ticketblobberechnung beider Formate. |
| `tests/Feature/Runs/PublishCandidateGateTest.php` | Candidate-/Gateguards und korrigierte Versionsentscheidung prüfen: Phasenfortschritte erhalten Evidenzbytes, zukünftige oder veraltete prospektive Versionen sowie Provenienzdrift bleiben abgewiesen. |
| `tests/Feature/Runs/RunFinalizationStepTest.php` | Unveränderte Jobstatus-Assertions um `failure_code` und die Sicherheitsgate-Diagnose um den wertfreien technischen Grund in `why_needed` ergänzen; redigierte Candidate-Ursache und unveränderte Fehlentscheidung prüfen. |
| `tests/Feature/Reviews/BuildsSecurityReviewFixture.php` | Rungebundene Klasse und redigierte Meldung der ursprünglichen Candidate-Ursache in der Statusassertion ausgeben. |
| `tests/Unit/Shared/Process/ControlProcessRunnerTest.php` | Elf deterministische Fälle für abgeschlossene Prozesse ohne Symfony-PID, Fehler/Redaktion, Ausgabegrenze, ungültige Identität und weiterhin begrenzte bzw. beendete Hintergrundkinder. |
| `tests/Unit/Shared/Process/BlockedControlProcessTest.php` | Bestehende sieben Direct-/Blocked-/Gruppen-/Lockfälle mit der benötigten Laravel-Testbasis vollständig ausführen; Assertions und Grenzen unverändert. |
| `tests/Unit/Shared/Process/EffectLockRuntimeSecurityTest.php` | Beide bestehenden Tests für unveränderliche Lockobjekte und Lockfreigabe nach Gruppen-SIGKILL mit der benötigten Laravel-Testbasis ausführen. |
| `tests/Feature/Runs/ReviewOnlyApprovalUiTest.php` | Rollen-/Step-up-Route und gerenderte OID-Feldlängen beider Formate. |
| `tests/Feature/Runs/ReviewOnlyExecutionTest.php` | Beide Review-only-Formate und direkte Insert-/Update-Guardproben. |
| `tests/Feature/Runs/RunRetentionSweepTest.php` | Retention-Upgradefixture vor ihrem Rückbau auch über die neue Objektformatmigration zurückführen; bestehende Legacy-Assertions erhalten. |
| `tests/Feature/Tickets/TicketUiTestCase.php` | Bestehende bestätigte UI-Fixtures explizit als SHA-256 markieren. |
| `tests/Feature/Tickets/TicketMutationWebTest.php` | SHA-1-Edit und Statusänderung über die tatsächlichen Step-up-Routen; fremde OID-Länge und Null-OID verweigern. |
| `tests/Feature/Tickets/TicketProfileQualificationTest.php` | Bestehende Control-Bindung der Profilfixture explizit markieren. |
| `tests/Feature/Shared/Runtime/RuntimeDocumentationTest.php` | Formatunterstützung, ruhendes Upgrade und erneuten Clone an README binden. |
| `tests/Fixtures/Agents/release-gate-write-audit.json` | Geänderte Fixturekontexte einzeln binden und zusätzliche negative Gateproben dokumentieren; vorhandene Bewertungen, Begründungen und `requires_service`-Lücken erhalten. |
| `README.md` | Formatbezogenen Clone-/Upgrade-/Rückbau-/Retrybetrieb dokumentieren. |
| `docs/AI6-051_MG-01_ABNAHMEPROTOKOLL.md` | Ergebnisfreies Formular für den menschlichen SSH-/Refreshnachweis. |
| `docs/AI6-051_IMPLEMENTIERUNGSNACHWEIS.md` | Dateizwecke, ausgeführte Nachweise und offene Schritte nachvollziehbar zusammenführen. |
| `tickets/AI6-051.md` | Menschlich freigegebene Candidate-Gate-Präzisierung, PID-/Prozessgruppenpräzisierung und tatsächlichen Files-Scope dokumentieren; die gesonderte Saga-/Recorded-Scope-Vertragsentscheidung ausdrücklich abgrenzen. |

Zusätzlich zum vermuteten Ausgangsscope sind die exakten Datei- und Schreibinventare, die Abschluss-/Abbruchregressionen und deren Fehlerdiagnostik, der ausdrücklich freigegebene gemeinsame PID-/Prozessgruppenfix samt Regressionstest sowie dieser Bericht nötig. `tickets/AI6-051.md` wurde zur freigegebenen Vertrags- und Scopepräzisierung geändert; die gesonderte Entscheidung zur Saga-/Recorded-Scope-Präzisierung wird im aktuellen Reviewnachweis ausgewiesen. Die neue Migration ist Bestandteil des Implementierungsauftrags. Bestehende Migrationen, Ticketstatus, Kriterien-IDs, Gateergebnisse, Plan, Auth-, Deployment- und Abhängigkeitsdateien wurden durch diese Korrekturen nicht verändert. Vorhandene Nutzeränderungen an `.gitignore` und `AGENTS.md` sowie die oben abgegrenzten parallelen AI6-052-Änderungen bleiben erhalten.

Bei der ursprünglichen Inventur wurden elf Quelleinträge in acht Methoden geprüft: Repositoryformatparameter und Singletonerneuerung in `managedFixture`, die explizite bestehende SHA-256-Bindung im Branch-Crashtest, eindeutige Projektkennungen in `configureRealWorkerRuntime`, zusätzliche negative Guardproben im Re-Review, formatgerechte Blobberechnung in `completedApproval` und `markTicketInProgress` sowie die expliziten Formatbindungen in `preparedImplementationRun` und `provisionedProject`. Die drei dort geänderten Schreibausdrücke fügen das Projektformat hinzu beziehungsweise berechnen den Git-Blob korrekt; die übrigen Ausdrücke bleiben gleich. Die späteren Gate-, Abschluss- und Diagnosekontexte sind in den jeweiligen Reviewabschnitten oben ergänzt. Kein Eintrag wurde entfernt oder von `requires_service` auf einen bestandenen Nachweis umklassifiziert.
