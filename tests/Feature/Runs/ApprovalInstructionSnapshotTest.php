<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Agents\InstructionCandidateOrigin;
use App\AI6\Agents\InstructionFileType;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalFreshness;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ApprovalInstructionSnapshotTest extends TicketUiTestCase
{
    public function test_instruction_order_hashes_adapter_and_runtime_changes_are_approval_bound(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('INS1');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/INS1.md', $content);
        $source = new MutableInstructionCandidateSource([
            $this->candidate('docs/AGENTS.md', 'nested', str_repeat('b', 40), 'agents_md_nested', ['AGENTS.md']),
            $this->candidate('AGENTS.md', 'root', str_repeat('a', 40)),
        ]);
        $this->app->instance(InstructionCandidateSource::class, $source);
        $selection = $this->selection();
        $contextId = (string) Str::uuid();
        $factory = $this->app->make(ApprovalSnapshotFactory::class);
        $snapshot = $factory->create($project, $readModel, $selection, $contextId);
        $entries = $snapshot->instructions['fake']['entries'];

        self::assertSame(['AGENTS.md', 'docs/AGENTS.md'], array_column($entries, 'repository_path'));
        self::assertSame(['repository', 'nested'], array_column($entries, 'scope'));
        self::assertSame([10, 20], array_column($entries, 'priority'));
        self::assertSame([str_repeat('a', 40), str_repeat('b', 40)], array_column($entries, 'blob_sha'));
        self::assertSame(hash('sha256', 'root'), $entries[0]['content_sha256']);
        self::assertSame(hash('sha256', 'nested'), $entries[1]['content_sha256']);
        self::assertNotContains('host/AGENTS.md', array_column($entries, 'repository_path'));

        $operationId = (string) Str::uuid();
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project,
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $content,
            'Instruktionsbindung prüfen',
            true,
            $selection,
            $snapshot,
            $contextId,
        );
        DB::table('jobs')->delete();
        $approval = TicketApproval::query()->findOrFail($operation->id);

        $source->candidates = [
            $this->candidate('nested/AGENTS.md', 'changed', str_repeat('c', 40), 'agents_md_nested', ['AGENTS.md']),
            $this->candidate('AGENTS.md', 'root', str_repeat('a', 40)),
        ];
        $changedInstructions = $factory->create($project, $readModel, $selection, $contextId);
        self::assertNotSame($snapshot->instructionHash, $changedInstructions->instructionHash);
        self::assertContains('instructions_changed', $this->app->make(ApprovalFreshness::class)->reasons(
            $approval,
            $project,
            $readModel,
            $approval->config_hash,
            ['instruction_hash' => $changedInstructions->instructionHash],
        ));

        config(['ai6.provider_runtime_profiles.fake-v1.version' => 2]);
        $this->app->forgetInstance(ProviderRuntimeProfileRegistry::class);
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);
        $runtimeChanged = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $project,
            $readModel,
            $selection,
            $contextId,
        );
        self::assertNotSame($snapshot->runtimeProfileHash, $runtimeChanged->runtimeProfileHash);
        self::assertContains('runtime_profile_changed', $this->app->make(ApprovalFreshness::class)->reasons(
            $approval,
            $project,
            $readModel,
            $approval->config_hash,
            ['runtime_profile_hash' => $runtimeChanged->runtimeProfileHash],
        ));

        $fakeAlias = config('ai6.agent_profiles.fake');
        self::assertIsArray($fakeAlias);
        config([
            'ai6.agent_profiles.fake-alias' => $fakeAlias,
            'ai6.agent_profiles.fake.provider_profile' => 'codex_cli',
            'ai6.agent_profiles.fake.adapter' => 'codex_cli',
        ]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $this->app->forgetInstance(ReviewerSlotFactory::class);
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);
        $adapterChanged = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $project,
            $readModel,
            $this->selection(),
            $contextId,
        );
        self::assertNotSame($snapshot->agentProfileHash, $adapterChanged->agentProfileHash);
        self::assertContains('agent_profiles_changed', $this->app->make(ApprovalFreshness::class)->reasons(
            $approval,
            $project,
            $readModel,
            $approval->config_hash,
            ['agent_profile_hash' => $adapterChanged->agentProfileHash],
        ));
    }

    private function selection(): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(
                config('ai6.project_config.server_defaults.limits'),
                $this->app->make(AgentInputLimits::class),
            ),
            null,
            'manual',
        );
    }

    /** @param list<string> $imports */
    private function candidate(string $path, string $content, string $blob, string $discovery = 'agents_md', array $imports = []): InstructionCandidate
    {
        return new InstructionCandidate(
            $discovery,
            InstructionCandidateOrigin::REPOSITORY,
            true,
            InstructionFileType::REGULAR,
            $path,
            $blob,
            $content,
            $imports,
        );
    }
}

final class MutableInstructionCandidateSource implements InstructionCandidateSource
{
    /** @param list<InstructionCandidate> $candidates */
    public function __construct(public array $candidates) {}

    public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
    {
        return $this->candidates;
    }
}
