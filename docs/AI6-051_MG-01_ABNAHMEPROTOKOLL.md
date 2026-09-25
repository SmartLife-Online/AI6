# AI6-051 / MG-01 — Reales SHA-1-Projekt über SSH

Ergebnisfreies Abnahmeformular. Dieses Dokument enthält weder ein bestandenes Gate noch eine Freigabe anderer Tickets.

Vorgesehenes Testremote ist `SmartLife-Online/Smartlife-Online-Sales-Chatbot` am Ref `refs/heads/neues_design_v1`; ein gleichwertiges SHA-1-Testremote benötigt eine ausdrückliche Freigabe. Vor dem Lauf müssen Containerbytes und eingetragener Implementierungscommit übereinstimmen. Erforderliche Laufzeitkorrekturen sind vorher gesondert zu entscheiden und in diesen Candidate aufzunehmen.

## Candidate und Freigabe

| Bindung | Menschlich einzutragender Wert |
|---|---|
| Finaler Implementierungscommit | |
| Betriebsumgebung und Image-Digest | |
| Git-Version im laufenden Image | |
| Freigegebenes SHA-1-Testprojekt / SSH-Remote | |
| Control-Ref | |
| Erwartete vollständige Control-OID | |
| Konkrete Ticket- und Config-Datei am Control-Ref | |
| Autorisiertes Wegwerfremote für fehlende Dateien, falls erforderlich | |
| Tester und Zeitpunkt | |
| Umfang der menschlichen Testfreigabe | |

## Durchführung

1. Ruhenden Upgradebetrieb und vorheriges Backup bestätigen. Migration erfolgreich ausführen; bei einer Abweichung keine automatische Datenreparatur vornehmen.
2. Projektseite neu laden. Nach terminalem ursprünglichem Erstclonefehler **Clone starten** mit neuer Operations-ID auslösen. Alte Operations-ID und unveränderten Fehlernachweis dokumentieren.
3. SSH-Clone am freigegebenen Remote ausführen lassen. Exakte Ref/OID, bestätigtes Git-Speicherformat `sha1`, `projects.object_format` sowie gemeinsam finalisierte Control-Bindung erfassen.
4. Fetch desselben Control-Refs auslösen. Erwartete und bestätigte OID, Operations-ID sowie unverändertes Projektformat erfassen.
5. Ticket- und Config-Refresh für die zuvor festgestellten Dateien auslösen und sichtbaren, an Control-/Blob-OID gebundenen Stand prüfen. Fehlt eine Datei, den `absent`-Befund getrennt dokumentieren und den entsprechenden positiven Refresh auf einem ausdrücklich autorisierten Wegwerfremote nachweisen. Keine Providerantwort, keinen privaten Schlüssel und keine Credentials in dieses Protokoll kopieren.
6. Beobachteten Prozess-/SSH-Nachweis mit aktivem Hostpinning, Credentialtrennung und unveränderten Git-Sicherheitsgrenzen referenzieren. Produktive Pushes sind durch dieses Gate nicht freigegeben.

## Beobachtungen

| Schritt | Operations-ID / Ref / OID / Evidenzreferenz | Endzustand und beobachtetes Ergebnis |
|---|---|---|
| Ursprünglicher Fehler / neuer Auftrag | | |
| Clone und Formatfinalisierung | | |
| Fetch | | |
| Ticket-Refresh | | |
| Config-Refresh | | |
| Fehlende Datei (`absent`) / positiver Ersatznachweis, falls erforderlich | | |
| Sicherheitsbeobachtung | | |

## Menschliche Entscheidung

| Feld | Eintrag |
|---|---|
| Ergebnis | |
| Abweichungen / Folgemaßnahmen | |
| Unterschrift / Zeitpunkt | |

Die Entscheidung gilt ausschließlich für den eingetragenen Candidate. Spätere Implementierungsänderungen erfordern erneuten Test und Unterschrift. AI6-036/MG-01 und sämtliche historischen Gates bleiben unabhängig.
