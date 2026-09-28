<?php

namespace Tests\Feature\Shared\Doctor;

use PHPUnit\Framework\TestCase;

final class DoctorDocumentationTest extends TestCase
{
    public function test_install_access_upgrade_and_open_gates_are_documented(): void
    {
        $root = dirname(__DIR__, 4);
        $readme = (string) file_get_contents($root.'/README.md');
        foreach ([
            'docker compose exec app php artisan ai6:install',
            'docker compose exec worker php artisan ai6:doctor --security --all-processes --require-strict',
            'openssl rand -base64 32', 'AI6_REDACTION_KEYS', 'Bootstrap',
            'ssh -N -L 127.0.0.1:<port>:127.0.0.1:<port> <tunnelbenutzer>@<host>',
            'lokaler und entfernter Port müssen identisch und gleich `AI6_HTTP_PORT` sein',
            'Match User <tunnelbenutzer>', 'AllowTcpForwarding local', 'PermitOpen 127.0.0.1:<port>',
            'PermitTTY no', 'ForceCommand /bin/false', 'AllowAgentForwarding no', 'X11Forwarding no', 'PermitTunnel no',
            'Referenz ohne Abnahme', 'AI6_APP_URL=https://<hostname>', '`Host` und `X-Forwarded-Proto`',
            '`trusted_proxies`', 'erneute Passkey-Registrierung',
            '### Upgrade', 'null Fehlern und null übersprungenen Nachweisen',
            'docker compose stop caddy app worker scheduler agent checker',
            'docker compose up --no-deps --force-recreate --exit-code-from init init',
            'Rootless-Betrieb und Härtungsempfehlungen', 'UNGEPRÜFT',
            'security_review_adapter_fake', 'AI6_AGENT_SECURITY_REVIEW_PROFILE',
            'AI6-033/MG-01', 'AI6-041/MG-01', 'AI6-048/MG-01',
            'Linux-Checkout desselben Commits', 'AI6-049',
            'git_allowlist_empty', 'kein erlaubter Restbefund',
            'sudo apparmor_parser -r /etc/apparmor.d/ai6-execution',
            'ai6-agent-v1', 'ai6-checker-v1', 'Enforce-Modus',
            'systempaths=unconfined', 'Hardlinks auf geschützte Ziele',
            'Hosts ohne AppArmor werden nicht unterstützt', 'apparmor_confined',
            '/proc/self/attr/current', 'ai6-agent-v1 (enforce)', 'ai6-checker-v1 (enforce)',
        ] as $required) {
            self::assertStringContainsString($required, $readme);
        }
        $environment = (string) file_get_contents($root.'/.env.example');
        self::assertStringContainsString('APP_URL=http://localhost', $environment);
        self::assertStringContainsString('# AI6_APP_URL=', $environment);
        self::assertDoesNotMatchRegularExpression('/base64:[A-Za-z0-9+\/=]{20,}/', $environment);
        self::assertDoesNotMatchRegularExpression('/base64:[A-Za-z0-9+\/=]{20,}/', $readme);
        $protocol = (string) file_get_contents($root.'/docs/AI6-036_MG-01_ABNAHMEPROTOKOLL.md');
        foreach (['leeren Volumes', 'Image-Digest:', 'Exitcode:', 'Unterschrift:', 'Remote-Kommando', 'SFTP', 'anderes Weiterleitungsziel', 'Remote-Weiterleitung', 'AppArmor aktiv', 'nach README installiert', 'aa-status', '/proc/self/attr/current', 'ai6-agent-v1 (enforce)', 'ai6-checker-v1 (enforce)', 'Login-Verzeichnis rw', 'auth.json', 'Credential-Projektion ro', 'Input ro/Output rw', '/run/ai6/provider-private', 'permission denied', 'keine erlaubten Restbefunde'] as $required) {
            self::assertStringContainsString($required, $protocol);
        }
        self::assertStringNotContainsString('- [x]', $protocol);
    }

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
