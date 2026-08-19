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

Die Tabellenbezeichnung **SecurityPolicy-Variablen** umfasst `AI6_SECURITY_PROFILE`, `AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE` und die sieben in der Sicherheitstabelle unten aufgeführten Maßnahmenschlüssel. **Redaction-Keyring-Variablen** umfasst `AI6_REDACTION_ACTIVE_KEY_ID` und `AI6_REDACTION_KEYS`. **HTTP-Härtungsvariablen** umfasst `AI6_HTTP_TRUSTED_HOSTS`, `AI6_HTTP_TRUSTED_PROXIES` und `AI6_HTTP_SESSION_SAME_SITE`. Compose reicht die SecurityPolicy-Variablen identisch an alle PHP-Rollen weiter; die Keyring-Variablen erhalten `app`, `worker`, `scheduler`, `agent` und `checker`, die HTTP-Härtungsvariablen ausschließlich `app`. `init`, `agent`, `checker` und `caddy` erhalten keine HTTP-Werte. Jede PHP-Rolle erhält zusätzlich eine feste, nicht aus `.env` substituierte `AI6_RUNTIME_ROLE`; ausschließlich `init` darf damit den schlüssellosen Migrationsstart durchlaufen. Der Mailboxstart von `agent` oder `checker` benötigt wie jeder andere dauerhafte Prozess einen gültigen Keyring.

