# AI6-049 — Linux-Nachweise des Review-Fixes

## Ergebnis und Geltungsbereich

Am 27. September 2026 wurden die unter Windows fehlenden Linux-Nachweise erneut
gegen den aktuellen, uncommitteten Arbeitsstand ausgeführt. Beide Läufe endeten
mit Exitcode **0**, ohne Fehler und ohne übersprungene Tests. Die erste Ausführung
war bereits im Implementierungs-Chat berichtet; diese Wiederholung schließt die
fehlende dauerhafte Evidenz im Repository.

| Lauf | UID:GID | Bestanden | Fehler / Fehlschläge | Übersprungen | Assertions |
|---|---|---:|---:|---:|---:|
| `WorkerStorageProvisioningTest` | `0:0` | 10 | 0 / 0 | 0 | 85 |
| `tests/Feature/Shared/Operations/` | `10001:10001` | 39 | 0 / 0 | 0 | 780 |
| Gesamt | | **49** | **0 / 0** | **0** | **865** |

Die unveränderten, von PHPUnit erzeugten JUnit-Berichte sind mit abgelegt:

- [Provisionierung: alle zehn Fälle](AI6-049_LINUX_VERIFICATION/provisioning.xml)
- [Operations: alle 39 Fälle](AI6-049_LINUX_VERIFICATION/operations.xml)

Damit ist die gemeldete Linux-Nachweislücke zu **TC-08 / AC-07** sowie zu den
genannten Anteilen von **TC-01, TC-02 und TC-03** geschlossen. Ein Windows-Skip
wird dadurch nicht als bestandener Test umgedeutet. Dieses Protokoll ist eine
automatisierte Testevidenz, keine menschliche Abnahme und kein vollständiger
Compose- oder Disaster-Recovery-Nachweis. **AI6-049/MG-01 bleibt offen**; Ticketstatus,
AC-Checkboxen und Gateprotokoll wurden nicht verändert.

## Nachweis der konkret beanstandeten Fälle

`WorkerStorageProvisioningTest` wurde vollständig als root ausgeführt:

1. Frisches und altes Layout, wiederholte Wartungsübernahme und unveränderte Locks.
2. Konflikt zwischen alten und neuen Credentials.
3. Link als Credential-Elternverzeichnis.
4. Link innerhalb des Schlüsselbaums.
5. Mehrfach verlinkte Credential-Datei.
6. Sonderdatei (FIFO).
7. Schreibfreier regulärer Abbruch bei altem `deploy-keys/`.
8. Schreibfreier regulärer Abbruch bei altem `known_hosts`.
9. Benannter Abbruch bei einem nicht auflösbaren alten Link.
10. Schreibfreie Vorprüfung einer noch fehlenden Root und Abweisung unbekannter Argumente.

Die drei Altbestandsfälle prüfen jeweils sowohl den regulären Helferaufruf als
auch `--check-legacy`, Exit 78, den README-Hinweis und unveränderte Dateien.

Im Operations-Lauf liefen insbesondere folgende Methoden erfolgreich, jeweils
ohne `<skipped>`, `<error>` oder `<failure>` im JUnit-Ergebnis:

| Methode | Assertions | Geprüfter Linux-Anteil |
|---|---:|---|
| `BackupFilesystemTest::test_backup_and_restore_refuse_symlinks_even_when_unlisted_in_the_manifest` | 15 | Echter Symlink wird vor dem Backup und auch als nicht im Manifest verzeichneter Restore-Eintrag abgewiesen. |
| `BackupRestoreTest::test_backup_contains_a_standalone_snapshot_and_only_the_selected_payloads` | 58 | Backupverzeichnis `0700`, gesicherter privater Deploy-Key `0600`. |
| `BackupRestoreTest::test_successful_restore_recovers_data_revokes_the_real_client_and_removes_previous_copies` | 39 | Wiederhergestellter privater Deploy-Key `0600`. |

Der tatsächliche Host ist Linux; daher werden die durch `DIRECTORY_SEPARATOR === '/'`
bedingten Modusassertions in beiden Methoden ausgeführt. Die Provisionierung
prüft zusätzlich `PHP_OS_FAMILY === 'Linux'` und `posix_geteuid() === 0` vor ihren
Assertions. Ein dortiger Skip wäre im abgelegten JUnit-Bericht sichtbar.

