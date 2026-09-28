# AI6-037/MG-01 — Abnahmeprotokoll der Legacy-Migration

Ergebnisfreie Vorlage. Ausschließlich eine menschliche Prüfperson trägt nach Lieferung der Migration Beobachtungen, Entscheidung und Unterschrift ein. Promptzuordnung, automatisierte Golden-Tests und der erste V1-Pilot von AI6-038 ersetzen diesen Nachweis nicht. Bis zur Durchführung und Signatur bleibt MG-01 offen.

## Bindung und freigegebener Korpus

- Implementierungscommit mit den final getesteten Migrationsbytes:
- Legacy-Projekt und Repository:
- Commit des Legacy-Projekts vor der Migration:
- Lokales Verzeichnis der ruhenden, committeten Arbeitskopie:
- Anzahl der Dateien und Legacy-Kandidaten:
- Freigegebene echte Beispieldatei, Ticket-ID und gegebenenfalls Redigierung:
- Bindung der Beispieldatei an `tests/Fixtures/Tickets/legacy-pilot-full.md` (Hash und Freigabe):
- Gewähltes Profil (`generic_v1` oder `ai6_detail_v1`) und Mindeststrenge der Projektkonfiguration:
- Datum, Uhrzeit und Prüfperson:

## Durchführung nach Lieferung des Migrationskommandos

| Prüfschritt | Kommando / Evidenzpfad | Beobachtung / Exitcode |
|---|---|---|
| Dry-run in der lokalen Entwickler-Arbeitskopie, vollständiger Bericht | | |
| Originaldateien nach Dry-run bytegleich | | |
| Statusmapping und verweigerte Statuswerte geprüft | | |
| Eingabetabelle gegen den realen Altbestand geprüft | | |
| Fehlende Pflichtinhalte vor Apply menschlich ergänzt | | |
| `--apply` in der Arbeitskopie | | |
| Vollständiger Vorher-/Nachher-Diff mit `git diff` | | |
| Validierung unter dem gewählten Profil | | |
| Wiederholung über den migrierten Bestand ohne Änderung | | |

## Semantischer Vergleich der freigegebenen Beispieldatei

| Feld / Inhalt | Vorher-/Nachher-Evidenz | Menschliche Bestätigung / Befund |
|---|---|---|
| Ziel | | |
| Kontext und Aufgaben | | |
| Kriterien, bestehende IDs und mehrzeilige Inhalte | | |
| Tests und AC Coverage | | |
| Scope, `files` und sensible Pfade | | |
| Gates und Abgrenzungen | | |
| Referenzen und erhaltene §-Hinweise | | |
| Nicht konsumierte Schlüssel und vollständige Legacy-Quelle | | |
| Status, Abhängigkeiten und übriges Frontmatter | | |

Menschlich ergänzte Pflichtinhalte und Begründung:

Übertragung nicht konsumierter Inhalte und Entfernung der Übergabeblöcke vor dem menschlichen Commit (Quelle bleibt in der Git-Historie):

## Befunde und Entscheidung

- Offene Abweichungen und erforderliche Nacharbeiten:
- Entscheidung zur semantischen Gleichwertigkeit:
- Geprüfter Implementierungscommit und Legacy-Projektstand nochmals bestätigt:
- Name, Datum und Unterschrift:

Eine spätere Änderung der getesteten Implementierungsbytes verlangt erneute Prüfung und Signatur. Dieses Protokoll allein gibt keinen Cutoff frei: Dafür sind zusätzlich die vollständige Migrationslieferung und AI6-038/MG-03 erforderlich.
