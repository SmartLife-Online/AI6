# Erweiterungsauftrag an Codex: Ticket-zentrierte Implementierungs- und Multi-Review-Pipelines

> **Status:** Planungs- und Integrationsvorgabe  
> **Zielsystem:** bestehendes AI6-Softwareentwicklungs- und Automatisierungstool  
> **Verwendung:** Diese Datei in Codex zusammen mit dem aktuellen Repository und dem bestehenden Gesamt-/Integrationsplan öffnen.  
> **Wichtig:** Dieses Dokument ist keine fertige technische Spezifikation für eine Neuentwicklung. Codex soll die Konzepte prüfen, an die vorhandene Architektur anpassen, in den bestehenden Gesamtplan integrieren und daraus anschließend kleine, umsetzbare Tickets ableiten.

---

## 0. Verhältnis zur vorherigen Planungsdatei

Dieses Dokument **ersetzt und erweitert** die bisherige Datei:

```text
AI6_modellrouting_ticket_automatisierung.md
```

Die wesentlichen Inhalte der bisherigen Datei sind hier integriert:

- starke Modelle für Architektur, Planung und Ticket-Erstellung;
- GPT-5.6 Luna als bevorzugtes Standardmodell für Routine-Implementierungen;
- GPT-5.6 Terra als primäre Eskalationsstufe;
- Sol/Opus nur für begründete Ausnahmefälle bei der Implementierung;
- unabhängige Code-Reviews;
- regel- und risikobasiertes Modell-Routing;
- kleine, klar geschnittene Tickets;
- automatische Checks vor LLM-Reviews;
- Fix-/Review-Schleifen;
- manuelle Modellüberschreibung;
- Kosten-, Token- und Audit-Tracking.

Neu hinzu kommen insbesondere:

- ein eigenständiger **Review-only-Modus**;
- frei verkettbare Implementierungs-, Review-, Verifikations-, Fix- und Freigabestufen;
- mehrere spezialisierte Review-Prompts statt eines einzigen allgemeinen Reviews;
- günstige Erst-Reviews durch schwächere Modelle;
- ein kritischer Verifier für die Validierung von Findings;
- ein strukturierter Finding-Lifecycle;
- risikobasierte menschliche Freigaben;
- ein Ticket-zentriertes Review-Ledger;
- kompakte, modellgerechte Kontextpakete;
- ein optionaler finaler Review mit einem besonders starken Modell;
- klare Regeln gegen unkontrollierte Modell-Debatten und Token-Verbrauch.

Codex soll daher bevorzugt **dieses Dokument als aktuelle Gesamtvorgabe** verwenden. Die alte Datei muss nicht zusätzlich in den Gesamtplan kopiert werden.

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
        +--> Luna für Standard-Implementierung
        +--> Terra für komplexe oder riskante Implementierung
        +--> Sol/Opus nur in begründeten Ausnahmefällen
        |
        v
Deterministische Prüfungen
        |
        v
Günstige spezialisierte Erst-Reviews
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
Risikobasierte Freigabe
        |
        +--> automatisch
        +--> menschlich
        |
        v
Optionaler finaler Review mit starkem Modell
        |
        v
Abschlussbericht im Ticket
```

Neben diesem vollständigen Ablauf soll derselbe Review-Unterbau auch unabhängig verwendet werden können:

```text
Vorhandener Branch / Commit / Diff / Pull Request
        |
        v
Review-only-Pipeline
        |
        v
Findings, Verifikation, Freigabe und Abschlussbericht
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
- günstige Modelle erledigen Routine-Implementierungen und fokussierte Erst-Reviews;
- deterministische Werkzeuge übernehmen alles, was nicht sinnvoll von einem LLM beurteilt werden muss;
- Findings werden nicht blind übernommen, sondern kritisch verifiziert;
- menschliche Freigaben werden dort verlangt, wo das Risiko dies rechtfertigt;
- einfache Tickets dürfen weitgehend automatisch durchlaufen;
- alle Entscheidungen bleiben nachvollziehbar und auditierbar.

---

# 4. Architekturprinzipien

## 4.1 Ticket als zentrale fachliche Wahrheit

Das Ticket ist die zentrale fachliche Einheit für:

- Ziel;
- Kontext;
- Scope;
- Nichtziele;
- Akzeptanzkriterien;
- Risiken;
- Modell-Empfehlungen;
- Implementierungszusammenfassung;
- Review-Ergebnisse;
- Finding-Entscheidungen;
- Fixes;
- Freigaben;
- Abschlussstatus.

Das Ticket soll jedoch **nicht** zu einem unstrukturierten Speicher aller Roh-Logs werden.

Daher ist zu trennen zwischen:

### Stabiler Ticket-Spezifikation

- Ziel;
- fachlicher Kontext;
- Scope;
- Out of Scope;
- Akzeptanzkriterien;
- technische Leitplanken;
- relevante Architekturentscheidungen;
- Risiko- und Kritikalitätseinstufung;
- Definition of Done.

### Kompaktem Arbeits- und Review-Ledger

- aktueller Implementierungsstand;
- wesentliche Umsetzungsentscheidungen;
- Abweichungen vom Plan;
- bestätigte Findings;
- verworfene Findings mit kurzer Begründung;
- Fix-Status;
- Review- und Freigabestatus;
- Verweise auf detaillierte Artefakte.

### Separaten Rohartefakten

- vollständige Modellantworten;
- vollständige Logs;
- Testausgaben;
- komplette Diff-Snapshots;
- umfangreiche Reviewer-Diskussionen;
- Provider-Metadaten.

Die Rohartefakte sollen referenzierbar sein, aber nicht bei jedem Modellaufruf vollständig in den Kontext geladen werden.

---

## 4.2 Findings sind strukturierte Entitäten

Ein Finding ist kein unstrukturierter Textblock, sondern eine fachliche Entität mit:

- Identität;
- Quelle;
- Kategorie;
- Schweregrad;
- Konfidenz;
- Beleg;
- Status;
- Entscheidungshistorie;
- Fix-Bezug;
- Verifikationsstatus.

Dadurch können Findings:

- dedupliziert;
- verglichen;
- angefochten;
- bestätigt;
- verworfen;
- herabgestuft;
- gefixt;
- erneut geprüft;
- wieder geöffnet;
- freigegeben oder bewusst akzeptiert

