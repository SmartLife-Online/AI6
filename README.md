# AI6

AI6 verwaltet Git-native Softwaretickets, lässt sie von Menschen freigeben und orchestriert anschließend getrennte LLM-Sitzungen für Implementierung und Review. Die Anwendung ist als modularer Laravel-Monolith mit einer Codebasis und klar getrennten Prozessrollen angelegt.

## Technische Basis

- PHP-Vertrag: `^8.5`; Composer löst Abhängigkeiten durch `config.platform.php: 8.5.0` gegen die zugesagte Mindestplattform auf.
- Laravel-Vertrag: `laravel/framework: ^13.8`; der aktuelle Lockstand enthält Laravel `13.23.0`.
- PHPUnit-Vertrag: `phpunit/phpunit: ^12.5.12`; der aktuelle Lockstand enthält PHPUnit `12.5.33`.
- Qualitätswerkzeuge im Lockstand: Pint `1.30.0` und PHPStan `2.2.7`.
- Scaffoldquelle: `laravel/laravel` Tag `v13.8.0` am Commit `e196bfdfc96903f2e10219749fcbca7c0aefe99f`.

Die vollständige Import-Allowlist, die Ausschlüsse und die vor der Übernahme geprüften Schutzdateien stehen maschinenlesbar in [`docs/AI6_SCAFFOLD_PROVENANCE.json`](docs/AI6_SCAFFOLD_PROVENANCE.json). Der Scaffold wurde in einem temporären Verzeichnis außerhalb dieses Repositorys bezogen; es lief dabei kein Composer-Skript und es wurde kein Scaffold rekursiv über den vorhandenen Bestand kopiert.

Der Scaffoldpfad `config/` wurde bewusst nicht übernommen. AI6-001 startete deshalb migrationsfrei mit `SESSION_DRIVER=file`, `CACHE_STORE=file` und `QUEUE_CONNECTION=sync`. AI6-002 führte die benötigte SQLite-Konfiguration und den Laufzeitdefault `QUEUE_CONNECTION=database` ein; Sessions und Cache blieben dabei zunächst dateibasiert. AI6-004 stellt den Anwendungsdefault für Sessions auf die Datenbank um, während der Cache dateibasiert bleibt.

## Installation und Start

Voraussetzungen sind PHP 8.5 mit den von Laravel verlangten Erweiterungen, `ext-intl`, `ext-mbstring`, `ext-openssl` sowie Composer. Ein frischer Clone wird ausschließlich aus dem committed `composer.lock` installiert; `composer update` und Node-Werkzeuge sind nicht erforderlich. Die restriktive Ticket-YAML-Naht verwendet die explizite Abhängigkeit `symfony/yaml`; direkter Zugriff außerhalb dieser Kapselung ist nicht zulässig.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=localhost --port=8000
```

Unter Windows PowerShell ersetzt `Copy-Item .env.example .env` den `cp`-Befehl. `scripts/run-ai6-local.cmd` startet denselben lokalen Server mit der für WebAuthn unterstützten Adresse `http://localhost:8000`; Browseraufrufe über `127.0.0.1` sind dafür nicht gleichwertig. Die Vorlage setzt bewusst keinen containergebundenen `DB_DATABASE`-Pfad; der direkte Artisan-Start würde deshalb Laravels Default `database/database.sqlite` verwenden. Der Health-Endpunkt startet ohne Datenbankvorbereitung und erzeugt diese Datei nicht. Für Queue oder Scheduler direkt außerhalb von Docker wird stattdessen ausdrücklich eine lokale Datei unter dem bereits ignorierten Laufzeitpfad `storage/app/ai6-local.sqlite` verwendet, damit die ausgeschlossene Scaffolddatei `database/database.sqlite` weiterhin fehlt:

```bash
touch storage/app/ai6-local.sqlite
DB_DATABASE="$PWD/storage/app/ai6-local.sqlite" php artisan migrate
```

Unter PowerShell entsprechen dem `New-Item -ItemType File -Force storage/app/ai6-local.sqlite`, danach `$env:DB_DATABASE = (Resolve-Path storage/app/ai6-local.sqlite).Path` und `php artisan migrate`. Die zusätzlichen Ignore-Regeln für `/database/*.sqlite`, `/database/*.sqlite-shm` und `/database/*.sqlite-wal` verhindern außerdem, dass eine versehentlich am Laravel-Defaultpfad angelegte lokale Datenbank oder ihre WAL-Begleitdateien committed werden kann. Der Docker-Start unten setzt seinen eigenen absoluten Datenbankpfad und legt Datei sowie Schema kontrolliert im einmaligen `init`-Dienst an.

Der Health-Endpunkt lässt sich nach dem Start prüfen:

```bash
curl -i http://127.0.0.1:8000/health
```

Die Antwort hat Status 200 und exakt den JSON-Body `{"status":"ok"}`.

## Docker-Compose-Laufzeit

Die primäre Laufzeit wird aus demselben Repository und einem einzigen AI6-Image gestartet. Das Image ist an PHP `8.5.5`, SQLite `3.53.4`, das PHP-Basisimage sowie einen datierten Debian-Paketsnapshot gebunden und enthält natives `ext-intl` für dieselbe NFC-Implementierung wie der Prüfhost. Weil der kanonische Bytevertrag `Normalizer` unmittelbar verwendet, führt auch `composer.json` `ext-intl` als zwingende Plattformanforderung. Der Reverse Proxy verwendet separat `caddy:2.10.2-alpine` am Manifest-Digest `sha256:4c6e91c6ed0e2fa03efd5b44747b625fec79bc9cd06ac5235a779726618e530d`.

