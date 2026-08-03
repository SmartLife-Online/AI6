<?php

namespace Tests\Feature\Shared\Doctor;

use PHPUnit\Framework\TestCase;

final class DoctorDocumentationTest extends TestCase
{
    public function test_readme_and_environment_example_document_the_complete_security_contract(): void
    {
        $root = dirname(__DIR__, 4);
        $readme = file_get_contents($root.'/README.md');
        $environment = file_get_contents($root.'/.env.example');
        self::assertNotFalse($readme);
        self::assertNotFalse($environment);

        foreach ([
            'AI6_SECURITY_PROFILE=strict',
            'AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=false',
            'AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION=true',
            'AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY=true',
            'AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP=true',
            'AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS=true',
            'AI6_SECURITY_REQUIRE_AGENT_SANDBOX=true',
            'AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION=true',
            'AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW=true',
            'AI6_REDACTION_ACTIVE_KEY_ID=app-key-v1',
            '# Außerhalb von APP_ENV=local/testing ist ein expliziter versionierter Ring erforderlich.',
            'AI6_REDACTION_KEYS=',
        ] as $expected) {
            self::assertStringContainsString($expected, $environment);
        }

        foreach ([
            '`strict` erlaubt keine deaktivierte Maßnahme',
            '`development` deaktiviert normativ ausschließlich `AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS`',
            '[REDACTED:SECRET]',
            '[REDACTED:TOKEN]',
            '[REDACTED:CREDENTIAL]',
            '[REDACTED:PATH]',
            'InvalidRedactionInputException',
            'darf wegen der PHP-Array-Key-Koerzierung nicht rein numerisch sein',
            'Unvollständige Maßnahmenmengen werden vor dem Hashing abgelehnt',
            'AI6-SECURITY-POLICY-V1',
            'AI6-REDACTION-FINGERPRINT-V1',
            'unsigned 64-bit Big-Endian',
            '655ab648b8459ed33bdbfe325bf59c26bbb0b39c762a7051e06076a49057dcda',
            'e1e01a3a8c055ee11a42b78161cc8db082b30810660a75af98b5ffc308f91c04',
            'Bereits gespeicherte Fingerprints werden weder neu berechnet noch verändert',
            '`ai6:doctor` weist deshalb ausdrücklich auf den lokalen Fallback hin',
            '`app`, `worker`, `scheduler` und normale Laravel-Starts lösen den Ring bereits im ersten Anwendungsprovider auf',
            '`composer.json` `ext-intl` als zwingende Plattformanforderung',
            'php artisan ai6:doctor',
        ] as $expected) {
            self::assertStringContainsString($expected, $readme);
        }
    }
}
