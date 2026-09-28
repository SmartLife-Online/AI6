# AI6-036 — Umsetzungs- und Prüfbericht

Stand: 28. September 2026. Ausgangscommit: `aeda4ae52f5170e6602a4989e250c48d8444eb91`; geprüft werden die uncommitteten Änderungen dieses Arbeitsstands. Dies ist keine menschliche Abnahme und kein Ergebnis für MG-01.

**Aktueller Reviewstand:** Die technische Linux-Evidenz steht im Abschnitt „Nachreview: Private Provider-Projektionen“; die anschließende lokale Vertragskorrektur im abschließenden Abschnitt „Nachreview: Dateiliste und lokale Schutzpfadbindung“. Der vollständige Linux-Compose-Smoke bestand mit 210 Assertions einschließlich Login-, Probe- und Credential-Projektionspfad, verweigerten Private-Root-Binds, fehlgeschlagenem Präsenzstart und dem vorherigen unconfined-Negativfall. Er wurde für die anschließende reine Test-Fixture-Verlagerung nicht erneut ausgeführt. Die vorherigen Abschlüsse und roten Rohprotokolle bleiben historische Evidenz. Menschliche Gates und die deklarierten Release-Abdeckungslücken AI6-032/AC-02 und AI6-032/AC-04 bleiben offen.

## Ergebnis und Grenzen

Die Installation-/Doctor-Implementierung wurde an ihren tatsächlichen Verträgen geprüft und korrigiert: Ein gewöhnlicher oder deaktivierter Benutzer erfüllt die Administratorvoraussetzung nicht mehr; fehlgeschlagene Sicherheitsmaßnahmen zeigen den ermittelten konkreten Grund. Retention prüft wieder vertragsgemäß den nächsten existierenden Vorfahren; ein Symlink davor ist kein zusätzlicher Ablehnungsgrund. Die korrigierte Variante ist unter Linux belegt. Die Rollenprüfung nennt gültige Kommandos und gibt unbekannte Rollenwerte nicht aus. Installation, Zugang, Upgrade und offene Fremdgates sind dokumentiert.

Die freigegebene Altersanzeige aus AC-04 verwendet `ProviderCapabilityReport::presence()` mit Boot-ID und dem bereits validierten Präsenzzeitstempel. `boot()` delegiert dorthin und behält Rückgabetyp, Standardfrischeprüfung und die Ausnahme `boot(false)` für laufende Turns. Es gibt keinen zweiten Präsenzparser. Der aktuelle vollständige Linux-Compose-Smoke besteht mit **1 Test und 210 Assertions** (`private-projections-smoke-after.log`). Seine Vorgänger mit 200 beziehungsweise 204 Assertions erfassten beim Agent nur den credentialfreien Turn. Alle drei erreichen den echten Worker-Doctor-Aufruf mit `Ticketmanifest: OK`; TC-06 ist damit auch über `docker compose exec worker` belegt. Die früheren roten Läufe bleiben im historischen Verlauf erhalten.

Das im vorherigen Laufzeitlabel-Review ausgeführte Linux-Release-Gate erreicht **453 bestandene Tests, null Fehler und null Skips** (`apparmor-runtime-release-gate.log`). Es wurde in dieser gezielten Findings-Iteration nicht erneut ausgeführt und ist kein neuer Gesamtnachweis für die privaten Mountregeln. Sein Exitcode 1 stammt ausschließlich aus den deklarierten Lücken **AI6-032/AC-02 und AI6-032/AC-04**; dies sind nicht die gleich nummerierten Kriterien des Tickets AI6-036 in der folgenden Tabelle. Das Gate ist weiterhin nicht bestanden. MG-01 und sämtliche fremden menschlichen Gates bleiben offen.

Vorhandene Nutzeränderungen wurden erhalten. Das Detailticket dokumentiert die freigegebenen Präsenz-, AppArmor- und OCI-Erweiterungen. Es wurden keine Ticketstatus oder menschlichen Gateergebnisse geändert und keine Commits oder Pushes ausgeführt. Auf dem VPS wurden ausschließlich die dokumentierten Testressourcen und Rollenprofile angelegt; die elf bestehenden Container blieben unverändert. Die zusätzliche Prüfung des tatsächlichen AppArmor-Laufzeitlabels ist im abschließenden Nachtrag gesondert gebunden.

## Geänderte Dateien — historischer Stand der ersten Iteration

Diese Tabelle beschreibt ausschließlich die erste Iteration und ist keine vollständige Dateiliste der nachfolgenden AppArmor-Reviews. Für die dateiweise Evidenzbindung gelten die SHA-256-Quellinventuren des jeweiligen Nachweises: [Laufzeitlabel-Review](../storage/logs/ai6-036-apparmor-runtime-sources-final.json) und [private Provider-Projektionen](../storage/logs/ai6-036-private-sources-final.json). Sie erfassen unter anderem Rollenprofile und Abstraktion, Laufzeitsonde, Publisher, Mailboxkommando, Compose, Rollenstart sowie Vertrags- und Inventurtests. Die Inventuren sind lokal abgelegte Nachweisartefakte, gehören zu den unten benannten Archiven und werden nach späteren Änderungen nicht rückwirkend überschrieben. Neuere Korrekturen und ihre gesonderte Prüfgrenze stehen im jeweiligen Nachtrag dieses Berichts.

| Datei | Zweck |
|---|---|
| `app/AI6/Shared/Doctor/InstallCommand.php` | Aktiven globalen Administrator verlangen. |
| `app/AI6/Shared/Doctor/DoctorCommand.php` | Vorhandene konkrete Fehlergründe den Sicherheitsmaßnahmen zuordnen, ohne Prüfungen zu wiederholen. |
| `app/AI6/Shared/Doctor/RetentionDoctorCheck.php` | Zwischenänderung zurückgenommen; nächster existierender Vorfahr bleibt maßgeblich. |
| `app/AI6/Shared/Doctor/ProcessRolesDoctorCheck.php` | Rollenwerte begrenzen, das geprüfte Agentpräsenzalter anzeigen und gültige Prüfkommandos für nicht beobachtbare Rollen ausgeben. |
| `app/AI6/Agents/ProviderCapabilityReport.php` | Freigegebene Rückgabe von Boot-ID und Präsenzzeitstempel über dieselbe Validierung; kompatibles `boot()`. |
| `tests/Unit/Agents/ProviderCapabilityReportTest.php` | Zeitstempel, neue Pulse, unveränderte Ablehnungen und `boot(false)` bei abgelaufener oder fremder Präsenz absichern. |
| `tickets/AI6-036.md` | Die ausdrücklich freigegebene Scope-Erweiterung und ihre enge Grenze dokumentieren. |
| `README.md` | Compose-Installation, Schlüssel, Hostkeys, Doctor, SSH-Origin, HTTPS-Referenz, sichere Upgrade-Reihenfolge und offene Gates präzisieren. |
| `docs/AI6-036_MG-01_ABNAHMEPROTOKOLL.md` | Unausgefüllte Evidenz-, Exitcode-, Alias- und Unterschriftsfelder ergänzen. |
| `tests/Feature/Shared/Doctor/InstallCommandTest.php` | Neue SQLite-Tests für Installationsschritte, Schreibfreiheit und frischen Produktions-Bootstrap. |
| `tests/Feature/Shared/Doctor/OperationalDoctorChecksTest.php` | Neue Mail-/Git-/Retention-Fehlerfälle und reale Manifestprüfung einschließlich Drift. |
| `tests/Feature/Shared/Doctor/DoctorOptionsTest.php` | Neue Options-, Evidenz-, Rollen- und Fehlergrundtests mit synthetischen Providerberichten. |
| `tests/Feature/Shared/Doctor/DoctorDocumentationTest.php` | Sicherheitsrelevante Dokumentation, Kommandos und leeres Gateprotokoll binden. |
| `tests/Unit/Runs/ReleaseGateCommandTest.php` | Manifestvorprüfung und Abbruch vor dem ersten Testprozess beweisen. |
| `tests/Unit/Shared/Runtime/RuntimeScriptsTest.php` | Exakt drei Manifest-Ausnahmen in `.dockerignore` binden. |
| `docs/AI6-036_VERIFIKATION.md` | Dieser Bericht; nach der menschlichen Freigabe in den Files-Scope aufgenommen. |

## Akzeptanzkriterien

| AC | Automatisierte Evidenz und verbleibende Grenze |
|---|---|
| AC-01 | TC-01: leere/migrierte SQLite-Datenbank, fehlender Schlüssel, fehlende Bestätigungsadresse, fehlende Migration, gewöhnlicher/deaktivierter Administrator und Schreibfreiheit geprüft. Produktions-Bootstrap separat beobachtet. MG-01 offen. |
| AC-02 | TC-02/TC-03: statische Rollenprüfungen, Konfigurationsfehler, reale Manifestdrift und fehlende Quelle geprüft. Der unter Windows übersprungene POSIX-Symlink-/Rechtetest bestand anschließend unter Linux als UID 1000. |
| AC-03 | TC-04: strict/development, Checker, Providerzustände und Securityprofil einschließlich Fehlergrund geprüft; gelöschte Attestation nach der regulären Prüfung beweist, dass `--security` sie nicht erneut liest. Fehlende oder falsche `apparmor_confined`-Evidenz wird abgewiesen, auch im echten unconfined-Container. |
| AC-04 | TC-05: eigener Heartbeat und Agentpräsenz mit ihrem jeweiligen Alter, fehlende/veraltete/ungültige Evidenz und `UNGEPRÜFT` geprüft. Die gemeinsame Präsenzvalidierung und das bisherige Verhalten laufender Turns sind gebunden. Fehlende Agent-Einschließung sperrt jetzt zusätzlich Boot-Präsenz und alle Heartbeats; die Verweigerung und die normale Produktion sind real belegt. MG-01 bleibt offen. |
| AC-05 | TC-03/TC-06: Manifestgenerator, Release-Gate-Reihenfolge/Abbruch und Image-Ausschlüsse geprüft. Das Produktionsimage enthält genau die drei vorgesehenen Manifestdateien. Der vollständige Compose-Smoke ist grün und erreicht `Ticketmanifest: OK` über `docker compose exec worker`. Das Release-Gate hat 453 bestandene Tests, 0 Fehler, 0 Skips und Exitcode 1 nur wegen AI6-032/AC-02 und AI6-032/AC-04. |
| AC-06 | TC-06: Dokumentation, Compose-/Profil-/Label-Vertrag, ergänzte Dateiinventur und vollständiger Linux-Smoke mit 210 Assertions belegt. Die echten privaten Login-, Probe- und Credential-Projektionen einschließlich Schreibgrenzen und gesperrter Wurzel sind ausgeführt. AppArmor-Hostvoraussetzung, Rollenlabels und diese Prüfpfade sind in der ergebnisfreien Vorlage gebunden. Frische Linux-Installation mit Browser, SSH und E-Mail bleibt MG-01. |

## Historischer Prüfverlauf — vor den abschließenden Freigaben

Die folgenden Abschnitte bis „Abschluss nach OCI-Freigabe“ protokollieren ihren jeweiligen damaligen Stand. Aussagen wie „rot“, „fehlt“ oder „offen“ sind keine aktuellen Statusaussagen; maßgeblich sind die Zusammenfassung oben und die nachfolgenden Abschlussnachweise. Rote Rohnachweise bleiben unverändert erhalten.

Umgebung: Windows, PHP 8.5.5 (`C:\php\php.exe`), Composer 2.10.1 (`C:\ProgramData\ComposerSetup\bin\composer.phar`), Docker Compose 5.2.0.

