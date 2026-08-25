# AI6-026: POSIX-Verifikation der Git-Worker-Beweise

Dieses Protokoll dokumentiert die Linux-/POSIX-Ausführung der auf Windows selbst-skippenden Git-Worker-Tests für den Arbeitsstand von `AI6-026` (Basis `f60e32e`, uncommittet) sowie die dabei menschlich bestätigten Scope-Abweichungen. Es ersetzt weder die sanktionierte Compose-Laufzeit noch die offenen `MG`-Gates; es bindet ausschließlich die Testevidenz, die ein Windows-Lauf nicht erbringen kann (`AGENTS.md` §1, §4).

## 1. Gebundene Evidenz

Ausgeführt am 25. August 2026 in der unter Abschnitt 2 beschriebenen Behelfsumgebung, gegen den vollständigen Arbeitsstand ohne jede Instrumentierung:

```text
php artisan test --filter="RunCancellationExecutorTest|TicketMutationExecutorTest"
Tests: 19 passed (899 assertions)

php vendor/bin/phpunit tests/Feature/Git
OK (200 tests, 4994 assertions)
```

Damit sind gebunden:

- `TC-10`/`TC-12` von `AI6-026` (`tests/Feature/Git/RunCancellationExecutorTest.php`): die Abbruch-Saga-Crashmatrix für Soft-, Block- und Hard-Abbruch mit Unterbrechung und Wiederanlauf an jeder Phasengrenze `prepared → commit_prepared → control_confirmed → db_finalized` bis zum terminalen Run einschließlich idempotentem Replay, sowie der reale Compare-and-Swap-Konflikt mit fremdem, ähnlich aussehendem Statuscommit, Replay ohne Überschreiben und neuautorisierter terminaler Fortsetzung.
- Die drei Reparaturen in `app/AI6/Git/TicketMutationExecutor.php` aus Abschnitt 3, deren Beweise ausschließlich in dieser Suite laufen.
- Die zwölf Altbestands-Tests der Git-Suite, die auf der POSIX-Laufzeit schon vor diesem Arbeitsstand rot waren und die er schließt. Der Vergleichslauf gegen einen unveränderten `HEAD`-Klon (`f60e32e`, `git checkout -- . && git clean -fd` in derselben Umgebung) endet mit `Tests: 196, Assertions: 4660, Failures: 12`, der Arbeitsstand mit `OK (200 tests, 4994 assertions)`: keiner der Fehlschläge kommt hinzu, alle zwölf verschwinden. Abschnitt 1.1 listet sie namentlich mit dem jeweils behebenden Fix.

Transparenz: Zwischen den Beweisläufen traten unter paralleler Host-Last sporadische, je Lauf wechselnde Fehlschläge auf (Prozess-/Latenzjitter der Behelfsumgebung, kein Codebefund; identische Bytes liefen unmittelbar danach sechsmal in Folge grün). Für dauerhaft belastbare Beweise bleibt die sanktionierte Laufzeit maßgeblich.

### 1.1 Die zwölf zuvor roten Altbestands-Tests

Der Vergleichslauf wurde am 25. August 2026 zweimal ausgeführt und lieferte beide Male exakt dieselben zwölf Fehlschläge — es sind keine Jitter-Treffer. Jeder Eintrag nennt den Test, seinen Fehlschlag im `HEAD`-Klon und die Änderung des Arbeitsstands, die ihn schließt; alle genannten Pfade liegen im `files`-Scope von `tickets/AI6-026.md`.

