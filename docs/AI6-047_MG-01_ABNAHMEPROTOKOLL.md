# Abnahmeprotokoll AI6-047 / MG-01 — Reale Agentrollen-Ausführung

Ergebnisfreie Vorlage. Die menschliche Prüfperson trägt Bindung, Beobachtungen,
Ergebnis und Unterschrift nach Durchführung im realen Linux-Compose-Stack ein.
Automatisierte Tests, Windowsläufe und übersprungene POSIX-Prüfungen schließen
dieses Gate nicht. Jede spätere Implementierungsänderung erfordert eine neue
Prüfung am exakten Implementierungscommit.

## 0. Vorbereitung

Der Stack läuft mit Securityprofil `strict` und aktiver
`AI6_SECURITY_REQUIRE_AGENT_SANDBOX`, ohne bestätigten Reduced Mode. Ein
verwaltetes Testprojekt besitzt einen freigegebenen Fake-Implementierungsturn
und eine Reviewrunde mit zwei Slots. Die für diese Testumgebung freigegebene
Agentpolicy enthält das tatsächlich eingesetzte PHP-Executable. Der Agentdienst
führt `ai6:execution-mailbox agent` als `10002:10001` aus. Ein längerer Turn
überschreitet das konfigurierte Heartbeat-Maximalalter.

## 1. Bindung

| Feld | Wert |
|---|---|
| Geprüfter Implementierungscommit | |
| Datum und Uhrzeit | |
| Docker-/Compose-Version und Image | |
| Agent-Boot-ID | |
| Run-, Slot-, Session- und Ausführungs-IDs | |
| Kontext-, Prompt-, Instruktions- und Runtimeprofil-Hashes | |
| Credential-Revision, Frist und Heartbeatwerte | |

## 2. Rollen- und Prozessgrenze

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| R1 | Der Worker staged pro Turn genau einen Auftrag und ein versiegeltes Home mit vollständigem `runtime/turn.json`; vor Übergabe startet kein Providerprozess. | | |
| R2 | Der Agentprozess liest keine Datenbank und erreicht den Adapter ausschließlich unter der Agentpolicy und dem vollständigen `ProcessIsolationVerifier`. | | |
| R3 | Managed-Clone, Deploy-Keys, Git-/SMTP-/Datenbankcredentials, fremde Providerprofile sowie Auftrags-, Claim- und Heartbeatpfade sind aus dem Adapterprozess unerreichbar. | | |
| R4 | Instruktions-Snapshot, Runtimeprofil und Authprojektion bleiben read-only. Host-/Parent-Instruktionen und Gitmetadaten sind unerreichbar. | | |
| R5 | Nur der Implementierungsworkspace im Änderungsausgang ist beschreibbar; der Worker validiert Projektion und Patch vor dem Import. Reviewworkspaces bleiben read-only. | | |

## 3. Ergebnis und Wiederaufnahme

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| H1 | Rollen- und Ausführungsheartbeat bleiben während des langen Turns frisch und bootgleich. | | |
| H2 | Doppelte Zustellung und ergebnislose Polls starten keinen zweiten Prozess und verbrauchen keinen zusätzlichen Versuch. | | |
| H3 | Das Ergebnis bindet Ausführungs-ID, Run, Slot, Session, Rolle, Versuch, Snapshots, Runtimeprofil, Revision, Frist, Boot und Antwortdatei-Hash. | | |
| H4 | Implementierungs-, Fix-, Review-, Verifikations- und Security-Review-Ergebnisse erreichen ihre bestehenden fachlichen Ergebniszeilen; der zweite Reviewslot folgt dem ersten gebundenen Ergebnis. | | |
| H5 | `invalid_json` und `provider_error` bleiben unterscheidbar; Nutzungswerte samt Quelle oder `unknown` liegen am jeweiligen Providerartefakt. Identische Antworten verschiedener Turns behalten getrennte Metadaten und Speicherdateien. | | |
| H6 | Nach Abschluss bleiben keine turnbezogenen Homes, Exporte, Invocation-, Claim-, Heartbeat-, Antwort- oder Mailboxdateien zurück. Als rollenweite Infrastruktur bleiben Boot-Attestation und die vom Worker angelegte `.agent-lifecycle.lock`; der Agent öffnet die Sperrdatei ausschließlich lesend. | | |

## 4. Terminale Grenzen

| Nr. | Prüfschritt | ja/nein | Beobachtung |
|---|---|---|---|
| A1 | Ein nach Claim gestoppter Agent erreicht eine benannte, an Ausführung und Boot gebundene Grenze; der Auftrag startet nicht blind erneut. | | |
| A2 | Ein neuer Boot, ein veralteter Ausführungsheartbeat und eine überschrittene Frist führen nicht zu einem grünen Ergebnis. | | |
| A3 | Nach Cancel oder Credentialrotation eintreffende Antworten verändern weder Run, Session noch Artefakte. | | |
| A4 | Veränderte Kontext-, Runtime-, Umschlag- oder Antwortdateien und zusätzliche Felder werden abgewiesen. | | |
| A5 | Cleanupfehler bleiben sichtbar; ein altes Ergebnis wird auch nach einem erneuten Workerstart nicht wieder wirksam. | | |

## 5. Ergebnis

| Feld | Wert |
|---|---|
| Gesamtergebnis (bestanden / nicht bestanden) | |
| Evidenzreferenzen | |
| Befunde und Nacharbeiten | |

## 6. Unterschrift

| Feld | Wert |
|---|---|
| Name der Prüfperson | |
| Datum | |
| Unterschrift | |
