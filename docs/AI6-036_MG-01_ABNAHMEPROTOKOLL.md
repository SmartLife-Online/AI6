# AI6-036/MG-01 — Abnahmeprotokoll

Dieses Protokoll wird ausschließlich von einer menschlichen Prüfperson nach einem frischen Linux-Lauf ausgefüllt. Automatisierte Tests und Windows-Läufe tragen kein Ergebnis ein.

## Bindung

- Implementierungscommit:
- Image-Digest:
- Host:
- Datum und Uhrzeit:
- Prüfperson:

## Installationsablauf

- [ ] Frischer Linux-Host mit wirklich leeren Volumes; Volume-Namen und Ausgangszustand protokolliert
- [ ] AppArmor aktiv; beide Rollenprofile nach README installiert und im Enforce-Modus geladen; `aa-status`-Auszug abgelegt
- [ ] Schlüssel ohne Host-PHP erzeugt und expliziter Ring gesetzt
- [ ] `docker compose up -d --build` und `init` erfolgreich
- [ ] `/proc/self/attr/current` in `agent` exakt `ai6-agent-v1 (enforce)` und in `checker` exakt `ai6-checker-v1 (enforce)`; Ausgaben abgelegt
- [ ] `docker compose exec app php artisan ai6:install` ausgeführt
- [ ] erster Administrator angelegt
- [ ] `known_hosts` und Git-Allowlisten gesetzt
- [ ] Providerlogin in der Agentrolle ausgeführt
- [ ] Unter dem echten Agentprofil: Login-Verzeichnis rw mit `auth.json` ro, Credential-Projektion ro und Probe-Home mit Input ro/Output rw nachgewiesen; Bind von `/run/ai6/provider-private` selbst mit `permission denied` abgewiesen
- [ ] `docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict` ausgeführt
- [ ] `ai6:runtime-health --role=worker` erfolgreich
- [ ] `ai6:runtime-health --role=scheduler` erfolgreich
- [ ] `ai6:release-gate` im Linux-Checkout desselben Commits ausgeführt

`ai6:install` nach Administratoranlage erneut ausführen. Jeder Aufruf erhält einen eigenen Eintrag; Geheimnisse werden vor Ablage redigiert.

| Kommando / Container | Ausgabe oder Evidenzpfad | Exitcode |
|---|---|---|
| `sudo aa-status` / Host | | |
| `docker compose exec agent cat /proc/self/attr/current` | | |
| `docker compose exec checker cat /proc/self/attr/current` | | |
| Login-/Probe-/Credential-Projektion und verweigerter Private-Root-Bind / `agent` | | |
| `ai6:install` / `app` | | |
| strict-Doctor / `worker` | | |
| `ai6:runtime-health --role=worker` / `worker` | | |
| `ai6:runtime-health --role=scheduler` / `scheduler` | | |
| `ai6:release-gate` / Linux-Checkout | | |

Doctor-Exitcode:

Release-Gate-Exitcode:

## Erwartete Doctor-Befunde

Nur folgende fremd zugeordneten Befunde dürfen offen bleiben:

| Befund | Zuständiges Gate | Beobachtung |
|---|---|---|
| `security_review_adapter_fake` | `AI6-050` | |
| `degraded`/`runtime` für `codex_cli` | `AI6-033/MG-01` | |
| `degraded`/`runtime` für `grok_cli` | `AI6-041/MG-01` | |
| `degraded`/`runtime` für `github_copilot_cli` | `AI6-048/MG-01` | |
| Grok-Sandboxblocker | bestehender README-Abschnitt | |

Jeder andere Befund hält dieses Gate offen. Kein Evidenzwert wird ohne bestandenes zugehöriges Gate gesetzt. Der VPN-/HTTPS-Weg ist Referenz ohne Abnahme und nicht Teil dieses Gates.

AppArmor-Verweigerungen der vorgesehenen Login-, Probe- oder Credential-Projektion sind keine erlaubten Restbefunde. Beobachtungen einschließlich Parserprüfung und redigierter Rohprotokolle gehören in die Befundliste; ein credentialfreier Turn allein schließt diesen Nachweis nicht.

## Zugang und Negativfälle

- [ ] Anmeldung über den eingeschränkten SSH-Tunnel mit Passkey oder TOTP
- [ ] echte Login-Bestätigungsmail zugestellt
- [ ] Remote-Kommando abgewiesen
- [ ] SFTP abgewiesen
- [ ] anderes Weiterleitungsziel abgewiesen
- [ ] Remote-Weiterleitung abgewiesen

## Entscheidung

- [ ] bestanden
- [ ] offen

Begründung und sichere, redigierte Ausgaben:

Unterschrift:
