<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\CheckTreeBinding;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\ReviewCheckpointVerifier;
use App\AI6\Git\ReviewSubject;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\Git\ReviewSubjectNormalizer;
use App\AI6\Git\ReviewSubjectReference;
use App\AI6\Git\ReviewSubjectVerifier;
use App\AI6\Git\RunWorkspaceLifecycle;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\ReviewOnlyPrepareStep;
use App\AI6\Runs\ReviewOnlyRunCoordinator;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunCheckStep;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunType;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Process\Process;
use Tests\Feature\Checks\BuildsCheckFixture;

/**
 * A real managed SHA-256 clone plus the approved review-only run that AI6-040
 * binds to it.
 *
 * Everything runs through the shipped seams: the approval carries the canonical
 * review-subject reference, the run is finalized by the real claim, and the
 * steps are delivered by the real execution job.
 */
trait BuildsReviewOnlyRunFixture
{
    use BuildsCheckFixture;

    private ?string $reviewOnlyManagedRoot = null;

    private ?ManagedProjectPath $reviewOnlyPaths = null;

    private ?ReviewSubject $reviewOnlySubject = null;

    private ReviewOnlyCompletionMode $reviewOnlyCompletionMode = ReviewOnlyCompletionMode::MANUAL;

    private ?string $reviewOnlyLockDirectory = null;

    private ?string $reviewOnlyCheckProfile = null;

    #[After]
    public function removeReviewOnlyManagedRoot(): void
    {
        if ($this->reviewOnlyManagedRoot !== null && is_dir($this->reviewOnlyManagedRoot)) {
            $this->removeReviewOnlyTree($this->reviewOnlyManagedRoot);
        }
        $this->reviewOnlyManagedRoot = null;
        $this->reviewOnlyPaths = null;
        $this->removeRunWorkspaceFixture();
    }

