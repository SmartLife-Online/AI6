# AI6-035 — Implementierungsstand und Grok-Sandboxkorrektur

Stand: 14. September 2026. Ausgangscommit: `ecc00d08aca13df83a796f693c912cebd2b6cdfc`. Arbeitsstand auf `main`, nicht committed oder deployed. Maßgeblich ist Plan V1.7.8. Der Benutzer hat das Ticket auf `ready` gespeichert und danach Grok-API-Key-Onboarding, die Korrektur des Redaction-Architekturdetektors und notwendige Files-Scope-Ergänzungen ausdrücklich freigegeben. Status, Approval-/Run-Metadaten, Plan, AGENTS und Gateentscheidungen wurden nicht durch den Agenten geändert.

## Reviewkorrekturen vom 18. September 2026

Der menschliche Auftrag erlaubt die Findingkorrekturen, die Scopeerweiterung und die beiden zuvor offenen Linux-Plattformguards. Die folgenden Angaben ergänzen den historischen Stand vom 15. September; sie sind keine Ticket- oder Providerabnahme.

- `AgentCapabilityPending` trennt vorübergehend fehlende Startevidenz von einem fehlgeschlagenen Providerattempt. `RunImplementation`, `ReviewRound`, `FindingVerificationRound` und `SecurityReviewStep` parken an der bestehenden Polling-Naht, ohne Workspacefehler oder Verbrauch eines Providerattempts.
- Nachpräzisierung der Staging-Grenze: Vor dem Dispatch bleiben Generation und Deadline gebunden, nicht der Agentboot. Ein Neustart, ein noch zum alten Boot gehörender Bericht oder ein veralteter Präsenz-Heartbeat parkt bei unveränderter Generation bis zur bestehenden Deadline. Erst frische zusammengehörige Evidenz erlaubt Staging. Auch ein bereits angelegtes, aber noch nicht dispatchtes Home wartet bei Präsenzverlust und behält seine gespeicherte Bindung. Alte `_boot`-Stagingwerte werden bei Wiederaufnahme entfernt. Fehlende oder beschädigte Kanaldateien, Widerruf und Deadline bleiben terminal; die Bootbindung ab Claim und die Abweisung eines Bootwechsels während eines gestarteten Turns bleiben bestehen.
- Der altersgesteuerte Recheck liest `checked_at` des validierten Rohberichts. Sein Intervall beträgt höchstens 240 Sekunden und verkürzt sich unter Berücksichtigung der letzten Zyklusdauer. Vor einem Providerstart prüft der Agent abgelaufene Evidenz derselben Generation tatsächlich erneut; auch während dieser Probe bleiben die Heartbeats aktiv. Ein frischer negativer Bericht erlaubt keinen Start.
- Der Queue-Fingerprint enthält Alias, Generation und Zeilen. Reine Änderungen von Prüfzeitpunkt oder Boot-ID bei ansonsten gültiger Evidenz lösen keine Neubewertung aus; Verlust gültiger Evidenz, geänderte Zeilen und Generationen weiterhin schon. Der Queue-Test verändert Zeilen und Generation deterministisch statt zeitabhängig eine vermeintlich neue Publikation zu erzeugen.
- Optionale Claude-Profile werden anhand des Copilot-Alias und ausschließlich `claude-*`-Modellen erkannt, unabhängig von der Profil-ID. README und Runtime-Dokumentationstest stimmen mit Scheduler-Mounts, Pins und Rechecklogik überein; `docker/grok/` ist als nicht eingebundener Entwurf gekennzeichnet.
- Die beiden benannten Grok-Verifikationstests in `FindingVerificationRoundTest` tragen jetzt den ausdrücklich angefragten Linux-Guard für den Session-Symlink. Zusätzlich prüft ein plattformunabhängiger Copilot-Verifierfall das Parken und die Wiederaufnahme ohne fehlgeschlagenen Verifikationsversuch.

### Scope und Regressionen

`files` und **Expected initial scope** des Tickets enthalten jetzt zusätzlich, jeweils als `existing`: `app/AI6/Runs/RunImplementation.php`, `app/AI6/Reviews/ReviewRound.php`, `app/AI6/Reviews/FindingVerificationRound.php`, `app/AI6/Reviews/SecurityReviewStep.php` und `tests/Feature/Reviews/ReviewRoundTest.php`. Diese Pfade behandeln beziehungsweise prüfen das gemeinsame Wartesignal. Status, AC-/TC-/MG-IDs und Gateergebnisse bleiben unverändert.

Die Regressionen liegen in `CodexCliExecutionTest` (Staging, Wiederaufnahme, Neustart, Präsenzverlust, Widerruf, Deadline, gezählte native Claim-Probe und negativer Claim), `ProviderCapabilityReportTest`, `ProviderOnboardingEvidenceTest` (Alter, Zyklusdauer, Fingerprint und Claude-Optionalität), `ProviderLoginTest` (gezählte Proben und Heartbeat), `QueueReevaluationTest`, `ReviewRoundTest`, `FindingVerificationRoundTest` und `RuntimeDocumentationTest`. `AgentExecutionMailboxTest`, `AgentLifecycleLockTest`, `AgentExecutionDocumentTest`, `RunArchitectureTest` und `ReviewRoundArchitectureTest` prüfen die unveränderten Ausführungs- und Architekturverträge.

### Prüfevidenz vom 18. September 2026

- Aktuelle Nachprüfung der Staging-/Neustartkorrektur: die drei Filter `test_pending_staging_survives`, `test_staging_wait_remains`, `test_expired_start_evidence` sowie `ProviderCapabilityReportTest`, `ProviderOnboardingEvidenceTest`, `RuntimeDocumentationTest`, `AgentExecutionDocumentTest`, `RunArchitectureTest` und `ReviewRoundArchitectureTest`: **85 bestanden, 2220 Assertions**, keine Skips, ohne externe Runtime-Umgebungsvariablen unter Windows/PHP 8.5.5.
- Zusätzliche Mailbox-/Lebenszyklus- und Reviewregressionen: **47 bestanden, 1796 Assertions, 2 POSIX-Skips**. Die abschließende Prüfung vorhandener und geparkter Stagings einschließlich des noch nicht dispatchten Homes: **10 bestanden, 409 Assertions**, keine Skips.
- Die gezielte Windows-Nachprüfung der Korrekturen aus der ersten Reviewrunde vom selben Tag bestand ebenfalls: Bericht/Queue/Dokumentation 41 Tests (9 Linux-Skips), Codex-Ausführung und Finding-Verifikation 11 Tests (27 Linux-Skips); die ergänzte plattformunabhängige Verifier-Wiederaufnahme bestand separat. Dies sind gezielte Regressionen, kein vollständiger regulärer Gesamtlauf.
- Vollständige statische Analyse mit `php vendor/bin/phpstan analyse --no-progress`: bestanden, keine Fehler. Pint für alle in dieser Nachkorrektur geänderten PHP-Dateien, Manifestabgleich, `composer validate --strict` und `git diff --check`: bestanden. Der ergänzte `RuntimeDocumentationTest` bestand separat mit 3 Tests und 223 Assertions.
- Native Linux-Namespace-/Probe-/Claim-Nachweise und echter Imagebuild wurden in diesen Reviewrunden nicht erbracht. Die Skips ersetzen sie nicht; insbesondere die gezählte native Claim-Probe und die Grok-Symlinknachweise bleiben unter Linux auszuführen. MG-01 bleibt offen. Der externe Locked-Install-Nachweis vom 15. September ist historische Evidenz und wurde für diese Korrekturen ohne Dependencyänderung nicht wiederholt.

## Reviewkorrekturen vom 15. September 2026

Der menschliche Reviewauftrag hebt die frühere Entscheidung zur vorläufigen Quellbuildinstallation ausdrücklich auf. Das Dockerfile installiert ausschließlich Grok `1.0.5` aus der festen Hersteller-URL und prüft SHA-256 `9ba87444e1819e8f6104adbbf4676a870c204380aa5c3e1c38a926c4ea677238`. Der nicht fertig verifizierte Entwurf unter `docker/grok/` bleibt dokumentiert und ist nicht in den Imagebuild eingebunden. Ein späterer Wechsel erfordert die gemeinsame Umstellung von Adapterkonstanten, Login-Erwartung, Fixtures, README und Protokoll. Der bekannte native Grok-Sandboxblocker bleibt geschlossen; Schutzkontrollen werden nicht gelockert.

Die Startfreigabe benötigt weiterhin frische, bootgebundene Capabilityevidenz. Laufende Turns prüfen dagegen die altersunabhängige Widerrufsgeneration; Rotation entzieht weiter Projektion und Ergebnisimport. Auch das erneute Öffnen eines bereits gebundenen Turn-Homes zur Ergebnisabholung prüft keine neue Startfreigabe; der unveränderte Kontexthash bindet die damalige Auswahl, neue Stagings benötigen weiterhin frische Evidenz. Die Generationsprüfung liest ungecachete Berichtsbytes. Nur die Binary-Digests werden je Prozess nach Pfad, Größe, mtime, ctime und inode memoisiert. Unveränderte Metadaten sind die Cacheidentität; Installationen ersetzen Binaries atomar oder ändern ihre Metadaten.

Der Recheck lädt einen authentifizierten Katalog je Alias und prüft identische native Oberflächen einmal pro Recheck; tupelabhängige Freigaben bleiben einzeln geprüft. Der Probenheartbeat erneuert auch den Rollenheartbeat. Zwischen Zyklen liegen mindestens 240 Sekunden beziehungsweise das Dreifache der gemessenen letzten Zyklusdauer. Die unveränderte Capabilityfrist von 300 Sekunden wird dadurch nicht verlängert; bei langsamen oder ausgefallenen Prüfungen bleiben neue Starts bis zur echten Neupublikation gesperrt.

Der Scheduler erhält Berichte und Präsenz read-only sowie dieselben Pins und Laufzeitnachweise. Sein Fingerprint enthält die vollständigen validierten Berichte einschließlich Generation und Prüfzeitpunkt. Verifierkandidaten benötigen das konkrete Finding-Verifikationstupel. Die Profilseite verwendet eine Diagnose je Profil, und optionale Claude-Profile färben den Copilot-Sammelstatus nicht. Der Home-Manager verweigert Credentialdateien außerhalb der Agentrolle; Windows-Cleanup gibt versiegelte Dateien vor dem Entfernen frei.

Die vollständige Windows-Prüfung deckte zusätzlich eine fehlende Supervisorpräsenz in der In-Process-Mailboxfixture auf. Sie erneuert jetzt während Fake-Turns den echten Providerheartbeat, ohne Capabilitybericht oder Generation neu zu schreiben. Zwei Tests in `FindingVerificationRoundTest` wechseln dagegen tatsächlich zum Grok-Home mit seinem nativen Linux-Session-Symlink. Dieser ist auf dem Windows-Testhost nicht erzeugbar. Vorgeschlagene zusätzliche Plattformguards wurden von der automatischen Freigabeprüfung wegen des Verbots, Tests zu überspringen, abgelehnt; sie wurden nicht angewendet. Die Entscheidung dazu ist beim Benutzer angefragt. **Finding 3 ist deshalb noch nicht vollständig abgeschlossen.** Die Produktionsgrenzen wurden dafür nicht angepasst.

Die nachfolgenden älteren Prüfergebnisse beschreiben den vorherigen Arbeitsstand und sind keine Abschlussnachweise dieser Reviewkorrekturen. Aktuelle Prüfergebnisse werden separat ergänzt. Linux-/Image- und manuelle Providerabnahme bleiben bis zur tatsächlichen Ausführung offen.

### Zuordnung der 14 Reviewfindings

Alle Findings sind im vorliegenden Arbeitsstand berechtigt; die Korrekturen verändern keine menschliche Abnahmeentscheidung.

| Finding | Korrektur und Regression |
|---|---|
| 1 — Grok-Pin-Drift | Herstellerdownload 1.0.5 samt SHA-256 statt unbestätigtem Quellbuild; alle drei Installationen in `RuntimeScriptsTest` gebunden. |
| 2 — Berichtsalter bricht laufende Turns ab | Frische Startfreigabe und altersunabhängiger Widerruf getrennt, einschließlich Wiederöffnung des gebundenen Homes; `ProviderCapabilityReportTest`, `ProviderOnboardingEvidenceTest`, `ProviderProcessScopeTest`, `CodexCliExecutionTest`. Native Prozess-/Importnachprüfung dieser Revision noch offen. |
| 3 — Windowsfehler | Teilweise korrigiert: Namespacefixtures und Cleanup repariert, Fake-Supervisorheartbeat ergänzt. Zwei zusätzliche Grok-Profilwechseltests benötigen noch eine freigegebene Plattformbehandlung; kein grüner Windows-Gesamtnachweis. |
| 4 — Wiederholtes Binary-Hashing | Gemeinsamer Cache und zählender Test mit Metadatenänderung in `ProviderOnboardingEvidenceTest`. |
| 5 — Wiederholte Proben/Katalogabrufe, fehlender Rollenheartbeat | Proben innerhalb eines Rechecks wiederverwendet, ein Katalog je Alias, Heartbeat durchgereicht und Pause an Zyklusdauer gebunden; zählender nativer Test in `ProviderLoginTest`, dessen Linuxausführung hier offen bleibt. |
| 6 — Scheduler ohne Evidenzquelle | Read-only Mounts, Pins und vollständige validierte Berichte im Fingerprint; `RuntimeComposeContractTest` und `QueueReevaluationTest`. |
| 7 — Verifier ohne passendes ready-Tupel | Provider-/Rollen-/Modell-/Effortprüfung plus profilgenaue Auflösung; Negativtest in `ProviderOnboardingEvidenceTest`. |
| 8 — Lücken in TC-09 | Download-, Digest-, Init-, Boot-ID- und tmpfs-Verträge in `RuntimeScriptsTest` und `RuntimeComposeContractTest`. |
| 9 — Optionales Claude färbt Copilot-Doctor | Optionale Claude-Diagnosen bleiben sichtbar, beeinflussen den V1-Sammelstatus nicht; `ProviderOnboardingEvidenceTest`. |
| 10 — Credentialkopie im Worker | Nichtleere Projektionen außerhalb Agentrolle früh abgewiesen; `ExecutionHomeManagerTest`, rollenkorrekte Adapterfixtures. |
| 11 — Codex-Pin doppelt literalisiert | Login liest geprüfte Adapterversionen und erwartete Versionszeile aus der bestehenden Konfiguration. |
| 12 — Doppelte Profildiagnose | Eine Diagnose je Profil liefert zugleich den dargestellten Status; `AgentProfileViewTest`. |
| 13 — Veralteter Copilot-Kommentar | Rollen, vorhandener Adapter und berichtsgebundene Generation in `.env.example` berichtigt. |
| 14 — Unklare AC-Testzuordnung | Tatsächliche Testklassen je AC aufgeführt; neue AC-06-Nachweise von unveränderten Regressionen unterschieden. |

