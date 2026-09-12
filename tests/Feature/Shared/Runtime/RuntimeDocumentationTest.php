<?php

namespace Tests\Feature\Shared\Runtime;

use PHPUnit\Framework\TestCase;

final class RuntimeDocumentationTest extends TestCase
{
    public function test_copilot_transport_documents_roles_and_separate_open_runtime_evidence(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 4).'/README.md');
        self::assertNotFalse($readme);
        self::assertSame(1, preg_match('/^### Copilot-CLI-Transport\R(.*?)(?=^### |\z)/ms', $readme, $matches));

        foreach ([
            '`github_copilot_cli` löst den `GitHubCopilotCliAdapter` auf',
            '`GitHubCopilotCliDoctorCheck`',
            '`AI6_COPILOT_BINARY`, `AI6_COPILOT_PINNED_VERSION` und `AI6_COPILOT_CREDENTIAL_REVISION` erreichen über Compose ausschließlich `worker` und `agent`',
            'ausschließlich `quality_review`',
            '`COPILOT_HOME` zeigt auf das vollständig read-only Home einschließlich `home/session-state`',
            '`ai6.copilot.capability_evidence` bleibt standardmäßig leer',
            '`AI6_COPILOT_CAPABILITY_EVIDENCE`',
            'kommagetrennte Liste kleingeschriebener SHA-256-Werte',
            'Compose-Weitergabe dieses neuen Wertes',
            'Die ausgelieferte Argumentliste enthält deshalb kein `--no-experimental`',
            'Der Vorfahren-Scan weist `.git` als Datei, Verzeichnis oder Symlink ab',
            '`config.json`, `session-store.db`/`-wal`/`-shm`',
            '`No authentication information found`',
            'ohne Modellturn',
            'Outcome, Exitcode und die zentral redigierte Diagnose',
            'neue Invocation ohne natives Resume',
            'AI6_RUN_COPILOT_SMOKE=1',
            'Ein gesetzter Pin gibt keine CLI-Version frei',
            'ergebnisfreie Vorlage',
            'Der Home-Vertragsentwurf bleibt bis zu diesem Laufzeitnachweis für eine Planübernahme gesperrt',
        ] as $statement) {
            self::assertStringContainsString($statement, $matches[1]);
        }
        self::assertStringNotContainsString('`TEMP`/`TMP` vor dem Test', $matches[1]);
        self::assertStringNotContainsString('ist noch nicht umgesetzt', $matches[1]);
        foreach (['worker', 'agent'] as $service) {
            self::assertStringContainsString('AI6_COPILOT_CAPABILITY_EVIDENCE', $this->serviceRow($readme, $service), $service);
        }
    }

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
            '`AI6_SERVICE_SUBNET`',
            '`AI6_PROXY_ADDRESS`',
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
            '`AgentExecutionRunner`',
            '`AgentExecutionProcessor`',
            '`runtime/turn.json`',
            '`AI6_SECURITY_REQUIRE_AGENT_SANDBOX`',
            '`AI6-047/MG-01`',
            '`docs/AI6-047_MG-01_ABNAHMEPROTOKOLL.md`',
            '`CodexCliAdapter`',
            '`CodexCliDoctorCheck`',
            '`codex exec`',
            '`--output-schema`',
            '`--ephemeral`',
            '`CODEX_HOME`',
            '`AI6_CODEX_BINARY`',
            '`AI6_CODEX_PINNED_VERSION`',
            'AI6_RUN_CODEX_SMOKE=1',
            'neue `codex exec`-Invocation',
            '`CodexCliAdapter::VERIFIED_MODELS`',
            '`CodexCliAdapter::PERMITTED_ENABLED_FEATURES`',
            '`codex features list`',
            '`codex debug models`',
            '`MAX_ARG_STRLEN`',
            'über die Standardeingabe',
            '`agent_codex_extension_unapproved`',
            '`agent_codex_extension_materialized`',
            '`agent_codex_model_unverified`',
            '`agent_response_after_turn`',
            '`AI6_CODEX_SANDBOX_PROOF`',
            '`agent_codex_sandbox_unproven`',
            '`agent_codex_sandbox_proof_invalid`',
            'ausschließlich am dekodierten Wert',
            'Ohne bindenden Sandboxnachweis meldet der Doctor nie',
            'Das gilt auf beiden Ausführungswegen',
            '`AI6-033/MG-01`',
            '`docs/AI6-033_MG-01_ABNAHMEPROTOKOLL.md`',
            'Ausführungsnachweise',
            'JSON ist gültiges Compose-YAML',
            'Containerinterface',
            'stabilen Selbsttestschlüssel je Scheduler-Boot-ID',
            '`ai6_managed`',
            '`AI6_CONTROL_OPERATION_LEASE_SECONDS`',
            '`ai6-control-operation-reconciler`',
            '`recovery_required`',
            '`retry_reconciliation`',
            '`adopt_external_state`',
            '`abandon_operation`',
            '`AI6-006C/MG-01`',
            '`AI6-006C/MG-02`',
            '`AI6-006D/MG-01`',
            '`AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST`',
            '`AI6_CONTROL_OPERATION_STALE_SECONDS`',
            '`AI6_CONTROL_OPERATION_RECONCILIATION_BUDGET`',
            '`target_control_oid`',
            'ausschließlich Remotes im SHA-256-Objektformat',
            'strikt größer als der Worker-Timeout',
            'natives `ext-intl`',
            'storage/app/ai6-local.sqlite',
            'DB_DATABASE="$PWD/storage/app/ai6-local.sqlite" php artisan migrate',
            '/database/*.sqlite-wal',
            'müssen zuvor `AI6_REDACTION_ACTIVE_KEY_ID` und ein nicht leerer, versionierter `AI6_REDACTION_KEYS`-Ring gesetzt werden',
        ] as $required) {
            self::assertStringContainsString($required, $readme);
        }

        $init = $this->serviceRow($readme, 'init');
        foreach ([
            'AI6_MANAGED_PROJECT_ROOT',
            'AI6_EFFECT_LOCK_DIRECTORY',
            'AI6_EFFECT_LOCK_OBJECT_COUNT',
            'AI6_EFFECT_LOCK_OWNER_UID',
            'ai6_managed',
        ] as $required) {
            self::assertStringContainsString($required, $init);
        }

        $worker = $this->serviceRow($readme, 'worker');
        foreach ([
            'AI6_MANAGED_PROJECT_ROOT',
            'AI6_DEPLOY_KEY_ROOT',
            'AI6_EFFECT_LOCK_DIRECTORY',
            'AI6_EFFECT_LOCK_OBJECT_COUNT',
            'AI6_EFFECT_LOCK_OWNER_UID',
            'AI6_CONTROL_OPERATION_LEASE_SECONDS',
            'AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS',
            'AI6_CONTROL_OPERATION_RECONCILER_SECONDS',
            'AI6_CONTROL_OPERATION_MAX_ATTEMPTS',
            'AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE',
            'AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST',
            'AI6_CONTROL_OPERATION_STALE_SECONDS',
            'AI6_CONTROL_OPERATION_RECONCILIATION_BUDGET',
            'AI6_SSH_KEYGEN_BINARY',
            'ai6_managed',
        ] as $required) {
            self::assertStringContainsString($required, $worker);
        }

        self::assertStringContainsString(
            'AI6_CONTROL_OPERATION_RECONCILER_SECONDS',
            $this->serviceRow($readme, 'scheduler'),
        );

        // AI6-033: the pinned Codex transport is documented for exactly the roles that receive it.
        foreach (['app', 'worker', 'agent'] as $service) {
            foreach (['AI6_CODEX_BINARY', 'AI6_CODEX_PINNED_VERSION', 'AI6_CODEX_SANDBOX_PROOF'] as $variable) {
                self::assertStringContainsString($variable, $this->serviceRow($readme, $service), $service.' '.$variable);
            }
        }
        self::assertStringContainsString('AI6_CODEX_CREDENTIAL_REVISION', $this->serviceRow($readme, 'agent'));
        foreach (['init', 'scheduler', 'checker'] as $service) {
            self::assertStringNotContainsString('AI6_CODEX_BINARY', $this->serviceRow($readme, $service), $service);
        }

        foreach (['Claim', 'Publish', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized', 'attempt_completed'] as $phase) {
            self::assertStringContainsString($phase, $readme);
        }

        self::assertStringNotContainsString(
            'AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE',
            $this->serviceRow($readme, 'app'),
        );
        self::assertStringContainsString(
            'AI6_CONTROL_OPERATION_HEARTBEAT_SECONDS',
            $this->serviceRow($readme, 'app'),
        );
    }

    private function serviceRow(string $readme, string $service): string
    {
        self::assertSame(1, preg_match('/^\| `'.preg_quote($service, '/').'` \|.*$/m', $readme, $matches));

        return $matches[0];
    }
}