werden.

---

## 4.3 Implementierer und Reviewer bleiben unabhängig

Das Modell, das eine Änderung implementiert, darf nicht allein über die Qualität seiner eigenen Arbeit entscheiden.

Ebenso darf ein Modell, das ein Finding erzeugt hat, dieses nicht ohne unabhängige Prüfung endgültig löschen.

Ein initiales Review-Modell darf:

- ein Finding vorschlagen;
- zusätzliche Belege nachreichen;
- auf Kritik antworten;
- seine Einschätzung korrigieren.

Die endgültige Entscheidung soll jedoch abhängig von Risiko und Policy durch:

- ein unabhängiges Verifier-Modell;
- ein stärkeres Review-Modell;
- oder einen Menschen

getroffen werden.

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

---

## 4.5 Kontext wird stufengerecht minimiert

Jede Pipeline-Stufe erhält nur den Kontext, den sie für ihre Aufgabe benötigt.

Beispiele:

- Ein Security-Reviewer benötigt nicht zwangsläufig alle UI-Dateien.
- Ein Test-Reviewer benötigt Ticket, Diff, bestehende Tests und Teststrategie.
- Ein Finding-Verifier benötigt das Finding, die Belege, relevanten Code, Ticketanforderungen und gegebenenfalls angrenzenden Kontext.
- Ein Fix-Modell benötigt bestätigte Findings, Zielcode, relevante Architekturregeln und Tests.
- Ein finaler starker Reviewer erhält den finalen Gesamtdiff und die Spezifikation, aber nicht sämtliche Rohdiskussionen vorheriger Modelle.

Das System soll Kontext bei Bedarf gezielt erweitern können, statt vorsorglich das gesamte Repository zu senden.

---

## 4.6 Modell- und Provider-Namen bleiben konfigurierbar

Die in diesem Dokument verwendeten Namen sind vorgesehene Rollen bzw. aktuelle Arbeitsbezeichnungen:

- GPT-5.6 Luna;
- GPT-5.6 Terra;
- GPT-5.6 Sol;
- Claude Opus 5;
- Claude Fable 5;
- Grok 4.5;
- GitHub-/Copilot-Code-Review-Modell.

Codex soll prüfen, wie diese Modelle im bestehenden Tool tatsächlich adressiert werden.

Im Code sollen möglichst Rollen oder konfigurierbare Aliase verwendet werden, zum Beispiel:

```yaml
model_roles:
  planning.primary: opus-5
  implementation.default: gpt-5.6-luna
  implementation.escalated: gpt-5.6-terra
  review.initial.general: gpt-5.6-luna
  review.initial.copilot: github-copilot-review
  review.verifier: grok-4.5
  review.final: opus-5
```

Provider-spezifische Modellnamen, Versionen und Endpunkte dürfen nicht unnötig über den Code verteilt werden.

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
7. bestätigte Findings beheben;
8. gezielt erneut prüfen;
9. Freigabe durchführen;
10. Abschlussbericht erzeugen.

---

## 5.2 Review-only-Modus

Der Review-only-Modus verändert zunächst keinen Code.

Mögliche Eingaben:

- Branch gegen Basis-Branch;
- Commit-Range;
- einzelner Commit;
- Pull Request;
- Patch/Diff;
- Arbeitsverzeichnis;
- vom Benutzer ausgewählte Dateien;
- Ticket plus vorhandene Implementierung.

Der Modus soll dieselben Review-, Finding-, Verifikations- und Approval-Komponenten verwenden wie der vollständige Implementierungsworkflow.

Mögliche Ausgaben:

- strukturierte Findings;
- verifizierte Findings;
- Review-Bericht;
- Freigabeentscheidung;
- optional ein erzeugter Fix-Auftrag;
- optional Übergang in den Fix-/Implementierungsmodus.

---

## 5.3 Verketteter Modus

Die einzelnen Funktionen sollen zu einer Pipeline verkettet werden können.

Beispiel:

```text
implement
-> deterministic_checks
-> review:functionality
-> review:security
-> review:tests
-> normalize_findings
-> verify_findings
-> approval
-> fix_confirmed_findings
-> targeted_recheck
-> final_review
-> close_ticket
```

Die erste Version darf sequenziell arbeiten.

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

# 6. Modellrollen

## 6.1 Planung, Architektur und Ticket-Erstellung

Geeignete starke Modelle, beispielsweise:

- Claude Opus 5;
- Claude Fable 5;
- GPT-5.6 Sol.

Aufgaben:

- Architektur;
- Gesamtplanung;
- Risikoanalyse;
- Zerlegung in kleine Tickets;
- Definition der Akzeptanzkriterien;
- Festlegung technischer Leitplanken;
- Modell-Empfehlung;
- Auswahl geeigneter Review-Profile;
- Festlegung der Freigabe-Policy.

Diese Modelle sollen nicht automatisch auch jede Umsetzung übernehmen.

---

## 6.2 Standard-Implementierung: Luna

GPT-5.6 Luna ist das bevorzugte Standardmodell für:

- kleine, klar abgegrenzte Features;
- einfache bis mittlere Business-Logik;
- UI-Anpassungen;
- CRUD-Funktionen;
- kleine API-Erweiterungen;
- klar beschriebene Bugfixes;
- zusätzliche Tests;
- lokale Refactorings;
- Änderungen mit eindeutigen Akzeptanzkriterien.

Default:

```yaml
recommended_implementation_model: gpt-5.6-luna
```

---

## 6.3 Eskalierte Implementierung: Terra

GPT-5.6 Terra ist die primäre Eskalationsstufe für:

- komplexe Business-Logik;
- mehrere gekoppelte Subsysteme;
- anspruchsvolle Datenflüsse;
- größere Refactorings;
- Authentifizierung;
- Autorisierung;
- Rollen und Berechtigungen;
- Security;
- komplexe Migrationen;
- Concurrency und Race Conditions;
- kritische externe Integrationen;
- hohe Seiteneffekt- oder Regressionsgefahr;
- wiederholtes Scheitern von Luna.

Bevorzugter Pfad:

```text
Luna -> Terra
```

---

## 6.4 Sol und Opus für Implementierung