| Befehl | Ergebnis |
|---|---|
| `php artisan test --compact --no-ansi` ohne `AI6_PHP85_BINARY`/`AI6_COMPOSER_PHAR` | Vor der anschließenden Präsenz-Nachbesserung: Exitcode 0, 1.820 bestanden, 375 übersprungen, 62.284 Assertions; 3.988,95 s. |
| Gezielter Vertragslauf, Befehl unten | 128 bestanden, 19 übersprungen, 2.867 Assertions; 21,70 s. |
| `php artisan test --compact --no-ansi tests/Feature/Shared/Doctor/DoctorOptionsTest.php` vor der anschließenden Präsenz-Nachbesserung | 15 bestanden, 137 Assertions. Pint für diese Datei und PHPStan anschließend ebenfalls bestanden. |
| Gezielte Regression nach der freigegebenen Präsenz-Nachbesserung, Befehl unten | Exitcode 0, 201 bestanden, 39 übersprungen, 3.739 Assertions; 210,91 s. |
| `php vendor/bin/pint --test` | Bestanden. |
| `php vendor/bin/pint --test` für die vier PHP-Dateien der Präsenz-Nachbesserung | Bestanden: `ProviderCapabilityReport.php`, `ProcessRolesDoctorCheck.php` und ihre beiden Testdateien. |
| `php vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`; auch nach der Präsenz-Nachbesserung mit zuvor geleertem Ergebniscache bestanden. Zuvor gemeldete unvollständige Fallunterscheidungen in Testfixtures behoben. |
| `TicketV1Parser` und `Ai6DetailV1TicketValidator` für das angepasste `tickets/AI6-036.md` | Keine Validierungsfehler (`[]`). |
| `php C:\ProgramData\ComposerSetup\bin\composer.phar validate --strict --no-interaction` | Bestanden. |
| `php C:\ProgramData\ComposerSetup\bin\composer.phar check-platform-reqs --no-interaction` | Bestanden. |
| `php scripts/generate-ticket-manifest.php --check` | `Ticket manifest is current.` |
| `git diff --check` | Bestanden. |
| `php vendor/bin/phpunit tests/Unit/LockedInstallTest.php --no-progress --do-not-cache-result` mit den beiden expliziten Laufzeitpfaden | 2 Tests, 1.280 Assertions bestanden. Der erste Sandboxlauf scheiterte an Netzwerk-/Dateirechten; der freigegebene Wiederholungslauf bestand. |

Der Produktcode blieb während des Gesamtlaufs unverändert. Anschließend hinzugefügte beziehungsweise präzisierte Testfälle wurden gezielt geprüft; die damalige Dokumentationsprüfung bestand mit 2 Tests/78 Assertions, die Scaffold-Inventur nach Anlage dieses Berichts mit 25 Tests/339 Assertions. Nach der menschlichen Freigabe wurde der Produktcode für die Präsenzschnittstelle und Altersanzeige ergänzt und mit der unten dokumentierten Regression erneut geprüft. Das lokale Gesamtprotokoll liegt unter `storage/logs/ai6-036-regular-20260927.log`, das Protokoll der Nachbesserung unter `storage/logs/ai6-036-presence-regression.log`; beide sind nicht zur Versionierung bestimmt. Übersprungene Tests sind keine bestandenen Linux-, Browser- oder externen Nachweise.

Gezielter Vertragslauf:

```powershell
php artisan test --compact --no-ansi tests/Feature/Shared/Doctor tests/Unit/Runs/ReleaseGateCommandTest.php tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php tests/Unit/Shared/Runtime/RuntimeScriptsTest.php tests/Unit/ScaffoldStructureTest.php
```

Gezielte Regression nach der freigegebenen Präsenz-Nachbesserung:

```powershell
php artisan test --compact --no-ansi tests/Unit/Agents/ProviderCapabilityReportTest.php tests/Unit/Agents/ProviderOnboardingEvidenceTest.php tests/Unit/Agents/ProviderProcessScopeTest.php tests/Unit/Agents/ExecutionHomeManagerTest.php tests/Unit/Agents/AgentProfileRegistryTest.php tests/Feature/Agents/ProviderLoginTest.php tests/Feature/Agents/AgentExecutionMailboxTest.php tests/Feature/Agents/AgentExecutionBoundaryTest.php tests/Feature/Shared/Doctor tests/Unit/Shared/Process/ProcessArchitectureTest.php tests/Unit/Shared/Redaction/RedactionArchitectureTest.php tests/Unit/ScaffoldStructureTest.php
```

Die neuen Tests zeigten vor den Korrekturen unter anderem falschen Installationserfolg bei gewöhnlichem/deaktiviertem Benutzer, verlorene Sicherheitsfehlergründe, unbrauchbare Rollenkommandos und fehlende Dokumentationsbindungen. Zwischenstände mit fehlgeschlagenen Assertions wurden korrigiert; keine Kontrolle wurde dafür abgeschwächt.

### Frischer Produktions-Bootstrap

`InstallCommandTest::test_production_without_a_ring_stops_in_bootstrap_before_install` startet über `Symfony\Component\Process\Process` einen frischen PHP-Prozess, lädt Autoloader und `bootstrap/app.php`, setzt einen isolierten Env-Pfad und ruft `ai6:install` auf. Umgebung: `APP_ENV=production`, `AI6_RUNTIME_ROLE=app`, synthetischer `APP_KEY`, leerer `AI6_REDACTION_KEYS`. Ausgeführt als Teil von `php artisan test --compact --no-ansi tests/Feature/Shared/Doctor/InstallCommandTest.php` und separat mit `C:\php\php.exe -r` mit demselben Einstieg.

Beobachtete sichere Ausgabe:

```text
AI6_REDACTION_KEYS is required unless APP_ENV is local or testing.
Bootstrap exit: 1
```

Es erschien kein `Nächster Schritt` aus dem Installationskommando; der Test prüft zusätzlich, dass der synthetische Schlüssel nicht ausgegeben wird.

### Compose und Linux

`docker compose --env-file .env.example config --format json` wurde mit `AI6_HTTP_PORT=18080` aufgerufen. Aus dem Ergebnis wurde ausschließlich `services.app.environment.APP_URL` ausgegeben: ohne `AI6_APP_URL` → `http://localhost:18080`; mit `AI6_APP_URL=https://ai6.example.org` → `https://ai6.example.org`. Beide Aufrufe endeten mit 0. Die übrige aufgelöste Umgebung wurde nicht protokolliert.

Der lokale Linux-Docker-Daemon war nicht verfügbar. `docker desktop start --timeout 45` endete mit 1 und `Docker Desktop is still starting: context deadline exceeded`. Auch die spätere Abfrage `docker info --format '{{json .ServerVersion}}'` endete mit 1: Die Pipe `dockerDesktopLinuxEngine` existierte nicht. Der folgende VPS-Lauf ergänzt diesen ursprünglichen Stand. Ein Windows-Skip ersetzt keinen Linux-Nachweis; die vorhandene VPS-Installation ist weiterhin kein leerer Host für MG-01.

### Freigegebener Linux-Lauf auf smartlife-vps

Der Auftraggeber hat sowohl `ssh ops@smartlife-vps` für die Tests als auch die Übertragung des konkreten Testarchivs ausdrücklich freigegeben. Arbeitsverzeichnis: `/home/ops/ai6-036-verification.kJ1wjW/`, mit getrennten Unterverzeichnissen `source`, `harness`, `work` und `logs`. Übertragen wurden der aktuelle Quellstand einschließlich der uncommitteten Änderungen und die vorhandenen Entwicklungsabhängigkeiten. `.env`, ursprüngliche Git-Metadaten, Laufzeitdaten und `.ai6-local-context.md` waren ausgeschlossen. Dies ist keine neue externe Locked-Install-Prüfung unter Linux.

Bindung:

- Ausgangscommit: `aeda4ae52f5170e6602a4989e250c48d8444eb91`.
- Archiv-SHA-256: `dd0a2c675f3e9bc92524129b942be2c2a74f7307ad9c2ba4d6735c5c05a1a7b3`; nach SCP geprüft. Alle 1.236 Quelldateien wurden zusätzlich gegen das mitgelieferte SHA-256-Inventar geprüft.
- Linux `7.0.0-28-generic`, Docker Engine `29.6.2`, Compose `5.3.1`; der Testrunner verwendet PHP `8.5.5` und SQLite `3.53.4` aus dem unveränderten Produktions-Dockerfile.
- Separater Tag: `ai6-036-verification:20260927`. Image-ID des ersten Builds: `sha256:d74feeba9ac5aa7921c2a514b8aca8379aaa4d66eadcc68d4bbe88a4691de88f`; nach dem erneuten Build durch den Compose-Smoke: `sha256:25102c6114680237088c000f4ee201d329e058a79beff9f54ecd35d37d439e2f`.

Die Tests laufen als UID/GID 1000 ohne Host-Capabilities. Der Testrunner verwendet das unveränderte Agent-Seccomp-Profil, eine read-only Containerwurzel und unveränderliche Control-/SSH-Wrapper. Ausführbare Testfixtures liegen auf dem eigenen `/work`-Mount; `/tmp` bleibt `noexec`. Für die isolierten Release-Unterprozesse setzt ausschließlich die Test-PHP-Konfiguration `sys_temp_dir=/work`. Ein neues, leeres Git-Verzeichnis ermöglicht `git check-ignore`; die leere `tests/.env` verhindert die nachgewiesene Warnung des Dotenv-Dateilesers und enthält keinerlei Konfiguration. Die Dateibytes des Produktcodes und sämtliche Sicherheitsprofile wurden nicht verändert.

Für die gezielte Worker-Nachprüfung wurden die dokumentierten, root-eigenen Lockobjekte `lock-0001` bis `lock-0064` mit Modus `0444` im Verzeichnis `0555` vorbereitet und read-only eingebunden. Hinzu kommen ein eigener Test-Worker-Heartbeat mit Boot-ID und `AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY`; Produktionsdaten werden dafür nicht verwendet. Weil `ssh-keygen` den anfänglich nur numerischen UID 1000 ohne Benutzereintrag zurückwies, registriert ein ausschließlich für Tests abgeleitetes Image diesen UID als `ai6-test`. Dessen Image-ID ist `sha256:4271313fd60d97fe5687013c954599f0b88ebd444e4c66becd637b3fdfc22d48`; das Produktionsimage bleibt unverändert.

Für den vorhandenen Compose-Smoke erhält ausschließlich der Wegwerf-Testrunner Docker-CLI und -Socket. Ein außerhalb des Quellstands liegender Aufrufhelfer akzeptiert nur die vom Test erzeugten `ai6smoke`-Projektnamen und ergänzt einen Override für den separaten Image-Tag der sechs AI6-Rollen. Dienste, Mounts, Sicherheitsprofile und Prüfungen bleiben ansonsten die ausgelieferten. Der Test wählt eigene Ports und kollisionsfreie Netze; seine Volumes werden durch den vorhandenen Teardown entfernt.