### Aktuelle Prüfevidenz der Reviewkorrekturen

| Prüfung | Ergebnis am 15. September 2026 |
|---|---|
| `php artisan test --compact --log-events-text=storage/framework/testing/ai6-035-review-events.log` ohne externe Runtime-Umgebungsvariablen | Fehlgeschlagener Gesamtlauf nach 1510 `Test Finished`-Ereignissen beendet, weil er noch die alte geladene Supervisor-Fixture verwendete. 15 Fehler erfasst: zwei Grok-Profilwechseltests sowie 13 Fälle aus `SecurityReviewCandidateIsolationTest`, `ReReviewCompletenessTest`, `SecurityReviewIsolationTest` und `FixLoopTest`. Die Fixturekorrektur wird durch die nachfolgenden gezielten Läufe geprüft. **Kein bestandener Gesamtlauf.** |
| Gezielte Nachprüfung nach dem letzten Staging-Fix: `AgentExecutionMailboxTest`, `AgentLifecycleLockTest`, `ImplementationImportIsolationTest`, `ImplementationInstructionTest` und neuer Staging-Alterstest | 52 bestanden, 3 POSIX-Skips, 1882 Assertions; `storage/framework/testing/ai6-035-review-staging.log`. |
| Nachprüfung der Supervisor-Fixture: `SecurityReviewCandidateIsolationTest` und `ReReviewCompletenessTest` | 3 bestanden, 727 Assertions, keine Skips; `storage/framework/testing/ai6-035-review-review-fixtures.log`. |
| Weitere Nachprüfung derselben Fixture: `SecurityReviewIsolationTest` und `FixLoopTest` | 14 bestanden, 2733 Assertions, keine Skips; `storage/framework/testing/ai6-035-review-fix-loop.log`. Damit sind die 13 Heartbeat-Folgefehler des abgebrochenen Gesamtlaufs in ihren vollständigen Testklassen nachgeprüft. |
| `php vendor/bin/pint --test` | Vollständiger Lauf bestanden; danach beide zuletzt geänderten PHP-Dateien erneut bestanden. |
| `php vendor/bin/phpstan analyse --no-progress` | Vollständiger Lauf nach dem letzten Codefix bestanden, keine Fehler; `storage/framework/testing/ai6-035-review-phpstan.log`. |
| Composer `validate --strict` und `check-platform-reqs` | Beide bestanden mit PHP 8.5.5. |
| Externer `LockedInstallTest` | 2 Tests, 1227 Assertions bestanden mit explizitem `AI6_PHP85_BINARY=C:/php/php.exe` und `AI6_COMPOSER_PHAR=C:/ProgramData/ComposerSetup/bin/composer.phar`; `storage/framework/testing/ai6-035-review-locked-install.log`. |
| Manifestabgleich und `git diff --check` | Bestanden. |
| Linux-Namespace-/Provider-Prozessnachprüfung und neuer Imagebuild | Nicht ausgeführt: Docker Desktop stellt trotz Startversuch keine erreichbare Linux-Engine bereit (`dockerDesktopLinuxEngine` fehlt). Die Windows-Skips liefern diesen Nachweis nicht. Insbesondere die neuen nativen Tests für lang laufende Turns und den gezählten Recheck müssen unter Linux noch ausgeführt werden. |
| MG-01 / echte Providerkonten | Unverändert offen, keine Anmeldung oder Abnahme ausgeführt. |

### Nicht gefixte Findings am 15. September (historisch)

**Historischer Stand vom 15. September — Finding 3 war teilweise offen:** `FindingVerificationRoundTest::test_a_contradicting_verifier_result_is_persisted_as_advisory_evidence_only` und `FindingVerificationRoundTest::test_invalid_verifier_schema_uses_the_bounded_retry_and_existing_human_request_path` wechseln zum Grok-Home. Dessen fester Session-Symlink scheitert auf Windows in `ExecutionHomeManager`; die bestehenden Sicherheits- und Testerwartungen bleiben unverändert. Die automatische Freigabeprüfung lehnte die zusätzlichen Linux-Plattformguards als Testüberspringen ab. Zum damaligen Stand lag keine zusätzliche Benutzerfreigabe vor; der Auftrag vom 18. September hat die Guards ausdrücklich freigegeben, siehe den neueren Abschnitt oben. Der Windows-Gesamtnachweis muss nach Klärung erneut vollständig laufen.

Für die übrigen Findings sind die Codekorrekturen umgesetzt. Die ausdrücklich genannten nativen Nachweise zu Findings 2 und 5 sowie der Imagebuild bleiben mangels erreichbarer Linux-Engine unbestätigt; sie sind keine bestandenen Gates. Es erfolgten weder Commit noch Push, Ticketstatus und manuelle Gateergebnisse bleiben unverändert.

## 1. Ergebnis

**Reviewbarer Implementierungsstand; noch keine vollständige Ticketabnahme.** Implementiert sind Login/Logout für alle drei V1-Aliasse, persistenter Minimalstore, zufällige Generation mit vorgezogenem Widerruf, ausschließlich agentseitige read-only Authprojektionen, echte Linux-Abschirmung des Providerprozesses, native Agentprüfungen, dynamische Berichtskonsumenten, Profilanzeige, feste Imagepins und Betriebsdokumentation. Fehler, Cancel, Rotation und Berichtsentzug sind automatisiert geprüft. Die früher offene Grok-Authentifizierungsentscheidung und die Detektorkorrektur sind umgesetzt.