Sol oder Opus sollen nur in begründeten Ausnahmefällen zur Implementierung eingesetzt werden, zum Beispiel:

- besonders schwierige Architekturänderung;
- außergewöhnlich komplexer Fehler;
- sicherheitskritische Kernkomponente;
- wiederholtes Scheitern von Terra;
- sehr hohe Auswirkungen einer Fehlimplementierung.

Nach wiederholtem Terra-Scheitern soll der Workflow normalerweise zunächst stoppen und menschliche Aufmerksamkeit anfordern, statt automatisch unbegrenzt auf teurere Modelle umzuschalten.

---

## 6.5 Günstige Erst-Reviewer

Für kostengünstige Erst-Reviews können unter anderem verwendet werden:

- GPT-5.6 Luna;
- ein GitHub-/Copilot-Code-Review-Modell;
- weitere konfigurierbare günstige Modelle.

Diese Modelle sollen nicht nur denselben allgemeinen Prompt mehrfach ausführen.

Stattdessen sollen sie fokussierte Aufgaben erhalten, damit:

- die Ergebnisse vergleichbarer werden;
- weniger Token verschwendet werden;
- Findings einen klaren Zweck besitzen;
- Überschneidungen leichter dedupliziert werden können.

---

## 6.6 Finding-Verifier

Als mittleres, kritisch prüfendes Modell ist beispielsweise Grok 4.5 vorgesehen.

Der Verifier soll nicht zwangsläufig den gesamten Review erneut von null durchführen.

Seine primäre Aufgabe:

- Finding verstehen;
- Belege prüfen;
- relevante Ticketanforderung prüfen;
- Gegenargumente prüfen;
- fehlenden Kontext anfordern;
- Finding bestätigen, verwerfen, herabstufen oder als unklar markieren.

Für schwierige oder hochkritische Fälle kann eine weitere Eskalation erfolgen.

---

## 6.7 Finaler starker Reviewer

Ein optionaler finaler Review kann beispielsweise mit Claude Opus 5 erfolgen.

Dieser Schritt soll nicht für jedes Ticket zwingend sein.

Geeignete Fälle:

- hohes Ticket-Risiko;
- zentrale Architekturänderung;
- sicherheitsrelevante Änderung;
- Release-Gate;
- viele vorherige Findings;
- wiederholte Fix-Schleifen;
- manuell angeforderte Tiefenprüfung.

### Präzisierung gegenüber der früheren Planung

Der finale starke Reviewer soll nicht nur bereits bekannte Findings beurteilen.

Für einen echten unabhängigen Review sollte er mindestens erhalten:

- finale Ticket-Spezifikation;
- relevante Architekturregeln;
- finalen kumulativen Diff gegen die definierte Basis;
- relevante Tests und Prüfergebnisse;
- kompakte Implementierungszusammenfassung;
- offene oder bewusst akzeptierte Risiken.

Er soll **nicht** automatisch alle vollständigen Rohantworten, Debatten und verworfenen Findings erhalten.

Optional kann der finale Review zweistufig arbeiten:

1. **blinder unabhängiger Review** ohne vorherige Finding-Urteile;
2. anschließende **Reconciliation** mit dem kompakten Review-Ledger.

So bleibt die Prüfung unabhängig, während unnötiger Kontextverbrauch und Bestätigungsbias reduziert werden.

---

# 7. Spezialisierte Review-Profile

Review-Prompts sollen als versionierte Profile verwaltet werden.

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
- Mandantentrennung;
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
review_profiles:
  - ticket_compliance
  - functional_correctness
  - tests

conditional_profiles:
  security:
    when:
      security_relevant: true
  database_migration:
    when:
      affected_paths:
        - "database/migrations/**"
```

Codex soll dieses Konzept an die vorhandene Konfigurationsstruktur anpassen.

---

# 9. Finding-Datenmodell

Das endgültige Schema ist an das Projekt anzupassen.

Mindestens folgende Informationen sollten abbildbar sein:

```yaml
id:
ticket_id:
pipeline_run_id:
review_run_id:
source_stage:
reviewer_model:
reviewer_provider:
prompt_profile:
prompt_version:

title:
category:
severity:
confidence:
description:
violated_requirement:
impact:
reproduction_or_failure_scenario:

evidence:
  - file:
    start_line:
    end_line:
    symbol:
    explanation:
    snippet_hash:

suggested_fix:
status:
decision:
decision_reason:
decision_by:
decision_model:
decision_at:

fix_reference:
verification_reference:
related_findings:
duplicate_of:
supersedes:

created_at:
updated_at:
```

Nicht jedes Feld muss zwingend in einer einzelnen Datenbanktabelle liegen. Bestehende Event-, JSON- oder Artefaktstrukturen können weiterverwendet werden.

---

# 10. Finding-Lifecycle

Empfohlene fachliche Zustände:

```text
proposed
    |
    +--> challenged
    |       |
    |       +--> awaiting_evidence
    |       |
    |       +--> confirmed
    |       +--> downgraded
    |       +--> rejected
    |       +--> inconclusive
    |
    +--> confirmed
    +--> rejected
    +--> duplicate
    +--> superseded
```

Nach Bestätigung:

```text
confirmed
    |
    +--> fix_pending
    +--> accepted_risk
    +--> waived
    |
    v
fixed
    |
    +--> fix_verified
    +--> reopened
```

Codex soll prüfen, ob weniger Zustände für die erste Version ausreichen.

Wesentliche Anforderungen:

- Statusänderungen sind nachvollziehbar;
- Entscheidungen enthalten eine Begründung;
- ein Finding wird nicht physisch gelöscht, nur weil es verworfen wurde;
- Deduplizierung bleibt nachvollziehbar;
- ein behobenes Finding kann bei einer Regression wieder geöffnet werden;
- Schweregrad und Entscheidung sind getrennte Konzepte;
- eine bewusste Risikoakzeptanz wird explizit dokumentiert.

---

# 11. Challenge- und Verifikationsprozess

Der gewünschte Prozess für günstige Erst-Reviews lautet beispielsweise:

```text
Luna / Copilot Review
        |
        v
proposed finding
        |
        v
