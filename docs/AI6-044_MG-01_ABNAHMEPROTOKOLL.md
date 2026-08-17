# Abnahmeprotokoll AI6-044 / MG-01 — Reales Copy/Paste in Codex Desktop und die Claude App

Ergebnisfreie Vorlage. Bindung, Beobachtungen, Ergebnis und Unterschrift werden ausschließlich
von der menschlichen Prüfperson eingetragen. Das Gate bleibt offen, bis dieses Protokoll
ausgefüllt und signiert vorliegt. Automatisierte Tests und übersprungene Browser-Smokes
tragen kein Ergebnis ein.

## 0. Vorbereitung

Geprüft wird die laufende Weboberfläche mit einem vollständig authentifizierten Benutzer.
Die Prompt-Hilfe ist über die Hauptnavigation ohne Projektwahl erreichbar. Für den dynamischen
Pfad wird eine vollständige Reviewantwort mit genau einem terminalen Abschnitt `### Fix-Liste`
verwendet; mindestens ein Finding soll einen redigierbaren sensiblen Wert enthalten, damit der
zentrale Marker in Vorschau und eingefügtem Text sichtbar bleibt.

Die Prüfung verwendet reale Desktop-Anwendungen, keine Emulation:

- Codex Desktop;
- die Claude App;
- denselben Browser, in dem die Prompt-Hilfe bedient wird.

Prüfschritt S1 (Erfolgsmeldung erst nach bestätigtem Clipboard-Schreiben) setzt einen
Secure Context voraus: die Prompt-Hilfe muss über HTTPS oder über `localhost`/`127.0.0.1`
erreicht werden. Auf einer sonstigen Plain-HTTP-Herkunft fehlt `navigator.clipboard`, sodass
nur der manuelle Fallback erscheint und S1 nicht als Clipboard-Erfolg bewertet werden kann.

Zusätzlich wird einmal die Zwischenablageberechtigung verweigert oder die Clipboard-API
deaktiviert, um den manuellen Vollselektions-Fallback zu prüfen.

## 1. Bindung

Die Evidenz ist an genau einen Stand gebunden. Zulässig ist der geprüfte Git-Commit oder —
vor einem Commit — Base-Commit **und** SHA-256 des vollständigen Prüfdiffs.

Commit-Bindung ermitteln:

```
git rev-parse HEAD
```

| Feld | Wert |
|---|---|
| Geprüfter Commit | |
| Datum und Uhrzeit der Prüfung | |

## 2. Statischer Prompt

Jede Zeile wird mit „ja" oder „nein" sowie bei Auffälligkeiten mit einer kurzen Beobachtung
ausgefüllt.

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| S1 | Ein statischer Prompt wird aus der read-only Vorschau kopiert; die Erfolgsmeldung erscheint erst nach bestätigtem Clipboard-Schreiben. | | |
| S2 | Der in Codex Desktop eingefügte Text stimmt bytegleich mit der Vorschau überein. | | |
| S3 | Der in die Claude App eingefügte Text stimmt bytegleich mit der Vorschau überein. | | |

## 3. Dynamischer Prompt

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| D1 | Eine vollständige Reviewantwort mit terminalem `### Fix-Liste` erzeugt eine Vorschau, die ausschließlich die redigierte Liste genau einmal enthält. | | |
| D2 | Der in Codex Desktop eingefügte dynamische Prompt stimmt bytegleich mit der Vorschau überein; Redactionmarker bleiben erhalten. | | |
| D3 | Der in die Claude App eingefügte dynamische Prompt stimmt bytegleich mit der Vorschau überein; Redactionmarker bleiben erhalten. | | |

## 4. Fallback ohne Clipboard-Erfolg

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| F1 | Bei verweigerter oder fehlender Clipboard-API wird die vollständige Vorschau selektiert. | | |
| F2 | Eine manuelle Kopieranweisung ist sichtbar, und es erscheint keine Erfolgsmeldung. | | |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis (bestanden / nicht bestanden) | |
| Befunde und Nacharbeiten | |

## 6. Unterschrift

| Feld | Wert |
|---|---|
| Name der Prüfperson | |
| Datum | |
| Unterschrift | |
