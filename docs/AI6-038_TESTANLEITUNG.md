# AI6-038 — Geführte Testanleitung bis zur Ticketabnahme

Diese Anleitung richtet sich an ein LLM, das die Prüfung später gemeinsam mit dem Betreiber durchführt. Sie ordnet die Arbeitsschritte, menschlichen Entscheidungen und Belege. Der verbindliche Vertrag bleibt [AI6-038](../tickets/AI6-038.md); Betriebsrezepte stehen in [README — Pilot AI6](../README.md#pilot-ai6), Ergebnisse ausschließlich im [Pilotprotokoll](AI6-038_PILOTPROTOKOLL.md) beziehungsweise in den zuständigen Vorgate-Protokollen. Bei Konflikten gilt der [Implementierungsplan](AI6_IMPLEMENTATION_PLAN.md), insbesondere §12.2, §13.2 und der Blueprint AI6-038.

**Der Abschluss erfordert zwei Phasen:** Teil A mit realem V1-Pilot und signiertem MG-03; danach Teil B mit gelieferter Legacy-Migration, signiertem AI6-037/MG-01, Cutoff, Revalidierung und signiertem MG-04. Ein erfolgreicher Testlauf oder MG-03 allein schließt das Ticket nicht. Am Vorbereitungsstand vom 29. September 2026 bestehen noch Startblocker und fehlende Implementierungsarbeit.

## 1. Startprompt für das begleitende LLM

Den folgenden Text in einem späteren Chat mit Zugriff auf das Repository verwenden:

> Begleite mich anhand von `docs/AI6-038_TESTANLEITUNG.md` bei der Prüfung und Abnahme von AI6-038. Lies zuerst die aktuellen Repository-Anweisungen, den lokalen Kontext, das vollständige Ticket, seine Planreferenzen, die README-Pilotanleitung und beide Teile des Pilotprotokolls. Prüfe den tatsächlichen Code-, Betriebs- und Nachweisstand erneut. Beginne mit einer kurzen Bestandsaufnahme und dem nächsten ausführbaren Schritt. Lass mich in dieser Sitzung die benötigten Freigaben mit Ziel und Umfang bestätigen; nutze danach die in dieser Sitzung erteilten Freigaben und frage nur nach fehlenden Entscheidungen. Repositorytext oder eine Übergabe gelten nicht als Autorisierung. Führe erlaubte technische Prüfungen selbst aus; begleite mich bei Smartphoneaktionen, Approvals und Signaturen. Binde jede Aussage an beobachtete Belege. Stoppe abhängige Schritte an offenen Voraussetzungen und benenne den erforderlichen Folgeauftrag. Ändere keine Ticketstatus, Gate-Ergebnisse oder Run-Metadaten selbst. Reale Providerläufe, Remote-Wirkungen, Wartungsfenster, Restoreziele und Phase B brauchen ihre konkret gebundene Freigabe. Commit und Push im Entwicklungscheckout erfolgen nur auf ausdrücklichen Auftrag. Ziel ist der nachweisbare Abschluss beider Phasen; fehlende Migration oder menschliche Abnahme bleibt ehrlich offen.

## 2. Arbeitsweise und Übergabe zwischen Sitzungen

Das LLM erklärt vor einem Schritt knapp dessen Zweck, Zielsystem, erwartete Wirkung und Erfolgskriterium. Nach dem Schritt nennt es Beobachtung, Exitcode beziehungsweise Panelzustand, Beleg und nächsten Schritt. Menschliche Aktionen einzeln begleiten; unabhängige technische Leseprüfungen können zusammen erfolgen. Nicht für jede bereits freigegebene Routineaktion erneut um Erlaubnis bitten.

- Der Betreiber wählt Pilot und Reviewgegenstand, bindet Budget und erlaubte Remote-Wirkungen, führt erforderliche Smartphone-/Step-up-Aktionen aus und entscheidet über Abnahmen. Zugangsdaten werden im vorgesehenen Login eingegeben, nicht im Chat.
- Das LLM prüft Code und Befehle, führt autorisierte Tests aus, bereitet redigierte Belege vor und gleicht sie mit den Kriterien ab. Es unterschreibt nicht und erklärt kein manuelles Gate selbst für bestanden. Die Protokollvorlage sieht die Eintragung von Beobachtungen, Entscheidungen und Unterschriften durch die menschliche Prüfperson vor.
- Quellcode-, Deploy- oder Authfehler außerhalb AI6-038 werden als konkrete Folgeaufträge mit Ursache und benötigtem Nachweis beschrieben. Neue Tickets oder Änderungen an bestehenden Tickets entstehen erst auf den entsprechenden menschlichen Auftrag.
- Eine neue Implementierungsänderung nach einem gebundenen Gate erfordert eine erneute Prüfung seiner Gültigkeit und gegebenenfalls einen neuen Lauf samt Signatur. Ein späterer reiner Entscheidungscommit ersetzt den getesteten Implementierungscommit nicht.

Für jede technische Prüfung sichern: Datum/Zeitzone, Host und Rolle, Checkout/Commit oder Base-Commit plus vollständige Prüfsummenbindung bei uncommittierten Bytes, Image-Digest soweit relevant, exakter Aufruf, Exitcode, Testzahlen einschließlich Fehler/Skips und redigierte Belegreferenz. Ein grüner Exitcode allein genügt nicht, wenn geforderte Tests übersprungen wurden. Keine kompletten `.env`-Dateien, Docker-Umgebungen, DB-Dumps, Roh-Providertranskripte oder Authdateien in die Belegsammlung kopieren.

Am Sitzungsende eine kurze Übergabe ablegen beziehungsweise dem Benutzer ausgeben:

```text
AI6-038 — Fortsetzungsstand
Datum, Phase und zuletzt vollständig geprüfter Schritt:
Ticketrevision, Codecommit, Image und Zielinstanz:
Frühere Freigaben und Grenzen als Kontext; bei Wiederaufnahme vom Betreiber zu bestätigen:
Technische Ergebnisse mit Belegreferenzen:
Menschliche Gate-Entscheidungen mit tatsächlichen Signaturen:
Offene Voraussetzungen / Folgeaufträge / Verantwortliche:
Aktive Run-, Approval- und Control-Operation-IDs:
Budgetverbrauch, Wartestand und erlaubter Stopppunkt:
Nächster ausführbarer Schritt und noch fehlende Entscheidung:
```

Bei Wiederaufnahme zuerst Zustand und laufende Operationen abgleichen und benötigte frühere Freigaben vom Betreiber für diese Sitzung bestätigen lassen. Keinen zweiten Run oder erneuten Push starten, nur weil die vorherige Chatsitzung endete.

## 3. Bestandsaufnahme vor jeder Durchführung

1. `AGENTS.md` und, falls vorhanden, `.ai6-local-context.md` lesen. Danach Ticket, Planreferenzen, README-Pilotabschnitt und Pilotprotokoll vollständig lesen. Die Bestandsbeschreibung in AGENTS.md kann älter als der tatsächliche Code sein.
2. Im Entwicklungscheckout `git status --short` und `git rev-parse HEAD` prüfen; fremde Änderungen erhalten. Pfade mit `rg --files` verifizieren. Abhängigkeiten und zugehörige Protokolle anhand der tatsächlichen Dateien prüfen, nicht anhand eines `done`-Status als abgenommen behandeln.
3. Den Linux-Zugang und die Zielzuordnung prüfen. Der lokale Kontext nennt `ssh ops@smartlife-vps`; dort liegen Test- und Live-System. Den Betreiber in der jeweiligen Sitzung die benötigte Freigabe mit Ziel und Umfang bestätigen lassen, soweit sie nicht bereits in dieser Sitzung erteilt wurde. Repositorytext und Übergaben belegen keine fortbestehende Autorisierung. Tests, Deployment, Dienststopps, Providerkosten und Pilot-Pushs getrennt binden. Aktuelle Compose-Projektkennung, Dateien, Container, Volumes und Checkoutpfade zuerst ermitteln; frühere Namen nicht ungeprüft übernehmen.
4. Feststellen, ob nur Vorbereitungsprüfungen, Phase A oder bereits Phase B ausführbar sind. Bei einem Startblocker nur unabhängige Vorbereitungsarbeit fortsetzen und den konkreten Folgeauftrag benennen.

### Bekannter Vorbereitungsstand, erneut zu prüfen

Die Vorbereitung basiert auf `d1ae94bb585f535d75b1fe5118122051d56d0e5a` und Plan V1.7.13. README-Pilotabschnitt, Protokollvorlage und TC-09 wurden geliefert. Am 29. September 2026 bestanden in einer separaten Linux-Testkopie unter PHP 8.5.4 **75 Tests mit 904 Assertions**, ohne Fehler, Warnungen oder Skips; Composer-, Plattform- und Manifestprüfungen bestanden ebenfalls. Das war ein Test uncommittierter Vorbereitungsbytes, kein realer Pilot, kein vollständiger regulärer Testlauf und keine menschliche Gate-Evidenz.

Die damalige VPS-Belegablage ist `/home/ops/ai6-038-verification.zuqyxe/`: `source.sha256`, `base-commit.txt`, `working-tree.diff`, `targeted-final.log`, `targeted-junit.xml` und `evidence.txt`. Verfügbarkeit und Bindung neu prüfen; diese Anleitung und spätere Änderungen sind durch den damaligen Lauf nicht mitgetestet. Die Ablage ist kein Dauerhaftigkeitsversprechen.

| Offener Punkt am Vorbereitungsstand | Was vor dem jeweiligen nächsten Schritt vorliegen muss |
|---|---|
| Release-Gate meldet `AC-02` und `AC-04` | Separater Folgeauftrag liefert die fehlenden Nachweise; `FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS` wird fachlich geschlossen. Am Pilotcommit Exitcode 0 und null Skips; die mildere Upgrade-Ausnahme genügt nicht. |
| Grok-Sandbox und Leseschutz | Behobener Transport sowie native, pin- und runtimegebundene Evidenz; kein Entfernen von Grok aus dem vorgeschriebenen Pilot als Ersatz. |
| `fake` in produktiven Auswahlpools | Separater Folgeauftrag sperrt `fake` in Implementierungs-/Reviewer-Auswahl und Verifierpool. Nur reale Slots im Approval zu wählen reicht für den automatisch gewählten Verifier nicht. |
| Vorgates und realer Securityreview | Jede Pflichtzeile aus Protokoll A2 besitzt gültige signierte/externe Evidenz; Copilot-Securityprofil und seine Evidenz erreichen den Worker. |
| Restore: offene Vertragsentscheidung | Task 6, AC-13/TC-06 und die Workerpflicht von `ai6:restore` sind abzugleichen. Die menschliche Entscheidungsanfrage steht im README; diese Zeile ist keine zusätzliche Pilotvoraussetzung. Rollen-/Loginumfang bis zur Entscheidung nicht als geklärt behaupten. |
| Pilotbindung | Menschlich gewähltes vorhandenes V1-Ticket mit realem Änderungsbedarf, Pilotremote, Control-Branch, Reviewgegenstand, aktuelle Konfiguration, Budget und mobilem Zugang. |
| Migration und Phase B | Tatsächliche Lieferung der Legacy-Migration aus AI6-037, echter Altbestand und signiertes AI6-037/MG-01; zusätzlich MG-03 und gesonderte Phase-B-Freigabe. |

**Erster Entscheidungspunkt:** Ist eine Pflichtvoraussetzung noch offen, keine Pilot-Approval anlegen. Für jeden offenen Punkt konkrete Ursache, zuständigen Auftrag, benötigte menschliche Entscheidung und erwarteten Nachweis nennen. Die Reihenfolge bleibt auch dann verbindlich, wenn der Server bereits läuft.

## 4. Sichere technische Vorprüfung der Vorbereitung

Diese Prüfung darf vor dem realen Pilot in einer separaten Linux-Quellkopie ohne Betriebsdaten erfolgen. Sie prüft Dokumentation und bestehende Verträge, schließt aber keine Zeile mit realer Laufzeit- oder Signaturpflicht.

Quellkopie mit Entwicklungsabhängigkeiten gemäß identischem `composer.lock` bereitstellen. Aktuelle Arbeitsdateien einschließlich neuer Dokumente mitnehmen und per SHA-256 binden; `.env`, Credentials, Datenbanken und Betriebsvolumes auslassen. Die Strukturtests benötigen Git-Metadaten für `git check-ignore`; eine reine Testkopie darf dafür mit `git -c init.templateDir= init --quiet` initialisiert werden, ohne Remote oder Commit. Keine bestehende Auslieferung dafür verändern.

Als unprivilegierter Testbenutzer vor dem Laravel-Start die in der README vorgeschriebenen Dateirechte setzen:

```bash
chmod a-w app/AI6/Shared/Process/control-process-wrapper.sh
chmod a+x,a-w bin/ai6-git-ssh.sh
test ! -w app/AI6/Shared/Process/control-process-wrapper.sh
test ! -w bin/ai6-git-ssh.sh
test -x bin/ai6-git-ssh.sh
```

Jede Prüfung muss erfolgreich sein. In der separaten Testumgebung `APP_ENV=testing`, SQLite `:memory:` und die Testwerte für Schlüssel, Mail, Session und Cache aus `phpunit.xml` verwenden; keine Betriebswerte übernehmen. Beim bisherigen VPS-Lauf verursachte die fehlende optionale `tests/.env` Dotenv-Warnungen im Artisan-Reporter. Eine **leere Datei nur in der Testkopie** beseitigte diese Umgebungswarnung. Weder eine vorhandene Datei überschreiben noch Fehler durch geänderte PHPUnit-Regeln verbergen.

Im Root der Testkopie ausführen und Ausgabe/Exitcode sichern:

```bash
php artisan test tests/Feature/Shared/Runtime/RuntimeDocumentationTest.php tests/Feature/Shared/Doctor/DoctorDocumentationTest.php tests/Feature/Shared/Doctor/DoctorOptionsTest.php tests/Feature/Shared/Doctor/OperationalDoctorChecksTest.php tests/Unit/ScaffoldStructureTest.php tests/Unit/ManifestGeneratorTest.php tests/Unit/Tickets/TicketParserAndValidationTest.php --compact --do-not-cache-result
composer validate --strict
composer check-platform-reqs
php scripts/generate-ticket-manifest.php --check
```

`git diff --check` zusätzlich im wirklichen Entwicklungscheckout ausführen. Bei neuen Änderungen die dort betroffenen Regressionstests ergänzen. Die Gesamtsuite gehört an den Implementierungsabschluss in Schritt 12, nicht nach jede Dokumentationskorrektur. Ein grüner Dokumentationstest TC-09 beweist weder einen erfolgreichen Doctor noch einen tatsächlich durchlaufenen Statuswechsel.

### 4.1 Scopebericht und Linux-Nachweis der Reviewkorrektur

Die Reviewkorrektur vom 12. Oktober 2026 berichtet folgende bereits gelieferte Änderungen außerhalb der ursprünglichen `files`-Liste von AI6-038. Die Pfade werden nicht als zusätzlicher Pilotauftrag oder als erledigte manuelle Gates behandelt:

| Pfad | Ursache und Begründung |
|---|---|
| `app/AI6/Shared/Doctor/GitDoctorCheck.php` | Die Doctor-Konstruktion darf keine `ProcessConfiguration` auflösen: Ein zur Laufzeit schreibbarer Wrapper muss als negativer Git-Check gemeldet werden, statt schon beim Bauen des Kommandos abzustürzen. Die geschützten Factories werden erst im zuständigen Worker-Check aufgelöst; ihre Verweigerung bleibt erhalten. |
| `app/AI6/Shared/AI6ServiceProvider.php` | Verdrahtung über `new GitDoctorCheck` ohne vorzeitige Auflösung der Ausführungskonfiguration. |
| `tests/Feature/Shared/Doctor/DoctorOptionsTest.php` | Regression der Kommando-/Doctor-Konstruktion und der unveränderten Optionen. |
| `tests/Feature/Shared/Doctor/OperationalDoctorChecksTest.php` | POSIX-Negativnachweis `test_worker_git_check_rejects_a_runtime_writable_process_wrapper`: Der gültige, unveränderliche Wrapper funktioniert; ein schreibbarer Wrapper liefert `git_configuration_invalid` ohne Rohpfad. |
| `tests/Fixtures/Agents/release-gate-write-audit.json` | Veraltete Kontext-Hashes nach AI6-037 korrigiert; kein autoritativer Schreibpfad oder Auditentscheid wurde dadurch freigegeben. |
| `docs/AI6-038_TESTANLEITUNG.md` | Zusätzlich angeforderte Anleitung und dieser Scope-/Nachweisbericht außerhalb der ursprünglichen Dateiliste. |
| `tickets/AI6-038.md` | Menschlich beauftragte Vertragskorrektur: Rollen für `ready → todo` und Copilot-Modell an den bestehenden Code angepasst; Status und Gate-Ergebnisse unverändert. |

Linux-Nachweis am 12. Oktober 2026, Host `smartlife-chatbot-dev-01`, unprivilegierter Benutzer `ops`, PHP 8.5.4: isolierte Quellkopie `/home/ops/ai6-038-review.SClgrW/source`, Base-Commit `6eb8f9f58c7925a30467e44f37dc638dc67c0157`. Die Doctor-Korrektur und der Fixture-Hash sind bereits in `0600c8e` geliefert; geprüft wurden die aktuellen Bytes des genannten Base-Commits. Die Dokumentationskorrekturen waren uncommittiert und sind durch das Quellarchiv mit SHA-256 `40a32820bbf1f2af43bc3f2345116944398aceba4557b00a9045be27b356afba` gebunden. Dieser Bericht und spätere Textkorrekturen sind kein Bestandteil dieses früheren Archivs.

Vor Laravel-Start wurden ausschließlich in der Testkopie `chmod a-w app/AI6/Shared/Process/control-process-wrapper.sh` und `chmod a+x,a-w bin/ai6-git-ssh.sh` ausgeführt. Der Wrapper hatte anschließend `444`, UID/GID `1000:1000`; `ops` konnte ihn nicht schreiben. Die Testkopie besitzt leere Git-Metadaten ohne Remote und eine leere `tests/.env`, keine Betriebsdaten oder Providerzugänge.

Der Testaufruf aus §4 lief mit allen sieben dort genannten Dateien, `--compact --do-not-cache-result`, Exitcode 0: **119 Tests, 1191 Assertions, keine Fehler oder Skips**. Insbesondere liefen `DoctorOptionsTest` und der neue POSIX-Wrappertest tatsächlich, nicht als Windows-Skip. Beleg: `/home/ops/ai6-038-review.SClgrW/targeted.log`; die fünf Doctor-/Auditquellen sind zusätzlich in `doctor-source.sha256` gebunden. Ergänzend lief `php artisan test tests/Unit/Tickets/TicketStatusTransitionPolicyTest.php tests/Feature/Runs/FakeAgentReleaseGateContractTest.php --compact --do-not-cache-result` mit Exitcode 0: **39 Tests, 712 Assertions**, keine Fehler oder Skips; Beleg `contract-regression.log` im selben Verzeichnis. Dies sind technische Regressionsergebnisse, kein realer Pilot und keine Signatur eines MG-/EXT-Gates.

Nach den Dokumentationskorrekturen liefen die fünf Dokumentations-/Struktur-/Manifest-/Parserdateien aus §4 erneut mit Exitcode 0 (**75 Tests, 907 Assertions**, keine Fehler oder Skips; `final-documents.log`). `php vendor/bin/pint --test`, `php vendor/bin/phpstan analyse` ohne warmen Ergebniscache, `composer validate --strict`, `composer check-platform-reqs` und `php scripts/generate-ticket-manifest.php --check` endeten ebenfalls mit Exitcode 0; Belege `pint.log`, `phpstan.log`, `composer.log`, `platform.log` und `manifest.log` in derselben isolierten Ablage. `final-documents.sha256` bindet den damaligen Stand der vier Dokumentationsdateien einschließlich dieses Berichts, nicht die nachfolgende stdin-Korrektur. `git diff --check` war im Entwicklungscheckout grün. Der ausgeführte stdin-/Tar-Nachweis des Transferrezepts steht im folgenden Absatz; eine Übertragung zwischen realen Docker-Volumes oder eine Restoreprobe wurde nicht ausgeführt. Gesamtsuite und externer Locked-Install-Test wurden bei dieser Dokumentationskorrektur nicht ausgeführt; Dependency-, Plattform- und Installationscode blieben unverändert.

Nachprüfung des Transferrezepts am 12. Oktober 2026 auf demselben Linux-Host und Base-Commit: Der alte Heredoc wurde mit stdin-lesenden Docker-/SSH-Ersatzfunktionen tatsächlich ausgeführt und reproduzierte den Fehler — nur die Quellprüfung lief, deren `cat` konsumierte den restlichen Skripttext, dennoch Exitcode 0. Das korrigierte Subshell-Rezept aus README wurde als Skriptdatei ausgeführt, mit unabhängigem Operator-stdin und nur dem Beispiel-Checkoutpfad auf ein privates Testverzeichnis gebunden. Alle fünf Schritte liefen: Quellprüfung, Zielprüfung, Tar-Erzeugung, Tar-Entpacken und `stat`. Die Ersatzkommandos lasen stdin tatsächlich; Erzeugung und Entpacken verwendeten echtes GNU Tar. Übertragene Dateibytes wurden per SHA-256 verglichen, Verzeichnismodus `0700` und Dateimodus `0600` blieben erhalten. Ein vorhandenes Ziel und eine ungültige Backupkennung brachen vor dem Transfer mit Exitcode 1 ab. In allen Fällen blieben Arbeitsverzeichnis, Shelloptionen und Variablen der aufrufenden Shell unverändert. Es wurden keine echten Docker-/SSH-Transferkommandos oder Betriebsdaten verwendet; der Test lief als `ops`, nicht als Worker-UID 10001. Beleg: `/home/ops/ai6-038-review.SClgrW/stdin-transfer.log` mit den vier privaten Testverzeichnissen und README-SHA-256 `9ee08045259ed7a1c2e7d950e3d1ba652f25b2bb19f1df168d52f76168305591`. Dieser Ablaufnachweis ersetzt die frühere reine `bash -n`-Aussage.

Erweiterte Reviewprüfung am 12. Oktober 2026: Die oben gebundene frühere Subshell-Fassung schloss nicht benötigtes stdin noch nicht ausdrücklich und druckte Metadaten lediglich aus. Die aktuelle README schließt stdin bei Quellprüfung und Tar-Erzeugung über `/dev/null`; die übrigen SSH-Prüfungen verwenden `-n`, ausschließlich die SSH-Entpackpipeline erhält Archivbytes. Jeder Fehler wird ausdrücklich aus der Subshell zurückgegeben, sodass auch ein Aufruf in einer `if`-Bedingung die Abbrüche nicht durch Bashs abgeschaltetes `errexit` verliert. Ein hängender Zielsymlink wird gesondert abgewiesen, Tar verweigert vorhandene Dateien mit `--keep-old-files`, und die abschließenden Metadaten müssen exakt `700 10001:10001` sein; andernfalls scheitert der Block.

Der reproduzierbare Harness `/home/ops/ai6-038-review.SClgrW/transfer-owner-probe.py` führte **20 Fälle erfolgreich** aus, mit Beleg `transfer-owner-probe.log`: erfolgreicher Transfer, ungültige Kennung, verweigerte Quellprüfung, vorhandenes Ziel, hängender Zielsymlink, Fehler bei Tar-Erzeugung und Entpacken, während des Transfers entstehende Dateikollision, fehlgeschlagener `stat` und falsche Metadaten; jeder Fall direkt und als Bedingung ausgeführt. Nur synthetische private `/tmp/ai6-transfer-owner-*`-Bäume wurden verwendet. Die tatsächlichen GNU-Tar-, Test- und Statprozesse liefen über `setpriv --reuid=10001 --regid=10001 --clear-groups`; Eigentümer und Gruppe wurden numerisch geprüft. Die nichtleere binäre Testdatei wurde per SHA-256 verglichen; Verzeichnis `0700` und Datei `0600` gehörten danach weiterhin `10001:10001`. Die Dateikollision änderte die bereits vorhandene Datei nicht. Operator-stdin blieb vollständig erhalten; Shelloptionen, Arbeitsverzeichnis und Variablen des Aufrufers blieben unverändert. Docker-/SSH-Aufrufe waren weiterhin Ersatzfunktionen; ein realer Compose-/Remote-/Restore-Nachweis und seine menschliche Vertragsentscheidung bleiben offen. Host und Quellbasis entsprechen dem obigen isolierten Linux-Checkout; README-SHA-256 dieses Laufs: `bcdc84b8a84efb4d6bf559b6315d333307306384a9b4a97a14ade688ab0b4c40`. Der betroffene `RuntimeDocumentationTest` bestand mit **13 Tests, 338 Assertions**, Exitcode 0; Beleg `transfer-owner-documentation-final.log`. Diese Nachprüfung ergänzt die historischen Nachweise, ohne deren Dateihashes oder Gate-Ergebnisse umzudeuten.

## 5. Phase A verbindlich auswählen und vorbereiten

**Protokoll: A1–A5.** Mit dem Betreiber nur noch fehlende Entscheidungen klären und deren konkrete Werte festhalten:

| Entscheidung | Benötigte Bindung |
|---|---|
| Pilotprojekt und V1-Ticket | Dedizierte Pilotkopie oder ausdrücklich erlaubte Remote, Projekt-ID, Ticket-ID/-pfad, Commit/Blob, realer Änderungsbedarf, erfüllte Startvoraussetzungen und geeignetes deklariertes Gate für die Smartphoneprobe. Kein willkürliches Übungsticket und kein erfundenes M169. |
| Review-only-Gegenstand | GIT-011-Quelle, Basis und Vorher-OID; etwa ein menschlich erstellter Umsetzungsbranch gegen den gebundenen Control-Stand. |
| Erlaubte Wirkungen | Vorbereitung, Review-only- und Implementierungslauf, deren Statuscommits auf dem Control-Branch sowie manueller Codepublish auf den Runbranch. Produktionsmerge gehört nicht dazu. |
| Zugänge und Rollen | Approver/Operator, Testproviderkonten, Smartphone, Step-up und gebundene Origin. Tunnel mit Smartphone-SSH-Client/TOTP oder gesondert abgenommenes HTTPS. |
| Budget und Stopppunkt | Zeit, Kosten, Runden, Invocations, Interventionen, Umgang mit unbekannten Kosten, verantwortlicher Operator und kontrollierter Cancelpfad. |
| Betriebsziele | Pilot-Compose-Kontext, Wartungsfenster, Backupziel und separater Restorehost mit eigenen Volumes und geklärtem Loginvertrag. |

A2 anschließend **Zeile für Zeile** abgleichen. Dazu gehören die Gates von AI6-032, AI6-033, AI6-035, AI6-036, AI6-041, AI6-045, AI6-047, AI6-048 einschließlich EXT-01, AI6-049 und AI6-050; AI6-034 ist bei zusätzlichem Claude-Slot ebenfalls zu prüfen. Für nicht gewählte optionale Slots „nicht anwendbar“ begründen. Pflichtnachweise werden nicht dadurch optional, dass eine Abhängigkeit `done` ist. Insbesondere ersetzt der vorhandene VPS keine frische Installation nach AI6-036/MG-01.

### 5.1 Instanz, Doctor und Release-Gate

Den freigegebenen Stand über den bestehenden Auslieferungsprozess binden: Commit, sauberer Stand, Image-Digest, Host, Pins und Policy-/Runtimehashes. Ein dafür noch nötiges Deployment wird separat konkret freigegeben. Alle nachfolgenden Compose-Aufrufe gelten ausschließlich im **verifizierten Pilot-Kontext**. Vor der Ausführung die ermittelte Projektkennung und Compose-/Env-Datei explizit über die passenden `-p`, `-f` und `--env-file`-Argumente binden; die Kurzformen unten entsprechen den README-Rezepten und wählen das Ziel nicht selbst aus.

```bash
docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict
docker compose exec scheduler php artisan ai6:runtime-health --role=scheduler
docker compose exec app /opt/ai6/docker/healthcheck.sh app
```

Danach im **Linux-Testcheckout desselben Commits**, mit den in der README beschriebenen Linux-/Fixturevoraussetzungen:

```bash
php artisan ai6:release-gate
```

Doctor und Release-Gate müssen Exitcode 0 liefern; keine offenen Coverage-Lücken, fehlenden Pflichtnachweise oder Skips. `UNGEPRÜFT` nicht als erfolgreiche Rollenprüfung ausgeben. Fehlende privilegiert provisionierte Linux-Fixtures vor dem Nachweis bereitstellen; nicht die Tests oder Sicherheitskontrollen ändern. Belege in A3 referenzieren.

### 5.2 Provider und externe Smokes

Die bestehenden Abschnitte „Provider-Onboarding“ und die drei CLI-Transporte in der README sowie die Smoke-Testklassen lesen. Loginbedarf, Testkonten und Kosten vor realen Aufrufen binden. Login läuft rollenrichtig über `docker compose exec agent php artisan ai6:provider login <alias>`; den tatsächlich benötigten Alias vorher festlegen. Pins, Authprojektion, aktuelle Providerberichte und geltende Sandbox-/Capabilityevidenz müssen vorhanden sein. Kein Evidenzwert wird gesetzt, nur damit ein Preflight grün wird.

Im dafür eingerichteten Linux-Testcheckout nacheinander ausführen:

```bash
AI6_RUN_CODEX_SMOKE=1 php artisan test tests/Feature/Agents/CodexCliSmokeTest.php
AI6_RUN_GROK_SMOKE=1 php artisan test tests/Feature/Agents/GrokCliSmokeTest.php
AI6_RUN_COPILOT_SMOKE=1 php artisan test tests/Feature/Agents/GitHubCopilotCliSmokeTest.php --filter='test_real_linux_copilot_(review|security_review)'
AI6_RUN_PROVIDER_ONBOARDING_SMOKE=1 AI6_PROVIDER_ONBOARDING_TEST_ACCESS=1 php artisan test tests/Feature/Agents/ProviderOnboardingSmokeTest.php
AI6_BROWSER_SMOKE=1 php artisan test tests/Feature/Runs/RunObservationMobileBrowserSmokeTest.php
```

Die Flags allein richten keine Umgebung ein. Insbesondere verlangt der Onboarding-Smoke einen eingerichteten Linux-Agentprozess unter `strict` und `APP_ENV=testing`. Den optionalen Claude-Smoke nur für einen gewählten Claude-Slot nach dessen README-Rezept ergänzen. Ohne Flag übersprungene Tests sind kein TC-02-Nachweis. Der Browser-Smoke prüft Darstellung und Scrollen; die tatsächliche Smartphoneantwort aus Schritt 8 bleibt zusätzlich nötig.

### 5.3 Projekt, Ticket und Vorbereitungsnullpunkt

1. Im Panel das freigegebene Pilotprojekt registrieren; Deploy-Key, Remote und Clone über die vorhandenen Aktionen einrichten.
2. Die vollständige `.ai6/config.yaml` aus dem README-Pilotbeispiel gegen aktuellen Parser und das konkrete Ticket prüfen und auf dem freigegebenen Pilot-Control-Branch bereitstellen. Alle zehn Schlüssel, `ai6_detail_v1`, zwei Reviewer, manuelle Abschlüsse und deaktivierten automatischen Folgestart prüfen. Keine sensible Scopekategorie entfernen.
3. Im Panel `config_refresh` auslösen, Abschluss abwarten und gültige Konfiguration durch den Approver mit Step-up freigeben. Das ist keine frei erfundene Artisan-Operation.
4. Vorhandenes V1-Ticket endgültig binden. Fetch und `ticket_refresh` für dessen Pfad ausführen. Erwartet: `todo`, `valid`, `redaction_state=clear`, Contract-Hash, aktuelle Config-/Controlbindung und erfüllte Startberechtigung. Bei `invalid`, Redaction oder veralteter Bindung kein Approval.
5. Alle Remote-Refs einschließlich Tags und Reviewquelle mit OIDs erfassen. Nach der ausdrücklich erlaubten Vorbereitung ist dies der Nullpunkt A5. Den künftigen Runbranch als noch abwesend erfassen. Remotezugriffe über den vorhandenen gehärteten Git-Vertrag ausführen; keine improvisierten SSH-/Credentialoptionen.

### 5.4 Backup und neuer Laufzeitnachweis

Erst im freigegebenen Wartungsfenster und bei ruhenden Runs/Control Operations. Jede Zeile einzeln ausführen, Ergebnis prüfen, dann fortsetzen:

```bash
docker compose stop caddy app worker scheduler agent checker
docker compose run --rm --no-deps --entrypoint php worker artisan ai6:backup
docker compose start app worker scheduler agent checker caddy
docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict
```

Bei Backupfehler nicht automatisch starten oder zum Approval übergehen; dokumentierten Betriebsrückweg mit dem Operator klären. Backupkennung, Manifest-Digest, Herkunft sowie Code-/Schemastand sichern; Schlüsselpaket separat geschützt halten. Nach Neustart müssen Doctor, rolleneigene Healthprüfungen, Boot-IDs, Providerpräsenz/-berichte und Checker-Attestation erneut passen. Budget und Startfreigabe A4 vor dem ersten Approval abschließend binden.

## 6. Zuerst Review-only durchführen

**TC-03; Protokoll A6–A8, laufend A4/A5/A7.**

1. Neues Approval `review_only` mit dem gebundenen Gegenstand, Abschlussmodus `manual` und den unabhängigen Slots `grok-cli-review` und `copilot-cli-review` erstellen. Ein zusätzlicher Claude-Slot über Copilot ersetzt keinen unabhängigen Provider. Die Vorschau mit Ticket-, Config-, Prompt- und Instruktionsbindung prüfen; getrennt autorisiert einreihen und starten.
2. `todo → ready` durch Approval und `ready → in_progress` durch Claim einschließlich jeweiligem Control-Commit und Run-/Approval-ID beobachten. Alle Reviewer müssen denselben unveränderlichen Checkpoint mit Tree-OID und Diff-Hash erhalten.
3. Für jeden Slot Approval-Auswahl, Adapterübergabe und tatsächlich gemeldete Providerdaten getrennt in A7 erfassen. Verifierquelle je Finding prüfen; kein `fake`. `provider_default` und fehlende Metadaten nicht durch vermutete Modell-, Effort-, Token- oder Kostenwerte ersetzen.
4. Human-/Limit-Wartestände über berechtigte Panelaktionen bearbeiten. Bei `verification_independence` entscheidet der Mensch bewusst; die geplante Standardantwort `keep_finding` ist keine automatische Antwort. Falls ein geeigneter Human Request entsteht, sofort den Smartphoneablauf in Schritt 8 durchführen.
5. Laufzeit, Providerfehler, Nutzung und menschliche Findingeinordnung „bestätigt / verworfen / unklar“ erfassen. Bei `manual_report` den gebundenen Abschlussbericht prüfen und manuell bestätigen. Findings dürfen im Report sichtbar bleiben; ein report-only Abschluss verlangt keinen Codefix im selben Run.
6. Erst nach abgeschlossener Status-Saga `in_progress → ready`, terminalem Run und freier Projektsperre fortfahren. Erwartet: kein Codepublish, genau drei autorisierte Statuscommits auf dem Control-Branch und unveränderte übrige Refs. Bericht, Slotdaten und Refvergleich sichern.

## 7. Anschließend Implementierung durchführen

**TC-04; MG-01/MG-02; Protokoll A6/A7/A9/A10.**

1. Als Admin oder Operator mit frischem Step-up nach beendetem Review-only-Lauf im Panel `return_to_todo` ausführen und die Control-Mutation `ready → todo` abwarten. `return_to_todo` ist eine Statusoperation, kein Shellkommando. Ticketdatei oder Datenbank nicht manuell auf `todo` setzen.
2. Frisches Approval `implementation` mit aktuellen Snapshots und manuellem Push erstellen. Implementierung/Fix: Profil `codex-gpt-5.6-terra`, im Vorbereitungsstand Modell `gpt-5.3-codex`, Effort `medium`; Werte erneut am tatsächlichen Register prüfen, nie aus dem Profilnamen ableiten. Reviewer: Grok und Copilot wie zuvor. Grok bindet Modell/Effort `provider_default`, Copilot `auto`/`provider_default` gemäß `config/ai6.php`; vor Approval mit dem tatsächlich aufgelösten Profilregister vergleichen.
3. Separat einreihen/starten. Approval `todo → ready`, Claim `ready → in_progress`, Implementierung, Checks, Review und tatsächlich auftretende Verifikation/Fixturns beobachten. Bei Codeänderung vollständige neue Reviewrunde am neuen Checkpoint nachweisen. Budget und tatsächliche Requestzahl fortschreiben.
4. **Vor Candidate:** Mensch prüft realen Diff, letzten gültigen Checkpoint, prospektive Tree-OID und Diff-Hash. Jede deklarierte MG-/EXT-ID des gewählten Pilottickets wird mit typisierter Evidenz im Panel geschlossen. AI6-038/MG-01 und MG-02 dokumentieren diese Prüfungen; sie ersetzen die IDs des Pilottickets nicht. Gate-Evidenz auf dem Smartphone nach Schritt 8 eintragen. Offene oder stale Evidenz sperrt Candidate, Commit und Push.
5. **Erst auf Candidate:** Tatsächlichen Copilot-Securityturn und dessen Ergebnis an genau diesen Candidate binden. `security_gate` getrennt prüfen. Ein nicht freigegebenes Ergebnis sperrt Commit/Push. Ein gegebenenfalls erforderlicher Human Override ist candidategebunden und ersetzt weder den realen Review noch ein Vorgate.
6. Den konkret freigegebenen Runbranch-Push im Panel manuell auslösen. Erwartete Remote-OID vor der Wirkung, finalen Commit und Candidate-Tree binden. Danach Status-CAS `in_progress → review` auf dem Control-Branch einschließlich `## Recorded Scope` abwarten. Nicht durch einen manuellen Git-Push am Produktpfad vorbeiarbeiten.

Jede Phase erhält „beobachtet“ oder „nicht beobachtet“. Auch tatsächlich aufgetretene Verweigerungen separat festhalten. Keine Findings, Fehler oder DB-Eingriffe erzeugen, um einen Beleg zu erzwingen. Fehlender verpflichtender Nachweis bleibt offen; einen begrenzten Ergänzungslauf erst nach konkreter Freigabe durchführen. Ein nicht beobachteter Fixturn ist nicht automatisch ein Pilotfehler, aber auch kein durchgeführter Fixnachweis.

## 8. Smartphoneaktionen während der Läufe nachweisen

**TC-05 / AC-12; Protokoll A11.** Diesen Schritt rechtzeitig während Schritt 6/7 ausführen; nach abgeschlossenem Lauf kann der benötigte offene Request bereits fehlen.

1. Über den gebundenen Zugangsweg am echten Smartphone anmelden. Berechtigten Benutzer, korrekte Origin und funktionsfähigen Step-up prüfen.
2. Einen tatsächlich entstandenen Human Request samt zugestellter E-Mail öffnen. Request-ID, Version und Ausgangszustand festhalten; autorisierte Antwort vom Smartphone absenden. Genau eine wirksame Antwort beziehungsweise Fortsetzung beobachten.
3. Seite neu laden und Polling abwarten. Derselbe Request bleibt beantwortet; keine zweite Entscheidung, Fortsetzung oder sonstige wiederholte Wirkung. Antworttext oder Screenshot nur redigiert sichern.
4. Eine echte pilotticketspezifische Gate-Evidenz **vor Candidate** mit frischem Passkey-/TOTP-Step-up vom Smartphone eintragen. Gate-ID, Checkpoint-/Tree-/Diffbindung, Version und Wirkung festhalten; nach Reload und Polling keine zweite Wirkung.
5. Runübersicht, Kerninformationen, Diff, Findings und Request/Gate auf Lesbarkeit ohne horizontales Scrollen prüfen.

Entsteht kein geeigneter Human Request, bleibt AC-12 offen, auch wenn der Browser-Smoke grün ist. Nicht „nicht beobachtet“ als Erfüllung eintragen. Benötigten Ergänzungsnachweis mit dem Betreiber klären, ohne einen künstlichen Request zu erzeugen.

## 9. Refvergleich, Cleanup und getrennte Restoreprobe

**TC-06 / AC-06 / AC-13; Protokoll A5/A12.**

Nach dem Publish Candidate-Tree und Tree des veröffentlichten Runbranch-Commits vergleichen. Auf dem Control-Branch sind ab dem Nullpunkt genau die sieben autorisierten Statusmutationen beider Läufe einschließlich `return_to_todo` und abschließend `## Recorded Scope` zuzuordnen. Alle anderen Refs mit dem Nullpunkt vergleichen; unerwartet neue oder verschwundene Refs ausdrücklich erfassen.

Für beide konkreten Pilot-Run-IDs die tatsächlichen Pfade von Run-Worktrees, Review-Checkpoints, Execution-Homes und Exportkopien ermitteln und ihr reguläres Entfernen nachweisen. Andere Runs und aufbewahrte Auditdaten bleiben erhalten. Manuelles Löschen wäre kein Nachweis des Produkt-Cleanups; bei Resten Ursache und Folgeauftrag aufnehmen.

### Restore zuerst auf Zulässigkeit prüfen

Die Pilotinstanz bleibt erhalten. Vorher redigierte Belege außerhalb der Restoreziele sichern. Restore ausschließlich auf einem getrennten Host, eigenen Volumes und derselben Software-/Schema-/Imagebindung des Vorbereitungsbackups. Ein anderer Compose-Projektname auf demselben Host genügt wegen der festen Subnetze nicht. Die offene Vertragsentscheidung aus Schritt 3 betrifft Workeridentität und Loginumfang; die Anfrage im README menschlich entscheiden lassen, bevor die davon abhängige Probe durchgeführt wird. Sie ist keine zusätzliche Pilotvoraussetzung. Bis dahin weder eine Vertragsauslegung noch einen Restore-/Loginerfolg behaupten.

Nach Vertragsentscheidung, Zielprüfung und konkreter Restorefreigabe das entsprechend freigegebene README-Rezept schrittweise verwenden:

```bash
docker compose -p ai6-pilot-restore run --rm --no-deps init
# Den konkreten SSH-/Tar-Transfer aus README auf dem Pilot-Linux-Host ausführen; Schlüsselpaket getrennt bereitstellen.
docker compose -p ai6-pilot-restore run --rm --no-deps --entrypoint php worker artisan ai6:restore <privater-backuppfad>
docker compose -p ai6-pilot-restore up -d --no-deps app
```

`<privater-backuppfad>` erst durch den geprüften Pfad ersetzen; Compose-/Env-Dateien explizit an das Restoreziel binden. Der einmalige Restoreprozess braucht Workeridentität, startet durch `--entrypoint php` aber keinen Queueworker. Als Dienste nur `init` zur Provisionierung und `app` verwenden; keine Worker-/Scheduler-/Agent-/Checker-Daemons, keine Providerlogins und keinen Git-Zugriff auf dieser Instanz.

Unmittelbar nach Restore die leeren `sessions` und `login_confirmations` durch gezielte, wertarme Leseprüfung nachweisen. Alte Browsersession muss widerrufen sein; einen vollständigen neuen Login nur bei entsprechend entschiedenem Vertragsumfang über den gesondert abgenommenen Zugangs-/Mailweg prüfen. Keine Authbarriere abschalten, SMTP-Zugangsdaten an `app` geben, Ingressdaten fälschen oder einen allgemeinen Queueworker starten.

Abgelaufene Rohdaten bleiben unlesbar, Tombstones bleiben erhalten und gültige Artefakte bleiben lesbar. Der Restore führt den ersten Retention-Sweep aus. Vorher-/Nachherzustände für einen zweiten Sweep sichern und ausschließlich den benannten Callback starten:

```bash
docker compose -p ai6-pilot-restore exec app php artisan schedule:test --name=ai6-run-retention
```

Keine zusätzliche Änderung an bereits bereinigten Daten; zwischenzeitlich neu abgelaufene Daten gesondert erklären. Exitcode allein ist kein Idempotenzbeweis. Kein `schedule:run`, das weitere geplante Wirkungen auslösen könnte. Bei Restorefehler Rollen gestoppt lassen und den README-Rückweg mit dem Operator verfolgen. Die Probe ersetzt nicht die eigenständige Git-/Rotationsübung AI6-049/MG-01.

## 10. Teil A durch den Menschen abnehmen lassen

**MG-03; Protokoll A13.** Alle A1–A12-Felder auf echte, passend gebundene Evidenz prüfen; offene Pflichtfelder blockieren ein positives Ergebnis. Zweiter Entwickler vollzieht Anleitung und Belege nach. Fehlende Phasen, tatsächliche Grenzen, Folgeaufträge, Budgetverbrauch und Stopppunkt bleiben sichtbar.

Die menschliche Prüfperson trägt ihr Ergebnis und ihre Signatur ein. Erst die gültige Signatur schließt MG-03. Dem Betreiber ausdrücklich mitteilen: „Teil A abgenommen; Phase B und die vollständige Ticketabnahme bleiben bis zu deren Nachweisen offen.“ Das LLM ändert den Ticketstatus nicht.

## 11. Phase B erst nach erfülltem Startprädikat

**AC-07/AC-10, TC-07/TC-08, MG-04; Protokoll B1–B4.** Vor Beginn müssen vier Punkte feststehen:

1. Die Legacy-Migration aus AI6-037 ist tatsächlich implementiert; Klassen, Kommando, Fixtures und Vertrag wurden am neuen Stand geprüft.
2. AI6-037/MG-01 ist für einen benannten **echten Altbestand** gültig signiert. Die V1-Tickets von AI6 und das synthetische Fixture `legacy-m169.md` ersetzen ihn nicht.
3. AI6-038/MG-03 aus Teil A ist gültig signiert.
4. Der Mensch hat Phase B und den gegen die Migrationslieferung neu abgeleiteten Scope freigegeben; die Release-Lineage zum Migrationsnachweis ist gebunden.

Fehlt etwas, die offene Implementierungs-/Abnahmearbeit konkret übergeben. Am Vorbereitungsstand sind weder Migrationskommando noch Cutoff geliefert. Deshalb enthält diese Anleitung keinen erfundenen ausführbaren Migrationsbefehl.

Nach Freigabe die noch ausstehende Implementierung aus Tasks 7/8/10 liefern: `TicketReadModelProjector` ruft `LegacyTicketReader::read()` nicht mehr auf; erkanntes Legacy wird `invalid` mit `legacy_format_unsupported`, ohne Contract-Hash und mit Hinweis auf das dann vorhandene Migrationskommando. `LegacyTicketReader::read()` bleibt für die Migration unverändert. Den bestehenden Parser-Test entsprechend fortschreiben und den im Ticket geplanten, am Vorbereitungsstand **noch fehlenden** Featuretest `tests/Feature/Tickets/LegacyCutoffTest.php` für TC-07/TC-08 anlegen. Keine vorgezogene Cutoff-Änderung in Phase A.

Neue Assertions einmal rot beobachten und anschließend die gezielten Tests samt betroffenen Regressionen grün ausführen. Erst nach Prüfung, ausdrücklichem Commit-/Auslieferungsauftrag und Bindung des Cutoff-Stands die reale Revalidierung durchführen:

| Bestand / Fall | Erwartung nach abgeschlossenem `ticket_refresh` je Pfad |
|---|---|
| Migriertes generisches Ticket | `valid`, Contract-Hash, freigegebenes Projektprofil `generic_v1` |
| Migriertes Detailticket | `valid`, Contract-Hash, freigegebenes Projektprofil `ai6_detail_v1` |
| Bereits gültiges V1-Ticket der AI6-Pilotkopie | Weiterhin `valid`, unveränderter Contract-Hash bei unverändertem Vertrag |
| Verbliebene Legacydatei | Sichtbar `invalid`, `legacy_format_unsupported`, kein Contract-Hash; keine stille Akzeptanz |

Im benannten Legacy-Projekt **und** in der AI6-Pilotkopie die vollständigen Ticketkandidatenlisten am gebundenen Control-Commit erfassen. Jeden Pfad über das vorhandene Panel-`ticket_refresh` aktualisieren, Abschluss abwarten und Projektionen einschließlich Anzahlen, Profil-/Configbindung und Fehlern abgleichen. Kein zweiter Reindexpfad. `ai6:tickets:reproject-unparsed --project-config` überspringt schon aktuell gebundene Projektionen und genügt dafür nicht.

B1–B3 samt Cutoff-Commit, ausgeliefertem Image, Korpus und Lineage vollständig belegen. Zweiter Entwickler prüft den Nachvollzug; menschliche Prüfperson entscheidet und unterschreibt B4 für MG-04. Bleibt eine Pflicht offen, bleibt die vollständige Ticketabnahme offen.

## 12. Abschlusspaket und menschliche Ticketentscheidung

Die folgende Übersicht ist ein Prüfraster. Die verbindliche Zuordnung bleibt die Tabelle `AC Coverage` im Ticket; hier wird kein Kriterium umdefiniert oder abgehakt.

| Kriterien | Vorzulegende Evidenz |
|---|---|
| AC-01 / AC-09 | Gültiges V1-Ticket, Approval und realer Review-only-Abschluss: TC-03, MG-03 |
| AC-02 | Reale Provider-Smokes und Slotvergleich: TC-02, TC-04, MG-03 |
| AC-03 | Gemeinsamer Checkpoint und neue Runden: TC-01, TC-04, MG-01 |
| AC-04 | Vor Candidate wirksame Gatebindungen: TC-01, MG-01, MG-02 |
| AC-05 / AC-06 | Candidate-Securityreview, Publish und vollständiger Refvergleich: TC-04, MG-03 |
| AC-08 | Nachvollziehbare Dokumentation und zweiter Entwickler: TC-09, MG-03 |
| AC-12 | Reale Smartphoneantwort und Gate-Evidenz samt Einmaleffekt: TC-05, MG-03 |
| AC-13 | Reguläres Cleanup und isolierter Restore: TC-06, MG-03 |
| AC-07 | Cutoff: TC-07, MG-04 |
| AC-10 | Vollständige Revalidierung: TC-08, MG-04 |
| AC-11 | Vollständig gebundene Protokollteile: TC-01, MG-03, MG-04 |

Am **finalen Implementierungsstand** die Definition of Done und das Reviewpaket aus Plan §12.2/§12.4 vollständig durcharbeiten. Dazu gehören aktuelle gezielte Nachweise und zum Abschluss die reguläre Suite sowie die Qualitätsprüfungen:

```bash
php artisan test
php vendor/bin/pint --test
php vendor/bin/phpstan clear-result-cache
php vendor/bin/phpstan analyse
composer validate --strict
composer check-platform-reqs
php scripts/generate-ticket-manifest.php --check
git diff --check
```

Die reguläre Suite läuft ohne `AI6_PHP85_BINARY` und `AI6_COMPOSER_PHAR`; Linux-/POSIX-Pflichten müssen in geeigneter Linux-Laufzeit mit den dokumentierten Fixtures erfüllt sein. Skips einzeln bewerten, externe Pflicht-Smokes separat nachweisen. Für geänderte PHP-Dateien Syntaxprüfung durchführen. PHPStan einmal ohne warmen Ergebniscache prüfen. Den externen Locked-Install-Test nur bei entsprechendem Dependency-/Lockfile-/Plattform-/Installationsscope mit den ausdrücklich ausgewählten PHP-8.5- und Composerpfaden nach README ausführen; einen nicht ausgeführten Test so benennen.

Reviewpaket zusammenstellen: freigegebene Ticketrevision, Base-/Checkpoint-/Cutoff-Commits, vollständiger Diff, effektiver Scope und Entscheidungen, Testausgaben, AC-/TC-Belege, gebundene Gate-Evidenz, unveränderte strukturierte Reviews und Findinghistorie. Der neue Anleitungspfad ist eine ausdrücklich angeforderte Dokumentationsergänzung außerhalb der ursprünglichen `files`-Liste; den effektiven Scope im Bericht nennen, nicht eigenmächtig ins Ticket schreiben.

Abschlussbericht an den Betreiber: erfüllte Kriterien mit Belegen, offene Punkte, Testresultate, beide menschlichen Signaturen und exakte Implementierungsbindung. Erst wenn beide Phasen einschließlich aller Pflichtnachweise erfüllt sind, ist die vollständige Abnahme entscheidbar. **Ticketstatus und Abnahmeentscheidung bleiben beim Menschen beziehungsweise beim dafür vorgesehenen AI6-Ablauf.** Kein eigenmächtiges `status: done`, kein stiller Commit oder Push und keine automatische Entfernung alter Tools.
