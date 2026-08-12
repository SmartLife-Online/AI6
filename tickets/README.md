# Tickets — Nichtnormative Backlog-Ansicht

> **Diese Datei ist weder Ticket noch Vertragsdokument.** Die verbindlichen Regeln für Ticketerkennung und Autorität stehen ausschließlich im [Implementierungsplan](../docs/AI6_IMPLEMENTATION_PLAN.md) (`TKT-001`, `TKT-005`, `TKT-010`).

Diese Übersicht fasst den Erzeugungsstand der 50 geplanten AI6-Tickets zusammen. Sie dient der Navigation und darf nicht als Eingabe für Status-, Freigabe-, Scope- oder Ausführungsentscheidungen verwendet werden.

## 1. Quellen und Grenzen

| Quelle | Rolle |
|---|---|
| [Implementierungsplan](../docs/AI6_IMPLEMENTATION_PLAN.md) | Normative Anforderungen, Meilensteine und Ticket-Blueprints |
| [Ticket-Template](../docs/AI6_TICKET_TEMPLATE_V1.md) | Format sowie Erzeugungs- und Umsetzungsvertrag für Detailtickets |
| [AGENTS.md](../AGENTS.md) | Verbindliche Arbeits-, Architektur- und Sicherheitsregeln |
| Reguläre Datei `tickets/<ID>.md` | Erzeugtes Detailticket |

Diese README formuliert keine dieser Regeln neu. Bei Änderungen an Plan, Template oder Detailtickets kann sie vorübergehend veraltet sein.

## 2. Erzeugungsstand

| Dateistand | Bedeutung | Anzahl |
|---|---|---|
| **Detailticket** | Reguläre Ticketdatei vorhanden | 17 |
| **Blueprint** | Noch keine reguläre Ticketdatei vorhanden | 33 |

`Dateistand` beschreibt ausschließlich, ob eine reguläre Ticketdatei existiert. Er sagt nichts über Gültigkeit, Freigabe oder Umsetzungsbereitschaft aus. Der Bearbeitungsstand eines Tickets steht in `status` der Ticketdatei. Die hier gezeigten `depends_on`-Werte liefern ausschließlich die Abhängigkeitsbedingung — nicht die Startbarkeit: Nach `RUN-008` wird die Eligibility unmittelbar vor jedem Claim und Start vollständig neu bewertet und hängt zusätzlich von gültiger Approval, Git- und Snapshotbindung, Policy, Capabilities, Projektsperre, Runabschluss und Queuezustand ab. Aus einer erfüllten `depends_on`-Liste folgt daher kein startbares Ticket.

Die Werte in den Spalten `Titel`, `Risiko` und `depends_on` sind ausschließlich abgeleitete Anzeigedaten: bei Blueprints aus Plan §15, bei Detailtickets zusätzlich aus dem Frontmatter der Ticketdatei. Aus dieser Ansicht folgt keine Wirkung. `—` zeigt lediglich an, dass in der Quelle keine Vorgänger aufgeführt sind. Weichen Plan und Ticketdatei voneinander ab, ist das ein Befund für die Quellen und wird nicht in dieser Ansicht geglättet.

### 2.1 Ableitungsbasis der vorhandenen Detailtickets

| Ableitungsbasis | Tickets |
|---|---|
| Gegen den realen Repositoryzustand abgeleitet oder rebased | AI6-001, AI6-002, AI6-003, AI6-004, AI6-005A, AI6-005B, AI6-006A, AI6-006B, AI6-006C, AI6-006D, AI6-006E, AI6-007, AI6-008, AI6-009 |
| Gegen den erwarteten Zustand nach den Vorgängertickets abgeleitet | AI6-006F, AI6-010, AI6-044 |

