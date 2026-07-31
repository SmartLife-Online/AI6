> **Version:** 4.0 · **Stand:** 2026-07-22 · Passend zum Ticketformat in
> `tickets/TICKET_TEMPLATE.md`.
> **Arbeitsverzeichnis:** Repository-Root; alle Ticketpfade sind relativ dazu.

# Auftrag

Analysiere das unten eingebettete Ticket und erstelle einen belastbaren, minimalen Umsetzungsplan.
Implementiere noch nichts.

`AGENTS.md` ist die kanonische Projektanweisung. Lies sie vollständig und beachte zusätzlich jede
näher gelegene `AGENTS.md`, die für voraussichtlich betroffene Dateien gilt.

# Pflichtlektüre

Lies vor der Analyse:

1. `AGENTS.md` und gegebenenfalls anwendbare untergeordnete `AGENTS.md`-Dateien;
2. das vollständige Ticket einschließlich YAML, Akzeptanzkriterien, Testfällen, Out-of-Scope,
   Hinweisen und Umsetzungshinweisen;
3. `tickets/TICKET_TEMPLATE.md`;
4. alle unter `depends_on` genannten Tickets und alle ausdrücklich referenzierten Dateien oder
   Dokumentationsabschnitte;
5. den relevanten aktuellen Code, vorhandene Tests und die direkten Aufrufer beziehungsweise
   Verbraucher der betroffenen Schnittstellen.

Ein Ticket oder Dokument beschreibt möglicherweise erst einen Zielzustand. Prüfe deshalb im
Repository, welche Abhängigkeiten, Dateien, Befehle und Verträge tatsächlich bereits existieren.
Lies keine ignorierten produktiven Konfigurationen, Secrets oder echten Nutzerdaten.

# Eingebettetes Ticket

<!-- BEGIN EINGEBETTETES TICKET -->
[TICKET HIER EINFÜGEN]
<!-- END EINGEBETTETES TICKET -->

# Erwartete Analyse

Liefere knapp und konkret:

1. **Outcome und Nicht-Ziele** – welches beobachtbare Ergebnis verlangt wird und was ausdrücklich
   außerhalb des Scope bleibt;
2. **Umsetzungsreife** – ob `depends_on` und benötigte Verträge im aktuellen Code wirklich
   vorhanden sind;
3. **Betroffene Dateien und Schnittstellen** – geplante Schreibziele innerhalb von `files:` sowie
   nur lesend zu prüfende Aufrufer und Verbraucher;
4. **Aktueller und gewünschter Vertrag** – relevante Daten-, API-, Sicherheits- und
   Fehlersemantik;
5. **Risiken und Nebenwirkungen** – insbesondere Sicherheit, Datenschutz, Kompatibilität,
   Parallelität, Migration und Hostinggrenzen, soweit zutreffend;
6. **Minimaler Umsetzungsplan** – kleine, geordnete Schritte, jeweils Akzeptanzkriterien
   zugeordnet;
7. **Verifikation** – ticketgenannte und zusätzlich notwendige automatisierte Checks sowie echte
   manuelle oder externe Gates;
8. **Widersprüche und Entscheidungen** – präzise Fundstellen und die kleinste erforderliche
   Klärung; keine erfundenen Verträge;
9. **Größenprüfung** – ob das Ticket als ein überprüfbares Ergebnis umsetzbar ist; nur bei echten
   unabhängigen Outcomes eine konkrete Aufteilung vorschlagen.

# Grenzen des Planungsmodus

- Ändere keine Datei, keinen Ticketstatus und kein externes System.
- Führe nur sichere, lesende Bestands- und Diagnoseprüfungen aus.
- Weite `files:` nicht still aus. Benenne eine notwendige Scope-Erweiterung als Blocker oder
  Entscheidung.
- Behaupte keine bestandenen Tests, Browser-, Datenbank-, Hosting- oder Anbieterprüfungen, die in
  diesem Planungslauf nicht tatsächlich durchgeführt wurden.

# Folgeprompt nach Bestätigung des Plans

Setze jetzt den bestätigten minimalen Umsetzungsplan für das eingebettete Ticket um.

Lies den aktuellen Repository-Stand erneut, da er sich seit der Planung geändert haben kann.
Befolge `AGENTS.md` sowie den Status-, Verifikations-, Review-KI- und Abschlussworkflow aus
`ai/prompts/implementierung_master_prompt.md`. Der Plan erweitert weder `files:` noch den
Ticketvertrag. Neue Abhängigkeiten, Refactorings oder zusätzliche Features sind nur zulässig, wenn
das Ticket und die bestätigte Schreibgrenze sie ausdrücklich verlangen.

