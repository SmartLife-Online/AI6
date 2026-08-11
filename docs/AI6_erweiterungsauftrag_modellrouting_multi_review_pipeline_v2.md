# Erweiterungsauftrag an Codex: Ticket-zentrierte Implementierungs- und Multi-Review-Pipelines

> **Status:** Planungs- und Integrationsvorgabe  
> **Zielsystem:** bestehendes AI6-Softwareentwicklungs- und Automatisierungstool  
> **Verwendung:** Diese Datei in Codex zusammen mit dem aktuellen Repository und dem bestehenden Gesamt-/Integrationsplan öffnen.  
> **Wichtig:** Dieses Dokument ist keine fertige technische Spezifikation für eine Neuentwicklung. Codex soll die Konzepte prüfen, an die vorhandene Architektur anpassen, in den bestehenden Gesamtplan integrieren und daraus anschließend kleine, umsetzbare Tickets ableiten.

---

## 0. Verhältnis zum bestehenden AI6-Plan

Dieses Dokument ist ein **Ergänzungsauftrag** zum kanonischen
[Implementierungsplan](AI6_IMPLEMENTATION_PLAN.md), kein zweiter Gesamtplan und keine
fertige technische Spezifikation. Der Plan, das Ticket-Template und `AGENTS.md` gewinnen
mit ihren normativen Architektur-, Sicherheits- und Arbeitsregeln bei Widersprüchen.
Beschreibende Bestandsaufnahmen sind dagegen Snapshots und müssen gegen Code, Git-Historie
und das autoritative Ticket-Frontmatter geprüft werden. Dieses Dokument darf insbesondere
keine Ticketstatus-, Approval- oder Runentscheidung vorwegnehmen.

Die im Auftrag genannte frühere Datei `AI6_modellrouting_ticket_automatisierung.md` ist
im aktuellen Repository nicht vorhanden. Ihre vermuteten Inhalte gelten daher weder als
Projektvertrag noch als zusätzliche Quelle. Codex soll nur den aktuellen Plan, den
aktuellen Code und die tatsächlich vorhandenen Tickets verwenden.

Neu zu integrieren sind insbesondere:

- ein eigenständiger **Review-only-Modus**;
- mehrere spezialisierte Review-Profile auf demselben unveränderlichen Checkpoint;
- strukturierte Findings und eine getrennte, auditierbare Disposition;
- begrenzte Fix-/Re-Review-Schleifen;
- risikobasierte Gates innerhalb der bereits bestehenden Human- und Approval-Verträge;
- kompakte, hashgebundene Kontextpakete;
- die drei CLI-Adapter `codex_cli`, `grok_cli` und `github_copilot_cli` für die erste
  Providerstufe.

### 0.1 Bindung an den aktuellen Repositorystand

Beim Erstellen dieses Auftrags gilt:

- Die normative Planbasis ist `AI6_IMPLEMENTATION_PLAN.md` V1.6.21 mit 44 bereits
  veröffentlichten Blueprint-IDs.
- Alle elf vorgesehenen Modulverzeichnisse unter `app/AI6/` existieren. Fachlich
  implementiert sind derzeit vor allem `Auth`, `Projects`, `Git` und `Shared`; die übrigen
  Modulverzeichnisse enthalten nur ihren Scaffold-Platzhalter und begründen noch keinen
  Laufzeitvertrag.
- `AI6-001` bis `AI6-006F` sind auf `main` integriert und ihre Ticketdateien stehen auf
  `status: done`. Damit existieren insbesondere Managed-Clone, Clone/Fetch,
  Control-Branch-Wechsel, Invalidierungsgeneration sowie blobgebundene Read Models und
  Einzelpfad-Refresh bereits im Code. Der nächste noch nicht als Detailticket vorhandene
  Vertrag beginnt mit dem Blueprint `AI6-007`; `AI6-007` bis `AI6-038` dürfen nicht als
  bereits vorhandene Klassen, APIs oder Nähte ausgegeben werden.
- Git ist die Autorität für Ticketdateien, Spezifikation und dauerhaften Ticketstatus.
  SQLite ist die Laufzeitautorität für Runs, Approvals, Findings, Human Requests,
  Artefaktmetadaten, Events und Reviewresultate. Eine `tickets`-Tabelle oder ein in die
  Ticketdatei geschriebenes Ausführungsledger ist damit nicht zulässig.
- Die 44 Blueprint-IDs sowie veröffentlichte `AC-`, `TC-`, `MG-` und `EXT-`-IDs sind
  unveränderlich. Die in Abschnitt 32 genannten Arbeitsnummern `T00` bis `T31` sind
  deshalb keine AI6-Ticketnummern und dürfen nicht als solche erzeugt werden.

Vor jeder konkreten Ableitung muss Codex den dann realen Stand erneut mit `rg --files`,
den Ticket-Frontmattern und den kanonischen Planabschnitten prüfen. `tickets/README.md`
ist nur eine nichtnormative Backlog-Ansicht und kann hinter dem realen Ticketstatus
zurückliegen. Auch der beschreibende Current-State-Abschnitt in `AGENTS.md` liegt zum
Zeitpunkt dieses Auftrags hinter den integrierten Tickets `AI6-006C` bis `AI6-006F`;
dessen normative Regeln bleiben unverändert bindend. Erwartete Pfade aus `AI6-007` und
späteren Blueprints sind bis zu ihrer Implementierung nicht als `— existing` im Code zu
behandeln.

---

# 1. Auftrag an Codex

Analysiere zuerst:

1. den aktuellen Aufbau des Repositories;
2. den bestehenden Gesamt- bzw. Integrationsplan;
3. bereits vorhandene Ticket-, Job-, Workflow-, Provider-, CLI-, Review-, Speicher-, UI- und Logging-Komponenten;
4. bereits implementierte oder geplante Modell-Routing-Mechanismen;
5. bestehende Datenmodelle und Statusmaschinen;
6. vorhandene Test-, Build-, Lint- und Security-Prozesse.

Erweitere anschließend den vorhandenen Gesamtplan um die in diesem Dokument beschriebenen Fähigkeiten.

Dabei gelten folgende Leitlinien:

- Vorhandene Strukturen sollen weiterverwendet werden, wenn sie fachlich geeignet sind.
- Keine parallele zweite Architektur einführen, wenn bestehende Komponenten erweitert werden können.
- Bestehende Begriffe und Namenskonventionen des Projekts bevorzugen.
- Neue Konzepte an die tatsächlichen Projektgrenzen und Abhängigkeiten anpassen.
- Unklare Annahmen als offene Entscheidung kennzeichnen.
- Widersprüche zwischen diesem Dokument und dem aktuellen Projekt sichtbar machen und begründet auflösen.
- Sicherheitsanforderungen dürfen durch die Kostenoptimierung nicht abgeschwächt werden.
- Rückwärtskompatibilität und notwendige Migrationen berücksichtigen.
- Noch keinen unnötig komplexen Universal-Workflow bauen, wenn eine einfachere erste Ausbaustufe genügt.
- Nach der Planintegration kleine, einzeln implementierbare Tickets mit klaren Abhängigkeiten erzeugen.

Für die erste reale Providerstufe gilt zusätzlich ein enger CLI-Vertrag:

- `codex_cli` verwendet die Codex-CLI von ChatGPT/Codex, nicht die direkte OpenAI-API.
- `grok_cli` verwendet die offizielle Grok-Build-CLI, nicht die direkte xAI-API.
- `github_copilot_cli` verwendet die GitHub-Copilot-CLI, nicht einen GitHub-PR-Review-
  Connector und nicht eine frei erfundene Copilot-Review-API.
- Alle drei Adapter laufen nicht-interaktiv und ausschließlich über den gemeinsamen
  `AgentAdapter`-/`ProcessRunner`-Vertrag. TUI, freie Shellstrings, unbeschränkte
  Provider-Homeverzeichnisse und Provider-eigene Autodiscovery sind kein V1-Vertrag.
- Providerantworten sind untrusted. Jeder Adapter muss sie in den bestehenden
  `ai6.agent.v1`- beziehungsweise `ai6.quality-review.v1`-Vertrag überführen; ungültiges
  Ergebnis ist ein sichtbarer Fehler und niemals ein impliziter Erfolg.

---

# 2. Ausgangslage und Zielbild

Das Tool automatisiert bereits oder zukünftig Teile der Softwareentwicklung auf Basis von Tickets. Ein Ticket soll die zentrale Einheit sein, an der sich Planung, Implementierung, Tests, Reviews, Fixes, Entscheidungen und Freigaben orientieren.

Der gewünschte Gesamtprozess lautet vereinfacht:

```text
Starkes Planungsmodell
        |
        v
Gesamtplan und kleine Tickets
        |
        v
Modell-Routing
        |
        +--> Codex CLI für Standard-Implementierung und Fixturns
        +--> Grok CLI für unabhängige Reviews und Verifikation
        +--> GitHub Copilot CLI für unabhängige Reviews
        |
        v
Deterministische Prüfungen
        |
        v
Unabhängige spezialisierte CLI-Reviews
        |
        v
Finding-Normalisierung und Deduplizierung
        |
        v
Kritische Finding-Verifikation
        |
        v
Fix-Schleifen und gezielte Re-Reviews
        |
        v
Risikobasierte Gate- und Pushentscheidung
        |
        +--> automatisch
        +--> menschlich
        |
        v
Optionaler finaler Review mit starkem Modell
        |
        v
Gebundener Abschlussbericht in AI6
```

Neben diesem vollständigen Ablauf soll derselbe Review-Unterbau auch unabhängig verwendet werden können:

```text
Gebundener Managed-Branch / Commit / Diff / Checkpoint
        |
        v
Review-only-Pipeline
        |
        v
Findings, Verifikation, Gateentscheidung und gebundener Bericht
```

Damit kann ein Feature beispielsweise lokal mit einem leistungsfähigen Modell oder durch einen Menschen entwickelt und anschließend auf dem Server automatisiert, günstig und mehrstufig geprüft werden.

---

# 3. Zentrale Optimierungsziele

Das System soll nicht ausschließlich auf den niedrigsten Preis eines einzelnen Modellaufrufs optimieren.

Die maßgeblichen Zielgrößen sind:

```text
Kosten pro erfolgreich abgeschlossenem und zuverlässig geprüftem Ticket
```

sowie:

```text
Qualität pro eingesetztem Token
```

und:

```text
menschlicher Prüfaufwand pro Ticket
```

Die gewünschte Strategie:

- starke Modelle investieren Reasoning hauptsächlich in Architektur, Planqualität, schwierige Eskalationen und gegebenenfalls den finalen Review;
- freigegebene CLI-Profile erledigen Routine-Implementierungen und fokussierte
  Erst-Reviews; ein Kostenvorteil wird gemessen und nicht vorausgesetzt;
- deterministische Werkzeuge übernehmen alles, was nicht sinnvoll von einem LLM beurteilt werden muss;
- Findings werden nicht blind übernommen, sondern kritisch verifiziert;
- menschliche Freigaben werden dort verlangt, wo das Risiko dies rechtfertigt;
- einfache Tickets dürfen weitgehend automatisch durchlaufen;
- alle Entscheidungen bleiben nachvollziehbar und auditierbar.

---

# 4. Architekturprinzipien

## 4.1 Ticket als fachliche Spezifikation, nicht als Laufzeitdatenbank

Das Ticket ist die Git-native Quelle für die fachliche Spezifikation und den dauerhaften
Ticketstatus:

- Ziel, Kontext, Scope, Nichtziele und technische Leitplanken;
- Akzeptanz- und Testkriterien mit stabilen IDs;
- Abhängigkeiten, Risiko, `files`, `spec_refs` und Definition of Done;
- der durch AI6 kontrolliert veröffentlichte Status.

Das Ticket ist **nicht** die Autorität für Run-, Review-, Finding- oder Approvaldaten.
Insbesondere werden `execution_summary`, `review_summary`, `approval_summary` und
Providertranskripte nicht als frei ergänzte YAML-Felder in die Ticketdatei geschrieben.

### Abgeleitete Laufzeitsicht

Die kompakte Arbeits- und Reviewsicht entsteht aus den vorhandenen beziehungsweise im
Plan vorgesehenen SQLite-Entitäten `runs`, `run_agents`, `run_events`, `check_results`,
`review_results`, `findings`, `human_requests`, `interventions`, `run_gates` und
`run_artifacts`. Sie darf im Panel und in einem gebundenen Abschlussartefakt dargestellt
werden, ist aber jederzeit aus Git und den Laufzeitdaten rekonstruierbar.

Die Statusänderung der Ticketdatei bleibt eine Git-/Control-Branch-Saga. Ein Review- oder
Providerprozess darf weder Ticketstatus noch Approval selbst schreiben. Damit bleiben
`TKT-001`, `TKT-005`, `GIT-008`, `RUN-002` und `RUN-004` erhalten.

### Separate Rohartefakte

- vollständige Modellantworten und stderr;
- Test- und Checkausgaben;
- Checkpoint-, Tree- und Diff-Snapshots;
- Provider-Metadaten und optionale Nutzungsdaten;
- ausführliche Reviewer-Diskussionen.

Diese Daten liegen als redigierte, größen- und retentionbegrenzte `run_artifacts` vor.
Sie sind über Hash und Bindung referenzierbar, werden aber nicht bei jedem Modellaufruf
vollständig in den Kontext geladen.

---

## 4.2 Findings sind strukturierte Entitäten

Ein Finding ist kein unstrukturierter Textblock, sondern eine fachliche Entität mit:

- lokaler Identität und unveränderlicher Reviewerquelle;
- Checkpoint-, Tree- und Diffbindung;
- Schweregrad, ursprünglicher Disposition und Kategorie;
- Datei, Zeile, Titel, Evidenz und erwartetem Ergebnis;
- Referenzen auf die betroffenen Akzeptanzkriterien;
- getrennt versionierter, autorisierter effektiver Disposition.