Grok-Verifier prüft kritisch
        |
        +--> ausreichend belegt ----------> confirmed
        |
        +--> offensichtlich falsch --------> rejected
        |
        +--> teilweise richtig ------------> downgraded / amended
        |
        +--> Beleg unzureichend ------------> challenged
                                                |
                                                v
                                    Erst-Reviewer reicht Beleg nach
                                                |
                                                v
                                    Verifier entscheidet erneut
```

Regeln:

1. Das Erst-Review-Modell darf auf Kritik antworten und Belege nachreichen.
2. Das Erst-Review-Modell darf sein Finding korrigieren oder zurückziehen.
3. Es darf jedoch nicht allein die endgültige Löschung bzw. Ablehnung beschließen.
4. Der Verifier trifft die Entscheidung im Rahmen seiner Policy.
5. Für `high` oder `critical` kann zusätzlich ein Mensch oder ein stärkeres Modell erforderlich sein.
6. Die Anzahl der Challenge-Runden ist begrenzt.
7. Nach Erreichen des Limits wird das Finding als `inconclusive` markiert oder eskaliert.
8. Modelle sollen nicht in eine freie, unstrukturierte Debatte geschickt werden.
9. Jede Runde muss ein konkretes strukturiertes Ergebnis liefern.
10. Ein Finding ohne überprüfbaren Code-, Ticket- oder Testbezug soll nicht automatisch bestätigt werden.

Beispiel:

```yaml
verification_policy:
  max_challenge_cycles: 1
  auto_confirm_max_severity: medium
  require_human_for:
    - critical
  escalate_inconclusive_from_severity: high
```

---

# 12. Deduplizierung und Konsolidierung

Mehrere Review-Profile oder Modelle können dasselbe Problem melden.

Vor der Verifikation soll eine Normalisierung und Deduplizierung stattfinden.

Kriterien können sein:

- gleiche Datei und ähnliche Zeilen;
- gleiche betroffene Funktion oder Klasse;
- gleiche verletzte Anforderung;
- semantisch gleiches Fehlerszenario;
- identischer Testfehler;
- gleiches vorgeschlagenes Ergebnis.

Die Konsolidierung darf unterschiedliche Ursachen nicht vorschnell zusammenwerfen.

Das System soll:

- ein primäres Finding festlegen;
- weitere Meldungen als unterstützende Quellen verknüpfen;
- abweichende Severity- oder Confidence-Werte speichern;
- dem Verifier alle relevanten unabhängigen Belege zeigen.

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

# 14. Review-Ledger im Ticket

Das Ticket soll eine kompakte, aktuelle Sicht enthalten.

Beispiel:

```yaml
execution_summary:
  implementation_model: gpt-5.6-luna
  implementation_attempts: 1
  current_revision: "<commit-or-artifact-ref>"
  checks_status: passed

review_summary:
  pipeline: standard_feature_review_v1
  proposed_findings: 7
  confirmed_findings: 3
  rejected_findings: 3
  duplicate_findings: 1
  open_findings: 0
  final_review_status: passed

approval_summary:
  policy: risk_based
  result: auto_approved
  reason: >
    Keine High- oder Critical-Findings, keine sensiblen Pfade,
    alle bestätigten Findings behoben und verifiziert.
```

Ein Mensch und ein nachfolgendes LLM sollen aus dem Ticket schnell erkennen können:

- was umgesetzt wurde;
- welche Risiken bestanden;
- was geprüft wurde;
- welche Findings bestätigt wurden;
- was behoben wurde;
- was verworfen wurde und warum;
- wer oder was freigegeben hat;
- wo die vollständigen Artefakte liegen.

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

- bestätigtes Finding;
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

Kontextpakete sollten versionierbar bzw. über Hashes einem konkreten Review-Lauf zugeordnet werden können.

---

# 16. Fix- und Re-Review-Schleife

Bestätigte Findings können in Fix-Aufträge überführt werden.

Empfohlener Ablauf:

```text
confirmed findings
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
- Ein Fix-Modell erhält nur bestätigte bzw. explizit freigegebene Findings.
- Verworfenes Feedback darf nicht versehentlich umgesetzt werden.
- Nach einem Fix soll zunächst gezielt das Finding geprüft werden.
- Zusätzlich sollen betroffene Tests und relevante Regression-Checks laufen.
- Nach größeren Fixes kann ein breiterer Review notwendig sein.
- Schleifenlimits sind konfigurierbar.
- Wiederholtes Scheitern führt zu Modell- oder Human-Eskalation.
- Alle Versuche und Änderungen bleiben nachvollziehbar.

Beispiel:

```yaml
fix_policy:
  default_model: gpt-5.6-luna
  escalated_model: gpt-5.6-terra
  max_luna_fix_cycles: 2
  max_terra_fix_cycles: 1
  stop_after_escalated_failure: true
```

---

# 17. Approval-Policy

Die Freigabe soll nicht nur als globales Ja/Nein konfiguriert werden.

Mögliche Modi:

## 17.1 `manual_all`

Jedes Ticket benötigt eine menschliche Abschlussfreigabe.

Geeignet für:

- frühe Einführungsphase;
- kritische Projekte;
- unbekannte Review-Qualität;
- Compliance-Anforderungen.

## 17.2 `risk_based`

Einfache Tickets können automatisch freigegeben werden.

Menschliche Freigabe wird durch definierte Risiken ausgelöst.

Dies ist der bevorzugte langfristige Standard.

## 17.3 `auto_verified`

Automatische Freigabe nur, wenn:

- alle vorgeschriebenen Checks bestanden sind;
- keine offenen Findings bestehen;
- alle Findings verifiziert wurden;
- keine Human-Trigger aktiv sind;
- alle Fixes verifiziert sind;
- der Pipeline-Lauf vollständig und fehlerfrei ist.

## 17.4 `auto_noncritical`

Automatische Freigabe für unkritische Tickets; High-/Critical-Fälle bleiben manuell.

## 17.5 `manual_on_request`

Automatischer Ablauf, solange Benutzer oder Policy kein manuelles Gate setzt.

Eine vollständig automatische Freigabe für kritische Änderungen sollte nicht der Default sein.

---

# 18. Human-Approval-Trigger

Mögliche Trigger:

- Ticket-Risiko `high` oder `critical`;
- Finding `high` oder `critical`;
- Authentifizierung;
- Autorisierung;
- Rollen/Berechtigungen;
- Payments/Billing;
- Secrets/Credentials;
- Verschlüsselung;
- Datenmigration;
- irreversible Datenänderung;
- Deployment-/Produktionslogik;
- Mandantentrennung;
- Security-relevante Pfade;
- viele betroffene Subsysteme;
- hohe Diff-Größe;
- wiederholte Fix-Schleifen;
- inconclusive Finding;
- bewusste Risikoakzeptanz;
- Reviewer widersprechen sich;
- finale Checks konnten nicht ausgeführt werden;
- manueller Override;
- unbekannter Tickettyp;
- nicht unterstützte Sprache oder Technologie.

Beispiel:

```yaml
approval_policy:
  mode: risk_based
  require_human_when:
    ticket_risk:
      - high
      - critical
    finding_severity:
      - critical
    flags:
      - security_relevant
      - irreversible_data_change
      - production_deployment_change
    max_fix_cycles_exceeded: true
    inconclusive_findings: true
```

---

# 19. Automatische Freigabe

Eine automatische Freigabe soll nur erfolgen, wenn alle festgelegten Voraussetzungen explizit erfüllt sind.

Beispiel:

```yaml
auto_approval_requirements:
  deterministic_checks: passed
  required_review_profiles: completed
  open_confirmed_findings: 0
  unresolved_high_or_critical_findings: 0
  inconclusive_findings: 0
  fix_verification: passed
  pipeline_errors: 0
  human_trigger_active: false
```

Auch bei automatischer Freigabe wird ein finaler Report erzeugt.

Der Entwickler soll das Endergebnis später nachvollziehen können, ohne sämtliche Einzelaufrufe lesen zu müssen.

---

# 20. Modell-Routing für Implementierung

Jedes Ticket soll mindestens enthalten:

```yaml
risk_level:
complexity:
security_relevant:
recommended_implementation_model:
model_reason:
```

Beim Start wird zusätzlich bestimmt:

```yaml
effective_implementation_model:
model_selection_reason:
```

Die Empfehlung kann überschrieben werden durch:

- harte Sicherheitsregeln;
- betroffene Pfade;
- Tickettyp;
- vorherige Fehlversuche;
- Benutzerentscheidung;
- Kosten-/Budgetregeln;
- Verfügbarkeit eines Providers;
- Projektkonfiguration.

Beispiel:

```text
recommended = Luna
security_relevant = true
=> effective = Terra
```

---

# 21. Modell-Routing für Reviews

Review-Routing soll separat vom Implementierungs-Routing konfigurierbar sein.

Beispiel:

```yaml
review_routing:
  initial:
    ticket_compliance:
      model: gpt-5.6-luna
    functional_correctness:
      model: gpt-5.6-luna
    secondary_general:
      model: github-copilot-review
    security:
      model: gpt-5.6-terra

  verifier:
    default_model: grok-4.5
    escalate_high_to: opus-5
    escalate_critical_to: human

  final:
    enabled_when:
      - ticket_risk_high
      - release_gate
      - manual_request
    model: opus-5
```

Codex soll prüfen, ob vorhandene Provider- und Modellabstraktionen dafür ausreichen.

---

# 22. Harte Eskalationsregeln

Mögliche Indikatoren für eine stärkere Implementierungs- oder Review-Stufe:

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
payment
billing
encryption
deployment
production
tenant
concurrency
race condition
irreversible
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

Mögliche Stufentypen:

```text
implementation
deterministic_check
review
finding_normalization
finding_verification
finding_challenge
fix
targeted_recheck
final_review
approval
report
ticket_update
```

Anforderungen:

- Stufen sind wiederaufnehmbar;
- Wiederholungen erzeugen keine unkontrollierten Duplikate;
- jeder Lauf besitzt eine eindeutige ID;
- Inputs und Outputs sind einem konkreten Stand des Codes zugeordnet;
- abgebrochene Läufe bleiben sichtbar;
- Providerfehler werden von fachlichen Fehlern getrennt;
- Retry-Policies sind begrenzt;
- eine fehlgeschlagene Stufe darf nicht unbemerkt als Erfolg gelten;
- manuelle Eingriffe werden protokolliert;
- Pipeline-Konfigurationen sind versioniert.

---

# 24. Idempotenz und Reproduzierbarkeit

Codex soll prüfen, wie das bestehende Tool folgende Punkte abbildet:

- Commit-/Diff-Hash je Review-Lauf;
- Ticket-Version;
- Prompt-Version;
- Modell und Modellversion;
- Kontextpaket-Hash;
- Pipeline-Version;
- Provider-Parameter;
- Tool-Versionen;
- Test-/Build-Umgebung.

Ein Finding darf nicht versehentlich auf einen anderen Code-Stand angewendet werden.

Nach Codeänderungen ist zu entscheiden:

- ist das Finding weiterhin gültig;
- muss es erneut geprüft werden;
- wurde es durch den neuen Stand obsolet;
- betrifft es eine unveränderte Stelle.

---

# 25. Kosten-, Token- und Laufzeitsteuerung

Pro Modellaufruf sollten, soweit verfügbar, gespeichert werden:

```yaml
provider:
model:
model_role:
prompt_profile:
input_tokens:
cached_input_tokens:
output_tokens:
estimated_cost:
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
total_estimated_cost:
final_status:
final_model_mix:
```

Konfigurierbare Budgets:

```yaml
budgets:
  max_cost_per_ticket:
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

- Bestätigungsrate je Review-Modell;
- False-Positive-Rate je Prompt-Profil;
- Anteil verworfener Findings;
- Anteil von Findings, die erst das starke Finalmodell entdeckt;
- Schweregradverteilung;
- Fix-Erfolgsrate je Modell;
- durchschnittliche Fix-Schleifen;
- Wiedereröffnungsrate;
- menschliche Override-Rate;
- automatische Freigaberate;
- Kosten pro abgeschlossenem Ticket;
- Kosten pro bestätigtem Finding;
- Zeit bis zur Freigabe;
- Regressionen nach Abschluss;
- Routing-Erfolg von Luna gegenüber Terra;
- Nutzen des finalen starken Reviews.

Diese Daten sollen später helfen, Prompt-, Modell- und Routing-Konfigurationen anzupassen.

Automatische Selbstoptimierung ist nicht Bestandteil der ersten Ausbaustufe.

---

# 27. Sicherheits- und Vertrauensgrenzen

LLM-Ausgaben sind nicht vertrauenswürdig und müssen als Vorschläge bzw. strukturierte Eingaben behandelt werden.

Zu berücksichtigen:

- Code, Kommentare, Tickets und externe Inhalte können Prompt-Injection enthalten;
- Reviewer dürfen Projektanweisungen nicht aus untrusted Code-Kommentaren übernehmen;
- Secrets dürfen nicht ungefiltert an Provider gesendet werden;
- Provider- und Projektzugriffe benötigen minimale Berechtigungen;
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

Bestehende Ticketfelder sollen erhalten bleiben.

Folgende Felder sollen geprüft und bei Bedarf ergänzt werden:

```yaml
id:
title:
goal:
context:
scope:
out_of_scope:
acceptance_criteria:
dependencies:
affected_areas:
implementation_notes:
architecture_constraints:
tests_required:

risk_level:
complexity:
security_relevant:
criticality_flags:

recommended_implementation_model:
effective_implementation_model:
model_reason:

review_pipeline:
review_profiles:
verifier_policy:
final_review_policy:
approval_policy:

max_implementation_attempts:
max_review_cycles:
max_fix_cycles:
cost_budget:

execution_summary:
review_summary:
approval_summary:
artifact_references:
```

Nicht alle Felder müssen direkt im Ticket-Hauptobjekt gespeichert werden. Codex soll sie sinnvoll auf vorhandene Entitäten verteilen.

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
Implementiere das komplette Benutzer-, Rollen- und Berechtigungssystem.
```

Besser:

```text
1. Rollen-Datenmodell ergänzen
2. Permission-Service implementieren
3. Autorisierungs-Middleware ergänzen
4. Verwaltungs-API für Rollen erstellen
5. Admin-UI ergänzen
6. Integrations- und Security-Tests ergänzen
```

Sicherheits- und Integrationsabhängigkeiten müssen trotzdem im Gesamtplan sichtbar bleiben.

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

---

# 31. Vermutete Umsetzungsphasen

Die folgenden Phasen sind ein Vorschlag. Codex soll sie anhand des realen Projekts ändern, zusammenlegen, aufteilen oder neu ordnen.

## Phase 0: Bestandsaufnahme und Planintegration

- vorhandene Architektur und Datenmodelle erfassen;
- bisherige Modell-Routing-Planung abgleichen;
- vorhandene Review-Funktionen identifizieren;
- Gap-Analyse erstellen;
- Zielarchitektur in den Gesamtplan einarbeiten;
- offene Entscheidungen dokumentieren.

## Phase 1: Gemeinsame Workflow-Grundlage

- Modellrollen und Provider-Aliase erweitern;
- Pipeline-/Stage-Konfiguration definieren;
- Run- und Statusmodell ergänzen;
- Artefaktreferenzen vereinheitlichen;
- idempotente Wiederaufnahme ermöglichen.

## Phase 2: Review-only-Modus

- Eingabe aus Branch, Commit-Range, Diff oder PR;
- Ticketbezug herstellen;
- Kontextpaket erzeugen;
- Review-Stufen sequenziell ausführen;
- strukturierten Bericht erzeugen.

## Phase 3: Prompt-Profile und Erst-Reviewer

- versionierte Prompt-Profile;
- Auswahlregeln je Tickettyp;
- Luna-Review-Adapter;
- Copilot-/GitHub-Review-Adapter;
- strukturierte Output-Validierung.

## Phase 4: Finding-System

- Finding-Schema;
- Persistenz;
- Lifecycle;
- Deduplizierung;
- Evidenzmodell;
- Ticket-Zusammenfassung.

## Phase 5: Verifikation und Challenge

- Grok-Verifier;
- gezielte Kontextnachladung;
- Challenge-Antwort des Erst-Reviewers;
- begrenzte Entscheidungsrunden;
- Eskalation für High/Critical/Inconclusive.

## Phase 6: Approval-Policy

- Policy-Konfiguration;
- Risk-Trigger;
- automatische Freigabe;
- menschliche Freigabe;
- Override und Waiver;
- finaler Report.

## Phase 7: Fix-Orchestrierung

- bestätigte Findings in Fix-Aufträge umwandeln;
- Fix-Modell routen;
- Tests erneut ausführen;
- gezielten Re-Review durchführen;
- Loop-Limits und Eskalation umsetzen.

## Phase 8: Finaler starker Review

- optionale Opus-/starke Review-Stufe;
- finalen Gesamtdiff paketieren;
- unabhängigen Blind-Review ermöglichen;
- Ergebnisse mit Review-Ledger abgleichen;
- Release-/Ticket-Gate integrieren.

## Phase 9: UI und Bedienung

- Review-only-Lauf starten;
- Pipeline auswählen;
- Modell- und Prompt-Profile anzeigen;
- Findings filtern;
- Finding-Historie anzeigen;
- manuell bestätigen/verwerfen/waiven;
- Freigabestatus anzeigen;
- Kosten und Laufzeit anzeigen.

## Phase 10: Observability und Optimierung

- Kosten- und Tokenmetriken;
- Erfolgs- und Fehlerquoten;
- Reviewer-Qualität;
- Routing-Auswertung;
- Budgetwarnungen;
- Export und Audit.

## Phase 11: Härtung und Rollout

- Security-Tests;
- Fehlerszenarien;
- Provider-Ausfälle;
- Migration bestehender Tickets;
- Rückwärtskompatibilität;
- schrittweise Aktivierung;
- zunächst manuelle Freigabe;
- später risikobasierte Automatisierung.

---

# 32. Vermutete Entwicklungstickets

Diese Liste ist ausdrücklich ein Ausgangspunkt. Codex soll sie anhand der vorhandenen Architektur verfeinern.

## T00 – Bestehende Architektur und Plan auf Review-Erweiterung analysieren

**Ziel:** Relevante Komponenten, Datenmodelle, Workflows und Lücken dokumentieren.

**Ergebnis:** Mapping zwischen bestehender Architektur und den Konzepten dieses Dokuments.

---

## T01 – Modellrollen und Provider-Konfiguration erweitern