## Laufzeit und Arbeitsstand

| Bindung | Wert |
|---|---|
| Datum | 27. September 2026; Hostzeit beim Beginn `2026-09-27T15:47:18Z` |
| Host | Projekt-VPS `smartlife-vps`, Linux `7.0.0-28-generic`, `x86_64` |
| Runtime-Image | `ai6-runtime:php-8.5.5_sqlite-3.53.4` |
| Aufgelöste lokale Image-ID | `sha256:674a6d9f0d2ce967d7f4abb81eeb5588b72b708d21192f7e3a6b0269ef19669e` |
| PHP / PHPUnit | `8.5.5` / `12.5.33`, von beiden Testläufen ausgegeben |
| Git-Basis | `7207b47d7cb73169180ed2d80d1c0e589f927800` plus uncommitteter AI6-049-Arbeitsstand |
| SHA-256 des übertragenen Quellarchivs | `38bc55ddeb757a867d52384e20983f1dfd6f5f83ce4dfab11c54728fd3354275` |
| SHA-256 des übertragenen Vendorarchivs | `984f67d32a6510dcc47b46939467d6f25ebc8a29da1fd39a0618e1d3bca33355` |

Das Quellarchiv entstand aus `git ls-files --cached --others --exclude-standard`
vor Ergänzung dieses Protokolls und seiner Ticketverweise. Es enthielt keine
ignorierte `.env`, keine lokalen Daten und keine Credentials. `vendor/` wurde
separat aus den vorhandenen Composer-Testabhängigkeiten übertragen. Der Quellstand
wurde nach `/opt/ai6` des Runtime-Images gemountet; damit wurde ausdrücklich der
aktuelle Arbeitsstand und nicht der ältere eingebaute Anwendungsstand getestet.

Die Testcontainer besaßen kein Netzwerk (`--network none`), keine
Produktivvolumes und keinen Docker-Socket. Ausschließlich die isolierte
Testkopie und das Evidenzverzeichnis waren eingebunden. In der Kopie gehörte der
Code root, Verzeichnisse hatten `0755`, Dateien `0644`, Shellskripte `0755`.
Nur `storage/`, `bootstrap/cache/` und das Evidenzverzeichnis gehörten UID/GID
`10001:10001`. Der Operations-Lauf setzte `APP_ENV=testing`; die übrige
Testkonfiguration kam aus der unveränderten `phpunit.xml` und den vorhandenen
Fixtures. Sicherheitskontrollen, Testcode und Produktivcode wurden für diese
Läufe nicht verändert.

## Ausgeführte Befehle und Ausgaben

Nach Entpacken und Setzen der oben beschriebenen Eigentümer und Modi wurden auf
dem VPS diese beiden Befehle ausgeführt. Für eine Wiederholung ist die Testkopie
erneut aus dem zu prüfenden Arbeitsstand bereitzustellen; das temporäre Verzeichnis
wurde nach dem Abholen der Ergebnisse entfernt.

```sh
sudo -n docker run --rm --network none --user 0:0 \
  --mount type=bind,src=/tmp/ai6-049-review.usQqjV/source,dst=/opt/ai6 \
  --mount type=bind,src=/tmp/ai6-049-review.usQqjV/evidence,dst=/evidence \
  --workdir /opt/ai6 --entrypoint php \
  ai6-runtime:php-8.5.5_sqlite-3.53.4 \
  vendor/bin/phpunit --do-not-cache-result --colors=never --testdox --display-skipped \
  --log-junit /evidence/provisioning.xml \
  tests/Unit/Shared/Runtime/WorkerStorageProvisioningTest.php

sudo -n docker run --rm --network none --user 10001:10001 -e APP_ENV=testing \
  --mount type=bind,src=/tmp/ai6-049-review.usQqjV/source,dst=/opt/ai6 \
  --mount type=bind,src=/tmp/ai6-049-review.usQqjV/evidence,dst=/evidence \
  --workdir /opt/ai6 --entrypoint php \
  ai6-runtime:php-8.5.5_sqlite-3.53.4 \
  vendor/bin/phpunit --do-not-cache-result --colors=never --testdox --display-skipped \
  --log-junit /evidence/operations.xml tests/Feature/Shared/Operations/
```