    /**
     * The review-only selection the finalized-run fixture consumes. Test classes
     * override `approvalSelection()` with this so the approval, the run and the
     * worker all read the same canonical reference.
     */
    protected function reviewOnlySelection(?User $attentionUser = null): ApprovalSelection
    {
        $subject = $this->reviewOnlySubject;
        self::assertInstanceOf(ReviewSubject::class, $subject, 'The review subject must be bound before the approval.');
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
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $attentionUser?->getKey(),
            'manual',
            RunType::REVIEW_ONLY,
            $this->app->make(ReviewSubjectReference::class)->encode($subject),
            $this->reviewOnlyCompletionMode,
        );
    }

    /**
     * Source normalization creates a detached staging worktree under the project
     * effect lock. Blocked control-process starts are POSIX-only, so the proofs
     * that actually materialize a review checkpoint run in the Linux runtime.
     */
    protected function requiresPosixEffectRuntime(): string
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The review-source normalization requires the POSIX process and effect-lock runtime.');
        }
        // The shipped `init` role provisions this directory with immutable
        // lock objects; the proof runs where the worker runtime really exists.
        $lockDirectory = config('ai6.process.lock_directory');
        if (! is_string($lockDirectory) || ! is_dir($lockDirectory) || ! is_file($lockDirectory.'/lock-0001')) {
            self::markTestSkipped('The review-source normalization requires the provisioned effect-lock directory of the worker runtime.');
        }
        $this->reviewOnlyLockDirectory = $lockDirectory;

        return $lockDirectory;
    }

    /**
     * Bind a throwaway managed root with the real hardened runner and return the
     * managed path layout the worker resolves.
     */
    protected function bindManagedReviewRoot(): ManagedProjectPath
    {
        if ($this->reviewOnlyPaths instanceof ManagedProjectPath) {
            return $this->reviewOnlyPaths;
        }
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-040-managed-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        $this->reviewOnlyManagedRoot = $root;
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        $replacement = new ControlOperationConfiguration(
            $root,
            $root.'/deploy-keys',
            $configuration->sshKeygenBinary,
            $configuration->sshKeygenWrapper,
            $configuration->leaseSeconds,
            $configuration->heartbeatSeconds,
            $configuration->reconcilerSeconds,
            $configuration->maxAttempts,
            $configuration->knownHostsFile,
            $configuration->managedRefAllowlist,
            $configuration->staleSeconds,
            $configuration->reconciliationBudget,
        );
        $this->app->instance(ControlOperationConfiguration::class, $replacement);
        $paths = new ManagedProjectPath($replacement);
        $this->app->instance(ManagedProjectPath::class, $paths);
        $lockDirectory = $this->reviewOnlyLockDirectory;
        $lockStat = is_string($lockDirectory) ? stat($lockDirectory) : false;
        $this->app->instance(HardenedGitRunner::class, $this->runWorkspaceRunner(
            $this->runWorkspaceRoot(),
            $lockDirectory,
            is_array($lockStat) ? (int) $lockStat['uid'] : 0,
        ));
        foreach ([
            RunWorkspaceLifecycle::class,
            ReviewSubjectVerifier::class,
            ReviewSubjectNormalizer::class,
            ReviewCheckpointVerifier::class,
            CheckTreeBinding::class,
            CheckRunner::class,
            ReviewOnlyPrepareStep::class,
            ReviewOnlyRunCoordinator::class,
            RunCheckStep::class,
            ReviewRound::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $this->reviewOnlyPaths = $paths;

        return $paths;
    }

    /**
     * Create the managed SHA-256 repository of a project with one base commit
     * on the control branch and return its path plus the base commit OID.
     *
     * @return array{0: string, 1: string}
     */
    protected function managedReviewRepository(string $projectIdentifier): array
    {
        $paths = $this->bindManagedReviewRoot();
        $repository = $paths->repositoryDirectory($projectIdentifier);
        self::assertTrue(mkdir($repository, 0700, true));
        $this->managedGit(['init', '--object-format=sha256', '--initial-branch=main'], $repository);
        self::assertNotFalse(file_put_contents($repository.'/README.md', "base\n"));
        self::assertTrue(mkdir($repository.'/app', 0700));
        self::assertNotFalse(file_put_contents($repository.'/app/Example.php', "<?php\n\n// base\n"));
        $this->managedGit(['add', '--all'], $repository);
        $this->managedGit(['commit', '-m', 'base'], $repository);

        return [$repository, trim($this->managedGit(['rev-parse', 'HEAD'], $repository))];
    }

    /** Add one commit on the current branch and return its OID. */
    protected function managedReviewCommit(string $repository, string $path, string $content, string $message): string
    {
        $target = $repository.DIRECTORY_SEPARATOR.$path;
        $directory = dirname($target);
        if (! is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0700, true));
        }
        self::assertNotFalse(file_put_contents($target, $content));
        $this->managedGit(['add', '--all'], $repository);
        $this->managedGit(['commit', '-m', $message], $repository);

        return trim($this->managedGit(['rev-parse', 'HEAD'], $repository));
    }

    /** @param list<string> $arguments */
    protected function managedGit(array $arguments, string $repository): string
    {
        $process = new Process(['git', ...$arguments], $repository, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'AI6 Review Fixture',
            'GIT_AUTHOR_EMAIL' => 'review@example.invalid',
            'GIT_COMMITTER_NAME' => 'AI6 Review Fixture',
            'GIT_COMMITTER_EMAIL' => 'review@example.invalid',
            'GIT_AUTHOR_DATE' => '2026-08-26T00:00:00+00:00',
            'GIT_COMMITTER_DATE' => '2026-08-26T00:00:00+00:00',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * A real managed clone with a base commit on the control branch, one
     * further commit as the reviewable source, and the project bound to both.
     *
     * @return array{project: Project, operator: User, administrator: User, attention: User, repository: string, base: string, source: string, paths: ManagedProjectPath}
     */
    protected function prepareManagedReviewProject(string $ticketId): array
    {
        $this->bindImplementationProcessBoundary();
        config([
            'ai6.runtime_role' => 'worker',
            'ai6.run_artifacts.root' => $this->implementationTemp('artifacts'),
            'ai6.execution_mailboxes.agent_root' => $this->implementationTemp('isolated'),
            'ai6.execution_mailboxes.agent_output_root' => $this->implementationTemp('agent-outputs'),
            'ai6.process.policies.agent.working_roots' => [$this->implementationTemp('isolated')],
            // The review-readiness boundary exports and hashes the bound tree
            // through the same check runner seam; only its roots are local.
            'ai6.execution_mailboxes.checker_root' => $this->implementationTemp('checker'),
            'ai6.execution_mailboxes.checker_output_root' => $this->implementationTemp('checker-outputs'),
        ]);
        // The console boot of the migrations already froze the process policy
        // registry with the shipped roots; the singletons derived from the
        // configuration above must rebuild on every platform.
        foreach ([
            ProcessPolicyRegistry::class,
            ControlProcessRunner::class,
            RunArtifactRoot::class,
            RunArtifactStore::class,
            EffectiveProjectConfiguration::class,
            CheckRunner::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }

        $identifier = substr(hash('sha256', $ticketId.microtime(true)), 0, 32);
        [$repository, $base] = $this->managedReviewRepository($identifier);

        $administrator = $this->createUser(['is_global_admin' => true]);
        $operator = $this->createUser();
        $project = Project::query()->create([
            'name' => 'ReviewOnly-'.$ticketId,
            'remote' => 'git@git.example.test:acme/'.$ticketId.'.git',
            'control_branch' => 'refs/heads/main',
            'project_identifier' => $identifier,
            'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '='),
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/test-key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => $base,
        ]);
        $attention = $this->createUser(['email' => 'attention-'.strtolower($ticketId).'@example.test']);
        $this->addMembership($administrator, $project, ProjectRole::ADMIN);
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);

        // A bound before-review check profile is frozen into the approval
        // snapshot before it is created, and its probe script is committed with
        // the reviewed source so the check really runs on the review checkpoint.
        if ($this->reviewOnlyCheckProfile !== null) {
            self::assertNotFalse(file_put_contents(
                $repository.'/ai6-check-review-source.php',
                "<?php\n\n\$file = __DIR__.'/app/Example.php';\n"
                    ."exit(is_file(\$file) && str_contains((string) file_get_contents(\$file), 'reviewed change') ? 0 : 1);\n",
            ));
            $this->bindCheckRuntime([
                $this->reviewOnlyCheckProfile => $this->probeProfile(['ai6-check-review-source.php']),
            ]);
        }

        // The reviewed source is a real descendant of the approved control
        // state, so the checkpoint carries a non-empty bound diff.
        $source = $this->managedReviewCommit($repository, 'app/Example.php', "<?php\n\n// reviewed change\n", 'reviewed change');

        return [
            'project' => $project->refresh(),
            'operator' => $operator,
            'administrator' => $administrator,
            'attention' => $attention,
            'repository' => $repository,
            'base' => $base,
            'source' => $source,
            'paths' => $this->bindManagedReviewRoot(),
        ];
    }

    /**
     * A finalized review-only run bound to the reviewable source of a managed
     * clone.
     *
     * @return array{run: Run, project: Project, operator: User, administrator: User, attention: User, repository: string, base: string, source: string, paths: ManagedProjectPath}
     */
    protected function preparedReviewOnlyRun(
        string $ticketId,
        ?ReviewSubjectKind $kind = null,
        ReviewOnlyCompletionMode $completionMode = ReviewOnlyCompletionMode::MANUAL,
        ?string $checkProfile = null,
    ): array {
        $this->reviewOnlyCheckProfile = $checkProfile;
        $managed = $this->prepareManagedReviewProject($ticketId);
        $this->reviewOnlyCompletionMode = $completionMode;
        $this->reviewOnlySubject = $this->reviewSubjectFor(
            $kind ?? ReviewSubjectKind::MANAGED_BRANCH,
            $managed['base'],
            $managed['source'],
        );

        return [...$managed, 'run' => $this->claimReviewOnlyRun($ticketId, $managed)];
    }

    /**
     * Claim the review-only run of an already bound managed project through the
     * real approval and run-start seam.
     *
     * @param  array{project: Project, operator: User, attention: User, base: string}  $managed
     */
    protected function claimReviewOnlyRun(string $ticketId, array $managed): Run
    {
        $fixture = $this->completedApproval($ticketId, $managed['project']->refresh(), $managed['operator'], $managed['attention']);
        $run = $this->finalizedRun($fixture, $managed['base']);
        DB::table('jobs')->delete();

        return $this->reviewOnlyCheckProfile === null ? $this->withoutBoundChecks($run) : $run;
    }

    /**
     * Empty the bound before-review check list of this run.
     *
     * Only the scenarios that are about normalization, review execution, report
     * and cleanup take this shortcut. That checks really execute on the ref-free
     * review checkpoint is proven separately by a run built with a bound probe
     * profile (`preparedReviewOnlyRun(..., checkProfile: ...)`).
     */
    protected function withoutBoundChecks(Run $run): Run
    {
        $snapshot = $run->config_snapshot;
        self::assertIsArray($snapshot);
        $snapshot['values']['checks']['before_review'] = [];
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'config_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));

        return $run->refresh();
    }

    protected function reviewSubjectFor(ReviewSubjectKind $kind, string $base, string $source): ReviewSubject
    {
        return match ($kind) {
            ReviewSubjectKind::MANAGED_BRANCH => new ReviewSubject($kind, $base, $source, 'refs/heads/main'),
            ReviewSubjectKind::COMMIT_RANGE, ReviewSubjectKind::SINGLE_COMMIT => new ReviewSubject($kind, $base, $source),
            default => throw new \InvalidArgumentException('The stored source kinds need their own bound run.'),
        };
    }

    protected function executeReviewOnlyStep(Run $run, ExecutionStepType $type): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', $type->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle(
            $this->app->make(RunOrchestrator::class),
            checks: $this->app->make(RunCheckStep::class),
            reviews: $this->app->make(ReviewRound::class),
            reviewPrepare: $this->app->make(ReviewOnlyPrepareStep::class),
            reviewOnly: $this->app->make(ReviewOnlyRunCoordinator::class),
        );

        return $job->fresh() ?? $job;
    }

    /**
     * The real claim moves the bound ticket from `ready` to `in_progress`. The
     * fixture reprojects that generation through the guarded refresh seam so the
     * report-only status saga sees the state a claimed run leaves behind.
     *
     * @param  array{project: Project, administrator: User}  $managed
     */
    protected function markTicketInProgress(array $managed, Run $run): void
    {
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $project = $managed['project']->refresh();
        $model = TicketReadModel::query()
            ->where('project_id', $project->getKey())
            ->where('relative_path', $approval->relative_path)
            ->firstOrFail();
        $content = str_replace('status: ready', 'status: in_progress', (string) $model->redacted_content);

        $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $managed['administrator'],
            $project,
            $approval->relative_path,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('b', 32));
        self::assertIsInt($attemptToken);
        self::assertSame(1, TicketReadModel::query()->whereKey($model->getKey())->update([
            'control_operation_id' => $operation->id,
            'blob_sha' => hash('sha256', $content),
            'redacted_content' => $content,
            'generated_at' => now(),
            'updated_at' => now(),
        ]));
        $this->finishOperation($operation->refresh(), $attemptToken);
        DB::table('jobs')->delete();
    }

    /**
     * Put a finished step back into the one state a restarted worker really
     * redelivers: claimed with an expired lease.
     *
     * `ExecuteRunStep` returns before the step body for a terminal job, so
     * re-invoking a succeeded one proves nothing about idempotency.
     */
    protected function crashedStep(Run $run, ExecutionStepType $type): ExecutionJob
    {
        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', $type->value)->firstOrFail();
        self::assertSame(1, DB::table('execution_jobs')->where('id', $job->id)->update([
            'state' => ExecutionJobState::RUNNING->value,
            'lease_owner' => 'worker:crashed',
            'lease_expires_at' => now()->subMinute(),
            'updated_at' => now(),
        ]));

        return $job->fresh() ?? $job;
    }

    protected function assertStepSucceeded(ExecutionJob $job): void
    {
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
    }

    private function removeReviewOnlyTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            @chmod($child, is_dir($child) && ! is_link($child) ? 0700 : 0600);
            if (is_dir($child) && ! is_link($child)) {
                $this->removeReviewOnlyTree($child);
            } else {
                @unlink($child);
            }
        }
        @chmod($path, 0700);
        @rmdir($path);
    }
}