Für AI6 sind Original-Reviewresultat und wirksame Finding-Disposition getrennt. Das
Originalresultat bleibt unverändert in `review_results`; die normalisierte Finding-Sicht
liegt in `findings`, und eine autorisierte Disposition wird checkpointgebunden separat
bewertet. Die gemeinsame Form muss mindestens `source`, `severity`, `disposition`,
`category`, `file`, `line`, `title`, `evidence`, `expected_result` und `criterion_refs`
aus dem AI6-Reviewvertrag abbilden. Ein Reviewer darf sein eigenes Originalresultat nicht
physisch löschen oder durch eine nachträgliche Antwort umschreiben. Auch ein
Verifierresultat ist zunächst nur ein weiteres unveränderliches Reviewresultat: Es darf
`must_fix` nicht eigenmächtig in `not_applicable` oder `accepted_risk` umwandeln. Diese
beiden wirksamen Dispositionen bleiben nach `REV-006` autorisierten menschlichen
Entscheidungen mit der dort verlangten Bindung vorbehalten.

Dadurch können Findings quellengetreu angezeigt, exakt gruppiert, mit zusätzlicher
Verifierevidenz versehen, in Fixturns bearbeitet, auf einem neuen Checkpoint erneut
geprüft oder durch eine autorisierte menschliche Entscheidung als `not_applicable`
beziehungsweise `accepted_risk` disponiert werden.

Eine Deduplizierung darf die unabhängigen Quellen nicht verlieren. Sie ist eine
Darstellung beziehungsweise Verknüpfung; die jeweils originalen Findings und ihre
Checkpointbindungen bleiben erhalten. Event-Historie wird über die vorgesehenen
`run_events`/Interventions geführt, nicht durch Event Sourcing oder eine zweite
allgemeine Lifecycle-Architektur.

---

## 4.3 Implementierer und Reviewer bleiben unabhängig

Das Modell, das eine Änderung implementiert, darf nicht allein über die Qualität seiner eigenen Arbeit entscheiden.

Ebenso darf ein Modell, das ein Finding erzeugt hat, dieses nicht ohne unabhängige Prüfung endgültig löschen.

Ein initiales Review-Modell darf:

- ein Finding vorschlagen;
- zusätzliche Belege nachreichen;
- auf Kritik antworten;
- seine Einschätzung korrigieren.

Die wirksame Blockade entscheidet jedoch kein Modell frei: Der Server berechnet sie nach
`REV-006` aus Originalfinding, aktuellem Checkpoint und gültiger autorisierter
Disposition. Ein unabhängiger Verifier oder weiterer Reviewer liefert zusätzliche
Evidenz; `not_applicable` und `accepted_risk` bleiben menschlich autorisiert.

Für die erste Providerbelegung ist Unabhängigkeit quellenbezogen:

- Ein Copilot-Finding kann durch eine neue Grok-Session verifiziert werden.
- Ein Grok-Finding wird nicht durch Grok selbst freigegeben; es geht an einen getrennten
  Copilot- oder zulässigen Codex-Reviewslot beziehungsweise an einen Menschen.
- Ein Codex-Implementierer ist für denselben Run kein Qualitätsreviewer. Ein Codex-
  Reviewslot ist nur in einem Review-only-Lauf ohne Codex-Implementierung und mit neuer
  Session zulässig.
- Eine abweichende Verifierempfehlung hebt kein blockierendes Originalfinding auf. Sie
  erzeugt Evidenz für HumanLoop oder einen weiteren autorisierten Reviewslot.

---

## 4.4 Deterministische Prüfungen vor LLM-Urteilen

Soweit verfügbar, sollen vor einem allgemeinen LLM-Review ausgeführt werden:

- Unit Tests;
- Integrationstests;
- Feature-Tests;
- Type Checks;
- Linter;
- Formatter-Checks;
- Static Analysis;
- Build;
- Dependency Checks;
- Security Scanner;
- projektspezifische Validierungen.

LLMs sollen diese Werkzeuge ergänzen, nicht ersetzen.

Deterministische Fehler sollen strukturiert in die Pipeline einfließen und müssen nicht nochmals durch mehrere Modelle „entdeckt“ werden.

Für AI6 löst `AI6-021` diese Liste in freigegebene Checkprofile auf. Die derzeitige
Qualitätsbaseline umfasst mindestens `php artisan test`, `vendor/bin/pint --test`,
`vendor/bin/phpstan analyse` und `git diff --check`. Der externe
`LockedInstallTest` läuft zusätzlich mit den ausdrücklich konfigurierten PHP-8.5- und
Composer-Pfaden, wenn Dependency-, Lockfile-, Plattform- oder Installationsverhalten im
Scope liegt. Ein grüner Windows-Lauf ersetzt nicht die Linux-/POSIX-Evidenz für Tests,
die sich außerhalb der vorgesehenen Laufzeit selbst überspringen.

---

## 4.5 Kontext wird stufengerecht minimiert

Jede Pipeline-Stufe erhält nur den Kontext, den sie für ihre Aufgabe benötigt.

Beispiele:

- Ein Security-Reviewer benötigt nicht zwangsläufig alle UI-Dateien.
- Ein Test-Reviewer benötigt Ticket, Diff, bestehende Tests und Teststrategie.
- Ein Finding-Verifier benötigt das Finding, die Belege, relevanten Code, Ticketanforderungen und gegebenenfalls angrenzenden Kontext.
- Ein Fix-Modell benötigt wirksam blockierende oder ausdrücklich für den Fix autorisierte
  Findings, Zielcode, relevante Architekturregeln und Tests.
- Ein finaler starker Reviewer erhält den finalen Gesamtdiff und die Spezifikation, aber nicht sämtliche Rohdiskussionen vorheriger Modelle.

Das System soll Kontext bei Bedarf gezielt erweitern können, statt vorsorglich das gesamte Repository zu senden.

---

## 4.6 Modell- und Provider-Namen bleiben konfigurierbar

Provider und Modelle sind zwei verschiedene Bindungen. Im Code werden Rollen und
serverseitig bekannte Profile verwendet; ein Projekt oder ein Ticket darf weder einen
CLI-Befehl noch freie Flags, Endpunkte, Credentials oder Modellnamen einschleusen.
Modellname, Version, Aufwand, Capability-Status und Runtime-Profil werden im Approval-
Snapshot festgehalten und bei jedem Providerturn erneut geprüft.

Die erste Providerstufe ist ausdrücklich:

- `codex_cli` — Implementierung, Fixturn und optional ein Review-only-Slot;
- `grok_cli` — unabhängiger Qualitätsreviewer und Finding-Verifier;
- `github_copilot_cli` — unabhängiger Qualitätsreviewer;
- `fake` — deterministischer Testadapter ohne echte Providerkosten.

`AI6-034` bleibt als bereits veröffentlichter Blueprint für Claude unverändert. Er darf
nicht stillschweigend in Grok oder Copilot umbenannt werden. Die Grok- und Copilot-
Adapter benötigen nach einer Planrevision eigene, neu vergebene Blueprint-IDs. Dieselbe
Planrevision erweitert den Wortlaut von `AGT-001`, der den gemeinsamen
AgentAdapter-Vertrag heute ausdrücklich auf Codex CLI, Claude CLI und FakeAgent bezieht,
um die neu freigegebenen Provider, ohne den Vertrag selbst zu ändern. `AI6-035`
ist dann auf alle in der ersten Providerstufe freigegebenen Adapter zu erweitern. Seine
heutige Abhängigkeit von `AI6-034` darf die erste Providerstufe nicht blockieren: Die
Planrevision muss Onboarding und Doctor für Codex, Grok und Copilot von der späteren
Claude-Erweiterung entkoppeln, ohne `AI6-034` umzuwidmen.

Ein mögliches **vertrauenswürdiges Serverprofil** (kein Projektkonfigurationsbeispiel)
sieht fachlich so aus:

```yaml
provider_profiles:
  codex_cli:
    command: codex
    transport: exec_jsonl
    roles: [implementation, fix, quality_review]
  grok_cli:
    command: grok
    transport: headless_streaming_json
    roles: [quality_review, finding_verification]
  github_copilot_cli:
    command: copilot
    transport: prompt_text
    roles: [quality_review]
```

Die konkrete CLI-Version und die verfügbaren Modell-/Effortwerte werden nicht aus diesem
Beispiel, sondern aus dem verifizierten Capability-Doctor und der Server-Allowlist
übernommen. Fehlende oder nicht nachweisbare Fähigkeiten führen zu `unavailable` oder
`degraded`, nicht zu einem stillen Fallback.

## 4.7 Verbindlicher CLI-Schnitt der ersten Providerstufe

Die erste Implementierung darf keine interaktiven TUI-Sitzungen und noch keinen
ACP-Langzeitprozess automatisieren. Je Adapter gibt es genau einen gepinnten, vom Doctor
nachgewiesenen Headless-Transport. stdout, stderr, Exitcode, Timeout, Abbruch und
Rohartefakt werden getrennt erfasst:

| Providerprofil | Maschinenmodus | V1-Vertrag |
|---|---|---|
| `codex_cli` | `codex exec --json --output-schema <schema>` | JSONL-Ereignisse und schema-gebundene finale Antwort; `--ephemeral`, `--ignore-user-config`, `--ignore-rules` und ein rollenabhängiges explizites Sandboxprofil |
| `grok_cli` | `grok --no-auto-update -p <prompt> --output-format streaming-json` | JSONL-Ereignisse; für Review/Verifikation nachgewiesenes read-only `--sandbox`-Profil, `--max-turns` und nur über `--tools`/`--disallowed-tools` freigegebene Lesetools; `--no-subagents`, `--no-memory` und `--disable-web-search` gesetzt |
| `github_copilot_cli` | `copilot -p <prompt> -s --no-ask-user --no-remote-export` | dokumentierter programmatischer Promptmodus mit finaler Textantwort, aus der der Adapter das AI6-JSON extrahiert; nur über `--available-tools`/`--excluded-tools` freigegebene Lesetools; `COPILOT_HOME` zeigt auf ein frisches, versiegeltes Sessionhome |

Die Befehlsformen sind Adapterdetails und werden bei jeder CLI-Version erneut durch den
Doctor geprüft. Für Codex sind der dokumentierte Einstieg `codex exec` und JSONL-Ausgabe
einschließlich `--output-schema` die relevante V1-Naht. Grok liefert im Headless-Modus
Streaming-JSON. Für Copilot ist derzeit kein nativer JSON-/JSONL-Ausgabemodus
dokumentiert; die V1-Naht ist die finale Textantwort des Promptmodus, und ein nativer
Maschinenmodus wird erst verwendet, wenn der Doctor ihn für die gepinnte Version
nachweist. ACP (bei Grok) und der Copilot-SDK-/Servermodus bleiben spätere, eigenständig
zu prüfende Transportoptionen. Daraus folgt **nicht**, dass die drei Provider
dasselbe Event- oder Ergebnisformat liefern. Der Adapter extrahiert die finale
Providerantwort und validiert sie anschließend gegen genau den zentralen
`ai6.agent.v1`- beziehungsweise `ai6.quality-review.v1`-Vertrag. Ein fremdes
Provider-Eventschema wird nicht zum zweiten AI6-Fachvertrag.

Für alle Profile gilt:

- Das Prozessargument ist eine Argumentliste; Shellstrings aus Projekt-, Ticket- oder
  Providertext sind verboten.
- `HOME`, Provider-Home, Config-, Cache-, History-, Plugin-, Skill-, Hook-, MCP- und
  Instruction-Pfade werden in einem frischen, versiegelten Execution-Home explizit
  festgelegt. Nicht nachweisbar abschaltbare Autodiscovery beendet den Start fail closed.
- Für Copilot ist das frische, versiegelte `COPILOT_HOME` die nachweisbare
  Konfigurationsgrenze: Es enthält keine MCP-Server-, Plugin-, Skill- oder
  Hook-Konfiguration, und repositorygetriebene Extensions, Hooks und Workspace-MCP
  bleiben damit aus. `--available-tools`/`--excluded-tools` schließen Shell,
  Schreibzugriffe, Delegation, Memory, URL-Zugriff und MCP für Reviews aus. Ein
  eingebauter GitHub-MCP-Server begründet keine Ausnahme; weist die gepinnte
  CLI-Version eine dieser Grenzen nicht nach, bleibt das Profil nicht startbar.
- Der Reviewer erhält den exportierten, gitmetadatenfreien Tree und niemals den
  Managed-Clone. Nur der Worker importiert einen validierten Patch.
- Codex-Implementierungs- und Fixturns verwenden `workspace-write` ausschließlich im
  wegwerfbaren Export. Reviews laufen read-only: bei Codex und Grok über das jeweilige
  nachgewiesene Sandbox-/Berechtigungsprofil, bei Copilot mangels dokumentiertem
  Sandboxmodus über die Toolallowlist. Die Container-/Dateisystemgrenze bleibt in allen
  Fällen die maßgebliche Kontrolle und verlässt sich nicht allein auf eine
  provider-native Sandbox.
- Providercredentials werden ausschließlich als kurzlebige read-only Projektion des
  ausgewählten Profils eingebunden; stdout, stderr, Sessions und Transkripte durchlaufen
  die zentrale Redaction und Retention.
- `--allow-all`, `--always-approve`, `--yolo`, `danger-full-access` und vergleichbare
  Vollzugriffsoptionen sind kein V1-Default und dürfen nicht aus Projektinput stammen.
- Ein optionaler echter Smoke-Test ist hinter einem expliziten, nicht standardmäßig
  aktiven Gate zu führen; Fake-Binary-Contracttests bleiben die deterministische
  Pflichtabdeckung.