`AI6-001` bis `AI6-006F` sind im Repository integriert. `AI6-002` wurde am 1. August 2026 gegen den realen `AI6-001`-Stand rebased; `AI6-003` wurde am 2. August 2026 nach der menschlichen Abnahme beider Abhängigkeiten gegen den integrierten `AI6-002`-Stand `29d67fa` rebased. `AI6-004` wurde am 3. August 2026 mit ausdrücklicher menschlicher Freigabe gegen den integrierten Stand `c8b99b2` neu abgeleitet. `AI6-005A` wurde am 3. August 2026 mit ausdrücklicher menschlicher Freigabe gegen den integrierten `main`-Stand `38b3c1d` neu abgeleitet; dabei wurde insbesondere das fehlende `config/mail.php` als neuer Pfad berichtigt. `AI6-006A` wurde am 5. August 2026 mit ausdrücklicher menschlicher Freigabe gegen den integrierten M0-Stand `b29d802` rebased; dabei wurden die reale Symfony-Process-Version, die Redaction-Aufrufnaht, Provider, Konfiguration und Containerbaseline verifiziert. `AI6-006B` wurde am 5. August 2026 mit ausdrücklicher menschlicher Freigabe gegen den integrierten Stand `d6e329f` rebased; dabei wurden die realen Projekt-, Policy-, Controller-, Git-Remote-, Pin- und globalen Inventurverträge verifiziert und die notwendigen eng begrenzten Git- und Unit-Testpfade in den Scope aufgenommen. `AI6-006C` wurde am 6. August 2026 auf ausdrücklichen menschlichen Auftrag gegen den integrierten Stand `e7a9059` rebased; dabei wurden Step-up-, Prozess-, Lock-, Git-, Projekt-, Provider-, Scheduler-, Compose- und Init-Nähte verifiziert und die notwendigen Querschnittstests für Inventur, Compose-Allowlist, Init-Skript, reale Compose-Harness und Runtime-Dokumentation in den Scope aufgenommen. Die später menschlich freigegebene Reviewkorrektur nahm zusätzlich den optionalen Lease-Heartbeat-Callback der bestehenden Process-Naht samt vorhandenem Unit-Test sowie `deploy/Caddyfile` für die an ein separates Caddy-/App-Proxynetz gekoppelte Loopback-Normalisierung in den Scope auf. Diese Abgleiche haben den jeweiligen Status nicht verändert; Statusänderungen blieben getrennte menschliche Entscheidungen.

`AI6-006D` wurde am 9. August 2026 auf ausdrückliche menschliche Freigabe gegen den integrierten Stand `1631a9a` rebased. Verifiziert wurden der reale Typ-, Phasen-, Executor-, Lease-, Managed-Path-, gehärtete Git-, Projektbindungs-, Config-, Web- und Testvertrag aus `AI6-006C`. Weil die vorhandenen SQLite-Trigger ausschließlich die Deploy-Key-Provisionierung erlauben und dem Operationsdatensatz ein persistierbarer Ziel-OID für die Clone-/Fetch-Saga fehlt, wurden eine neue Folgemigration sowie die bestehenden Compose-, Environment-, Inventur-, Dokumentations- und CSRF-Vertragsnähte in den Ausgangsscope aufgenommen. Die vorhandenen AC-/TC-/MG-IDs blieben stabil; ausschließlich die neuen IDs `AC-18`, `AC-19`, `TC-16` und `TC-17` wurden angehängt. Der Rebase hat den Ticketstatus nicht verändert.

`AI6-006E` wurde am 10. August 2026 auf ausdrücklichen menschlichen Auftrag gegen den integrierten Stand `a200be1` rebased. Verifiziert wurden die reale Step-up-, Operations-, Claim-, Clone-/Fetch-, Projektbindungs-, Ref-Allowlist-, Web-, Inventur- und CSRF-Testnaht. Weil der mit `AI6-006D` veröffentlichte SQLite-Vertrag den zusätzlichen Operationstyp und seine Probephase geschlossen abweist und kein geeigneter Auditspeicher existiert, wurden eine neue Folgemigration sowie die vorhandenen Inventur- und CSRF-Vertragstests in den Ausgangsscope aufgenommen. Der Rebase hat weder vorhandene AC-/TC-/MG-IDs noch den Ticketstatus verändert.

`AI6-006F` wurde nach dem Landen seiner Vorgänger implementiert, am 11. August 2026 integriert und menschlich abgenommen (`61db7e6`, einschließlich des Statuswechsels auf `done`); seine `— existing`-Marker bezeichnen seither den realen Repositoryzustand. Zwischen dieser Abnahme und der Ticketerzeugung vom 11. August 2026 war kein vorhandenes Detailticket vorabgeleitet. Die Regeln für menschlich angeordnete Vorabableitungen — aufgeschobene Prüfungen, Rebase-Gate vor `status: ready`, kein Blockieren über `status: blocked` — stehen unverändert in Plan §13.6, Template §9.1 und `AGENTS.md` §8.1. Statusänderungen gehören AI6 und weder dieser Ansicht noch einem Agenten (Plan §5.2, `AGENTS.md` §10).

`AI6-007` wurde am 11. August 2026 gegen den realen integrierten Stand nach `AI6-006F` abgeleitet; die genannten Refresher-, Guard-, Enum-, Policy-, Pfad-, Provider- und Konfigurationsnähte wurden zum Erzeugungszeitpunkt im Repository verifiziert. `AI6-008`, `AI6-009` und `AI6-010` wurden am selben Tag auf ausdrückliche menschliche Anordnung vor der Umsetzung ihrer noch offenen Abhängigkeiten vorabgeleitet und waren damit **ahead-derived** im Sinne von Plan §13.6: `AI6-008` fehlte `AI6-007`, `AI6-009` fehlen `AI6-007` und `AI6-008`, `AI6-010` fehlen `AI6-007` und `AI6-009`. Ihre `## Context`-Abschnitte benennen die zum Ableitungszeitpunkt fehlenden Pfade samt erzeugendem Ticket, ihre `## Notes` tragen die Rebase-Verpflichtung, und ihre `existing`-Marker bezeichnen die Runbasis nach dem Landen der `depends_on`-Tickets. Diese Ableitungen haben keinen Status verändert; Statusänderungen bleiben getrennte menschliche Entscheidungen.

`AI6-008` wurde am 12. August 2026 auf ausdrückliche menschliche Freigabe gegen den integrierten Stand `16e5e53` (nach der menschlichen Abnahme von `AI6-007`) rebased. Verifiziert wurden die realen Parser-, Projektions-, Guard-, Policy- (`TicketReadModelUsePolicy::allowsEditor()`/`::allowsApproval()`), Freshness-, Refresh-, Markdown-, CSP- und Testnähte. Weil die Runbasis den Composer-Vertrag zusätzlich über gepinnte Hashes in `tests/Unit/Shared/Runtime/RuntimeScriptsTest.php` und über den Provenienzvergleich in `tests/Unit/Git/GitRuntimeContractTest.php` bindet, wurden diese beiden bestehenden Vertragsdateien in den Ausgangsscope aufgenommen; auf ausdrückliche menschliche Anordnung im Review kamen für die Härtung der `last_error`-Anzeige `tests/Feature/Git/ManagedCloneControlOperationTest.php` sowie — als angeordnete Ausnahme vom `Do Not Change`-Eintrag `app/AI6/Git/` — `ControlOperationExecutor.php` und die neue `ControlOperationPublicFailureText.php` hinzu. Die übrigen `files` samt Scope-Markern blieben unverändert gültig, und vorhandene AC-/TC-/MG-IDs blieben stabil. Der Rebase hat den Ticketstatus nicht verändert.

`AI6-009` wurde am 12. August 2026 auf ausdrückliche menschliche Freigabe gegen den integrierten Stand `d1c8af4` nach der menschlichen Abnahme von `AI6-007` und `AI6-008` rebased. Alle 18 Scope-Pfade existieren real. Verifiziert wurden die zentrale Parse-/Validierungs-/Hashnaht (`TicketV1Parser`, `TicketReadModelProjector`, `TicketContractHasher`), `TicketReadModelUsePolicy::allowsEditor()`/`::allowsApproval()`, die Livewire-Detailansicht samt routenloser Edit-Aktion, der Control-Operation-Executor, die einzige initiale Claim-Naht `ProjectOperationLease::claimInitialControlOperation()`, die gehärtete Git-Naht, Projektbindung, Autorisierungsmatrix und deterministischen SQLite-Folgemigrationen. Eine Commit-/Push-Primitive existiert im Produktionscode weiterhin nicht und entsteht erst mit diesem Ticket. Scope, Marker und konkrete Nähte sind damit gegen den realen Stand neu abgeleitet; vorhandene AC-/TC-IDs und der Ticketstatus blieben unverändert. `AI6-010` bleibt ahead-derived: Vor `status: ready` sind seine aufgeschobenen Prüfungen vollständig nachzuholen; bis dahin bleibt es auf `status: todo` und darf weder freigegeben noch beansprucht werden.

`AI6-044` wurde am 12. August 2026 auf ausdrückliche menschliche Anordnung vor der Umsetzung von `AI6-011` aus Planrevision V1.7.1 abgeleitet (`ahead-derived`). Der mit Plannamen bezeichnete zentrale Promptkatalog und sein kanonischer Renderer entstehen erst mit `AI6-011`; konkrete Katalog-, Renderer-, Profil-, Konfigurations- und Testpfade werden deshalb vor `status: ready` gegen dessen dann realen Stand neu abgeleitet. Das Detailticket bleibt bis zu diesem Rebase auf `status: todo` und darf weder freigegeben noch beansprucht werden. Seine Beispielprompts und das Legacy-Tool sind Ableitungsevidenz, keine zweite Laufzeitquelle.

## 3. Backlog

