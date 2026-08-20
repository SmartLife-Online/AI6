# Abnahmeprotokoll AI6-045 / MG-01 — Reale Checkerrollen-Ausführung

Ergebnisfreie Vorlage. Bindung, Beobachtungen, Ergebnis und Unterschrift werden ausschließlich
von der menschlichen Prüfperson eingetragen. Das Gate bleibt offen, bis dieses Protokoll im
realen Linux-Compose-Stack vollständig ausgefüllt und signiert vorliegt. Automatisierte Tests
und übersprungene POSIX-Nachweise tragen kein Ergebnis ein.

## 0. Vorbereitung

Der Stack läuft mit Securityprofil `strict`, aktiver
`AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION` und ohne bestätigten Reduced Mode. Ein
verwaltetes Testprojekt besitzt einen erfolgreichen, länger als das konfigurierte
Heartbeat-Maximalalter laufenden `before_review`-Check sowie eine Sonde für verbotene Pfade,
Credentials, Gitmetadaten und Netzschnittstellen.

## 1. Bindung

| Feld | Wert |
|---|---|
| Geprüfter Commit | |
| Datum und Uhrzeit | |
| Docker-/Compose-Version | |
| Checker-Boot-ID | |

## 2. Rollen- und Prozessgrenze

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| R1 | Der Worker staged genau eine read-only Quelle samt leerem `baseline` und genau einen Auftrag. | | |
| R2 | Genau ein Prozess läuft unter UID/GID `10003:10001` in der Checkerrolle und einer eigenen Mount-/PID-Sicht. | | |
| R3 | Der private Arbeitsbaum ist beschreibbar; Quelle und Auftragsumschlag bleiben bytegleich. | | |
| R4 | Managed-Clone, Deploy-Keys, Provider-/Git-/SMTP-/Datenbankcredentials, Gitmetadaten, IPC-, Ergebnis- und Heartbeatpfade sind im Check-Unterprozess nicht erreichbar. | | |
| R5 | Außer Loopback ist keine Netzwerkschnittstelle sichtbar. | | |

## 3. Heartbeat, Ergebnis und Wiederaufnahme

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| H1 | Während des langen Checks bleiben lokaler und ausführungsgebundener Heartbeat frisch und bootgleich. | | |
| H2 | Das Ergebnis ist an Ausführungs-ID, Run, Phase, Profil, Quellbaum, Frist und Checker-Boot gebunden. | | |
| H3 | Wiederholtes Worker-Polling startet keinen zweiten Prozess und verbraucht das Retrymaximum nicht. | | |
| H4 | Nach Erfolg bleiben kein privater Arbeitsstand und kein erneut konsumierbarer Auftrag zurück. | | |

## 4. Checkerabsturz

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| A1 | Der Checker wird nach Claim und vor Ergebnisveröffentlichung gestoppt. | | |
| A2 | Der Run endet benannt und nicht grün; der bereits gestartete Auftrag wird nicht blind erneut ausgeführt. | | |
| A3 | Ein neuer Checker-Boot macht den alten Auftrag nicht wieder lebendig; ein spätes Ergebnis wird nicht persistiert. | | |

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
