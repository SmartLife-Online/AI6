# Tickets — Nichtnormative Backlog-Ansicht

> **Diese Datei ist weder Ticket noch Vertragsdokument.** Die verbindlichen Regeln für Ticketerkennung und Autorität stehen ausschließlich im [Implementierungsplan](../docs/AI6_IMPLEMENTATION_PLAN.md) (`TKT-001`, `TKT-005`, `TKT-010`).

Diese Übersicht fasst den Erzeugungsstand der 44 geplanten AI6-Tickets zusammen. Sie dient der Navigation und darf nicht als Eingabe für Status-, Freigabe-, Scope- oder Ausführungsentscheidungen verwendet werden.

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
| **Detailticket** | Reguläre Ticketdatei vorhanden | 12 |
| **Blueprint** | Noch keine reguläre Ticketdatei vorhanden | 32 |

`Dateistand` beschreibt ausschließlich, ob eine reguläre Ticketdatei existiert. Er sagt nichts über Gültigkeit, Freigabe oder Umsetzungsbereitschaft aus. Der Bearbeitungsstand eines Tickets steht in `status` der Ticketdatei. Die hier gezeigten `depends_on`-Werte liefern ausschließlich die Abhängigkeitsbedingung — nicht die Startbarkeit: Nach `RUN-008` wird die Eligibility unmittelbar vor jedem Claim und Start vollständig neu bewertet und hängt zusätzlich von gültiger Approval, Git- und Snapshotbindung, Policy, Capabilities, Projektsperre, Runabschluss und Queuezustand ab. Aus einer erfüllten `depends_on`-Liste folgt daher kein startbares Ticket.

Die Werte in den Spalten `Titel`, `Risiko` und `depends_on` sind ausschließlich abgeleitete Anzeigedaten: bei Blueprints aus Plan §15, bei Detailtickets zusätzlich aus dem Frontmatter der Ticketdatei. Aus dieser Ansicht folgt keine Wirkung. `—` zeigt lediglich an, dass in der Quelle keine Vorgänger aufgeführt sind. Weichen Plan und Ticketdatei voneinander ab, ist das ein Befund für die Quellen und wird nicht in dieser Ansicht geglättet.

### 2.1 Ableitungsbasis der vorhandenen Detailtickets

| Ableitungsbasis | Tickets |
|---|---|
| Gegen den realen Repositoryzustand abgeleitet oder rebased | AI6-001, AI6-002 |
| Gegen den erwarteten Zustand nach den Vorgängertickets abgeleitet | AI6-003, AI6-004, AI6-005A, AI6-005B, AI6-006A, AI6-006B, AI6-006C, AI6-006D, AI6-006E, AI6-006F |

`AI6-001` ist im Repository integriert. `AI6-002` wurde am 1. August 2026 gegen diesen realen Vorgängerstand und den vorhandenen Arbeitsbaum rebased; sein Implementierungsstand im Arbeitsbaum ist damit noch weder Integration noch Statusfreigabe. Die zehn übrigen vorabgeleiteten Detailtickets benennen weiterhin Pfade, Klassen, Kommandos und Tests, die erst mit ihren Vorgängern entstehen; einzelne davon tragen bereits den Marker `— existing`, obwohl sie heute fehlen. Plan §13.6 nennt diesen Zustand `ahead-derived` und legt fest, welche Prüfungen bis zum Rebase aufgeschoben werden dürfen: die Existenzprüfung eines `existing`-Pfades, die Verifikation einer ausschließlich mit Plan-eigenen Namen benannten Naht und die Ableitung konkreter Klassen-, Kommando- und Testnamen aus realem Code. Alles andere gilt sofort.

Der Umgang damit ist normativ entschieden und keine offene Frage dieser Ansicht: Ein noch vorabgeleitetes Ticket bleibt auf `status: todo` und darf vor dem durchgeführten Rebase weder freigegeben noch beansprucht werden (Plan §13.6, Template §9.1, `AGENTS.md` §8.1). Ein zusätzliches Blockieren über `status: blocked` ist dafür nicht vorgesehen — dieser Wert bezeichnet nach Plan §5.2 eine dauerhafte fachliche Blockade, nicht eine ausstehende Vorabableitungsprüfung. Das Rebase-Gate leitet unmittelbar vor `status: ready` `files`, die Scope-Marker sowie alle genannten Pfade, Klassen, Kommandos und Tests gegen den dann realen Repositoryzustand neu ab; ein `— existing`-Marker bezeichnet bis dahin die Runbasis nach dem Landen der Abhängigkeiten und wird nicht auf `— new` umgeschrieben. Ob ein Ticket dieses Gate durch Rebase oder durch vollständige Neuerzeugung durchläuft, entscheidet der Mensch je Ticket; beide Wege müssen die aufgeschobenen Prüfungen vollständig nachholen. Ein abgeschlossener Rebase bewirkt selbst keine Statusänderung. Statusänderungen gehören AI6 und weder dieser Ansicht noch einem Agenten (Plan §5.2, `AGENTS.md` §10).

## 3. Backlog

Stand der abgeleiteten Ansicht: 1. August 2026, abgeleitet aus Planrevision V1.6.21 und dem vorhandenen Dateibestand.

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
| AI6-007 | Ticketparser V1, Legacy-Leser und Validator | medium | Blueprint | AI6-006F |
| AI6-008 | Responsive Ticketübersicht und Ticketdetail | medium | Blueprint | AI6-004, AI6-007 |
| AI6-009 | Ticketbearbeitung, Statusübergänge und Git-Persistenz | high | Blueprint | AI6-006F, AI6-007, AI6-008 |
| AI6-010 | Projektkonfiguration und freigegebene Config-Snapshots | high | Blueprint | AI6-003, AI6-006F, AI6-007, AI6-009 |

### M2 — Freigabe und Run-Fundament

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-011 | Agentenprofil-, Capability- und Promptkatalog | medium | Blueprint | AI6-003, AI6-004 |
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
| AI6-035 | Provider-Onboarding, Credential-Setup und Capability-Doctor | high | Blueprint | AI6-003, AI6-005A, AI6-033, AI6-034 |

### M7 — Betrieb, Migration und Pilot

| ID | Titel | Risiko | Dateistand | depends_on |
|---|---|---|---|---|
| AI6-036 | Installation, Backup/Restore und Security-Release-Gate | high | Blueprint | AI6-002, AI6-003, AI6-005A, AI6-005B, AI6-015, AI6-035, AI6-029, AI6-031, AI6-032 |
| AI6-037 | Migration des bisherigen Ticket-Prompt-Tools | medium | Blueprint | AI6-007, AI6-008, AI6-009, AI6-016, AI6-032 |
| AI6-038 | Realer M169-Pilot und MVP-Abnahme | high | Blueprint | AI6-032, AI6-035, AI6-036, AI6-037 |

## 4. Getrennter Index-Refresh

Diese Ansicht wird in einem getrennten Schritt aus dem aktuellen [Plan](../docs/AI6_IMPLEMENTATION_PLAN.md) und den vorhandenen Detailticketdateien aktualisiert. Der Refresh bestimmt Anzahl, Verlinkung, Anzeigedaten und die Ableitungsbasis aus §2.1 neu. Er verändert keine Quelldatei und trifft keine Status-, Freigabe- oder Ausführungsentscheidung.