Die bei der Planintegration verwendeten Primärreferenzen sind die [Codex-Dokumentation
zum Non-interactive Mode](https://learn.chatgpt.com/docs/non-interactive-mode), die
[Grok-Build-Dokumentation zu Headless & Scripting](https://docs.x.ai/build/cli/headless-scripting)
mit ihrer [CLI-Referenz](https://docs.x.ai/build/cli/reference) sowie die
[GitHub-Dokumentation zum programmatischen Copilot-CLI-Aufruf](https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-programmatic-reference)
und die [Copilot-CLI-Befehlsreferenz](https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-command-reference).
Sie sind keine dauerhafte Versionspinning-Quelle; das Pinning und der Capability-Nachweis
gehören in `AI6-035`.

---

# 5. Funktionsmodi

## 5.1 Implementierungsmodus

Der Implementierungsmodus startet mit einem Ticket und führt abhängig von der Konfiguration folgende Schritte aus:

1. Ticket und Projektkontext laden;
2. effektives Implementierungsmodell bestimmen;
3. Implementierung ausführen;
4. Implementierungsnotizen erzeugen;
5. deterministische Prüfungen ausführen;
6. Review-Pipeline starten;
7. wirksam blockierende Findings beheben;
8. gezielt erneut prüfen;
9. Candidate-, Gate- und Pushpolicy durchführen;
10. Abschlussbericht erzeugen.

Im AI6-MVP sind diese Schritte keine frei definierbaren Jobketten. Sie werden in die
Phasen und Übergänge des `RunOrchestrator` eingeordnet. Implementierung, Fixturns,
Checks, Checkpoint, Review, Finalisierung und Push bleiben deshalb an die bestehenden
Run-, Scope-, Git- und Gateverträge gebunden. Ein Provider darf keine dieser Übergänge
selbst auslösen.

---

## 5.2 Review-only-Modus

Der Review-only-Modus verändert in V1 keinen Code, keinen verwalteten Ref und keinen
Ticketstatus aus einem Providerprozess. Er bleibt ticketzentriert: Ein Lauf benötigt ein
verwaltetes Projekt, einen gültigen AI6-Ticketvertrag, einen menschlich freigegebenen
Approval-Snapshot und eine darin gebundene Reviewquelle. Eine freie „reviewe dieses
Verzeichnis“-Operation außerhalb der Approval-/Run-Grenze ist kein AI6-Lauf.

Für die erste AI6-Stufe sind ausschließlich bereits serverseitig gebundene Gegenstände
zulässig:

- ein verwalteter Branch gegen den im Approval gebundenen, verifizierten Control-Stand;
- eine gebundene Commit-Range oder ein einzelner Commit im Managed-Clone mit
  nachgewiesener Basis;
- ein durch den Worker importierter Patch/Diff mit geprüfter Basis, Scope- und
  Pfadvalidierung;
- ein vorhandener AI6-Checkpoint samt Ticket-/Control-/Tree-/Diff-Bindung.

Ein beliebiges lokales Arbeitsverzeichnis, frei ausgewählte Dateien oder eine URL zu einem
Pull Request sind keine V1-Eingaben. Ein Pull Request kann später über seine gebundene
Commit-Range abgebildet werden; ein GitHub-Connector oder eine automatische PR-Aktion ist
nicht Teil dieses Auftrags.

Der Worker normalisiert die Quelle in einen wegwerfbaren, gitmetadatenfreien
Review-Checkpoint. Der Modus verwendet danach dieselben Check-, Review-, Finding-, Gate-
und HumanLoop-Komponenten wie der vollständige Implementierungsworkflow.

Vor der Planintegration ist jedoch eine echte Vertragslücke zu schließen: Der heutige
Plan kennt ausschließlich den Claim `ready → in_progress` und den Abschluss nach
bestätigtem Branch-Push mit `in_progress → review`. Für einen report-only Lauf ohne Push
muss die Planrevision einen eigenen, absturzsicheren Claim-/Abschlussvertrag mit
Ticketstatuswirkung, Lockfreigabe, Cancel und Wiederanlauf festlegen. Das darf nicht
durch direktes Setzen von `runs.state`, durch Wiederverwendung von `no_change_required`
oder durch einen erfundenen `in_progress → review`-Übergang umgangen werden.

Mögliche Ausgaben:

- strukturierte Findings;
- unveränderte Reviewer- und Verifierresultate;
- Review-Bericht;
- Gate-/Human-Request-Entscheidung;
- gebundener Abschlussbericht ohne Pushwirkung.

Ein Fix aus einem Review-only-Ergebnis startet in V1 nicht still im selben Lauf. Er
benötigt einen neuen normalen Implementierungs-/Fixlauf mit eigener menschlicher
Freigabe und frischer Snapshot-/Scopebindung. Die Findings des Review-only-Laufs sind
dabei referenzierte Evidenz, keine unmittelbare Mutationsautorität.

---

## 5.3 Verketteter Modus

Die fachlichen Schritte sollen innerhalb des bestehenden Run-Workflows verkettet werden
können. Für V1 gibt es zunächst ein oder wenige serverseitig versionierte Pipelineprofile,
keinen frei editierbaren DAG-Designer und keine neue generische Pipeline-Engine.

Beispiel:

```text
claim_approved_ticket
-> implement_or_import_bound_review_subject
-> deterministic_checks
-> immutable_checkpoint
-> review:functionality
-> review:security
-> review:tests
-> validate_and_group_exact_findings
-> advisory_verify_findings
-> fix_authorized_must_fix_findings
-> checks_and_full_re_review_on_new_checkpoint
-> candidate_and_declared_gates
-> manual_or_automatic_after_gates_push
-> status_sync_and_run_completion
```

Die erste Version arbeitet sequenziell. Eine Stage ist im AI6-Sinn ein Orchestrator-
Schritt, ein Check, ein Review-Slot oder ein Gate; sie ist nicht automatisch eine neue
Tabelle `pipeline_runs` oder `stage_runs`.

Die Architektur sollte jedoch spätere Erweiterungen ermöglichen:

- parallele unabhängige Reviews;
- bedingte Stufen;
- Überspringen bestimmter Stufen;
- manuelle Gates;
- Wiederaufnahme ab einer fehlgeschlagenen Stufe;
- unterschiedliche Pipelines je Tickettyp;
- unterschiedliche Pipelines je Risiko.

Es ist nicht erforderlich, sofort einen allgemeinen DAG-Workflow-Designer zu bauen, sofern das bestehende Projekt dies nicht ohnehin vorsieht.

---

# 6. Providerrollen der ersten Ausbaustufe

## 6.1 Planung und Ticket-Erstellung

Architektur, Planrevision, Ticket-Blueprints und Freigabe des Plans bleiben menschlich
gesteuerte beziehungsweise außerhalb des produktiven Run-Workflows liegende Aufgaben.
Ein beliebiges starkes Modell darf dabei unterstützen, erhält aber keine Autorität über
`AGENTS.md`, den kanonischen Plan, Ticketstatus, Approvals oder Provider-Allowlists.

Die Planung muss die vorhandenen AI6-Module und die stabilen Requirement-IDs referenzieren.
Sie darf keine Modellnamen als dauerhafte Produktverträge festschreiben.

## 6.2 `codex_cli` für Implementierung und Fixturns

Der Codex-CLI-Adapter ist der erste Implementierungsadapter. Er erhält:

- den gebundenen Implementierungskontext;
- die serverseitig aufgelösten Prompt-, Instruction- und Runtime-Snapshots;
- den exportierten Tree ohne Gitmetadaten;
- den erlaubten Scope und die Tests.

Er darf nur über den gemeinsamen ProcessRunner laufen. Der Worker übernimmt den
resultierenden Patch erst nach Pfad-, Typ-, Symlink-, Größen-, Scope- und Diffprüfung.
Ein leerer Diff ist nur als validiertes `no_change_required` zulässig; ein Providertext
allein beweist keinen leeren Diff.

Für komplexe oder wiederholt fehlschlagende Aufgaben wird nicht automatisch ein
unbenanntes „stärkeres Modell“ gewählt. AI6 pausiert mit einem typisierten Human Request
oder verwendet ein vorher freigegebenes alternatives `codex_cli`-Modell-/Effortprofil.

## 6.3 `grok_cli` für unabhängige Reviews und Verifikation

Die Grok-Build-CLI ist in V1 ein Review- und Verifier-Adapter. Sie erhält denselben
unveränderlichen Checkpoint wie alle anderen ausgewählten Reviewer, aber eine eigene
Session und ein eigenes Kontextpaket.

Der Verifier bewertet Belege, Ticketkriterien, erwartetes Ergebnis und Gegenargumente.
Er schreibt kein Finding um und entscheidet nicht durch freie Debatte. Sein Ergebnis wird
als eigenes unveränderliches, quellgebundenes Reviewresultat gespeichert. Eine
Empfehlung „nicht zutreffend“ ist keine wirksame `not_applicable`-Disposition und kann ein
fremdes `must_fix` nicht automatisch entblocken. Bei fehlendem Beleg oder Widerspruch
entsteht `inconclusive` beziehungsweise ein Human Request nach der zentralen Policy; ein
unbegrenzter Challenge-Loop ist ausgeschlossen. Grok verifiziert kein Grok-
Originalfinding aus demselben Provider-/Modellprofil.

## 6.4 `github_copilot_cli` als unabhängiger Reviewer

Die GitHub-Copilot-CLI ist in V1 ein zweiter unabhängiger Reviewpfad. Sie wird
programmgesteuert über `-p`/`--prompt` mit `--no-ask-user` gestartet, mit einer eigenen
Session, frischem versiegeltem `COPILOT_HOME` und explizit über
`--available-tools`/`--excluded-tools` begrenzten Tools. Die finale Antwort ist Text und
wird vom Adapter gegen den AI6-Reviewvertrag validiert. Der Copilot-SDK-/Servermodus ist
kein V1-Transport.

Copilot wird nicht als „GitHub-Code-Review-Modell“ behandelt und darf keine Pull Requests,
Issues, Commits oder Remotes selbst mutieren. GitHub-MCP, Plugins, Skills, Hooks,
Workspace-MCP und Home-Instruktionen sind aus, sofern sie nicht in einem vertrauenswürdigen
AI6-Runtimeprofil ausdrücklich allowlistet und hashgebunden sind. Kann die eingesetzte
CLI-Version diese Grenze nicht nachweisbar einhalten, bleibt das Profil nicht startbar.

## 6.5 Erste Providerbelegung

Der erste vollständige Implementierungsablauf verwendet standardmäßig:

```text
Codex CLI implementiert oder fixt
        -> Checks und unveränderlicher Checkpoint
        -> Grok CLI reviewt unabhängig
        -> GitHub Copilot CLI reviewt unabhängig
        -> Findings und AC-Abdeckung im AI6-Vertrag
        -> Fixturn über Codex CLI, falls autorisiert
```

Im eigenständigen Review-only-Modus kann Codex zusätzlich als Reviewer gewählt werden.
Der Implementierer entscheidet jedoch nie allein über die Qualität seiner eigenen
Änderung. Alle ausgewählten Reviewer prüfen denselben neuen Checkpoint; eine Mehrheit
hebt ein blockierendes Finding nicht auf.

Der Verifierslot wird pro Findingquelle gewählt. Grok ist der V1-Standard für Copilot-
und zulässige Codex-Findings; Grok-Findings gehen an einen unabhängigen Slot oder an den
HumanLoop. „Neuer Slot“ bedeutet neue Session und neue Slotrevision, nicht nur einen
zweiten Prompt in derselben Sitzung.

Ein optionaler finaler Review ist in V1 kein eigener, vorausgesetzter Modelltyp. Er nutzt
ein serverseitig freigegebenes Providerprofil (bevorzugt Grok oder Codex mit neuer
Session) und wird nur bei Risiko-, Release- oder Human-Triggern zugeschaltet. Ein
„Opus“- oder sonstiger Modellname darf dafür nicht als Projektvertrag erfunden werden.

---

# 7. Spezialisierte Review-Profile

Review-Prompts sollen als versionierte Profile im zentralen Promptkatalog aus `AI6-011`
verwaltet werden. Es entsteht weder ein zweiter Renderer noch eine providerindividuelle
Sammlung frei formatierter Templates. Jeder erforderliche spezialisierte Reviewlauf
liefert weiterhin die vollständige `criterion_coverage` nach `REV-004`; der Fokus des
Profils darf Akzeptanzkriterien nicht aus der Antwort entfernen.

Mögliche Profile:

## 7.1 Ticket- und Akzeptanzkriterien

Prüft:

- erfüllt der Diff das Ticketziel;
- fehlen Akzeptanzkriterien;
- wurde zusätzlicher Scope eingebaut;
- wurden Nichtziele verletzt;
- wurden notwendige Fälle übersehen.

## 7.2 Funktionale Korrektheit und Edge Cases

Prüft:

- Logikfehler;
- Null-/Leerfälle;
- Grenzwerte;
- fehlerhafte Zustandsübergänge;
- inkonsistente Annahmen;
- Fehlerbehandlung;
- unerwartete Seiteneffekte.

## 7.3 Security und Berechtigungen

Prüft:

- Authentifizierung;
- Autorisierung;
- Rollen und Rechte;
- Input-Validierung;
- Injection-Risiken;
- Secret-Verarbeitung;
- Datenexposition;
- Projekt-, Rollen- und Credentialtrennung;
- Git-/Ref-/Scope- und Provider-Runtime-Isolation;
- unsichere Defaults.

## 7.4 Datenbank und Migrationen

Prüft:

- Datenverlust;
- Rückwärtskompatibilität;
- Rollback-Fähigkeit;
- Locking;
- Indizes;
- Constraints;
- Nullability;
- große Datenmengen;
- Deployment-Reihenfolge.

## 7.5 Concurrency und Zustandskonsistenz

Prüft:

- Race Conditions;
- Idempotenz;
- doppelte Verarbeitung;
- Transaktionsgrenzen;
- Retry-Verhalten;
- veraltete Zustände;
- atomare Änderungen.

## 7.6 Performance und Ressourcen

Prüft:

- unnötige Abfragen;
- N+1-Probleme;
- ungebundene Schleifen;
- große Speicherlast;
- Cache-Inkonsistenzen;
- unpassende Synchronität;
- teure Netzwerkpfade.

## 7.7 Tests und Testbarkeit

Prüft:

- fehlen relevante Tests;
- testen vorhandene Tests das richtige Verhalten;
- fehlen Negativfälle;
- sind Tests zu eng an die Implementierung gekoppelt;
- können Regressionen unentdeckt bleiben;
- stimmen Fixtures und Mocks mit der Realität überein.

## 7.8 Architektur und Wartbarkeit

Prüft:

- Verstöße gegen bestehende Schichten;
- neue unnötige Abstraktionen;
- Duplikation;
- inkonsistente Zuständigkeiten;
- versteckte Kopplung;
- erschwerte Erweiterbarkeit;
- Abweichungen von dokumentierten Architekturentscheidungen.

## 7.9 API- und Integrationsverträge

Prüft:

- Request-/Response-Verträge;
- Versionierung;
- Fehlercodes;
- Timeouts;
- Retry;
- Idempotency Keys;
- externe Abhängigkeiten;
- Abwärtskompatibilität.

## 7.10 Stil und Format

Stilprüfungen sollen möglichst deterministisch erfolgen.

Ein LLM-Stilreview ist nur sinnvoll, wenn es um nicht automatisierbare Projektkonventionen oder Verständlichkeit geht.

---

# 8. Auswahl der Review-Profile

Nicht jedes Profil muss bei jedem Ticket laufen.

Die Auswahl kann abhängen von:

- Tickettyp;
- betroffenen Dateien;
- Sprache und Framework;
- Risiko;
- Security-Relevanz;
- Datenbankänderungen;
- Anzahl betroffener Subsysteme;
- manueller Auswahl;
- Projektregeln;
- vorherigen Fehlern.

Beispiel:

```yaml
approved_reviewer_slots:
  - prompt_profile: ticket_compliance
    provider_profile: grok_cli
  - prompt_profile: functional_correctness
    provider_profile: github_copilot_cli
  - prompt_profile: tests
    provider_profile: grok_cli
selection_reason_codes:
  - ticket_risk_high
  - touches_database_migrations
```

Dies ist eine fachliche Approval-Projektion, kein neues Projekt-YAML-Schema. `AI6-011`
führt die allowlisteten Rollen-/Prompt-/Providerprofile, `AI6-012` persistiert die
wirksamen Reviewer-Slots und ihre Auswahlgründe im Approval-Snapshot. Pfad- und
Risikoregeln dürfen nur serverseitig bekannte Profile auswählen; sie erzeugen keine
freien Prompts oder Befehle.

---

# 9. Finding-Datenmodell

Das endgültige Schema ist an den vorhandenen AI6-Reviewvertrag anzupassen. Die folgende
Zuordnung trennt bewusst Original, wirksame Disposition und reine Darstellung. Sie ist
keine Erlaubnis für eine zweite freie Finding-Tabelle oder ein zweites JSON-Schema:

```yaml
original_finding:
  local_id:
  source:
  review_result_id:
  checkpoint_tree_oid:
  checkpoint_diff_hash:
  severity:
  disposition:
  category:
  file:
  line:
  title:
  evidence:
  expected_result:
  criterion_refs:

authorized_disposition:
  value: fixed|not_applicable|accepted_risk
  reason:
  actor_and_authorization_reference:
  checkpoint_and_policy_binding:
  version:

derived_view:
  exact_duplicate_group_references:
  verifier_review_result_references:
  effectively_blocking:
```

Provider, Modell, Session, Promptversion, Instruction-/Runtime-Hash und Nutzungsdaten
gehören primär in `run_agents`, `review_results` oder `run_artifacts` und werden über
stabile IDs referenziert. Ticket, Run, Checkpoint, Tree-OID, Diff-Hash, Prompt- und
Policybindung müssen vor einer wirksamen Disposition verifiziert werden. Nicht jedes
Feld muss in einer einzelnen Datenbanktabelle liegen, aber kein Feld darf eine zweite
Autorität für Ticketinhalt oder Ticketstatus begründen.

---

# 10. Finding-Lifecycle

Die folgenden Begriffe sind eine fachliche Beschreibung und keine neue AI6-State-Machine.
Für V1 genügt die zentrale AI6-Disposition des Quality-Review-Vertrags:

```text
review_result: unveränderlich
    |
    +--> must_fix
    +--> human_required
    +--> suggestion
    +--> follow_up

separate autorisierte Disposition am aktuellen Checkpoint
    |
    +--> fixed / not_applicable / accepted_risk
```

Die projektweite Wirksamkeit wird checkpointgebunden berechnet:

```text
must_fix
    |
    +--> Fixturn -> neuer Checkpoint -> vollständiger Re-Review
    +--> accepted_risk / not_applicable -> nur mit autorisierter, begründeter Disposition
    +--> human_required -> Human Request
    +--> reopened -> neues Finding oder neue Reviewresultatbindung
```

`proposed`, `challenged`, `confirmed` und `rejected` können in einer UI oder in
verknüpften Artefakten als erklärende Begriffe erscheinen, sind aber nicht ungeprüft als
neue persistente Statuswerte einzuführen. AI6 verwendet kein Event-Sourcing.

Wesentliche Anforderungen:

- Originalresultate und Dispositionen sind nachvollziehbar;
- Entscheidungen enthalten eine Begründung;
- ein Finding beziehungsweise Originalresultat wird nicht physisch gelöscht, nur weil es
  nicht blockiert;
- Deduplizierung bleibt nachvollziehbar;
- ein behobenes Finding kann bei einer Regression wieder geöffnet werden;
- Schweregrad und Entscheidung sind getrennte Konzepte;
- eine bewusste Risikoakzeptanz wird explizit dokumentiert.

---

# 11. Challenge- und Verifikationsprozess

Der V1-Prozess verwendet unabhängige Review-Slots auf demselben Checkpoint:

```text
Codex / Grok / Copilot Review-Slots
        |
        v
unveränderte Reviewresultate
        |
        v
Schema-Validierung, exakte Duplikatgruppen und AC-Abdeckung
        |
        +--> Original-Must-fix ----------> bleibt must_fix
        |
        +--> Verifier bestätigt ----------> zusätzliche Evidenz
        |
        +--> Verifier widerspricht/unklar -> HumanLoop; kein Auto-Unblock
        |
        +--> Fix autorisiert --------------> neuer Checkpoint und alle Reviewer erneut
```

Regeln:

1. Jeder ausgewählte Reviewer erhält eine eigene Session, aber denselben Checkpoint.
2. Ein Reviewer darf sein Originalresultat nicht selbst löschen oder umschreiben.
3. Ein vom Quellslot unabhängiger Verifier kann Evidenz und Gegenargumente prüfen; das
   ist keine freie Debatte, keine Mehrheitsentscheidung und keine autorisierte
   Finding-Disposition.
4. Jede Runde muss ein konkretes, schema-validiertes Ergebnis liefern.
5. Für `high`, `critical`, `human_required` und `inconclusive` gilt die zentrale Human-
   beziehungsweise Security-Policy.
6. Nach einem Fix, Scope-, Vertrags-, Prompt-, Profil- oder Policywechsel entsteht ein
   neuer Checkpoint und ein vollständiger Re-Review durch alle ausgewählten Reviewer.
7. Ein Finding ohne überprüfbaren Code-, Ticket- oder Testbezug wird nicht automatisch
   entblockt oder wirksam disponiert; Widerspruch und fehlende Evidenz werden sichtbar
   an HumanLoop eskaliert.
8. Retry und Challenge sind durch RUN-006/AI6-026 begrenzt; ein erschöpftes Limit setzt
   einen sichtbaren Wartestatus statt einer stillen Ablehnung.

Beispiel:

```yaml
verification_policy:
  max_challenge_cycles: 0
  verifier_effect: advisory_only
  automatic_unblock: false
  require_human_for:
    - verifier_disagrees_with_must_fix
    - inconclusive_high_or_critical
```

---

# 12. Deduplizierung und Konsolidierung

Mehrere Review-Profile oder Modelle können dasselbe Problem melden. Für V1 bleibt der
bereits veröffentlichte Vertrag aus `AI6-024` maßgeblich: Es gibt ausschließlich exakte,
deterministische Duplikatgruppen. Gleiche normalisierte Datei/Zeile, identische
Kriteriumsreferenzen und identische kanonische Findingfelder dürfen verknüpft werden;
jedes Originalfinding, seine Quelle, Severity und Checkpointbindung bleiben erhalten.

Ähnliche Zeilen, dieselbe Funktion oder ein semantisch ähnliches Fehlerszenario reichen
nicht für eine automatische Zusammenführung. Eine LLM-basierte semantische
Deduplizierung ist ausdrücklich **nicht** Teil von `AI6-024` und wird für die erste
Providerstufe nicht benötigt. Soll sie später hinzukommen, braucht sie nach der
Splitprüfung einen eigenen Blueprint, eine unveränderte Quellspur und einen
Mehrheits-/Unblock-Negativtest.

Der Verifier erhält entweder ein einzelnes Originalfinding oder eine exakte
Duplikatgruppe mit allen unabhängigen Quellen. Die Darstellung darf ein primäres Finding
wählen; die Wirksamkeitsberechnung betrachtet weiterhin jedes blockierende Original.

---

# 13. Implementierungsnotizen für Reviewer

Das implementierende Modell soll nach der Umsetzung eine strukturierte Zusammenfassung erzeugen.

Beispiel:

```yaml
implementation_summary:
  changed_components:
    - ...
  key_decisions:
    - ...
  assumptions:
    - ...
  deviations_from_ticket:
    - ...
  known_limitations:
    - ...
  tests_added_or_updated:
    - ...
  areas_requiring_special_review:
    - ...
```

Diese Notizen helfen Review-Modellen, die Absicht der Änderung zu verstehen.

Sie sind jedoch als:

```text
Kontext des Implementierers, nicht als geprüfte Wahrheit
```

zu behandeln.

Reviewer sollen:

- die Hinweise lesen;
- sie gegen Ticket und Code prüfen;
- sich nicht auf die Selbsteinschätzung des Implementierers verlassen;
- unerwähnte Seiteneffekte weiterhin selbstständig suchen.

---

# 14. Kompakte Review-Sicht in AI6

Die gewünschte schnelle Sicht wird im Panel und aus den SQLite-Laufzeitdaten erzeugt;
sie wird nicht als zweites, automatisch gepflegtes Metadatenformat in die Git-Ticketdatei
geschrieben. Maßgeblich sind `runs`, `run_agents`, `check_results`, `review_results`,
`findings`, `human_requests`, `run_gates`, `interventions`, `run_artifacts` und
`run_events`.

Eine fachliche Projektion kann mindestens anzeigen:

- Run, Checkpoint, Tree-OID und Diff-Hash;
- Implementierungs- und Reviewer-Slots mit Providerprofil und Sessionstatus;
- Checkstatus und vollständige AC-Abdeckung;
- offene, wirksame und autorisiert disponierte Findings;
- Fix- und Re-Review-Stand;
- Human Requests, Gates, Pushmodus und Abschlussstatus;
- redigierte Artefaktverweise und Retentionstatus.

Ein Abschlussbericht ist ein gebundenes `run_artifact` oder eine vom Worker kontrolliert
veröffentlichte Darstellung. Er darf keine eigene Statusautorität oder einen zweiten
Ticketbestand begründen. Ein Mensch und ein nachfolgendes LLM sollen damit schnell
erkennen können, was geprüft und freigegeben wurde, ohne vollständige Providerlogs zu
laden.

---

# 15. Kompakte Kontextpakete

Das System sollte aus Ticket, Repository und Artefakten gezielte Kontextpakete erzeugen.

Mögliche Pakete:

## Implementierungs-Kontext

- Ziel;
- Scope;
- Akzeptanzkriterien;
- relevante Architekturregeln;
- betroffene Module;
- Abhängigkeiten;
- vorherige relevante Entscheidungen;
- erforderliche Tests.

## Erst-Review-Kontext

- Ticket-Spezifikation;
- kumulativer oder stufenspezifischer Diff;
- relevante Quelldateien;
- Implementierungszusammenfassung;
- Prüfergebnisse;
- genau ein Review-Profil.

## Verifier-Kontext

- normalisiertes Finding;
- Quellen des Findings;
- konkrete Belege;
- relevante Codeumgebung;
- verletzte Akzeptanzkriterien;
- Gegenargument bzw. nachgereichte Belege;
- passende Testausgaben.

## Fix-Kontext

- wirksam blockierendes beziehungsweise ausdrücklich für den Fix autorisiertes Finding;
- Entscheidungsbegründung;
- relevanter Code;
- Ticket-Constraints;
- bestehende Tests;
- gewünschte minimale Korrektur.

## Final-Review-Kontext

- finale Spezifikation;
- finaler Gesamtdiff;
- relevante Architekturregeln;
- Test- und Check-Zusammenfassung;
- bekannte offene Risiken;
- optional kompakte Finding-Statistik;
- keine vollständigen Rohdebatten.

Jedes Kontextpaket muss zusätzlich an Run, Approval-/Ticketblob, Control-/Basis-OID,
Checkpoint-Tree, Diff-Hash, Scope, Prompt-Snapshot, Instruction-Snapshot,
Provider-Runtime-Profil und SecurityPolicy gebunden werden. Reviewer erhalten nur den
exportierten, gitmetadatenfreien Tree und die für ihr Profil freigegebenen Dateien.
Eine Änderung an Code, Scope, Vertrag, Prompt, Instruction, Runtime-Profil, Policy oder
Reviewerprofil invalidiert nachgelagerte Ergebnisse.

---

# 16. Fix- und Re-Review-Schleife

Wirksam blockierende `must_fix`-Findings können nach der zentralen Orchestrator-
Entscheidung in Fix-Aufträge überführt werden. Eine Verifierempfehlung ist dafür weder
erforderlich noch allein autorisierend.

Empfohlener Ablauf:

```text
effective must_fix findings
        |
        v
Findings nach zusammenhängenden Fixes gruppieren
        |
        v
Fix-Modell auswählen
        |
        v
Minimalen Fix implementieren
        |
        v
Deterministische Prüfungen
        |
        v
Gezielter Re-Review
        |
        +--> behoben -----------------> fix_verified
        |
        +--> nicht behoben -----------> erneuter Fix oder Eskalation
        |
        +--> neue Regression ---------> neues/reopened Finding
```

Wichtige Regeln:

- Fixes sollen nicht unnötig den Ticket-Scope erweitern.
- Ein Fix-Modell erhält nur wirksam blockierende beziehungsweise explizit für den Fix
  freigegebene Findings.
- Verworfenes Feedback darf nicht versehentlich umgesetzt werden.
- Nach einem Fix darf zunächst gezielt das Finding und die betroffenen Tests geprüft
  werden; der Abschluss verlangt trotzdem einen neuen Checkpoint und den vollständigen
  Re-Review durch alle ausgewählten Reviewer.
- Nach größeren Fixes kann die Pipeline zusätzlich ein breiteres Checkprofil verlangen.
- Schleifenlimits sind konfigurierbar.
- Wiederholtes Scheitern führt zu Modell- oder Human-Eskalation.
- Alle Versuche und Änderungen bleiben nachvollziehbar.

Beispiel:

```yaml
fix_policy:
  default_provider_profile: codex_cli
  escalated_provider_profile: codex_cli
  max_default_fix_cycles: 2
  max_escalated_fix_cycles: 1
  stop_after_escalated_failure: true
```

---

# 17. Approval-Policy

Die Freigabe muss zwei Ebenen auseinanderhalten:

1. die menschliche, Git-/Ticket-gebundene Approval-Saga aus `AI6-012` (`todo → ready`),
2. die spätere Run-/Candidate-/Push-Policy nach Checks, Reviews und Gates.

Keine Review- oder Providerstufe darf die erste Ebene ersetzen. Die folgenden Modi
beschreiben daher nur die zweite Ebene und müssen an die vorhandenen AI6-Policies
angeschlossen werden.

Der kanonische Plan kennt für den Push exakt zwei Werte. Diese Erweiterung führt keine
Synonyme und keine zweite Approval-Policy-Engine ein:

## 17.1 `manual`

Nach gültigem Candidate und allen geschlossenen Gates setzt der Orchestrator
`wait_reason=manual_push`. Nur der autorisierte Push-Resolver auf dem unveränderten
Candidate oder Cancel setzt den Lauf fort. Die initiale Ticketfreigabe bleibt ohnehin
menschlich und wird nicht automatisiert.

`manual` ist der V1- und Rollout-Default, insbesondere bei unbekannter Providerqualität,
hohem Ticketrisiko und manuellen Pilotgates.

## 17.2 `automatic_after_gates`

Der Orchestrator darf den unveränderten Candidate nach allen bestehenden Checks,
Reviews, wirksamen Finding-Dispositionen, Security- und `MG-`-/`EXT-`-Gates ohne
zusätzlichen Pushbutton veröffentlichen. Der Modus überspringt kein Gate und ändert nicht
die menschliche `todo → ready`-Approval.

Begriffe wie `risk_based`, `auto_verified`, `auto_noncritical` und `manual_on_request`
können fachliche Auswahlregeln beschreiben, sind aber keine zusätzlichen
`push_mode`-Werte. Risikoregeln dürfen höchstens entscheiden, ob das freigegebene
`automatic_after_gates` für einen Approval-Snapshot zulässig ist oder auf `manual`
verengt wird. Provider- oder Projektinhalt darf die Auswahl nie erweitern.

---

# 18. Human-Approval-Trigger

Mögliche Trigger für `manual_push`, einen vorhandenen typisierten Wartestatus oder einen
Human Request:

- Ticket-Risiko `high` (der Ticketvertrag kennt kein `critical`);
- Finding `high` oder `critical`;
- Authentifizierung;
- Autorisierung;
- Rollen/Berechtigungen;
- Secrets/Credentials;
- Verschlüsselung;
- Datenmigration;
- irreversible Datenänderung;
- Control-Branch, Git-Refs, Deploy-Key oder Remotezugriff;
- ProcessRunner-, ExecutionMailbox-, Sandbox- oder Instruction-Discovery-Grenzen;
- Deployment-, Container- oder Runtime-Logik;
- Projekt- und Rollenisolation;
- Security-relevante Pfade;
- viele betroffene Subsysteme;
- hohe Diff-Größe;
- wiederholte Fix-Schleifen;
- inconclusive Finding;
- bewusste Risikoakzeptanz;
- Reviewer widersprechen sich;
- finale Checks konnten nicht ausgeführt werden;
- manueller Override;
- unbekanntes Ticketprofil;
- nicht unterstützte Capability, CLI-Version oder Checkumgebung.

Beispiel:

```yaml
push_policy_guard:
  requested_mode: automatic_after_gates
  effective_mode: manual
  require_human_when:
    ticket_risk:
      - high
    finding_severity:
      - critical
    flags:
      - security_relevant
      - irreversible_data_change
      - control_or_deploy_key_change
    max_fix_cycles_exceeded: true
    inconclusive_findings: true
```

Die Namen in diesem Beispiel sind Reason-Codes einer serverseitigen Entscheidung, kein
neues Projektkonfigurations- oder Datenbankschema. Die Planrevision leitet sie auf die
vorhandenen Approval-, Gate-, Wait-Reason- und Auditverträge ab.

---

# 19. Automatische Fortsetzung nach Gates

`automatic_after_gates` darf nur wirken, wenn alle festgelegten Voraussetzungen explizit
erfüllt und an den unveränderten Candidate gebunden sind.

Beispiel:

```yaml
automatic_after_gates_requirements:
  ticket_approval: ready_and_bound
  approved_ticket_blob_sha: present
  approved_control_sha: present
  deterministic_checks: passed
  required_review_profiles: completed
  criterion_coverage: complete_per_required_reviewer
  effectively_blocking_findings: 0
  unresolved_human_required_or_inconclusive: 0
  fix_verification: passed
  pipeline_errors: 0
  human_trigger_active: false
  open_mg_or_ext_gates: 0
  candidate_binding: current
  security_gate: clear_or_valid_override
```

Auch diese Liste ist ein Prädikat über vorhandene Bindungen und kein zweiter
Approvaldatensatz.

Auch bei automatischer Fortsetzung wird ein gebundener Abschlussbericht erzeugt.

Der Entwickler soll das Endergebnis später nachvollziehen können, ohne sämtliche Einzelaufrufe lesen zu müssen.

---

# 20. Modell-Routing für Implementierung

Die fachliche Empfehlung kann aus dem Ticket kommen; die wirksame Auswahl wird jedoch
erst in `AI6-012` aus serverseitig erlaubten Profilen als Approval-Snapshot festgehalten.
Ein Ticket soll dafür nur die im bestehenden Format zulässigen Risiko- und Scopeangaben
beitragen. Laufzeitfelder gehören in Approval, Run und Agentenslot.

```yaml
ticket_risk: low|medium|high
affected_paths:
spec_refs:

recommended_provider_profile:
recommendation_reason:
```

`ticket_risk`, `affected_paths` und `spec_refs` werden aus dem AI6-Ticketvertrag und der
serverseitigen Policy abgeleitet. `recommended_provider_profile` ist eine Empfehlung für
Approval und Run, kein freies Ticketfeld; das Detailticket-Format wird dadurch nicht
erweitert.

Beim Start wird zusätzlich bestimmt:

```yaml
effective_provider_profile:
model_selection_reason:
```

Die Empfehlung kann überschrieben werden durch:

- harte Sicherheitsregeln;
- betroffene Pfade;
- Tickettyp;
- vorherige Fehlversuche;
- Benutzerentscheidung;
- Kosten-/Budgetregeln;
- Capability- und Verfügbarkeitsstatus eines Providers;
- Projektkonfiguration.

Beispiel:

```text
recommended = codex_cli
security_relevant = true
=> effective = codex_cli mit freigegebenem Security-/Effortprofil oder Human Request
```

---

# 21. Modell-Routing für Reviews

Review-Routing soll separat vom Implementierungs-Routing konfigurierbar sein.

Beispiel:

```yaml
review_routing:
  initial:
    ticket_compliance:
      provider_profile: grok_cli
    functional_correctness:
      provider_profile: github_copilot_cli
    security:
      provider_profile: grok_cli

  verifier:
    by_finding_source:
      github_copilot_cli: grok_cli
      codex_cli: grok_cli
      grok_cli: github_copilot_cli
    on_same_profile_or_unavailable: human
    effect: advisory_only

  final:
    enabled_when:
      - ticket_risk_high
      - release_gate
      - manual_request
    provider_profile: grok_cli
```

Die Beispielprofile sind serverseitige Aliase. Sie dürfen nicht durch Projekt-YAML in
neue Befehle, Flags oder Provider umdefiniert werden. V1 muss mindestens Codex CLI,
Grok CLI und GitHub Copilot CLI durch den Capability-Doctor prüfen und als `ready`,
`degraded` oder `unavailable` ausweisen. Ein Providerfehler wird nicht stillschweigend
als anderer Providererfolg verbucht; ein Profilwechsel ist eine autorisierte neue
Session mit neuer Slotrevision. Ein Codex-Finalreview ist nur zulässig, wenn Codex den
zu prüfenden Stand nicht implementiert oder gefixt hat.

---

# 22. Harte Eskalationsregeln

Mögliche AI6-spezifische Indikatoren für eine strengere Implementierungs- oder
Review-Stufe:

```text
auth
authentication
authorization
permission
role
security
credential
secret
token
session
migration
encryption
deployment
concurrency
race condition
irreversible
control_branch
deploy_key
git ref
scope
ticket status
approval
run state
instruction snapshot
provider runtime
redaction
retention
process runner
execution mailbox
```

Keywords allein sind nicht ausreichend.

Zusätzlich sollten berücksichtigt werden:

- Pfade;
- Framework- oder Projektdomänen;
- Ticket-Metadaten;
- Diff-Inhalt;
- Anzahl betroffener Komponenten;
- Datenbankänderungen;
- Risiko;
- bisherige Modellfehler;
- Test- und Scannerergebnisse.

Die erste Version darf regelbasiert sein.

Ein eigenes lernendes Routing-System ist zunächst nicht erforderlich.

---

# 23. Orchestrierung und Pipeline-Ausführung

Eine Pipeline-Stufe sollte fachlich mindestens beschreiben:

```yaml
id:
type:
input_contract:
output_contract:
model_role:
prompt_profile:
conditions:
retry_policy:
timeout_policy:
failure_policy:
approval_gate:
```

Im AI6-MVP wird diese Beschreibung auf die vorhandenen Entitäten und Phasen abgebildet:
`RunOrchestrator` führt den Zustand, `run_agents` führt Rollen und Sessions,
`run_events` die Timeline, `review_results` die unveränderlichen Reviews, `findings`
die normalisierte Sicht und `run_artifacts` die gebundenen Roh-/Kontextartefakte. Eine
zusätzliche `PipelineRun`-/`StageRun`-Autorität oder ein zweiter Orchestrator ist nicht
zulässig.

Mögliche Stufentypen:

```text
implementation
deterministic_check
review
finding_normalization
finding_verification
fix
targeted_recheck
final_review
candidate_gate
manual_push_or_automatic_after_gates
status_sync
report
```

Anforderungen:

- Stufen sind über Runphase und Runversion wiederaufnehmbar;
- Wiederholungen erzeugen keine unkontrollierten Duplikate;
- jeder Lauf besitzt eine eindeutige ID;
- Inputs und Outputs sind einem konkreten Stand des Codes zugeordnet;
- abgebrochene Läufe bleiben sichtbar;
- Providerfehler werden von fachlichen Fehlern getrennt;
- Retry-Policies sind begrenzt;
- eine fehlgeschlagene Stufe darf nicht unbemerkt als Erfolg gelten;
- manuelle Eingriffe werden protokolliert;
- Pipelineprofile und Promptprofile sind versioniert und Teil der Approval-/Runbindung;
- Providerstarts laufen nur über den gemeinsamen `ProcessRunner` und die
  `ExecutionMailbox` (`execution_jobs`).

---

# 24. Idempotenz und Reproduzierbarkeit

Codex soll die folgende Reproduzierbarkeitsbindung auf den bestehenden AI6-Vertrag
abbilden:

- `approved_ticket_blob_sha` und `approved_control_sha`;
- `initial_run_base_sha` beziehungsweise aktuelles `run_base_sha`;
- Checkpoint-Tree-OID und Diff-Hash;
- Contract-, Scope-, Config-, Prompt-, Instruction-, Runtime- und SecurityPolicy-Hash;
- Providerprofil, CLI-Version, Modell/Effort und Session-/Slotrevision;
- Kontextpaket- und Artefaktdigests;
- relevante Check-/Buildumgebung.

Ein Finding darf nicht versehentlich auf einen anderen Code-Stand angewendet werden.

Nach Codeänderungen ist zu entscheiden:

- ist das Finding weiterhin gültig;
- muss es erneut geprüft werden;
- wurde es durch den neuen Stand obsolet;
- betrifft es eine unveränderte Stelle.

---

# 25. Kosten-, Token- und Laufzeitsteuerung

Pro Providerturn sollten, soweit verfügbar und vom CLI belastbar gemeldet, gespeichert
werden:

```yaml
provider_profile:
cli_version:
model:
model_role:
prompt_profile:
input_tokens:
cached_input_tokens:
output_tokens:
reported_cost_or_unknown:
cost_source_or_unknown:
duration:
attempt:
cache_hit:
context_artifact:
result_artifact:
```

Pro Ticket bzw. Pipeline:

```yaml
implementation_attempts:
review_runs:
verification_runs:
fix_runs:
escalations:
human_interventions:
total_input_tokens:
total_output_tokens:
total_reported_cost_or_unknown:
final_status:
final_model_mix:
```

Konfigurierbare Budgets:

```yaml
budgets:
  max_reported_cost_per_ticket:
  max_tokens_per_stage:
  max_review_cycles:
  max_verification_cycles:
  max_fix_cycles:
  require_approval_before_budget_overrun:
```

Mögliche Optimierungen:

- Prompt-Caching;
- Wiederverwendung stabiler Projektkontexte;
- diff-basierter Kontext;
- symbolbasierte Codeauswahl;
- kontextabhängiges Nachladen;
- Vermeidung doppelter Reviewer-Aufgaben;
- kompakte strukturierte Outputs;
- keine Roh-Logs im finalen Reviewer-Kontext;
- Überspringen irrelevanter Review-Profile.

---

# 26. Qualitätsmetriken

Langfristig sollte messbar sein:

- Übereinstimmungs- und Human-Disposition-Rate je Review-Profil;
- False-Positive-Rate je Prompt-Profil;
- Anteil menschlich als `not_applicable` disponierter Findings;
- Anteil von Findings, die erst ein optionaler Finalreview entdeckt;
- Schweregradverteilung;
- Fix-Erfolgsrate je Modell;
- durchschnittliche Fix-Schleifen;
- Wiedereröffnungsrate;
- menschliche Override-Rate;
- Rate erfolgreicher `automatic_after_gates`-Pushes;
- Kosten pro abgeschlossenem Ticket, soweit Providerdaten belastbar vorliegen;
- Kosten pro wirksamem Finding, soweit belastbar messbar;
- Zeit bis Candidate, Push und Statussynchronisation;
- Regressionen nach Abschluss;
- Routing-Erfolg von `codex_cli` gegenüber autorisierten Alternativprofilen;
- Nutzen des finalen starken Reviews.

Fehlende Token- oder Kostendaten werden explizit als unbekannt markiert und nicht aus
Textlänge oder Laufzeit erfunden. Ein Kostenwert wird nur mit einer definierten,
auditierbaren CLI- oder Providerquelle übernommen; eine unbestätigte Aussage im
Assistententext ist Provideroutput und kein Metering. Diese Daten sollen später helfen,
Prompt-, Modell- und Routing-Konfigurationen anzupassen.

Automatische Selbstoptimierung ist nicht Bestandteil der ersten Ausbaustufe.

---

# 27. Sicherheits- und Vertrauensgrenzen

LLM-Ausgaben sind nicht vertrauenswürdig und müssen als Vorschläge bzw. strukturierte Eingaben behandelt werden.

Zu berücksichtigen:

- Code, Kommentare, Tickets und externe Inhalte können Prompt-Injection enthalten;
- Reviewer dürfen Projektanweisungen nicht aus untrusted Code-Kommentaren übernehmen;
- Secrets dürfen nicht ungefiltert an Provider gesendet werden;
- Provider- und Projektzugriffe benötigen minimale Berechtigungen;
- Codex-, Grok- und Copilot-Home-/Config-/History-/Cachepfade werden pro Session
  versiegelt und getrennt; die jeweils dokumentierten nativen Discoverypfade werden
  entweder auf den gebundenen Instruction-Snapshot begrenzt oder fail closed blockiert;
- automatisierte Fixes sollen in isolierten Branches oder Worktrees erfolgen;
- keine direkte Produktionseinwirkung ohne explizite Policy;
- Tool-Aufrufe und Dateizugriffe sollen begrenzt sein;
- kritische Befehle benötigen gesonderte Freigabe;
- Modellantworten werden validiert, bevor sie Status oder Daten verändern;
- strukturierte Outputs benötigen Schema-Validierung;
- ein Reviewer darf nicht allein seinen eigenen Output freigeben;
- fehlende Prüfung ist kein bestandener Review.

Codex soll vorhandene Sicherheitsmechanismen weiterverwenden und fehlende Punkte in den Gesamtplan aufnehmen.

---

# 28. Ticket-Schema

Das bestehende `ai6.ticket.v1`-Format und das strengere serverseitige
Validierungsprofil `ai6_detail_v1` aus `TKT-011` bleiben maßgeblich. Die folgenden Laufzeitbegriffe sind eine Zuordnungshilfe, keine Aufforderung,
das Frontmatter oder die zwölf festen Abschnitte um freie Felder zu erweitern:

```yaml
ticket_file:
  schema: ai6.ticket.v1
  id:
  title:
  status:
  depends_on:
  goal:
  files:
  spec_refs:
  acceptance_criteria:
  test_cases:

approval_snapshot:
  implementation_profile:
  reviewer_slots:
  limits:
  prompt_snapshot_hash:
  instruction_snapshot_hash:
  provider_runtime_profile_hash:
  security_policy_hash:

run_projection:
  run_id:
  checkpoint_tree_oid:
  diff_hash:
  review_results:
  findings:
  gates:
  artifact_references:
```

`ticket_file` liegt in Git. `approval_snapshot` gehört zu `ticket_approvals` und
`project_config_snapshots`; `run_projection` gehört zu den Run-/Review-/Finding- und
Artefaktentitäten. `effective_*`-Werte, Kosten, Findings und Approvalresultate werden
nicht als freie Ticketmetadaten persistiert. Eine Änderung am Ticketformat erfordert eine
Plan-/Template-Revision und keine stillschweigende Erweiterung während eines Adapters.

---

# 29. Ticketgröße und Zuschnitt

Tickets sollen so klein sein, dass:

- ein günstigeres Modell die Aufgabe zuverlässig verstehen kann;
- wenige neue Architekturentscheidungen während der Umsetzung nötig sind;
- ein klarer Diff entsteht;
- Tests und Reviews fokussiert bleiben;
- Findings eindeutig zugeordnet werden können;
- Fix-Schleifen nicht mehrere unabhängige Features vermischen.

Nicht sinnvoll:

```text
Implementiere Review-only, drei Provider, Finding-Verifikation, automatische Fixes,
UI, Metriken und Pushpolicy in einem Ticket.
```

Besser:

```text
1. Review-only-Claim und report-only Abschluss-Saga normieren
2. Review-only-Eingang mit FakeAgent und gebundenem Checkpoint umsetzen
3. Grok-CLI-Adapter samt Fake-Binary-Contracttests liefern
4. Copilot-CLI-Adapter samt Fake-Binary-Contracttests liefern
5. Quellenabhängigen advisory Verifier orchestrieren
6. Bedienung und Providerstatus ergänzen
7. Reale Smokes und Pilot als getrennte Gates durchführen
```

Das sind Arbeitsschnitte für die Planrevision, noch keine freigegebenen IDs. Jeder Schnitt
muss nach §13.2 des Plans gegen Modul-, Schema-, Provider- und Rollbackgrenzen geprüft
werden; Sicherheits- und Integrationsabhängigkeiten bleiben im Gesamtplan sichtbar.

---

# 30. Erwartete Anpassung des bestehenden Gesamtplans

Codex soll die neuen Konzepte nicht bloß als Anhang ergänzen.

Sie sollen an den passenden Stellen des bestehenden Plans eingearbeitet werden, beispielsweise in:

- Systemübersicht;
- fachliche Workflows;
- Provider- und Modellabstraktion;
- Ticketmodell;
- Job-/Queue-System;
- Pipeline-Orchestrierung;
- Review-System;
- Persistenz;
- Artefaktspeicherung;
- UI/Admin-Panel;
- CLI;
- Audit-Logging;
- Kostenmessung;
- Sicherheitskonzept;
- Teststrategie;
- Rollout-Plan;
- spätere Erweiterungen.

Codex soll dabei eine **Delta-Analyse** erstellen:

```text
bestehende Komponente
-> benötigte Erweiterung
-> mögliche Wiederverwendung
-> neue Abhängigkeit
-> Migrationsrisiko
```

Für dieses Repository muss die Analyse mindestens diese realen Plan-Nähte abdecken:

| AI6-Vertrag | Verwendung für die Erweiterung |
|---|---|
| `AI6-011` / `CFG-001` / `AGT-008` / `AGT-009` | Agentprofile, Prompt-, Instruction- und Runtime-Snapshots |
| `AI6-012` / `RUN-002` / `REV-001` | Approval, Slotauswahl, Limits und mehrere Reviewer |
| `AI6-013` / `RUN-001` bis `RUN-008` | Runzustand, Projektsperre, Eligibility und Wiederaufnahme |
| `AI6-014` / `GIT-002` bis `GIT-004` / `GIT-010` | Worktree, Checkpoint, Diff und gitmetadatenfreier Export |
| `AI6-015` / `AGT-006` / `AGT-007` | ProcessRunner, ExecutionMailbox, Timeout und Credentialtrennung |
| `AI6-016` / `AGT-004` / `AGT-005` | JSON-Hülle und FakeAgent vor echten Providern |
| `AI6-021` bis `AI6-026` / `REV-002` bis `REV-007` | Checks, Review, Findings, Fix, Limits und Stall |
| `AI6-027` bis `AI6-029` / `REV-008` / `RUN-009` | Candidate, Security-Gate, Commit, Push und Gateevidenz |
| `AI6-033` bis `AI6-035` | Codex-CLI, unveränderte Claude-Basis, weitere CLI-Adapter, Onboarding und Doctor; Claude darf V1 nicht blockieren |

Die Tabelle benennt Zielverträge, nicht bereits vorhandene Klassen. Die Detailableitung
prüft jeden Pfad im dann realen Stand und bleibt innerhalb des jeweiligen initialen
Scopes.

---

# 31. An AI6 angepasste Umsetzungsphasen

Die Reihenfolge folgt den vorhandenen Blueprints und dem progressiven Elaborationsprinzip.
Sie darf bei der Planrevision weiter gesplittet werden, darf aber keine bereits
veröffentlichte ID umwidmen und keine zukünftige Naht als heutigen Code ausgeben.

## Phase 0: Planintegration und Bestandsaufnahme

- integrierten Stand `AI6-001` bis `AI6-006F`, aktuelle Module, Klassen, Tests und
  Containerrollen verifizieren; die nächste progressive Detailableitung beginnt bei
  `AI6-007`;
- die Anforderungen dieses Dokuments gegen `AI6-011` bis `AI6-038` und die Requirement-
  Traceability abgleichen;
- Ticket-/SQLite-Autorität, `RunOrchestrator`, `ProcessRunner`, Worktree-/Checkpoint-
  Bindung und Instruction-/Runtime-Snapshot als harte Leitplanken festhalten;
- für Grok CLI und GitHub Copilot CLI neue, noch nicht vergebene Blueprint-IDs als
  Planrevision vorschlagen; keine `AI6-034`-Umwidmung.

## Phase 1: Gemeinsame AI6-Workflowgrundlage

- `AI6-011` bis `AI6-017`: Profile, Promptvertrag, Approval-/Reviewer-Slots, Run,
  Worktree, ProcessRunner, JSON-Hülle, FakeAgent und Timeline;
- `AI6-018` bis `AI6-022`: HumanLoop, Implementierung, Scope, Checks und Checkpoint-
  Bereitschaft;
- noch keine echten Provider voraussetzen; FakeAgent und Fake-Binaries müssen den
  End-to-End-Vertrag zuerst nachweisen.

## Phase 2: Review-only und Multi-Review

- `AI6-023` bis `AI6-026`: read-only Reviewer-Workspaces, Reviewresultate, Findings,
  AC-Abdeckung, Fixturns, Re-Review, Limits und Stall;
- zusätzlicher, vor Implementierung normativ zu schließender Review-only-Vertrag für
  Approval, Claim, report-only Abschluss, Ticketstatus, Lockfreigabe und Cancel; dieser
  Vertrag darf nicht in `AI6-023` versteckt werden;
- V1-Eingabe nur mit AI6-Ticket-/Approvalbindung aus Managed-Clone, Commit-Range,
  Patch/Diff oder Checkpoint;
- alle ausgewählten Reviewer prüfen seriell denselben neuen Checkpoint.

## Phase 3: Candidate, Gates und vollständiger Fake-Workflow

- `AI6-027` bis `AI6-032`: Finalchecks, Candidate, optionales Security-Gate, Commit/
  Push, Queue/UI sowie vollständige Fake-Recovery-Matrix;
- `push_mode: manual` und offene `MG-`-/`EXT-`-Gates bleiben Standard;
- vor echten Provider-Smokes müssen Prompt-Injection, Gitmetadaten, Limits, Retention,
  Drift und Sessiontrennung mit Fake-Providern grün nachgewiesen sein.

## Phase 4: Echte CLI-Provider

- `AI6-033`: Codex CLI für Implementierung und Fixturns;
- neue Blueprint-ID nach Planrevision: Grok-Build-CLI als Review-/Verifier-Adapter;
- neue Blueprint-ID nach Planrevision: GitHub-Copilot-CLI als unabhängiger Reviewer;
- `AI6-035` wird auf alle drei Providerprofile, getrennte Credential-Stores,
  Capability-Doctor, Version-Pinning und Re-Doctor nach Upgrade erweitert; seine heutige
  Claude-Abhängigkeit wird dabei so revidiert oder aufgespalten, dass die V1 nicht auf
  `AI6-034` warten muss;
- `AI6-034` bleibt Claude als spätere, nicht für die erste Providerstufe erforderliche
  Erweiterung.

Alle drei V1-Adapter verwenden zunächst ihren dokumentierten Headless-Transport: Codex
und Grok mit JSON(L)-Ereignissen, Copilot mit finaler Textantwort im Promptmodus. ACP,
der Copilot-SDK-/Servermodus, persistente Provider-Sessions über Rungrenzen und
parallele Providerprozesse bleiben spätere, getrennt zu prüfende Erweiterungen.

## Phase 5: Pilot und Rollout

- `AI6-036` bis `AI6-038` um die drei V1-CLIs, deren reale Smoke-Tests und sichtbare
  Provider-/Security-Ausfälle ergänzen;
- zuerst Review-only mit manuell bestätigtem report-only Abschluss;
- danach autorisierte Codex-Fixturns und vollständige Re-Reviews;
- automatische Candidate-/Push-Fortsetzung nur hinter der bestehenden Policy und nach
  belastbarer Messung von False Positives, Laufzeit, Kosten und Providerfehlern.

Ein frei konfigurierbarer DAG, parallele Providerprozesse und automatische
Selbstoptimierung bleiben spätere Erweiterungen. V1 ist eine feste, versionierte,
sequenzielle Orchestrator-Konfiguration.

---

# 32. Abbildung auf die bestehenden AI6-Blueprints

Die folgenden Arbeitsbausteine sind nur eine Traceability-Hilfe für den ursprünglichen
Erweiterungsentwurf. `T00` bis `T31` sind **keine** AI6-Ticket-IDs, dürfen nicht als
Tickets angelegt und nicht in Status, Manifest oder `depends_on` verwendet werden. Die
verbindliche Ableitung erfolgt ausschließlich aus `docs/AI6_IMPLEMENTATION_PLAN.md` und
`AI6_TICKET_TEMPLATE_V1.md`; die AI6-IDs sind in Abschnitt 31 und in der nachfolgenden
Zuordnung maßgeblich.

## T00 – Bestehende Architektur und Plan auf Review-Erweiterung analysieren

**Ziel:** Relevante Komponenten, Datenmodelle, Workflows und Lücken dokumentieren.

**Ergebnis:** Mapping zwischen bestehender Architektur und den Konzepten dieses Dokuments.

---

## T01 – Rollen- und Providerprofile erweitern

**Ziel:** AI6-011/AI6-012 um die serverseitig allowlisteten Providerprofile `codex_cli`,
`grok_cli` und `github_copilot_cli` sowie ihre Capability- und Effortbindung ergänzen.

**Abhängigkeit:** T00.

---

## T02 – Pipeline- und Stage-Vertrag definieren

**Ziel:** Einheitliche Inputs, Outputs, Status, Retry- und Fehlerregeln für verkettbare Stufen festlegen.

**Abhängigkeit:** T00.

---

## T03 – Run- und Reviewdaten in vorhandenen Entitäten abbilden

**Ziel:** Ausführung, Wiederaufnahme, Fehlerstatus und Auditierbarkeit in `runs`,
`run_agents`, `review_results`, `findings`, `run_artifacts` und `run_events` abbilden;
keine neuen Pipeline-/Stage-Autoritäten einführen.

**Abhängigkeit:** T02.

---

## T04 – Review-only-Eingaben unterstützen

**Ziel:** Ticket-/Approval-gebundene Branches, Commit-Ranges, Diffs und Checkpoints als
Review-Gegenstand normalisieren und den fehlenden report-only Claim-/Abschlussvertrag
festlegen; freie Arbeitsverzeichnisse, ausgewählte Dateien und PR-URLs bleiben V1-
außerhalb.

**Abhängigkeit:** T02, T03.

---

## T05 – Kontextpaket-Builder implementieren

**Ziel:** Ticket-, Diff-, Code-, Test- und Architekturkontext stufengerecht zusammenstellen.

**Abhängigkeit:** T04.

---

## T06 – Versionierte Review-Prompt-Profile einführen

**Ziel:** Funktionalität, Security, Tests, Architektur und weitere Review-Ziele getrennt konfigurieren.

**Abhängigkeit:** T01, T05.

---

## T07 – Codex-CLI-Reviewpfad anbinden

**Ziel:** Strukturierte Findings aus einem fokussierten Codex-CLI-Reviewprofil erzeugen,
falls der Slot nicht zugleich die Implementierung dieses Runs ausgeführt hat.

**Abhängigkeit:** T06.

---

## T08 – GitHub-/Copilot-Review-Adapter anbinden

**Ziel:** Ergebnisse der GitHub-Copilot-CLI in das gemeinsame AI6-Reviewformat überführen;
kein PR-Connector und keine automatische GitHub-Mutation.

**Abhängigkeit:** T06.

---

## T09 – Finding-Schema und Persistenz einführen

**Ziel:** Findings, Evidenzen, Status und Quellen strukturiert speichern.

**Abhängigkeit:** T03.

---

## T10 – Finding-Normalisierung und Schema-Validierung implementieren

**Ziel:** Modellantworten robust validieren und in ein einheitliches Finding-Format überführen.

**Abhängigkeit:** T07, T08, T09.

---

## T11 – Finding-Deduplizierung und Konsolidierung implementieren

**Ziel:** Ausschließlich exakte deterministische Duplikate nach `AI6-024` verknüpfen,
ohne Originalresultate oder unabhängige Ursachen zu verlieren; keine semantische
LLM-Deduplizierung in V1.

**Abhängigkeit:** T10.

---

## T12 – Finding-Disposition und Auditspur abbilden

**Ziel:** Unveränderliche Reviewresultate, wirksame Dispositionen und Auditspur nach
`REV-006` abbilden, ohne Event Sourcing oder zweite State-Machine.

**Abhängigkeit:** T09.

---

## T13 – Grok-Verifier anbinden

**Ziel:** Findings mit relevantem Kontext über die Grok-Build-CLI kritisch prüfen und
schema-validiert ein eigenes advisory Reviewresultat erzeugen. Es bestätigt oder
widerspricht Evidenz, disponiert das Originalfinding aber nicht wirksam und prüft kein
Grok-Originalfinding mit demselben Profil.

**Abhängigkeit:** T05, T11, T12.

---

## T14 – Begrenzten Challenge-/Evidence-Loop implementieren

**Ziel:** Einen begrenzten, optionalen Evidenznachlauf über HumanLoop und Verifier abbilden;
keine freie Modell-zu-Modell-Debatte.

**Abhängigkeit:** T13.

---

## T15 – Ticket-zentriertes Review-Ledger ergänzen

**Ziel:** Eine aus SQLite/Runartefakten abgeleitete kompakte Sicht bereitstellen, nicht
zusätzliche Ausführungsmetadaten in die Ticketdatei schreiben.

**Abhängigkeit:** T09, T12.

---

## T16 – Rohartefakte getrennt speichern und referenzieren

**Ziel:** Vollständige Modellantworten, Logs und Diffs außerhalb der kompakten Ticketansicht verwalten.

**Abhängigkeit:** T03, T15.

---

## T17 – Implementierungszusammenfassung für Reviewer erzeugen

**Ziel:** Geänderte Komponenten, Annahmen, Entscheidungen, Tests und bekannte Risiken strukturiert dokumentieren.

**Abhängigkeit:** vorhandener Implementierungsworkflow, T15.

---

## T18 – Bestehenden Gate- und Pushvertrag erweitern

**Ziel:** Risikoregeln an Candidate-Gates und die exakten Pushmodi `manual` und
`automatic_after_gates` anbinden; keine zweite Approval-Policy-Engine und keine neuen
Pushmode-Enumnamen einführen.

**Abhängigkeit:** T12, T15.

---

## T19 – Menschliches Approval und Override ergänzen

**Ziel:** Findings, Waiver, Risikoakzeptanz und die vorhandenen Status-Sagas manuell
steuern und auditieren; kein frei übergebener Ticketzielstatus.

**Abhängigkeit:** T18.

---

## T20 – Automatischen Abschlussbericht erzeugen

**Ziel:** Endzustand, Checks, Findings, Fixes, Providerprofile, unbekannte/gelieferte
Nutzungsdaten und Approval in einem gebundenen Runartefakt darstellen.

**Abhängigkeit:** T15, T18.

---

## T21 – Wirksam blockierende Findings in Fix-Aufträge überführen

**Ziel:** Nur wirksam autorisierte `must_fix`-Findings über den RunOrchestrator an einen
Codex-CLI-Fixturn übergeben.

**Abhängigkeit:** T12, T13.

---

## T22 – Gezielten Re-Review nach Fix implementieren

**Ziel:** Nach einem Fix zunächst gezielt prüfen und anschließend den vollständigen
Re-Review aller ausgewählten Reviewer auf dem neuen Checkpoint erzwingen.

**Abhängigkeit:** T21.

---

## T23 – Fix-Loop-Limits und Human-Eskalation ergänzen

**Ziel:** Codex-Fixversuche begrenzen, ein freigegebenes alternatives Profil oder einen
Human Request verwenden und nach weiterem Scheitern sichtbar stoppen.

**Abhängigkeit:** T21, T22, T01.

---

## T24 – Optionalen finalen starken Review implementieren

**Ziel:** Den finalen Gesamtdiff optional durch ein neues, serverseitig freigegebenes
Providerprofil prüfen lassen; kein fest verdrahteter Modellname.

**Abhängigkeit:** T05, T20.

---

## T25 – Review-Pipeline mit bestehendem Implementierungsmodus verketten

**Ziel:** Implementierung, Checks, Reviews, advisory Verifikation, Fixes, Candidate-Gates
und vorhandene Push-/Status-Sagas als durchgängigen Ablauf verbinden.

**Abhängigkeit:** T03, T18, T21, T24.

---

## T26 – Review-only-Bedienung in CLI bzw. UI ergänzen

**Ziel:** Review-Läufe starten, überwachen und fortsetzen.

**Abhängigkeit:** T04, T25.

---

## T27 – Finding- und Approval-Ansicht ergänzen

**Ziel:** Status, Evidenz, Historie, Entscheidungen und offene Aktionen verständlich anzeigen.

**Abhängigkeit:** T12, T19.

---

## T28 – Token-, Kosten- und Budgettracking erweitern

**Ziel:** Belastbar gelieferte Nutzung und Kosten je Orchestratorschritt, Providerprofil,
Ticket und erfolgreichem Abschluss nachvollziehen; fehlende Werte bleiben `unknown`.

**Abhängigkeit:** T03, T01.

---

## T29 – Qualitätsmetriken für Reviewer und Prompts erfassen

**Ziel:** Bestätigungsrate, False Positives, Eskalationen und Final-Review-Mehrwert messen.

**Abhängigkeit:** T12, T24, T28.

---

## T30 – Security- und Prompt-Injection-Härtung

**Ziel:** Untrusted Code/Tickets, Secrets, Toolrechte und strukturierte Outputs absichern.

**Abhängigkeit:** querschnittlich und Bestandteil jedes betroffenen Tickets; kein erst am
Ende hinzufügbarer Härtungsbaustein.

---

## T31 – Migration und schrittweiser Rollout

**Ziel:** Bestehende Tickets und Workflows kompatibel halten und die neue Automation kontrolliert aktivieren.

**Abhängigkeit:** Gesamtintegration.

---

### Verbindliche Delta-Zuordnung der Arbeitsbausteine

| Arbeitsbaustein | Entscheidung für die Planrevision |
|---|---|
| T00 | reine Plan-/Traceability-Arbeit; kein Implementierungsticket |
| T01, T05, T06 | vorhandene Blueprints `AI6-011`, `AI6-012`, `AI6-016` und `AI6-023` um Rollen-, Promptprofil-, Kontext- und Slotbindungen ergänzen; kein zweiter Katalog oder Renderer |
| T02, T03 | auf `AI6-013`, `AI6-017` und die vorhandenen Runentitäten abbilden; keine `PipelineRun`-/`StageRun`-Modelle |
| T04, T26 | eigener Review-only-Arbeitsschnitt nach Planrevision: Ticket-/Approvalbindung, Quellimport, Claim, report-only Abschluss-Saga und Bedienung; nicht als bloßes Extra in `AI6-023` verstecken |
| T07 | `AI6-033` um den Codex-Headless-/Schema-Vertrag und die zulässige Reviewrolle ergänzen; Codex bleibt für selbst implementierte Stände als Reviewer ausgeschlossen |
| T08 | eigener neuer Provideradapter-Blueprint für `github_copilot_cli`; keine Umwidmung von `AI6-034` |
| T09, T10, T12 | vorhandener `AI6-024`-/`REV-003`-bis-`REV-006`-Vertrag; Originale, AC-Abdeckung und autorisierte Dispositionen nicht duplizieren |
| T11 | ausschließlich die bereits in `AI6-024` vorgesehene exakte Duplikatgruppierung; semantische LLM-Deduplizierung bleibt außerhalb V1 |
| T13 | eigener neuer `grok_cli`-Provideradapter plus davon getrennter providerunabhängiger Verifier-Orchestrierungsarbeitsschnitt; der Adapter enthält keine Orchestrierungslogik |
| T14 | an `AI6-018`/`AI6-026` und deren HumanLoop-/Limitvertrag anbinden; V1-Default ohne Modell-zu-Modell-Challenge |
| T15–T17, T20, T27 | vorhandene Projektionen und UI-/Artefaktverträge aus `AI6-017`, `AI6-024`, `AI6-031`; kein Ledger in der Ticketdatei |
| T18, T19 | vorhandene Approval-, Finding-, Gate-, Push- und Status-Sagas aus `AI6-012`, `AI6-024`, `AI6-027` und `AI6-029`; keine zweite Policy-Engine |
| T21–T23 | vorhandene `AI6-025`-/`AI6-026`-Fix- und Re-Review-Verträge, Fixturn über `codex_cli` |
| T24 | optionaler allgemeiner Finalreview ist nicht das Security-Gate aus `AI6-028`; bei Aufnahme eigener kleiner Arbeitsschnitt oder bewusst nach V1 verschieben |
| T25 | vorhandenen `RunOrchestrator` aus `AI6-017` sowie `AI6-025`, `AI6-027` bis `AI6-029` erweitern; kein zweiter Orchestrator |
| T28, T29 | nur belastbare Provider-/CLI-Messwerte in vorhandenen Run-/Agent-/Artefaktverträgen; weitergehende Qualitätsauswertung nach dem Pilot separat schneiden |
| T30 | querschnittlicher Akzeptanzvertrag jedes betroffenen Tickets und Security-Release-Gate; kein spätes Sammel-Härtungsticket |
| T31 | `AI6-032`, revidierter `AI6-035`, `AI6-036` und `AI6-038` für Fake-E2E, Onboarding, Betrieb und realen Pilot |

Neue Grok-/Copilot-IDs, ihre `depends_on`, Scopepfade, Requirement-Refs und
Abnahmekriterien dürfen erst in einer expliziten Planrevision festgelegt werden. Bis
dahin bleiben sie ein Änderungsbedarf dieses Auftrags und keine freigegebenen
Implementierungsaufträge.

---

# 33. Anforderungen an die final von Codex erzeugten Tickets

Codex soll die endgültigen Tickets an das vorhandene Ticketformat anpassen.

Jedes Ticket sollte mindestens beantworten:

```text
Was ist das Ziel?
Warum ist es erforderlich?
Welche bestehende Komponente wird erweitert?
Welche Teile dürfen geändert werden?
Welche Teile sollen nicht neu gebaut werden?
Welche Abhängigkeiten bestehen?
Welche Daten- oder Statusmigration ist nötig?
Welche Akzeptanzkriterien gelten?
Welche Tests sind erforderlich?
Welche Risiken bestehen?
Welche Provider-/Review-Capability muss der spätere Approval-Snapshot auswählen können?
Welche vorhandenen Approval-, HumanLoop-, Gate- und Pushverträge werden berührt?
```

Die Tickets sollen:

- klein;
- einzeln testbar;
- einzeln reviewbar;
- sinnvoll abhängig;
- rückwärtskompatibel planbar;
- ohne unnötige Architekturentscheidungen während der Umsetzung;
- ohne neue Frontmatterfelder für Provider, Reviewprofile oder Pushpolicy

sein.

---

# 34. Form der späteren AI6-Detailtickets

Das frühere Beispiel mit `REVIEW-014` und frei erfundenen YAML-Feldern ist für AI6 nicht
verbindlich und soll nicht kopiert werden. Ein späteres Detailticket muss:

- eine vorhandene oder per Planrevision neu vergebene `AI6-*`-ID verwenden;
- das Frontmatter und die festen englischen Abschnittsüberschriften aus
  `AI6_TICKET_TEMPLATE_V1.md` verwenden, mit deutscher menschlicher Prosa;
- reale `depends_on`, `files`, Scopemarker, Requirement-Refs, AC-/TC-/MG-/EXT-IDs und
  Tests aus dem dann existierenden Code ableiten;
- Providerprofile nur als serverseitig bekannte Referenzen beschreiben, niemals als
  freie Befehle, Flags, Modellnamen oder Credentialwerte;
- die Trennung von Ticketdatei, Approval-Snapshot, Run-/Reviewdaten und Rohartefakten
  ausdrücklich einhalten;
- die drei CLI-Adapter in ihren jeweiligen Tickets einzeln testen und erst danach in
  einem Pilot-/Integrations-Ticket zusammenführen.

Die Struktur eines Tickets ist daher Ergebnis der Planrevision und des echten
Repositorystands, nicht dieses hypothetischen Beispiels.

---

# 35. Offene Entscheidungen, die Codex anhand des Projekts klären soll

Codex soll diese Punkte prüfen und im Plan beantworten oder als explizite Entscheidungen markieren:

1. Welche neuen, noch nicht vergebenen Blueprint-IDs erhalten Grok CLI und GitHub
   Copilot CLI, ohne `AI6-034` umzuwidmen, und wie wird die heutige `AI6-035`-Abhängigkeit
   von Claude für die V1 sauber entkoppelt?
2. Welche konkreten CLI-Versionen, Modelle und Effortwerte werden pro Providerprofil
   serverseitig gepinnt und wie wird der Capability-Doctor bei Upgrades neu ausgeführt?
3. Welche exakten gepinnten Headless-Flags, Provider-Eventschemas und finalen
   Ergebnisextraktoren bestehen den Fake-Binary- und Capability-Doctor-Vertrag; welche
   Kriterien müssten später vor einer separaten ACP-/SDK-Servermodus-Einführung erfüllt
   sein? Insbesondere für Copilot: Weist die gepinnte Version einen nativen
   maschinenlesbaren Ausgabemodus nach, oder bleibt die textbasierte finale Antwort mit
   Adapter-Extraktion der V1-Vertrag?
4. Welche Authentifizierungsform und welcher minimale Credential-Store sind für Codex,
   Grok und Copilot im `agent`-Prozess zulässig?
5. Kann die eingesetzte CLI-Version ihre native Instruction-, Home-, Plugin-, Skill-,
   Hook- und MCP-Autodiscovery tatsächlich auf das AI6-Runtimeprofil begrenzen?
6. Welche der vorhandenen Review-Profile laufen im V1-Standardprofil auf Grok und
   Copilot, und welche brauchen einen eigenen Provider-Snapshot?
7. Ist Codex im Review-only-Modus ein dritter Reviewer oder nur Implementierer/Fixer?
8. Welche Provider liefern belastbare Token-/Kostenwerte; wie wird `unknown` sichtbar
   behandelt, ohne Werte zu schätzen?
9. Welche sichere Reaktion gilt bei Provider-Ausfall: begrenzter Retry, autorisierter
   Slot-/Profilwechsel, Human Request oder Abbruch?
10. Welche CLI- und UI-Oberfläche wird zuerst benötigt, ohne `RUN-004` zu verletzen?
11. Welche V1-Review-only-Eingaben werden als gebundene Managed-Clone-/Checkpointdaten
   freigegeben; bleibt PR-URL-Unterstützung ausdrücklich später?
12. Wann ist ein finaler zusätzlicher Review neben Grok und Copilot wirklich erforderlich?
13. Welche serverseitigen Limits für Provideroutput, Sessions, Fix-/Reviewrunden und
   Artefakte gelten zusätzlich zu den bereits in `RUN-006` vorgesehenen Maxima?
14. Welche konkreten `MG-`-/`EXT-`-Gates bleiben im Pilot offen und welche menschliche
    Evidenz schließt sie?
15. Welcher eigene Claim-/Abschluss- und Ticketstatusvertrag gilt für einen
    Review-only-Lauf ohne Branch-Push, einschließlich Lockfreigabe, Cancel, Crash-Recovery
    und Übergang in einen später getrennt freigegebenen Fixlauf?

---

# 36. Erwartete Ergebnisse von Codex

Nach Verarbeitung dieses Dokuments soll Codex liefern:

## A. Aktualisierten Gesamt-/Integrationsplan

Die neuen Konzepte sind an den passenden Stellen der bestehenden Planung integriert;
die drei CLI-Provider sind als konkrete, getrennte Adapter verankert. `AI6-034` bleibt
unverändert, Grok/Copilot erhalten nur nach Planrevision neue IDs, und die heutige
`AI6-035`-Abhängigkeit von Claude blockiert die V1 nicht.

## B. Architektur-Delta

Für jede betroffene bestehende Komponente:

```text
Ist-Zustand
-> erforderliche Erweiterung
-> Wiederverwendung
-> neue Schnittstellen
-> Persistenzänderung
-> Risiko
```

## C. Endgültige Phasenplanung

Die vorgeschlagenen Phasen wurden an den realen Projektstand angepasst.

## D. Umsetzbare Tickets

Kleine Tickets mit:

- Abhängigkeiten;
- Akzeptanzkriterien;
- Risiko;
- den betroffenen Provider-/Review-Capability-Verträgen in Tasks und Tests;
- den einschlägigen Approval-, HumanLoop-, Gate- und Pushanforderungen über `spec_refs`.

Die Tickets sind AI6-Detailtickets nach Template, nicht `T00`-bis-`T31`-Dateien. Ihre
Scopepfade werden am realen Stand neu abgeleitet.

## E. Migrations- und Kompatibilitätsplan

Bestehende Workflows und Tickets bleiben funktionsfähig oder erhalten eine klare Migration.

## F. Offene Entscheidungen

Nur Punkte, die aus Code und bestehender Planung nicht zuverlässig entschieden werden können.

## G. Rollout-Vorschlag

Bevorzugt:

1. Review-only zunächst mit manuell bestätigtem report-only Abschluss;
2. Qualität und False Positives messen;
3. Finding-Verifikation aktivieren;
4. Fix-Schleifen aktivieren;
5. `automatic_after_gates` erst nach Pilotmessung und nur dort zulassen, wo die
   serverseitige Risikopolicy nicht auf `manual` verengt;
6. finalen starken Review gezielt zuschalten;
7. Routing und Prompts anhand realer Daten optimieren.

Die Schritte setzen die menschliche `todo → ready`-Approval und die gebundenen
`MG-`-/`EXT-`-Gates nicht außer Kraft. Providerqualität und Kosten werden zunächst
beobachtet; eine automatische Selbstoptimierung gehört nicht zum V1-Rollout.

---

# 37. Abnahmekriterien für die Planintegration

Die Planerweiterung gilt als vollständig, wenn:

- Review-only als eigenständiger Modus beschrieben ist;
- Review-only ticket-/approvalgebunden bleibt und sein Claim-/report-only
  Abschlussvertrag einschließlich Ticketstatus, Lockfreigabe, Cancel und Recovery
  normativ geschlossen ist;
- Review-Stufen mit dem Implementierungsworkflow verkettet werden können;
- Providerrollen und Modelle nur über serverseitig bekannte Profile konfigurierbar sind;
- Codex CLI als Implementierungs- und Fixadapter eingeplant ist;
- Grok CLI als unabhängiger Review-/Verifieradapter eingeplant ist;
- GitHub Copilot CLI als unabhängiger Reviewadapter eingeplant ist;
- alle drei Provider im nicht-interaktiven CLI-Modus über `ProcessRunner` und
  `AgentAdapter` laufen;
- jeder V1-Adapter genau einen gepinnten, vom Doctor nachgewiesenen Headless-Transport
  verwendet und weder ACP noch einen SDK-/Servermodus voraussetzt;
- FakeAgent/Fake-Binaries den gemeinsamen Vertrag vor echten Provider-Smokes abdecken;
- provider-native Home-/Instruction-/MCP-/Plugin-/Skill-/Hook-Autodiscovery nur aus
  gebundenen Runtimeprofilen zugelassen ist;
- die erste Version keine direkte OpenAI-/xAI-/Anthropic-API und keine PR-Mutation
  voraussetzt;
- spezialisierte Erst-Reviews denselben unveränderlichen Checkpoint prüfen;
- Findings strukturiert gespeichert werden;
- nur exakte deterministische Duplikatgruppen V1-Bestandteil sind und Verifikation als
  eigenes unveränderliches advisory Reviewresultat beschrieben ist;
- das Erst-Review-Modell nicht allein über sein eigenes Finding entscheidet;
- der Verifierslot quellenabhängig gewählt wird und kein eigenes Originalfinding
  automatisch entblockt;
- die Pushmodi exakt `manual` und `automatic_after_gates` bleiben und Risikoregeln nur
  auf `manual` verengen können;
- das Panel eine ticketzentrierte kompakte Sicht aus Git- und SQLite-Autoritäten ableitet;
- Rohartefakte separat gespeichert werden;
- Fix- und Re-Review-Schleifen begrenzt sind;
- ein optionaler unabhängiger finaler Review beschrieben ist;
- der finale Reviewer den finalen Gesamtdiff prüfen kann;
- automatische Tests und statische Prüfungen eingebunden sind;
- Kosten, Token, Schleifen und Modellentscheidungen auditierbar sind;
- Security- und Prompt-Injection-Risiken berücksichtigt sind;
- Rückwärtskompatibilität und Migration eingeplant sind;
- die Git-/SQLite-Autoritätsgrenze und die bestehenden AI6-Blueprint-Abhängigkeiten
  eingehalten sind;
- daraus kleine, realistisch implementierbare Tickets erzeugt werden können.

---

# 38. Schlussprinzip

Die gewünschte Qualität soll aus einer kontrollierten Kette entstehen:

```text
gute Planung
+ kleine Tickets
+ geeignetes Providerprofil (Codex CLI für Implementierung/Fix)
+ deterministische Checks
+ fokussierte Reviews über Grok CLI und GitHub Copilot CLI
+ unabhängige Finding-Verifikation
+ begrenzte Fix-Schleifen
+ risikobasierte menschliche Kontrolle
+ optionaler starker Final-Review
+ vollständige Nachvollziehbarkeit
```

Nicht jedes Ticket benötigt denselben Provider oder dasselbe Modell.

Nicht jedes Finding benötigt einen Menschen.

Nicht jeder Review benötigt den gesamten Projektkontext.

Aber jede automatische Entscheidung muss:

- begründet;
- reproduzierbar;
- begrenzt;
- überprüfbar;
- und bei erhöhtem Risiko eskalierbar

sein.

Codex soll diese Zielsetzung in die vorhandene Projektarchitektur übersetzen, den Gesamtplan entsprechend verfeinern und daraus die endgültige Ticketstruktur ableiten.