**Ziel:** Implementierungs-, Erst-Review-, Verifier- und Final-Review-Rollen konfigurierbar abbilden.

**Abhängigkeit:** T00.

---

## T02 – Pipeline- und Stage-Vertrag definieren

**Ziel:** Einheitliche Inputs, Outputs, Status, Retry- und Fehlerregeln für verkettbare Stufen festlegen.

**Abhängigkeit:** T00.

---

## T03 – Pipeline-Run und Stage-Run persistieren

**Ziel:** Ausführung, Wiederaufnahme, Fehlerstatus und Auditierbarkeit speichern.

**Abhängigkeit:** T02.

---

## T04 – Review-only-Eingaben unterstützen

**Ziel:** Branch, Commit-Range, Diff, PR oder ausgewählte Dateien als Review-Gegenstand normalisieren.

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

## T07 – Günstigen Luna-Erst-Reviewer anbinden

**Ziel:** Strukturierte Findings aus einem fokussierten Review-Profil erzeugen.

**Abhängigkeit:** T06.

---

## T08 – GitHub-/Copilot-Review-Adapter anbinden

**Ziel:** Ergebnisse des vorhandenen Copilot-/GitHub-Code-Review-Modells in das gemeinsame Format überführen.

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

**Ziel:** Überschneidende Meldungen zusammenführen, ohne unterschiedliche Ursachen zu verlieren.

**Abhängigkeit:** T10.

---

## T12 – Finding-Lifecycle und Decision Events implementieren

**Ziel:** Proposed, challenged, confirmed, rejected, fixed und weitere Zustände nachvollziehbar abbilden.

**Abhängigkeit:** T09.

---

## T13 – Grok-Verifier anbinden

**Ziel:** Findings mit relevantem Kontext kritisch bestätigen, verwerfen, ändern oder als unklar markieren.

**Abhängigkeit:** T05, T11, T12.

---

## T14 – Begrenzten Challenge-/Evidence-Loop implementieren

**Ziel:** Erst-Reviewer darf Belege nachreichen; der unabhängige Verifier trifft die Entscheidung.

**Abhängigkeit:** T13.

---

## T15 – Ticket-zentriertes Review-Ledger ergänzen

**Ziel:** Kompakte Implementierungs-, Review-, Fix- und Freigabezusammenfassung am Ticket bereitstellen.

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

## T18 – Approval-Policy-Engine implementieren

**Ziel:** Manual-, Risk-based- und Auto-Approval anhand definierter Bedingungen entscheiden.

**Abhängigkeit:** T12, T15.

---

## T19 – Menschliches Approval und Override ergänzen

**Ziel:** Findings, Waiver, Risikoakzeptanz und Ticketabschluss manuell steuern und auditieren.

**Abhängigkeit:** T18.

---

## T20 – Automatischen Abschlussbericht erzeugen

**Ziel:** Endzustand, Checks, Findings, Fixes, Modelle, Kosten und Approval kompakt darstellen.

**Abhängigkeit:** T15, T18.

---

## T21 – Bestätigte Findings in Fix-Aufträge überführen

**Ziel:** Nur bestätigte bzw. freigegebene Findings an ein Implementierungsmodell übergeben.

**Abhängigkeit:** T12, T13.

---

## T22 – Gezielten Re-Review nach Fix implementieren

**Ziel:** Finding und betroffene Bereiche erneut prüfen, ohne unnötig die gesamte Pipeline zu wiederholen.

**Abhängigkeit:** T21.

---

## T23 – Fix-Loop-Limits und Terra-Eskalation ergänzen

**Ziel:** Luna-Fixversuche begrenzen, auf Terra eskalieren und nach weiterem Scheitern stoppen.

**Abhängigkeit:** T21, T22, T01.

---

## T24 – Optionalen finalen starken Review implementieren

**Ziel:** Finalen Gesamtdiff unabhängig durch Opus oder eine konfigurierbare starke Rolle prüfen lassen.

**Abhängigkeit:** T05, T20.

---

## T25 – Review-Pipeline mit bestehendem Implementierungsmodus verketten

**Ziel:** Implementierung, Checks, Reviews, Verifikation, Fixes und Approval als durchgängigen Ablauf verbinden.

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

**Ziel:** Kosten je Stage, Modell, Ticket und erfolgreichem Abschluss nachvollziehen.

**Abhängigkeit:** T03, T01.

---

## T29 – Qualitätsmetriken für Reviewer und Prompts erfassen

**Ziel:** Bestätigungsrate, False Positives, Eskalationen und Final-Review-Mehrwert messen.

**Abhängigkeit:** T12, T24, T28.

---

## T30 – Security- und Prompt-Injection-Härtung

**Ziel:** Untrusted Code/Tickets, Secrets, Toolrechte und strukturierte Outputs absichern.

**Abhängigkeit:** querschnittlich; früh einplanen, final umfassend prüfen.

---

## T31 – Migration und schrittweiser Rollout

**Ziel:** Bestehende Tickets und Workflows kompatibel halten und die neue Automation kontrolliert aktivieren.

**Abhängigkeit:** Gesamtintegration.

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
Welches Implementierungsmodell wird empfohlen?
Welche Review-Profile müssen laufen?
Welche Approval-Policy gilt?
```

Die Tickets sollen:

- klein;
- einzeln testbar;
- einzeln reviewbar;
- sinnvoll abhängig;
- rückwärtskompatibel planbar;
- ohne unnötige Architekturentscheidungen während der Umsetzung

sein.

---

# 34. Beispiel einer späteren Ticket-Metadatenstruktur

```yaml
id: REVIEW-014
title: Grok-Verifier für normalisierte Findings integrieren

goal: >
  Normalisierte Findings aus günstigen Erst-Reviews unabhängig prüfen
  und strukturiert bestätigen, verwerfen, ändern oder eskalieren.

scope:
  - Verifier-Stage
  - Kontextpaket für Finding-Verifikation
  - strukturiertes Decision-Result
  - begrenzte Retry- und Challenge-Policy

out_of_scope:
  - allgemeiner vollständiger Final-Review
  - automatische Code-Fixes
  - UI für menschliche Freigaben

dependencies:
  - REVIEW-009
  - REVIEW-010
  - REVIEW-012