```text
Provisionierung — Exit 0:
Time: 00:00.147, Memory: 18.00 MB
OK (10 tests, 85 assertions)

Operations — Exit 0:
Time: 00:48.245, Memory: 73.00 MB
OK (39 tests, 780 assertions)
```

Die JUnit-Summen nennen für beide Läufe explizit `errors="0"`, `failures="0"`
und `skipped="0"`. Es gab in dieser Wiederholung keinen roten Zwischenlauf und
keine ausgeschlossenen Testfälle.

## Bindung an Dateien

Die folgende SHA-256-Liste wurde lokal erzeugt und in der Linux-Testkopie mit
`sha256sum --check` vollständig erfolgreich geprüft. Änderungen an diesen
Implementierungs- oder Testbytes benötigen einen neuen Nachweis; ein späterer
Commit allein ersetzt diese Bindung nicht.

```text
68d4ef9a3d5b34d1840727e8077d97ca8bab390ba155050374412042b7caf4d4  app/AI6/Shared/Operations/BackupCommand.php
3d19dbf7356dc2e6491daca9e7bb3f200c7ebf1902faf6da938bac4152b49c97  app/AI6/Shared/Operations/BackupException.php
070bb54e506638e04468c0975b436a46bd4ac5e56225d4536fb89ed34880c854  app/AI6/Shared/Operations/BackupManifest.php
0b1cd68bcb032db8fceea37e9ad1ad6d03f822ddc09e9f45838403577033d93c  app/AI6/Shared/Operations/BackupSet.php
f6cf9791e48ea13823e12d05f132b6bea71166e86339e393027b9329adfbabb5  app/AI6/Shared/Operations/RelocateCredentialsCommand.php
a06379f16f269ead482976ebddb728741f202bb4006b2f95bf6ca623e7a8f127  app/AI6/Shared/Operations/RestoreCommand.php
d78068668d94873022b85be8a2db206402ba705fc7d71b41478d0e20c005513a  composer.lock
2010b39f90c51db0b27196857e4af8cfab7f490a41d04f5274a0b15a5de65b25  docker/entrypoint.sh
ad513f253eb3e056fb6ceaec77a6758bcbd5df291196d5b7f33ecc119bfaa5e8  docker/provision-worker-storage.sh
d333844e6a35557891fb3d57890f60139cdb685cfd2487152fa6f911cf103d10  phpunit.xml
78e824306559e29d8c0747c78b25ccec65051ef1e4233f2c4f03dcc632655f06  tests/Feature/Shared/Operations/BackupFilesystemTest.php
59757020b9efa5c55de55c204039e1385fd1fe66ef54fdd6a94e31aab8622b6d  tests/Feature/Shared/Operations/BackupRestoreTest.php
4abb3047c7f43ed10035d3f450ff52f1b18396053012e0fad8d1e2f159f28785  tests/Feature/Shared/Operations/CredentialRelocationTest.php
de913c0482444f1269b62c602bc3737ddb3e5713fef7e6fb6425f887774377f0  tests/Feature/Shared/Operations/OperationsTestCase.php
1e30255b15fadc0b6e25418793545483d1e7b7f14c9d58b214e26b1537a335a0  tests/Feature/Shared/Operations/RestoreRetentionTest.php
3f56ddace28c1ce40df9940d3ad1d8d611355ea54769252bfa9e9fba3bb8ebf6  tests/Unit/Shared/Runtime/WorkerStorageProvisioningTest.php
```

SHA-256 der unverändert übernommenen JUnit-Berichte:

```text
93a418d5566c1314c7f72024e94721dbb4216731598e6b2cac555b01aa39c352  AI6-049_LINUX_VERIFICATION/provisioning.xml
32d6897a8635ca057401499ff0cc934974ffe05b13c1a2dc8fedfa2d89e7ec72  AI6-049_LINUX_VERIFICATION/operations.xml
```
