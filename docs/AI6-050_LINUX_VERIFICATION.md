# AI6-050 – Linux-Nachweis mit unverändertem Repository-Profil

Stand: 29. September 2026. Die Übernahme der vier Mount-Regeln wurde im Chat
mit „Freigabe hiermit erteilt“ ausdrücklich genehmigt; siehe
`AI6-050_APPARMOR_SCOPE_REQUEST.md`. Die Regeln sind jetzt in
`docker/apparmor/ai6-execution` enthalten. Dieser Nachweis ersetzt den vorherigen
Nachweis mit Testpatch. Er ist keine menschliche oder externe Provider-Abnahme.

## Bindung des geprüften Standes

- Basiscommit: `86c760338815e8007ea5507bb6d90095c10b79ba`.
- Getestet wurde der uncommittierte Arbeitsstand auf dieser Basis. Kein neuer
  Commit und kein Push wurden erstellt.
- SHA-256 des vollständigen Quell-/Dependency-Manifests `source.sha256`:
  `405ccf7f019b2ea1fc40cb8d38df0deb83370bab6b3c0bcfd614721188cda03e`.
- Runtimeimage-ID:
  `sha256:674a6d9f0d2ce967d7f4abb81eeb5588b72b708d21192f7e3a6b0269ef19669e`.
- Neu gebautes Testimage:
  `sha256:6ddbfc6a95bd696b32d2dc3c87faf5ae8027c8185e40ef61a569a9aeefa9cfdd`.
- PHP 8.5.5, PHPUnit 12.5.33, Linux/amd64.
- Profil-SHA-256, identisch im Repository und in der geladenen Kopie:
  `ce9e441d7b0a25f1a679aec0e590c8732f8c4814e04db367da592e0ba1f9bbfc`.
- Tatsächliches Kernel-Label im separaten Namespace:
  `ai6-agent-v1 (enforce)`.

`run.sh` prüft die Profilkopie mit `cmp`; es findet keine Patch-Anwendung statt.
Der alte `apparmor-turn-homes.patch` bleibt ausschließlich als historischer
Entwurf erhalten. Quelle und Dependencies werden in ein frisches Verzeichnis
kopiert. Die Compose-Regressionen erhalten zusätzlich die versionierten
Vorlagen `.env.example`, `docker-compose.yml` und `deploy/Caddyfile`; eine echte
`.env` wird weder übernommen noch verwendet.

## Aufruf und Ergebnisse

Voraussetzungen und Image-Rezept stehen in README unter „Reproduzierbarer
Linux-Mailboxtest (AI6-050)“. Tatsächlich ausgeführter Aufruf:

```bash
bash /home/ops/ai6-050-review.XzPMqn/source/tests/Fixtures/Agents/container/run.sh \
  /home/ops/ai6-050-review.XzPMqn/source \
  ai6-runtime:php-8.5.5_sqlite-3.53.4 \
  86c760338815e8007ea5507bb6d90095c10b79ba
```

Erzeugtes Verzeichnis: `/tmp/ai6-native.dBjGEGYd`.

```bash
sudo -n docker exec ai6-native-c1e7ff831818b9c7-worker php vendor/bin/phpunit \
  tests/Feature/Agents/AgentExecutionMailboxTest.php \
  --filter=test_runner_home_paths_match_the_shipped_apparmor_projection_contract --testdox

sudo -n docker exec ai6-native-c1e7ff831818b9c7-worker php vendor/bin/phpunit \
  tests/Feature/Reviews/CopilotSecurityReviewTest.php \
  tests/Feature/Shared/Doctor/GitHubCopilotCliDoctorCheckTest.php \
  tests/Feature/Agents/GitHubCopilotCliExecutionTest.php \
  tests/Feature/Reviews/FindingVerificationRoundTest.php \
  tests/Unit/Agents/GitHubCopilotCliAdapterTest.php \
  tests/Feature/Agents/GitHubCopilotCliSmokeTest.php \
  tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php --testdox
```

- Runner-Pfadvertrag: **1 bestanden, 39 Assertions, Exitcode 0**. Prüft die
  tatsächlich erzeugten Eingabe-/Ausgabepfade, ihre Bindung an Execution-ID
  und `request.home` sowie die Übereinstimmung mit den Profilprojektionen.
- Hauptlauf: **115 Tests, 112 bestanden, 3 übersprungen, 10900 Assertions,
  Exitcode 0**. Alle 14 Security-Szenarien, native Execution, Doctor, Finding
  Verification, POSIX-Adapterfälle, MG-01-Vorlage und Compose-Verträge liefen.
- Die drei Skips sind ausschließlich die explizit opt-in aktivierbaren echten
  Provider-Smokes. Sie werden nicht als externe Evidenz gezählt.
- Root-Mount-Gegenprobe: Exitcode 1 mit
  `Can't bind mount /oldroot/ on /newroot/` und `Permission denied`.
  Der Test verlangt genau die Mount-Verweigerung; ein früherer Fehler beim
  Erzeugen des Namespace würde die Gegenprobe nicht bestehen lassen.

Damit bestehen insgesamt **113 Tests mit 10939 Assertions**, bei drei
bewusst nicht aktivierten Provider-Smokes.

Vollständige lokale Belege: `storage/logs/ai6-050-shipped-profile/` mit
`evidence.txt`, `source.sha256`, `contract.log`, `phpunit.log`,
`root-mount-negative.log`, `build.log` und `supervisor.log`. Die Laufartefakte
sind git-ignoriert; das Verfahren und dieser Bericht liegen im Repository.
Die Nachweisdokumente wurden nach dem Lauf aktualisiert; für die getesteten
Code-/Konfigurationsbytes gilt das oben gebundene Vorlaufmanifest.

## Weitere Prüfungen und Grenzen

- Lokal: 23 betroffene Vertragstests bestanden, 1536 Assertions.
- PHPStan, Pint, Ticketmanifest, `composer validate --strict` und
  `git diff --check`: bestanden.
- Der erste lokale Testversuch enthielt einen falschen PHPUnit-Methodennamen;
  nach Korrektur bestehen die Tests. Ein VPS-Zwischenlauf wurde wegen noch
  fehlender Compose-Testvorlagen in der Kopie beendet. Der oben dokumentierte
  vollständige Wiederholungslauf enthält diese Vorlagen und ist grün.
- Kein vollständiger Regular-Suite-Lauf und kein externer Locked-install-Lauf
  in dieser Finding-Fixiteration; der Abhängigkeits-/Plattformvertrag wurde
  nicht geändert.
- Der Aufruf beendet seine eigenen Container, Volumes, den Host-Supervisor
  und die geladenen Testprofile. Die Laufartefakte bleiben erhalten.
- Das Live-Profil wurde nicht neu geladen und es gab kein Deployment. Die
  Live-Instanz benötigt die gesonderte Auslieferung/Aktivierung des korrigierten
  Profils. MG-01 und sämtliche externen Provider-Gates bleiben offen.

Zusätzlicher Scope: der freigegebene sensible Pfad
`docker/apparmor/ai6-execution`, der Runner-Pfadvertrag in
`tests/Feature/Agents/AgentExecutionMailboxTest.php`, die Profilverträge in
`tests/Unit/Shared/Runtime/RuntimeComposeContractTest.php`, der isolierte
Starter sowie README und die Scope-/Nachweisdokumente. Ticketstatus,
AGENTS.md und manuelle Gate-Ergebnisse wurden nicht geändert.

## Nicht gefixte Findings

Keine. Die beiden Findings des freigegebenen Folgeauftrags sind im Repository
behoben; eine produktive Auslieferung war nicht Gegenstand der Freigabe.
