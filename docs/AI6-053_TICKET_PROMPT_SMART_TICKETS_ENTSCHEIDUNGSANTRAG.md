# ANTRAG — AI6-053

**Art:** entscheidung
**Ausgelöst durch:** Menschlicher Auftrag vom 19. Oktober 2026 im Repository `smart-tickets`, die Tickets für dessen Phase 1 („Umstieg der Projekte“) zu erstellen. Für AI6 wird nach `AGENTS.md` §2 und §8 sowie Plan §13.3 kein Detailticket ohne Blueprint geschrieben, sondern dieser Antrag vorgelegt.

## Befund

Das bisherige Ticket-Prompt-Tool liegt unverändert unter `ticket-prompt/`: `api.php` (Aktionen `list`, `prompt` und `status` mit Übergangsgraph, Dateisperre, Rollback und Validatorlauf über `tools/validate_tickets.php`), `index.html` und `tests/status_update_test.php`. Es ist kein Produktcode; nichts unter `app/`, `config/`, `routes/` oder `scripts/` verweist darauf.

Die eigenständige lokale Anwendung Smart Tickets (`G:/software_projekte/smart-tickets`, `http://127.0.0.1:8092/`) bedient AI6 seit ihrer Phase 0 über das Profil `ai6` ohne Änderung an diesem Repository: Frontmatter-Tickets unter `tickets/` mit Muster `AI6-\d{3}[A-Z]?`, Titel aus `title`, Meta aus `milestone`, `risk` und `kind`, dieselbe Übergangsmatrix wie `ticket-prompt/api.php`, Master-Prompt `ai/prompts/implementierung_master_prompt.md` mit Einsetzen an der Zeile `[TICKET HIER EINFÜGEN` und Validator `tools/validate_tickets.php` mit der Regel, dass jeder Validatorfehler des Kandidaten blockiert. Der Gleichheitsnachweis aus `smart-tickets/docs/abnahme-phase-0.md` bestätigt Liste und Prompts. Ein erfolgreicher Statuswechsel ist dort nicht nachweisbar, weil eine unveränderte Kopie der Tickets am 19. Oktober 2026 mit Validator-Exit 1 und 152 Fehlern endet; dieselbe Sperre gilt für das alte Tool.

Zur Entfernung liegen bereits Aussagen vor: `README.md` (Abschnitt „Zuordnung des bisherigen Ticket-Prompt-Tools (AI6-037, erste Etappe)“), `tickets/AI6-037.md` und `tickets/AI6-038.md` halten fest, dass `ticket-prompt/`, `ai/prompts/` und `tools/` zur Ablösung vorgesehen sind und ihre Entfernung eine gesonderte menschliche Entscheidung in einem eigenen Commit außerhalb des Pilotcodes ist.

Gebundene Stellen: `.dockerignore` enthält `ticket-prompt`, und `tests/Unit/Shared/Runtime/RuntimeScriptsTest.php` verlangt diese Zeile. `pint.json` schließt `ticket-prompt` aus. `tests/Unit/Prompts/ManualPromptCatalogTest.php` bindet die Herkunftszeilen „Review-Prompt aus `ticket-prompt/index.html`“ und „Statischer Fix-Prompt aus `ticket-prompt/index.html`“ in `README.md` wörtlich. `tests/Feature/Shared/Runtime/RuntimeComposeSmokeTest.php` verlangt, dass `/opt/ai6/ticket-prompt` im Image fehlt. Der Blueprint `AI6-052` schließt eine Veröffentlichung von `ticket-prompt/api.php` aus.

Der Plan steht auf V1.7.14. `AI6-052` ist die höchste vergebene ID; `AI6-053` ist die vorgeschlagene nächste ID, noch keine durch diesen Antrag veröffentlichte Blueprintvergabe.

## Konflikt mit dem Blueprint

Kein Blueprint in §15 deckt das Ersetzen von `ticket-prompt/` ab. `AI6-037` und `AI6-038` nehmen die Entfernung ausdrücklich aus ihrem Umfang aus. Löschungen und `tickets/` sind nach `AGENTS.md` §7.3 sensible Pfade, und ein Detailticket entsteht nach §8 nur aus genau einem Blueprint.

Zusätzlich widerspricht die bisherige Absicht, auch `ai/prompts/` und `tools/` abzulösen, dem Betrieb von Smart Tickets: Das Profil `ai6` liest `ai/prompts/implementierung_master_prompt.md` und führt `tools/validate_tickets.php` aus. Fehlt der Master-Prompt, erzeugt Smart Tickets den Implementierungs-Prompt aus seiner projektneutralen Standardvorlage; fehlt der Validator, ist kein Statuswechsel mit Validatorprüfung mehr möglich. Eine spätere Entfernung dieser beiden Pfade muss deshalb mit einer Änderung des Profils `ai6` in Smart Tickets abgestimmt werden.

## Vorschlag

**Entscheidungsfrage:** Soll Plan V1.7.15 den nachstehenden Blueprint `AI6-053` aufnehmen, damit daraus unmittelbar ein Detailticket mit `status: todo` erzeugt werden kann, das `ticket-prompt/` durch einen Hinweis auf Smart Tickets ersetzt?