| Dienst | Prozess und Heartbeat-Produzent | Erlaubte Umgebungsvariablen | Erlaubte Mounts |
|---|---|---|---|
| `caddy` | Separater Reverse Proxy; HTTP-Healthcheck | keine | ausschließlich `deploy/Caddyfile` read-only |
| `init` | Einmaliger Migrations- und Lock-Bereitstellungsschritt; kein Heartbeat | SecurityPolicy-Variablen, `AI6_RUNTIME_ROLE`, `AI6_MANAGED_PROJECT_ROOT`, `AI6_EFFECT_LOCK_DIRECTORY`, `AI6_EFFECT_LOCK_OBJECT_COUNT`, `AI6_EFFECT_LOCK_OWNER_UID`, `APP_ENV`, `APP_DEBUG`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS` | Datenbank, `storage/` und `ai6_managed` read-write; `/tmp` als `tmpfs` |
| `app` | Apache/PHP; kein Heartbeat, HTTP-Healthcheck | SecurityPolicy-, Redaction-Keyring-, HTTP-Härtungs-, Git-Remote- und `AI6_AUTH_*`-Variablen, `AI6_RUNTIME_ROLE`, `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_NAME`, `APP_URL`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `SESSION_DRIVER`, `AI6_CONTROL_OPERATION_LEASE_SECONDS`, `AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS`, `AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST`, `AI6_CONTROL_OPERATION_STALE_SECONDS` | Datenbank und `storage/` read-write, Nachweise read-only; `/tmp` als `tmpfs` |
| `worker` | `queue:work`; `Looping`-Listener schreibt auch im Leerlauf, ausschließlich am Worker-Heartbeatziel | SecurityPolicy-, Redaction-Keyring- und Git-Remote-Variablen, `AI6_RUNTIME_ROLE`, `APP_ENV`, `APP_DEBUG`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_QUEUE_RETRY_AFTER`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `AI6_EXECUTION_DIRECTORY`, `AI6_AGENT_EXECUTION_ROOT`, `AI6_AGENT_OUTPUT_ROOT`, `AI6_CHECKER_EXECUTION_ROOT`, `AI6_CHECKER_OUTPUT_ROOT`, die drei `AI6_*_CREDENTIAL_REVISION`-Werte, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_MAX_AGE`, `AI6_WORKER_TIMEOUT`, `AI6_MANAGED_PROJECT_ROOT`, `AI6_DEPLOY_KEY_ROOT`, `AI6_EFFECT_LOCK_DIRECTORY`, `AI6_EFFECT_LOCK_OBJECT_COUNT`, `AI6_EFFECT_LOCK_OWNER_UID`, `AI6_CONTROL_OPERATION_LEASE_SECONDS`, `AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS`, `AI6_CONTROL_OPERATION_RECONCILER_SECONDS`, `AI6_CONTROL_OPERATION_MAX_ATTEMPTS`, `AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE`, `AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST`, `AI6_CONTROL_OPERATION_STALE_SECONDS`, `AI6_CONTROL_OPERATION_RECONCILIATION_BUDGET`, `AI6_SSH_KEYGEN_BINARY` | Datenbank, `storage/`, Nachweise, beide getrennten Eingabe-/Ausgabepaare und `ai6_managed` read-write; eigener Heartbeat und `/tmp` als `tmpfs` |
| `scheduler` | `schedule:work`; der Zehn-Sekunden-Task schreibt den Heartbeat und verwendet einen stabilen Selbsttestschlüssel je Scheduler-Boot-ID | SecurityPolicy- und Redaction-Keyring-Variablen, `AI6_RUNTIME_ROLE`, `APP_ENV`, `APP_DEBUG`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_QUEUE_RETRY_AFTER`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_MAX_AGE`, `AI6_CONTROL_OPERATION_RECONCILER_SECONDS` | Datenbank und `storage/` read-write; eigener Heartbeat und `/tmp` als `tmpfs` |
| `agent` | Laravel-Agent-Mailboxprozess mit rollengebundenem Heartbeat | Redaction-Keyring-Variablen, `AI6_AGENT_EXECUTION_ROOT`, `AI6_AGENT_OUTPUT_ROOT`, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_INTERVAL`, `AI6_HEARTBEAT_MAX_AGE`, `AI6_RUNTIME_ROLE`, `LOG_CHANNEL=stderr` | Agent-Eingabewurzel read-only, getrennte Agent-Ausgabewurzel read-write, eigener Heartbeat und `/tmp`; UID/GID `10002:10001` |
| `checker` | Laravel-Checker-Mailboxprozess mit rollengebundenem Heartbeat und ohne Containernetz | Redaction-Keyring-Variablen, `AI6_CHECKER_EXECUTION_ROOT`, `AI6_CHECKER_OUTPUT_ROOT`, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_INTERVAL`, `AI6_HEARTBEAT_MAX_AGE`, `AI6_RUNTIME_ROLE`, `LOG_CHANNEL=stderr` | Checker-Eingabewurzel read-only, getrennte Checker-Ausgabewurzel read-write, eigener Heartbeat und `/tmp`; UID/GID `10003:10001`, `network_mode: none` |

Datenbank, `storage/`, Ausführungsnachweise und der verwaltete Projekt-/Deploy-Key-Baum sind persistente benannte Volumes. Für jede Ausführungsrolle existieren zwei getrennte flüchtige `tmpfs`-Volumes mit `nosuid,nodev,noexec`: `ai6_*_executions` nimmt die vom Worker erzeugte Mailbox, Exportkopie, Instruktionen und Runtimebindung auf und ist in der Ausführungsrolle read-only; `ai6_*_outputs` nimmt ausschließlich Ergebnis-, Artefakt-, Patch- und Rückkanalbytes auf und ist dort read-write. Der Worker sieht alle vier Volumes, jede Ausführungsrolle ausschließlich ihr eigenes Paar. Verschiedene UIDs verhindern zusätzlich, dass eine Rolle die Ausgabedateien der anderen Rolle über gemeinsame Unix-Gruppenrechte lesen kann. Das Volume `ai6_managed` wird ausschließlich in `init` und `worker` eingebunden; `app`, `scheduler`, `agent`, `checker` und Caddy können die privaten Deploy-Keys nicht erreichen. Der Scheduler erzeugt trotz seines Zehn-Sekunden-Intervalls höchstens einen neuen Nachweis je Container-Boot-ID; wiederholte Jobs desselben Boots treffen denselben persistenten Genau-einmal-Nachweis. Jeder Rollen-Heartbeat liegt dagegen auf einem nur für diesen Container vorhandenen `tmpfs` und ist an eine beim Containerstart erzeugte Boot-ID gebunden. Die Worker-Heartbeatfrist muss beim Start strikt größer als der Worker-Timeout sein, damit ein zulässig langer Job den Worker nicht allein wegen seiner Laufzeit ungesund macht. `agent`, `checker` und Caddy sehen weder Datenbank noch `storage/` oder Nachweisvolume; `agent` und `checker` erhalten außerdem keinen produktiven `APP_KEY`. Der eingebaute Anwendungscode ist in allen sechs AI6-Diensten read-only. Tests, Tickets, normative Dokumente, Entwicklungswerkzeuge und Qualitätskonfigurationen werden durch `.dockerignore` aus dem Produktionsimage ausgeschlossen.

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

## Runzustand und Projektsperre

Ein Browserrequest zum Runstart erzeugt ausschließlich eine typisierte `run_start`-Control-Operation samt persistiertem `ready → in_progress`-Intent und Queuejob; Git wird erst im Worker ausgeführt. Der Worker prüft den aktuellen Approval-Snapshot erneut, verlangt den gebundenen Claim-Parent als Fast-forward-Nachfahren von `approved_control_sha` und nutzt die wiederholbare Ticketstatus-Saga mit Attempt-Ref, Effekt-Lock und Push-CAS. Erst nach dem bestätigten eigenen Commit ruft diese Saga `RunOrchestrator::finalizeClaim()` auf.

Ein gestarteter Run wird ausschließlich über `RunOrchestrator` mutiert. Seine SQLite-Zustände sind `queued`, `running`, `waiting`, `failed`, `completed` und `cancelled`; ein `wait_reason` ist ausschließlich im Zustand `waiting` zulässig. Jede Mutation bindet die erwartete Runversion. Die Projektsperre liegt als `projects.active_run_id` im selben Datensatz wie die Operationslease. Der initiale Claim prüft in einem bedingten Update freien Run, freie Operationslease und alle drei Felder der ausstehenden Control-Bindung; die Run- und Branchwechselpfade fordern dabei ausdrücklich einen inaktiven Run, ohne Clone, Fetch oder Refresh pauschal zu sperren. Die DB-Finalisierung prüft zusätzlich Eigentümeroperation, Attempt-Token, Control-Generation, bestätigten Control-SHA und weiterhin leere Pending-Bindung. Vor dem bestätigten Git-Statuscommit wird kein Run angelegt; danach bindet `runs.status_operation_id` die eigene Runstart-Operation, während `ticket_approval_id` allein die Approval-Lineage hält, und `initial_run_base_sha` sowie `run_base_sha` zeigen auf genau dessen SHA. Ein technischer Fehler löst die Run-Sperre nicht; Abschluss oder Abbruch erfordern eine bestätigte eigene terminale Statusoperation und geben die Sperre gemeinsam mit dem Orchestratorübergang frei.

Welcher Schritt als Nächstes läuft, entscheidet ausschließlich `RunOrchestrator`; die Entscheidung selbst ist eine reine Funktion aus Runzustand, Wartestatus und den bereits erfolgreichen Schritten. Jede Runstufe wird als `execution_jobs`-Datensatz mit Schrittart, Nummer, deterministischem Schlüssel, Versuchszähler und Lease geplant; der Schlüssel ist eindeutig indiziert, ein Schritttyp ohne registrierten Handler wird geplant, aber nicht zugestellt. Der Worker beansprucht seinen Schritt per Compare-and-Swap, bindet zuerst seinen Intent, führt danach den Seiteneffekt aus und veröffentlicht erst zuletzt das Ergebnis; ein Absturz vor dem Effekt lässt den Schritt erneut ausführbar, ein Absturz danach wird aus dem gespeicherten Intent ohne zweiten Effekt abgeschlossen. Verlässt der Run währenddessen den ausführbaren Bereich, wird der Schritt geparkt oder als `run_not_executable` beendet, statt einen Erfolg ohne Wirkung zu veröffentlichen. Überholte Besitzer veröffentlichen nichts mehr. Weil die Queue nach ihren eigenen Versuchen aufgibt, holt der geplante `ai6-run-step-reconciler` abgelaufene Leases, verwaiste geplante Schritte und hinter einem wartenden Run geparkte Schritte zurück und beendet einen Schritt nach dem vertrauenswürdigen Versuchsmaximum sichtbar als fehlgeschlagen. Der Preflight prüft in fester Reihenfolge Approvalbindung, Runbasis, Worktree und Checkpoint, Sandbox- und Prozesspolicyvoraussetzungen sowie Prompt-, Instruction- und Runtimeprofilbindung und endet entweder mit genau einem vorbereiteten Implementierungsschritt oder mit einem benannten Fehlercode; ein technischer Fehler lässt die Projektsperre unberührt. Jedes Timeline-Ereignis läuft vor der Persistenz durch den zentralen `Redactor`. Der fachliche Agententurn gehört nicht zu diesem Basisschritt. Die lesende Run-Timeline nutzt ausschließlich gespeicherte Zustände und Livewire-Polling unter der unveränderten CSP; Browserrequests starten weder Git noch Prozesse.

## Human Requests, Attention-Inbox und Resume

Ein Agentenvorschlag ist untrusted Evidenz. Nur `HumanRequestService` erzeugt und löst Human Requests auf: Er klassifiziert den Vorschlag serverseitig in erlaubte Antwortwirkungen und die Projektaktion `answer_human_request`, lässt Requestart und Optionsschlüssel nur durch den geschlossenen Zeichensatz `^[a-z0-9][a-z0-9_-]{0,31}$`, hält die reservierte Wirkung `cancel` serverseitig und weist `approve_reject` ohne genau die Schlüssel `approve` und `reject` ab, führt Titel, Nachricht, Begründung, Optionslabels und Pfade durch den zentralen `Redactor` und schreibt Request plus Wartestatus `human_question` in einer Transaktion. Ohne gebundenen, aktiven `attention_user`, ohne gebundenen Checkpoint (`checkpoint_not_bound`) oder ohne parkbaren gebundenen Schritt (`bound_step_not_parkable`) entsteht kein Request; jede dieser Bedingungen endet als benannte Ablehnung ohne Wirkung. Jede Runmutation bleibt beim `RunOrchestrator`. Ein partieller eindeutiger Index auf `human_requests.run_id` für `resolution_state=open` erzwingt höchstens einen offenen blockierenden Request je Run; terminal aufgelöste Requests bleiben unverändert lesbar und sperren keinen späteren Warteschritt.

Die Benachrichtigung geht ausschließlich an die Kontoadresse des im Approval gebundenen `attention_user`; `HumanRequestRecipient` löst sie als einzige Naht für Erzeugung und Versand auf und verlangt einen aktiven Benutzer, dessen Projektrolle `answer_human_request` tatsächlich besitzt. `AI6_LOGIN_CONFIRMATION_EMAIL` und Adressen aus Ticket, Projektkonfiguration oder Providertext erreichen den Versand nicht. Der verschlüsselte Queuejob prüft vor dem Versand Existenz, Zustellrevision und offenen Zustand; dieselbe Nachricht wird genau einmal versendet. Ein Mailfehler setzt einen benannten sichtbaren Zustellstatus (`mail_transport_failed`, `mail_transport_not_deliverable`, `attention_user_unavailable`), verändert weder Runzustand noch Wartestatus und wird bis `AI6_HUMAN_REQUEST_NOTIFICATION_MAX_ATTEMPTS` im Abstand `AI6_HUMAN_REQUEST_NOTIFICATION_RETRY_SECONDS` wiederholt. Die E-Mail verweist nur auf die authentifizierte Detailseite und trägt keine wirksame Aktion.

Eine Antwort bindet Runversion, Ticketvertrag, Checkpoint, Scope, Agentenslot und angeforderte Wirkung. Jede Abweichung, jede zweite Antwort und jede unberechtigte Antwort endet als benannte Ablehnung ohne Wirkung; je Request entsteht höchstens eine Intervention. Eine akzeptierte Antwort löst den Wartestatus über `RunOrchestrator::resumeHumanQuestion()` auf, setzt genau den gebundenen Schritt genau einmal fort und stellt ihn ohne Reconciler-Wartezeit erneut zu. Weil ein Request ohne gebundenen Checkpoint gar nicht erst entsteht, ist jede gespeicherte Checkpointbindung ein vollständiger Wert und nie ein leeres Feld. `HumanRequestService::redispatchNotification()` erhöht die Zustellrevision, sodass eine veraltete Jobzustellung ohne zweite Mail endet. Abbrechen folgt dem bestehenden `failRun`-Pfad. `human_question` ist mit Producer `needs_human`, Resolver `bound_answer` und Cancelpfad registriert; kein anderer Wartestatus wird vorgezogen.

Die Attention-Inbox listet ausschließlich offene Requests aus Projekten, für die der angemeldete Benutzer `answer_human_request` besitzt, mit Projekt, Ticket, Wartegrund, Alter und Zustellstatus. Die projektgebundene Detailseite zeigt Frage, Optionen, Empfehlung, Pfade und Bindung und bietet nur die klassifizierten Wirkungen plus Abbrechen. Controller und Livewire-Seiten schreiben keinen Runzustand direkt.

Jeder Run erhält genau einen Worktree unterhalb von `<managed-root>/projects/<projekt>/worktrees/<run>` und genau einen Branch `refs/heads/ai6/runs/<projekt>/<run>`, dessen geschlossene ASCII-Grammatik ausschließlich aus Projektkennung und Run-ID gebildet wird und einen von den verwalteten Control-Refs getrennten Namensraum belegt. Beide entstehen genau auf dem unveränderlichen `initial_run_base_sha`; das aufgelöste Branch-Objekt wird danach gegen genau diesen Anker geprüft. Ein späterer `run_base_sha` ist die aktuelle Candidate-Basis und wird nie als Worktree-Ahn verwendet. Die SQLite-Guards halten Branch, Worktree-Pfad und Checkpointbindung nach dem Setzen unveränderlich, auch gegen ein Zurücksetzen auf `NULL`.

Lokale Checkpoints werden nie gepusht. Sie entstehen mit fester, hostunabhängiger Commit-Identität und festem Zeitstempel und binden Commit-OID, Tree-OID sowie einen über `CanonicalJson` domänenseparierten Diff-Hash am Run; der Hash bindet die tatsächlichen kanonischen Diffdaten mit vollständigen Objektnamen, während ausschließlich das zentrale Redaction-Ergebnis persistiert oder angezeigt wird. Diff-, Tree- und Blob-Zugriffe eines Runs laufen lesend über dieselbe gehärtete Naht.

Jeder wirkende Schritt — Worktree anlegen, entfernen, prunen, Branch löschen und der Checkpoint-Commit — läuft unter demselben projektgebundenen Effekt-Lock wie Clone, Fetch und Push; lesende Schritte brauchen ihn nicht. Automatische Maintenance und Garbage Collection sind für jeden Lauf abgeschaltet. Bricht ein Lauf zwischen Worktree-Erzeugung und Bindung ab, entfernt der nächste Anlauf Worktree, Registrierung und Branch, bevor er neu anlegt; ein zweiter Worktree je Run entsteht nicht. Der Abgleich entfernt jeden Arbeitsbereich ohne aktiven Run symlinksicher, lässt den Arbeitsbereich eines aktiven Runs unberührt und ist wiederholbar idempotent.

Exportierte Agent-, Checker- und Reviewerbäume enthalten in keiner Tiefe eine `.git`-Datei oder ein `.git`-Verzeichnis und damit kein erreichbares Common-Dir, keine Alternates, Refs, Index- oder Hookverzeichnisse; Symlinks, Sondertypen und Pfade außerhalb der Exportwurzel werden vor jeder Ausgabe benannt abgelehnt, sodass kein Teilergebnis entsteht. Der optionale Historienkontext ist ein separates, identitätsfreies und technisch read-only Artefakt außerhalb des Arbeitsbereichs, niemals der verwaltete Clone selbst. Die Importrichtung bleibt einseitig: Ausschließlich der Worker berechnet aus der isolierten Sicht einen Änderungssatz, validiert Pfade, Typen, Symlinks, Scope, Größe und Änderungsumfang und schreibt erst danach in den Run-Worktree.

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

AI6-015 erweitert genau diese eine Prozessnaht um serverseitige `control`-, `agent`- und `checker`-Policies. Sie binden je Rolle Timeout, Outputgrenze, erlaubte Executables und Environmentwerte, Arbeitswurzel, Prozessgruppenpflicht und Cancel-Frist. Die ausgelieferte Agent-/Checker-Executable-Allowlist ist absichtlich leer und startet damit geschlossen; erst ein späteres Provider-Adapter-Ticket darf den tatsächlich eingebauten CLI-Pfad freigeben. Approvalwerte dürfen die sechs Servermaxima `AI6_EXECUTION_MAX_RUNTIME_SECONDS`, `AI6_EXECUTION_MAX_OUTPUT_BYTES`, `AI6_EXECUTION_MAX_PROCESSES`, `AI6_EXECUTION_MAX_FILES`, `AI6_EXECUTION_MAX_BYTES` und `AI6_EXECUTION_MAX_ARTIFACTS` nur verkleinern. Eine Überschreitung beendet die Prozessgruppe und liefert ein `ai6.process-limit.v1`-Resultat mit Grenzenkennung, beobachtetem Wert, Maximum und SHA-256-Bindung; Prozessausgabe und partielle Ergebnisbytes werden dabei nicht freigegeben.

Worker und Ausführungsrolle tauschen Auftrag und Ergebnis ausschließlich über ihre getrennte `ExecutionMailbox` aus. Jedes atomar publizierte Envelope bindet Version, Rolle, Slot, Zustellkennung, Inhaltgröße und SHA-256. Größen-, Hash-, Rollen- und Slotprüfung erfolgen vollständig vor der einmaligen Zustellung; unvollständige, manipulierte, fremde, zu große oder wiederholte Envelopes enden als benannte Ablehnung. Der Browserpfad besitzt keinen Prozessstart und keinen Mount auf diese Mailboxes.

Für jeden Slot beziehungsweise jede Session entsteht ein wegwerfbares Execution-Home aus getrennter Eingabe- und Ausgabewurzel. Die Eingabe enthält nur eine Kopie des durch `IsolatedTreeExporter` bereitgestellten Trees, die unveränderten Bytes des gebundenen `InstructionSnapshot` an ihren nativen Discoverypfaden und in einem read-only Overlay, die hashgebundene `ProviderRuntimeProfile`-Konfiguration sowie eine minimale read-only Authprojektion genau eines Providerprofils. Die Ausgabewurzel enthält nur Ergebnis-, Artefakt- und Patchkanäle. Vor jedem Nicht-Control-Spawn verifiziert `ProcessIsolationVerifier` die echte Rollenkennung, die getrennten Mounts samt Optionen, die read-only Containerwurzel, die Hashbindungen, die verbotenen Discovery-/Gitpfade und für den Checker das ausschließlich aus Loopback bestehende Netzwerk. Die aktuelle Credentialrevision kommt aus der vertrauenswürdigen Serverkonfiguration und wird gegen die Projektion geprüft; der Aufrufer kann sie nicht selbst als aktuell deklarieren. Bei einer fehlenden oder falschen Grenze startet kein Prozess. Providerhomes, Cache, History, fremde Profile, Gitmetadaten, Datenbankzugang und produktiver `APP_KEY` werden nicht projiziert. Nach Prozessende werden beide Wurzeln zerstört. Ein Instruction-Update läuft vor JSON-Verarbeitung durch die zentrale UTF-8-/Redaction-Grenze, ist größenbegrenzt und schreibt ausschließlich einen strukturierten Vorschlag für genau einen bereits im `initial_scope` gebundenen Discoverypfad; der native Snapshot bleibt während des Laufs unverändert und nur die aus vertrauenswürdiger Konfiguration ermittelte Workerrolle darf den Vorschlag nach Ende lesen.

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

Das geschlossene Schema erlaubt nur `version`, `tickets_path`, `ticket_validation_profile`, `push_mode`, `auto_start_next`, `dependency_satisfied_statuses`, `defaults`, `limits`, `scope` und `checks`. Checknamen sind reine Referenzen auf eine serverseitige Allowlist; Modellprofil und Effort referenzieren gemeinsam eine für die jeweilige Rolle zulässige Kombination aus dem zentralen Agentenprofilregister. Auch erfüllende Abhängigkeitsstatus werden gegen eine vertrauenswürdige serverseitige Allowlist geprüft: Ausgeliefert sind ausschließlich `review` und `done`, während `done` der Serverdefault bleibt. Damit können blockierte oder verworfene Tickets nie allein durch Projektinhalt als erfüllte Abhängigkeit gelten; jede instanzweite Verbreiterung ist eine bewusste Änderung der vertrauenswürdigen Serverkonfiguration. Shellstrings, Security-, Credential-, Remote-, Control-Branch- und Managed-Path-Schlüssel sind strukturell ausgeschlossen. Limits müssen positive Ganzzahlen unter den unveränderlichen Servermaxima sein; Scope-Globs bleiben reine Policywerte.

Die konservativen Serverdefaults setzen derzeit `auto_start_next=false` und genau den Reviewer `grok-cli-review`. Das weicht sichtbar von den in Plan §19 festgelegten Produktdefaults „Auto-Start aktiv“ und zwei unabhängigen Reviewer-Slots ab. Diese Dokumentation ist keine Planrevision und keine Freigabe der Abweichung: Die Umstellung auf automatischen Start und den zusätzlichen externen `copilot-cli-review`-Slot bleibt bis zu einer ausdrücklichen menschlichen Betriebsentscheidung offen.

Ein Projekt-`approver` sieht den semantischen Diff und kann einen gültigen Entwurf nach frischem Step-up genau einmal freigeben. Der Compare-and-Swap bindet Projekt, Entwurf, Control-Commit, Blob-SHA, kanonischen Config-Hash, Validierungsbefund und `control_generation`. Snapshots sind unveränderlich und historisch weiter auflösbar. Eine neue vorhandene Repositoryfassung wirkt ohne neue Freigabe nicht. Ein `absent`-Befund am aktuellen Control-Commit setzt die effektive Auflösung dagegen bewusst auf `server_defaults` mit `blob_sha=null` und demselben domain-separierten Hashvertrag zurück, auch wenn in derselben Generation bereits ein Snapshot freigegeben wurde. Diese menschlich entschiedene Auslegung folgt AC-04 und der normativen Planvorgabe „Fehlende Datei verwendet Serverdefaults“; die frühere gegenteilige Formulierung im Review-Fokus von AI6-010 ist im Ticket korrigiert.

Der zentrale `Redactor` bleibt vor dem Parser sowohl UTF-8- als auch Secret-Ausgabegrenze. Ein Treffer im vollständigen Rohinhalt sperrt den Entwurf weiterhin fail closed; Kommentare und andere YAML-Bytes werden nicht als strukturell wirkungslos von dieser Sicherheitsprüfung ausgenommen. Diese in der Umsetzung beibehaltene Sicherheitsentscheidung ist strenger als AC-01/TC-02, die nur strukturelle Validitätsentscheidungen verlangen, und verhindert, dass erkannte schützenswerte Werte über Entwurf oder Fehlerpfade weiterverarbeitet werden.

Produktive Ticket-Refreshes, Editor-/Approval-Entscheidungen und Ticketprojektionen verwenden ausschließlich diese effektive Bindung. Jede Projektion schreibt Validierungsprofil und effektiven Config-Hash mit. Abweichende Generation, Control-OID, Profil- oder Hashbindung ist ein reines Lesezeit-Stalenessprädikat und sperrt die Verwendung bis zur Reprojektion:

```bash
AI6_RUNTIME_ROLE=worker php artisan ai6:tickets:reproject-unparsed --project-config
```

Auch der historische Unparsed-Backfill ohne `--project-config` verwendet `tickets_path`, Validierungsprofil und Config-Hash ausschließlich aus der effektiven Projektbindung. Separate Instanzwerte für Refresh-Basispfad oder Ticketvalidierungsprofil existieren nicht mehr.

## Agentenprofile, Promptkatalog und Instruktionsgrenzen

`config/ai6.php` enthält als einzige vertrauenswürdige Quelle die Profile `codex-gpt-5.6-terra`, `grok-cli-review`, `copilot-cli-review` und `fake`. Jedes Profil bindet genau einen Provideralias (`codex_cli`, `grok_cli`, `github_copilot_cli` oder `fake`), den Adapter, erlaubte Modelle, Efforts und Rollen sowie ein versiegeltes Runtimeprofil. Eine Auswahl wird serverseitig als vollständige Kombination geprüft. Die Zustände `unchecked` und `unavailable` bleiben sichtbar und sind nicht auswählbar; nur `available` darf aufgelöst werden. Das credential-, netzwerk- und prozessfreie Profil `fake` ist für alle vier Rollen verfügbar. Die authentifizierte Seite `/agents/profiles` zeigt den Vertrag ausschließlich lesend.

Alle Runtimeprofile beginnen mit deaktivierten MCP-Servern, Plugins, Skills, Hooks, Commands, Agentdefinitionen und externen Helpern. Eine Erweiterung kann ausschließlich durch eine Änderung der vertrauenswürdigen Serverkonfiguration in die abschließende Liste gelangen. Version, effektive Adapterflags, Permissions und Erweiterungslisten werden über die Domäne `AI6-PROVIDER-RUNTIME-PROFILE-V1` und die zentrale kanonische JSON-Naht mit SHA-256 gebunden.

Der zentrale Promptkatalog besitzt die Katalogversion `1` und genau einen `PromptRenderer`. Seine sechs Zwecke sind `implementation`, `quality_review`, `fix`, `finding_verification`, `security_review` und `human_response`. Die spezialisierten Reviewprofile decken Ticket-/AC-Treue, funktionale Korrektheit, Security, Datenbank/Migrationen, Concurrency, Performance, Tests, Architektur und API-Verträge ab. Katalogeinträge und Reviewprofile tragen eigene Versionen. Ein Prompt-Snapshot enthält Katalogversion, ausgewählte Reviewprofile und gerenderte Promptbytes; sein SHA-256 verwendet die Domäne `AI6-PROMPT-SNAPSHOT-V1` und kanonisches JSON. Providerindividuelle Templates oder ein zweiter Renderer existieren nicht.

Native Instruktionskandidaten werden als bereits typisierte Ergebnisliste übergeben; der Resolver liest weder Git noch Dateisystem oder Prozesse. Ausschließlich serverseitig konfigurierte Discoverynamen, Rangfolge und Geltungsbereiche sind zulässig. Host-/Parentquellen, fehlende Dateien, Symlinks, absolute oder traversierende Pfade, unbekannte Discoverynamen, ungültiges UTF-8, kanonische Duplikate und Importzyklen schließen ohne Teilsnapshot. Effektiver Inhalt passiert vor Importauswertung und Hashbildung den zentralen `Redactor`; `AI6-INSTRUCTION-SNAPSHOT-V1` bindet Providerprofil, Reihenfolge, Geltungsbereich, Pfad, Blob-SHA, Imports, den SHA-256 des effektiven Inhalts je Eintrag und die tatsächlich verwendeten redigierten Bytes.

Die unveränderlichen Servermaxima lauten:

| Grenze | Maximum |
|---|---:|
| Instruktionsdateien | `16` |
| Bytes je Instruktionsdatei | `262144` |
| Instruktionsbytes gesamt | `1048576` |
| Instruktions-Importtiefe | `8` |
| finaler Promptinput | `2097152` Bytes |

Exakt das jeweilige Maximum wird akzeptiert; eins darüber endet mit einem typisierten, wertfreien Fehler ohne Snapshot oder Prompt. Instruktions-Rohbytes werden vor der Redaction gegen Einzel- und Gesamtgrenze geprüft. Beim Prompt gelten dieselbe Grenze sowohl für die Summe der rohen Variablenwerte vor der Redaction als auch für den vollständig assemblierten finalen Prompt. Repository-, Provider- und Browsereingaben können diese Werte nicht erhöhen.

## Agentenergebnisse und FakeAgent

Providerantworten werden ausschließlich über `RestrictedJsonDecoder` verarbeitet. Die Naht führt die zentrale UTF-8-/Redaction-Grenze vor dem Parsing aus, übernimmt das bereits konfigurierte Outputlimit des Agent-Prozessprofils und lehnt ungültiges JSON, Duplikatschlüssel sowie übermäßige Verschachtelung oder Elementanzahl mit einem typisierten, wertfreien Fehler ab.

`ai6.agent.v1` gilt für Implementierungsergebnisse, `ai6.quality-review.v1` für Qualitäts-, Verifikations- und Sicherheitsreviews. Die zulässigen Felder werden je Schemaversion bestimmt: nur ein Implementierungsergebnis darf `instruction_patch` tragen, nur ein Reviewergebnis `findings` und `criterion_coverage`. Jedes Ergebnis bindet `prompt_snapshot_hash`, `instruction_snapshot_hash` und `provider_runtime_profile_hash` des Turns. Ergebnisse mit unbekannten Feldern, einer unzulässigen Rolle/Status-Kombination, unvollständiger AC-Abdeckung, falscher Bindung oder einem `no_change_required` bei nicht leerem tatsächlichem Diff werden vor jeder Wirkung abgelehnt.

`criterion_coverage` ist eine Liste aus `criterion_id`, `status` und `evidence` und stuft jedes Akzeptanzkriterium des Turns genau einmal ein; ein Finding trägt `local_id`, `severity`, `disposition`, `category`, `file`, `line`, `title`, `evidence`, `expected_result` und `criterion_refs`. Ein strukturierter Human-Request bleibt untrusted Evidenz mit Optionen aus `key` und `label` und einer `recommended_option`, die genau einen dieser Schlüssel benennt; Rolle, Step-up, Empfängeradresse und tatsächliche Wirkung sind keine Providerfelder.

Das optionale `ai6.instruction-patch.v1` ist nur im gebundenen Modus `instruction_update` zulässig. Es erlaubt exakt einen initial freigegebenen kanonischen Pfad, verlangt das Format `utf8_file_replacement_v1`, bindet den erwarteten Blob oder die nachgewiesene Abwesenheit und verlangt kanonisches Base64, UTF-8 ohne BOM, Länge, SHA-256 sowie die Einhaltung der Instruktions-Einzel- und Gesamtbytegrenzen. Ein Ziel ist ausschließlich ein gebundener Snapshotpfad oder ein serverseitig als neu freigegebener Pfad; die Scope-Freigabe allein genügt nicht. Erst ein vollständig gültiger Vertrag erreicht den bestehenden workergebundenen `InstructionPatchChannel`; ein Fehler schreibt kein Byte.

`FakeAgentAdapter` implementiert denselben `AgentAdapter`-Vertrag wie spätere reale Provideradapter. `result()` erzeugt ohne Zeitabhängigkeit oder Netzwerkzugriff byteidentisch die acht Szenarien Erfolg, No-change mit leerem Diff, No-change mit unzulässig nicht leerem Diff, Frage, Findings, ungültiges JSON, Providerfehler und Securityfindings; der Implementierungsturn `turn()` führt dasselbe Dokument über `ControlProcessRunner` und die Agent-Prozesspolicy in der exportierten Tree-Sicht aus. Die technische Nichterreichbarkeit von `.git`, Managed-Worktree und Credentials stützt sich dort auf PHP-`open_basedir` und ist bis zu den realen Adaptern (`AI6-033`, `AI6-034`) kein Nachweis für ein Provider-CLI. Das Szenario ist Zustand des Fakes und kein Feld des Turnkontexts. Seine Bytes durchlaufen unverändert denselben `AgentResultValidator` und sind als Golden-Vektoren bytegenau gebunden. Ein Implementierungsergebnis enthält zusätzlich die geschlossenen Felder `decisions`, `changed_paths`, `open_manual_gates` und `implementation_summary`.

## Implementierungsturn, Diff-Import und Runartefakte

Der vorbereitete Schritt `implement` besitzt einen registrierten Handler auf dem bestehenden idempotenten Jobweg. Der Worker erzeugt je Run genau einen Implementierungsslot in `run_agents` aus dem freigegebenen Approval-Snapshot. Die Providersitzung entsteht je Ticket neu und wird nur innerhalb desselben Runs nach einer autorisierten Antwort fortgesetzt.

Der Prompt stammt ausschließlich aus dem im Run gebundenen Prompt-Snapshot. Der Providerturn läuft in einer exportierten, gitmetadatenfreien Tree-Sicht; `.git`, Managed-Worktree und Credentials sind aus dem Agentenprozess nicht erreichbar. Die Pfadcontainment-Zusage des FakeAgent-Turns beruht auf `TurnContainmentBoundary` plus PHP-`open_basedir` und gilt nur für diesen PHP-Kindprozess. Reale Provider-CLIs in `AI6-033`/`AI6-034` müssen die Isolationszusage erneut an der Agent-Prozessgrenze nachweisen. Vor Start und Resume prüft der Worker Instruktionsblob, Reihenfolge, Geltungsbereich, effektiven Hash und Runtime-Profilhash gegen die Runbindung. Eine Abweichung stoppt ohne Providerturn, verwirft Sitzungen und verlangt eine neue Approval- und Run-Linie.

Der Ergebnisimport ist zweistufig: zuerst die zentrale JSON- und Schemagrenze, danach berechnet ausschließlich der Worker den tatsächlichen Diff und vergleicht gemeldete mit tatsächlichen Pfaden. Unbekannte Binärdateien ohne erlaubte Herkunft, Symlinks und übergroße Einzeländerungen blockieren atomar ohne Teilstand. Die freigegebenen Limits `max_changed_files`, `max_changed_bytes`, `max_artifacts`, `max_artifact_bytes`, `max_total_artifact_bytes` und `max_provider_output_bytes` werden vor jeder Wirkung geprüft und bleiben unter den unveränderlichen Servermaxima. Eine Überschreitung importiert nichts, setzt `wait_reason=resource_limit` und erzeugt über `HumanRequestService` höchstens einen offenen gebundenen Request.

Strukturierte Implementierungszusammenfassung und Providerrohstand liegen ausschließlich in `run_artifacts`. Das vertrauenswürdige Wurzelverzeichnis ist `AI6_RUN_ARTIFACT_ROOT` (Default `storage/app/ai6/run-artifacts`) und ist durch Projekt- oder Providerinhalt nicht veränderbar. Die Run-Timeline zeigt redigiert geänderte Dateien, Änderungsart, Entscheidungen und Sitzungszustand und startet keinen Prozess.

## Wirksamer Scope, Quarantäne und Vertragsänderungen

Jeder Run führt seinen wirksamen Scope als gebundenen Zustand: `initial_scope` aus dem freigegebenen Ticket, `effective_scope` aus initialem Scope zuzüglich genehmigter exakter Pfade und `actual_changed_paths` aus dem tatsächlich importierten Diff; alle drei sind gehasht an den Run gebunden, und jede Änderung läuft über den `RunOrchestrator`. Die eine deterministische Scope-Policy (`ScopeCategorizer`) entscheidet ausschließlich über die freigegebene Projektkonfiguration und kennt die drei Spuren aus Plan §8.2: Ein Pfad unter `scope.auto_allow` wird ohne Rückfrage aufgenommen; Instruktionsdateien, Ticketdateien, Migrationen, Abhängigkeitsdateien, CI-, Deploy- und Authpfade, `scope.require_approval` sowie jede Löschung verlangen immer eine menschliche Entscheidung; jeder übrige Pfad folgt der vertrauenswürdigen Projektvorgabe `scope.unlisted_paths` mit den Werten `auto_allow` (Vorgabe) und `require_approval`. Ticketinhalt, Providertext oder Dateiname können keine Kategorie, kein Limit und keine Vorgabe umdeuten, und ein unbekannter Wert von `scope.unlisted_paths` wird beim Parsen abgewiesen. Was als Instruktionspfad gilt, entscheidet die eine `InstructionPathPolicy` aus den tatsächlich verwendeten Discoveryformen der Providerprofile: Die Form `agents_md_nested` macht `AGENTS.md` und `CLAUDE.md` in jeder Verzeichnistiefe zur Instruktionsdatei, und ein solcher Pfad wird benannt abgewiesen, statt jemals in den wirksamen Scope eines laufenden Runs zu gelangen. Jede Entscheidung trägt einen serverseitig persistierten, benannten Grund aus dem geschlossenen Wertesatz `auto_allow`, `unlisted_auto_allow`, `human_approved`, `human_rejected` und `amendment`; die Run-Timeline zeigt genau diesen Grund, statt ihn aus dem Vorhandensein eines gebundenen Requests abzuleiten. Jede Aufnahme zählt gegen denselben idempotenten Zähler `added_scope_paths_count`; er bindet Auto-Allow, Retry, Teilgenehmigung und Contract Amendment an dasselbe freigegebene `max_added_scope_paths`. Eine Überschreitung nimmt keinen Pfad auf und setzt `resource_limit`; ein Amendment, das sein Limit überschreiten würde, wird bereits beim Einreihen benannt abgewiesen. Weil ein Pfad erst im laufenden Turn in den wirksamen Scope kommt, laufen die freigegebenen Änderungslimits nach der Neuaufteilung erneut über die dann tatsächlich importierte Änderungsmenge.

Ein außerhalb des wirksamen Scopes geänderter Pfad erreicht den Run-Worktree nicht: Er wird vor der Entscheidung als redigiertes `quarantined_path`-Artefakt bewahrt, und je Run entsteht höchstens ein offener `scope_approval`-Request über die bestehende HumanLoop-Naht. Eine Genehmigung erweitert den gebundenen wirksamen Scope, sodass der Pfad im nächsten Turn durch dieselbe Importnaht läuft; eine Ablehnung verwirft ausschließlich die AI6-eigene Änderung, während fremder Repositoryinhalt, Auditspur und Artefakt unverändert bleiben.

Eine freigegebene Vertragsänderung läuft als eigener Operationstyp `contract_amendment` durch dieselbe Ticketmutations-Saga. Sie wird nur für einen Run eingereiht, dessen Schritte geparkt sind — ein laufender Implementierungsschritt weist sie benannt ab, damit ein bestätigtes Amendment nicht mitten im Turn Version, Scope und Prompt verschiebt; weil ein geplanter Schritt nach dieser Prüfung noch geclaimt werden kann, wiederholt der Worker sie unter der Operationssperre vor jedem Außeneffekt. Ein Pfad, den derselbe Run bereits abgelehnt hat, kehrt nicht per Vertragsänderung in den Scope zurück: Die Entscheidungszeile ist unveränderlich, und beide Prüfungen weisen den Re-Add als `amendment_readds_rejected_path` ab: Der Worker schreibt den autorisierten Ticketpatch mit dem persistierten `run_base_sha` als erwartetem Control-OID per Compare-and-Swap auf den Control-Branch, behält den Ticketstatus `in_progress` bei und registriert den bestätigten Commit ausschließlich als neues `run_base_sha`; `initial_run_base_sha` und `approved_control_sha` bleiben unverändert. Vor und nach der Übernahme weist `TicketFileDeltaProof` über den realen Objektbestand nach, dass ausschließlich die Ticketdatei vom Parent abweicht; anschließend entstehen neue Bindungen für Ticketblob, Contract-Hash, Scope und Prompt am Run, während Config-, Instruktions-, Runtime-, Agenten- und Securitybindung nur mit neuer Approval wechseln — die Config-Bindung ist bytestabil nachgewiesen, sodass eine Config-Abweichung auch hinter einem bestätigten Amendment `snapshot_binding_changed` bleibt. Jede unerwartete Abweichung von Codebaum, Control-Branch-Head oder Runbasis blockiert mit `git_base_changed` und bewahrt Run und Projektsperre; ein automatisches Rebase oder Merge fremder Änderungen findet nicht statt. Diese Parkung erreicht jeden nicht terminalen Runzustand: Weil ein Amendment üblicherweise für einen bereits hinter `contract_change` wartenden Run eingereiht wird und sein Compare-and-Swap genau dessen allowlisteter Resolver ist, wird ein solcher Run auf `git_base_changed` umgeparkt, statt einen Resolver weiter anzubieten, der nicht mehr gelingen kann; die Umparkung ist idempotent.

Nach jeder Scope- oder Vertragsänderung rückt die `evidence_epoch` des Runs vor: Checkpoints, Reviewresultate, Gate-Evidenzen und Finding-Dispositionen des alten Stands verlieren ihre Wirksamkeit, bleiben aber unverändert lesbar; der Checkpoint bindet dafür seine Erzeugungsepoche. Eine angeforderte Instruktions- oder Runtime-Profiländerung ist kein Ticketpatch: `ContractChangeService` setzt `contract_change`, verwirft Providersitzungen und erlaubt ausschließlich die kontrollierte Rücksetzung `in_progress → todo` über die vorhandene Statusoperation oder den Abbruch; die Änderung wirkt erst über neue Approval, neuen Run und neue Sitzungen. Eine Scope-Entscheidung läuft zusätzlich per Compare-and-Swap gegen die Runversion, an die ihr Request gebunden wurde: Bewegt sich der Run danach — etwa durch ein Amendment —, wird die Antwort als `stale_run_version` abgewiesen und bleibt wirkungslos. Der AI6-eigene Ticketabschnitt `## Recorded Scope` nach `TKT-012` geht nicht in `ticket_contract_sha256` ein; sein Schreiben und Fortschreiben täuscht daher keine Vertragsänderung vor und invalidiert keine Reviewevidenz. Die Wartestatus `scope_approval` und `contract_change` sind je mit genau einem Producer, ihren Resolvern (`contract_change`: gebundener Amendment-CAS für den Tickettext, kontrollierte Rücksetzung auf `todo` für eine Instruktions-/Runtime-Profiländerung) und einem Cancelpfad registriert, und die Run-Timeline zeigt initialen Scope, Entscheidungen, quarantänierte Pfade und den Limitverbrauch redigiert und ausschließlich lesend unter der unveränderten CSP.

