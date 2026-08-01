# AI6

AI6 verwaltet Git-native Softwaretickets, lässt sie von Menschen freigeben und orchestriert anschließend getrennte LLM-Sitzungen für Implementierung und Review. Die Anwendung ist als modularer Laravel-Monolith mit einer Codebasis und klar getrennten Prozessrollen angelegt.

## Technische Basis

- PHP-Vertrag: `^8.5`; Composer löst Abhängigkeiten durch `config.platform.php: 8.5.0` gegen die zugesagte Mindestplattform auf.
- Laravel-Vertrag: `laravel/framework: ^13.8`; der aktuelle Lockstand enthält Laravel `13.23.0`.
- PHPUnit-Vertrag: `phpunit/phpunit: ^12.5.12`; der aktuelle Lockstand enthält PHPUnit `12.5.33`.
- Qualitätswerkzeuge im Lockstand: Pint `1.30.0` und PHPStan `2.2.7`.
- Scaffoldquelle: `laravel/laravel` Tag `v13.8.0` am Commit `e196bfdfc96903f2e10219749fcbca7c0aefe99f`.

Die vollständige Import-Allowlist, die Ausschlüsse und die vor der Übernahme geprüften Schutzdateien stehen maschinenlesbar in [`docs/AI6_SCAFFOLD_PROVENANCE.json`](docs/AI6_SCAFFOLD_PROVENANCE.json). Der Scaffold wurde in einem temporären Verzeichnis außerhalb dieses Repositorys bezogen; es lief dabei kein Composer-Skript und es wurde kein Scaffold rekursiv über den vorhandenen Bestand kopiert.

Der Scaffoldpfad `config/` wurde bewusst nicht übernommen. Beim Bootstrap gelten die Konfigurationsdefaults des Laravel-Frameworks; die migrationsfreien AI6-Defaults `SESSION_DRIVER=file`, `CACHE_STORE=file` und `QUEUE_CONNECTION=sync` stammen ausschließlich aus `.env.example`. Spätere Tickets führen nur die tatsächlich benötigten Konfigurationsdateien ein.

## Installation und Start

Voraussetzungen sind PHP 8.5 mit den von Laravel verlangten Erweiterungen sowie Composer. Ein frischer Clone wird ausschließlich aus dem committed `composer.lock` installiert; `composer update`, Migrationen und Node-Werkzeuge sind nicht erforderlich.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Unter Windows PowerShell ersetzt `Copy-Item .env.example .env` den `cp`-Befehl. Die lokalen Defaults verwenden `SESSION_DRIVER=file`, `CACHE_STORE=file` und `QUEUE_CONNECTION=sync`; deshalb ist für Bootstrap und Health-Endpunkt keine Datenbankeinrichtung nötig.

Der Health-Endpunkt lässt sich nach dem Start prüfen:

```bash
curl -i http://127.0.0.1:8000/health
```

Die Antwort hat Status 200 und exakt den JSON-Body `{"status":"ok"}`.

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