Stand der abgeleiteten Ansicht: 12. August 2026, abgeleitet aus Planrevision V1.7.1 und dem vorhandenen Dateibestand.

### M0 — Fundament und sichere Laufzeit

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| [AI6-001](./AI6-001.md) | Laravel-Grundgerüst und Qualitätsbaseline | low | Detailticket | — |
| [AI6-002](./AI6-002.md) | Docker-Compose-Laufzeit, SQLite-Queue und Scheduler | medium | Detailticket | AI6-001 |
| [AI6-003](./AI6-003.md) | Typed Config und zentrale SecurityPolicy | high | Detailticket | AI6-001, AI6-002 |
| [AI6-004](./AI6-004.md) | Benutzer, Projektrollen und Basis-Authentifizierung | high | Detailticket | AI6-002, AI6-003 |
| [AI6-005A](./AI6-005A.md) | Starke Primärauthentifizierung und E-Mail-Barriere | high | Detailticket | AI6-003, AI6-004 |
| [AI6-005B](./AI6-005B.md) | Session- und HTTP-Härtung, CSP und sichere Markdown-Basispolitik | high | Detailticket | AI6-003, AI6-004, AI6-005A |

### M1 — Projekte und Git-native Tickets

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| [AI6-006A](./AI6-006A.md) | ControlProcessRunner und gehärtete Git-Ausführung | high | Detailticket | AI6-002, AI6-003 |
| [AI6-006B](./AI6-006B.md) | Projektregistrierung und vertrauenswürdige Projektmetadaten | high | Detailticket | AI6-004, AI6-006A |
| [AI6-006C](./AI6-006C.md) | Control-Operation-Kern, Operationssperre und Deploy-Key-Provisionierung | high | Detailticket | AI6-005A, AI6-006A, AI6-006B |
| [AI6-006D](./AI6-006D.md) | Managed-Clone, Clone und Fetch | high | Detailticket | AI6-006C |
| [AI6-006E](./AI6-006E.md) | Control-Branch-Wechsel und Invalidierungsgeneration | high | Detailticket | AI6-005A, AI6-006D |
| [AI6-006F](./AI6-006F.md) | Blobgebundene Read Models und Einzelpfad-Refresh | high | Detailticket | AI6-006E |
| [AI6-007](./AI6-007.md) | Ticketparser V1, Legacy-Leser und Validator | medium | Detailticket | AI6-006F |
| [AI6-008](./AI6-008.md) | Responsive Ticketübersicht und Ticketdetail | medium | Detailticket | AI6-004, AI6-007 |
| [AI6-009](./AI6-009.md) | Ticketbearbeitung, Statusübergänge und Git-Persistenz | high | Detailticket | AI6-006F, AI6-007, AI6-008 |
| [AI6-010](./AI6-010.md) | Projektkonfiguration und freigegebene Config-Snapshots | high | Detailticket | AI6-003, AI6-006F, AI6-007, AI6-009 |

### M2 — Freigabe und Run-Fundament

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-011 | Agentenprofil-, Capability- und Promptkatalog | medium | Blueprint | AI6-003, AI6-004 |
| [AI6-044](./AI6-044.md) | Manuelle Prompt-Hilfe für Codex und Claude | medium | Detailticket | AI6-008, AI6-011 |
| AI6-012 | Ticketprüfung, Approval-Snapshot und Multi-Reviewer-Auswahl | high | Blueprint | AI6-008, AI6-009, AI6-010, AI6-011 |
| AI6-013 | Run-State-Machine, Persistenz und Projektsperre | high | Blueprint | AI6-004, AI6-012 |
| AI6-014 | Run-Branch, Worktree, Checkpoint und Diff-Service | high | Blueprint | AI6-006D, AI6-013 |
| AI6-015 | ProcessRunner, ExecutionMailbox und Prozessgrenzen | high | Blueprint | AI6-002, AI6-003, AI6-006A, AI6-013, AI6-014 |
| AI6-016 | JSON-Verträge und FakeAgent | medium | Blueprint | AI6-011, AI6-015 |
| AI6-017 | Basisschritt-Orchestrator und Run-Timeline | high | Blueprint | AI6-013, AI6-014, AI6-015, AI6-016 |