## Ticketfreigabe und Approval-Queue

Eine Ticketfreigabe ist die reservierte, asynchrone Control-Operation `ticket_approval`. Nur ein Projektmitglied mit Rolle `approver` kann sie nach frischem, aktionsgebundenem Step-up auslösen. Die allgemeine Statusoberfläche kennt die interne Operation `approve` nicht. Der Worker verwendet den bestehenden Einzeldatei-Mutationspfad und weist vor dem Compare-and-Swap nach, dass `todo` ausschließlich zu `ready` geändert wurde, der Ticketvertrag identisch bleibt und der übrige Tree unverändert ist.

Auch Snapshot-Vorschau und aktuelle Startberechtigungsprüfung bleiben aus dem Browserprozess heraus: Die Seiten persistieren ausschließlich gebundene Arbeitsaufträge in der Datenbank. Erst der Worker liest die native Instruktionsauflösung aus Git, erzeugt beziehungsweise vergleicht die Snapshotbindungen und schreibt ein sicher anzeigbares Ergebnis zurück. Die HTTP-Antwort konsumiert nur diesen persistierten Stand und startet weder Git noch einen Prozess oder Run synchron.

`ticket_approvals` bewahrt die menschlich geprüfte Todo-Bindung (`reviewed_ticket_blob_sha`, `reviewed_control_sha`) getrennt von der erst nach dem bestätigten Push gesetzten Ready-Bindung (`approved_ticket_blob_sha`, `approved_control_sha`). Außerdem bindet der unveränderliche Datensatz Control-Generation, Config, Scope, zentral gerenderte Prompts, native Instruktionsauflösung, versiegelte Runtimeprofile, Agentenprofile, SecurityPolicy, sämtliche wirksamen Limits, Attention-User und Pushmodus. Reviewer-Slots besitzen stabile UUIDs; Profil, Modell, Effort und Review-Promptprofil werden ausschließlich serverseitig aufgelöst, inhaltliche Duplikate werden abgelehnt.

