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
| Geprüfter Commit | _____________________________ |
| Alternativ: Base-Commit | _____________________________ |
| Alternativ: SHA-256 des Prüfdiffs | _____________________________ |
| Datum und Uhrzeit der Prüfung | _____________________________ |

## 2. Prüfumgebung

| Feld | Wert |
|---|---|
| Laptop (Gerät, Betriebssystem, Browser samt Version) | _____________________________ |
| Smartphone (Gerät, Betriebssystem, Browser samt Version) | _____________________________ |
| Geprüftes Projekt und Anzahl der Projektionen | _____________________________ |

## 3. Prüfschritte auf dem Laptop

Jede Zeile wird mit „ja" oder „nein" sowie bei Auffälligkeiten mit einer kurzen Beobachtung
ausgefüllt.

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| L1 | Die Ticketliste zeigt je Ticket `id`, `title`, `status`, `depends_on` und Goal ohne Aufklappen. | ___ | ___ |
| L2 | Die Filter nach Status und Validierungszustand reagieren und verändern die Treffermenge nachvollziehbar. | ___ | ___ |
| L3 | Die Zustände `unparsed`, `invalid`, `valid` und „Inhalt maskiert" sind auf einen Blick unterscheidbar. | ___ | ___ |
| L4 | Veraltete Projektionen sind als „Veraltet" samt Prädikat erkennbar. | ___ | ___ |
| L5 | „Refresh beauftragen" ist bedienbar und führt zur Operationsansicht; der Auftragszustand bleibt sichtbar. | ___ | ___ |
| L6 | Das Ticketdetail hebt die Pflichtfelder hervor und zeigt bei `invalid` die strukturierten Fehler lesbar. | ___ | ___ |
| L7 | Die Browserkonsole enthält keine CSP- oder `unsafe-eval`-Verstöße. Bekannte offene Abweichung: Livewire v4.4.0 versucht beim Laden die Injektion des deaktivierten Fortschrittsbalken-`<style>`; der von der CSP geblockte Eintrag trägt den Hash `sha256-wHM+htXdtkideW9K/pE8sHwN7LYOKJTCZfrrEvY5Qvg=`. Erscheint dieser oder ein anderer Eintrag, ist mit „nein" zu antworten und der Eintrag als Befund zu vermerken. | ___ | ___ |

## 4. Prüfschritte auf dem Smartphone

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| S1 | Liste und Detail benötigen für `id`, `title`, `status`, `depends_on`, Goal und den Validierungszustand kein horizontales Scrollen. | ___ | ___ |
| S2 | Die Filter sind mit dem Daumen bedienbar und reagieren ohne Seitenneuladen. | ___ | ___ |
| S3 | „Refresh beauftragen" ist bedienbar; Commit-/Blob-Bindung, Aktualisierungszeit und Staleness bleiben lesbar. | ___ | ___ |
| S4 | Die Zustände bleiben auch auf dem schmalen Viewport unterscheidbar, einschließlich Fehler- und Maskierungskennzeichen. | ___ | ___ |
| S5 | Lange Werte (Commit, Blob-SHA, Contract-Hash) brechen um, statt die Seite zu verbreitern. | ___ | ___ |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis (bestanden / nicht bestanden) | _____________________________ |
| Befunde und Nacharbeiten | _____________________________ |

## 6. Unterschrift

| Feld | Wert |
|---|---|
| Name der Prüfperson | _____________________________ |
| Datum | _____________________________ |
| Unterschrift | _____________________________ |
