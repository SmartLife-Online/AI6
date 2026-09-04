> **Version:** 4.0 · **Stand:** 2026-07-22 · Passend zum Ticketformat in
> `tickets/TICKET_TEMPLATE.md`.
> **Arbeitsverzeichnis:** Repository-Root; alle Ticketpfade sind relativ dazu.

# Auftrag

Implementiere genau das unten eingebettete, kleine Ticket im bestehenden Repository der
Smartlife-Webseite. „Klein“ verkürzt nur Planung und Bericht; Scope-, Sicherheits-, Status- und
Prüfregeln gelten vollständig.

`AGENTS.md` ist die kanonische Projektanweisung. Befolge zusätzlich jede näher gelegene
`AGENTS.md`, die für eine betroffene Datei gilt. Für Status, Review-KI-Hinweise, Verifikation und
Abschlussbericht gilt `ai/prompts/implementierung_master_prompt.md`.

# Pflichtlektüre

Lies vor der ersten Änderung vollständig:

1. `AGENTS.md` und gegebenenfalls anwendbare untergeordnete `AGENTS.md`-Dateien;
2. das eingebettete Ticket;
3. `tickets/TICKET_TEMPLATE.md`;
4. alle unter `depends_on` genannten Tickets und ausdrücklich referenzierten Stellen;
5. den relevanten Code, vorhandene Tests und direkten Schnittstellen.

# Eingebettetes Ticket

<!-- BEGIN EINGEBETTETES TICKET -->
[TICKET HIER EINFÜGEN]
<!-- END EINGEBETTETES TICKET -->

# Regeln

- Implementiere nur dieses Ticket und ändere ausschließlich die unter `files:` erlaubten Dateien
  beziehungsweise Inhalte ausdrücklich gelisteter Verzeichnisse.
- Die ausgewählte Ticketdatei darf zusätzlich nur für das YAML-Feld `status` und den vorhandenen
  Abschnitt `## Umsetzungshinweise für die Review-KI` geändert werden.
- Prüfe `depends_on` gegen den tatsächlichen Code. Erfinde keine fehlende Vorarbeit und erweitere
  den Scope nicht still.
- Nutze vorhandene Architektur und zentrale Verträge. Keine unnötigen Refactorings, neuen
  Abhängigkeiten, Frameworks, Ordner oder Features.
- Bewahre fremde Änderungen und sensible Daten. Keine destruktiven Git-Aktionen, Commits, Pushes,
  Deployments oder externen Änderungen ohne ausdrücklichen Auftrag.
- Simuliere keine manuellen oder externen Nachweise.

# Arbeitsweise

1. Prüfe Git-Status, Ticketreife, relevante Dateien und Schnittstellen.
2. Gib ein kurzes Vorwort mit höchstens vier Punkten aus und beginne direkt, sofern keine
   folgenreiche, nicht sicher auflösbare Entscheidung fehlt.
3. Implementiere die kleinste vollständige Lösung und ergänze die vorgesehenen Verhaltenstests.
4. Setze das Ticket nach der ersten tatsächlichen Umsetzung auf `in_progress`.
5. Führe während der Iteration nur die Tests aus, die an das geänderte Verhalten und die berührten
   Verträge gebunden sind: neue oder angepasste Tests sowie unveränderte Regressionstests,
   Inventartests und Architekturtests, die diese Verträge absichern. Den vollständigen regulären
   Suite-Lauf und die Abschlussprüfungen führst du erst am Abschluss-Gate gemäß `AGENTS.md` aus.
   Berichte fehlende oder fehlgeschlagene Prüfungen offen.
6. Aktualisiere genau einen Abschnitt `## Umsetzungshinweise für die Review-KI` in der
   Ticketdatei. Verwende `Keine besonderen Hinweise.`, wenn es nichts Relevantes mitzuteilen gibt.
7. Setze den Status nur bei vollständig bestandenen automatischen und verpflichtenden echten
   manuellen beziehungsweise externen Gates auf `review`; niemals selbst auf `done`.

# Abschlussbericht

Antworte knapp im Format des Master-Prompts: Ergebnis, geänderte Dateien, Akzeptanzkriterien,
Tests und Checks, manuelle/externe Gates sowie nur tatsächlich vorhandene Annahmen, Risiken oder
Review-Hinweise.
