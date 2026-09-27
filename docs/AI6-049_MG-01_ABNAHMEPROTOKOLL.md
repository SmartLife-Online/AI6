# AI6-049 / MG-01 — Menschliche Restore-Übung

Ergebnisfreie Vorlage. Dieses Dokument enthält keine Abnahme und kein Testergebnis.
Nur die menschliche Prüfperson füllt Beobachtungen, Ergebnis und Unterschrift aus.
Automatisierte Tests und Windows-Läufe tragen kein Ergebnis ein.

## Bindung

| Feld | Wert |
|---|---|
| Geprüfter Implementierungscommit | |
| Image-Digest | |
| Quellhost | |
| Frischer Zielhost | |
| Datum und Uhrzeit (UTC) | |
| Prüfperson | |
| Docker- und Compose-Version | |
| Backupkennung und Manifest-SHA-256 | |
| Herkunft des getrennten Schlüsselpakets (keine Werte) | |

## Vorbereitung

Vor Durchführung die in README beschriebene private Workerstruktur unter
`/var/lib/ai6/managed/credentials` und `/var/lib/ai6/managed/backups` prüfen.
Bei einer Bestandsinstanz auch die Dateiübernahme und die Datenbankreferenzen
mit `ai6:relocate-credentials` prüfen. Managed-Root und Effect-Locks bleiben
privilegiert geschützt. Diese Vorlage enthält kein positives Laufzeitergebnis.

Eine strict betriebene Testinstanz gemäß README mit einem provisionierten Projekt,
funktionierendem Git-Zugriff, eingerichtetem TOTP-Faktor und einer angemeldeten
Browsersession bereitstellen. Einen gültigen und einen bis zum Restore ablaufenden
Artefaktinhalt sowie ein ablaufendes Runlog und einen vorhandenen Tombstone vorsehen.
Alle Runs und Control Operations abschließen. Der Zielhost verwendet denselben
Software- und Migrationsstand. Er besitzt weder ursprüngliche Volumes noch weiterhin
erreichbare ursprüngliche Schlüsseldateien; nur das getrennt gesicherte Schlüsselpaket
wird kontrolliert eingebracht. Geheimnisse niemals in dieses Protokoll kopieren.

## Durchführung

| Nr. | Prüfschritt | Beobachtung / redigierte Evidenz | Ergebnis |
|---|---|---|---|
| 1 | Dauerhafte Rollen stoppen; ruhenden Zustand belegen. | | |
| 2 | `ai6:backup` im Worker-Einmalcontainer mit `--no-deps` ausführen; Exitcode und vollständiges Manifest prüfen. | | |
| 3 | Backuprechte, getrenntes Schlüsselpaket und Off-Host-Kopie prüfen; Provider-Store fehlt im Backup. | | |
| 4 | Frische Zielinstanz mit neuen Volumes und gebundenem Image bereitstellen; ursprüngliche Volumes und Schlüsseldateien sind unerreichbar. | | |
| 5 | Nach dem Ablaufzeitpunkt `ai6:restore` ausführen; Exitcode, Sweep-Zähler und Entfernung der Rückwegkopien dokumentieren. | | |
| 6 | Eine Session aus dem Sicherungsstand erneut verwenden: nächster Request verlangt Anmeldung; offene Login-Challenges sind widerrufen. | | |
| 7 | Gültiges Artefakt lesen; abgelaufene Daten fehlen in Runansicht und Storage, Download antwortet 410; Tombstones bleiben erhalten. Zweiter Sweep verändert nichts. | | |
| 8 | Control-Branch und offene Wirkungen seit der Sicherung abgleichen; Managed-Clone bei Bedarf über die vorhandene Clone-Operation rekonstruieren. Git-Zugriff mit wiederhergestelltem Deploy-Key und `known_hosts` nachweisen. | | |
| 9 | Zugangsrechte und Recovery-Codes prüfen, erforderlichenfalls `ai6:reissue-recovery-codes` ausführen; Providerlogins gemäß AI6-035 neu einrichten. | | |
| 10 | APP_KEY wechseln, alten Wert in APP_PREVIOUS_KEYS erhalten; wiederhergestelltes TOTP bleibt nutzbar. Keine Schlüsselwerte protokollieren. | | |
| 11 | Erst nach erfolgreicher Wiederanlaufprüfung dauerhafte Rollen starten und Zugriff prüfen. | | |

## Entscheidung

| Feld | Wert |
|---|---|
| Gesamtergebnis (bestanden / nicht bestanden) | |
| Offene Befunde und Einschränkungen | |
| Evidenzablage | |
| Unterschrift der Prüfperson | |
| Datum | |

Jede spätere Änderung der geprüften Implementierungsbytes verlangt eine neue Bindung
und Prüfung. Eine reine spätere Dokumentation der unterschriebenen Entscheidung
ersetzt oder verändert die hier gebundene Implementierung nicht.
