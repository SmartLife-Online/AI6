<?php

namespace App\AI6\Auth\Console;

use App\AI6\Auth\EmailNormalizer;
use App\AI6\Auth\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CreateAdministratorCommand extends Command
{
    protected $signature = 'ai6:create-admin
        {email : E-Mail-Adresse des ersten Administrators}
        {--name= : Anzeigename; wird andernfalls abgefragt}
        {--password-env=AI6_CREATE_ADMIN_PASSWORD : Name der Umgebungsvariablen mit dem Passwort}';

    protected $description = 'Legt den ersten globalen Administrator ohne öffentliche Registrierung an.';

    public function handle(EmailNormalizer $normalizer): int
    {
        $emailInput = $this->argument('email');

        if (! is_string($emailInput)) {
            $this->error('Die E-Mail-Adresse fehlt.');

            return self::FAILURE;
        }

        $email = $normalizer->normalize($emailInput);

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('Die E-Mail-Adresse ist ungültig.');

            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->error('Der Administrator-Bootstrap ist bereits abgeschlossen.');

            return self::FAILURE;
        }

        $nameOption = $this->option('name');
        $name = is_string($nameOption) && trim($nameOption) !== ''
            ? trim($nameOption)
            : trim((string) $this->ask('Anzeigename'));

        if ($name === '' || Str::length($name) > 255) {
            $this->error('Der Anzeigename ist ungültig.');

            return self::FAILURE;
        }

        $passwordEnvironmentOption = $this->option('password-env');
        $passwordEnvironment = is_string($passwordEnvironmentOption) ? $passwordEnvironmentOption : '';

        $allowedEnvironmentCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
        $hasValidEnvironmentName = $passwordEnvironment !== ''
            && str_contains('ABCDEFGHIJKLMNOPQRSTUVWXYZ', $passwordEnvironment[0])
            && strspn($passwordEnvironment, $allowedEnvironmentCharacters) === strlen($passwordEnvironment);

        if (! $hasValidEnvironmentName) {
            $this->error('Der Name der Passwort-Umgebungsvariablen ist ungültig.');

            return self::FAILURE;
        }

        $configuredPassword = getenv($passwordEnvironment);
        $password = is_string($configuredPassword) && $configuredPassword !== ''
            ? $configuredPassword
            : $this->secret('Passwort');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Das Passwort muss mindestens 12 Zeichen lang sein.');

            return self::FAILURE;
        }

        try {
            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
                'is_global_admin' => true,
            ]);
        } catch (QueryException) {
            $this->error('Der Administrator konnte nicht angelegt werden.');

            return self::FAILURE;
        } finally {
            unset($password, $configuredPassword);
        }

        $this->info('Administrator angelegt.');

        return self::SUCCESS;
    }
}