Nach dem Kopieren der Umgebungsvorlage startet der Stack mit einem Befehl. Weil Compose die PHP-Rollen mit `APP_ENV=production` startet, müssen zuvor `AI6_REDACTION_ACTIVE_KEY_ID` und ein nicht leerer, versionierter `AI6_REDACTION_KEYS`-Ring gesetzt werden; ein Formatbeispiel steht im Abschnitt [Sicherheitsprofile und zentrale Redaction](#sicherheitsprofile-und-zentrale-redaction).

```bash
docker compose up -d --build
```

Nur Caddy veröffentlicht `127.0.0.1:${AI6_HTTP_PORT:-8080}`. Caddy lauscht innerhalb seines eigenen Netzwerk-Namespace auf `:8080`, damit die Docker-Portweiterleitung und der interne Healthcheck ihn erreichen; eine Bindung an `127.0.0.1` im Container würde beides vom Containerinterface abschneiden. Die ausschließlich lokale Exposition wird deshalb durch die literale Loopbackbindung der Compose-Veröffentlichung erzwungen. Caddy und `app` teilen zusätzlich ein von allen anderen Rollen getrenntes Proxynetz; erreichbar ist Caddy damit über die Hostveröffentlichung und aus `app` heraus, aus keiner anderen Rolle. Kein AI6-Dienst besitzt einen veröffentlichten Port. `init` läuft einmal als einziger Dienst mit erhöhter Identität, legt die SQLite-Datei an, führt `php artisan migrate --force --no-interaction` aus, korrigiert die Volume-Rechte und endet mit Exitcode 0. Alle dauerhaften Dienste warten auf diesen erfolgreichen Abschluss.

Compose verwendet standardmäßig `172.30.60.0/24` für das Servicenetz und `172.30.61.0/29` für das getrennte Proxynetz. Für parallel isolierte Stacks dürfen `AI6_SERVICE_SUBNET` und `AI6_SERVICE_IP_RANGE` sowie `AI6_PROXY_SUBNET`, `AI6_PROXY_IP_RANGE` und `AI6_PROXY_ADDRESS` gemeinsam auf kollisionsfreie IPv4-Netze gesetzt werden. `AI6_PROXY_ADDRESS` muss außerhalb des dynamischen Proxy-IP-Bereichs liegen; `AI6_HTTP_TRUSTED_PROXIES` muss dann genau diese feste Adresse benennen. Der reale Compose-Smoke wählt diese Werte selbst aus den aktuell unbelegten Docker-Netzen und verändert keinen laufenden Stack.

Die Loopbackbindung der Portveröffentlichung ist eine Netzwerkgrenze, aber kein Private-Access-Nachweis für den nachgelagerten Request: Bei einem Browserzugriff über den veröffentlichten Compose-Port sieht Caddy als Client die Docker-Bridge-Gateway-Adresse. Diese Adresse ist normativ kein Loopback, und die Anwendung akzeptiert sie auch weiterhin nicht als Privatzugriff.

Aufgelöst wird das nicht dadurch, dass Caddy die Clientadresse überschreibt — eine gefälschte Adresse würde den zentralen Gate-Eingang verfälschen und die echte Clientadresse dauerhaft für Audit und Missbrauchserkennung zerstören. Stattdessen **behauptet** Caddy den Eingangsweg getrennt von der Adresse: Der Reverse Proxy setzt auf dem an die Loopback-Veröffentlichung gebundenen Listener den festen Header `X-AI6-Ingress: loopback-publication` und ersetzt dabei jeden vom Client mitgeschickten Wert; `X-Forwarded-For` bleibt unangetastet. `EnforceHttpsOrPrivateAccess` wertet diese Behauptung ausschließlich dann aus, wenn der unmittelbare Peer eine über `AI6_HTTP_TRUSTED_PROXIES` konfigurierte vertrauenswürdige Proxyadresse ist; ein direkt verbundener Client kann sie deshalb nicht setzen. Der Klartextrequest gilt damit genau dann als Privatzugriff, wenn er entweder selbst von einem Loopback stammt oder der vertrauenswürdige Proxy den Loopback-Eingang behauptet. `getClientIp()` liefert unverändert die reale Clientadresse.

Diese Konstruktion ersetzt keine Transportverschlüsselung: Sie öffnet den Klartextzugriff für jeden Prozess auf dem Docker-Host und für `app` selbst, und in den Profilen mit aktiver Maßnahme `REQUIRE_HTTPS_OR_PRIVATE_ACCESS` bleibt das Sessioncookie `Secure`, sodass eine Klartextanmeldung weiterhin nicht funktioniert. Ein bedienbarer, authentifizierter Browserzugriff auf den Compose-Stack setzt deshalb weiterhin die HTTPS-Terminierung beziehungsweise den VPN-/SSH-Zugang aus `AI6-036` oder eine ausdrücklich menschlich entschiedene, gleichwertig sichere Netzwerklösung voraus. Die zugehörige Integrationsentscheidung bleibt als `AI6-005B/MG-01` **offen** und ist durch die Ingress-Behauptung nicht erfüllt; sie verlangt weiterhin gebundene menschliche Evidenz.

`docker-compose.yml` ist bewusst als JSON geschrieben; JSON ist gültiges Compose-YAML. Die Contract-Tests können die Datei dadurch mit dem vorhandenen `json_decode` lesen, ohne entgegen `AC-11` einen zusätzlichen YAML-Parser in den gebundenen Abhängigkeitssatz aufzunehmen. Änderungen erhalten daher die JSON-Schreibweise.

Die Tabellenbezeichnung **SecurityPolicy-Variablen** umfasst `AI6_SECURITY_PROFILE`, `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE` und die sieben in der Sicherheitstabelle unten aufgeführten Maßnahmenschlüssel. **Redaction-Keyring-Variablen** umfasst `AI6_REDACTION_ACTIVE_KEY_ID` und `AI6_REDACTION_KEYS`. **HTTP-Härtungsvariablen** umfasst `AI6_HTTP_TRUSTED_HOSTS`, `AI6_HTTP_TRUSTED_PROXIES` und `AI6_HTTP_SESSION_SAME_SITE`. Compose reicht die SecurityPolicy-Variablen identisch an alle PHP-Rollen weiter; die Keyring-Variablen erhalten nur `app`, `worker` und `scheduler`, die HTTP-Härtungsvariablen ausschließlich `app`. `init`, `agent`, `checker` und `caddy` erhalten keine dieser HTTP-Werte. Die vier Laravel-Rollen erhalten zusätzlich eine feste, nicht aus `.env` substituierte `AI6_RUNTIME_ROLE`; sie grenzt ausschließlich den schlüssellosen `init`-Migrationsstart vom regulären Keyring-Bootstrap ab.

| Dienst | Prozess und Heartbeat-Produzent | Erlaubte Umgebungsvariablen | Erlaubte Mounts |
|---|---|---|---|
| `caddy` | Separater Reverse Proxy; HTTP-Healthcheck | keine | ausschließlich `deploy/Caddyfile` read-only |
| `init` | Einmaliger Migrations- und Lock-Bereitstellungsschritt; kein Heartbeat | SecurityPolicy-Variablen, `AI6_RUNTIME_ROLE`, `AI6_MANAGED_PROJECT_ROOT`, `AI6_EFFECT_LOCK_DIRECTORY`, `AI6_EFFECT_LOCK_OBJECT_COUNT`, `AI6_EFFECT_LOCK_OWNER_UID`, `APP_ENV`, `APP_DEBUG`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS` | Datenbank, `storage/` und `ai6_managed` read-write; `/tmp` als `tmpfs` |
| `app` | Apache/PHP; kein Heartbeat, HTTP-Healthcheck | SecurityPolicy-, Redaction-Keyring-, HTTP-Härtungs-, Git-Remote- und `AI6_AUTH_*`-Variablen, `AI6_RUNTIME_ROLE`, `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_NAME`, `APP_URL`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `AI6_CONTROL_OPERATION_LEASE_SECONDS`, `AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS`, `AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST`, `AI6_CONTROL_OPERATION_STALE_SECONDS` | Datenbank und `storage/` read-write, Nachweise read-only; `/tmp` als `tmpfs` |
| `worker` | `queue:work`; `Looping`-Listener schreibt auch im Leerlauf, ausschließlich am Worker-Heartbeatziel | SecurityPolicy-, Redaction-Keyring- und Git-Remote-Variablen, `AI6_RUNTIME_ROLE`, `APP_ENV`, `APP_DEBUG`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_QUEUE_RETRY_AFTER`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `AI6_EXECUTION_DIRECTORY`, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_MAX_AGE`, `AI6_WORKER_TIMEOUT`, `AI6_MANAGED_PROJECT_ROOT`, `AI6_DEPLOY_KEY_ROOT`, `AI6_EFFECT_LOCK_DIRECTORY`, `AI6_EFFECT_LOCK_OBJECT_COUNT`, `AI6_EFFECT_LOCK_OWNER_UID`, `AI6_CONTROL_OPERATION_LEASE_SECONDS`, `AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS`, `AI6_CONTROL_OPERATION_RECONCILER_SECONDS`, `AI6_CONTROL_OPERATION_MAX_ATTEMPTS`, `AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE`, `AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST`, `AI6_CONTROL_OPERATION_STALE_SECONDS`, `AI6_CONTROL_OPERATION_RECONCILIATION_BUDGET`, `AI6_SSH_KEYGEN_BINARY` | Datenbank, `storage/`, Nachweise und `ai6_managed` read-write; eigener Heartbeat und `/tmp` als `tmpfs` |
| `scheduler` | `schedule:work`; der Zehn-Sekunden-Task schreibt den Heartbeat und verwendet einen stabilen Selbsttestschlüssel je Scheduler-Boot-ID | SecurityPolicy- und Redaction-Keyring-Variablen, `AI6_RUNTIME_ROLE`, `APP_ENV`, `APP_DEBUG`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_QUEUE_RETRY_AFTER`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_MAX_AGE`, `AI6_CONTROL_OPERATION_RECONCILER_SECONDS` | Datenbank und `storage/` read-write; eigener Heartbeat und `/tmp` als `tmpfs` |
| `agent` | Feste Leerlaufschleife schreibt ausschließlich den Agent-Heartbeat | `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_INTERVAL`, `AI6_HEARTBEAT_MAX_AGE` | ausschließlich eigener Heartbeat und `/tmp` als `tmpfs` |
| `checker` | Feste Leerlaufschleife schreibt ausschließlich den Checker-Heartbeat | `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_INTERVAL`, `AI6_HEARTBEAT_MAX_AGE` | ausschließlich eigener Heartbeat und `/tmp` als `tmpfs` |

Datenbank, `storage/`, Ausführungsnachweise und der verwaltete Projekt-/Deploy-Key-Baum sind persistente benannte Volumes. Das Volume `ai6_managed` wird ausschließlich in `init` und `worker` eingebunden; `app`, `scheduler`, `agent`, `checker` und Caddy können die privaten Deploy-Keys nicht erreichen. Der Scheduler erzeugt trotz seines Zehn-Sekunden-Intervalls höchstens einen neuen Nachweis je Container-Boot-ID; wiederholte Jobs desselben Boots treffen denselben persistenten Genau-einmal-Nachweis. Jeder Rollen-Heartbeat liegt dagegen auf einem nur für diesen Container vorhandenen `tmpfs` und ist an eine beim Containerstart erzeugte Boot-ID gebunden. Die Worker-Heartbeatfrist muss beim Start strikt größer als der Worker-Timeout sein, damit ein zulässig langer Job den Worker nicht allein wegen seiner Laufzeit ungesund macht. `agent`, `checker` und Caddy sehen weder Datenbank noch `storage/` oder Nachweisvolume; `agent` und `checker` erhalten außerdem keinen produktiven `APP_KEY`. Der eingebaute Anwendungscode ist in allen sechs AI6-Diensten read-only. Tests, Tickets, normative Dokumente, Entwicklungswerkzeuge und Qualitätskonfigurationen werden durch `.dockerignore` aus dem Produktionsimage ausgeschlossen.

Ein manueller Laufzeittestjob wird mit einem stabilen Idempotenzschlüssel eingestellt:

```bash
docker compose exec app php artisan ai6:runtime-selftest manual
docker compose exec worker php artisan ai6:runtime-health --role=worker
docker compose exec scheduler php artisan ai6:runtime-health --role=scheduler
```

Der vollständige automatisierte Compose-Smoke ist standardmäßig sichtbar übersprungen. Nur das ausdrückliche Flag baut das Image, startet einen isolierten Stack, wartet auf `init` und alle Healthchecks und prüft Proxy, Runtimeversionen, Scheduler und Genau-einmal-Nachweis:

```bash
AI6_RUN_COMPOSE_SMOKE=1 php artisan test --filter=RuntimeComposeSmokeTest
```

Unter PowerShell wird das Flag vor dem Befehl mit `$env:AI6_RUN_COMPOSE_SMOKE = '1'` gesetzt. Dieser optionale Lauf ergänzt die manuellen Gates MG-01 und MG-02, ersetzt ihre gebundene menschliche Evidenz aber nicht.

Der verpflichtende Mindestplattform-Nachweis wird in einer realen PHP-8.5-Umgebung nach dem Locked Install ausgeführt und dokumentiert:

```bash
php --version
composer validate --strict
composer install
composer check-platform-reqs
```

## Qualitätsprüfungen

Alle Befehle laufen vom Repository-Root. Die reguläre Suite und die statischen Prüfungen benötigen keine zusätzlichen Umgebungsvariablen:

```bash
php artisan test
vendor/bin/pint
vendor/bin/pint --test
vendor/bin/phpstan analyse
composer validate --strict
php scripts/generate-ticket-manifest.php --check
git diff --check
```

Der externe Locked-Install- und Plattformnachweis ist bewusst ein eigener Testbefehl. Er verlangt zwei explizite lokale Pfade: `AI6_PHP85_BINARY` muss eine echte PHP-8.5-Binary auswählen, `AI6_COMPOSER_PHAR` das damit auszuführende Composer-PHAR beziehungsweise Composer-Skript. Fehlt eine Auswahl oder meldet die Binary keine Version `8.5.x`, schlägt dieser Befehl fehl; es gibt weder einen Laufzeit-Fallback noch einen Skip.

Linux/macOS:

```bash
export AI6_PHP85_BINARY=/usr/bin/php8.5
export AI6_COMPOSER_PHAR=/usr/local/bin/composer
```

Windows PowerShell, beispielhaft:

```powershell
$env:AI6_PHP85_BINARY = 'C:\php\php.exe'
$env:AI6_COMPOSER_PHAR = 'C:\ProgramData\ComposerSetup\bin\composer.phar'
```

Anschließend wird ausschließlich die externe Locked-Install-Suite ausgeführt:

```bash
php vendor/bin/phpunit tests/Unit/LockedInstallTest.php
```

PHPStan analysiert `app/`, `bootstrap/`, `routes/`, `scripts/` und `tests/` auf Level 6. Dieses Level erzwingt bereits konkrete Typen, Rückgabeverträge und belastbare Nullprüfungen, ohne den Bootstrap durch eine Baseline oder durch pauschale Fehlerausnahmen zu entwerten. Das Level kann später mit wachsender Fachlogik kontrolliert verschärft werden; eine PHPStan-Baseline ist nicht vorgesehen.

## Sicherheitsprofile und zentrale Redaction

AI6 löst die instanzweite Securitykonfiguration beim Bootstrap genau einmal als unveränderliche `SecurityPolicy` auf. Ein ungültiger Literalwert oder eine nicht bestätigte Reduktion beendet den Start vor Request- beziehungsweise Command-Code. Booleanwerte akzeptieren ausschließlich die nativen Boolean-/Integerwerte `true`, `false`, `1`, `0` oder die nicht von Groß-/Kleinschreibung abhängigen Stringliterale `true`, `false`, `1`, `0`, `yes`, `no`, `on`, `off`; Leerwerte, Whitespace und andere Schreibweisen sind Fehler. Fehlermeldungen nennen Schlüssel und zulässige Literale, niemals den gelesenen Wert. Parser liefern Fehler zunächst wertfrei zurück; erst der Bootstrap-Rand ohne direktes Rohwertargument wirft. Laravel-Objekte in einem künstlich mit aktivierten Traceargumenten erzeugten Trace können weiterhin auf den geschützten Konfigurationszustand des Containers verweisen; deshalb erzwingt das Bootstrap `zend.exception_ignore_args=1` als bindende Ausgabegrenze. HTTP-Clients erhalten ausschließlich `Interner Konfigurationsfehler.`, während CLI und Serverlog die sichere Diagnose ohne Rohwert ausgeben.

| Schlüssel | strict-Default | Bedeutung |
|---|---:|---|
| `AI6_SECURITY_PROFILE` | `strict` | `strict`, `development` oder `custom` |
| `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE` | `false` | ausdrückliche Bestätigung jeder wirksamen Reduktion |
| `AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION` | `true` | Login-E-Mail-Bestätigung |
| `AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY` | `true` | Passkeypflicht für privilegierte Rollen |
| `AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP` | `true` | Step-up für kritische Aktionen |
| `AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS` | `true` | HTTPS-/Private-Access-Durchsetzung |
| `AI6_SECURITY_REQUIRE_AGENT_SANDBOX` | `true` | Agentensandbox |
| `AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION` | `true` | Checker-Netzwerksperre |
| `AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW` | `true` | LLM-Precommit-Sicherheitsreview |

`strict` erlaubt keine deaktivierte Maßnahme. `development` deaktiviert normativ ausschließlich `AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS`; jede zusätzliche Deaktivierung ist ein Konfigurationsfehler. `custom` übernimmt die sieben einzelnen Schalter. `development` und jedes tatsächlich reduzierte `custom` benötigen `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=true`. Nicht abschaltbare Invarianten wie Autorisierung, CSRF, Pfad-/Ref-/JSON-/Hostvalidierung, Shell-Injection-Schutz, Credentialtrennung, Anti-Replay und sichere Ausgabe besitzen keinen Konfigurationsschlüssel.

Der zentrale `Redactor` besitzt als einziger Anwendungsdienst die Secret-, Token-, Credential- und Sensitive-Path-Regeln. Treffer werden durch die stabilen Marker `[REDACTED:SECRET]`, `[REDACTED:TOKEN]`, `[REDACTED:CREDENTIAL]` und `[REDACTED:PATH]` ersetzt. Sortierung nach Startposition, längstem Treffer und eindeutiger Regelpriorität macht überlappende Treffer unabhängig von der Registrierungsreihenfolge. Eine echte zweite Anwendung erkennt exakt abgeschlossene Marker und bleibt byteidentisch; ein Markerpräfix aus untrusted Input mit angehängtem Wert wird dagegen vollständig redigiert. JSON-Felder, gequotete Werte, benannte Tokenzuweisungen und Benutzerpfade mit Leerzeichen oder Unicode werden atomar ersetzt. Ein strukturiertes Ergebnis enthält nur Typ, Feld/Span, Marker, Fingerprintversion, Key-ID und HMAC-Fingerprint, niemals entfernten Klartext oder dessen unkeyed Digest. Eingaben müssen gültiges UTF-8 sein; andernfalls wirft der Dienst vor Regelauswertung, Fingerprinting oder Ergebnisausgabe eine wertfreie `InvalidRedactionInputException`. Aufrufer behandeln diesen Fall fail-closed und geben die ungültigen Eingabebytes nicht aus.

Der Redaction-Schlüsselring wird mit `AI6_REDACTION_ACTIVE_KEY_ID` und optional `AI6_REDACTION_KEYS` konfiguriert. `AI6_REDACTION_KEYS` ist ein JSON-Objekt aus Key-ID und je einer eindeutigen positiven `version` sowie einem mindestens 32 Byte langen `base64:`-Schlüssel. Eine Key-ID beginnt mit einem Kleinbuchstaben oder einer Ziffer, enthält danach höchstens 63 Kleinbuchstaben, Ziffern, Punkte, Unterstriche oder Bindestriche und darf wegen der PHP-Array-Key-Koerzierung nicht rein numerisch sein. Bleibt der Ring in `APP_ENV=local` oder `APP_ENV=testing` leer, wird ausschließlich als lokale Bootstrap-Hilfe ein domain-separierter Schlüssel `app-key-v1` aus `APP_KEY` abgeleitet. Dieser Fallback ist nicht rotationsstabil: Eine Änderung von `APP_KEY` würde das Schlüsselmaterial ohne neue Key-ID und Version ändern. `ai6:doctor` weist deshalb ausdrücklich auf den lokalen Fallback hin. In jeder anderen Umgebung, insbesondere `production`, ist ein leerer Ring ein Konfigurationsfehler; jede persistierende oder produktive Instanz verwendet einen expliziten, versionierten Schlüsselring. `app`, `worker`, `scheduler` und normale Laravel-Starts lösen den Ring bereits im ersten Anwendungsprovider auf. Nur `package:discover`, `key:generate`, der Test-Runner `test` und das feste `migrate` der schlüssellosen Compose-`init`-Rolle dürfen vor dieser Validierung laufen. Beispiel ohne echten Schlüsselwert:

```dotenv
AI6_REDACTION_ACTIVE_KEY_ID=2026-08
AI6_REDACTION_KEYS='{"2026-01":{"version":1,"key":"base64:<alter-schluessel>"},"2026-08":{"version":2,"key":"base64:<neuer-schluessel>"}}'
```

Bei Rotation bleibt jeder alte Eintrag unverändert im Ring, ein neuer Eintrag erhält eine neue Version und Key-ID, und ausschließlich `AI6_REDACTION_ACTIVE_KEY_ID` wechselt. Bereits gespeicherte Fingerprints werden weder neu berechnet noch verändert; nur neue Redactions verwenden den neuen aktiven Schlüssel.

### Byteverträge und Golden-Vektoren

Der Policyhash verwendet die ASCII-Domäne `AI6-SECURITY-POLICY-V1` plus NUL-Byte. Danach folgen in fester Reihenfolge Profil, die sieben Maßnahmenschalter in der Tabellenreihenfolge und der Bestätigungszustand. Booleanwerte werden als `1` beziehungsweise `0` dargestellt. Unvollständige Maßnahmenmengen werden vor dem Hashing abgelehnt; ein fehlender Zustand wird niemals still als `0` interpretiert. Jedes Feld wird zunächst nach UTF-8/NFC normalisiert und dann einzeln mit seiner Bytelänge als unsigned 64-bit Big-Endian-Wert gerahmt. SHA-256 über diesen Bytestrom ergibt kleingeschriebenes Hex. Golden-Vektor: `strict`, alle sieben Maßnahmen aktiv, Bestätigung `false` → `655ab648b8459ed33bdbfe325bf59c26bbb0b39c762a7051e06076a49057dcda`.

Der Fingerprint verwendet dasselbe Längenrahmenverfahren mit der eigenen ASCII-Domäne `AI6-REDACTION-FINGERPRINT-V1` plus NUL-Byte und der Feldreihenfolge Fingerprintversion, Treffertyp, Kontextkennung, kanonische Projekt-ID, optionale Run-ID und entfernter Wert. Das Ergebnis ist HMAC-SHA-256 mit dem aktiven Redaction-Schlüssel. Golden-Vektor: Version `7`, Typ `token`, Kontext `log`, Projekt `projekt-ä`, Run `run-42`, NFD-Wert `sekret-e\u0301` und 32 Schlüsselbytes `0b` → `e1e01a3a8c055ee11a42b78161cc8db082b30810660a75af98b5ffc308f91c04`. Die Feldgrenzen `("ab", "c")` und `("a", "bc")` erzeugen beim Policy-Frame beispielsweise die verschiedenen SHA-256-Werte `0a6db13d399b70f84a88a0ad5bd5361853ad28ca53635ce244b255910709a360` und `8e069b204c6fa26244c654b775402b3b4756da75e158dcb17c27c8c27cf1d9e5`.

Der aktuelle Zustand wird ohne Ausgabe von Secret-, Schlüssel- oder Rohkonfigurationswerten geprüft:

```bash
php artisan ai6:doctor
```

## Benutzer, Projektrollen und Basislogin

AI6 besitzt keine öffentliche Registrierung, Passwortzurücksetzung oder E-Mail-Verifizierung. Der erste globale Administrator wird ausschließlich über das Bootstrap-Kommando angelegt. Das Passwort wird entweder verdeckt abgefragt oder aus der ausdrücklich benannten Umgebungsvariablen gelesen und weder ausgegeben noch protokolliert:

```bash
AI6_CREATE_ADMIN_PASSWORD='<mindestens-12-zeichen>' php artisan ai6:create-admin admin@example.com --name='AI6 Administrator'
```

Nach dem ersten Benutzer verweigert das Kommando weitere Bootstrap-Aufrufe. Weitere Benutzer, globale Administratoren und Projektmitgliedschaften werden ausschließlich durch einen bereits aktiven globalen Administrator verwaltet. Der letzte aktive globale Administrator kann weder deaktiviert, gelöscht noch zum normalen Benutzer herabgestuft werden.

Globale Administratorrolle und Projektrollen sind getrennte Autorisierungsgrenzen. Diese Tabelle gibt den Vertrag aus AI6-004 wieder; sie definiert ihn nicht. `Ja` bei einer Projektrolle setzt eine Mitgliedschaft im betroffenen Projekt voraus.

| Aktion | Globaler Administrator | Projekt `admin` | Projekt `viewer` | Projekt `operator` | Projekt `approver` | Ohne Mitgliedschaft |
|---|---:|---:|---:|---:|---:|---:|
| Projekt erscheint in der Projektliste | Nein | Ja | Ja | Ja | Ja | Nein |
| Projektdetail ansehen | Nein | Ja | Ja | Ja | Ja | Nein |
| Ticket bearbeiten | Nein | Ja | Nein | Ja | Nein | Nein |
| Ticketstatus human-owned ändern | Nein | Ja | Nein | Ja | Ja | Nein |
| Benutzer anlegen | Ja | Nein | Nein | Nein | Nein | Nein |
| Benutzer deaktivieren | Ja | Nein | Nein | Nein | Nein | Nein |
| Benutzer löschen | Ja | Nein | Nein | Nein | Nein | Nein |
| Globale Administratorrolle vergeben | Ja | Nein | Nein | Nein | Nein | Nein |
| Globale Administratorrolle entziehen | Ja | Nein | Nein | Nein | Nein | Nein |
| Projektmitgliedschaft setzen | Ja | Nein | Nein | Nein | Nein | Nein |
| Projektmitgliedschaft entziehen | Ja | Nein | Nein | Nein | Nein | Nein |

Ein globaler Administrator benötigt für die sieben Verwaltungsaktionen keine Projektmitgliedschaft, sieht ohne Mitgliedschaft aber weder ein Projekt in der Liste noch dessen Detailansicht. Die Projektrolle `admin` gewährt keine instanzweiten Verwaltungsrechte. Ticketbearbeitung ist den Projektrollen `admin` und `operator` vorbehalten; human-owned Statusoperationen sind zusätzlich für `approver` erreichbar und werden anschließend je Quellstatus und Operation enger entschieden.

## Ticketbearbeitung und Git-Persistenz

Der Editor lädt ausschließlich frische, unmaskierte Ticketprojektionen im Zustand `invalid` oder `valid`. Das Formular zeigt den gebundenen Blob-SHA und, sofern vorhanden, den kanonischen Contract-Hash. Jeder Auftrag bindet den bytegenauen geladenen Inhalt, den Control-Head und einen zentral redigierten Auditgrund. Ein veralteter oder manipulierter Stand wird als Konflikt abgewiesen und kann über den Neuladepfad erneut geholt werden. Nur ein vollständig gültiger Zielstand darf committed werden. Ein Inhaltsedit eines `ready`-Tickets setzt den Status im selben Git-CAS auf `todo` zurück.

Editor und Statusansicht führen kein Git aus. Sie erzeugen nach CSRF-, Policy- und aktionsgebundenem Step-up-Nachweis eine typisierte asynchrone Control Operation. Der Worker setzt deren persistierte Saga `prepared` → `commit_prepared` → `control_confirmed` → `db_finalized` unter Projektlease und Effekt-Lock fort, erzeugt genau einen Commit mit dem erwarteten Parent, pusht per Compare-and-Swap und bestätigt den Remote-Head. Erst danach werden lokaler Control-Ref, `projects.control_oid`, `control_binding_version` und das Read Model atomar auf den neuen Commit fortgeschrieben; `control_generation` bleibt unverändert. Ein Abbruch darf ausschließlich einen noch nicht veröffentlichten Mutationsversuch verwerfen. Steht der gebundene Mutationscommit bereits am Remote-Control-Ref, bleibt die Operation sichtbar in Recovery und kann vorwärts über `adopt_external_state` konsistent abgeschlossen werden; AI6 schreibt dafür keine veröffentlichte Control-Historie zurück. Human-owned Statusoperationen sind `block`, `cancel`, `return_to_todo` und `complete_review`; sie akzeptieren keinen frei angegebenen Zielstatus. `complete_review` verlangt außerdem die ausdrückliche Bestätigung der externen Zusammenführung beziehungsweise Abnahme. Reservierte Approval-, Run- und Statussync-Kanten besitzen hier keine Route.

## Projektregistrierung und vertrauenswürdige Metadaten

Ein aktiver globaler Administrator registriert ein Projekt über die Weboberfläche mit Anzeigename, SSH-Remote, vollständigem Control-Branch-Ref und dem erwarteten Hostkey-Fingerprint. Projektrollen einschließlich der Projektrolle `admin` reichen für diese instanzweite Aktion nicht aus. Die Registrierung prüft Protokoll, Host, exakten Remote-Pfad, Ref und Fingerprint ausschließlich gegen die vertrauenswürdige Instanzkonfiguration aus `AI6_GIT_ALLOWED_HOSTS`, `AI6_GIT_ALLOWED_REMOTE_PATHS`, `AI6_GIT_ALLOWED_REF_PATTERNS` und `AI6_GIT_PINNED_HOST_KEYS`. Sie startet weder Git noch einen anderen Kindprozess und öffnet keine Netzwerkverbindung.

Remote, Control-Branch, Hostkey-Bindung und relative Projektkennung liegen autoritativ in der Tabelle `projects`; Repositorydateien wie `.git/config`, `.gitattributes` oder als Anweisung formulierter Inhalt können keinen dieser Werte setzen oder überschreiben. Ein absoluter Managed-Path wird nicht gespeichert. Stattdessen erzeugt der Server die relative Projektkennung als `bin2hex(random_bytes(16))`: genau 32 Zeichen aus dem festen Alphabet `[0-9a-f]`. Ein clientseitiger Vorschlag wird ignoriert, und die Datenbank erzwingt Format und Eindeutigkeit zusätzlich.

Projekt und Mitgliedschaft entstehen atomar. Der registrierende globale Administrator erhält in derselben Transaktion eine Mitgliedschaft mit der Projektrolle `admin`; schlägt die Mitgliedschaft fehl, wird auch das Projekt zurückgerollt. Die aktive Control-OID-Bindung und die ausstehende Bindung sind nach der Registrierung leer, `control_binding_version`, `control_generation` und der Attempt-Token der Operationssperre beginnen bei `0`.

Der Provisionierungszustand besitzt genau vier Werte:

| Zustand | Bedeutung |
|---|---|
| `not_provisioned` | Registrierung abgeschlossen, Deploy-Key-Provisionierung noch nicht gestartet |
| `provisioning` | Eine Control Operation beansprucht die Provisionierung |
| `provisioned` | Provisionierung terminal erfolgreich; ausschließlich jetzt wird der öffentliche Deploy-Key angezeigt |
| `provisioning_failed` | Provisionierung fehlgeschlagen; ein neuer Versuch ist zulässig |

Die Registrierung selbst schaltet den Zustand ausschließlich auf `not_provisioned`. Die weiteren Übergänge und die Erzeugung des privaten Deploy-Keys gehören zum nachfolgenden Control-Operation-Ticket; der App-Prozess erhält dabei kein privates Schlüsselmaterial.

Sessions liegen serverseitig in der Datenbank. Deaktivieren oder Löschen eines Benutzers widerruft alle seine Sessions; der gezielte Sessionwiderruf entfernt genau eine Session. Anwendungs- und Compose-Default sind `SESSION_DRIVER=database`; eine Laufzeitdefinition darf sie nicht mit einer dateibasierten Sessionablage überschreiben, weil ein sofortiger gezielter Widerruf dann nicht nachweisbar wäre. Login und Logout regenerieren beziehungsweise invalidieren die Session samt CSRF-Token.

Die operativen Auth-Grenzwerte werden getrennt von der `SecurityPolicy` aufgelöst:

| Schlüssel | Default | Bedeutung |
|---|---:|---|
| `AI6_AUTH_LOGIN_MAX_ATTEMPTS` | `5` | Fehlversuche bis zur Sperre einer normalisierten E-Mail-Kennung |
| `AI6_AUTH_LOGIN_DECAY_SECONDS` | `60` | Dauer des Rate-Limit-Fensters in Sekunden |
| `AI6_AUTH_SESSION_LIFETIME_MINUTES` | `120` | serverseitige Sessionlebensdauer in Minuten |

E-Mail-Kennungen werden vor Login und Rate-Limit um Rand-Whitespace bereinigt und in Kleinschreibung überführt. Ein erfolgreicher Login löscht den Fehlversuchszähler. Die nachgelagerte starke Authentifizierung und E-Mail-Barriere beschreibt der folgende Abschnitt.

## Starke Anmeldung, Enrollment und E-Mail-Barriere

Nach dem Passwort bleibt eine neue Websession zunächst unautorisiert. Besitzt der Benutzer noch weder Passkey noch bestätigtes TOTP-Geheimnis, entsteht genau eine kurze, an Benutzer, Passwortzustand und Browsersession gebundene Enrollment-Sitzung. Sie erreicht ausschließlich die Passkey- und TOTP-Registrierung und endet mit der ersten erfolgreichen Registrierung, bei Ablauf, Logout, Deaktivierung, Löschung oder Passwortänderung. Recovery-Codes zählen nicht als starkes Verfahren und beenden das Enrollment nicht. Nach erfolgreichem Enrollment wird abgemeldet; der Benutzer durchläuft anschließend den regulären Login erneut.

Besitzt der Benutzer bereits ein starkes Verfahren, folgt auf das Passwort die Prüfung per Passkey oder TOTP; ein noch gültiger Recovery-Code ist für nicht privilegierte Benutzer der einmalig verwendbare Wiederherstellungspfad. Als privilegiert gelten globale Administratoren sowie Benutzer mit mindestens einer Projektrolle `admin`, `operator` oder `approver`; die Projektrolle `viewer` ist nicht privilegiert. Solange `AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY=true` gilt, dürfen privilegierte Benutzer die Primärprüfung ausschließlich per Passkey oder TOTP abschließen. Ein Recovery-Code wird ihnen weder angeboten noch zur Autorisierung angenommen. Alle zugelassenen Wege laufen durch den zentralen `LoginCompletionGate`. Im `strict`-Profil erzeugt die erfolgreiche Primärprüfung nur den Vorautorisierungszustand: Ein zufälliger Code aus genau acht Ziffern wird ausschließlich an `AI6_LOGIN_CONFIRMATION_EMAIL` gesendet und muss in derselben Browsersession eingegeben werden. Die Mail enthält bewusst keinen Bestätigungslink. Digest, Empfänger- und Sessionbindung, Revision, Ablauf, Versuche und Zustellzustand liegen in der Datenbank; der Klartextcode liegt weder dort noch im Log. Der Mailjob implementiert Laravels verschlüsselte Queue-Payload, damit der Klartext auch nicht in der Database Queue steht. Fehlende Adresse, Queue- oder Transportfehler bleiben geschlossen und im Bestätigungspanel sichtbar.

Die operative Konfiguration besitzt sichere Grenzwerte; die Codelänge ist absichtlich keine Konfiguration:

| Schlüssel | Default | Bedeutung |
|---|---:|---|
| `AI6_AUTH_LOGIN_CONFIRMATION_TTL_SECONDS` | `600` | Gültigkeit einer Loginbestätigung |
| `AI6_AUTH_LOGIN_CONFIRMATION_MAX_ATTEMPTS` | `5` | terminales Versuchslimit |
| `AI6_AUTH_STRONG_AUTHENTICATION_MAX_ATTEMPTS` | `5` | terminales Sessionlimit je Primärprüfung beziehungsweise Step-up-Aktion und sessionunabhängiges Benutzerlimit je Verfahrensfamilie |
| `AI6_AUTH_STRONG_AUTHENTICATION_DECAY_SECONDS` | `300` | Zeitfenster des benutzergebundenen Primär- beziehungsweise Step-up-Limits |
| `AI6_AUTH_LOGIN_CONFIRMATION_RESEND_COOLDOWN_SECONDS` | `30` | Sperre bis zur nächsten Revision |
| `AI6_AUTH_STEP_UP_WINDOW_SECONDS` | `300` | Gültigkeit einer frischen, aktionsgebundenen Step-up-Prüfung |
| `AI6_AUTH_ENROLLMENT_TTL_SECONDS` | `900` | Höchstdauer der Enrollment-Sitzung |
| `AI6_LOGIN_CONFIRMATION_EMAIL` | leer | einzelne vertrauenswürdig konfigurierte Sicherheitsadresse |

Der Mailtransport verwendet die normalen Laravel-Variablen `MAIL_MAILER`, `MAIL_URL` beziehungsweise `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_EHLO_DOMAIN`, `MAIL_FROM_ADDRESS` und `MAIL_FROM_NAME`. Die Repositoryvorlage enthält weder Empfänger noch Zugangsdaten und verwendet fail-closed `smtp`. Die nicht zustellenden Laravel-Transporttypen `log` und `array` sind für Sicherheitscodes unzulässig; der Mailjob verwirft jeden Transport außer `smtp`, bevor er den Code an Laravel Mail übergibt. Compose reicht die Bestätigungsadresse und Auth-Grenzwerte ausschließlich an `app` weiter. Nur `worker` erhält `APP_KEY` zum Entschlüsseln der Queue-Payload und die genannten Mailtransportvariablen zum Versand; keine andere Rolle erhält diese Werte. Der reale Mailtest bleibt bis zur menschlichen Evidenz aus MG-02 offen.

Ein kritischer Anwendungspfad konsumiert über `StepUpGuard` einen frischen Nachweis. Dieser Nachweis entsteht erst nach erneuter Passkey- oder TOTP-Prüfung, ist an Benutzer, Session und Aktionstyp gebunden, läuft nach dem konfigurierten Fenster ab, wird genau einmal verbraucht und nach Ablauf aus der Session entfernt. Fehlversuche der Primärprüfung teilen sich pro Benutzer und Browsersession ein terminales Limit; zusätzlich überdauert ein benutzergebundenes Primärlimit Passwortanmeldung, Logout und Sessionwechsel bis zum Ablauf seines Zeitfensters oder einer erfolgreichen Primärprüfung. Step-up-Fehlversuche werden je Aktion in der Session terminal begrenzt und zugleich über alle Step-up-Aktionen desselben Benutzers in einem zweiten sessionunabhängigen Limit zusammengeführt. Nur eine erfolgreiche Step-up-Prüfung löscht dieses Benutzerlimit. Gleichzeitig gespeicherte Sessionzähler sind hart auf 16 Scopes begrenzt. Der Enrollment-Zustand erfüllt kein Step-up. Die E-Mail-Barriere, die Einschränkung privilegierter Primärverfahren und die Step-up-Maßnahme lassen sich ausschließlich über die vorhandene `SecurityPolicy` reduzieren; ein reduziertes `custom`-Profil verlangt `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=true` und bleibt in `ai6:doctor` sowie den Bannerdaten sichtbar.

Recovery-Codes werden ausschließlich lokal für genau einen aktiven Benutzer mit bereits registriertem Passkey oder TOTP neu ausgegeben:

```bash
php artisan ai6:reissue-recovery-codes user@example.com
```

Das Kommando ersetzt den alten Satz atomar, schreibt nur Hashes und einen redigierten Auditdatensatz und gibt den neuen Klartextsatz genau einmal auf der Standardausgabe aus. Es besitzt keinen Sammelmodus, keine Dateioption, keine HTTP-Route und keinen Queue-Auslöser. Ein unbekannter, deaktivierter, gelöschter oder faktorloser Benutzer wird ohne Zustandsänderung abgewiesen. Die Autorisierungsgrenze ist der lokale Shellzugang zum Container; mangels Websession wird kein Step-up simuliert.

Das WebAuthn-Browserskript liegt ohne Buildschritt unter `public/assets/ai6-passkey.js`. Auth-Ansichten laden ausschließlich dieses selbst gehostete Asset und enthalten weder Inline-Skript noch Inline-Eventhandler oder externe Skriptquelle. Der Server erzeugt und verbraucht Challenge und Sessionbindung, prüft Origin, Relying Party, Signatur und Signaturzähler und verwendet keine Laufzeit-Metadatenabfrage im Netz. Für lokale Entwicklung akzeptiert der ausgewählte WebAuthn-Verifikator Klartext-HTTP ausschließlich mit einer exakten `APP_URL` wie `http://localhost` oder `http://localhost:8000`; `http://127.0.0.1` und `http://[::1]` werden bereits beim Auflösen der Relying Party abgewiesen und benötigen wie alle anderen Hosts HTTPS. Browseraufruf und `APP_URL` müssen dieselbe Origin verwenden.

### Auswahl der Kryptobibliotheken

Der Base-Lockstand enthielt weder WebAuthn- noch TOTP-Verifikation; angewendet wurde deshalb Regelstufe 2 aus AI6-005A. Beide Prüfungen laufen vollständig serverseitig und ohne externen Dienst oder Laufzeit-Netzzugriff.

| Fähigkeit | Gewählt und aufgelöst | Geprüfte Alternative | Entscheidung |
|---|---|---|---|
| WebAuthn | `lbuchs/webauthn` `v2.2.0` | `web-auth/webauthn-lib` `v5.3.5` | Beide decken serverseitige WebAuthn-Prüfung ab. `lbuchs/webauthn` besitzt im Lockstand keine Composer-Transitive und den engeren Funktionsumfang; die Alternative bringt den deutlich breiteren WebAuthn-/CBOR-/PSR-Baum mit. Der Tie-Breaker „weniger Transitive, danach engerer Umfang“ entscheidet für `lbuchs/webauthn`. Benötigt werden vorhandenes OpenSSL und zusätzlich `ext-mbstring`; das Dockerfile installiert und prüft `mbstring`. |
| TOTP | `pragmarx/google2fa` `v9.0.0` plus `paragonie/constant_time_encoding` `v3.1.3` | `spomky-labs/otphp` `v11.5.0` | Beide arbeiten offline. `pragmarx/google2fa` stellt den für denselben Zeitraum benötigten monotonen `verifyKeyNewer`-Vertrag direkt bereit und fügt nur eine Transitive hinzu; `spomky-labs/otphp` hat den breiteren Clock-/OptionsResolver-Baum. Der Tie-Breaker entscheidet für den kleineren und engeren Baum. |

`composer.json` bindet die gewählten Minor-Linien, `composer.lock` die genannten Versionen. Eine eigene WebAuthn-, Signatur- oder TOTP-Kryptoimplementierung existiert nicht.

Die sichere Prüfung auf einem Windows-Entwicklungs-PC und auf einem Docker-Compose-VPS beschreibt [`docs/AI6-004_VERIFICATION.md`](docs/AI6-004_VERIFICATION.md). Die zugehörigen Skripte verändern weder bestehende Benutzer noch Sessions oder Datenbanken.

## HTTP-, Session- und Markdown-Härtung

AI6 löst die HTTP-Härtung beim Bootstrap aus dem Abschnitt `ai6.http_hardening` auf. Ungültige Werte beenden die Initialisierung mit einer wertfreien Diagnose, die den betroffenen Schlüssel nennt. Die Host- und Proxyliste stammen ausschließlich aus vertrauenswürdiger Instanzkonfiguration:

| Schlüssel | Default | Vertrag |
|---|---|---|
| `AI6_HTTP_TRUSTED_HOSTS` | `localhost,127.0.0.1,::1` | Kommagetrennte exakte Hostnamen oder IP-Adressen; leer, Wildcards und ungültige Listeneinträge werden abgelehnt. Produktionsinstanzen tragen hier ausschließlich ihre tatsächlich bedienten Hosts ein. |
| `AI6_HTTP_TRUSTED_PROXIES` | leer; Compose: `172.30.61.2` | Kommagetrennte explizite IP-Adressen oder CIDR-Netze; `*`, universelle `/0`-Netze, aus dem Request abgeleitete Werte und Laravel-Cloud-Heuristiken sind unzulässig. Compose bindet Caddy an die feste Adresse `172.30.61.2` im separaten internen Proxynetz und vertraut standardmäßig ausschließlich diesem Proxy, nicht einem der beiden Docker-Netze. |
| `AI6_HTTP_SESSION_SAME_SITE` | `lax` | Ausschließlich `lax` oder `strict`; `HttpOnly` bleibt unabhängig davon immer aktiv. |

Ein Request wird vor Anwendungslogik abgewiesen, wenn sein wirksamer Host nicht exakt in der Hostliste steht. Weiterleitungsheader einer nicht konfigurierten Gegenstelle werden ebenfalls abgewiesen und verändern weder Host, Clientadresse noch Schema. Ein konfigurierter Proxy darf ausschließlich `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto` und `X-Forwarded-Port` mit syntaktisch gültigen Werten liefern; die standardisierte Sammelform `Forwarded` und `X-Forwarded-Prefix` werden nicht akzeptiert. Ein weitergeleiteter Host muss selbst in der Hostliste stehen. Das wirksame HTTP-Schema stammt damit nur von der unmittelbaren Verbindung oder von `X-Forwarded-Proto` einer konfigurierten Proxygegenstelle.

Private Access ist enger als ein internes Netz. Maßgeblich ist die wirksame Clientadresse nach der konfigurierten Proxyauflösung: Ohne Trusted Proxy entspricht sie der unmittelbaren Gegenstelle, mit Trusted Proxy dessen aufgelöster Clientadresse. Nur ein Wert aus `127.0.0.0/8` oder exakt `::1` erfüllt das Prädikat. Ein als Trusted Proxy konfigurierter Loopback-Peer ohne Weiterleitungsangabe bleibt damit Private Access; liefert derselbe Peer eine nicht-loopbackgebundene Clientadresse, wird der Request abgelehnt. RFC-1918-Adressen, `fc00::/7` und jeder Trusted Proxy mit einer anderen Clientadresse erfüllen das Prädikat nicht.

| Wirksame Lage | Klartext über Private Access | Klartext außerhalb Private Access | Compose-Browser über Caddy, Klartext | HTTPS | Session-`Secure` | `HttpOnly` / `SameSite` | Acknowledgement |
|---|---:|---:|---:|---:|---:|---|---|
| `strict`, HTTPS-Maßnahme aktiv | bedient | abgelehnt, keine Umleitung | abgelehnt, kein Private Access | bedient | immer gesetzt | immer gesetzt / konfiguriert | nicht erforderlich |
| `development`, HTTPS-Maßnahme reduziert | bedient | abgelehnt, keine Umleitung | abgelehnt, kein Private Access | bedient | auf Klartext-Private-Access nicht gesetzt, auf HTTPS gesetzt | unverändert | `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=true` erforderlich |
| `custom`, HTTPS-Maßnahme aktiv; gegebenenfalls andere Reduktion | bedient | abgelehnt, keine Umleitung | abgelehnt, kein Private Access | bedient | immer gesetzt | unverändert | bei jeder tatsächlichen anderen Reduktion erforderlich |
| `custom`, HTTPS-Maßnahme reduziert | bedient | abgelehnt, keine Umleitung | abgelehnt, kein Private Access | bedient | auf Klartext-Private-Access nicht gesetzt, auf HTTPS gesetzt | unverändert | `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=true` erforderlich |

Für diese Entscheidung wird ausschließlich der aufgelöste Zustand von `AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS` aus der zentralen `SecurityPolicy` gelesen. Profilname und Zustand anderer Maßnahmen sind keine Ersatzsignale. Das Sessioncookie ist ein Browser-Sessioncookie ohne persistente Ablaufzeit; eingehende oder ausgehende Laravel-Remember-Cookies werden vor der Authentifizierung beziehungsweise vor der Antwort entfernt. Jede zustandsändernde Webroute bleibt in Laravels CSRF-Middleware ohne Ausschlussliste. Ein fehlendes Token und ein Token einer anderen Session werden abgewiesen.

Jede HTML-Antwort trägt die zentral erzeugte Content-Security-Policy. `script-src` enthält ausschließlich die aktuelle, bereits host- und proxyvalidierte Origin mit dem Pfadpräfix `/assets/`; alle Auth-Skripte liegen als statische Dateien dort. Die Policy enthält weder `unsafe-inline` noch `unsafe-eval`, sperrt Objekte, Frames und Base-URL-Änderungen und wird auch auf gerenderte Fehlerantworten angewendet. AI6 verwendet derzeit keinen Nonce, weil keine Inline-Skripte zulässig sind. Sollte ein späterer Vertrag einen Nonce benötigen, darf ausschließlich ein zentraler, antwortgebundener Helfer ihn erzeugen; Views und andere Konsumenten erzeugen keinen eigenen Wert.

Die Markdown-Basispolitik verwendet die bereits über `laravel/framework` gebundene Fähigkeit `league/commonmark` in der Lockversion `2.8.3`. `composer.json` und `composer.lock` bleiben deshalb gegenüber der Runbasis unverändert. Geprüfte Alternative war eine zusätzliche direkte Markdown-Abhängigkeit beziehungsweise ein eigener Parser; beides würde den vorhandenen Supply-Chain-Baum vergrößern oder sicherheitskritische Parserlogik duplizieren, ohne eine fehlende Fähigkeit zu schließen. Der vorhandene CommonMark-Parser mit abgeschaltetem Raw HTML und abgeschalteten unsicheren Links ist daher die minimale Auswahl.

Vor dem Parsen läuft der Eingabetext unverändert durch den zentralen `Redactor`; ausschließlich dessen `text`-Ergebnis wird weiterverarbeitet. Danach lässt die Presentation-Sanitization nur `p`, `br`, `hr`, `em`, `strong`, `blockquote`, `ul`, `ol`, `li`, `pre`, `code`, `a` und die sechs Überschriftsebenen sowie die eng zugeordneten Attribute `href`, `title`, `class` und `start` zu. Raw HTML, Eventhandler, Bilder und nicht allowlistete Attribute werden entfernt. Links dürfen relativ sein oder die Schemas `http`, `https` und `mailto` verwenden; insbesondere `javascript:`, `data:` und verschleierte Varianten erhalten kein aktives Ziel. Diese Allowlist ist ausschließlich eine Darstellungsgrenze und enthält keine Secret-, Token-, Credential- oder Pfadmuster. Solche Muster bleiben allein in `app/AI6/Shared/Redaction/`.

## Gehärtete Control-Prozesse und Git-Ausführung

Produktive Git- und Control-Prozesse laufen ausschließlich über den zentralen `ControlProcessRunner`. Sein öffentlicher Vertrag akzeptiert eine nicht leere Argumentliste, ein bereits vorhandenes Arbeitsverzeichnis und eine positive Environment-Allowlist. Nicht allowlistete Werte aus dem Elternprozess werden im Kindprozess explizit entfernt. Die serverseitigen Maxima `AI6_PROCESS_TIMEOUT_SECONDS`, `AI6_PROCESS_OUTPUT_LIMIT_BYTES` und `AI6_PROCESS_CANCEL_GRACE_MILLISECONDS` begrenzen Laufzeit, Gesamtausgabe und kontrollierten Abbruch; eine Überschreitung liefert ein benanntes Ergebnis ohne Teilausgabe. `AI6_PROCESS_WRAPPER_READY_TIMEOUT_SECONDS` begrenzt zusätzlich den Bereitschafts- und Freigabe-Handshake des blockierenden Starts. `AI6_PROCESS_SHELL_BINARY` benennt auf POSIX den kanonischen, symlinkfreien und nicht gruppen- oder fremdbeschreibbaren Interpreter für den festen Wrapper; der Image-Default ist `/usr/bin/dash`. Symfony Process ist über Laravel bereits als `symfony/process` `v7.4.13` im Lockfile vorhanden. Deshalb fügt AI6-006A keine Composer-Abhängigkeit hinzu und verändert weder `composer.json` noch `composer.lock`.

Der blockierende Modus ist eine Fähigkeit desselben Startpfads. Der vertrauenswürdige Wrapper meldet seine Prozesskennung und den tatsächlichen Startzeitpunkt, hält vor jeder Zielwirkung an und wird nach der Freigabe per `exec` selbst zum Zielprogramm. Auf POSIX-Systemen erzeugt `AI6_PROCESS_SETSID_BINARY` für jeden Lauf eine eigene Prozessgruppe; bei Abbruch oder Limit signalisiert `AI6_PROCESS_GROUP_KILL_BINARY` zuerst `TERM` und nach der konfigurierten Frist immer `KILL` an die vollständige Gruppe. So bleiben auch von einem Ziel gestartete Kindprozesse nicht verwaist. Der Wrapper nimmt keine Shellkommandozeile entgegen; Ziel und Argumente bleiben getrennte Einträge der Prozessargumentliste.

Der Effekt-Lock verwendet in beiden Verwendungsformen denselben `EffectLock`: direkt für einen begrenzten wirkenden Abschnitt des Aufrufers oder im blockierenden Wrapper bis zum Ende des per `exec` gestarteten Programms. Konfiguriert werden `AI6_EFFECT_LOCK_DIRECTORY`, `AI6_EFFECT_LOCK_OBJECT_COUNT`, `AI6_EFFECT_LOCK_WAIT_MILLISECONDS` und die privilegierte Eigentümer-ID `AI6_EFFECT_LOCK_OWNER_UID`. Zulässige Namen sind `lock-0001` bis zur konfigurierten Anzahl. Ein unbekannter Name wird niemals angelegt. Das Verzeichnis muss dem privilegierten Eigentümer gehören und Modus `0555` besitzen; jedes vorab bereitgestellte reguläre Lockobjekt gehört demselben Eigentümer und besitzt Modus `0444`. Die Anwendung öffnet es ausschließlich read-only, prüft Pfad, Symlinkfreiheit, Eigentümer, Modus und Inode und löscht oder ersetzt es nie. Dadurch scheitern Löschen, Umbenennen und Ersetzen bereits an der Verzeichnisgrenze. `init` legt diese Objekte im Volume `ai6_managed` vor der Migration mit der durch `AI6_EFFECT_LOCK_OWNER_UID` bestimmten Eigentümer-ID und Modus `0444` an. Vollständige existierende Objekte werden weder gelöscht, ersetzt, erneut gechownt noch erneut chmodifiziert. Ausschließlich ein leerer regulärer Absturzrest aus dem Erzeugungsschritt mit Gruppe `0`, Modus `0400` und Eigentümer `0` oder der konfigurierten Eigentümer-ID wird inode-erhaltend per `chown` und `chmod 0444` fertiggestellt. Inhalt, ein anderer Modus, eine fremde Gruppe oder ein anderer Eigentümer brechen den wiederholten Start geschlossen ab. Unsichere Typen brechen ebenfalls geschlossen ab. Der unprivilegierte Worker kann weder Verzeichnis noch Lockobjekte austauschen.

### Control-Operationen und Deploy-Key-Provisionierung

Jede mutierende Projektaktion besitzt eine persistierte Control-Operation. Der Webrequest prüft die Projektpolicy und beansprucht Projektsperre samt Provisionierungszustand in derselben SQLite-Transaktion, in der Operation und Database-Queue-Job entstehen; er startet weder Git noch einen anderen Prozess. Derselbe Operations-Identifier ist idempotent an genau einen kanonischen Request gebunden. Nach einem terminal fehlgeschlagenen Auftrag darf derselbe fachliche Request unter einer neuen Operations-ID erneut eingestellt werden. Der SHA-256-Request-Hash verwendet die wörtliche ASCII-Domäne `AI6-CONTROL-OPERATION-V1` mit folgendem NUL-Byte. Danach folgt je Feld ein Präsenzbyte. Für `null` ist dieses Präsenzbyte `0x00`, und danach folgt für dieses Feld ausschließlich dieses eine Byte — kein Längenfeld und keine Bytes. Für einen vorhandenen Wert ist das Präsenzbyte `0x01`, gefolgt von der Länge der UTF-8-Bytes als unsigned 64-bit big-endian und den Bytes selbst. Jede Rahmung, die den Null-Fall abweichend kodiert — etwa durch ein zusätzliches Längenfeld `0` anstelle des alleinigen Marker-Bytes —, erzeugt einen anderen Hash und ist keine gültige Reimplementierung dieses Vertrags; `ControlOperationHasher::field()` ist die alleinige Quelle dieser Rahmung. Die feste Reihenfolge lautet Schemaversion, Projektkennung, Operationstyp, Actor, JCS-serialisierter Autorisierungssnapshot, erwarteter Control-Commit und anschließend jedes operationstypspezifische Parameterfeld einzeln in der durch `ControlOperationType::PARAMETER_FIELDS` festgelegten Reihenfolge. Für `deploy_key_provision` ist diese Parameterliste auf genau `algorithm` festgelegt; dessen Wert ist `ed25519`. Die zusätzlich persistierte Parameterdarstellung `{"algorithm":"ed25519"}` dient der kanonischen Auditdarstellung, wird aber nicht als ein zusammengefasstes Hashfeld gerahmt. Jedes operationstypspezifische Parameterfeld besitzt einen durch `ControlOperationType` fest deklarierten, unveränderlichen Werttyp; `ControlOperationHasher::parameterValue()` bildet `bool` auf die literalen Strings `true`/`false` und `int` auf ihre Dezimaldarstellung ab, bevor dasselbe Feld-Framing greift. Diese Abbildung ist ausschließlich deshalb kollisionsfrei, weil der Werttyp je Feld vom Operationstyp fest vorgegeben ist und nicht aus dem Wert selbst erraten wird — der boolesche Wert `true` und der String `"true"` desselben Feldes erzeugen sonst identische Hashbytes; ein Feld darf über verschiedene Operationstypen oder Aufrufe hinweg nie mit unterschiedlichem deklariertem Werttyp verwendet werden. Strings und Snapshot-Schlüssel werden zuvor rekursiv nach Unicode NFC normalisiert, der Snapshot anschließend nach der in AI6 verwendeten Teilmenge von RFC 8785 serialisiert und diese JCS-Bytes werden nicht erneut normalisiert. Diese Teilmenge ist bewusst enger als der volle RFC: Ein Gleitkommaparameter wird von `ControlOperationHasher::parameterValue()` grundsätzlich abgewiesen, und eine Ganzzahl oberhalb von 2^53 wird nicht gemäß der in RFC 8785 vorgesehenen IEEE-754-Rundung serialisiert, sondern bleibt als exakte Dezimaldarstellung erhalten. Beides ist eine gewollte Einschränkung auf sicher rundtrip-fähige Werte, kein offener Mangel dieser Implementierung. Eine durch Normalisierung entstehende Schlüsselkollision wird abgewiesen. Das Ergebnis ist kleingeschriebenes hexadezimales SHA-256.

Der Worker beansprucht pro Projekt höchstens eine mutierende Operation über genau ein bedingtes Datenbankupdate. Diese erste Compare-and-Swap-Phase heißt **Claim**. Operations-ID, monotoner Attempt-Token, Lease-Ablauf und Heartbeat bilden die Besitzgrenze; Fortschritt, Finalisierung und Freigabe vergleichen immer Operations-ID und Attempt-Token. Die zweite Compare-and-Swap-Phase heißt **Publish**: Sie schreibt das gebundene Ergebnis nur unter der weiterhin exakt besessenen Projektsperre. Abgelaufene Tokens können weder Fortschritt schreiben noch eine neuere Lease freigeben. Der separate Schedulertask `ai6-control-operation-reconciler` sucht neben `ai6-runtime-scheduler` regelmäßig nach verwaisten oder ausgeschöpften Queue-Zustellungen, ausstehenden Recoveryentscheidungen ohne Job und abgelaufenen Leases. Vor einer erneuten Zustellung prüft er, dass kein lauffähiger Queue-Job mehr existiert; den realen Effektzustand prüft der wiederaufgenommene Worker unter dem Effekt-Lock, bevor er fortschreibt, terminalisiert oder einen neuen Recoverybefund erhebt.

Die Deploy-Key-Provisionierung läuft ausschließlich in `worker` und kann nur durch einen aktiven globalen Administrator mit Projektrolle `admin` ausgelöst werden. Vor dem Kindprozess werden Launch-Intent, Worker-Boot-ID, SHA-256 der eindeutig JSON-gerahmten Argumentliste und autorisierter Snapshot gebunden. `ControlProcessRunner::startBlocked()` hält das fest konfigurierte `ssh-keygen` hinter dem deterministisch aus der Projektkennung abgeleiteten, vorprovisionierten Effekt-Lock an. Erst nachdem Prozess-ID und tatsächlicher Startzeitpunkt per CAS gespeichert wurden, gibt der Worker den Prozess frei; während des Wartens erneuert ein eng begrenzter Callback der bestehenden Process-Naht den Lease-Heartbeat. Der feste Wrapper `app/AI6/Git/generate-deploy-key.sh` setzt ausschließlich die leere Ed25519-Passphrase; dynamische Werte bleiben einzelne Argumente und werden nicht als Shellkommando interpretiert. Das erzeugte Ergebnis trägt Operation-ID und Request-Hash in seinem festen Kommentar, und vor jeder Persistenz oder Aktivierung leitet der Worker den öffentlichen Schlüssel erneut aus dem privaten Schlüssel ab und verifiziert das Paar. Die Saga persistiert nacheinander `key_generated`, `key_activated`, `provisioning_finalized` und schließlich den einzigen terminalen Abschluss `attempt_completed`. Nach erfolgreicher Erzeugung werden Operation, Request-Hash, Attempt und Public-Key-Fingerprint in `intent.json` gebunden. Der vollständige Bundle-Ordner wird in `key_activated` unter direktem `EffectLock::acquire()` atomar in den aktiven Pfad verschoben. `provisioning_finalized` bindet anschließend private Keyreferenz, öffentlichen Schlüssel und `provisioned` in SQLite; erst `attempt_completed` erzeugt genau ein an Operation, Request und Fingerprint gebundenes Ergebnis und darf die Projektsperre freigeben.

Der verwaltete Baum liegt standardmäßig unter `/var/lib/ai6/managed`. `.control-staging/` und `deploy-keys/` gehören UID/GID `10001`, Modus `0700`; das übergeordnete Volume und `effect-locks/` bleiben privilegiert kontrolliert. Pfadauflösung, Erzeugung und Cleanup prüfen Symlinks und Containment. Terminales Cleanup entfernt ausschließlich den Stagingteilbaum der gebundenen Operation einschließlich aller ihrer versuchseigenen Unterbäume und niemals einen aktiven Schlüssel. Ein fehlgeschlagener erster Versuch bleibt über `provisioning_failed` explizit erneut auslösbar.

| Schlüssel | Default | Bedeutung |
|---|---:|---|
| `AI6_MANAGED_PROJECT_ROOT` | `/var/lib/ai6/managed` | persistenter, privilegiert eingerichteter Wurzelpfad |
| `AI6_DEPLOY_KEY_ROOT` | `/var/lib/ai6/managed/deploy-keys` | im Managed Root enthaltener aktiver Schlüsselbaum |
| `AI6_SSH_KEYGEN_BINARY` | `/usr/bin/ssh-keygen` | festes Keygen-Programm des Worker-Images |
| `AI6_CONTROL_OPERATION_LEASE_SECONDS` | `120` | maximale Lease-Dauer eines Attempts |
| `AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS` | `30` | Heartbeatintervall, strikt kürzer als die Lease |
| `AI6_CONTROL_OPERATION_RECONCILER_SECONDS` | `30` | Mindestalter für Scheduler-Reconciliation |
| `AI6_CONTROL_OPERATION_MAX_ATTEMPTS` | `3` | Grenze vor geprüfter Terminalisierung oder Recovery |
| `AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE` | `/var/lib/ai6/managed/known_hosts` | kanonische, serverseitig gegen die konfigurierten Pins geprüfte Hostkeydatei des Workers |
| `AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST` | `refs/heads/main` | exakte, komma-separierte Live-Refs ohne Wildcards |
| `AI6_CONTROL_OPERATION_STALE_SECONDS` | `300` | positive Altersgrenze der UI-Anzeige für die letzte erfolgreiche Synchronisierung |
| `AI6_CONTROL_OPERATION_RECONCILIATION_BUDGET` | `8` | positive, auf 100 begrenzte Zahl automatischer Recovery-Abgleichversuche |
| `AI6_TICKET_MAX_CANDIDATES` | `100` | positive, serverseitige und auf 1000 begrenzte Höchstzahl separater Blob-Lesezyklen pro Ticketinventur; bei Überschreitung ist jeder Einzelpfad-Refresh des Projekts gesperrt, bis der Wert instanzweit erhöht oder der wirksame Ticketbestand verkleinert wurde |

Bleibt nach einem Absturz ein vorhandener, aber nicht eindeutig gebundener aktiver Schlüssel zurück, wechselt die Operation sichtbar in `recovery_required`. Der Befund benennt auf Deutsch den beobachteten Außenzustand, den persistierten Intent und die konkrete Abweichung; sein Hash bindet genau diese stabilen Inhalte und ändert sich bei einer unveränderten Wiederholungsprüfung nicht durch Versuchszähler oder Zeitstempel. Kann der Außenstand vorübergehend nicht beobachtet werden, bleibt der letzte echte Befund erhalten. Nur ein aktiver globaler Administrator darf nach einer frischen, einmal verwendbaren Step-up-Bestätigung genau eine der typisierten Entscheidungen `retry_reconciliation`, `adopt_external_state` oder `abandon_operation` festhalten. Die Entscheidung ist an Projekt, Operation, Attempt-Token, Operationsversion, Finding-Hash und Ausgangszustand gebunden und wird erst im Worker wirksam. Operation und verbrauchte Entscheidung wechseln in derselben Datenbanktransaktion; die dateisystemübergreifende Adoption besitzt davor einen unveränderlichen, fingerprintgebundenen Anwendungsmarker und kann dadurch nach einem Crash unter einem höheren Attempt sicher fortgesetzt werden. `abandon_operation` verlangt eine Begründung sowie serverseitig typisierte Evidenz: entweder einen Commit oder Base-Commit plus SHA-256 des vollständigen Prüfdiffs, jeweils zusätzlich an Projekt, Operation und Finding gebunden. Diagnosen durchlaufen vor Persistenz und Darstellung ausschließlich den zentralen `Redactor`; interne Exceptiontexte werden nicht in HTTP-Antworten übernommen.

### Managed-Clone, Clone und Fetch

Ein aktiver globaler Administrator löst Clone und Fetch ausschließlich als `managed_clone` beziehungsweise `managed_fetch` aus. Der HTTP-Request beansprucht nur die Projektsperre, persistiert den kanonischen Auftrag und stellt den Database-Queue-Job zu; weder Controller noch View lesen den Managed-Clone oder starten Git. Beide Operationen binden im Request-Hash den exakten Control-Ref und die erwartete Version der aktiven Control-Bindung.

Vor dem ersten Clone ermittelt ein read-only `git ls-remote --refs` genau einen kleingeschriebenen 64-stelligen Control-OID. Der Worker persistiert diesen OID zusammen mit seinem Attempt-Token vor dem ersten wirkenden Prozess. Der veröffentlichte Operations- und Datenbankvertrag unterstützt derzeit ausschließlich Remotes im SHA-256-Objektformat; ein 40-stelliger SHA-1-OID wird geschlossen als ungültige Bindung abgewiesen. Eine Erweiterung auf SHA-1 benötigt vor einer Implementierung eine ausdrückliche menschliche Produkt- und Vertragsentscheidung. Bis dahin muss auch das reale Gate `AI6-006D/MG-01` mit einem SHA-256-Remote durchgeführt werden. Der Clone entsteht als bare Repository ausschließlich unter `.control-staging/<operation>/<attempt>/repository`, läuft unter dem projektgebundenen Effekt-Lock und wird verworfen, wenn der geklonte Control-Ref vom Probe-OID abweicht. Erst der eigentümergebundene Publish verschiebt ihn unter `projects/<project_identifier>/repository`; ein vorheriger Repositorybestand wird bis zum erfolgreichen Abschluss als versuchseigenes Recoverymaterial erhalten.

Fetch schreibt mit `--no-write-fetch-head`, `--no-auto-maintenance`, abgeschalteter Garbage Collection und deaktivierten Reflogs ausschließlich nach `refs/ai6/attempts/<operation>/<attempt>/control`. Heruntergeladene Objekte dürfen dem gemeinsamen inhaltsadressierten Objektspeicher hinzugefügt werden. Ausschließlich der nach erneutem Lease- und Effekt-Lock-Abgleich ausgeführte Publish bewegt den konfigurierten Live-Control-Ref. Jeder versuchseigene Ref wird per Compare-and-Swap gegen seinen beobachteten OID entfernt; fremde oder inzwischen veränderte Refs werden niemals gelöscht.

Die Publish-Saga durchläuft `effect_staged`, `outcome_published`, `binding_finalized` und als einzige terminale Phase `attempt_completed`. Zwischen Git beziehungsweise Dateisystem und SQLite existiert keine gemeinsame Transaktion: Die Reconciliation liest deshalb den realen Live-Ref, vergleicht ihn mit `target_control_oid` und holt nur den fehlenden Schritt nach. Die aktive `projects.control_oid` wird erst nach bestätigtem Außenzustand per Compare-and-Swap gegen `control_binding_version`, Operations-ID und Attempt-Token fortgeschrieben. Ein verlorener Attempt veröffentlicht danach nichts mehr; ein Versionskonflikt bei weiterhin gehaltener Projektsperre bleibt als sichtbares `recovery_required` bestehen. Cleanup entfernt ausschließlich Operations-Staging und versuchseigene Refs, niemals den aktiven Managed-Clone.

### Control-Branch-Wechsel und Invalidierungsgeneration

Ein Control-Branch-Wechsel entsteht ausschließlich als typisierte `control_branch_change`-Operation eines aktiven globalen Administrators und verlangt eine frische, einmal verwendbare Step-up-Bestätigung. Die erste Compare-and-Swap-Phase **Claim** beansprucht die freie Projektsperre. Danach ermittelt ein **read-only Remote-Probe** den exakten 64-stelligen SHA-256-OID des neuen, serverseitig allowlisteten Ref, ohne den verwalteten Baum zu verändern. Die zweite Compare-and-Swap-Phase **Publish** verlangt weiterhin exakt Operations-ID und Attempt-Token sowie den gebundenen alten Branch, alten Control-OID und `control_binding_version`.

Der erfolgreiche Publish schreibt in einer SQLite-Transaktion den neuen `control_branch`, leert die aktive `control_oid`, setzt die ausstehende Bindung aus Ref, Probe-OID und Quelloperation, erhöht `control_binding_version` und `control_generation` und legt den unveränderlichen Auditeintrag mit Actor, Zeitpunkt sowie alten und neuen Branch- und OID-Werten an. Damit ist `control_generation` der einzige Zustandsträger der Invalidierung: Ein abhängiger Bestand ist ohne Benachrichtigungsjob sofort stale, sobald seine mitgeschriebene Generation nicht mehr der Projektgeneration entspricht.

Solange die ausstehende Bindung existiert, bleibt die aktive Control-OID leer. Der anschließende `managed_fetch` bindet Quelloperation, Bindungsversion und OID dieses Pending-Tripels in seinen Request-Hash. Nur wenn Remote-Control-Head und das unveränderte Tripel exakt passen, setzt seine Bindungsfinalisierung die aktive OID und löscht die ausstehende Bindung atomar in derselben Transaktion. Ein verschobenes Remote oder eine neuere Branchentscheidung endet als sichtbarer Bindungskonflikt und lässt die ausstehende Bindung bestehen. Repositorydateien, `.git/config` und als Anweisung formulierter Repositoryinhalt bleiben Evidenz und können weder einen Branchwechsel auslösen noch ihn autorisieren.

### Blobgebundene Read Models und Einzelpfad-Refresh

`ticket_refresh` ist eine typisierte asynchrone Control Operation für genau einen repositoryrelativen Pfad. Weil sie die exklusive Projektoperationssperre beansprucht, dürfen nur globale Administratoren und Projektoperatoren sie einreihen; Viewer und Approver bleiben auf den lesenden Zugriff beschränkt. Die Oberfläche liefert nur einen untrusted Kandidaten. Server und Worker kanonisieren ihn unabhängig und akzeptieren ihn ausschließlich unter dem freigegebenen `tickets_path` der effektiven Projektkonfiguration; weder Browserinput noch nicht freigegebener Repositoryinhalt setzt oder erweitert diesen Basispfad. Der Worker inventarisiert dafür NUL-sicher ausschließlich die direkten Kindpfade dieses Baums am gebundenen Control-Commit und liest nur reguläre, case-genaue `<TICKET-ID>.md`-Blobs. `AI6_TICKET_MAX_CANDIDATES`, standardmäßig `100`, begrenzt die Zahl separater Blob-Lesezyklen pro Inventur; eine Überschreitung endet als benannter terminaler Konflikt, bevor ein Kandidat gelesen wird, und sperrt damit jeden Einzelpfad-Refresh dieses Projekts. Der Betreiber kann den instanzweiten Wert bis zur harten Obergrenze `1000` erhöhen. Reicht das nicht, muss der wirksame Ticketbestand verkleinert werden. Eine projektbezogene Kandidatengrenze existiert derzeit nicht; bis zu einer ausdrücklichen normativen Planrevision gilt ausschließlich die instanzweite Grenze `AI6_TICKET_MAX_CANDIDATES`. Andere Endungen, Mehrfachendungen, Unterverzeichnisse, Symlinks, Gitlinks und ungültige Namen werden ignoriert; alle Case-fold-Kollisionsgruppen, doppelt deklarierte IDs und Dateiname-/ID-Konflikte sind dagegen projektweite Validierungsfehler und nennen die betroffenen sicheren Kandidatennamen. Der Auftrag persistiert weiterhin nur seinen einen gebundenen Zielpfad. Absolute Pfade, `..`, Backslashes, Steuerzeichen und Pfade außerhalb des Basispfads werden ohne Read Model abgewiesen. Jeder gelesene Kandidatenblob passiert vor Parser, Normalisierung und Hashing die zentrale typisierte UTF-8-Grenze. Ein ungültiger Kandidat blockiert die Inventur anderer Pfade nicht; wird er selbst als Ziel angefordert, endet sein Refresh als `refresh_blob_not_utf8` statt als Retry.

Jede Projektion bindet Projekt, Operation, relativen Pfad, Control-Commit, Blob-SHA, `control_generation`, das wirksame Validierungsprofil und die Erzeugungszeit. Neue Refreshes veröffentlichen ausschließlich `invalid` mit strukturierten Fehlern und ohne Contract-Hash oder `valid` mit dem kanonischen `ticket_contract_sha256`; `unparsed` bleibt nur als vorübergehend lesbarer Altzustand bis zur Reprojektion erhalten. Gültigkeit ist immer profilqualifiziert. Eine Abweichung vom erforderlichen Profil trägt `validation_profile_mismatch` in `source_blockers` und sperrt Editor wie Approval fail closed. Der zentrale `Redactor` erzeugt den gespeicherten Inhalt sowie Typ, Feld/Span, Marker, Fingerprintversion, Key-ID und projektgebundenen HMAC-Fingerprint je Treffer. Entfernte Klartexte und unkeyed Digests werden nicht gespeichert. Die zentrale `TicketReadModelUsePolicy` trifft getrennte Entscheidungen: Ein frisches, unredigiertes und profilgerecht geprüftes `invalid` ist editierbar, aber nie approvable; ein entsprechendes `valid` kann beide Entscheidungen erfüllen. Das alte kombinierte Datenbankfeld bleibt ausschließlich als nicht freigebender Kompatibilitätswert erhalten und ist keine Entscheidungsquelle.

Vor AI6-007 erzeugte Altprojektionen werden einmalig und beliebig wiederholbar im Worker-Kontext reprojiziert:

```bash
AI6_RUNTIME_ROLE=worker php artisan ai6:tickets:reproject-unparsed
```

Das Kommando liest jeden gebundenen Blob erneut über die gehärtete Git-Naht und schreibt nur per Compare-and-Swap gegen Projekt, Pfad, Control-Commit, Blob-SHA, `control_generation` und Profil. Ein neueres Refreshergebnis, eine höhere Generation oder ein Profilwechsel gewinnt; der Backfill meldet den Konflikt, überschreibt nichts und endet ungleich null, solange `unparsed`-Zeilen verbleiben.

Staleness ist kein geschriebenes Flag. Die Projektansicht berechnet sie beim Lesen aus getrennten Prädikaten: Die mitgeschriebene `control_generation` weicht nach einem Branchwechsel von der aktuellen ab oder der projizierte Control-Commit weicht nach einem Fetch von der aktiven `control_oid` ab. Sie listet alle vorhandenen Read Models und zeigt je Projektion das auslösende Prädikat zusammen mit Control-Commit, Blob-SHA, Aktualisierungs- und Redactionzustand. Read Models sind verwerfbare, jederzeit aus Git rekonstruierbare Ansichten und niemals Ticket-, Status-, Approval- oder Editorautorität.

Die Projektansicht zeigt aktive Control-OID, ausstehende Bindung, `control_generation`, Bindungsversion, Zeitpunkt der letzten erfolgreichen Clone-/Fetch-Operation, die daraus mit `AI6_CONTROL_OPERATION_STALE_SECONDS` berechnete Aktualität sowie einen anstehenden Recoveryzustand. Der Workerpfad aus `AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE` muss vor dem Lauf als reguläre, kanonische und nicht gruppen- oder fremdschreibbare Datei im bereits vorhandenen Managed-Volume bereitgestellt sein. Ihr Inhalt wird bei jedem Remotezugriff gegen `AI6_GIT_PINNED_HOST_KEYS` geprüft; AI6 lernt keine Hostkeys dynamisch. Der `app`-Dienst erhält weder diesen Pfad als Umgebungswert noch das Managed-Volume.

Die externen Gates bleiben offen: `AI6-006C/MG-01` verlangt in einer realen Laufzeit den Rechte- und Mountnachweis je Rolle für den privaten Deploy-Key: Nur `worker` und der danach beendete einmalige Dienst `init` erreichen das Schlüsselvolume beschreibbar, der private Schlüssel ist auf den Workerbenutzer beschränkt und weder Antwort noch Log enthalten seinen privaten Teil. `AI6-006C/MG-02` verlangt in der realen Compose-Laufzeit zwei Worker auf demselben persistenten Volume, den nachweisbaren instanzübergreifenden Effekt-Lock, das anschließende Erwerben nach abruptem Containerende des Halters sowie die unveränderte, idempotente Bereitstellung der Lockobjekte durch einen zweiten `init`-Lauf unter unprivilegierter Workeridentität. `AI6-006D/MG-01` bleibt offen, bis ein Mensch den provisionierten öffentlichen Deploy-Key in einem realen Remote hinterlegt, Clone und Fetch auslöst und die gebundene Control-OID sowie unveränderte fremde Refs mit commit- oder diffgebundener Evidenz bestätigt. `AI6-006E/MG-01` verlangt einen menschlich ausgeführten Control-Branch-Wechsel mit frischer Step-up-Bestätigung gegen ein reales Remote: Danach müssen die ausstehende Bindung den geprüften Ziel-OID tragen, die aktive Bindung leer und `control_generation` erhöht sein; erst ein Fetch gegen exakt diesen Ziel-OID darf die aktive Bindung wiederherstellen. Die Evidenz ist an einen Commit oder an Base-Commit plus SHA-256 des vollständigen Prüfdiffs zu binden. Automatisierte Tests ersetzen diese menschlichen Evidenzen nicht.

Der erweiterte Linux-Nachweis für TC-14/TC-15 verwendet `AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY` und `AI6_EFFECT_LOCK_SECONDARY_FIXTURE_DIRECTORY`. Er muss als nicht privilegierter Laufzeitbenutzer erfolgen und bricht unter `root` ausdrücklich ab. Das erste, privilegiert vorbereitete Fixture enthält gültige Objekte `lock-0001` und `lock-0006`, ein Symlinkobjekt `lock-0002`, ein zu weit berechtigtes `lock-0003`, ein fremdes `lock-0004` und kein `lock-0005`; das zweite liegt auf einem vom temporären Verzeichnis verschiedenen Mount und enthält ein gültiges `lock-0001`. Alle Lock- und Blocked-Control-Tests verwenden diese fremdbesessenen Objekte. Ohne die extern vorbereiteten Pfade bleiben die betreffenden Linux-Beweise als übersprungene externe Tests sichtbar.

Außerhalb des gebauten Images muss die Verifikation den Immutabilitätsvertrag selbst herstellen: `app/AI6/Shared/Process/control-process-wrapper.sh` und `bin/ai6-git-ssh.sh` dürfen für die Laufzeitidentität sowie für Gruppe und andere Identitäten nicht schreibbar sein; der von Git direkt gestartete SSH-Wrapper muss zusätzlich ausführbar bleiben. Diese Vorbedingung gilt vor jedem Control-/Git-Lauf. Der vollständige Linux-Verifikationslauf erfolgt daher in dieser Reihenfolge als nicht privilegierte Laufzeitidentität; die Fixturepfade stehen beispielhaft für die zuvor privilegiert vorbereiteten, read-only eingebundenen Mounts:

```bash
chmod a-w app/AI6/Shared/Process/control-process-wrapper.sh
chmod a+x,a-w bin/ai6-git-ssh.sh
test ! -w app/AI6/Shared/Process/control-process-wrapper.sh
test ! -w bin/ai6-git-ssh.sh
test -x bin/ai6-git-ssh.sh
AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY=/fixtures/primary \
AI6_EFFECT_LOCK_SECONDARY_FIXTURE_DIRECTORY=/fixtures/secondary \
php artisan test
```

Ein Checkout, in dem der ausführende Benutzer einen der beiden Wrapper weiterhin schreiben kann, ist kein gültiger Sicherheitsnachweis. Nach der Verifikation darf der Eigentümer das Schreibrecht für eine beabsichtigte Quelltextänderung wieder setzen; vor dem nächsten Lauf ist es erneut mit `chmod a-w` zu entfernen.

Die Git-Laufzeit verwendet ein eigenes Home, eigenes XDG-Verzeichnis, `GIT_CONFIG_NOSYSTEM=1`, die minimale read-only Globalconfig und den leeren versiegelten Hookspfad. Git-Kommandos setzen zusätzlich `--no-pager`, deaktivieren Hooks, fsmonitor, Credentialhelper, externe Diffs, Signing und rekursive Submodule und erlauben als Transport ausschließlich SSH. Vor Checkout werden lokale Filter-, textconv- und Pagerwerte inventarisiert und neutralisiert; lokale SSH- oder Signing-Programme führen zu einem geschlossenen Fehler. Clone verwendet immer `--no-checkout` und `--no-recurse-submodules`, Fetch und Checkout ebenfalls die nicht rekursive Form.

Serverseitig erforderlich sind exakte, kommaseparierte Werte:

| Schlüssel | Bedeutung |
|---|---|
| `AI6_GIT_ALLOWED_HOSTS` | kanonische SSH-Hosts ohne Wildcards |
| `AI6_GIT_ALLOWED_REMOTE_PATHS` | exakte Pfade wie `organisation/projekt.git` |
| `AI6_GIT_ALLOWED_REF_PATTERNS` | erlaubte vollständige Refs, standardmäßig `refs/heads/*` |
| `AI6_GIT_PINNED_HOST_KEYS` | je Host `host=SHA256:<Fingerprint>`, mehrere Pins mit `|` |
| `AI6_GIT_BINARY` / `AI6_GIT_EXECUTABLE_PATH` | fester Git-Binärpfad und fester interner Suchpfad ohne Übernahme des Eltern-`PATH` |
| `AI6_GIT_SSH_BINARY` | kanonischer, symlinkfreier SSH-Binärpfad; festes Image-Default `/usr/bin/ssh` |
| `AI6_GIT_EXECUTION_HOME` / `AI6_GIT_XDG_CONFIG_HOME` | isolierte beschreibbare Git-Verzeichnisse |
| `AI6_GIT_GLOBAL_CONFIG` / `AI6_GIT_HOOKS_PATH` | kontrollierte read-only Config und leerer versiegelter Hookspfad |

`file://`, `git://`, `http://`, `https://`, `ext::`, unbekannte Hosts, nicht exakte Pfade, unzulässige Refs und nicht zur eigenen `known_hosts` passende Hostkeys werden vor dem operativen Git-Prozess abgelehnt. Zulässig sind ausschließlich `ssh://git@host/pfad.git` und `git@host:pfad.git` auf Port 22. Projektbezogener privater Schlüssel und `known_hosts` werden als zwei kanonische, nicht symbolische Dateien übergeben; der private Schlüssel darf keine Gruppen- oder Fremdrechte besitzen.

`bin/ai6-git-ssh.sh` ist der einzige SSH-Einstieg. Git verwendet dafür die einfache SSH-Variante ohne dynamisch ergänzte SSH-Optionen; Remotes mit expliziter Portangabe sind deshalb unzulässig und der feste Standardport 22 gilt. Der Wrapper verwirft `GIT_SSH_COMMAND`, `GIT_SSH` und `SSH_AUTH_SOCK` aus seiner Umgebung und startet ausschließlich das über die positive Runner-Allowlist gesetzte `AI6_GIT_SSH_BINARY` mit dem festen Default `/usr/bin/ssh`, ohne Benutzerconfig, Passwort, interaktive Eingabe, Agent- oder Portweiterleitung, Proxykommando, DNS-Hostkeyprüfung oder Hostkeyaktualisierung. Aktiv bleiben ausschließlich der projektspezifische Schlüssel, die projektspezifische `known_hosts`, `StrictHostKeyChecking=yes` und der normale Git-SSH-Aufruf. Das manuelle Gate `AI6-006A/MG-01` bleibt offen, bis eine reale, commit- beziehungsweise diffgebundene Prozessbeobachtung Clone, Fetch und Checkout bestätigt.

## Freigegebene Projektkonfiguration

`.ai6/config.yaml` ist versionierter, nicht vertrauenswürdiger Repositoryinhalt. Ausschließlich die asynchrone Worker-Operation `config_refresh` liest den festen Pfad am aktiven Control-Commit. Sie veröffentlicht entweder einen strukturiert validierten, redigierten Entwurf oder den sichtbaren, fehlerfreien Zustand `absent`; Browser und App lesen die Datei nie direkt.

Das geschlossene Schema erlaubt nur `version`, `tickets_path`, `ticket_validation_profile`, `push_mode`, `auto_start_next`, `dependency_satisfied_statuses`, `defaults`, `limits`, `scope` und `checks`. Check- und Modellnamen sind reine Referenzen auf serverseitige Allowlists. Auch erfüllende Abhängigkeitsstatus werden gegen eine vertrauenswürdige serverseitige Allowlist geprüft: Ausgeliefert sind ausschließlich `review` und `done`, während `done` der Serverdefault bleibt. Damit können blockierte oder verworfene Tickets nie allein durch Projektinhalt als erfüllte Abhängigkeit gelten; jede instanzweite Verbreiterung ist eine bewusste Änderung der vertrauenswürdigen Serverkonfiguration. Shellstrings, Security-, Credential-, Remote-, Control-Branch- und Managed-Path-Schlüssel sind strukturell ausgeschlossen. Limits müssen positive Ganzzahlen unter den unveränderlichen Servermaxima sein; Scope-Globs bleiben reine Policywerte.

Die konservativen Serverdefaults setzen derzeit `auto_start_next=false` und genau den Reviewer `grok-cli-review`. Das weicht sichtbar von den in Plan §19 festgelegten Produktdefaults „Auto-Start aktiv“ und zwei unabhängigen Reviewer-Slots ab. Diese Dokumentation ist keine Planrevision und keine Freigabe der Abweichung: Die Umstellung auf automatischen Start und den zusätzlichen externen `copilot-cli-review`-Slot bleibt bis zu einer ausdrücklichen menschlichen Betriebsentscheidung offen.

Ein Projekt-`approver` sieht den semantischen Diff und kann einen gültigen Entwurf nach frischem Step-up genau einmal freigeben. Der Compare-and-Swap bindet Projekt, Entwurf, Control-Commit, Blob-SHA, kanonischen Config-Hash, Validierungsbefund und `control_generation`. Snapshots sind unveränderlich und historisch weiter auflösbar. Eine neue vorhandene Repositoryfassung wirkt ohne neue Freigabe nicht. Ein `absent`-Befund am aktuellen Control-Commit setzt die effektive Auflösung dagegen bewusst auf `server_defaults` mit `blob_sha=null` und demselben domain-separierten Hashvertrag zurück, auch wenn in derselben Generation bereits ein Snapshot freigegeben wurde. Diese menschlich entschiedene Auslegung folgt AC-04 und der normativen Planvorgabe „Fehlende Datei verwendet Serverdefaults“; die frühere gegenteilige Formulierung im Review-Fokus von AI6-010 ist im Ticket korrigiert.

Der zentrale `Redactor` bleibt vor dem Parser sowohl UTF-8- als auch Secret-Ausgabegrenze. Ein Treffer im vollständigen Rohinhalt sperrt den Entwurf weiterhin fail closed; Kommentare und andere YAML-Bytes werden nicht als strukturell wirkungslos von dieser Sicherheitsprüfung ausgenommen. Diese in der Umsetzung beibehaltene Sicherheitsentscheidung ist strenger als AC-01/TC-02, die nur strukturelle Validitätsentscheidungen verlangen, und verhindert, dass erkannte schützenswerte Werte über Entwurf oder Fehlerpfade weiterverarbeitet werden.

Produktive Ticket-Refreshes, Editor-/Approval-Entscheidungen und Ticketprojektionen verwenden ausschließlich diese effektive Bindung. Jede Projektion schreibt Validierungsprofil und effektiven Config-Hash mit. Abweichende Generation, Control-OID, Profil- oder Hashbindung ist ein reines Lesezeit-Stalenessprädikat und sperrt die Verwendung bis zur Reprojektion:

```bash
AI6_RUNTIME_ROLE=worker php artisan ai6:tickets:reproject-unparsed --project-config
```

Auch der historische Unparsed-Backfill ohne `--project-config` verwendet `tickets_path`, Validierungsprofil und Config-Hash ausschließlich aus der effektiven Projektbindung. Separate Instanzwerte für Refresh-Basispfad oder Ticketvalidierungsprofil existieren nicht mehr.

## Ticketmanifest

`docs/AI6_TICKET_MANIFEST.yaml` ist eine deterministisch erzeugte Ansicht der Blueprint-Metadaten und Requirement-Zuordnungen aus dem Implementierungsplan, keine zweite gepflegte Wahrheit.

```bash
php scripts/generate-ticket-manifest.php
php scripts/generate-ticket-manifest.php --check
```

Der erste Befehl erzeugt den Export. Der zweite beendet sich mit Exitcode 1, wenn Plan und committed Manifest voneinander abweichen.

## Modul- und Codekonventionen

Die elf Module liegen unter `app/AI6/` und verwenden die vorhandene PSR-4-Zuordnung `App\\` auf `app/`. Gemeinsame technische Bausteine gehören nach `Shared`; Fachlogik bleibt im zuständigen Modul. Es gibt keine generischen Repository-, `BaseService`-, `BaseAction`- oder Eventbus-Schichten.

Actions bilden einen einzelnen Anwendungsfall ab und erhalten ein eindeutiges Verb im Namen:

```php
final class NormalizeValueAction
{
    public function __construct(private ValueNormalizer $normalizer) {}

    public function execute(NormalizeValueData $data): string
    {
        return $this->normalizer->normalize($data->value);
    }
}
```

Services kapseln eine konkrete wiederverwendbare Fähigkeit und bleiben konkrete Klassen, solange keine echte technische Grenze oder ein benötigter Fake ein Interface verlangt:

```php
final class ValueNormalizer
{
    public function normalize(string $value): string
    {
        return trim($value);
    }
}
```

DTOs sind unveränderliche Datenträger ohne Orchestrierungs- oder Infrastrukturverhalten:

```php
final readonly class NormalizeValueData
{
    public function __construct(public string $value) {}
}
```

## Projektdokumentation

- [Implementierungsplan V1.7.1](docs/AI6_IMPLEMENTATION_PLAN.md) — normative Quelle für Anforderungen, Architektur, Meilensteine und Ticket-Blueprints.
- [Ticket-Template V1](docs/AI6_TICKET_TEMPLATE_V1.md) — verbindliches Format sowie Erzeugungs- und Umsetzungsvertrag für Detailtickets.
- [Ticketübersicht](tickets/README.md) — Erzeugungsstand und Abhängigkeiten; keine autoritative Statusquelle.
- [Agentenanweisungen](AGENTS.md) — verbindliche Regeln für agentische LLMs in diesem Repository.
