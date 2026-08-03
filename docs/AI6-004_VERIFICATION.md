# AI6-004 auf Entwicklungs-PC und VPS prüfen

Diese Anleitung prüft die Umsetzung von AI6-004 in zwei getrennten Ebenen:

1. Der Entwicklungs-PC prüft Quellcode, Tickettests, statische Analyse und Repositoryverträge.
2. Der VPS prüft ausschließlich den laufenden Compose-Stack und seine öffentlich erreichbare HTTP-Oberfläche.

Die Skripte führen kein `migrate:fresh` aus, erstellen oder löschen keinen Benutzer und widerrufen keine produktive Session. Sie können deshalb gegen einen bestehenden VPS ausgeführt werden. Änderungen an Benutzern und Sessions bleiben eine bewusst manuelle Prüfung.

## 1. Vor dem Lauf prüfen

Die Prüfscripte binden drei für AI6-004 wesentliche Vertragsstellen ausdrücklich:

- `docker-compose.yml` muss im `app`-Dienst `SESSION_DRIVER=database` setzen und die drei `AI6_AUTH_*`-Grenzwerte weiterreichen.
- `tests/Unit/ScaffoldStructureTest.php` muss den Repositoryzustand einschließlich der AI6-004-Module, Migrationen und Config-Dateien abbilden.
- Der strikte Integerparser muss unter `app/AI6/Shared/Config/` liegen und durch die Shared-Parser-Tests gebunden sein.

Drift an einer dieser Stellen beendet die vollständige Prüfung mit Exitcode 1. Die Prüfscripte ändern sie nicht automatisch.

## 2. Entwicklungs-PC unter Windows

### Voraussetzungen

- Repository-Root: `G:\software_projekte\AI6`
- PHP 8.5 im `PATH`
- Composer und Git im `PATH`
- installierte Abhängigkeiten unter `vendor/`
- `curl.exe`, wie es in aktuellen Windows-Versionen enthalten ist

PowerShell im Repository-Root öffnen:

```powershell
Set-Location G:\software_projekte\AI6
```

Falls die lokale Ausführungsrichtlinie PowerShell-Skripte sperrt, nur für den aktuellen Prozess freigeben:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```

Schneller Lauf ohne die bekannte rote Gesamtsuite:

```powershell
.\scripts\verify-ai6-004.ps1 -Quick
```

Vollständiger Repositorylauf:

```powershell
.\scripts\verify-ai6-004.ps1
```

Wenn lokal bereits eine Instanz unter `http://127.0.0.1:8000` läuft, können zusätzlich die sicheren HTTP-Probes ausgeführt werden:

```powershell
.\scripts\verify-ai6-004.ps1 -RuntimeUrl http://127.0.0.1:8000
```

Eine vorbereitete lokale `.env` und SQLite-Datenbank werden unter Windows mit dem reproduzierbaren Starter ausgeführt:

```powershell
Start-Process -FilePath .\scripts\run-ai6-local.cmd -WindowStyle Hidden
```

Der Starter bindet ausschließlich `127.0.0.1:8000` und verwendet Laravels Routerdatei. Zum Beenden kann der Listener-Prozess gezielt ermittelt und gestoppt werden:

```powershell
$listener = Get-NetTCPConnection -LocalPort 8000 -State Listen
Stop-Process -Id $listener.OwningProcess
```

Der Lauf ist erst vollständig grün, wenn die Gesamtsuite, der Compose-Sessioncheck und der Shared-Parsercheck grün sind. `-Quick` dient nur der schnelleren Entwicklung und ist kein Freigabenachweis.

## 3. VPS mit Docker Compose

### Voraussetzungen

- derselbe freizugebende Repositorystand liegt auf dem VPS
- Docker mit `docker compose`
- der Stack wurde bereits mit gültigem `APP_KEY` und dem für Produktion vorgeschriebenen versionierten Redaction-Keyring gestartet
- `curl` ist auf dem Host installiert
- die öffentliche Basisadresse ist bekannt

Vor einem Deployment mit einer bestehenden Datenbank muss das reguläre VPS-Backupverfahren erfolgreich abgeschlossen sein. Das Prüfsystem erstellt selbst kein Backup.

Der VPS-Check benötigt kein PHP auf dem Host:

```bash
cd /pfad/zu/AI6
bash scripts/verify-ai6-004-vps.sh --base-url=https://ai6.example.test
```

Er prüft:

- Erreichbarkeit der Compose-Dienste,
- den effektiven Wert `SESSION_DRIVER` im laufenden `app`-Container,
- den Migrationsstatus,
- den öffentlichen Health-Endpunkt,
- 404-Antworten für Registrierung, Passwort-Selbstbedienung und E-Mail-Verifizierung,
- die noch offene Position des Shared-Integerparsers im ausgecheckten Quellcode.

Ist Docker Compose auf einem anderen Host und soll nur die HTTP-Oberfläche geprüft werden:

```bash
bash scripts/verify-ai6-004-vps.sh \
  --base-url=https://ai6.example.test \
  --skip-compose
```

Wenn PHP, Composer und die Entwicklungsabhängigkeiten auch auf dem VPS installiert sind, können dort zusätzlich die Repositoryprüfungen laufen:

```bash
bash scripts/verify-ai6-004-vps.sh \
  --base-url=https://ai6.example.test \
  --with-code-checks
```

Für einen schnellen Diagnoselauf kann `--quick` ergänzt werden. Für die Freigabe ist anschließend ein Lauf ohne `--quick` erforderlich.

## 4. Ersten Administrator nur auf einer neuen Instanz anlegen

Das folgende Kommando verändert die persistente Datenbank und darf nur ausgeführt werden, wenn die Instanz tatsächlich noch keinen Benutzer besitzt:

```bash
docker compose exec app php artisan ai6:create-admin admin@example.com --name="AI6 Administrator"
```

Das Passwort wird verdeckt abgefragt. Es sollte nicht als Klartext in die Shellzeile geschrieben werden. Danach prüfen:

- Das Kommando endet beim ersten Aufruf erfolgreich.
- Die sichtbare Ausgabe enthält das Passwort nicht.
- Ein zweiter Aufruf endet ungleich 0 und erzeugt keinen weiteren Benutzer.
- Die aktuellen Containerlogs enthalten keine Passwortausgabe. Das Passwort nicht als Suchargument in die Shell kopieren, weil es dadurch in der Shellhistorie landen könnte.

## 5. Manuelle Browserprüfung

Für die Prüfung getrennte Browserprofile oder ein normales und ein privates Fenster verwenden.

1. `/register`, `/forgot-password`, `/reset-password/example-token` und `/email/verify` müssen 404 liefern.
2. Eine gültige Anmeldung muss zur Projektliste führen.
3. Ein Benutzer ohne Projektmitgliedschaft darf den Projektnamen weder in der Liste noch in einer 403-Antwort der Detailroute sehen.
4. Jede der vier Rollen `admin`, `viewer`, `operator` und `approver` darf die Liste und Detailansicht ausschließlich für Projekte mit eigener Mitgliedschaft sehen.
5. Ein globaler Administrator ohne Mitgliedschaft darf Benutzer und Mitgliedschaften verwalten, aber keine Projektinhalte sehen.
6. Deaktivieren, Löschen und Herabstufen des letzten aktiven globalen Administrators müssen mit HTTP 409 scheitern und den Datensatz unverändert lassen.
7. Derselbe Benutzer wird in zwei Browserprofilen angemeldet. Nach Widerruf genau einer Session muss dieses Profil beim nächsten Request ausgeloggt sein; das andere Profil bleibt angemeldet.
8. Nach Deaktivierung oder Löschung eines Benutzers müssen alle seine Profile beim nächsten Request ausgeloggt sein.

AI6-004 liefert absichtlich keine Verwaltungsoberfläche und keine Sessionliste. Die sieben Verwaltungsaktionen sind HTTP-Endpunkte und werden vollständig durch die Feature-Tests geprüft. Einen manuellen Sessionwiderruf auf einer produktiven Datenbank nicht durch direkte SQL-Manipulation simulieren. Falls noch kein freigegebener API-Client existiert, die Punkte 5 bis 8 auf einer Wegwerf-/Staginginstanz oder über die automatisierte Suite prüfen.

## 6. Erwartete automatisierte Ergebnisse

Der AI6-004-spezifische Befehl lautet:

```bash
php artisan test tests/Unit/Auth tests/Feature/Auth tests/Feature/Projects
```

Im aktuellen Implementierungsstand ergibt er 32 bestandene Tests. Für eine Freigabe müssen zusätzlich ohne Ausnahme erfolgreich sein:

```bash
php artisan test
php vendor/bin/pint --test
php -d memory_limit=512M vendor/bin/phpstan analyse
composer validate --strict
php scripts/generate-ticket-manifest.php --check
git diff --check
```

Der sichtbare Skip des Compose-Smoke ist kein bestandener Nachweis. Soll der bereits vorhandene vollständige Compose-Smoke zusätzlich laufen, wird er ausdrücklich aktiviert:

```bash
AI6_RUN_COMPOSE_SMOKE=1 php artisan test --filter=RuntimeComposeSmokeTest
```

Unter PowerShell:

```powershell
$env:AI6_RUN_COMPOSE_SMOKE = '1'
php artisan test --filter=RuntimeComposeSmokeTest
Remove-Item Env:AI6_RUN_COMPOSE_SMOKE
```

Der Smoke-Test erzeugt `APP_KEY` und einen versionierten Redaction-Keyring ausschließlich kurzlebig im Testprozess, baut das gesperrte Image und entfernt seinen isolierten Compose-Stack einschließlich Volumes anschließend wieder. Er liest oder überschreibt dafür keine produktiven Schlüsselwerte. Der erfolgreiche Referenzlauf umfasst einen Test mit 29 Assertions; ein lokaler Lauf kann wegen Image-Build, Healthcheck-Intervallen und Heartbeat-Neustartprüfung mehrere Minuten dauern.

## 7. Exitcodes

- `0`: Alle vom gewählten Modus ausgeführten Prüfungen waren erfolgreich.
- `1`: Mindestens eine fachliche oder technische Prüfung ist fehlgeschlagen.
- `2`: Das Skript konnte wegen falscher Argumente oder fehlender Voraussetzungen nicht starten.
