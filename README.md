# AI6

AI6 verwaltet Git-native Softwaretickets, lässt sie von Menschen freigeben und orchestriert anschließend getrennte LLM-Sitzungen für Implementierung und Review. Die Anwendung ist als modularer Laravel-Monolith mit einer Codebasis und klar getrennten Prozessrollen angelegt.

## Technische Basis

- PHP-Vertrag: `^8.5`; Composer löst Abhängigkeiten durch `config.platform.php: 8.5.0` gegen die zugesagte Mindestplattform auf.
- Laravel-Vertrag: `laravel/framework: ^13.8`; der aktuelle Lockstand enthält Laravel `13.23.0`.
- PHPUnit-Vertrag: `phpunit/phpunit: ^12.5.12`; der aktuelle Lockstand enthält PHPUnit `12.5.33`.
- Qualitätswerkzeuge im Lockstand: Pint `1.30.0` und PHPStan `2.2.7`.
- Scaffoldquelle: `laravel/laravel` Tag `v13.8.0` am Commit `e196bfdfc96903f2e10219749fcbca7c0aefe99f`.

Die vollständige Import-Allowlist, die Ausschlüsse und die vor der Übernahme geprüften Schutzdateien stehen maschinenlesbar in [`docs/AI6_SCAFFOLD_PROVENANCE.json`](docs/AI6_SCAFFOLD_PROVENANCE.json). Der Scaffold wurde in einem temporären Verzeichnis außerhalb dieses Repositorys bezogen; es lief dabei kein Composer-Skript und es wurde kein Scaffold rekursiv über den vorhandenen Bestand kopiert.

Der Scaffoldpfad `config/` wurde bewusst nicht übernommen. AI6-001 startete deshalb migrationsfrei mit `SESSION_DRIVER=file`, `CACHE_STORE=file` und `QUEUE_CONNECTION=sync`. AI6-002 führt ausschließlich die benötigte SQLite- und Queue-Konfiguration ein; der aktuelle Laufzeitdefault ist `QUEUE_CONNECTION=database`, während Sessions und Cache dateibasiert bleiben.

## Installation und Start

Voraussetzungen sind PHP 8.5 mit den von Laravel verlangten Erweiterungen sowie Composer. Ein frischer Clone wird ausschließlich aus dem committed `composer.lock` installiert; `composer update` und Node-Werkzeuge sind nicht erforderlich.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Unter Windows PowerShell ersetzt `Copy-Item .env.example .env` den `cp`-Befehl. Die Vorlage setzt bewusst keinen containergebundenen `DB_DATABASE`-Pfad; der direkte Artisan-Start würde deshalb Laravels Default `database/database.sqlite` verwenden. Der Health-Endpunkt startet ohne Datenbankvorbereitung und erzeugt diese Datei nicht. Für Queue oder Scheduler direkt außerhalb von Docker wird stattdessen ausdrücklich eine lokale Datei unter dem bereits ignorierten Laufzeitpfad `storage/app/ai6-local.sqlite` verwendet, damit die ausgeschlossene Scaffolddatei `database/database.sqlite` weiterhin fehlt:

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

Die primäre Laufzeit wird aus demselben Repository und einem einzigen AI6-Image gestartet. Das Image ist an PHP `8.5.5`, SQLite `3.53.4`, das PHP-Basisimage sowie einen datierten Debian-Paketsnapshot gebunden und enthält natives `ext-intl` für dieselbe NFC-Implementierung wie der Prüfhost. Der Reverse Proxy verwendet separat `caddy:2.10.2-alpine` am Manifest-Digest `sha256:4c6e91c6ed0e2fa03efd5b44747b625fec79bc9cd06ac5235a779726618e530d`.

Nach dem Kopieren der Umgebungsvorlage startet der Stack mit einem Befehl:

```bash
docker compose up -d --build
```

Nur Caddy veröffentlicht `127.0.0.1:${AI6_HTTP_PORT:-8080}`. Caddy lauscht innerhalb seines eigenen Netzwerk-Namespace auf `:8080`, damit die Docker-Portweiterleitung und der interne Healthcheck ihn erreichen; eine Bindung an `127.0.0.1` im Container würde beides vom Containerinterface abschneiden. Die ausschließlich lokale Exposition wird deshalb durch die literale Loopbackbindung der Compose-Veröffentlichung erzwungen. Kein AI6-Dienst besitzt einen veröffentlichten Port. `init` läuft einmal als einziger Dienst mit erhöhter Identität, legt die SQLite-Datei an, führt `php artisan migrate --force --no-interaction` aus, korrigiert die Volume-Rechte und endet mit Exitcode 0. Alle dauerhaften Dienste warten auf diesen erfolgreichen Abschluss.

