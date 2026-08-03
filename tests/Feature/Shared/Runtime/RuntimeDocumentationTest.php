<?php

namespace Tests\Feature\Shared\Runtime;

use PHPUnit\Framework\TestCase;

final class RuntimeDocumentationTest extends TestCase
{
    public function test_readme_documents_start_roles_allowlists_versions_and_commands(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 4).'/README.md');
        self::assertNotFalse($readme);

        foreach ([
            'docker compose up -d --build',
            '`caddy`',
            '`init`',
            '`app`',
            '`worker`',
            '`scheduler`',
            '`agent`',
            '`checker`',
            '127.0.0.1:${AI6_HTTP_PORT:-8080}',
            'caddy:2.10.2-alpine',
            'sha256:4c6e91c6ed0e2fa03efd5b44747b625fec79bc9cd06ac5235a779726618e530d',
            'php artisan migrate --force --no-interaction',
            'AI6_RUN_COMPOSE_SMOKE=1',
            'ai6:runtime-selftest',
            'ai6:runtime-health',
            'APP_KEY',
            'AI6_HEARTBEAT_DIRECTORY',
            'AI6_RUNTIME_ROLE',
            'SecurityPolicy-Variablen',
            'Redaction-Keyring-Variablen',
            'Ausführungsnachweise',
            'JSON ist gültiges Compose-YAML',
            'Containerinterface',
            'stabilen Selbsttestschlüssel je Scheduler-Boot-ID',
            'strikt größer als der Worker-Timeout',
            'natives `ext-intl`',
            'storage/app/ai6-local.sqlite',
            'DB_DATABASE="$PWD/storage/app/ai6-local.sqlite" php artisan migrate',
            '/database/*.sqlite-wal',
            'müssen zuvor `AI6_REDACTION_ACTIVE_KEY_ID` und ein nicht leerer, versionierter `AI6_REDACTION_KEYS`-Ring gesetzt werden',
        ] as $required) {
            self::assertStringContainsString($required, $readme);
        }
    }
}
