# AI6-036/MG-01 — Abnahmeprotokoll

Dieses Protokoll wird ausschließlich von einer menschlichen Prüfperson nach einem frischen Linux-Lauf ausgefüllt. Automatisierte Tests und Windows-Läufe tragen kein Ergebnis ein.

## Bindung

- Implementierungscommit:
- Image-Digest:
- Host:
- Datum und Uhrzeit:
- Prüfperson:

## Installationsablauf

- [ ] Schlüssel ohne Host-PHP erzeugt und expliziter Ring gesetzt
- [ ] `docker compose up -d --build` und `init` erfolgreich
- [ ] `docker compose exec app php artisan ai6:install` ausgeführt
- [ ] erster Administrator angelegt
- [ ] `known_hosts` und Git-Allowlisten gesetzt
- [ ] Providerlogin in der Agentrolle ausgeführt
- [ ] `docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict` ausgeführt
- [ ] `ai6:runtime-health --role=worker` erfolgreich
- [ ] `ai6:runtime-health --role=scheduler` erfolgreich
- [ ] `ai6:release-gate` im Linux-Checkout desselben Commits ausgeführt

## Erwartete Doctor-Befunde

Nur folgende fremd zugeordneten Befunde dürfen offen bleiben:

| Befund | Zuständiges Gate | Beobachtung |
|---|---|---|
| `security_review_adapter_fake` | `AI6-050` | |
| `degraded`/`runtime` je Provideralias | `AI6-033/MG-01`, `AI6-041/MG-01`, `AI6-048/MG-01` | |
| Grok-Sandboxblocker | bestehender README-Abschnitt | |

Jeder andere Befund hält dieses Gate offen.

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

