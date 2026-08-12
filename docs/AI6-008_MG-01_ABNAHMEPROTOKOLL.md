# Abnahmeprotokoll AI6-008 / MG-01 — Bedienbarkeit der Ticketübersicht und des Ticketdetails

Ergebnisfreie Vorlage. Bindung, Beobachtungen, Ergebnis und Unterschrift werden ausschließlich
von der menschlichen Prüfperson eingetragen. Das Gate bleibt offen, bis dieses Protokoll
ausgefüllt und signiert vorliegt.

## 0. Vorbereitung

Geprüft wird die laufende Weboberfläche mit mindestens einem Projekt, dessen Read Model
Projektionen in den Zuständen `valid`, `invalid` und `unparsed` enthält; mindestens eine
Projektion soll `content_redacted` oder veraltet (`Veraltet` mit Prädikat) sein, damit die
Zustandsunterscheidung real prüfbar ist. Die Ticketübersicht ist von der Projektansicht aus
über den Link „Zur Ticketübersicht" erreichbar; jede Projektion verlinkt auf ihr Ticketdetail.

Die Prüfung verwendet reale Geräte, keine Emulation:

- einen Laptop mit üblicher Desktopauflösung;
- ein Smartphone mit schmalem Viewport (Größenordnung 375 Pixel Breite).

## 1. Bindung

Die Evidenz ist an genau einen Stand gebunden. Zulässig ist der geprüfte Git-Commit oder —
vor einem Commit — Base-Commit **und** SHA-256 des vollständigen Prüfdiffs.

Commit-Bindung ermitteln:

```
git rev-parse HEAD
```

| Feld | Wert |
|---|---|
| Geprüfter Commit | 4c589dd469a399de7515f877bd05bd01e43bf76d |
| Datum und Uhrzeit der Prüfung | 12.08.2026 12:33 |

## 2. Prüfschritte auf dem Laptop

Jede Zeile wird mit „ja" oder „nein" sowie bei Auffälligkeiten mit einer kurzen Beobachtung
ausgefüllt.

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| L1 | Die Ticketliste zeigt je Ticket `id`, `title`, `status`, `depends_on` und Goal ohne Aufklappen. | ja | - |
| L2 | Die Filter nach Status und Validierungszustand reagieren und verändern die Treffermenge nachvollziehbar. | ja | - |
| L3 | Die Zustände `unparsed`, `invalid`, `valid` und „Inhalt maskiert" sind auf einen Blick unterscheidbar. | ja | - |
| L4 | Veraltete Projektionen sind als „Veraltet" samt Prädikat erkennbar. | ja | - |
| L5 | „Refresh beauftragen" ist bedienbar und führt zur Operationsansicht; der Auftragszustand bleibt sichtbar. | ja | - |
| L6 | Das Ticketdetail hebt die Pflichtfelder hervor und zeigt bei `invalid` die strukturierten Fehler lesbar. | ja | - |
| L7 | Die Browserkonsole enthält keine CSP- oder `unsafe-eval`-Verstöße. Bekannte offene Abweichung: Livewire v4.4.0 versucht beim Laden die Injektion des deaktivierten Fortschrittsbalken-`<style>`; der von der CSP geblockte Eintrag trägt den Hash `sha256-wHM+htXdtkideW9K/pE8sHwN7LYOKJTCZfrrEvY5Qvg=`. Erscheint dieser oder ein anderer Eintrag, ist mit „nein" zu antworten und der Eintrag als Befund zu vermerken. | ja | - |

## 3. Prüfschritte auf dem Smartphone

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| S1 | Liste und Detail benötigen für `id`, `title`, `status`, `depends_on`, Goal und den Validierungszustand kein horizontales Scrollen. | ja | - |
| S2 | Die Filter sind mit dem Daumen bedienbar und reagieren ohne Seitenneuladen. | ja | - |
| S3 | „Refresh beauftragen" ist bedienbar; Commit-/Blob-Bindung, Aktualisierungszeit und Staleness bleiben lesbar. | ja | - |
| S4 | Die Zustände bleiben auch auf dem schmalen Viewport unterscheidbar, einschließlich Fehler- und Maskierungskennzeichen. | ja | - |
| S5 | Lange Werte (Commit, Blob-SHA, Contract-Hash) brechen um, statt die Seite zu verbreitern. | ja | - |

## 4. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis (bestanden / nicht bestanden) | bestanden |
| Befunde und Nacharbeiten | Den Browserkonsole Fehler prüfen |

## 5. Unterschrift

| Feld | Wert |
|---|---|
| Name der Prüfperson | Michael Strübing |
| Datum | 12.08.2026 |
| Unterschrift | Michael Strübing |
