> **Version:** 4.1 · **Stand:** 2026-09-04 · Passend zum Ticketformat in
> `docs/AI6_TICKET_TEMPLATE_V1.md`.
> **Arbeitsverzeichnis:** Repository-Root; alle Ticketpfade sind relativ dazu.

# Auftrag

Implementiere genau das unten eingebettete, kleine Ticket im bestehenden AI6-Repository.
„Klein“ verkürzt nur Planung und Bericht; Scope-, Sicherheits-, Status- und
Prüfregeln gelten vollständig.

`AGENTS.md` ist die kanonische Projektanweisung. Befolge zusätzlich jede näher gelegene
`AGENTS.md`, die für eine betroffene Datei gilt. Für Status, Review-KI-Hinweise, Verifikation und
Abschlussbericht gilt `ai/prompts/implementierung_master_prompt.md`.

# Pflichtlektüre

Lies vor der ersten Änderung vollständig:

1. `AGENTS.md` und gegebenenfalls anwendbare untergeordnete `AGENTS.md`-Dateien;
2. das eingebettete Ticket;
3. `docs/AI6_TICKET_TEMPLATE_V1.md`;
4. alle unter `depends_on` genannten Tickets und ausdrücklich referenzierten Stellen;
5. den relevanten Code, vorhandene Tests und direkten Schnittstellen.

# Eingebettetes Ticket

<!-- BEGIN EINGEBETTETES TICKET -->
[TICKET HIER EINFÜGEN]
<!-- END EINGEBETTETES TICKET -->

# Regeln

- Implementiere nur dieses Ticket. `files` beschreibt den vermuteten Ausgangsscope nach
  `AGENTS.md` §7; begründe zusätzliche Pfade im Bericht. Sensible Änderungen benötigen die dort
  verlangte menschliche Freigabe, soweit diese nicht bereits im Auftrag vorliegt.
- Ändere niemals Ticketstatus, Approval- oder Run-Metadaten. Sonstige Ticketänderungen, auch
  `files`, Scope-Marker und `## Recorded Scope`, brauchen die ausdrückliche menschliche Freigabe
  der betreffenden Änderung nach `AGENTS.md` §10. Eine Testbindung allein ist keine Scopeänderung.
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
4. Berichte den Arbeitsstand; lasse den Ticketstatus unverändert.
5. Führe während der Iteration nur die Tests aus, die an das geänderte Verhalten und die berührten
   Verträge gebunden sind: neue oder angepasste Tests sowie unveränderte Regressionstests,
   Inventartests und Architekturtests, die diese Verträge absichern. Den vollständigen regulären
   Suite-Lauf und die Abschlussprüfungen führst du erst am Abschluss-Gate gemäß `AGENTS.md` aus.
   Berichte fehlende oder fehlgeschlagene Prüfungen offen.
6. Dokumentiere Review-Hinweise und Scopeabweichungen im Abschlussbericht. Schreibe sie nur
   bei ausdrücklicher menschlicher Freigabe der betreffenden Änderung in die Ticketdatei.
7. Berichte bestandene und offene Prüfungen; eine Statusentscheidung bleibt beim Menschen.

# Abschlussbericht

Antworte knapp im Format des Master-Prompts: Ergebnis, geänderte Dateien, Akzeptanzkriterien,
Tests und Checks, manuelle/externe Gates sowie nur tatsächlich vorhandene Annahmen, Risiken oder
Review-Hinweise.
