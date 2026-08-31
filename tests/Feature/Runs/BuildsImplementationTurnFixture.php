<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\CanonicalDiffHasher;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPreflight;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

trait BuildsImplementationTurnFixture
{
    use BuildsFinalizedRunFixture;

    private ?string $implementationFixtureRoot = null;

    #[After]
    public function removeImplementationFixture(): void
    {
        $root = $this->implementationFixtureRoot;
        $temporary = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));
        $resolved = is_string($root) ? realpath($root) : false;
        if ($resolved !== false && str_starts_with(str_replace('\\', '/', $resolved).'/', $temporary.'/ai6-019-')) {
            $this->removeImplementationFixtureTree($resolved);
        }
        $this->implementationFixtureRoot = null;
    }

    /**
     * @param  list<string>  $files
     * @param  list<InstructionCandidate>  $instructionCandidates
     * @return array{run: Run, project: Project, operator: User, worktree: string, isolatedRoot: string}
     */
    protected function preparedImplementationRun(
        string $ticketId,
        array $files = ['app/Example.php'],
        ?AgentScenario $scenario = null,
        bool $shippedProcessPolicy = false,
        bool $coherentGitBinding = false,
        array $instructionCandidates = [],
    ): array {
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class(...$instructionCandidates) implements InstructionCandidateSource
        {
            /** @var list<InstructionCandidate> */
            private readonly array $candidates;

            public function __construct(InstructionCandidate ...$candidates)
            {
                $this->candidates = $candidates;
            }

            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return $this->candidates;
            }
        });
        if (! $shippedProcessPolicy) {
            $this->bindImplementationProcessBoundary();
        }
        config([
            'ai6.runtime_role' => 'worker',
            'ai6.run_artifacts.root' => $this->implementationTemp('artifacts'),
            'ai6.execution_mailboxes.agent_root' => $this->implementationTemp('isolated'),
            'ai6.execution_mailboxes.agent_output_root' => $this->implementationTemp('agent-outputs'),
            'ai6.process.policies.agent.working_roots' => [$this->implementationTemp('isolated')],
        ]);
        // The console boot of the migrations already constructed every artisan
        // command; the mailbox command of AI6-045 thereby froze the process
        // policy registry with the shipped roots. The singletons derived from
        // the configuration above must therefore rebuild on every platform —
        // the shipped process-group semantics themselves stay untouched.
        $this->app->forgetInstance(ProcessPolicyRegistry::class);
        $this->app->forgetInstance(ControlProcessRunner::class);
        $this->app->forgetInstance(RunPreflight::class);
        $this->app->forgetInstance(RunArtifactRoot::class);
        $this->app->forgetInstance(RunArtifactStore::class);
        $this->app->forgetInstance(RunImplementation::class);
        if ($scenario instanceof AgentScenario) {
            $adapter = new FakeAgentAdapter($scenario);
            $this->app->instance(FakeAgentAdapter::class, $adapter);
            $this->app->instance(AgentAdapter::class, $adapter);
        }

        $worktree = null;
        $controlOid = str_repeat('a', 64);
        if ($coherentGitBinding) {
            $repository = $this->implementationTemp('repository-coherent');
            $this->writeInitialWorktree($repository, $files);
            self::assertTrue(mkdir($repository.'/tickets', 0700));
            self::assertNotFalse(file_put_contents(
                $repository.'/tickets/'.$ticketId.'.md',
                str_replace('status: todo', 'status: in_progress', $this->implementationTicketMarkdown($ticketId, $files)),
            ));
            $this->initWorktreeGit($repository, true);
            $controlOid = $this->gitOutput(['rev-parse', 'HEAD'], $repository);
            $worktree = $this->linkedWorktree($repository, $controlOid);
        }

        $markdown = $this->implementationTicketMarkdown($ticketId, $files);
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $operator = $this->createUser();
        $attention = $this->createUser(['email' => 'attention-'.$ticketId.'@example.test']);
        $project = Project::query()->create([
            'name' => 'Implement-'.$ticketId,
            'remote' => 'git@git.example.test:acme/'.$ticketId.'.git',
            'control_branch' => 'refs/heads/main',
            'project_identifier' => substr(hash('sha256', $ticketId.microtime(true)), 0, 32),
            'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '='),
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/test-key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => $controlOid,
        ]);
        $this->addMembership($administrator, $project, ProjectRole::ADMIN);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);
        $this->addMembership($attention, $project, ProjectRole::APPROVER);
        $todoBlob = hash('sha256', 'blob '.strlen($markdown)."\0".$markdown);
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/'.$ticketId.'.md', $markdown, ['blob_sha' => $todoBlob]);
        $selection = $this->approvalSelection($attention);
        $operationId = (string) Str::uuid();
        $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create($project, $readModel, $selection, $operationId);
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project->refresh(),
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $markdown,
            'Runstart-Vertrag vorbereiten',
            true,
            $selection,
            $snapshot,
            $operationId,
        );
        DB::table('jobs')->delete();
        $ready = str_replace('status: todo', 'status: ready', $markdown);
        $readyBlob = hash('sha256', 'blob '.strlen($ready)."\0".$ready);
        self::assertSame(1, TicketApproval::query()->whereKey($operationId)->update([
            'approved_ticket_blob_sha' => $readyBlob,
            'approved_control_sha' => $project->control_oid,
            'intended_commit_sha' => $project->control_oid,
            'saga_phase' => 'complete',
            'queue_state' => 'queued',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('e', 32));
        self::assertIsInt($attemptToken);
        self::assertSame(1, ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $project->control_oid,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, DB::table('ticket_read_models')->where('id', $readModel->id)->update([
            'control_operation_id' => $operation->id,
            'blob_sha' => $readyBlob,
            'redacted_content' => $ready,
            'editor_eligible' => true,
            'approval_eligible' => true,
            'generated_at' => now(),
            'updated_at' => now(),
        ]));
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => 'succeeded',
            'result_binding' => str_repeat('d', 64),
            'safe_summary' => 'Testabschluss der Approval-Operation.',
        ]);
        ControlOperation::query()->whereKey($operation->id)->update([
            'phase' => ControlOperationPhase::DB_FINALIZED,
            'state' => ControlOperationState::COMPLETED,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release($operation->id, $operation->project_id, $attemptToken));
        $fixture = [
            'operator' => $operator,
            'project' => $project->refresh(),
            'approval' => TicketApproval::query()->findOrFail($operationId),
            'attention' => $attention,
        ];
        $run = $this->finalizedRun($fixture, $coherentGitBinding ? $controlOid : null);
        if ($worktree === null) {
            $worktree = $this->implementationTemp('worktree-'.$run->id);
            $this->writeInitialWorktree($worktree, $files);
            $this->initWorktreeGit($worktree);
        } else {
            $this->gitOutput(['checkout', '-b', 'ai6/runs/'.$project->project_identifier.'/'.$run->id], $worktree);
        }
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->bindWorkspace(
            $run,
            $run->version,
            'refs/heads/ai6/runs/'.$project->project_identifier.'/'.$run->id,
            $worktree,
        );
        if ($coherentGitBinding) {
            $run = $orchestrator->bindCheckpoint(
                $run,
                $run->version,
                $controlOid,
                $this->gitOutput(['rev-parse', $controlOid.'^{tree}'], $worktree),
                $this->app->make(CanonicalDiffHasher::class)
                    ->fromRaw('', new RedactionContext((string) $project->id, $run->id, 'test-checkpoint'))->hash,
            );
        } else {
            $run = $orchestrator->bindCheckpoint($run, $run->version, str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64));
        }
        $preflight = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::PREFLIGHT->value)->firstOrFail();
        (new ExecuteRunStep($preflight->id))->handle($orchestrator);
        self::assertSame(ExecutionJobState::SUCCEEDED, $preflight->fresh()->state, (string) $preflight->fresh()?->failure_code);
        DB::table('jobs')->delete();

        return [
            'run' => $run->refresh(),
            'project' => $project->refresh(),
            'operator' => $operator,
            'worktree' => $worktree,
            'isolatedRoot' => $this->implementationTemp('isolated'),
        ];
    }

    protected function executeImplement(Run $run): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));

        return $job->fresh() ?? $job;
    }

    /** @param list<string> $files */
    protected function implementationTicketMarkdown(string $id, array $files): string
    {
        $fileLines = implode("\n", array_map(static fn (string $file): string => '  - '.$file, $files));

        return <<<MARKDOWN
        ---
        schema: ai6.ticket.v1
        id: {$id}
        title: "Ticket {$id}"
        status: todo
        depends_on: []
        files:
        {$fileLines}
        ---

        # {$id} — Ticket {$id}

        ## Goal

        Ziel des Tickets.

        ## Acceptance Criteria

        - [ ] **AC-01** Die Änderung ist nachvollziehbar.
        MARKDOWN;
    }

    protected function bindImplementationProcessBoundary(): void
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return;
        }
        config(['ai6.process.policies.agent.requires_process_group' => false]);
        $this->app->forgetInstance(ProcessPolicyRegistry::class);
        $this->app->forgetInstance(ControlProcessRunner::class);
        $this->app->forgetInstance(RunPreflight::class);
        $this->app->forgetInstance(FakeAgentAdapter::class);
        $this->app->forgetInstance(AgentAdapter::class);
        $this->app->bind(AgentAdapter::class, FakeAgentAdapter::class);
    }

    protected function initWorktreeGit(string $worktree, bool $sha256 = false): void
    {
        $git = (new ExecutableFinder)->find('git');
        self::assertIsString($git);
        $arguments = [$git, 'init'];
        if ($sha256) {
            $arguments[] = '--object-format=sha256';
        }
        $arguments[] = '--initial-branch=main';
        (new Process($arguments, $worktree))->mustRun();
        (new Process([$git, 'config', 'user.email', 'ai6@example.test'], $worktree))->mustRun();
        (new Process([$git, 'config', 'user.name', 'AI6 Fixture'], $worktree))->mustRun();
        (new Process([$git, 'add', '.'], $worktree))->mustRun();
        (new Process([$git, 'commit', '-m', 'fixture'], $worktree))->mustRun();
    }

    private function linkedWorktree(string $repository, string $commit): string
    {
        $worktree = $this->implementationTemp('worktree-coherent');
        self::assertTrue(rmdir($worktree));
        $git = (new ExecutableFinder)->find('git');
        self::assertIsString($git);
        (new Process([$git, 'worktree', 'add', '--detach', $worktree, $commit], $repository))->mustRun();
        $real = realpath($worktree);
        self::assertNotFalse($real);

        return str_replace('\\', '/', $real);
    }

    /** @param list<string> $files */
    private function writeInitialWorktree(string $worktree, array $files): void
    {
        if (! is_dir($worktree.'/app')) {
            self::assertTrue(mkdir($worktree.'/app', 0700, true));
        }
        self::assertNotFalse(file_put_contents($worktree.'/app/Example.php', "<?php\n\n// original\n"));
        foreach ($files as $file) {
            if ($file === 'app/Example.php') {
                continue;
            }
            $target = $worktree.'/'.$file;
            $directory = dirname($target);
            if (! is_dir($directory)) {
                self::assertTrue(mkdir($directory, 0700, true));
            }
            if (! is_file($target)) {
                self::assertNotFalse(file_put_contents($target, "# fixture\n"));
            }
        }
    }

    /** @param list<string> $arguments */
    protected function gitOutput(array $arguments, string $worktree): string
    {
        $git = (new ExecutableFinder)->find('git');
        self::assertIsString($git);
        $process = new Process([$git, ...$arguments], $worktree);
        $process->mustRun();

        return trim($process->getOutput());
    }

    protected function implementationTemp(string $suffix): string
    {
        $this->implementationFixtureRoot ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-019-'.bin2hex(random_bytes(8));
        $root = $this->implementationFixtureRoot.DIRECTORY_SEPARATOR.$suffix;
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $real = realpath($root);
        self::assertNotFalse($real);

        return str_replace('\\', '/', $real);
    }

    private function removeImplementationFixtureTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            @chmod($child, is_dir($child) && ! is_link($child) ? 0700 : 0600);
            if (is_dir($child) && ! is_link($child)) {
                $this->removeImplementationFixtureTree($child);
            } else {
                @unlink($child);
            }
        }
        @chmod($path, 0700);
        @rmdir($path);
    }
}
