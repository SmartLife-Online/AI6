<?php

namespace Tests\Feature\Runs;

use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RecordedScopeRenderer;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class RecordedScopeRendererTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_persisted_scope_decisions_and_quarantine_artifacts_are_rendered_and_redacted(): void
    {
        $fixture = $this->completedApproval('AI6-029-RECORDED-SCOPE');
        $run = $this->finalizedRun($fixture);
        $run = $this->app->make(RunOrchestrator::class)->applyScopeDecision(
            $run,
            'app/AI6/Runs/Path`WithTick.php',
            true,
            null,
            12,
            $this->app->make(CanonicalJson::class),
            'auto_allow',
        );
        RunArtifact::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => $run->id,
            'kind' => RunArtifactKind::QUARANTINED_PATH,
            'redacted_metadata' => ['path' => 'private/quarantined.php'],
            'digest' => str_repeat('9', 64),
            'size_bytes' => 1,
            'sequence' => 1,
            'storage_reference' => 'test://recorded-scope/quarantine',
            'expires_at' => now()->addDay(),
            'fingerprint_version' => 1,
            'fingerprint_key_id' => 'app-key-v1',
            'fingerprint' => str_repeat('a', 64),
        ]);
        RunArtifact::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => $run->id,
            'kind' => RunArtifactKind::QUARANTINED_PATH,
            'redacted_metadata' => ['path' => 'ghp_1234567890'],
            'digest' => str_repeat('8', 64),
            'size_bytes' => 1,
            'sequence' => 2,
            'storage_reference' => 'test://recorded-scope/redacted-quarantine',
            'expires_at' => now()->addDay(),
            'fingerprint_version' => 1,
            'fingerprint_key_id' => 'app-key-v1',
            'fingerprint' => str_repeat('a', 64),
        ]);
        $input = "---\nstatus: in_progress\n---\n\n# Test\n\n## Goal\n\n```md\n## Notes\n```\n\n## Notes\n\nNotiz.\n";

        $rendered = $this->app->make(RecordedScopeRenderer::class)->write($run->fresh(), $input);

        self::assertStringContainsString('- ``app/AI6/Runs/Path`WithTick.php`` — approved — auto_allow', $rendered);
        self::assertStringContainsString('- `private/quarantined.php`', $rendered);
        self::assertStringContainsString('- `[redigiert]`', $rendered);
        self::assertStringNotContainsString('ghp_1234567890', $rendered);
        self::assertStringContainsString("```md\n## Notes\n```", $rendered);
        self::assertGreaterThan(strpos($rendered, '## Notes'), strpos($rendered, '## Recorded Scope'));
        self::assertLessThan(strrpos($rendered, '## Notes'), strpos($rendered, '## Recorded Scope'));
        self::assertSame(1, substr_count($rendered, '## Recorded Scope'));
    }
}