### M3 — Human-in-the-loop und Implementierung

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-018 | Human Requests, E-Mail, Attention-Inbox und Resume | high | Blueprint | AI6-005A, AI6-017 |
| AI6-019 | Implementierungsagent-Turn und sicherer Diff-Import | high | Blueprint | AI6-014, AI6-016, AI6-017, AI6-018 |
| AI6-020 | Adaptive Scope- und Vertragsänderungen | high | Blueprint | AI6-009, AI6-018, AI6-019 |
| AI6-021 | Checkprofile und credentialfreier Checker | high | Blueprint | AI6-010, AI6-015, AI6-017 |
| AI6-022 | Pre-Review-Verifikation und Checkpoint-Bereitschaft | medium | Blueprint | AI6-019, AI6-020, AI6-021 |

### M4 — Multi-Review und Fixschleife

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-023 | Read-only Review-Workspaces und Multi-Reviewer-Ausführung | high | Blueprint | AI6-012, AI6-016, AI6-022 |
| AI6-024 | Findings, AC-Abdeckung und Reviewdarstellung | high | Blueprint | AI6-023 |
| AI6-025 | Fixturn und vollständige Re-Review-Schleife | high | Blueprint | AI6-019, AI6-020, AI6-022, AI6-024 |
| AI6-026 | Reviewlimits, Stall-Erkennung und Interventionsaktionen | high | Blueprint | AI6-018, AI6-025 |
| AI6-039 | Review-only-Runvertrag: Claim und report-only Abschluss-Saga | high | Blueprint | AI6-013, AI6-026 |
| AI6-040 | Review-only-Quellbindung, Ausführung, Bericht und Bedienung | high | Blueprint | AI6-014, AI6-021, AI6-024, AI6-039 |
| AI6-043 | Quellenabhängige advisory Finding-Verifikation | high | Blueprint | AI6-011, AI6-024, AI6-026 |

### M5 — Finalisierung und vollständiger Fake-Workflow

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-027 | Finalchecks, Publish-Kandidat und deterministische Provenienz | high | Blueprint | AI6-021, AI6-025, AI6-026 |
| AI6-028 | Optionales LLM-Sicherheitsgate | high | Blueprint | AI6-003, AI6-016, AI6-018, AI6-024, AI6-027 |
| AI6-029 | Finaler Commit, Ticketstatus, Push, Drift und Cleanup | high | Blueprint | AI6-009, AI6-014, AI6-027, AI6-028 |
| AI6-030 | Projektqueue und abhängigkeitssicherer Auto-Start | medium | Blueprint | AI6-008, AI6-012, AI6-013, AI6-029 |
| AI6-031 | Vollständige Runbeobachtung und mobile Bedienung | medium | Blueprint | AI6-008, AI6-018, AI6-024, AI6-026, AI6-029, AI6-030 |
| AI6-032 | Vollständiger FakeAgent-End-to-End- und Recovery-Test | high | Blueprint | AI6-026, AI6-028, AI6-029, AI6-030, AI6-031 |

### M6 — Echte Provideradapter

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-033 | Codex-CLI-Adapter | high | Blueprint | AI6-011, AI6-015, AI6-016, AI6-032 |
| AI6-034 | Claude-CLI-Adapter | high | Blueprint | AI6-011, AI6-015, AI6-016, AI6-032 |
| AI6-035 | Provider-Onboarding, Credential-Setup und Capability-Doctor | high | Blueprint | AI6-003, AI6-005A, AI6-033, AI6-041, AI6-042 |
| AI6-041 | Grok-CLI-Adapter | high | Blueprint | AI6-011, AI6-015, AI6-016, AI6-032 |
| AI6-042 | GitHub-Copilot-CLI-Adapter | high | Blueprint | AI6-011, AI6-015, AI6-016, AI6-032 |

### M7 — Betrieb, Migration und Pilot

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-036 | Installation, Backup/Restore und Security-Release-Gate | high | Blueprint | AI6-002, AI6-003, AI6-005A, AI6-005B, AI6-015, AI6-035, AI6-029, AI6-031, AI6-032 |
| AI6-037 | Migration des bisherigen Ticket-Prompt-Tools | medium | Blueprint | AI6-007, AI6-008, AI6-009, AI6-016, AI6-032 |
| AI6-038 | Realer M169-Pilot und MVP-Abnahme | high | Blueprint | AI6-032, AI6-035, AI6-036, AI6-037 |

## 4. Getrennter Index-Refresh

Diese Ansicht wird in einem getrennten Schritt aus dem aktuellen [Plan](../docs/AI6_IMPLEMENTATION_PLAN.md) und den vorhandenen Detailticketdateien aktualisiert. Der Refresh bestimmt Anzahl, Verlinkung, Anzeigedaten und die Ableitungsbasis aus §2.1 neu. Er verändert keine Quelldatei und trifft keine Status-, Freigabe- oder Ausführungsentscheidung.