Der verbleibende technische Blocker ist die native Sandbox von Grok 1.0.5: Die CLI verlangt vor dem Start einen zusätzlichen Schreibzugriff im versiegelten Home. Der bestehende Schutz verweigert ihn. MG-01 bleibt außerdem mangels echter Testaccount-Abnahme, Implementierungscommit und Signatur offen. Ein grüner Test, der diesen geschlossenen Fehler bestätigt, ist kein erfolgreicher Providerturn.

### Native Auth-/Modellnachweise

Codex verwendet die native Device-Anmeldung, minimale `auth.json` und eine frische native Online-Modellabfrage. Für Copilot wird der Token verdeckt eingelesen und über stdin an `login --with-token` übergeben; die anschließende kontobezogene Prüfung nutzt ausschließlich den turnfreien JSON-RPC-Aufruf `models.list` der gepinnten CLI. Die mitgelieferten Dateien `copilot-sdk/index.js` und `copilot-sdk/generated/rpc.d.ts` definieren diesen Aufruf und seine Modellpolicy. Die echte CLI lieferte ohne Account im netzlosen Testcontainer den erwarteten Authentifizierungsfehler. Positive Antworten und Policyfälle sind mit Prozessdoubles geprüft. Die [offizielle SDK-Dokumentation](https://docs.github.com/en/copilot/how-tos/copilot-sdk/troubleshooting/compatibility) beschreibt die CLI-Kommunikation über JSON-RPC; es wurde kein SDK installiert.

Grok verwendet den freigegebenen bestehenden `token`/`XAI_API_KEY`-Vertrag. Die native Prüfung startet `grok models` in einem frischen privaten Home. Exitcode 0 oder sichtbare Modellnamen reichen nicht: Die echte CLI 1.0.5 gibt auch nach drei fehlgeschlagenen Remoteversuchen ihren eingebauten Katalog aus und beendet sich erfolgreich, ohne einen Cache zu schreiben. Übernommen wird deshalb ausschließlich ein neu erzeugter, begrenzter `models_cache.json` mit frischem `fetched_at`, Version `1.0.5`, `auth_method=api_key`, Herstellerorigin `https://api.x.ai/v1/models` und exakt ausgewähltem, sichtbarem und API-fähigem Modell. Bei `provider_default` muss auch die native Default-Modellzeile passen. Fremde Origin/Authmethode/Version, alte oder zukünftige Zeiten, ungültiges JSON, fehlende Modellberechtigung und Offlinefallback verweigern die Übernahme; der bisherige Store bleibt unverändert.

Die tatsächliche Cacheform wurde mit dem gepinnten Binary an einem ausdrücklich lokalen synthetischen Modellendpunkt untersucht. Der produktive Prozess erlaubt diese Endpunktumleitung nicht. Das ist Formatevidenz, kein echter Accountnachweis. Der [Hersteller beschreibt den Modellendpunkt als Liste für den authentifizierenden API-Key](https://docs.x.ai/developers/rest-api-reference/inference/models). AI6 startet ausschließlich die CLI und führt keinen eigenen Provider-HTTP-Adapter ein.

## 2. Dateien und Scope

Die vollständige Dateiliste mit Zweck folgt am Dokumentende. Der freigegebene Ticket-Scope enthält zusätzlich die betroffenen Agentfixtures, Git-/Review-/Queue-/Security-Regressionen, den Architekturdetektor und diesen Bericht. Der vorhandene Prozesswrapper entfernt jetzt das von `dash` erzeugte `PWD`; die Environment-Allowlist bleibt unverändert. `QueueAutoStarter` wiederholt erkannte Datenbankkonflikte höchstens zweimal nach einem vollständigen Rollback (50/100 ms) und verwendet bei dauerhaftem Fehler die vorhandene benannte Ablehnung. Fachliche Ablehnungen werden nicht wiederholt. Zwei zusätzliche Git-Testfixtures behandeln Symlinks beim Cleanup ohne Zugriff auf deren Ziel. Die Scopeergänzung ändert keine `spec_refs`, AC-/TC-/MG-IDs, Ticketstatus oder Gateergebnisse. Es wurde kein `Recorded Scope` eingefügt.

### Korrektur des Architekturdetektors

Die ausdrücklich freigegebene Präzisierung betrifft ausschließlich `RedactionArchitectureTest::definesRedactionRules()`. Die fünf ursprünglichen positiven Detektorbeispiele, Redactionmarker, `new RedactionRule(...)`, Definition-Allowlist und vollständige Repositoryprüfung bleiben erhalten. Der bereits vorhandene Entwicklungsparser prüft das tatsächliche Pattern-/Suchargument einschließlich Array- und benannter Argumente; ein späterer Variablen- oder Exceptionname definiert keine Secretregel. Geschlossene Regex-Literalsammlungen werden weiterhin in allen untersuchten Sprachen geprüft. Zusätzliche positive Mutanten sichern Secret-/Password-/Token-/Credential- und Provider-Key-Muster ab. Dateipfade, allgemeine Hex-/ASCII-Formate und First-class-callable-Syntax sind negative Kontrollfälle. Die produktive Redactionlogik wurde nicht verändert.

### Fünf am ursprünglichen HEAD reproduzierte Linuxfehler

| Betroffener Nachweis | Ursache und Korrektur |
|---|---|
| `CheckIsolationTest` | Der POSIX-Interpreter erzeugt selbst bei leerem Environment ein exportiertes `PWD`. Der feste Wrapper entfernt es vor dem kontrollierten Start. Die unveränderte Leckassertion und ein neuer Test auf exakt leere Prozessumgebung bestehen. |
| `PublishCandidateTest` | Die Fixture änderte nur den Gitindex auf Modus `120000`, ließ aber eine reguläre Arbeitsbaumdatei zurück. Sie materialisiert jetzt den echten Symlink; die unveränderte Erwartung `candidate_symlink_forbidden` wird erreicht. |
| `ProjectQueueStartFeatureTest` | Nach `DB::purge` hielt die gecachte DatabaseQueue noch die alte Connection und öffnete neben der Claim-Schreibtransaktion eine zweite PDO. Vor dem Fork wird auch diese Queueinstanz verworfen. Eine spätere Nachprüfung legte zusätzlich eine echte SQLite-Konkurrenz offen: Die sofortige Ablehnungs-Telemetrie des ersten Verlierers konnte den Snapshot des anderen Claims entwerten. Der begrenzte Wiederholungsversuch erfolgt jetzt vor dieser Telemetrie und nur bei vollständig zurückgerollter Transaktion. Fünf aufeinanderfolgende native Paralleltests erzeugen jeweils genau einen Claim. Dauerhafte Konflikte enden nach drei Versuchen benannt und ohne Änderung bestehender Run-/Approval-/Projektbindungen. |
| Zwei `SecurityBootstrapTest`-Fälle | Erfolgreicher Security-Bootstrap bedeutet seit AI6-035 keinen grünen Gesamt-Doctor ohne Providerberichte. `artisan about` muss erfolgreich starten, die drei Security-/Checkerprüfungen bleiben explizit grün; genau die drei Providerdiagnosen müssen fehlen und Exitcode 1 erklären. Die bisherigen Securityassertionen bleiben erhalten. |

Der Queue-Fixturefix ändert den Kontext einer schon zuvor auditierten direkten Run-Aktualisierung. Deshalb wurde **genau ein** `context_sha256` in `tests/Fixtures/Agents/release-gate-write-audit.json` neu gebunden: `test_two_parallel_auto_start_attempts_claim_exactly_one_next_run#1`, von `a96d8512670a627cc82748902cc70df7eb009f3a50363aca7175276d1c99ead0` auf `2318c855b269255620b476c6983ea816955aeaca5d5c5e0aeabac7b29480ea79`. Schreibausdruck, Begründung und Entscheidung **`requires_service` bleiben unverändert offen**. Kein Audit wurde entfernt oder als akzeptiert umklassifiziert; die vorhandenen Release-Gate-Lücken AC-02/AC-04 werden damit nicht geschlossen.

## 3. Akzeptanzkriterien

| AC | Implementierter beziehungsweise automatisiert geprüfter Stand | Testklassen | Verbleibende Evidenz |
|---|---|---|---|
| AC-01 | Drei minimale Logins, verdeckte Eingabe, fremde Rolle/Aktion, Fehler, Cancel und Rotation | ProviderLoginTest | Positive reale Testaccount-Logins in MG-01 |
| AC-02 | Reale Mount-/PID-Namespaceprüfung mit negativer Lecksonde; Compose-Rollenzuordnung | ProviderProcessScopeTest, RuntimeComposeContractTest | Signierte Prüfung aller tatsächlichen Compose-Rollen |
| AC-03 | Exakte Tupelauswahl; getrennte Copilot-Policies; Binary-, Runtime-, Generation- und menschliche Bindung | ProviderCapabilityReportTest, ProviderOnboardingEvidenceTest | Positive reale Account-/Laufzeitnachweise; Grok bleibt gesperrt |
| AC-04 | Geschlossenes Berichtsschema, Boot/Alter, Korruption, drei Diagnosen, lesende Doctor/UI-Pfade; Agentprobe erreicht native Grok-Sandboxvorbereitung | ProviderCapabilityReportTest, CodexCliDoctorCheckTest, GrokCliDoctorCheckTest, GitHubCopilotCliDoctorCheckTest, DoctorCommandTest | Erfolgreicher Grok-Sandboxstart technisch blockiert |
| AC-05 | Produktive Agentprojektion, read-only Auth, Cleanup, verweigerter Worker-Direktstart; Mailboxturns mit Doubles | ProviderProcessScopeTest, ExecutionHomeManagerTest, CodexCliExecutionTest, GrokCliExecutionTest, GitHubCopilotCliExecutionTest, ClaudeCopilotCliExecutionTest | Reale Providerturns in MG-01, insbesondere Grok nach Behebung des Blockers |
| AC-06 | Zufallsgeneration, Widerruf vor Storewechsel, Prozessabbruch, veraltete Bindungen und verspätete Probe | ProviderProcessScopeTest, ProviderOnboardingEvidenceTest, CodexCliExecutionTest; unverändert: AgentExecutionMailboxTest, AgentLifecycleLockTest | Reale Rotation/Logout mit laufendem Turn in MG-01 |
| AC-07 | Pin-, Binary-, Runtime- und menschlicher Evidenzdrift; neu datierter Altbericht bleibt ungültig | ProviderOnboardingEvidenceTest | Menschliche Bestätigung im realen Upgradeablauf |
| AC-08 | Berechtigte GET-Seite; keine Secret-/Generationsanzeige; Auswahl, Claim, Start und Resume nach Berichtsentzug im gebooteten Worker gesperrt; Approval unverändert | AgentProfileViewTest, ApprovalEligibilityFeatureTest, QueueReevaluationTest | Zusammenhängender realer Ablauf in MG-01 |
| AC-09 | Feste Downloads und SHA-256, Imagebuild, README, getrennte Smoke-Voraussetzungen | RuntimeScriptsTest, RuntimeComposeContractTest, RuntimeDocumentationTest, ScaffoldStructureTest, ProviderOnboardingSmokeTest | Positive externe Fresh-install-Abnahme |
| AC-10 | Ergebnisfreie MG-01-Vorlage vorhanden | Keine automatisierte Abnahme; MG-01 | Vollständiges manuelles Gate, Implementierungscommit und Signatur |

Diese Zuordnung ist keine Abnahmeentscheidung und ändert keine Ticket-Checkbox.

## 4. Prüfungen

Linux-Nachweise laufen als UID 10002 ohne Netzwerk, ohne zusätzliche Capabilities, mit read-only Containerroot, ausgeliefertem Agent-Seccomp-Profil und tatsächlichen Bubblewrap-Namespaces. Ausführbare Testfixtures liegen auf einem separaten Mount; die native Mailbox nutzt das tatsächliche noexec/nosuid/nodev-tmpfs `/dev/shm`. Der feste Prozesswrapper ist read-only. Windows ist nicht die POSIX-Evidenz.

| Kommando / Prüfgruppe | Ergebnis |
|---|---|
| `php artisan test` — vollständige reguläre Linux-Suite | **Exitcode 0: 1804 bestanden, 128 Skips, 2 Warnungen, 68127 Assertions; 1513,37 s.** Danach wurden der sporadische Queue-Konkurrenzfall und die beiden Cleanup-Warnungen korrigiert; die ausdrücklich genannten gezielten Nachprüfungen binden diese letzten Änderungen. Es wurde danach kein zweiter Gesamtlauf ausgeführt. |
| Gesamter Agent-/Doctorbereich: `php vendor/bin/phpunit tests/Feature/Agents tests/Feature/Shared/Doctor tests/Unit/Agents` | Vor der letzten Ergänzung: 474 bestanden, 6 externe Skips, 15409 Assertions. Der Abschlusslauf umfasst die nachfolgenden Grok-/Start-/Resume-Ergänzungen. |
| Vertrags-/Regressionsgruppen während der Umsetzung | 163 Tests / 3701 Assertions sowie 59 Tests / 7043 Assertions bestanden. |
| Abschließende Linux-Nachprüfung: Architekturdetektor, Queue-Folgestart, Release-Audit, Providerlogin, Runtime-Dokumentation, Publish-Completion, Queue-Reevaluation, Workspace- und Candidate-Verträge | **66 Tests / 3049 Assertions bestanden, keine Warnung und kein Skip.** Einschließlich der nach dem Gesamtlauf korrigierten Konkurrenz- und Cleanupfälle; Ausgabe `storage/framework/testing/ai6-035-completion-targeted-linux.log`. |
| `php vendor/bin/phpunit tests/Unit/Shared/Redaction/RedactionArchitectureTest.php` | 3 Tests / 972 Assertions auf Windows bestanden; letzter Linuxstand Bestandteil der abschließenden gezielten Prüfgruppe. |
| `php vendor/bin/phpunit tests/Feature/Runs/ProjectQueueStartFeatureTest.php` | Letzter Linuxstand: 10 Tests / 274 Assertions bestanden; zusätzlich fünf aufeinanderfolgende Paralleltests mit je 37 Assertions bestanden. |
| Wrapper-Environment plus unveränderter `CheckIsolationTest` | Linux: 2 Tests / 43 Assertions bestanden. |
| `php vendor/bin/phpunit tests/Feature/Runs/FakeAgentReleaseGateContractTest.php --filter=write` | 3 Tests / 407 Assertions bestanden. Der offene Auditstatus bleibt offen. |
| `AI6_GROK_BINARY=/usr/local/bin/grok php vendor/bin/phpunit --filter=GrokCliNativeDoctorSmokeTest` | 1 Test / 16 Assertions bestanden: Nachweis des geschlossenen nativen Sandboxfehlers, **kein** grüner Provider-Smoke. |
| `AI6_RUN_COMPOSE_SMOKE=1`, Onboardingflag aus; nur `ProviderOnboardingSmokeTest` | 1 Skip, kein Providerlogin; kein externer Nachweis. |
| `AI6_RUN_PROVIDER_ONBOARDING_SMOKE=1`, Testzugangsfreigabe `0`; nur `ProviderOnboardingSmokeTest` | Erwarteter Fehler vor jeder Anmeldung: `Explicit test-account authorization is required.` |
| `php vendor/bin/pint --test` | Vollständiger Lauf sowie gezielte Nachprüfung aller danach geänderten PHP-Dateien bestanden. |
| `php vendor/bin/phpstan analyse --no-progress` | Bestanden: keine Fehler. |
| Composer `validate --strict` und `check-platform-reqs` | Bestanden, PHP 8.5.5. |
| `php scripts/generate-ticket-manifest.php --check` | Bestanden: `Ticket manifest is current.` |
| Externer `LockedInstallTest` mit `AI6_PHP85_BINARY=C:/php/php.exe` und `AI6_COMPOSER_PHAR=C:/ProgramData/ComposerSetup/bin/composer.phar` | 2 Tests / 1220 Assertions bestanden. Kein Fallback auf eine implizite Runtime. |
| `git diff --check` | Bestanden. |
| `docker build --tag ai6-035-candidate:20260914 .` | Vollständiger Imagebuild bestanden. Lokale Image-Kennung `sha256:d3cf5eaba6a12db5112d5cd5298d7781c8f88d2182eaad0ce53ea065db3fbafe`; Build-Konfiguration `sha256:221c7de7c95886304313fcf2a34e2be6ab1e990df342a8258c1e8ab516e46ab1`. Der Build enthält auch die letzte Queue-Korrektur. Kein Deployment. |

Die zwei Warnungen des Gesamtlaufs stammten von `chmod` auf Symlinkziele beim Fixture-Cleanup. Die Cleanup-Helfer folgen diesen Links jetzt nicht mehr; beide unveränderten negativen Symlinktests bestehen unter `php artisan test --filter=... --fail-on-warning` (2 Tests / 39 Assertions).

Frühere rote Gesamtausgaben und die zwischenzeitlich fehlgeschlagene Queue-Nachprüfung bleiben lokale Entwicklungsevidenz; maßgeblich sind der Abschlusslauf und die danach ausdrücklich genannten gezielten Nachprüfungen. Skips oder Warnungen werden nicht als bestanden gezählt. Die Ausgaben liegen unter `storage/framework/testing/ai6-035-*.log` und sind ignorierte, nicht signierte Laufzeitevidenz. Das separate FakeAgent-Release-Gate bleibt mit seinen erklärten Lücken AC-02/AC-04 absichtlich nicht grün.

## 5. Offene Gates

**AI6-035/MG-01 bleibt vollständig offen.** Es wurden keine echten Providerzugänge gelesen oder verwendet, keine echten Accounts angemeldet und keine menschlichen Laufzeitnachweise erzeugt. Die Abnahmevorlage bleibt ergebnisfrei und ohne Signatur. Der Arbeitsstand ist nicht committed und damit noch kein an einen Implementierungscommit gebundener Abnahmekandidat.

## 6. Verbleibender Entscheidungsbedarf und Abschlussweg

Die tatsächliche Grok-CLI 1.0.5 scheitert in der unveränderten nativen Sandboxvorbereitung mit Exitcode 1. Der wertfrei protokollierte Befund nennt einen nicht erzeugbaren Bubblewrap-Platzhalter für einen Read-deny-Pfad und verweigert anschließend eine teilweise Sandbox. Die gepinnte Binärdatei enthält hierfür die Homepfade `sandbox-blocked` und `sandbox-blocked-dir`. Dies wurde mit dem realen Binary, den tatsächlichen Guardargumenten, versiegeltem Home und tatsächlichem Bubblewrap reproduziert, ohne einen Modellturn oder Zugangsdaten zu verwenden.

Plan V1.7.8 §4.2 erlaubt ausschließlich den festen Link `home/sessions` nach `resultDirectory/grok-sessions`; reguläre Home-Inhalte bleiben versiegelt. Das Ticket schließt neue Providertransporte aus. Die erteilte Freigabe für API-Key-Onboarding und Files-Scope ändert diesen Vertrag nicht. Ein beschreibbares Home, entfernte Deny-Regeln oder vorgetäuschte Sandboxeingaben sind deshalb keine zulässige Reparatur.

Empfohlener Abschlussweg: den Grok-Transport als konkreten Folgeauftrag am bestehenden AI6-041-Vertrag korrigieren. Zuerst einen herstellerseitig unterstützten, nachweislich kompatiblen Pin beziehungsweise eine dokumentierte Umleitung ausschließlich der Sandbox-Arbeitsdateien in den privaten Turnoutput prüfen. Erst der belegte Mechanismus bestimmt einen gegebenenfalls nötigen Plan-/Pinentscheid; keine pauschale Home-Schreibausnahme. Danach Grok-Nativprobe und betroffene Sicherheitsregressionen erneut ausführen, den finalen Implementierungsstand auf ausdrücklichen Auftrag committen und MG-01 mit eigenen Testzugängen an genau diesem Commit durchführen. Ein späterer Entscheidungscommit kann das signierte Ergebnis und die separate Statusentscheidung dokumentieren.

Die technische Prüfung eines möglichen Ersatzmechanismus ist kein bereits gebilligter Transportwechsel. Der aktuelle Code lässt Grok bis zur vollständigen Evidenz gesperrt und erneuert keine Approval-Bindung automatisch.

## Geänderte und angelegte Dateien

Zusätzlich im Reviewauftrag bearbeitete Pfade außerhalb der bisherigen Einzelliste:

| Datei | Änderung | Zweck |
|---|---|---|
| `app/AI6/Agents/ProviderBinaryDigest.php` | neu | Gemeinsamer prozesslokaler Binary-Digestcache mit Metadateninvalidierung. |
| `app/AI6/Reviews/VerifierCandidatePoolFactory.php` | geändert | Exaktes freigegebenes Verifikationstupel pro Profil erforderlich. |
| `tests/Unit/Runs/QueueReevaluationTest.php` | geändert | Berichtzeitpunkt und Widerruf ändern den Schedulerfingerprint. |
| `tests/Unit/Shared/Runtime/RuntimeScriptsTest.php` | geändert | Bindet alle drei Downloads und Digests sowie Provisionierung und Bootpräsenz. |
| `tests/Unit/Agents/ExecutionHomeManagerTest.php` | geändert | Worker-Credentialkopie wird vor jeder Materialisierung abgewiesen. |
| `tests/Unit/Agents/CodexCliAdapterTest.php` | geändert | Credentialhaltige Testhomes entstehen ausschließlich in Agentrolle. |
| `tests/Fixtures/Agents/BuildsCopilotHome.php` | geändert | Credentialhaltige Testhomes entstehen ausschließlich in Agentrolle. |
| `tests/Fixtures/Agents/BuildsGrokHome.php` | geändert | Credentialhaltige Testhomes entstehen ausschließlich in Agentrolle. |

85 Dateien im aktuellen Git-Diff einschließlich neuer Dateien. Ignorierte Diagnosehilfen und Laufzeitlogs sind nicht Teil des Diffs.

| Datei | Änderung | Zweck |
|---|---|---|
| `.env.example` | geändert | Onboardingwerte und Entfernung realer statischer Credentialrevisionen. |
| `Dockerfile` | geändert | Drei feste Herstellerdownloads mit SHA-256-Prüfung. |
| `README.md` | geändert | Login, Rollen, Prüfungen, Rotation, Pins und offene Abnahmen. |
| `app/AI6/Agents/AgentExecutionProcessor.php` | geändert | Agentseitige Projektion und Revisionsabweisung vor Start, beim Heartbeat und vor Veröffentlichung. |
| `app/AI6/Agents/AgentExecutionRunner.php` | geändert | Reale Provider ausschließlich über die Agent-Mailbox. |
| `app/AI6/Agents/AgentProfileRegistry.php` | geändert | Dynamische Auswahl anhand exakter aktueller Berichtstupel; Queue-Fingerprint bleibt serialisierbar. |
| `app/AI6/Agents/Console/ProviderCommand.php` | neu | Rollenbeschränkte Aktionen login/logout, verdeckte Eingabe und Cancel. |
| `app/AI6/Agents/CredentialRevisionRegistry.php` | geändert | Reale Generation aus demselben Bericht statt statischer Env-Revision. |
| `app/AI6/Agents/ExecutionHomeManager.php` | geändert | Zentrale Agentprojektion und privates Probe-Home. |
| `app/AI6/Agents/GitHubCopilotCliAdapter.php` | geändert | Bestehenden Adaptervertrag an zirkelfreie Agentprüfung und abgeschirmte Projektion anbinden. |
| `app/AI6/Agents/GrokCliAdapter.php` | geändert | Bestehenden Adaptervertrag an zirkelfreie Agentprüfung und abgeschirmte Projektion anbinden. |
| `app/AI6/Agents/GrokCliConfiguration.php` | geändert | Bestehenden Adaptervertrag an zirkelfreie Agentprüfung und abgeschirmte Projektion anbinden. |
| `app/AI6/Agents/Http/AgentProfileController.php` | geändert | Berechtigte lesende Profilansicht mit Diagnose, Grund und Version. |
| `app/AI6/Agents/ProviderCapabilityPublisher.php` | neu | Agentseitige native Prüfung und gegen Rotation gebundene Publikation. |
| `app/AI6/Agents/ProviderCapabilityReport.php` | neu | Begrenzter Bericht, tatsächlicher Boot, Alter, getrennte Bindungen und lesende Diagnose. |
| `app/AI6/Agents/ProviderCredentialStore.php` | neu | Minimalstore, atomarer vorgezogener Widerruf, Generation, Projektion und Cleanup. |
| `app/AI6/Agents/ProviderLogin.php` | neu | Private CLI-Anmeldung und turnfreie kontobezogene Modellprüfung. |
| `app/AI6/Agents/ProviderOnboarding.php` | neu | Validierte vertrauenswürdige Pfade, Aliasse und Intervalle. |
| `app/AI6/Runs/QueueAutoStarter.php` | geändert | Erkannte Datenbankkonflikte nach Rollback begrenzt wiederholen; erst danach benannte Ablehnung, keine Änderung der Approval-/Run-Bindung. |
| `app/AI6/Shared/AI6ServiceProvider.php` | geändert | Singleton-Bindung des Publishers ohne vorzeitiges Auflösen der Redaction-Konfiguration. |
| `app/AI6/Shared/Doctor/CodexCliDoctorCheck.php` | geändert | Lesender Doctor; native Probe ausschließlich in der Agentrolle. |
| `app/AI6/Shared/Doctor/GitHubCopilotCliDoctorCheck.php` | geändert | Lesender Doctor; native Probe ausschließlich in der Agentrolle. |
| `app/AI6/Shared/Doctor/GrokCliDoctorCheck.php` | geändert | Lesender Doctor; native Probe ausschließlich in der Agentrolle. |
| `app/AI6/Shared/Process/AgentProcessScope.php` | neu | Leere Namespace-Root, erlaubte RO-/RW-Mounts, Schutz von Store und fremdem Zustand. |
| `app/AI6/Shared/Process/ControlProcessRunner.php` | geändert | Native Provider nur innerhalb des Agent-Prozessscopes. |
| `app/AI6/Shared/Process/ExecutionMailboxCommand.php` | geändert | Abbruchbereinigung vor Bootpräsenz und regelmäßige Capabilityprüfung. |
| `app/AI6/Shared/Process/ProcessIsolationVerifier.php` | geändert | Bestehende enge Grok-Sessionumleitung bleibt nachweisbar. |
| `app/AI6/Shared/Process/RunningControlProcess.php` | geändert | Redigierte Loginbeobachtung und kontrollierter Cancel bei Callbackfehlern. |
| `app/AI6/Shared/Process/control-process-wrapper.sh` | geändert | Vom POSIX-Interpreter erzeugtes PWD vor dem kontrollierten Prozess entfernen; keine Erweiterung der Environment-Allowlist. |
| `bootstrap/app.php` | geändert | Registrierung von ai6:provider. |
| `config/ai6.php` | geändert | Store-/Berichts-/Private-Pfade, Intervalle, Pins und Environment-Allowlist. |
| `docker-compose.yml` | geändert | Rollenbezogene Store-, Report-, Präsenz- und private Mounts. |
| `docker/agent-seccomp-moby-29.6.1.json` | geändert | Zusätzliche konkrete Namespace-Clone-Kombination für die geprüfte Grenze. |
| `docker/entrypoint.sh` | geändert | Eng begrenzte Init-Provisionierung ohne Zugangsdaten. |
| `docker/role-process.sh` | geändert | Unabhängige tatsächliche Agent-Boot-ID. |
| `docs/AI6-035_IMPLEMENTIERUNGSSTAND.md` | neu | Implementierungsstand, Dateiübersicht, Prüfergebnisse und verbleibender Grok-Entscheidungsbedarf. |
| `docs/AI6-035_MG-01_ABNAHMEPROTOKOLL.md` | neu | Ergebnisfreie, nicht signierte Anleitung für die spätere menschliche Abnahme. |
| `resources/views/agents/profiles.blade.php` | geändert | Berechtigte lesende Profilansicht mit Diagnose, Grund und Version. |
| `tests/Feature/Agents/AgentProfileViewTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Agents/CodexCliExecutionTest.php` | geändert | Produktive Agentprojektion sowie Berichtsentzug vor erstem Start und Resume im bereits gebooteten Worker. |
| `tests/Feature/Agents/GitHubCopilotCliExecutionTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Agents/GitHubCopilotCliSmokeTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Agents/GrokCliExecutionTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Agents/ProviderLoginTest.php` | neu | Drei minimale Stores, Rollen, verdeckte Eingabe, native Modellantworten, Fehler, Cancel und unveränderte Generation bei Fehlversuchen. |
| `tests/Feature/Agents/ProviderOnboardingSmokeTest.php` | neu | Separat freizugebender realer Onboarding-Smoke; fehlende Voraussetzungen schlagen vor Anmeldung fehl. |
| `tests/Feature/Git/BuildsRunWorkspaceGitFixture.php` | geändert | Cleanup behandelt Symlinks ohne chmod auf fremde oder bereits entfernte Ziele; bestehende Ablehnungsassertionen bleiben erhalten. |
| `tests/Feature/Git/HardenedGitRunnerTest.php` | geändert | Vorhandenen Prozessvertrag mit tatsächlichem Laravel-Testboot ausführen. |
| `tests/Feature/Git/PublishCandidateTest.php` | geändert | Symlink-Fixture auch im Arbeitsbaum materialisieren, sodass die unveränderte Provenienzprüfung erreicht wird. |
| `tests/Feature/Reviews/BuildsReviewRoundFixture.php` | geändert | Aktuelle synthetische Providerberichte vor der Auswahl in der Reviewfixture. |
| `tests/Feature/Reviews/FindingVerificationRoundTest.php` | geändert | Fehlende Antwort über den tatsächlichen Agentprozess und die produktive Credentialgrenze prüfen. |
| `tests/Feature/Runs/ApprovalEligibilityFeatureTest.php` | geändert | Berichtsentzug nach Workerboot verhindert Auswahl und Claim ohne Erneuerung des Approval-Snapshots. |
| `tests/Feature/Runs/ApprovalInstructionSnapshotTest.php` | geändert | Tatsächlich gültiges Codex-Profil und Agentbericht für den bestehenden Adapterwechseltest. |
| `tests/Feature/Runs/ProjectQueueStartFeatureTest.php` | geändert | Queue-Verbindung vor dem Fork zurücksetzen; weiterhin genau ein paralleler Claim. Zusätzlicher Deadlock-Nachweis schützt Run-/Approval-Bindungen. |
| `tests/Feature/Shared/Doctor/CodexCliDoctorCheckTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Shared/Doctor/DoctorCommandTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Shared/Doctor/GitHubCopilotCliDoctorCheckTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Shared/Doctor/GrokCliDoctorCheckTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Shared/Doctor/GrokCliNativeDoctorSmokeTest.php` | geändert | Echte Grok-Sandboxprobe über den Agentproduzenten; weist den vorhandenen nativen Blocker nach. |
| `tests/Feature/Shared/Runtime/RuntimeDocumentationTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Feature/Shared/Security/SecurityBootstrapTest.php` | geändert | Erfolgreichen Security-Bootstrap getrennt von den jetzt zwingend fehlenden Providerberichten prüfen. |
| `tests/Fixtures/Agents/AgentMailboxFixture.php` | geändert | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/BuildsProviderOnboarding.php` | neu | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/FakeCodexBinary.php` | geändert | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/FakeCopilotBinary.php` | geändert | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/FakeGrokBinary.php` | geändert | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/MissingAnswerAdapter.php` | neu | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/NativeProviderMailbox.php` | neu | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/fake-codex.php` | geändert | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/fake-copilot.php` | geändert | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/fake-grok.php` | geändert | Native Grok-Modellcacheform und geschlossene Fehlerfälle als eigenständiges Prozessdouble. |
| `tests/Fixtures/Agents/provider-supervisor.php` | neu | Synthetische Provider-/Berichtsfixture beziehungsweise tatsächlicher Agent-Unterprozess für Regressionen. |
| `tests/Fixtures/Agents/release-gate-write-audit.json` | geändert | Genau einen Kontextdigest wegen der Queue-Fixturekorrektur neu binden; requires_service bleibt offen. |
| `tests/Unit/Agents/AgentProcessBoundaryTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Agents/GrokCliConfigurationTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Agents/ProviderCapabilityReportTest.php` | neu | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Agents/ProviderOnboardingEvidenceTest.php` | neu | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Agents/ProviderProcessScopeTest.php` | neu | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Git/RunWorkspaceContractTest.php` | geändert | Symlink-sicherer Fixture-Cleanup ohne Warnung im unveränderten Export-Negativtest. |
| `tests/Unit/ScaffoldStructureTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Shared/Process/ControlProcessRunnerTest.php` | geändert | Unveränderte Prozessverträge und exakte leere Environment ohne vom Wrapper erzeugtes PWD. |
| `tests/Unit/Shared/Process/ProcessArchitectureTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Shared/Process/ProcessPolicyAndLimitTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tests/Unit/Shared/Redaction/RedactionArchitectureTest.php` | geändert | Echte Pattern-/Suchargumente erkennen; alle alten positiven Kontrollen, Repositoryprüfung und Allowlist beibehalten. |
| `tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php` | geändert | Regression des betroffenen Onboarding-, Auswahl-, Prozess-, Runtime- oder Dokumentationsvertrags. |
| `tickets/AI6-035.md` | geändert | Ausdrücklich freigegebene Ergänzung von files, Scope-Markern und Notes; vom Benutzer gespeichertes ready bewahrt. |
| `docker/grok/build.sh` | neu | Fest gebundener Herstellerquellbuild mit lokalem Patch, gesperrten Abhängigkeiten, Buildhärtung und Lizenznachweisen. |
| `docker/grok/sandbox-work-dir.patch` | neu | Ausschließlich private Sandbox-Arbeitsdateien außerhalb des versiegelten Home; native Kontrollen bleiben erhalten. |
| `docker/grok/README.md` | neu | Quellen, Buildbindungen, Patchgrenzen und ausdrücklich offene native Nachweise. |