Die Sagaphasen `prepared`, `commit_prepared`, `control_confirmed` und `complete` werden vor beziehungsweise nach jeder äußeren Wirkung persistiert und idempotent abgeglichen. Ein fremder Ready-Commit, falscher Parent, abweichender Tree oder Operation-ID-Replay wird nicht adoptiert. Approval-Staleness entsteht ausschließlich beim Lesen durch abweichende Generation, Ticketblob, Ticketvertrag oder Config-, Scope-, Prompt-, Instruction-, Runtime-, Profil- beziehungsweise Policyhashes.

`queue_state=queued` bedeutet nur, dass die Approval aufgenommen wurde. Die Startberechtigung ist davon getrennt und wird jeweils aktuell aus Staleness, Provider-Capabilities, Abhängigkeiten, unfertigen Runs und Queuezustand abgeleitet. Unerfüllte Abhängigkeiten verhindern damit weder die Freigabe noch `ready` oder die Queueaufnahme; sie liefern lediglich einen sichtbaren Startblocker. AI6-012 legt weder einen Run an noch startet es einen Providerprozess.

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

- [Implementierungsplan V1.7.2](docs/AI6_IMPLEMENTATION_PLAN.md) — normative Quelle für Anforderungen, Architektur, Meilensteine und Ticket-Blueprints.
- [Ticket-Template V1](docs/AI6_TICKET_TEMPLATE_V1.md) — verbindliches Format sowie Erzeugungs- und Umsetzungsvertrag für Detailtickets.
- [Ticketübersicht](tickets/README.md) — Erzeugungsstand und Abhängigkeiten; keine autoritative Statusquelle.
- [Agentenanweisungen](AGENTS.md) — verbindliche Regeln für agentische LLMs in diesem Repository.
