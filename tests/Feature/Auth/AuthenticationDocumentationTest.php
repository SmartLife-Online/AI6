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
            'Passkeys, TOTP',
        ] as $requiredText) {
            self::assertStringContainsStringIgnoringCase($requiredText, $readme);
        }
    }

    public function test_framework_configuration_uses_the_new_user_model_and_database_sessions(): void
    {
        self::assertSame(User::class, config('auth.providers.users.model'));

        $environment = file_get_contents(dirname(__DIR__, 3).'/.env.example');
        self::assertIsString($environment);
        self::assertStringContainsString('SESSION_DRIVER=database', $environment);
        self::assertStringNotContainsString('SESSION_DRIVER=file', $environment);
        self::assertStringContainsString('AI6_AUTH_LOGIN_MAX_ATTEMPTS=5', $environment);
        self::assertStringContainsString('AI6_AUTH_LOGIN_DECAY_SECONDS=60', $environment);
        self::assertStringContainsString('AI6_AUTH_SESSION_LIFETIME_MINUTES=120', $environment);
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
