# AI6-038 — Pilotprotokoll und getrennte Cutoff-Abnahme

Ergebnisfreie Vorlage. Ausschließlich die menschliche Prüfperson trägt nach der jeweiligen Durchführung Beobachtungen, Entscheidungen und Unterschriften ein. Leere Felder sind offene Nachweise, keine Zustimmung. Implementierung, Dokumentationstests, Fake-/Windows-Läufe und übersprungene Smokes tragen kein Ergebnis ein. Ticketstatus und Gate-Ergebnisse werden durch diese Vorlage nicht geändert.

Anleitung: [README, Pilot AI6](../README.md#pilot-ai6). Vertrag: [AI6-038](../tickets/AI6-038.md), Plan V1.7.13 (§13.2, §15). Teil A bestätigt ausschließlich den realen V1-Pilot (MG-03). Teil B verlangt zusätzlich die gelieferte Legacy-Migration und das signierte AI6-037/MG-01; ohne echten Altbestand bleibt er offen. Erst beide Phasen erfüllen das gesamte Ticket.

Nur redigierte, zugriffsgeschützte Belegreferenzen eintragen: keine Roh-Providertranskripte, Credentials, Gerätecodes, Tokens, Session-Cookies oder Schlüsselwerte. Referenzen tragen Commit/Stand, Zeitpunkt und gegebenenfalls Digest; vor der Restoreprobe werden Pilotbelege außerhalb ihres Rücksetzbereichs gesichert. Die folgenden Tabellen bei Bedarf um Zeilen erweitern, ohne nicht beobachtete Phasen als bestanden auszugeben.

## Teil A — V1-Pilot, MG-03

### A1. Menschliche Bindung vor dem Start

| Feld | Vom Prüfer auszufüllen |
|---|---|
| Prüfperson, verantwortlicher Approver/Operator, Datum und Zeitzone | |
| Dedizierte AI6-Pilotkopie oder ausdrücklich freigegebene Pilot-Remote, Projekt-ID | |
| Freigabe der Vorbereitung, der zwei Läufe und ihrer Remote-Wirkungen (Person, Zeitpunkt, Referenz) | |
| Vorhandenes V1-Pilotticket: ID, Pfad, Ticket-Commit und Blob-OID | |
| Realer Änderungsbedarf und erfüllte Startvoraussetzungen des Pilottickets | |
| Control-Branch und Control-OID nach Vorbereitung | |
| `ai6_detail_v1`, `valid`, `redaction_state=clear`, Ticket-Contract-Hash | |
| Reviewgegenstand nach GIT-011: Quelltyp, verwalteter Branch/Quelle, Basis- und Vorher-OID | |
| Exakter Implementierungscommit der Instanz und sauberer Auslieferungsstand | |
| Image-Digest, Linux-Host, Plattform/Architektur, Compose-Projektkennung | |
| Getesteter Linux-Checkout: Commit, Pfad und Entwicklungsabhängigkeiten | |
| Securityprofil `strict`, Policyhash und Runtimeprofil-Hashes | |
| CLI-Pins, Binaryherkunft und Binary-SHA-256 je Provider | |
| `.ai6/config.yaml`: Control-/Blobbindung, Config-Hash, Freigabereferenz | |
| Promptprofil/-version und Prompt-/Instruktionssnapshot-Hashes je Lauf | |
| Securityprofil `copilot-cli-review`, Rolle `security_review`, Worker-Konfigurationsbeleg | |
| Mobiler Zugang: Entscheidung, SSH-Tunnel/gesondert abgenommenes HTTPS, Origin und Port | |
| Smartphone, SSH-Client bei Tunnelzugang, TOTP/Passkey und Step-up-Verfahren | |
| Geschützter Ablageort der redigierten Belege außerhalb der Restore-Ziele | |

### A2. Voraussetzungstabelle — offene Pflichtvoraussetzungen sperren den Pilot

Die ausdrücklich als offene Vertragsentscheidung bezeichnete Restorezeile ist keine zusätzliche Pflichtvoraussetzung. Sie dokumentiert die Anfrage an den Menschen und wird nicht als Pilotsperre ausgewertet.

Die Bestandsbeschreibung im Ticket ist keine Freigabe. Je Zeile den zuständigen menschlichen Entscheider oder konkreten Folgeauftrag, den gebundenen Implementierungsstand und die signierte Evidenz nennen. Ein `done`-Status, eine vorhandene Linux-Auslieferung, eine Versionsprobe oder ein gesetzter Evidenzwert ersetzt keine Signatur und keinen Laufzeitnachweis. Bei geänderten Implementierungsbytes ist die Gültigkeit neu zu prüfen.

| Voraussetzung | Zuständige Person / Entscheidung / Folgeauftrag | Erforderliche Evidenz | Gebundener Stand / Beleg / Entscheidung |
|---|---|---|---|
| Release-Gate-Lücken `AC-02` / `AC-04` | | Lücken separat geschlossen; Linux-Release-Gate am Pilotcommit, Exitcode 0, null Fehler und Skips | |
| Grok-Transport | | Separat behobener Sandboxblocker und Leseschutz, nativer Nachweis am Pin | |
| Realer Securityreview, AI6-050/MG-01 | | Signiertes Candidate-Review; `ready` für Security-Tupel; `AI6_AGENT_SECURITY_REVIEW_PROFILE` ausschließlich in `worker` | |
| Kein `fake` in Verifierpool, Reviewer- und Implementierungsauswahl | | Gelieferter Folgeauftrag und tatsächliche Auswahl-/Poolprüfung auf Pilotinstanz | |
| AI6-033/MG-01 | | Signierte Codex-Laufzeit-/Sandboxevidenz mit aktuellem Pin und Bindungen | |
| AI6-041/MG-01 | | Signierte Grok-Laufzeitevidenz mit aktuellem Pin und Bindungen | |
| AI6-048/MG-01 und EXT-01 | | Signierte beziehungsweise externe Copilot-Evidenz für aktuelle Rollen/Adapterbytes | |
| AI6-032/MG-01 | | Signierte Linux-Release-Abnahme am gültigen Stand | |
| AI6-035/MG-01 | | Signiertes Fresh-install-Onboarding aller drei V1-Provider | |
| AI6-036/MG-01 | | Signierte frische strict-Installation auf leerem Host; bestehender Deploy genügt nicht | |
| AI6-049/MG-01 | | Signierte eigenständige Restore-/Git-/Rotationsübung | |
| AI6-045/MG-01 | | Rollenrichtige Checkerabnahme mit gültiger Bindung | |
| AI6-047/MG-01 | | Agentrollen-/Mailboxabnahme mit gültiger Bindung | |
| AI6-034/MG-01 bei zusätzlichem Claude-Slot | | Gebundene Modell-/Providerabnahme; ohne diesen Slot ausdrücklich nicht anwendbar begründen | |
| Vorhandenes V1-Pilotticket aus AI6 | | Menschliche Auswahl von ID/Pfad/Commit/Änderungsbedarf; `todo`, gültig unter `ai6_detail_v1`, Startvoraussetzungen erfüllt | |
| Zwei unabhängige Reviewer und vollständige Projektkonfiguration | | Alle zehn Schlüssel; Slots Grok/Copilot; `config_refresh`, Step-up-Freigabe und Config-Hash | |
| Review-only-Gegenstand und Pilotremote | | Menschliche Entscheidung; GIT-011-Quelle, Basis und Vorher-OID; ausdrücklich erlaubte Ref-Wirkungen | |
| Mobiler Zugangsweg | | SSH-Client und TOTP über gebundene Origin oder gesondert abgenommenes HTTPS; Step-up möglich | |
| Exakte Linux-Auslieferung | | Commit, sauberer Stand, Image-Digest, Host und identischer Testcheckout | |
| Offene Vertragsentscheidung zur Restoreprobe (keine zusätzliche Pilotsperre) | | Task 6 gegenüber AC-13/TC-06: Workeridentität für Restore und Umfang des Loginnachweises; menschliche Entscheidung gemäß Anfrage im README, keine neue Pflichtvoraussetzung | |

### A3. Vorbereitung und Nachweise vor Approval

Die Reihenfolge entspricht der README. Ein fehlgeschlagener Schritt erlaubt keinen Übergang zum Approval. Doctor-/Healthausgaben nur redigiert referenzieren; `UNGEPRÜFT` ist keine Rollenevidenz. Das Release-Gate läuft im Linux-Checkout, nicht im Produktionsimage.

| Reihenfolge / Prüfung | Zeitpunkt, Host, Commit / Kommando oder Paneloperation | Beobachtung, Exitcode, Beleg |
|---|---|---|
| 1. Freigegebenen Instanzstand bereitstellen, Voraussetzungstabelle prüfen | | |
| 2. Doctor vor Backup: `docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict` | | |
| 3. Ergänzend `ai6:runtime-health --role=scheduler` im Scheduler und `/opt/ai6/docker/healthcheck.sh app` im Appcontainer | | |
| 4. `php artisan ai6:release-gate` im Linux-Checkout, ohne `AC_COVERAGE_GAPS`/Skips | | |
| 5. Providerloginbedarf freigeben, Login/aktuelle Berichte je Alias nach AI6-035 prüfen | | |
| 6. Codex-/Grok-/Copilot-Smokes und Onboarding-Smoke mit Testzugängen, Flag und Bindung | | |
| 7. Pilotprojekt/Remote registrieren, Clone und vollständige `.ai6/config.yaml` bereitstellen | | |
| 8. `config_refresh` abschließen, Approver-Freigabe nach Step-up und zwei Slots prüfen | | |
| 9. V1-Ticket auswählen/binden, Fetch und `ticket_refresh` abschließen, Profil/Hash/Startbarkeit prüfen | | |
| 10. Vorbereitungsstand aller Refs als Nullpunkt in A5 erfassen | | |
| 11. Ruhende Instanz; `docker compose stop caddy app worker scheduler agent checker` | | |
| 12. `docker compose run --rm --no-deps --entrypoint php worker artisan ai6:backup` | | |
| 13. Backupkennung, Herkunft, Manifest-Digest, gleicher Code-/Schemastand und geschütztes getrenntes Schlüsselpaket | | |
| 14. `docker compose start app worker scheduler agent checker caddy` | | |
| 15. Doctor nach Backup-Neustart: `docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict` | | |
| 16. Neue Boot-IDs, frische Präsenz/Berichte/Checker-Attestation und rolleneigene Healthprüfungen | | |
| 17. Aktuelle gültige Approval-Vorschau, Budget und menschliche Startfreigabe | | |

| Provider-/Browsernachweis | Flag / Test | Commit, Host, Zugangsvoraussetzung ohne Secret | Ergebnis, Skips, Beleg |
|---|---|---|---|
| Codex | `AI6_RUN_CODEX_SMOKE=1`, `CodexCliSmokeTest` | | |
| Grok | `AI6_RUN_GROK_SMOKE=1`, `GrokCliSmokeTest` | | |
| Copilot Review und Securityreview | `AI6_RUN_COPILOT_SMOKE=1`, `GitHubCopilotCliSmokeTest` | | |
| Optionales Claude-Modell | Dasselbe Copilot-Flag, eigener Modellwert/Test; sonst nicht anwendbar begründen | | |
| Onboarding | `AI6_RUN_PROVIDER_ONBOARDING_SMOKE=1`, `AI6_PROVIDER_ONBOARDING_TEST_ACCESS=1`, `ProviderOnboardingSmokeTest` | | |
| Mobile Darstellung | `AI6_BROWSER_SMOKE=1`, `RunObservationMobileBrowserSmokeTest` | | |

### A4. Budget und Stopppunkt vor dem Lauf

| Vorab zu bindende Grenze | Freigegebener Wert, Verantwortlicher und Beleg | Tatsächlicher Verbrauch / Stoppeffekt |
|---|---|---|
| Fix-, Review- und Verifikationsrunden; Agentinvocations | | |
| Interventionsgrenze und erwartete Anzahl `verification_independence` für Grok-Findings | | |
| Standardantwort `keep_finding`; bewusste menschliche Entscheidung je Request | | |
| Zeitbudget je Lauf / insgesamt | | |
| Kostenbudget, Währung, Quelle und Umgang mit `unknown` | | |
| Stopppunkt, verantwortlicher Operator und kontrollierter Cancelpfad | | |
| Gesonderte Freigabe eines begrenzten Ergänzungslaufs bei fehlender Beobachtung | | |

### A5. Vollständiger Refvergleich ab Vorbereitungsnullpunkt

Jeden Ref der Remote einzeln erfassen, einschließlich Tags und Reviewquelle. Fehlende Refs als „abwesend“ kennzeichnen; unerwartet neu angelegte oder verschwundene Refs ebenfalls aufnehmen. „Kein Codepublish“ beim Review-only-Lauf erlaubt dessen drei autorisierte Control-Statuscommits, keine beliebigen Refänderungen.

| Ref / Rolle | OID nach autorisierter Vorbereitung (Nullpunkt) | OID nach Review-only | OID nach Implementierung | Erwartete Änderung / Beleg / Abweichung |
|---|---|---|---|---|
| Control-Branch: | | | | |
| Implementierungs-Runbranch: | | | | |
| Reviewgegenstand: | | | | |
| Übriger Ref: | | | | |

| Zusätzliche Bindung | Tatsächlicher Wert / Beleg |
|---|---|
| Erwartete Remote-OID beim manuellen Runbranch-Push | |
| Candidate-Tree-OID, finaler Commit, dessen Parent und Tree-OID | |
| Vergleich Candidate-Tree = veröffentlichter Commit-Tree | |
| Vollständige Vorher-/Nachher-Refinventare, Zeitpunkte und Digests | |
| Control-Branch ausschließlich autorisierte Statuscommits, `## Recorded Scope` und Status-CAS | |

### A6. Statusfolge und zwei eigenständige Approvals

| Operation in Reihenfolge | Run-/Approval-/Operations-ID | Control-Commit / erwartete und tatsächliche OID | Zeitpunkt / Beleg |
|---|---|---|---|
| Approval `review_only`: `todo → ready`, Abschlussmodus `manual` | | | |
| Claim `review_only`: `ready → in_progress` | | | |
| `manual_report` bestätigt: `in_progress → ready`, kein Codepublish | | | |
| Admin/Operator mit frischem Step-up: `return_to_todo`, `ready → todo` | | | |
| Neues Approval `implementation`: `todo → ready`, Pushmodus `manual` | | | |
| Claim `implementation`: `ready → in_progress` | | | |
| Nach Runbranch-Push: `in_progress → review`, `## Recorded Scope` | | | |

### A7. Slot-Tabelle und tatsächliche Providerwerte

Je Run, Runde und Invocation eine Zeile, einschließlich Fix, Verifikation und Securityreview. Jede der drei Wertspalten enthält getrennt Profil, Provideralias, Modell, Effort und Promptprofil; Approval-/Prompt-/Runtime-/Instruktionshashes als Beleg mitführen. `provider_default` bleibt die gebundene Auswahl, fehlende Providerangaben bleiben `unknown`. Erwartungswerte aus der Anleitung sind keine beobachteten Metadaten. Kein Slot und kein Verifier darf `fake` verwenden.

| Run / Runde / Slot / Rolle / Session | Im Approval gebundene Auswahl | An Adapter übergebene Werte und Beleg | Vom Provider gemeldete Metadaten und Beleg |
|---|---|---|---|
| Review-only / Grok-Qualitätsreview | | | |
| Review-only / Copilot-Qualitätsreview | | | |
| Implementierung / Codex | | | |
| Implementierung / Grok-Qualitätsreview | | | |
| Implementierung / Copilot-Qualitätsreview | | | |
| Fixturn (falls beobachtet) | | | |
| Finding-Verifikation (falls beobachtet) | | | |
| Candidate / Copilot-Securityreview | | | |
| Optionaler Claude-Slot (sonst nicht anwendbar) | | | |

| Run / Finding / Quellslot | Tatsächlich gewählter Verifier mit Profil/Alias/Modell/Effort oder `verification_independence` | Request / Antwort / Zeitpunkt | Beleg der Unabhängigkeit / Wirkung |
|---|---|---|---|
| | | | |

### A8. Review-only: Messwerte und Abschlussbericht

| Run / Runde / Slot | Beginn / Ende / Laufzeit | Providerfehler / Wiederholung | Gemeldete Nutzung / Kosten / Quelle (`unknown` erhalten) |
|---|---|---|---|
| | | | |

| Finding-ID / Slot | Menschliche Einordnung: bestätigt / verworfen / unklar | Begründung / Beleg | Wirksame Disposition und autorisierte Entscheidung |
|---|---|---|---|
| | | | |

| Abschlussbindung | Tatsächlicher Wert / Beobachtung / Beleg |
|---|---|
| Gemeinsamer unveränderlicher Checkpoint, Tree-OID und Diff-Hash aller Reviewer | |
| Vollständige Slot-/AC-Abdeckung, aufgelöste Human-/Limit-Wartestände | |
| Gebundener Abschlussbericht mit weiterhin sichtbaren Findings | |
| `manual_report`: bestätigende Person, Zeitpunkt, Version und Einmaleffekt | |
| Kein Codepublish; genau drei autorisierte Statuscommits und bestätigtes `ready` | |

### A9. Implementierung: tatsächlich beobachtete Phasen

„Nicht beobachtet“ ist kein bestandener Nachweis. Kein Erzwingen durch Produktfehler, künstliche Findings oder Datenbankeingriffe. Ergänzungsläufe benötigen eigene begrenzte Freigaben; die kostenpflichtige Wiederholung der deterministischen Fehlermatrix gehört nicht zum Pilot.

| Phase | Beobachtet / nicht beobachtet | Auslöser, Run / Runde / Slot | Ergebnis, Laufzeit, Nutzung / Fehler, Beleg |
|---|---|---|---|
| Implementierungsturn / validierter Änderungsimport | | | |
| Checks | | | |
| Grok- und Copilot-Review am selben Checkpoint | | | |
| Finding | | | |
| Quellenabhängige Verifikation / `verification_independence` | | | |
| Fixturn und vollständige neue Reviewrunde nach Codeänderung | | | |
| Human Request und E-Mail | | | |
| Gates vor Candidate | | | |
| Candidate-Erzeugung | | | |
| Securityreview auf Candidate / `security_gate` | | | |
| Manueller Runbranch-Push / Statussynchronisation | | | |
| Cleanup | | | |

| Tatsächlich beobachtete Verweigerung | Auslöser / Stand / Run | Ergebnis / Beleg oder ausdrücklich nicht beobachtet |
|---|---|---|
| Offenes Gate verhindert Candidate, Commit und Push | | |
| Stale Gate nach geänderter Bindung | | |
| Codeänderung erfordert vollständige neue Reviewrunde | | |
| Weitere Verweigerung | | |

### A10. MG-01/MG-02 vor Candidate und getrenntes Securitygate danach

Die Gate-IDs stammen aus dem gewählten Pilotticket. AI6-038/MG-01 bindet dessen menschliche Diffprüfung, MG-02 dessen deklarierte externe/UX-Gates. Sämtliche Evidenz entsteht vor Candidate; spätere Änderungen machen die betroffene Bindung stale und erfordern neue Evidenz.

| Pilotticket / MG- oder EXT-ID / Zuordnung zu MG-01 oder MG-02 | Letzter gültiger Checkpoint / prospektive Tree-OID / Diff-Hash | Typisierte Evidenz: Quelle, Zeitpunkt, Digest falls vorhanden | Person / Step-up / Panelbeleg vor Candidate |
|---|---|---|---|
| | | | |

| Nachgelagerte Prüfung | Candidate / Ergebnis / Beleg |
|---|---|
| Candidate erst nach allen gültig geschlossenen pilotticketspezifischen Gates | |
| Tatsächlicher Copilot-Securityturn, Session und gespeichertes Ergebnis | |
| `security_gate`: Freigabe oder benannte Blockade von Commit/Push | |
| Eventueller Human Override: Person, Step-up, Begründung, exakt gebundener Candidate | |

Ein Override ist kein Securityreview-Nachweis und schließt keine Pilotvoraussetzung. Securityergebnisse werden niemals als vorgezogene Evidenz eines MG-/EXT-Gates verwendet.

### A11. Mobile Einsicht und Einmaleffekt

| Prüfung auf realem Smartphone | Run / Request oder Gate / Version / Zeitpunkt | Beobachtung und Beleg |
|---|---|---|
| Gebundener Zugang und korrekte WebAuthn-Origin; Step-up verfügbar | | |
| Human Request: zugestellte E-Mail, berechtigte Antwort und tatsächliche Wirkung | | |
| Gate-Evidenz mit frischem Step-up eingetragen | | |
| Reload nach Human-Antwort, unveränderter Einmaleffekt | | |
| Polling nach Human-Antwort, unveränderter Einmaleffekt | | |
| Reload/Polling nach Gate-Evidenz, keine zweite Wirkung | | |
| Kerninformationen ohne horizontales Scrollen | | |

Tatsächliche Zahl der Human Requests je Lauf, einschließlich `verification_independence`, und Budgetvergleich:

### A12. Cleanup und isolierte Restoreprobe

| Pilot-Run-ID / Objekt | Erwartetes reguläres Cleanup | Tatsächlicher Pfad / Abwesenheitsnachweis / Zeitpunkt |
|---|---|---|
| Run-Worktrees | Entfernt | |
| Review-Checkpoints | Entfernt | |
| Execution-Homes | Entfernt | |
| Exportkopien | Entfernt | |
| Andere Läufe und aufbewahrte Auditdaten | Unverändert / vorhanden | |

Die Restoreprobe verwendet das Backup aus A3 auf einer **getrennten Wiederherstellungsinstanz mit eigenen Volumes**; das README-Rezept setzt dafür einen getrennten Host voraus. **Offene Vertragsentscheidung:** Task 6 und AC-13/TC-06 nennen nur `init`/`app`, der Restore verlangt Workeridentität, und nur Task 6 nennt Login. Die Anfrage im README muss Rollen und Prüfumfang menschlich binden. Bis dahin sind dessen Transfer-/Restorekommandos und die folgenden Loginzeilen Vorschläge, keine zusätzliche Pilotvoraussetzung und kein Nachweis. Keine Worker-/Scheduler-/Agent-/Checker-Daemons, kein Git-Zugriff, keine Providerlogins. Projektregistrierung, Approvals, Queue und private Deploy-Keys aus dem Backup dürfen keine zweite Instanz an der Pilotremote aktivieren. Die Pilotinstanz und ihre Belege bleiben unverändert.

Falls der Mensch einen vollständigen Browser-/Loginnachweis zum Vertragsumfang erklärt, benötigt dieser einen gebundenen Zugang und eine sicher begrenzte Mailzustellung: `app` veröffentlicht keinen Hostport, und ein strict-Login benötigt die durch den Worker zugestellte Bestätigungsmail. Ein allgemeiner Queueworker oder Abschalten der Barriere ist keine Lösung. Die offene Vertragsentscheidung aus A2 darf bis dahin weder als gelöst noch als zusätzliche Pilotsperre behandelt werden.

| Bindung / Prüfung | Tatsächlicher Wert / Beobachtung / Beleg |
|---|---|
| Separate Host-/Compose-Projektkennung, eigene Volumes und gesicherte Zielzuordnung | |
| Exakt gleicher Commit, Image-Digest, Software-/Schemastand und private Backupquelle aus A3 | |
| Redigierte Pilotbelege vor Restore außerhalb des Rücksetzbereichs gesichert | |
| Nur Init zur Provisionierung; getrennt geschütztes Schlüsselpaket ohne Geheimniswerte im Protokoll | |
| Einmaliger Restoreaufruf gemäß README, Exitcode 0 und vollständiger Sweep ohne Aufschub/Fehler | |
| Unmittelbar danach `sessions` und `login_confirmations` leer, alte Session widerrufen | |
| App-Login über gebundene Origin, ohne weitere Rollenstarts | |
| Abgelaufene Daten bleiben unlesbar; Tombstones erhalten, gültige Artefakte lesbar | |
| Zweiter Sweep als `schedule:test --name=ai6-run-retention` in App, Vorher-/Nachher-Vergleich unverändert | |
| Keine Worker-/Scheduler-/Agent-/Checker-Daemons, kein Git-/Providerzugriff | |
| Pilotinstanz, Refbindungen und Belege unverändert | |

Die Exitmeldung des zweiten Sweep allein belegt keine Idempotenz. Zwischenzeitlich neu abgelaufene Daten separat zuordnen. Die eingeschränkte Pilotprobe ersetzt nicht AI6-049/MG-01 mit dessen eigenem Git-/Rotationsnachweis.

### A13. Grenzen, Nachvollzug und menschliche Entscheidung MG-03

| Beobachtete Grenze / fehlende Phase / Abweichung | Auswirkung / Beleg | Folge-Ticket oder Folgeauftrag / Verantwortlicher |
|---|---|---|
| | | |

| Entscheidung | Vom Prüfer auszufüllen |
|---|---|
| Zweiter Entwickler: Name, nachvollzogene Anleitung/Belege, Datum und offene Punkte | |
| Pflichtvoraussetzungen vollständig geschlossen; optionale Slots begründet nicht anwendbar; Restore-Vertragsentscheidung separat dokumentiert | |
| Budgetverbrauch, Zahl der Human Requests und tatsächlicher Stopppunkt | |
| Gesamtergebnis Teil A (bestanden / nicht bestanden) | |
| Begründung, Grenzen und Belegreferenzen | |
| Datum und Unterschrift der Prüfperson für MG-03 | |

MG-03 bestätigt ausschließlich den V1-Pilot. Es autorisiert allein keinen Legacy-Cutoff; spätere Änderungen an getesteten Implementierungsbytes erfordern neue gebundene Prüfung und Signatur.

## Teil B — Cutoff und Revalidierung, MG-04

Ergebnisfrei offen bis zur gesonderten Freigabe. Vor Erfüllung des Startprädikats werden weder Cutoff-Code noch geänderte Legacy-Testerwartungen integriert. Der V1-Pilot und die Promptzuordnung sind keine Legacy-Migration.

### B1. Eigenständige Startvoraussetzungen

| Voraussetzung | Zuständige Entscheidung | Commit / signierte Evidenz / Gültigkeit |
|---|---|---|
| Tatsächlich gelieferte Migration aus AI6-037, Klassen, Kommando und Fixtures am realen Stand neu geprüft | | |
| Signiertes AI6-037/MG-01 an einem benannten echten Altbestand | | |
| Signiertes AI6-038/MG-03 aus Teil A | | |
| Menschliche Phase-B-Freigabe und Rebase von Cutoff-/Revalidierungspfaden gegen gelieferten Code | | |

### B2. Korpus und Release-Lineage

| Feld | Vom Prüfer auszufüllen |
|---|---|
| Legacy-Projekt, Remote, Control-Branch und gebundener Stand vor/nach Migration | |
| Echter Legacy-Korpus, vollständige Pfadliste, Anzahl und Profilbindung | |
| Migrationslieferung, genaues geliefertes Kommando und signiertes AI6-037/MG-01 | |
| Cutoff-Commit und Beleg seiner Release-Lineage zum Migrationsnachweis | |
| Ausgelieferter Cutoff-Stand, Image-Digest, Host und Prüfdatum | |
| AI6-Pilotkopie: Projekt, Control-Branch/-Commit, vollständige Ticketkandidatenliste | |
| Config-Hash und freigegebenes Validierungsprofil je Projekt | |

### B3. Cutoff- und Revalidierungsnachweise

Nach Cutoff für **jeden** Ticketpfad beider gebundener Control-Stände `ticket_refresh` im Panel auslösen und den Abschluss abwarten. Keine zweite Reindexroute; `ai6:tickets:reproject-unparsed --project-config` überspringt aktuell gebundene Projektionen und genügt nicht. Vollständige Kandidatenlisten mit Projektionen vergleichen, auch verbliebene Legacydateien sichtbar ausweisen.

| Projekt / Control-Commit / Ticketpfad | `ticket_refresh`-Operation und Abschluss | Profil / Config-Hash | Zustand / Contract-Hash vorher und nachher / Befund | Beleg |
|---|---|---|---|---|
| Migriertes generisches Ticket | | | | |
| Migriertes Detailticket | | | | |
| Zuvor gültiges V1-Ticket der AI6-Pilotkopie | | | | |
| Verbliebene Legacydatei, falls vorhanden | | | | |

| Prüfung | Tatsächliches Ergebnis / Beleg |
|---|---|
| TC-07: Projektion ruft `LegacyTicketReader::read()` nicht mehr auf; `canRead()` erkennt zur benannten Ablehnung | |
| Legacybefund `invalid`, `legacy_format_unsupported`, kein Contract-Hash, Hinweis auf geliefertes Migrationskommando | |
| `LegacyTicketReader::read()` bleibt unveränderter Migrationseingang, kein stiller Fallback | |
| TC-08: Migrierte Tickets `valid` mit Contract-Hash unter `generic_v1` beziehungsweise `ai6_detail_v1` | |
| Bereits gültige V1-Tickets bleiben `valid` mit unverändertem Contract-Hash | |
| Legacy-Projekt: Kandidatenliste vollständig mit Projektionen abgeglichen, Anzahlen und Abweichungen | |
| AI6-Pilotkopie: Kandidatenliste vollständig mit Projektionen abgeglichen, Anzahlen und Abweichungen | |

### B4. Menschliche Entscheidung MG-04

| Entscheidung | Vom Prüfer auszufüllen |
|---|---|
| Gesamtergebnis Teil B (bestanden / nicht bestanden) | |
| Offene Abweichungen, Grenzen, Folgeaufträge und Belegreferenzen | |
| Zweiter Entwickler: Nachvollzug von Cutoff und Revalidierung | |
| Datum und Unterschrift der Prüfperson für MG-04 | |

Ohne Migration, echten Altbestand und beide vorausgesetzten Signaturen bleibt Teil B offen. Das verhindert nicht die gesonderte Abnahme von Teil A, lässt aber die vollständige Ticketabnahme offen.