| Historischer erster Linuxlauf | Damalige Beobachtung vor den Korrekturen |
|---|---|
| Direkte AI6-036-Verträge, Befehl unten | Exitcode 0; 165 Tests, 3.148 Assertions; 9,924 s. Keine Skips oder Warnungen. Einschließlich Retention-Symlinks und POSIX-Rechten sowie Provider-Präsenzvertrag und Altersanzeige. |
| Breitere Regression einschließlich Provider-Prozess-/Login-/Mailboxtests | Nach Korrektur von Fixture-Mount und Git-Verzeichnis: Exitcode 1; 22 fehlgeschlagen, 200 Warnungen, 62 bestanden; 6.267 Assertions, 92,90 s. Die Warnungen wurden als fehlende leere `tests/.env` identifiziert und anschließend korrigiert; sie sind kein grüner Nachweis. Mehrere tatsächliche Fehler betreffen den verweigerten Mount-Namespace und davon abhängige Provider-Probes. |
| `AI6_RUN_COMPOSE_SMOKE=1` mit `RuntimeComposeSmokeTest.php` | Exitcode 1; 1 Test, 15 Assertions, 1 Fehler; 44,595 s. `init` und die Healthchecks aller sechs dauerhaften Dienste werden erreicht; der Checker-Namespace-Aufruf scheitert danach mit `unshare: cannot change root filesystem propagation: Permission denied`. Der restliche Smoke ist dadurch nicht nachgewiesen. |
| Manifestdateien und `TicketManifestDoctorCheck` direkt im neuen Produktionsimage | Exitcode 0. Unter `docs/` genau Plan und Manifest; unter `scripts/` genau `generate-ticket-manifest.php`. Ergebnis: `passed: true`, `Manifest ist aktuell`. |
| Image-Ausschlüsse aus dem Smoke separat geprüft | Exitcode 0. Alle elf dort als ausgeschlossen gebundenen Pfade fehlen tatsächlich im Image; `.env.example` ist vorhanden. Der fehlgeschlagene gesamte Smoke wird dadurch nicht grün. |
| `php artisan ai6:release-gate --no-ansi` | Vollständig ausgeführt, Exitcode 2: 398 Tests bestanden, 54 Nachweise wegen fehlender Worker-Fixtures übersprungen. Keine fehlgeschlagene Testauswahl in diesem Lauf; die unveränderten Lücken AC-02 und AC-04 werden ausdrücklich als offen ausgegeben. Das Gate meldet `Release-Gate nicht bestanden.` |
| Gezielte Nachprüfung der fünf Klassen mit den 54 Skips, vollständiges Worker-Fixture | Exitcode 2: 69 Tests, 2.864 Assertions, 64 bestanden, 2 Errors und 3 Failures; keine Skips; 172,459 s. Die fünf roten Fälle liegen sämtlich in `ReviewOnlyExecutionTest`; Details unten. Der vollständige Gatebefehl wurde danach nicht erneut ausgeführt und wird nicht als bestanden berichtet. |

Direkter Vertragslauf im Linux-Testrunner:

```bash
php vendor/bin/phpunit tests/Unit/Agents/ProviderCapabilityReportTest.php tests/Unit/Agents/ProviderOnboardingEvidenceTest.php tests/Feature/Shared/Doctor/InstallCommandTest.php tests/Feature/Shared/Doctor/OperationalDoctorChecksTest.php tests/Feature/Shared/Doctor/DoctorOptionsTest.php tests/Feature/Shared/Doctor/DoctorDocumentationTest.php tests/Unit/Runs/ReleaseGateCommandTest.php tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php tests/Unit/Shared/Runtime/RuntimeScriptsTest.php tests/Unit/ScaffoldStructureTest.php --display-warnings --display-deprecations --display-notices --no-progress --colors=never
```

Der Mountfehler ist zusätzlich mit der vom Agenten verwendeten Namespace-Kombination reproduziert: `bwrap: Failed to make / slave: Permission denied`. Der Container meldet `docker-default (enforce)`; die genaue Zuordnung der Verweigerung zu einer Host-Sicherheitsregel ist damit noch nicht abschließend bewiesen. Keine Host-, AppArmor-, Seccomp- oder Produktkontrolle wurde zur Umgehung geändert. Der ursprüngliche Testlauf ohne passendes Fixture-Verzeichnis sowie der wegen der Testumgebung abgebrochene erste Release-Gate-Versuch bleiben in den Rohprotokollen erhalten und gelten nicht als erfolgreiche Nachweise.

Die gezielte Worker-Nachprüfung umfasst `ReviewOnlyExecutionTest`, `ControlOperationCrashInjectionTest`, `TicketMutationExecutorTest`, `RunCancellationExecutorTest` und `ReportOnlyCompletionExecutorTest`. Damit wurden sämtliche vorher übersprungenen Fälle tatsächlich ausgeführt. Nach Ergänzung der vollständigen Testvoraussetzungen bleiben folgende Fehler bestehen:

- `test_a_complete_review_only_workflow_keeps_reductions_visible_under_every_security_profile`: Für `strict`, `custom` und `development` ist der erwartete Wert 1 tatsächlich 0 (Testzeile 184).
- `test_review_findings_stay_visible_and_never_open_a_fix_phase`: `ModelNotFoundException` für `ExecutionJob` im Fixturehelfer (Zeile 413), aufgerufen aus Testzeile 294.
- `test_a_blocked_completion_predicate_ends_the_report_step_visibly`: SQLite weist den Test-Gateeintrag mit `invalid run gate` zurück; dessen `ticket_contract_sha256` ist leer (Testzeile 316).

Diese fünf Befunde sind außerhalb der Doctor-Änderungen noch zu untersuchen. Sie werden weder durch einen Skip noch durch eine Änderung der Tests oder der Gatebedingungen geschlossen. Die vorbereitenden Nachprüfungen mit noch unvollständigem Fixture beziehungsweise fehlendem Benutzerkonto bleiben ebenfalls als erfolglose Zwischenstände protokolliert.

Die vollständigen Rohprotokolle, das SHA-256-Quellinventar und der Testharness sind lokal unter `storage/logs/ai6-036-linux-evidence/` gesichert; sie werden nicht versioniert. Das finale Evidenzarchiv hat SHA-256 `4d3aa97bb6bc36fc34585d8c570aaff215d99bf9b87c107134c6d99c418191c9`. Besonders relevant sind `logs/doctor-contracts.log`, `logs/compose-smoke.log`, `logs/release-gate-final.log` und `logs/worker-final.log`. Die Testkopie und Images bleiben für die Nachvollziehbarkeit auf dem VPS erhalten. Abschließend bestätigt: alle 1.236 übertragenen Quelldateien bytegleich, alle elf ursprünglichen Container mit unveränderten IDs gesund, der Live-Image-Tag unverändert, keine Testcontainer mehr vorhanden; die Smoke-Volumes wurden entfernt.

## Freigabe und verbleibende Nachweise

1. Die Freigabe zur gezielten Erweiterung der Provider-Präsenzschnittstelle einschließlich des Ticket-Files-Scopes wurde ausdrücklich erteilt und umgesetzt. Die vor der Änderung fehlende Methode und Altersanzeige wurden mit einem roten Testlauf nachgewiesen (15 fehlgeschlagene, 34 bestandene Tests); nach der Implementierung bestanden diese beiden Testdateien mit 49 Tests/374 Assertions. Weitere negative Doctor-Fälle sowie die betroffenen unveränderten Vertrags- und Architekturtests bestanden in der anschließenden Regression mit insgesamt 201 Tests/3.739 Assertions bei 39 Skips. Gemäß AGENTS.md §4 wurde für diese Korrekturiteration kein erneuter Gesamtlauf ausgeführt.
2. Den dokumentierten Linux-Mount-Namespace-Blocker und die fünf Review-only-Fehler klären und die betroffenen roten Nachweise anschließend erneut ausführen. MG-01 bleibt durch eine menschliche Prüfperson am endgültigen Commit auszuführen und zu signieren. Das leere Protokoll ist keine Abnahme.
3. Fremdgates bleiben offen: AI6-005B/MG-01, AI6-032/MG-01, AI6-035/MG-01 und Provider-/Adaptergates; das reale Securityreview-Profil gehört zu AI6-050. Rootless und die externe HTTPS-Kette sind ausschließlich Empfehlungen beziehungsweise Referenz ohne Abnahme.

## Nachprüfung der 16 Reviewfindings vom 27. September 2026

Dieser Abschnitt ergänzt und ersetzt die oben ausdrücklich historischen Zwischenstände nur für die hier erneut geprüften Punkte. Auftrag: Findings prüfen und sinnvoll beheben, bei Bedarf auch außerhalb des bisherigen Scopes und mit präzisierter Ticketprosa. Kein Commit, Push, Ticketstatus oder menschliches Gateergebnis wurde geändert.

### Codekorrekturen und Scope

| Finding | Bewertung und Änderung |
|---|---|
| Manifest ohne Exitcode | Berechtigt. `manifest_drift` bleibt erhalten; bei `null` erscheinen `Exitcode: keiner` und `Prozessergebnis: start_rejected` beziehungsweise der jeweilige feste Enumwert. Eigenständiger Test mit `START_REJECTED`. |
| Retention prüft alle Vorfahren | Berechtigt. Wieder auf die Ticketregel begrenzt: Wurzel oder nächster vorhandener Vorfahr muss ein reguläres, beschreibbares Verzeichnis ohne Symlink sein. Der POSIX-Test unterscheidet jetzt den unmittelbar geprüften Symlink von einem Symlink weiter oben. Der Artefaktstore wurde nicht verändert. |
| Rollenfremde Security-Evidenz | Berechtigt. `Zuständigkeit: nicht zuständig` kann eine aktive Maßnahme nicht erfüllen; der Doctor nennt `nicht zuständig in dieser Rolle` und endet ungleich null. Test mit der Rolle `app`. |
| Handlungsempfehlung trotz OK | Berechtigt. `Nächster Schritt` erscheint ausschließlich bei `FEHLT`; erfolgreicher Bootstrap empfiehlt keinen zweiten Administrator. |
| APP_KEY-Präfix | Berechtigt. Der Installationshinweis enthält `APP_KEY=base64:$(openssl rand -base64 32)` und verlangt den resultierenden Wert in `.env`. |
| Benutzer vorhanden, kein aktiver Administrator | Berechtigt. Der Assistent erkennt den abgeschlossenen Bootstrap und empfiehlt die Reaktivierung eines bestehenden Administratorkontos; Member und deaktivierter Administrator sind geprüft. |
| Git-Allowlist-Dokumentation | Berechtigt. README und Ticket verwenden `git_allowlist_empty` und schließen ihn als MG-01-Restbefund aus. Dokumentationstest bindet den Grund. |
| Doppelte Heartbeatprüfung | Berechtigt. `RuntimeHeartbeat::statusFromEnvironment()` liest und validiert die beiden Umgebungswerte und ermittelt den Status für beide Aufrufer. Die Rollenbeschränkung des Healthkommandos bleibt erhalten. |
| `.dockerignore`-Inventur | Berechtigt. Die Assertion erfasst jede mit `!` beginnende Zeile und erwartet exakt die vorhandenen vier Ausnahmen einschließlich `!.env.example`; keine Imageausnahme wurde ergänzt. |
| README-Schlüsselwerte | Berechtigt. Dieselbe `base64:`-Regex wie für `.env.example` prüft jetzt auch die README. |

Zusätzlich zum bisherigen AI6-036-Scope wurden `app/AI6/Shared/Runtime/RuntimeHeartbeat.php`, `app/AI6/Shared/Runtime/RuntimeHealthCommand.php` und `tests/Unit/Shared/Runtime/RuntimeHeartbeatTest.php` für die gemeinsame Heartbeatprüfung geändert und im Ticket vermerkt. Außerhalb von AI6-036 liegen die separat untersuchten Fixturekorrekturen in `tests/Feature/Runs/ReviewOnlyExecutionTest.php` und die exakte Quellbindung in `tests/Fixtures/Agents/release-gate-write-audit.json`. Eine pauschale Aktualisierung des veralteten Repositoryüberblicks in `AGENTS.md` ist für diese Ursachen nicht erforderlich und wurde nicht vorgenommen.

Die Review-only-Ursachen wurden am Code geklärt:

- Die drei Profilfälle verwechselten einen erfolgreichen Checkschritt mit einem einzelnen Checkprofilresultat. Das Fixture bindet ausdrücklich eine leere Profilliste. Der Test bindet jetzt diese Vorbedingung, genau einen erfolgreich ausgeführten `CHECK`-Job, die bereits vorhandene Review-Bereitschaft und null Einzelresultate. Der getrennte Test `test_the_bound_checks_run_on_the_ref_free_review_checkpoint` bleibt für eine tatsächlich ausgeführte, gebundene Prüfung unverändert. Weder Profil noch Policyhash werden zur Korrektur reduziert.
- Der Findings-Fall sprang von Review direkt zu Report, obwohl der Orchestrator zuvor `VERIFY` verlangt. Das Fixture bindet nun vor dem Approval einen unabhängigen Verifier mit synthetischer Testevidenz und führt den vorgesehenen Verifizierungsschritt aus. Auch danach darf keine Fixphase entstehen.
- Der offene Gateeintrag verwendete den in diesem Fixture leeren Run-Vertragshash. Er verwendet nun den vorhandenen Approval-Vertragshash, wie der entsprechende Completion-Prädikattest. Die Quellinventur wurde nur für diesen geprüften Write aktualisiert; seine Entscheidung `requires_service` bleibt unverändert offen. AC-02 wird dadurch nicht geschlossen.

### Linux-Namespace: Ursache und erforderliche Sicherheitsentscheidung

Host: `smartlife-vps`, Kernel `7.0.0-28-generic`, Docker Engine `29.6.2` / Git-Commit `3d80467`. Aktives AppArmor-Profil `docker-default`, Modus `enforce`; im installierten `/usr/bin/dockerd` ist die Regel `deny mount,` nachgewiesen. Der Kernel bietet AppArmor-Mountvermittlung (`mount umount pivot_root`). `kernel.apparmor_restrict_unprivileged_userns=1`, `user.max_user_namespaces=55877`.

Zwei Wegwerfcontainer verwendeten dasselbe bestehende Produktions-Testimage, zunächst UID/GID `10001:10001`, `--network none`, `--read-only`, `--cap-drop ALL`, `no-new-privileges:true` und das unveränderte Checker-Seccomp-Profil. Beide Ergebnisse wurden anschließend mit der exakt ausgelieferten Checker-UID/GID `10003:10001` bestätigt. Die Container erhielten keine Hostdaten oder Zugangsdaten:

| Namespace-Aufruf | Beobachtung |
|---|---|
| `/usr/bin/unshare --user --map-root-user --mount --propagation unchanged /usr/bin/id` | Exitcode 0; UID/GID 0 innerhalb des neuen User-Namespace. |
| `/usr/bin/unshare --user --map-root-user --mount /usr/bin/id` | Exitcode 1; `unshare: cannot change root filesystem propagation: Permission denied`. |