acceptance_criteria:
  - Der Verifier verarbeitet genau ein normalisiertes Finding.
  - Er erhält nur den relevanten Ticket- und Codekontext.
  - Das Ergebnis entspricht einem validierten Schema.
  - Confirmed, rejected, downgraded und inconclusive werden unterstützt.
  - Die Entscheidung und Begründung werden auditierbar gespeichert.
  - High/Critical können gemäß Policy eskaliert werden.

risk_level: medium
complexity: medium
security_relevant: false

recommended_implementation_model: gpt-5.6-luna
model_reason: >
  Klar begrenzte Provider- und Workflow-Integration auf Basis bereits
  definierter Stage- und Finding-Verträge.

review_profiles:
  - functional_correctness
  - architecture_maintainability
  - tests

approval_policy: risk_based
```

Dieses Beispiel ist nicht zwingend das endgültige Schema.

---

# 35. Offene Entscheidungen, die Codex anhand des Projekts klären soll

Codex soll diese Punkte prüfen und im Plan beantworten oder als explizite Entscheidungen markieren:

1. Welche bestehenden Entitäten repräsentieren Ticket, Run, Job und Artefakt?
2. Gibt es bereits eine allgemeine Workflow- oder Queue-Abstraktion?
3. Soll die Pipeline deklarativ konfiguriert oder zunächst fest im Backend orchestriert werden?
4. Wie werden Branch, Worktree, Commit und PR derzeit verwaltet?
5. Wo liegen Rohartefakte und wie werden sie referenziert?
6. Wie werden Modellantworten heute validiert?
7. Welche Provider liefern Token- und Kostendaten?
8. Wie werden GitHub-/Copilot-Review-Ergebnisse abgerufen?
9. Welche Modelle unterstützen strukturierte Ausgaben zuverlässig?
10. Welche bestehenden Rollen- und Berechtigungsmodelle gelten für manuelle Freigaben?
11. Wie wird verhindert, dass untrusted Repository-Inhalt Systemanweisungen beeinflusst?
12. Welche Ticketfelder existieren bereits?
13. Wie werden Ticketänderungen historisiert?
14. Welche Review- und Fix-Schleifen bestehen bereits?
15. Soll ein Finding Event-Sourcing verwenden oder einen einfacheren Statusverlauf?
16. Welche Review-Profile sind für die erste Version wirklich erforderlich?
17. Welche Pfade oder Domänen gelten im Projekt als kritisch?
18. Wann soll der finale starke Review automatisch laufen?
19. Wie hoch dürfen Kosten- und Schleifenbudgets sein?
20. Welche Teile werden zunächst nur per CLI und welche in der UI benötigt?
21. Wie werden bestehende Tickets ohne neue Metadaten behandelt?
22. Welche Funktion dient als sichere Fallback-Strategie bei Provider-Ausfällen?
23. Welche Funktionen dürfen automatisch Code verändern?
24. An welcher Stelle ist zwingend ein Mensch erforderlich?
25. Wie wird ein Review gegen einen unveränderten, reproduzierbaren Code-Stand garantiert?

---

# 36. Erwartete Ergebnisse von Codex

Nach Verarbeitung dieses Dokuments soll Codex liefern:

## A. Aktualisierten Gesamt-/Integrationsplan

Die neuen Konzepte sind an den passenden Stellen der bestehenden Planung integriert.

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
- Modell-Empfehlung;
- Review-Profilen;
- Risiko;
- Approval-Policy.

## E. Migrations- und Kompatibilitätsplan

Bestehende Workflows und Tickets bleiben funktionsfähig oder erhalten eine klare Migration.

## F. Offene Entscheidungen

Nur Punkte, die aus Code und bestehender Planung nicht zuverlässig entschieden werden können.

## G. Rollout-Vorschlag

Bevorzugt:

1. Review-only zunächst mit manueller Abschlussfreigabe;
2. Qualität und False Positives messen;
3. Finding-Verifikation aktivieren;
4. Fix-Schleifen aktivieren;
5. risikobasierte Auto-Freigabe nur für unkritische Fälle;
6. finalen starken Review gezielt zuschalten;
7. Routing und Prompts anhand realer Daten optimieren.

---

# 37. Abnahmekriterien für die Planintegration

Die Planerweiterung gilt als vollständig, wenn:

- Review-only als eigenständiger Modus beschrieben ist;
- Review-Stufen mit dem Implementierungsworkflow verkettet werden können;
- Modellrollen konfigurierbar sind;
- Luna als Standard-Implementierungsmodell eingeplant ist;
- Terra als primäre Implementierungseskalation eingeplant ist;
- Sol/Opus für Implementierung Ausnahmefälle bleiben;
- günstige Erst-Reviews mehrere spezialisierte Prompt-Profile unterstützen;
- Findings strukturiert gespeichert werden;
- Deduplizierung und Verifikation beschrieben sind;
- das Erst-Review-Modell nicht allein über sein eigenes Finding entscheidet;
- Grok oder eine konfigurierbare mittlere Rolle als Verifier vorgesehen ist;
- menschliche und automatische Freigabe risikobasiert konfigurierbar sind;
- das Ticket als zentrale kompakte Sicht dient;
- Rohartefakte separat gespeichert werden;
- Fix- und Re-Review-Schleifen begrenzt sind;
- ein optionaler unabhängiger finaler Review beschrieben ist;
- der finale Reviewer den finalen Gesamtdiff prüfen kann;
- automatische Tests und statische Prüfungen eingebunden sind;
- Kosten, Token, Schleifen und Modellentscheidungen auditierbar sind;
- Security- und Prompt-Injection-Risiken berücksichtigt sind;
- Rückwärtskompatibilität und Migration eingeplant sind;
- daraus kleine, realistisch implementierbare Tickets erzeugt werden können.

---

# 38. Schlussprinzip

Die gewünschte Qualität soll aus einer kontrollierten Kette entstehen:

```text
gute Planung
+ kleine Tickets
+ geeignetes Implementierungsmodell
+ deterministische Checks
+ fokussierte günstige Reviews
+ unabhängige Finding-Verifikation
+ begrenzte Fix-Schleifen
+ risikobasierte menschliche Kontrolle
+ optionaler starker Final-Review
+ vollständige Nachvollziehbarkeit
```

Nicht jedes Ticket benötigt das teuerste Modell.

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