`docker-compose.yml` ist bewusst als JSON geschrieben; JSON ist gültiges Compose-YAML. Die Contract-Tests können die Datei dadurch mit dem vorhandenen `json_decode` lesen, ohne entgegen `AC-11` einen zusätzlichen YAML-Parser in den gebundenen Abhängigkeitssatz aufzunehmen. Änderungen erhalten daher die JSON-Schreibweise.

| Dienst | Prozess und Heartbeat-Produzent | Erlaubte Umgebungsvariablen | Erlaubte Mounts |
|---|---|---|---|
| `caddy` | Separater Reverse Proxy; HTTP-Healthcheck | keine | ausschließlich `deploy/Caddyfile` read-only |
| `init` | Einmaliger Migrationsschritt; kein Heartbeat | `APP_ENV`, `APP_DEBUG`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS` | Datenbank und `storage/` read-write; `/tmp` als `tmpfs` |
| `app` | Apache/PHP; kein Heartbeat, HTTP-Healthcheck | `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | Datenbank und `storage/` read-write, Nachweise read-only; `/tmp` als `tmpfs` |
| `worker` | `queue:work`; `Looping`-Listener schreibt auch im Leerlauf, ausschließlich am Worker-Heartbeatziel | `APP_ENV`, `APP_DEBUG`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_QUEUE_RETRY_AFTER`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `AI6_EXECUTION_DIRECTORY`, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_MAX_AGE`, `AI6_WORKER_TIMEOUT` | Datenbank, `storage/` und Nachweise read-write; eigener Heartbeat und `/tmp` als `tmpfs` |
| `scheduler` | `schedule:work`; der Zehn-Sekunden-Task schreibt den Heartbeat und verwendet einen stabilen Selbsttestschlüssel je Scheduler-Boot-ID | `APP_ENV`, `APP_DEBUG`, `CACHE_STORE`, `DB_CONNECTION`, `DB_DATABASE`, `DB_FOREIGN_KEYS`, `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_QUEUE_RETRY_AFTER`, `LOG_CHANNEL`, `QUEUE_CONNECTION`, `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_MAX_AGE` | Datenbank und `storage/` read-write; eigener Heartbeat und `/tmp` als `tmpfs` |
| `agent` | Feste Leerlaufschleife schreibt ausschließlich den Agent-Heartbeat | `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_INTERVAL`, `AI6_HEARTBEAT_MAX_AGE` | ausschließlich eigener Heartbeat und `/tmp` als `tmpfs` |
| `checker` | Feste Leerlaufschleife schreibt ausschließlich den Checker-Heartbeat | `AI6_HEARTBEAT_DIRECTORY`, `AI6_HEARTBEAT_INTERVAL`, `AI6_HEARTBEAT_MAX_AGE` | ausschließlich eigener Heartbeat und `/tmp` als `tmpfs` |

Datenbank, `storage/` und Ausführungsnachweise sind persistente benannte Volumes. Der Scheduler erzeugt trotz seines Zehn-Sekunden-Intervalls höchstens einen neuen Nachweis je Container-Boot-ID; wiederholte Jobs desselben Boots treffen denselben persistenten Genau-einmal-Nachweis. Jeder Rollen-Heartbeat liegt dagegen auf einem nur für diesen Container vorhandenen `tmpfs` und ist an eine beim Containerstart erzeugte Boot-ID gebunden. Die Worker-Heartbeatfrist muss beim Start strikt größer als der Worker-Timeout sein, damit ein zulässig langer Job den Worker nicht allein wegen seiner Laufzeit ungesund macht. `agent`, `checker` und Caddy sehen weder Datenbank noch `storage/` oder Nachweisvolume; `agent` und `checker` erhalten außerdem keinen produktiven `APP_KEY`. Der eingebaute Anwendungscode ist in allen sechs AI6-Diensten read-only. Tests, Tickets, normative Dokumente, Entwicklungswerkzeuge und Qualitätskonfigurationen werden durch `.dockerignore` aus dem Produktionsimage ausgeschlossen.

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

- [Implementierungsplan V1.6.21](docs/AI6_IMPLEMENTATION_PLAN.md) — normative Quelle für Anforderungen, Architektur, Meilensteine und Ticket-Blueprints.
- [Ticket-Template V1](docs/AI6_TICKET_TEMPLATE_V1.md) — verbindliches Format sowie Erzeugungs- und Umsetzungsvertrag für Detailtickets.
- [Ticketübersicht](tickets/README.md) — Erzeugungsstand und Abhängigkeiten; keine autoritative Statusquelle.
- [Agentenanweisungen](AGENTS.md) — verbindliche Regeln für agentische LLMs in diesem Repository.
