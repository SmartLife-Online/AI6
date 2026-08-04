<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Models\User;

final class AuthenticationDocumentationTest extends AuthFeatureTestCase
{
    public function test_readme_reproduces_the_complete_role_matrix_and_authentication_contract(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 3).'/README.md');
        self::assertIsString($readme);

        foreach ([
            '| Projekt erscheint in der Projektliste | Nein | Ja | Ja | Ja | Ja | Nein |',
            '| Projektdetail ansehen | Nein | Ja | Ja | Ja | Ja | Nein |',
            '| Benutzer anlegen | Ja | Nein | Nein | Nein | Nein | Nein |',
            '| Benutzer deaktivieren | Ja | Nein | Nein | Nein | Nein | Nein |',
            '| Benutzer löschen | Ja | Nein | Nein | Nein | Nein | Nein |',
            '| Globale Administratorrolle vergeben | Ja | Nein | Nein | Nein | Nein | Nein |',
            '| Globale Administratorrolle entziehen | Ja | Nein | Nein | Nein | Nein | Nein |',
            '| Projektmitgliedschaft setzen | Ja | Nein | Nein | Nein | Nein | Nein |',
            '| Projektmitgliedschaft entziehen | Ja | Nein | Nein | Nein | Nein | Nein |',
        ] as $row) {
            self::assertStringContainsString($row, $readme);
        }

        foreach ([
            'ai6:create-admin',
            'letzte aktive globale Administrator',
            'keine öffentliche Registrierung',
            'AI6_AUTH_LOGIN_MAX_ATTEMPTS',
            'AI6_AUTH_LOGIN_DECAY_SECONDS',
            'AI6_AUTH_SESSION_LIFETIME_MINUTES',
            'gezielte Sessionwiderruf',
            'Passkey oder TOTP',
            'globale Administratoren sowie Benutzer mit mindestens einer Projektrolle `admin`, `operator` oder `approver`',
            'Projektrolle `viewer` ist nicht privilegiert',
            'Ein Recovery-Code wird ihnen weder angeboten noch zur Autorisierung angenommen',
            'AI6_LOGIN_CONFIRMATION_EMAIL',
            'ai6:reissue-recovery-codes',
            'public/assets/ai6-passkey.js',
            'lbuchs/webauthn` `v2.2.0',
            'web-auth/webauthn-lib` `v5.3.5',
            'pragmarx/google2fa` `v9.0.0',
            'spomky-labs/otphp` `v11.5.0',
            'Nur `worker` erhält `APP_KEY` zum Entschlüsseln der Queue-Payload',
            'Mailjob verwirft jeden Transport außer `smtp`',
            '`http://localhost:8000`',
            '`http://127.0.0.1` und `http://[::1]`',
        ] as $requiredText) {
            self::assertStringContainsStringIgnoringCase($requiredText, $readme);
        }
    }

    public function test_framework_configuration_uses_the_new_user_model_and_database_sessions(): void
    {
        self::assertSame(User::class, config('auth.providers.users.model'));
        self::assertNull(config('mail.mailers.smtp.url'));
        self::assertNotSame('', config('mail.mailers.smtp.local_domain'));

        $environment = file_get_contents(dirname(__DIR__, 3).'/.env.example');
        self::assertIsString($environment);
        self::assertStringContainsString('SESSION_DRIVER=database', $environment);
        self::assertStringNotContainsString('SESSION_DRIVER=file', $environment);
        self::assertStringContainsString('AI6_AUTH_LOGIN_MAX_ATTEMPTS=5', $environment);
        self::assertStringContainsString('AI6_AUTH_LOGIN_DECAY_SECONDS=60', $environment);
        self::assertStringContainsString('AI6_AUTH_SESSION_LIFETIME_MINUTES=120', $environment);
        self::assertStringContainsString('AI6_AUTH_LOGIN_CONFIRMATION_TTL_SECONDS=600', $environment);
        self::assertStringContainsString('AI6_AUTH_LOGIN_CONFIRMATION_MAX_ATTEMPTS=5', $environment);
        self::assertStringContainsString('AI6_AUTH_STRONG_AUTHENTICATION_MAX_ATTEMPTS=5', $environment);
        self::assertStringContainsString('AI6_AUTH_STRONG_AUTHENTICATION_DECAY_SECONDS=300', $environment);
        self::assertStringContainsString('AI6_AUTH_LOGIN_CONFIRMATION_RESEND_COOLDOWN_SECONDS=30', $environment);
        self::assertStringContainsString('AI6_AUTH_STEP_UP_WINDOW_SECONDS=300', $environment);
        self::assertStringContainsString('AI6_AUTH_ENROLLMENT_TTL_SECONDS=900', $environment);
        $environmentLines = preg_split('/\R/', $environment);
        self::assertIsArray($environmentLines);
        self::assertContains('AI6_LOGIN_CONFIRMATION_EMAIL=', $environmentLines);
        self::assertContains('MAIL_MAILER=smtp', $environmentLines);
        self::assertContains('MAIL_PASSWORD=', $environmentLines);
    }

    public function test_local_windows_start_script_uses_the_supported_webauthn_origin(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/scripts/run-ai6-local.cmd');
        self::assertIsString($script);
        self::assertStringContainsString(
            'php artisan serve --host=localhost --port=8000 --no-interaction',
            $script,
        );
        self::assertStringNotContainsString('--host=127.0.0.1', $script);
    }

    public function test_role_checks_live_only_in_registered_policies(): void
    {
        foreach ([
            dirname(__DIR__, 3).'/app/AI6/Auth/Http',
            dirname(__DIR__, 3).'/app/AI6/Projects/Http',
            dirname(__DIR__, 3).'/routes/web.php',
        ] as $path) {
            $files = is_dir($path) ? glob($path.'/*.php') : [$path];
            self::assertIsArray($files);

            foreach ($files as $file) {
                $contents = file_get_contents($file);
                self::assertIsString($contents);
                self::assertStringNotContainsString('is_global_admin', $contents, $file);
                self::assertDoesNotMatchRegularExpression(
                    '/ProjectRole::(ADMIN|VIEWER|OPERATOR|APPROVER)/',
                    $contents,
                    $file,
                );
            }
        }
    }
}
