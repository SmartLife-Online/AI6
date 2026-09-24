<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Auth\Models\User;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Redaction\RedactionKeyringFactory;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'ai6:install';

    protected $description = 'Prüft die notwendigen Schritte einer AI6-Installation.';

    public function __construct(
        private readonly SecurityPolicy $policy,
        private readonly RedactionKeyringFactory $keyringFactory,
        private readonly DatabaseManager $database,
        private readonly Migrator $migrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $passed = true;
        $passed = $this->step(
            'APP_KEY',
            $this->hasApplicationKey(),
            'APP_KEY setzen, zum Beispiel mit `openssl rand -base64 32`.',
        );

        [$databasePassed, $databaseNext] = $this->databaseStatus();
        $passed = $this->step('Datenbank und Migrationen', $databasePassed, $databaseNext) && $passed;

        $administratorExists = $this->administratorExists();
        $passed = $this->step(
            'Erster Administrator',
            $administratorExists,
            'docker compose exec app php artisan ai6:create-admin admin@example.com --name="AI6 Administrator"',
        ) && $passed;

        $confirmationRequired = $this->policy->isEnabled(SecurityMeasure::LOGIN_EMAIL_CONFIRMATION);
        $confirmationConfigured = ! $confirmationRequired
            || (is_string(config('ai6.auth.login_confirmation_email'))
                && trim((string) config('ai6.auth.login_confirmation_email')) !== '');
        $passed = $this->step(
            'Login-Bestätigungsadresse',
            $confirmationConfigured,
            'AI6_LOGIN_CONFIRMATION_EMAIL in `.env` setzen.',
            $confirmationRequired ? null : 'nicht erforderlich (Maßnahme deaktiviert)',
        ) && $passed;

        $this->printInformationalState();

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function hasApplicationKey(): bool
    {
        $key = config('app.key');

        return is_string($key) && trim($key) !== '';
    }

    /** @return array{bool, string} */
    private function databaseStatus(): array
    {
        try {
            $this->database->connection()->getPdo();
            if (! $this->migrator->repositoryExists()) {
                return [false, 'docker compose up -d init'];
            }

            $files = $this->migrator->getMigrationFiles(database_path('migrations'));
            $ran = $this->migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($files), $ran);

            return [$pending === [], $pending === [] ? 'keine weiteren Migrationen erforderlich' : 'docker compose up -d init'];
        } catch (Throwable) {
            return [false, 'Datenbank prüfen und anschließend `docker compose up -d init` ausführen.'];
        }
    }

    private function administratorExists(): bool
    {
        try {
            return User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function step(string $label, bool $passed, string $next, ?string $note = null): bool
    {
        $this->line($label.': '.($passed ? 'OK' : 'FEHLT'));
        $this->line('  Nächster Schritt: '.$next);
        if ($note !== null) {
            $this->line('  Hinweis: '.$note);
        }

        return $passed;
    }

    private function printInformationalState(): void
    {
        try {
            $keyring = $this->keyringFactory->fromConfiguredValues();
            $this->line('Schlüsselring: OK');
            $this->line('  Key-ID: '.$keyring->activeKeyId());
            $this->line($keyring->usesApplicationKeyFallback()
                ? '  Schlüsselquelle: APP_KEY-Fallback (nur lokal; nicht rotationsstabil)'
                : '  Schlüsselquelle: expliziter Schlüsselring');
        } catch (ConfigurationException) {
            $this->line('Schlüsselring: nicht aufgelöst (Bootstrap-Schritt vor ai6:install prüfen)');
        }

        $this->line('Sicherheitsprofil: '.$this->policy->profile->value);
        $this->line('Hinweis: docker compose exec agent php artisan ai6:provider login <alias>');
        $this->line('Hinweis: docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict');
    }
}