Damit scheitert nicht bereits die Namespace-Erzeugung, sondern die folgende Mountoperation. Das ausgelieferte Checker-Seccomp-Profil erlaubt `mount`, `umount2` und `unshare` ausdrücklich ohne Capability-Bedingung. Zusammen mit dem aktiven `docker-default` und dessen Mountverbot spricht dies für AppArmor als Ursache; ein zugehöriger Audit-Denial wurde nicht gefunden, die abschließende Gegenprobe mit einem gezielt freigegebenen Profil steht noch aus. Der erste Aufruf ist ausschließlich eine Diagnose, kein Ersatz für den Wrapper oder Isolationstest. Die unveränderten Regeln wurden nicht durch `unconfined`, zusätzliche Host-Capabilities oder abgeschaltete Hostkontrollen umgangen. Die Docker-Dokumentation beschreibt die automatische Anwendung von `docker-default` und die explizite Zuordnung eigener Profile: [Docker AppArmor](https://docs.docker.com/engine/security/apparmor/), [Moby-Profilvorlage](https://github.com/moby/profiles/blob/main/apparmor/template.go).

**Offener Entscheidungsantrag:** Ein eigenes, auf die benötigten Namespace-Mountoperationen und Pfade begrenztes AppArmor-Profil für Agent und Checker entwickeln und prüfen; anschließend ausschließlich diese Rollen über `security_opt` daran binden. Scope: entsprechende Profildatei unter `docker/`, die beiden Rollenzuordnungen in `docker-compose.yml`, Vertrags-/Negativtests und erneuter realer Compose-Smoke. Das bestehende globale `docker-default` darf dabei nicht verändert werden. Der Entwurf und seine vollständige Negativmatrix benötigen die im Finding ausdrücklich verlangte menschliche Sicherheitsfreigabe vor Umsetzung; diese Reviewiteration nimmt sie nicht vor. Ein grüner Compose-Smoke bleibt bis dahin offen. Die Hostvoraussetzung und dieser Blocker stehen jetzt bereits unter „Installation und Start“.

### Schlüssel und Upgrade im vorhandenen Produktions-Testimage

Ausführungsort: der bereits vorhandene isolierte Linux-Testcheckout `/home/ops/ai6-036-verification.kJ1wjW/source` mit dem oben gebundenen vorherigen Quellstand. Der neue Quellcode wurde für diese beiden Betriebsproben nicht übertragen. Die geänderten Doctor- und Testbytes sind damit nicht als Linux-geprüft behauptet. Compose exakt `5.3.1`, Binary-SHA-256 `f9ebc6ebdb19d769b793c245a736caaeb198c62587f13b25c660c13b4987f959`; im Repository ist kein abweichender Compose-Binary-Pin gefunden worden. Image nach dem Upgrade-Build: `sha256:ee6e3d0e3eabe7cf12e8790e1f57e762809bc2d36df5bbef0f84d3c806c3e10c`.

Die Aufrufe verwendeten jeweils `docker compose --env-file /dev/null --file docker-compose.yml --file ../harness/image-override.json --project-name <Testprojekt>`. Der vorhandene Override ändert ausschließlich die sechs AI6-Image-Tags. Testspezifische Netze: `10.240.0.0/24` und `10.240.1.0/29`, Proxy `10.240.1.2`, HTTP-Port `18936`; alle belegten Docker-Netze wurden vorher verglichen. Das initiale Schlüsselerzeugungsexperiment ohne diese Netzwerte scheiterte an der Überschneidung mit dem Live-Netz, bevor PHP startete (Exitcode 1). Die Wiederholung mit isolierten Netzen bestand.

| Befehl nach diesem Compose-Präfix | Exitcode und Beobachtung |
|---|---|
| `run --rm --no-deps --entrypoint php app /opt/ai6/artisan key:generate --show` | 0; ein gültiger Laravel-Schlüssel mit `base64:` und 32 dekodierten Bytes wurde ausgegeben. Ein nachgeschalteter Prüfer konsumierte die Ausgabe und protokollierte ausschließlich `key_generated=True; value_not_logged=true`. Kein Schlüsselwert wurde protokolliert. Projekt `ai6smoke0360000001`. |
| `up -d` | 0; isolierte Ausgangsinstallation für den Upgradeablauf, Projekt `ai6smoke0360000002`. |
| `build` | 0; neues Produktions-Testimage, separater Tag. |
| `stop caddy app worker scheduler agent checker` | 0; alle dauerhaften Rollen dieses Testprojekts gestoppt. |
| `up --no-deps --force-recreate --exit-code-from init init` | 0; `INFO Nothing to migrate.`, danach `init` im Zustand `exited` mit Exitcode 0. Der Befehl kehrt nach dem Ende von `init` zurück. |
| `up -d` | 0; anschließend alle sechs dauerhaften Rollen gesund. |
| `exec -T worker php artisan ai6:doctor --security --all-processes --require-strict` | 1; `Ticketmanifest: OK`, `Strict-Profil: OK`, eigener Heartbeat und Agentpräsenz OK. Die bewusst nicht eingerichtete Wegwerfinstanz meldet außerdem `known_hosts_missing`, nicht angemeldete Providerprofile und `security_review_adapter_fake`. Kein erfolgreicher Gesamtdoctor und keine MG-01-Abnahme. |
| `down --volumes --remove-orphans --timeout 10` | 0; nur Ressourcen des Testprojekts entfernt. |

Die README-Befehlsfolge benötigte keine Korrektur. Die Upgradeprüfung belegt die Wiederholung von `init` auf einer bereits migrierten Installation; sie erfindet keine neue Schemaänderung. Alle elf zuvor laufenden Container blieben danach gesund und ohne Neustart. Die Rohprotokolle liegen lokal unter `storage/logs/ai6-036-linux-evidence/logs/review-upgrade-*.log` und bleiben unversioniert.

**Historisch — Manifest-Assertion des früheren Compose-Smokes:** Sie wurde in diesem Lauf nicht erreicht. `assertMailboxAndIsolationBoundaries()` scheiterte vor dem späteren Worker-Doctor-Aufruf. TC-06 war durch diesen Smoke daher für `docker compose exec worker` nicht belegt. Der oben dokumentierte Upgrade-Lauf erbrachte diesen einzelnen Worker-Aufruf mit `Ticketmanifest: OK` separat. Erst der spätere vollständige Smoke im Abschluss nach OCI-Freigabe schloss diese Nachweislücke.

### Lokale Regression dieser Iteration

Der erste gezielte Lauf der neuen Assertions endete rot: 9 fehlgeschlagen, 1 übersprungen, 54 bestanden, 765 Assertions. Die korrigierten Doctor-, Installations-, Runtime-, Compose-, Architektur-, Inventar-, Provider- und Release-Gate-Verträge bestanden anschließend: Exitcode 0, 184 Tests bestanden, 19 plattformbedingte Skips, 4.232 Assertions (`storage/logs/ai6-036-review-regression.log`). Die lokale Review-only-/Release-Inventur bestand mit 14 Tests, 17 POSIX-Skips und 814 Assertions (`storage/logs/ai6-036-review-workflow-local.log`); die zuvor roten Linuxfälle sind damit ausdrücklich noch nicht ausgeführt.

PHPStan mit zuvor geleertem Ergebniscache (`php vendor/bin/phpstan clear-result-cache`, danach `php vendor/bin/phpstan analyse --no-progress`) meldete `[OK] No errors`; der anschließende Abgleich der zuletzt präzisierten Testdatei bestand ebenfalls. Protokolle: `storage/logs/ai6-036-review-phpstan.log` und `storage/logs/ai6-036-review-phpstan-final.log`. `php vendor/bin/pint --test`, der abschließende Pint-Lauf für die zuletzt präzisierte Review-only-Testdatei, Composer-Validierung, Plattformanforderungen, Manifestdriftprüfung und `git diff --check` bestanden. Das präzisierte Ticket wurde mit `TicketV1Parser` und `Ai6DetailV1TicketValidator` geprüft: `[]`. Die zuletzt präzisierte README bestand die Dokumentationsprüfung mit 2 Tests und 81 Assertions.

Die ausdrücklich angeforderte vollständige reguläre Suite wurde auf dem korrigierten Produktstand einschließlich `ProviderCapabilityReport::presence()` ausgeführt. Befehl: `php artisan test --compact --no-ansi`, ohne `AI6_PHP85_BINARY` und `AI6_COMPOSER_PHAR`. Der Produktcode blieb während des gesamten Laufs unverändert. Ausgabe aus `storage/logs/ai6-036-review-regular.log`:

```text
Tests:    375 skipped, 1850 passed (62519 assertions)
Duration: 4176.48s
REGULAR_EXIT=0
```

Die 375 Skips sind keine bestandenen Nachweise. Insbesondere wurden die geänderten POSIX-Fälle der Review-only- und Retention-Tests unter Windows nicht ausgeführt. Die externe Locked-Install-Suite wurde in dieser Iteration ohne Änderungen an Abhängigkeiten oder Installationsplattform nicht erneut ausgeführt; ihr oben dokumentierter früherer Lauf bleibt historische Evidenz.

Die automatische Freigabeprüfung hat den Upload der geänderten Quell-, Test- und Dokumentationsdateien auf den VPS abgelehnt, weil für diesen konkreten Export keine ausdrückliche Nutzerfreigabe vorliegt. Die Freigabe wurde angefragt; `.env`, Zugangsdaten, Laufzeitdaten und Git-Metadaten sollen dabei ausgeschlossen bleiben. Bis zur Antwort wird der neue Quellstand nicht auf den VPS übertragen. Lokal steht nur die nicht gestartete Docker-Desktop-WSL-Distribution zur Verfügung, kein nutzbarer Linux-Daemon.

Der lokale Code- und Teststand ist zusätzlich in `storage/logs/ai6-036-review-source-inventory.json` an die SHA-256-Werte der 17 geänderten beziehungsweise neuen Dateien unter `app/` und `tests/` gebunden. SHA-256 dieses Inventars: `7263277633d3ad2a0fc3def860ae68857d26610b025ac3762d8b3d4b4d3169d0`. Es ersetzt weder einen Commit noch die ausstehende Linuxausführung und wurde nicht übertragen.

### Historisch: damals nicht gefixte Findings — vor der anschließenden Freigabe

- **Checker-Namespace / grüner Compose-Smoke:** Ursache eingegrenzt und Hostvoraussetzung dokumentiert; der vollständige Smoke ist weiter offen. Erforderlich sind die oben konkretisierte menschliche AppArmor-/Compose-Sicherheitsentscheidung, ihre Umsetzung mit Negativtests und anschließend ein erfolgreicher realer Smoke. Die Sicherheitsregeln bleiben bis dahin unverändert.
- **Fünf Review-only-Fehler / fehler- und skipfreies Linux-Release-Gate:** Die drei Fixtureursachen sind im Code korrigiert, aber die erneute Linuxausführung des geänderten Stands fehlt. Der Quellcode-Transfer wartet auf die angefragte ausdrückliche Freigabe nach Ablehnung durch die automatische Freigabeprüfung. Danach sind die vollständige betroffene Klasse und `ai6:release-gate` im vollständigen Worker-Fixture erneut auszuführen; lokale POSIX-Skips gelten nicht als Nachweis. Die vorhandenen `OFFEN`-Zeilen bleiben unverändert.
- **Retention-Symlinkprüfung:** Code und Test entsprechen wieder der Ticketregel; die geänderte positive Symlink-Vorfahrenvariante benötigt noch die Linuxausführung. Der Test bleibt unter Windows sichtbar übersprungen. Auch dieser Nachweis wartet auf dieselbe Transferfreigabe und wird nicht aus dem historischen Linuxlauf der inzwischen geänderten Assertion abgeleitet.

## Fortsetzung nach Transfer- und AppArmor-Freigabe

Die anschließend erteilte ausdrückliche Freigabe umfasst den Quelltransfer in den isolierten VPS-Prüfcheckout und die Entwicklung eng begrenzter AppArmor-Profile für Agent und Checker. Die zuvor dokumentierte automatische Ablehnung blockiert diesen Transfer nicht mehr. Übertragen wurden 23 ausgewählte Quell-, Test- und Dokumentationsdateien ohne `.env`, Zugangsdaten, Git-Metadaten oder Laufzeitdaten. Das Transferarchiv hat SHA-256 `053be1cc0cc37cc78eed3ce231c1fc182064ab75931a666ea81611451715beab`; der vorherige Testcheckout wurde separat gesichert. Weitere gezielte Quellkorrekturen wurden anschließend in denselben Checkout übertragen.

### Review-only, Retention und Release-Gate

Das vollständige Linux-Worker-Fixture verwendet weiterhin privilegiert vorbereitete, nur lesbare Lockobjekte, das echte Worker-Heartbeat-Verzeichnis und den unprivilegierten Testbenutzer. Kein Skip wurde entfernt und keine Lockprüfung reduziert.

Beim ersten Wiederholungslauf blieb der Findings-Fall rot: Die vorzeitig erzeugte synthetische Provider-Evidenz war durch die anschließend geänderten Execution-Home-Pfade des Fixtures nicht mehr gültig. Die Evidenz entsteht jetzt unmittelbar vor dem Approval nach diesen Pfadänderungen. Der Test prüft zusätzlich den gespeicherten unabhängigen Verifier-Kandidaten und führt `VERIFY` vor `REPORT` aus. Diese Korrektur verändert nur das Fixture; die produktive Snapshotbindung bleibt unverändert.

| Nachweis | Ergebnis |
|---|---|
| Vollständige `ReviewOnlyExecutionTest`- und `OperationalDoctorChecksTest`-Klassen unter Linux | 42 Tests, 1.424 Assertions, 57,280 s; keine Fehler oder Skips. Rohprotokoll `logs/approved-review-regression-final.log`. |
| Neue Retention-Symlinkvariante gegen die gesicherte alte Doctor-Klasse | Erwartet rot: 1 Test, 3 Assertions; der zulässige Vorfahr `link/child` wird fälschlich verweigert. Die alte Klasse wurde nur in einem separaten Wegwerfcontainer read-only überlagert. Rohprotokoll `logs/approved-retention-before.log`. Gegen die korrigierte Klasse besteht der Test im obigen Lauf. |
| Erster vollständiger Release-Gate-Lauf nach Review-only-Korrektur | 415 bestanden, 37 übersprungen, Exitcode 1; `logs/approved-release-gate.log`. Die fünf ursprünglichen Review-only-Fehler treten nicht mehr auf. Die Skips sind weiterhin fehlende Evidenz und wurden separat ursächlich untersucht. |

Die zusätzlichen 37 Skips hatten eine eigene Ursache: `FakeAgentReleaseGateCommand` bereinigt die Kindprozessumgebung und verlor dabei den expliziten Testfixture-Pfad `AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY`. Dieselben Klassen finden die gemounteten Lockobjekte beim direkten Aufruf, aber nicht beim Aufruf über das Gate. Das Gate übernimmt jetzt ausschließlich diesen ausdrücklich gesetzten, nicht leeren Testwert in seinen mit `var_export` serialisierten Testbootstrap. Die zentrale Prozess-Allowlist bleibt unverändert; beliebige Elternvariablen werden weiterhin verworfen. Fehlt der Wert oder das tatsächliche sichere Fixture, bleiben die Nachweise ehrlich übersprungen.

Der neue Regressionstest lief vor der Produktkorrektur rot und danach grün. Er startet den aufgezeichneten Kindaufruf über den echten `ControlProcessRunner`, prüft fehlenden, leeren und gesetzten Fixture-Pfad einschließlich Leerzeichen und bindet die unveränderte Allowlist. Der bestehende Elternsecret-Test bleibt grün. Die abschließenden Gate-, Architektur-, Dokumentations- und Runtimeverträge bestanden lokal mit 83 Tests und 3.159 Assertions (`storage/logs/ai6-036-approved-final-contracts.log`), ohne den zuvor durch die lokale Sandbox ausgelösten Cache-Schreibhinweis.

Der abschließende vollständige Linux-Gate-Lauf mit dieser Korrektur ist abgeschlossen: **453 Tests bestanden, 23.322 Assertions in 65 Testauswahlen, null Fehler, null Warnungen und null übersprungene Nachweise.** Jede Testauswahl meldet ausschließlich bestandene Tests. Rohprotokoll: `logs/approved-release-gate-final.log`. Der Prozess endet mit **Exitcode 1 ausschließlich wegen der unverändert deklarierten offenen AC-02 und AC-04**. Seine Schlussausgabe lautet:

```text
OFFEN AC-02: Die quellgebundene Schreibstelleninventur umfasst die Release-Testklassen und ihre geerbten Fixturehelfer; Einträge mit requires_service sind noch auf öffentliche Producer umzustellen und gelten ausdrücklich nicht als zulässige Ausnahmen.
OFFEN AC-04: Gitmetadaten, Hook, Hostinstruktion und Credentials sind für die Agentenrolle nachgewiesen; die Wirkungslosigkeit providerspezifischer Konfiguration im geprüften Baum nicht: RunImplementation übergibt den Export direkt und durchläuft keine ExecutionHomeManager-Versiegelung wie die Reviewpfade.
Release-Gate nicht bestanden: unvollständige AC-Nachweise.
```

Damit ist das Review-Finding zu den fünf roten Review-only-Fällen und den übersprungenen Linux-Gatenachweisen behoben. Das Release-Gate selbst ist wegen seiner deklarierten Abdeckungslücken weiterhin nicht bestanden; eine menschliche Abnahme wird daraus nicht abgeleitet.

Die zusätzliche lokale Prozessregression (`ControlProcessRunnerTest`, `ProcessPolicyAndLimitTest`, `BlockedControlProcessTest`) bestand mit 10 Tests und 52 Assertions; ihre 21 POSIX-Skips bleiben als solche ausgewiesen. `pint --test` bestand. PHPStan wurde nach der letzten Produktkorrektur erneut mit `clear-result-cache` und anschließend `analyse --no-progress` vollständig ausgeführt: `[OK] No errors`, Exitcode 0 (`storage/logs/ai6-036-approved-phpstan-final2.log`). Manifestdriftprüfung, `composer validate --strict --no-check-publish`, Plattformanforderungen und `git diff --check` bestanden. Die zuletzt ergänzte README bestand mit 2 Tests/81 Assertions; der Detailticketvalidator lieferte `[]`.

Das abschließende Codeinventar `storage/logs/ai6-036-approved-source-inventory.json` umfasst 19 betroffene Dateien einschließlich der auf den ursprünglichen Vertrag zurückgeführten `RetentionDoctorCheck.php`. Sein SHA-256 ist `86943c2d62b2ef331ac60766ac49178df8e830a759de15cac904daab75073937`; alle 19 Dateien wurden lokal und im Linux-Prüfcheckout bytegleich bestätigt. Die oben dokumentierte vollständige reguläre Suite bleibt der angeforderte Nachweis einschließlich `ProviderCapabilityReport::presence()`; nach dieser zusätzlichen Finding-Korrektur wurden gemäß AGENTS.md §4 die gebundenen Verträge und das vollständige Linux-Release-Gate ausgeführt, kein weiterer kompletter Windows-Lauf. Die externe Locked-Install-Suite wurde mangels Abhängigkeits- oder Plattformänderung in dieser Fortsetzung nicht erneut ausgeführt.

Die Rohprotokolle dieser Fortsetzung sind lokal unter `storage/logs/ai6-036-linux-evidence/logs/approved*.log` gesichert. Das Archiv `storage/logs/ai6-036-approved-evidence-final.tar.gz` hat lokal und auf dem VPS SHA-256 `8369214afd8529d32f26feec7675db4e20316be8e67e7b9872560707e00a84e4`; es enthält auch den Testharness, den separaten roten Retention-Nachweis und die tatsächlich getesteten AppArmor-Entwürfe. `approved-apparmor-parser.log` bestätigt Parser-Exitcode 0, `approved-apparmor-kernel.log` sichert die gezielt gefilterten Kernelbefunde. Alle elf ursprünglichen VPS-Container haben abschließend dieselben IDs und sind gesund; es bleibt kein AI6-036-Testcontainer zurück. Die ungenutzten Laufzeitprofile und die neu angelegte gemeinsame Abstraktion sind wie unten beschrieben erhalten. Kein Commit, Push, Ticketstatus oder Gateergebnis wurde geändert.

### Historisch: zusätzlicher Namespace-Befund vor OCI-Freigabe

Die neuen Profile `ai6-agent-v1` und `ai6-checker-v1` wurden auf dem Testhost geparst und im Enforce-Modus ausschließlich an Wegwerfcontainern erprobt. Das globale `docker-default`, die ausgelieferten Seccomp-Regeln, Capabilities und Compose-Optionen wurden nicht geändert. Die gemeinsame Abstraktion wurde unter `/etc/apparmor.d/abstractions/ai6-container-base` neu angelegt; die Rollenprofile sind nur zur Laufzeit geladen, nicht als automatisch geladene Hostprofildatei installiert. Kein bestehender Dienst verwendet sie. Die Dateien unter `docker/apparmor/` bleiben ausdrücklich nicht in Compose aktivierte Entwürfe, einschließlich des noch unvollständigen Agent-Mountprofils.

Der Checker erreicht mit dem eigenen Profil die Mount-Propagation und den User-/Mount-/PID-Namespace; ein fremder tmpfs-Mount nach `/etc` scheitert nachweislich an dessen AppArmor-Mount-Allowlist. Das folgende neue procfs scheitert dagegen an `VFS: Mount too revealing`. Derselbe unabhängige Kernelblocker ist mit den tatsächlichen Bubblewrap-Namespace-Flags der Agentenrolle nachgewiesen. Ursache sind die geerbten, gesperrten OCI-Procmasken. Weitere AppArmor-Mountfreigaben allein beheben dies nicht.

Der konkrete zusätzliche Lösungsvorschlag steht in `docs/AI6-036_NAMESPACE_ENTSCHEIDUNG.md`: Die OCI-Pfadmaskierung ausschließlich für Agent und Checker durch ausdrücklich gebundene AppArmor-Zugriffsverbote ersetzen und die Gleichwertigkeit einschließlich Namespace-, Bind- und Pivot-Umwegen negativ nachweisen. Dieser zusätzliche Scope ist noch nicht freigegeben. Insbesondere wurde `systempaths=unconfined` weder allein noch zusammen mit den Entwürfen angewendet. README und Ticket benennen den neuen Befund und die offene Entscheidung.

Der vollständige Compose-Smoke bleibt damit rot beziehungsweise nach dem Namespace-Befund nicht erneut erfolgreich ausführbar. Die Worker-Doctor-Assertion `Ticketmanifest: OK` liegt **nach** dem Namespace-Aufruf und wurde im historischen Smoke nicht erreicht. TC-06 für `docker compose exec worker` bleibt daher unbelegt; die separate Manifestprüfung im Image ersetzt diesen Nachweis nicht. MG-01 und alle fremden Gates bleiben offen.

### Historisch: damals nicht gefixte Findings — vor der zusätzlichen OCI-Freigabe

- **Checker-Namespace / vollständiger Compose-Smoke:** Die AppArmor-Ursache ist behoben und die nächste, unabhängige OCI-/Kernelgrenze nachgewiesen. Für deren Behebung fehlt die zusätzliche menschliche Sicherheitsentscheidung aus `docs/AI6-036_NAMESPACE_ENTSCHEIDUNG.md`. Danach sind der gleichwertige Pfadschutz, seine Negativmatrix und der vollständige Compose-Smoke einschließlich Worker-Doctor nachzuweisen. Es gibt keine Abschwächung als Testumgehung.

## Historischer Abschluss nach OCI-Freigabe — Agentnachweis nur credentialfrei

Die folgenden 200-/204-Assertion-Smokes belegen ausschließlich den credentialfreien Agent-Turn. Login-Verzeichnisse einschließlich `auth.json`, Credential-Projektionen und private Probe-Homes waren darin nicht ausgeführt. Der entsprechende Teil von Nachweis 5 aus der Namespace-Entscheidung bleibt bis zum nachfolgend dokumentierten erweiterten AppArmor-Smoke offen; die damalige Abschlussaussage ist insoweit eingeschränkt.

Die zusätzliche menschliche Freigabe vom 28. September 2026 („Freigabe hiermit erteilt.“) gilt für den konkreten Antrag `docs/AI6-036_NAMESPACE_ENTSCHEIDUNG.md`. Umgesetzt ist die gekoppelte Konfiguration ausschließlich für `agent` und `checker`: das jeweilige feste AppArmor-Profil im Enforce-Modus und `systempaths=unconfined`. Die bisherigen OCI-Masken und Readonly-Pfade werden durch explizite Zugriffsverbote einschließlich Unterpfaden, Hardlinkzielen und Rootwechsel-Aliasen ersetzt. Die schon im Antrag genannte Metadatensichtbarkeit ist Teil dieser Freigabe; sie wird nicht als identisch mit einem verdeckten Mount beschrieben.

Die Mountregeln erlauben die vorhandenen festen Checker-Mounts und die tatsächlich beobachtete Bubblewrap-Projektion von `AgentProcessScope`: Systemdateien aus dem Containerimage, einzelne versiegelte Turn-Homes und deren Outputs. Ganze Mailbox-, Providerstore- und Hostwurzeln sind keine erlaubten Quellen. Andere Rollen, das globale `docker-default`, Seccomp, Host-Capabilities und die produktiven PHP-Isolationsgrenzen wurden nicht geändert. README beschreibt die erforderliche Hostinstallation beider Profile und der gemeinsamen Abstraktion. Task 11 und die sensiblen Scopeeinträge des Tickets dokumentieren die zusätzliche Freigabe; Status und AC-/TC-/MG-IDs bleiben unverändert.

### Tatsächlich ausgeführte Sicherheitsnachweise

- Beide Rollen laufen mit ihrem erwarteten Profil im Enforce-Modus. Ein Container mit nicht vorhandenem Pflichtprofil startet auf diesem AppArmor-fähigen Host trotz OCI-Option nicht: Docker-Exitcode 127, Fehler beim Anwenden des AppArmor-Profils. Dieser Nachweis erfasst keine Hosts ohne AppArmor; deren bislang fehlende Laufzeitabsicherung ist Gegenstand des nachfolgenden Reviewnachtrags.
- Für alle zwölf zuvor maskierten und fünf zuvor schreibgeschützten Pfade erstellt der Worker synthetische, nachweislich les- und schreibbare Gegenproben. Die Matrix prüft jeweils den Pfad selbst und einen Unterpfad, zusätzlich unter `oldroot`, `newroot` und `tmp/oldroot`. Normale Dateien sowie Hardlinks/Umbenennungen funktionieren als Positivkontrolle; geschützte Reads/Writes und Links/Umbenennungen werden entsprechend verweigert. Reale Kernelpfade und ihr `/proc/self/root`-Alias werden ebenfalls geprüft; nicht vorhandene Kernelknoten sind ausdrücklich kein Ersatz für die vollständig vorhandenen synthetischen Gegenproben.
- Dieselbe Matrix besteht nach dem Checker-User-/Mount-/PID-Namespacewechsel und nach der echten Bubblewrap-Projektion samt Pivot. Unerlaubte tmpfs-/Bind-Mounts nach `/etc` beziehungsweise `/tmp`, der Writable-Remount von `/proc/sys`, Projektionen von `/`, Providerstore, Mailbox und procfs an ein Outputziel scheitern. Die Kernelprotokolle bestätigen die Mountverweigerungen unter den jeweiligen Rollenprofilen.
- Der unveränderte Checker-Wrapper erreicht sein Nutzprogramm. Alle fünf Capabilitymengen sind leer, die Mailbox-/Heartbeat-Wurzeln bleiben verborgen, die Workspace ist beschreibbar und außer Loopback ist kein Netzwerkinterface sichtbar. Auch ein weiterer User-/Mount-Namespace kann die verdeckte Mailbox nicht durch Unmount wieder sichtbar machen.
- Der unveränderte `AgentProcessScope` startet tatsächlich PHP durch Bubblewrap mit einem vom Worker erzeugten `ExecutionHome`. Input und Root bleiben schreibgeschützt, nur der gebundene Output ist beschreibbar. Supervisorzustand und ein tatsächlich vorhandener fremder Outputbaum fehlen im projizierten Baum; alle Capabilitymengen sind leer. Diese Prüfung ersetzt die frühere bloße Inspektion der Home-Verzeichnisse durch einen echten Namespace-Aufruf zusätzlich zu den bestehenden Prüfungen.
- Der vollständige Smoke erreicht anschließend die Worker-Doctor-Assertion **`Ticketmanifest: OK`**. Der vorher fehlende TC-06-Aufruf über `docker compose exec worker` ist jetzt tatsächlich nachgewiesen. Auch der reale Checker-Mailbox-Rundlauf, Effect-Lock-Prüfungen, Agent-Neustart/Heartbeat-Replay, HTTP-Health, Runtimeversionen, Worker-Konfigurationsfehler und Scheduler-/Worker-Ausführungsmarken bestehen.

### Ergebnisse und falsifizierende Zwischenstände

| Lauf | Ergebnis |
|---|---|
| Neuer gepaarter Compose-Vertrag vor der Compose-Änderung | Erwartet rot: Die beiden verpflichtenden AppArmor-/OCI-Einträge fehlen. Nach der Änderung 15 Tests/722 Assertions bestanden. |
| `oci-compose-smoke-1.log` | Rot: eigener Syntaxfehler im neuen Testhelper vor der Pfadprüfung; korrigiert, kein Produktnachweis. |
| `oci-compose-smoke-2.log` | Rot: Die Negativmatrix entdeckt einen erfolgreichen Hardlink von einem geschützten Ziel. Zusätzliche `deny link /** -> …`-Regeln schließen die konkrete Umgehung; die Read-/Write-Regeln allein genügten nicht. |
| `oci-compose-smoke-3.log` | Rot: Der Mount wird korrekt verweigert, aber der Test erwartete einen anderen util-linux-Fehlertext. Der Test bindet jetzt den tatsächlichen Mount-Fehlercode 32; erlaubte Mounts werden weiterhin positiv ausgeführt. |
| `oci-compose-smoke-4.log` | Rot nach 93 Assertions: Der echte Agent-Aufruf benötigt den beobachteten Readonly-Remount von `/etc/resolv.conf` ohne `noexec` in den Flags. Genau diese feste Systemdateiprojektion wurde korrigiert. |
| **`oci-compose-smoke-5.log`** | **Exitcode 0; 1 Test, 200 Assertions, 186,620 s; keine Fehler, Warnungen oder Skips.** Vollständiger Smoke mit den neuen Negativnachweisen. |
| Lokale gebundene Runtime-/Architektur-/Isolations-/Dokumentationsregression | Exitcode 0; 64 Tests, 1.555 Assertions, keine Skips (`storage/logs/ai6-036-oci-regression.log`). |
| Finale Qualitätssicherung | `pint --test` bestanden; PHPStan `[OK] No errors`, Exitcode 0 (`storage/logs/ai6-036-oci-phpstan-final.log`). Der bereits dokumentierte kalte PHPStan-Lauf und die vollständige reguläre Suite werden hier nicht als neuer Lauf ausgegeben. |

Eine zusätzliche Parserprüfung des abschließend kommentierten Profils bestand mit Exitcode 0. Der finale Detailticketvalidator lieferte `[]`, das Ticketmanifest ist aktuell und `git diff --check` bestand. Die vier in `oci-source-sha256.log` gebundenen Dateien sind bytegleich mit dem lokalen Stand. Eine zu aufwendige Zwischenform der Turn-Namensregel wurde vor dem Smoke verworfen; die finale Regel bindet einzelne Namen mit den serverseitigen Trennzeichen und kompiliert innerhalb weniger Sekunden. Nach dem grünen Smoke wurden nur Kommentare und Dokumentation ergänzt, keine wirksamen Sicherheitsregeln oder PHP-Testlogik geändert.

Die automatische Freigabeprüfung hatte zunächst die Agent-Projektionserweiterung als nicht eindeutig autorisiert abgelehnt. Die anschließende rein lesende Prüfung belegte, dass alle elf Live-Container `docker-default` verwenden, keine persistente Rollenprofildatei existiert und Punkt 5 des ausdrücklich freigegebenen Antrags genau die bereits vorhandene `AgentProcessScope`-Projektion verlangt. Nach Beschränkung und Bindung an diese realen Quell-/Zielpfade wurde der erneute Testaufruf zugelassen. Es wurde keine Ablehnung über einen anderen Ausführungsweg umgangen; keine zusätzliche Nutzerfreigabe steht dafür aus.

Die Rohprotokolle liegen lokal unter `storage/logs/ai6-036-linux-evidence/logs/oci-*.log`. Das Archiv `storage/logs/ai6-036-oci-evidence-final.tar.gz` hat lokal und auf dem VPS SHA-256 `844fc30c055a1c4c5e2b9c35a18bb46491fb00dc67bdf88f0275c7f0ad5067df`; es enthält alle fünf Smoke-Läufe, Parser-/Kernel-/Startverweigerungsnachweise und die geprüften Compose-, Profil- und Smoke-Quelldateien. Die vier Quellhashes stehen zusätzlich in `oci-source-sha256.log`. Die Profile wurden auf dem Testhost nur zur Laufzeit geladen; die gemeinsame Abstraktion liegt unter dem dokumentierten Hostpfad. Alle elf ursprünglichen Container bleiben mit denselben IDs gesund; kein AI6-036- oder Smoke-Testcontainer bleibt zurück. Kein Commit, Push, Ticketstatus oder menschliches Gateergebnis wurde geändert.

Die im vorherigen Abschnitt belegten 453 bestandenen Linux-Release-Gate-Tests ohne Fehler/Skips gelten für den damaligen PHP-Produktstand vor dem folgenden AppArmor-Laufzeitnachtrag. AC-02 und AC-04 bleiben deklarierte, blockierende Abdeckungslücken; MG-01 bleibt die separate menschliche Abnahme am endgültigen Commit.

**Historische Abschlussbewertung der damaligen 16 Findings:** Die seinerzeit als vollständig bezeichnete Agent-Projektion erfasste nur den credentialfreien Fall. Login-, Probe- und Credential-Projektionspfad benötigen den zusätzlichen Nachweis des aktuellen Reviews.

## Nachreview: AppArmor-Laufzeitbindung und Dokumentation

Alle drei neuen Findings sind berechtigt. Die reine Compose-Konfiguration ist keine Laufzeitevidenz: [Moby `WithApparmor`](https://github.com/moby/moby/blob/master/daemon/oci_linux.go) setzt das OCI-Profil nur bei positivem `appArmorSupported()`. Das Entfernen der OCI-Masken darf deshalb nicht allein an dieser Option hängen. Die zentrale vorhandene `ProcessRuntimeProbe`-Naht liest nun `/proc/self/attr/current` direkt vom Kernel; nur der exakte rollenbezogene Profilname mit `(enforce)` wird akzeptiert, mit höchstens dem vom Kernel gelieferten abschließenden LF. Es gibt weder einen konfigurierbaren Ersatzwert noch eine Ausnahme für reduzierte Sicherheitsprofile.

Der Checker veröffentlicht `apparmor_confined` zusammen mit seinen bisherigen Laufzeitzusagen. Der Doctor verlangt den Wert strikt und lehnt auch alte Dokumente ohne das Feld ab; dieselbe Zusage sperrt über `ProcessIsolationVerifier` den Programmstart. Der Agent prüft vor seiner Mailboxverarbeitung und vor jedem Rollenheartbeat erneut. `ProviderCapabilityPublisher` schützt zusätzlich seine öffentlichen Boot- und Puls-Veröffentlichungen. Deshalb wurde die bisherige Boot-Präsenz aus `docker/role-process.sh` hinter die PHP-Prüfung verlagert. Ein ungültiger Bootwert darf auch bei vorhandener Einschließung keine Präsenz ersetzen.

Die zusätzlichen Dateien außerhalb des ursprünglichen Scopes sind im freigegebenen Task 12, im Files-Scope und unter den sensiblen Pfaden des Tickets ausgewiesen. Dazu gehören die technische Prüfnaht, der Publisher, der Mailboxstart, der Shell-Rollenstart und die betroffenen Fixtures. Deren synthetische Rollenprüfung ist ausschließlich Orchestrierungsevidenz; echte Kernel- und Namespaceevidenz liefert der Smoke. AGENTS.md brauchte keine Änderung. Status, AC-/TC-/MG-IDs, Sicherheitsprofile und menschliche Gateergebnisse bleiben unverändert.

README schließt Hosts ohne AppArmor ausdrücklich aus. Die leere MG-01-Vorlage verlangt nun aktives AppArmor, nach README installierte und geladene Profile mit `aa-status`-Auszug sowie beide tatsächlichen Enforce-Labels. `DoctorDocumentationTest` bindet diese Begriffe und weiterhin das Fehlen ausgefüllter Häkchen. Ergebniszusammenfassung und AC-Tabelle dieses Berichts sind aktualisiert; die früheren Abschnitte einschließlich aller roten Rohnachweise sind ausdrücklich historisch gekennzeichnet.

### Reale Beobachtung ohne AppArmor-Einschließung

Der Smoke startet zusätzlich einen datenfreien Wegwerfcontainer des gebauten Testimages mit `apparmor=unconfined` und `systempaths=unconfined`: kein Netzwerk, keine Host-Mounts, keine Credentials, keine Capabilities, read-only Root und nur frische tmpfs-Verzeichnisse. Der unveränderte Host bleibt AppArmor-fähig; ein Hostboot mit abgeschaltetem AppArmor wurde nicht vorgenommen. Die Anwendung liest im Container tatsächlich `unconfined` aus dem Kernel, ohne überlagerte `/proc`-Datei oder gefälschte Prüfwerte.

Der Test beweist: Die Agent-Mailbox endet mit Exitcode 1, direkte Boot-/Puls-Aufrufe werden abgewiesen, Präsenzverzeichnis und Rollenheartbeat bleiben leer. Beim Checker sind **alle übrigen nativen Laufzeitzusagen wahr und ausschließlich `apparmor_confined` falsch**. Die echte Attestierung führt zu `checker_attestation_apparmor_confined` im Doctor. Der echte `ControlProcessRunner` liefert `START_REJECTED`; die Startmarke des Nutzprogramms existiert nicht. In den normal gestarteten Rollen akzeptiert dieselbe Probe nur das eigene Enforce-Profil und lehnt das jeweils fremde Rollenprofil ab. Anschließend bestehen alle bisherigen Namespace-, Pfadschutz-, Checker-Mailbox- und Worker-Manifestnachweise.

### Prüfungen dieses Nachreviews

| Prüfung | Ergebnis |
|---|---|
| Vollständiger Linux-Smoke einschließlich tatsächlichem unconfined-Container | **Exitcode 0; 1 Test, 204 Assertions, 189,527 s; keine Fehler oder Skips.** `logs/apparmor-runtime-smoke-1.log`. |
| Unmittelbar betroffene Linux-Verträge und Architektur-/Compose-Inventur | **Exitcode 0; 191 Tests, 2.493 Assertions, 14,123 s; keine Fehler oder Skips.** `logs/apparmor-runtime-contracts-final.log`. |
| Erneutes vollständiges Linux-Release-Gate mit den geänderten gemeinsamen Provider-Fixtures | **453 Tests, 23.322 Assertions in 65 Auswahlen; 0 Fehler, 0 Warnungen, 0 Skips. Exitcode 1 ausschließlich wegen OFFEN AC-02/AC-04.** `logs/apparmor-runtime-release-gate.log`; maschinell zusammengefasst in `logs/apparmor-runtime-gate-summary.log`. Kein bestandenes Release-Gate und keine menschliche Abnahme. |
| Lokale neue Negativ-, Präsenz- und Dokumentationstests | Exitcode 0; 35 Tests, 307 Assertions. `storage/logs/ai6-036-apparmor-runtime-targeted.log`. |
| Breitere lokale Regression | 237 bestanden, 61 POSIX-Skips; ein eigener neuer Test verwendete zunächst die hier nicht installierte Mockery-Naht und scheiterte. Umgestellt auf den vorhandenen echten `Artisan::call`-Einstieg; die vollständige betroffene Klasse besteht im obigen gezielten Lauf. Der ursprüngliche rote Lauf bleibt in `storage/logs/ai6-036-apparmor-runtime-local.log` erhalten. |
| Pint / kalter PHPStan-Lauf | `pint --test` bestanden; nach `clear-result-cache` meldet PHPStan `[OK] No errors`, Exitcode 0. `storage/logs/ai6-036-apparmor-runtime-pint.log` und `storage/logs/ai6-036-apparmor-runtime-phpstan.log`. |
| Manifest / Composer / Ticket | Manifest aktuell; Composer-Validierung und Plattformanforderungen bestanden; Detailticketvalidator `[]`; `git diff --check` bestanden. |

Der zusätzlich auf das gesamte Doctor-Testverzeichnis ausgeweitete Linuxlauf bleibt ausdrücklich **rot**: 210 Tests, 12 Fehler und ein nicht aktivierter externer Provider-Smoke. Ein Fehler war eine im Prüfcheckout verbliebene alte Compose-Testdatei; mit dem aktuellen Vertrag besteht die Inventur im obigen Lauf. Die übrigen elf Fehler betreffen native Codex-/Grok-/Copilotproben des allgemeinen Prüfharness. Ein separater Vergleich mit den fünf read-only überlagerten Publisher-, Provider-Fixture- und Runtime-Prüfdateien des gesicherten Stands vor dieser Korrektur ergibt **dieselben elf fehlschlagenden Testfälle** (18 Tests, 11 Fehler). Diese elf Fehler bestehen somit bereits im vorherigen Prüfstand; der Provider-Nachweis bleibt rot. Der allgemeine Harness und seine nativen Provider-Fixtures benötigen dafür eine gesonderte Untersuchung; seine Sicherheitsgrenzen wurden hier nicht gelockert. Rohnachweise: `logs/apparmor-runtime-regression.log`, `logs/apparmor-runtime-provider-baseline.log`, `logs/apparmor-runtime-provider-comparison.log`; Vergleichsharness: `harness/run-php-apparmor-baseline`.

Die 21 Dateien in `storage/logs/ai6-036-apparmor-runtime-sources-final.json` wurden zwischen dem tatsächlich geprüften Linux-Checkout und dem finalen lokalen Stand bytegleich bestätigt. Nach dem grünen Smoke wurde nur noch der zusätzliche Test für ungültige Boot-IDs ergänzt und in der vollständigen betroffenen Klasse geprüft; Produkt- und Smoke-Code blieben unverändert. Der kalte PHPStan-Lauf und die anschließende inkrementelle Abschlussprüfung bestanden beide. Gemäß AGENTS.md §4 wurde in dieser Finding-Iteration keine weitere vollständige Windows-Suite und mangels Abhängigkeitsänderung keine weitere externe Locked-Install-Suite gestartet.

Das lokale Archiv `storage/logs/ai6-036-apparmor-runtime-evidence-final.tar.gz` hat denselben SHA-256 wie das VPS-Archiv: **`906ecca3913abaef96e645a04c3746cc147fda7db5b37b1ea7b548d7b66470a1`**. Es enthält die gebundenen Quellen, alle Linux-Rohprotokolle dieses Nachreviews sowie den Baseline-Harness und seine fünf ursprünglichen Vergleichsdateien. Die Laufzeitabschlussprüfung bestätigt dieselben elf gesunden ursprünglichen Container und keinen verbliebenen Testcontainer. Hostprofile, Live-Container, Ticketstatus und manuelle Gateergebnisse wurden in diesem Nachreview nicht geändert; Commit und Push wurden nicht ausgeführt.

Die automatische Freigabeprüfung lehnte den Quelltransfer zunächst wegen unzureichend belegter Ziel-/Payload-Autorisierung ab. Nach rein lesender Prüfung des ausdrücklich als Nutzerkontext benannten VPS-Ziels und des auf konkrete Quell-/Test-/Dokumentationsdateien begrenzten Archivs wurde derselbe Transfer mit diesen Belegen zugelassen. Keine Freigabe steht dafür mehr aus.

**Nicht gefixte Findings aus der damaligen drei Einträge umfassenden Fix-Liste: Keine.** Das gilt für Laufzeitlabel und Dokumentationskorrekturen, nicht als Login-/Probe-/Credential-Projektionsnachweis. Die separat dokumentierten elf bereits zuvor roten Provider-Harnessfälle und die bekannten AI6-032-Abdeckungslücken sind dadurch nicht als behoben deklariert.

## Nachreview: Private Provider-Projektionen

Alle sechs Findings dieser Fix-Liste sind berechtigt. Das bisherige Profil erlaubt den credentialfreien Turn, verweigert aber die bestehenden privaten Scope-Quellen. Der erweiterte Smoke reproduziert den Fehler mit unverändertem Altprofil: `ProviderCredentialStore::withProjection()` erreicht Bubblewrap und der Bind von `/oldroot/run/ai6/provider-private/projection-*` nach `/newroot/run/ai6/provider-private/projection-*` endet mit `Permission denied`. Dieser rote Lauf bleibt erhalten.

Die Agent-Allowlist ergänzt ausschließlich die vorhandenen serverseitigen Einzelverzeichnisse: `projection-*` mit Readonly-Remount, `login-*` mit Writable-Remount und dessen einzelne `auth.json` mit Readonly-Remount sowie `probe-*/inputs/doctor-new-*` read-only und `probe-*/outputs/doctor-new-*` writable. Die Remounts behalten `nosuid,nodev,noexec` der privaten tmpfs-Quelle. Es gibt keine Freigabe für `private_root`, beliebige Unterbäume oder andere Wurzeln. Der korrigierte Systemdateikommentar berücksichtigt auch Docker-verwaltete DNS-/Hosts-Dateien. Andere Rollen, Seccomp und Capabilities bleiben unverändert.

Der echte Compose-Agent führt durch die unveränderten Scope-Erzeuger `withProjection()`, `ProviderLogin::temporary()` und `withProbeHome()` jeweils PHP über `ControlProcessRunner` und `AgentProcessScope` aus. Für die private Login-Methode verwendet ausschließlich der Test Reflection, um genau diese Dateisystemgrenze ohne externen OAuth-Dialog auszuführen; es gibt keinen neuen Produkt-Testschalter. Die synthetische `auth.json` wird über ihren Hash geprüft. Readonly-Mounts verweigern auch `chmod` durch den Eigentümer, beschreibbare Verzeichnisse akzeptieren eine Datei. Ein tatsächlich vorhandener privater Geschwister-Canary, Providerstore, Berichte, Heartbeat und Anwendung sind im Kindprozess nicht sichtbar. Temporäre Login-, Probe- und Projektionsverzeichnisse werden anschließend entfernt. Der unveränderte Normal-Turn bleibt zusätzlich geprüft.

Die ganze private Wurzel wird sowohl an ein fremdes Ziel als auch an ihren normalen Pfad zu binden versucht. Beide Binds scheitern mit `permission denied`; `private-projections-kernel.log` bestätigt unter `ai6-agent-v1` `apparmor="DENIED"`, `operation="mount"`, `error=-13` für beide Ziele. Das ist der ergänzende technische Nachweis 5 der Namespace-Entscheidung, kein externer Providerlogin und keine menschliche Abnahme.

Die weiteren Korrekturen:

- `RuntimeComposeContractTest` liest beide Profildeklarationen, verlangt exakt `ai6-agent-v1` und `ai6-checker-v1` einschließlich gemeinsamer Abstraktion und bindet sie an die Compose-Optionen sowie die zentrale native Enforce-Labelbildung. Jede Verwendung von `systempaths=unconfined` benötigt genau ein bekanntes AppArmor-Profil.
- `ScaffoldStructureTest` bindet alle neun im Finding genannten Doctor-, Dokumentations- und AppArmor-Dateien mit `assertFileExists`.
- `ExecutionMailboxCommand` fängt Fehler beim Präsenzstart ab und endet mit wertfreier Komponentenmeldung und FAILURE vor der Mailboxschleife. Ein separater Wegwerfcontainer unter dem echten Agentprofil provoziert über ein ungültiges Publikationsziel die tatsächliche `CredentialProjectionException`. Exitcode und Meldung werden geprüft; kein Bootziel wird ersetzt, kein Präsenz- oder Rollenheartbeat geschrieben. Die vorhandene Bootdatei und der Ziel-Canary bleiben unverändert.
- README, Task 11/12 und die leere MG-01-Vorlage präzisieren die privaten Pfade und den notwendigen Nachweis. `DoctorDocumentationTest` bindet die neuen Begriffe und weiterhin die Ergebnisfreiheit. Die vorherigen 200-/204-Assertion-Abschlüsse sind ausdrücklich als credentialfreie Teilnachweise gekennzeichnet. AGENTS.md benötigt keine zusätzliche Regel; die bestehenden Vorgaben und die konkreten Vertragstests decken die Ursache ab.

### Gebundene Prüfungen

| Prüfung | Ergebnis / Rohprotokoll |
|---|---|
| Erster erweiterter Smoke | Rot vor der Mountprüfung: schreibbarer Logpfad fehlte im neuen datenfreien Fehlerfixture; ausschließlich die Fixture korrigiert. `private-projections-smoke-before.log`. |
| Erweiterter Smoke gegen Altprofil | Erwartet rot: 1 Test, 103 Assertions, `projection-*`-Mount verweigert. `private-projections-smoke-before-2.log`. |
| AppArmor-Parser und geladene Profile | Parser 5.0.0~beta1; Parserprüfung ohne Laden und anschließendes Laden erfolgreich; beide Rollenprofile enforce. `private-projections-parser.log`. |
| Vollständiger Smoke mit ergänztem Profil | **Exitcode 0; 1 Test, 210 Assertions, 159,290 s; keine Fehler oder Skips.** `private-projections-smoke-after.log`. Enthält alle vorherigen Schutzmatrizen, den echten Checker-Rundlauf, den Worker-Manifestcheck und die neuen privaten Pfade samt Präsenzfehler. |
| Linux-Verträge, Inventur, Architektur, Präsenz, Execution-Home und Doctor | **Exitcode 0; 92 Tests, 1.510 Assertions, 1,715 s; keine Fehler oder Skips.** `private-projections-contracts-final.log`. Der erste Lauf fand die im Prüfcheckout fehlende `LICENSE.moby`; nach Übertragung der vorhandenen Lizenzdatei besteht die Inventur. Der rote Erstlauf bleibt in `private-projections-contracts.log`. |
| Lokale gezielte Regression | Exitcode 0; 79 Tests, 1.407 Assertions. `storage/logs/ai6-036-private-targeted.log`. |
| Qualität | Vollständiges `pint --test` bestanden; kalter PHPStan-Lauf nach `clear-result-cache`: `[OK] No errors`. `storage/logs/ai6-036-private-pint.log`, `storage/logs/ai6-036-private-phpstan.log`. Manifest aktuell, Composer gültig, Ticketvalidator `[]`, `git diff --check` bestanden. |

Die geprüften Quellen sind in `storage/logs/ai6-036-private-sources-final.json` mit SHA-256 gebunden und zwischen lokalem Stand und Linux-Checkout verglichen. Das Archiv `storage/logs/ai6-036-private-evidence-final.tar.gz` enthält diese Quellen, den Harness und die Linux-Rohprotokolle; sein separater SHA-256 steht in `storage/logs/ai6-036-private-evidence-final.sha256`. Die Linux-Rohprotokolle liegen außerdem unter `storage/logs/ai6-036-linux-evidence/logs/private-projections-*.log`.

Alle elf ursprünglichen Container bleiben mit denselben IDs gesund und verwenden weiterhin `docker-default`; kein Testcontainer bleibt zurück. Nur die bereits geladenen Testrollenprofile wurden im freigegebenen Umfang aktualisiert. Es gibt keinen Commit, Push, geänderten Ticketstatus oder eingetragenes MG-Ergebnis. Die vollständige reguläre Suite, das externe Locked-Install-Gate und das Release-Gate wurden in dieser gezielten Findings-Iteration gemäß AGENTS.md §4 nicht erneut ausgeführt. Die zuvor dokumentierten elf Provider-Harnessfehler sind damit weder erneut bewertet noch als behoben ausgegeben; reale externe Provideranmeldung und die menschlichen Gates bleiben getrennte Nachweise.

**Nicht gefixte Findings: Keine.**

## Nachreview: Dateiliste und lokale Schutzpfadbindung

Beide niedrigen Findings sind berechtigt und behoben. Die Tabelle „Geänderte Dateien“ ist ausdrücklich als historischer Stand der ersten Iteration gekennzeichnet und verweist auf die unveränderten SHA-256-Quellinventuren der späteren Laufzeitnachweise. Diese Inventuren binden ihren damaligen Prüfstand; sie sind keine Behauptung identischer Bytes nach diesem Nachtrag.

Die zwölf Maskenpfade und fünf Readonly-Pfade stehen jetzt einmalig in `tests/Fixtures/Runtime/ExecutionRoleProtectedPaths.php`. Sowohl der lokale `RuntimeComposeContractTest` als auch der Linux-Smoke verwenden diese Fixture. Der bestehende Profilvertrag verlangt für alle 17 Pfade die passenden `deny`-Rechte, Unterpfade, Rootwechsel-Aliase und Hardlinkzielverbote in `ai6-container-base`. Dafür löst der Test die literalen Klammeralternativen der vorhandenen Regeln auf; er ersetzt keinen AppArmor-Parser. Die Scaffoldinventur bindet die neue Fixture. Keine wirksame Profilregel und kein Produktcode wurden geändert.

Geänderter Scope dieser Iteration: der Bericht, der Profilvertrag, der Smoke, die Scaffoldinventur und die neue gemeinsame Test-Fixture. Eine Ticket- oder AGENTS.md-Änderung ist dafür nicht erforderlich. Die Quellbindung dieses lokalen Nachtrags liegt separat in `storage/logs/ai6-036-protected-paths-sources.json`.

| Prüfung | Ergebnis / Rohprotokoll |
|---|---|
| Lokale Profil-, Inventar-, Architektur- und Dokumentationsverträge | **46 Tests, 1.618 Assertions bestanden**, keine Skips. `storage/logs/ai6-036-protected-paths-tests.log`. |
| Negativproben ausschließlich im Speicher | Alle **21 Mutationen abgewiesen**: jeder einzelne der 17 Pfade sowie entfernte Lese-, Linkziel-, Alias- und Unterpfadverbote. Die echte Profildatei bleibt bytegleich. `storage/logs/ai6-036-protected-paths-mutations-final.log`. Der erste direkte PHP-Versuch scheiterte an fehlender PHPUnit-Konfiguration; der reguläre PHPUnit-Runner besteht. Das erste Protokoll bleibt unter `storage/logs/ai6-036-protected-paths-mutations.log` erhalten. |
| Smoke-Verlagerung | Gegen die archivierte Quelle des grünen 210-Assertion-Smokes geprüft: ausschließlich gemeinsame Konstantenverweise geändert, alle 17 Pfadwerte identisch. `storage/logs/ai6-036-protected-paths-refactor.log`. Kein neuer Linux-Smoke oder Eingriff am VPS. |
| Qualität | Pint für alle vier geänderten PHP-Dateien bestanden; PHPStan `[OK] No errors` (`storage/logs/ai6-036-protected-paths-phpstan.log`); `git diff --check` bestanden. |

Die zuvor belegten Laufzeitnachweise bleiben ihrem damaligen Quellstand zugeordnet. MG-01, Ticketstatus und die bekannten Fremdgates bleiben unverändert. Kein Commit oder Push.

**Nicht gefixte Findings: Keine.**