1. **Empfehlung: Altes Tool durch Hinweis ersetzen, gebundene Stellen unverändert lassen.** `ticket-prompt/api.php`, `ticket-prompt/index.html` und `ticket-prompt/tests/` werden entfernt; `ticket-prompt/README.md` verweist auf Smart Tickets. Weil das Verzeichnis bestehen bleibt, bleiben `.dockerignore`, `pint.json` und die daran gebundenen Tests unverändert gültig. Die Herkunftszeilen in `README.md` beschreiben weiterhin zutreffend, woher `manual_review` stammt, und bleiben stehen. Geändert wird nur der Satz zur Ablösung: `ticket-prompt/` ist durch Smart Tickets ersetzt; `ai/prompts/implementierung_master_prompt.md` und `tools/validate_tickets.php` bleiben, solange Smart Tickets sie verwendet. So gibt es nur noch eine gepflegte Fassung des Werkzeugs bei kleinstem Diff.
2. **Status quo mit Hinweis.** `ticket-prompt/` bleibt bis zur Phase B von `AI6-038`; `README.md` nennt Smart Tickets lediglich als Alternative. Kein Blueprint nötig, aber zwei statusschreibende Werkzeuge mit derselben Übergangsmatrix bleiben parallel zu pflegen.
3. **Vollständige Entfernung des Verzeichnisses.** Zusätzlich werden `.dockerignore`, `pint.json`, `RuntimeScriptsTest` und die Herkunftszeilen samt `ManualPromptCatalogTest` angepasst. Das berührt gebundene Tests ohne zusätzlichen Nutzen gegenüber Option 1.

Für Option 1 wird folgender Blueprint vorgeschlagen:

| Merkmal | Vorgeschlagener Wert |
|---|---|
| ID | `AI6-053` |
| Titel | Altes Ticket-Prompt-Tool durch Hinweis auf Smart Tickets ersetzen |
| Initialstatus | `todo` |
| Meilenstein | `M7`, neben `AI6-037` |
| Risiko | `low` |
| Art | `chore` |
| Abhängigkeiten | keine; kein Produktcode betroffen |
| Requirement-Refs | `OPS-005` (wie `AI6-037` und `AI6-038`), bei der Planrevision zu bestätigen |
| Fachlicher Umfang | Repository-Werkzeuge außerhalb des Produktcodes und `README.md` |

**Vorgeschlagenes Blueprint-Ziel:** Das bisherige Ticket-Prompt-Tool unter `ticket-prompt/` durch einen Hinweis auf die lokale Anwendung Smart Tickets ersetzen, ohne Produktcode, Promptkatalog, Master-Prompt oder Ticketvalidator zu ändern.

Lieferumfang:

1. `ticket-prompt/api.php`, `ticket-prompt/index.html` und `ticket-prompt/tests/` sind entfernt; `ticket-prompt/README.md` nennt Pfad, Startbefehl `php artisan serve --host=127.0.0.1 --port=8092`, Adresse und Projekt-ID `ai6` von Smart Tickets sowie den Commit mit dem letzten Stand des alten Tools.
2. `README.md` beschreibt im Zuordnungsabschnitt den Ersatz durch Smart Tickets und nennt `ai/prompts/implementierung_master_prompt.md` und `tools/validate_tickets.php` als weiterhin verwendet; die Herkunftszeilen der Zuordnungstabelle bleiben unverändert.
3. `.dockerignore`, `pint.json`, `ai/prompts/`, `tools/`, Produktcode und bestehende Tickets bleiben unverändert.

Testnachweise:

- `git ls-files ticket-prompt` liefert genau `ticket-prompt/README.md`.
- `ManualPromptCatalogTest`, `RuntimeScriptsTest`, Pint und die vollständige PHPUnit-Suite bleiben grün.
- `php tools/validate_tickets.php` liefert nach der Änderung keine zusätzlichen Fehler gegenüber dem Vorher-Lauf.
- Manuell: Smart Tickets zeigt für `ai6` die Ticketliste und erzeugt den Implementierungs-Prompt mit Herkunft „Projekt“; kein Statuswechsel an echten Tickets.

Nicht umfasst sind die Entfernung von `ai/prompts/` oder `tools/`, die Abschaltung des Legacy-Lesers aus Phase B von `AI6-038`, Änderungen am Promptkatalog, an `/prompts/help` oder an Smart Tickets sowie Korrekturen der bestehenden Validatorfehler in `tickets/`.

## Auswirkung auf den Plan

Nach Freigabe wird `docs/AI6_IMPLEMENTATION_PLAN.md` gezielt auf V1.7.15 fortgeschrieben: Revisionseintrag mit Zitat der Freigabe, Blueprint `AI6-053` unter M7 in §15, Nachführung von §14.1, §16, §21 und der Blueprintanzahl von 58 auf 59 sowie Neuerzeugung von `docs/AI6_TICKET_MANIFEST.yaml` mit `scripts/generate-ticket-manifest.php`. Die Ausschlüsse in `AI6-037`, `AI6-038` und `AI6-052` bleiben als Historie unverändert. Danach entsteht `tickets/AI6-053.md` nach `AGENTS.md` §8 mit `status: todo`, und `tickets/README.md` erhält den üblichen Erzeugungseintrag. Dieser Antrag beauftragt weder Planänderung noch Implementierung.