| # | Test | Fehlschlag im `HEAD`-Klon | Behebender Fix |
|---|---|---|---|
| 1 | `ControlOperationPersistenceTest::test_claim_and_publish_architecture_has_one_atomic_claim_seam_and_owner_bound_publish` | Erwartete Writer-Liste identisch, aber `RunOrchestrator.php` vor `ProjectOperationLease.php` | Test-Fixture-Korrektur: `sort($writers, SORT_STRING)` in `tests/Feature/Git/ControlOperationPersistenceTest.php`. Die Verzeichnisdurchmusterung ist dateisystemabhängig; der Vertrag ist die Writer-*Menge*, nicht ihre Reihenfolge. Unter NTFS lief dieselbe Zusicherung zufällig grün. |
| 2 | `ImplementationImportIsolationTest::test_a_tampered_reported_path_and_post_validation_patch_leave_no_partial_state` | `process_policy_unavailable`, Job endet `failed` statt `succeeded` | Test-Fixture-Korrektur: `tests/Feature/Runs/BuildsImplementationTurnFixture.php` vergisst zusätzlich die Singletons `ProcessPolicyRegistry`, `ControlProcessRunner` und `RunPreflight`, wie es die umgebenden Zeilen für die übrigen konfigurationsabgeleiteten Singletons bereits tun. Der Konsolen-Boot der Migrationen konstruiert jedes Artisan-Kommando; das Mailbox-Kommando aus `AI6-045` fror die Registry damit auf die ausgelieferten Roots ein. Die ausgelieferte Prozessgruppen-Semantik bleibt unberührt. |
| 3 | `ImplementationImportIsolationTest::test_git_metadata_from_the_isolated_view_never_reaches_the_managed_clone` | wie 2 | wie 2 |
| 4 | `ImplementationImportIsolationTest::test_the_agent_process_cannot_reach_credentials_or_the_managed_worktree` | wie 2 | wie 2 |
| 5 | `ImplementationImportIsolationTest::test_the_implementation_turn_starts_with_the_shipped_agent_policy` | wie 2 | wie 2 |
| 6 | `ImplementationImportIsolationTest::test_has_registered_handler_is_true_for_implement` | wie 2 | wie 2 |
| 7 | `TicketMutationExecutorTest::test_worker_publishes_one_file_commit_and_finalizes_every_bound_projection` mit Datensatz `ticket edit` | Die verwaiste Attempt-Ref überlebt die terminale Bereinigung (`assertFalse($missingAttempt->succeeded())`) | Attempt-Ref-Bereinigung in `app/AI6/Git/TicketMutationExecutor.php` (Abschnitt 3, Punkt 2, erster Spiegelstrich) |
| 8 | dieselbe Methode mit Datensatz `ticket status change` | wie 7 | wie 7 |
| 9 | dieselbe Methode mit Datensatz `ticket approval` | `reviewed_ticket_blob_sha` weicht ab; der Test scheitert schon vor der Attempt-Ref-Zusicherung | Test-Fixture-Korrektur in `tests/Feature/Git/TicketMutationExecutorTest.php`: der geprüfte Quell-Blob wird vor `refresh()` gebunden. Die alte Zusicherung las dieselbe, inzwischen auf den Zielstand rehydrierte Instanz und war damit gegenüber der Folgezeile tautologisch. Danach greift zusätzlich der Fix aus 7. |
| 10 | `TicketMutationExecutorTest::test_published_commit_abandonment_refuses_history_rewrite_and_adoption_moves_forward` | Operation bleibt `recovery_required` statt `completed` | Adopt-Bindung in `app/AI6/Git/TicketMutationExecutor.php` (Abschnitt 3, Punkt 2, zweiter Spiegelstrich) |
| 11 | `TicketMutationExecutorTest::test_ticket_mutation_recovery_decisions_reconcile_only_the_bound_commit` mit Datensatz `adopt bound external commit` | wie 10 | wie 10 |
| 12 | `TicketMutationExecutorTest::test_worker_rejects_a_forged_redaction_marker_before_commit_creation` | Konflikt heißt `target_validation_changed` statt `target_requires_redaction` | Markerprüfung in `app/AI6/Git/TicketMutationExecutor.php` (Abschnitt 3, Punkt 2, dritter Spiegelstrich) |

Drei der sechs Fixe sind Test-Fixture-Korrekturen und keine Produktivänderung (Zeilen 1, 2–6 und 9): Sie stellen unter POSIX die Ausgangslage her, die die Tests auf NTFS bereits zufällig vorfanden. Die drei übrigen (7/8, 10/11, 12) sind die in Abschnitt 3 menschlich bestätigten Reparaturen toter Pfade im Produktivcode.

## 2. Reproduktion der Umgebung

Die Umgebung bildet die Laufzeitverträge nach, die die Tests real prüfen: PHP `8.5.5`, SQLite `3.53.4` (aus Quellen wie im Produktions-`Dockerfile`), Locale `C.UTF-8`, `dash`/`setsid`/`kill`/`ssh-keygen`/`git`, ein root-eigenes Effect-Lock-Fixture (Verzeichnis `0555`, Objekte `lock-0001` … `lock-0064` mit `0444`), eine 32-Hex-Heartbeat-Boot-ID unter `/run/ai6/heartbeat/worker/boot-id` und ein unprivilegierter Laufzeitbenutzer, für den der Anwendungscode unbeschreibbar ist (`ImmutableRuntimeFile`).

```dockerfile
FROM php:8.5.5-cli-bookworm

ARG SQLITE_ARCHIVE_VERSION="3530400"
ARG SQLITE_SHA3_256="454e45f61c6bd75b7420e7190732dea03ce6639c63ada47bbc592f67fc340338"
ARG SQLITE_VERSION="3.53.4"

ENV LD_LIBRARY_PATH=/usr/local/lib

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git openssh-client dash procps util-linux findutils curl libicu-dev; \
    curl --fail --location --retry 3 \
        --output /tmp/sqlite.tar.gz \
        "https://sqlite.org/2026/sqlite-autoconf-${SQLITE_ARCHIVE_VERSION}.tar.gz"; \
    php -r '$actual = hash_file("sha3-256", $argv[1]); if (! hash_equals($argv[2], $actual)) { fwrite(STDERR, "SQLite source digest mismatch.\n"); exit(1); }' \
        /tmp/sqlite.tar.gz "${SQLITE_SHA3_256}"; \
    mkdir /tmp/sqlite; \
    tar --extract --gzip --file /tmp/sqlite.tar.gz --directory /tmp/sqlite --strip-components=1; \
    cd /tmp/sqlite; \
    CFLAGS="-O2 -DSQLITE_ENABLE_COLUMN_METADATA" \
        ./configure --prefix=/usr/local --disable-static --enable-shared; \
    make -j"$(nproc)"; \
    make install; \
    ldconfig; \
    docker-php-ext-install -j"$(nproc)" intl pcntl; \
    php -r '$pdo = new PDO("sqlite::memory:"); $version = $pdo->query("select sqlite_version()")?->fetchColumn(); if ($version !== $argv[1]) { fwrite(STDERR, "Unexpected SQLite runtime: ".$version."\n"); exit(1); }' "${SQLITE_VERSION}"; \
    rm -rf /var/lib/apt/lists/* /tmp/sqlite /tmp/sqlite.tar.gz

RUN set -eux; \
    mkdir -p /opt/ai6-locks; \
    i=1; while [ "$i" -le 64 ]; do \
        n=$(printf 'lock-%04d' "$i"); \
        : > "/opt/ai6-locks/$n"; \
        chmod 0444 "/opt/ai6-locks/$n"; \
        i=$((i+1)); \
    done; \
    chmod 0555 /opt/ai6-locks; \
    useradd -m runner; \
    mkdir -p /run/ai6/heartbeat/worker; \
    echo 0123456789abcdef0123456789abcdef > /run/ai6/heartbeat/worker/boot-id; \
    chown -R runner /run/ai6
```

Ausführung: Das Repository wird in das native Container-Dateisystem kopiert (ein Windows-Bind-Mount liefert `0777` und scheitert an den Unveränderlichkeitsprüfungen), der Code bleibt root-eigen, nur `storage/` und `bootstrap/cache` gehören dem Laufzeitbenutzer:

```bash
docker build -t ai6-posix-test <verzeichnis-mit-diesem-dockerfile>
```

```bash
docker run --rm -v <repo>:/src:ro ai6-posix-test bash -c "set -e; cp -a /src /work; chmod -R go-w /work; chmod -R a+rX /work; chown -R runner /work/storage /work/bootstrap/cache; mkdir -p /work/.phpunit.cache; chown -R runner /work/.phpunit.cache; su -s /bin/bash runner -c 'cd /work && export LANG=C.UTF-8 LC_ALL=C.UTF-8 AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY=/opt/ai6-locks; php vendor/bin/phpunit tests/Feature/Git'"
```

## 3. Menschlich bestätigte Scope-Abweichungen

Alle Entscheidungen wurden am 25. August 2026 ausdrücklich vom Menschen getroffen und werden mit dem Commit dieses Standes dauerhaft aufgezeichnet:

1. **Rollenzuordnung `in_progress:cancel` additiv um `APPROVER` erweitert** (`app/AI6/Tickets/TicketStatusTransitionPolicy.php`). Änderung einer veröffentlichten Rollenzuordnung außerhalb der in `tickets/AI6-026.md` genehmigten sensiblen Entscheidung (dort nur die `BLOCK`-Quelle `in_progress`). Notwendig, weil der Hard-Cancel sonst strukturell tot wäre: `RunCancellationService` verlangt die Approverrolle, die Policy hätte sie abgewiesen. Entscheidung: **Erweiterung genehmigt**; die veröffentlichten Zuordnungen `ADMIN`/`OPERATOR` bleiben unverändert, die `in_progress`-Matrix ist positiv wie negativ unit-getestet.
2. **Drei Reparaturen an veröffentlichten Verträgen früherer Tickets in `app/AI6/Git/TicketMutationExecutor.php`** (`AI6-009`/`AI6-012`/`AI6-013`), jeweils tote oder inerte Pfade:
   - Die Attempt-Ref-Bereinigung prüft beide Token-Kandidaten (`prepared_attempt_token` und aktueller Attempt) unter denselben Commit-Bindungsprüfungen; zuvor blieb die unter dem aktuellen Token verwaiste Ref eines Crash-Versuchs dauerhaft liegen.
   - Die Recovery-Entscheidung `adopt_external_state` bindet den verifizierten Commit atomar als `application_fingerprint` samt Attempt-Token; zuvor verbot das Schema jedes `applied` für Ticket-Adopts.
   - Die Prüfung `target_requires_redaction` erkennt eingeschleuste Redactionmarker direkt über die geschlossene Markermenge aus `RedactionMatchType`; zuvor konnte sie nie feuern, weil der zentrale Redactor bekannte Marker absichtlich unangetastet lässt, und der Fall endete unter dem falschen Namen `target_validation_changed`.

   Entscheidung: **Alle drei bestätigt.**
3. **Eine additive Zeile im Verzeichnisbaum von `AGENTS.md` §1.** Der Baum nennt dieses Protokoll seither in der `docs/`-Auflistung. `AGENTS.md` ist eine geschützte Instruktionsdatei; §10 erlaubt eine Änderung nur auf ausdrückliche menschliche Anweisung. Diese Anweisung wurde am 25. August 2026 im Review-Durchgang zu `AI6-026` erteilt und umfasst genau diese eine Baumzeile: keine Regel, kein Prosatext und kein Status wurden berührt. Entscheidung: **Aufnahme genehmigt.**

## 4. Grenzen

Diese Behelfsumgebung ist keine sanktionierte Laufzeit: Sie besitzt weder die getrennten Rollenidentitäten noch die Volume-, Netz- und Seccomp-Verträge des Compose-Stacks. Die offenen `MG`-Gates aus `docs/` bleiben unberührt offen; ihre Evidenz bleibt eine menschliche Prüfung auf der realen Laufzeit (`AGENTS.md` §7, plan §20).
