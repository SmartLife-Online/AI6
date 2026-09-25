<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentExecutionRunner;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitObjectFormat;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\HardenedGitEnvironment;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\PublishCandidateService;
use App\AI6\Git\ReviewSubject;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\Git\ReviewSubjectReference;
use App\AI6\Git\RunCheckpointService;
use App\AI6\Git\RunWorkspaceLifecycle;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalClaimStarter;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalQueue;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\PublishCompletionService;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunPreflight;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunType;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Checks\BuildsCheckFixture;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Agents\AgentMailboxFixture;

/** AI6-051/TC-06: all claimed effects use the worker against disposable remotes. */
final class GitObjectFormatWorkflowTest extends TicketUiTestCase
{
    use AssertsGitObjectGuards;
    use BuildsCheckFixture;
    use BuildsManagedControlRuntimeFixture;

    private RunType $workflowType = RunType::IMPLEMENTATION;

    private ?string $workflowReviewSubject = null;

    /** @return iterable<string, array{GitObjectFormat}> */
    public static function formats(): iterable
    {
        yield 'sha1' => [GitObjectFormat::SHA1];
        yield 'sha256' => [GitObjectFormat::SHA256];
    }

    #[DataProvider('formats')]
    public function test_fake_agent_reaches_real_runbranch_push_and_ticket_status_cas_after_gate_answer(GitObjectFormat $format): void
    {
        $this->runWorkflow($format, RunType::IMPLEMENTATION);
    }

    #[DataProvider('formats')]
    public function test_review_only_reaches_completed_after_real_report_status_cas(GitObjectFormat $format): void
    {
        $this->runWorkflow($format, RunType::REVIEW_ONLY);
    }

    #[DataProvider('formats')]
    public function test_a_tail_matching_publish_response_is_rejected_before_any_push(GitObjectFormat $format): void
    {
        $this->runWorkflow($format, RunType::IMPLEMENTATION, misleadingPublishResponse: true);
    }

    private function runWorkflow(GitObjectFormat $format, RunType $type, bool $misleadingPublishResponse = false): void
    {
        $this->workflowType = $type;
        $implementation = $type === RunType::IMPLEMENTATION;
        $guards = ['control_operations_insert_guard', 'control_operations_update_guard', 'review_results_insert_guard',
            'run_gates_candidate_update_guard', 'run_gates_update_guard', 'run_intervention_state_update_guard', 'runs_candidate_update_guard',
            'runs_insert_guard', 'runs_publish_completion_update_guard', 'runs_update_guard', 'ticket_approvals_insert_guard',
            'ticket_approvals_update_guard', 'ticket_mutations_insert_guard', 'ticket_mutations_update_guard',
            'ticket_read_models_insert_guard', 'ticket_read_models_update_guard'];
        if ($implementation) {
            $this->observeGitObjectGuards($guards);
        }
        Mail::fake();
        // Reuse the existing visible custom-profile test runtime for checks.
        // No check, review, candidate, gate or publish result is seeded.
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['--version'])]);
        config([
            'ai6.checks.profiles.probe-final' => $this->probeProfile(['--version'], phases: ['final']),
            'ai6.run_artifacts.root' => $this->implementationTemp('artifacts'),
            'ai6.execution_mailboxes.agent_root' => $this->implementationTemp('isolated'),
            'ai6.execution_mailboxes.agent_output_root' => $this->implementationTemp('agent-outputs'),
            'ai6.process.policies.agent.working_roots' => [$this->implementationTemp('isolated'), $this->implementationTemp('agent-outputs')],
        ]);
        // Migrations instantiate console services before these per-test roots exist.
        foreach ([ProcessPolicyRegistry::class, RunArtifactRoot::class, RunArtifactStore::class,
            RunImplementation::class, RunPreflight::class, AgentExecutionRunner::class, AgentExecutionProcessor::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $fixture = $this->managedFixture($format);
        $project = $fixture['project'];
        $admin = $fixture['administrator'];
        $approver = $this->createUser();
        $operator = $this->createUser();
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);
        $ticketId = 'AI6-051-FLOW';
        $path = 'tickets/'.$ticketId.'.md';
        $content = $this->implementationTicketMarkdown($ticketId, ['app/Example.php']);
        if ($implementation) {
            $content .= "\n\n## Manual and External Gates\n\n- **MG-01** Geändertes Verhalten bestätigen.\n";
        }
        mkdir($fixture['source'].'/app', 0700);
        file_put_contents($fixture['source'].'/app/Example.php', "<?php\n\n// initial\n");
        file_put_contents($fixture['source'].'/'.$path, $content);
        $this->managedFixtureGit(['add', 'app/Example.php', $path], $fixture['source']);
        $this->managedFixtureGit(['commit', '-m', 'workflow input'], $fixture['source']);
        $this->managedFixtureGit(['push', $fixture['remote'], 'HEAD:refs/heads/main'], $fixture['source']);
        $this->executeControl(app(QueueManagedCloneOperation::class)->handle($admin, $project, ControlOperationType::MANAGED_CLONE, (string) Str::uuid()));
        $this->executeControl(app(QueueTicketReadModelRefresh::class)->handle($admin, $project->refresh(), $path, (string) Str::uuid()));
        $readModel = TicketReadModel::query()->where('project_id', $project->id)->where('relative_path', $path)->sole();
        if (! $implementation) {
            $this->workflowReviewSubject = app(ReviewSubjectReference::class)->encode(new ReviewSubject(
                ReviewSubjectKind::COMMIT_RANGE, $readModel->control_commit, $readModel->control_commit,
            ));
        }
        $id = (string) Str::uuid();
        $selection = $this->approvalSelection($approver);
        $snapshot = app(ApprovalSnapshotFactory::class)->create($project, $readModel, $selection, $id);
        $this->executeControl(app(QueueTicketMutation::class)->approve(
            $approver, $project, $readModel, $id, $readModel->control_commit, $readModel->blob_sha,
            $content, 'Formatdurchlauf freigeben', true, $selection, $snapshot, $id,
        ));
        $approval = TicketApproval::query()->findOrFail($id);
        $approval = app(ApprovalQueue::class)->enqueue($project->refresh(), $id, $approval->version);
        $this->executeControl(app(ApprovalClaimStarter::class)->start($operator, $project, $approval->id, (string) Str::uuid()));
        $run = Run::query()->where('project_id', $project->id)->sole();
        self::assertStringContainsString('status: in_progress', $this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'show', 'refs/heads/main:'.$path], $fixture['root']));
        if ($implementation) {
            $this->app->forgetInstance(RunWorkspaceLifecycle::class);
            $this->app->forgetInstance(RunCheckpointService::class);
            $context = new RedactionContext((string) $project->id, $run->id, 'format-workflow-prepare');
            $run = app(RunWorkspaceLifecycle::class)->create($run, $project->project_identifier, $context);
            $run = app(RunCheckpointService::class)->create($run, $project->project_identifier, $context);
            if (! $misleadingPublishResponse) {
                $wrapper = $fixture['root'].'/ssh-wrapper';
                $script = file_get_contents($wrapper);
                self::assertIsString($script);
                self::assertTrue(chmod($wrapper, 0700));
                file_put_contents($wrapper, str_replace('set -eu', "set -eu\nprintf 'INFO: host IP added to known hosts\\n' >&2", $script));
                self::assertTrue(chmod($wrapper, 0555));
                $probe = $fixture['runner']->probeRemote(
                    $project->remote, (string) $run->run_branch, $run->worktree_path,
                    $project->deploy_key_reference, $fixture['root'].'/known_hosts', $project->host_key_fingerprint, $context,
                );
                self::assertSame(2, $probe->exitCode);
                self::assertSame('', $probe->output);
                self::assertStringContainsString('INFO: host IP added to known hosts', $probe->errorOutput);
            }
        }
        $adapter = new FakeAgentAdapter;
        $this->app->instance(FakeAgentAdapter::class, $adapter);
        $this->app->bind(AgentAdapter::class, fn (): AgentAdapter => $adapter);
        $gateAnswered = false;
        $answeredRequest = null;
        $answeredPayload = null;
        for ($step = 0; $step < 30 && $run->refresh()->state !== RunState::COMPLETED; $step++) {
            if ($run->pending_status_operation_id !== null) {
                $this->executeControl(ControlOperation::query()->findOrFail($run->pending_status_operation_id));

                continue;
            }
            if ($run->state === RunState::WAITING) {
                self::assertSame(WaitReason::MANUAL_GATE, $run->wait_reason, json_encode($run->only(['state', 'phase', 'wait_reason'])));
                self::assertFalse($gateAnswered);
                $request = HumanRequest::query()->where('run_id', $run->id)->where('resolution_state', 'open')->sole();
                $candidate = app(PublishCandidateService::class)->prospect($run);
                self::assertSame($candidate->treeOid.':'.$candidate->diffHash, $request->bound_requested_effect);
                $payload = $this->answerPayload($request);
                $this->postAnswer($approver, $project, $request, [...$payload, 'requested_effect' => str_repeat('f', $format->length()).':'.$candidate->diffHash])
                    ->assertSessionHasErrors('chosen_effect');
                self::assertSame('open', RunGate::query()->where('run_id', $run->id)->where('gate_id', 'MG-01')->sole()->state->value);
                $this->postAnswer($approver, $project, $request, $payload)->assertRedirect()->assertSessionHasNoErrors();
                $this->postAnswer($approver, $project, $request, $payload)->assertSessionHasErrors('chosen_effect');
                $gateAnswered = true;
                $answeredRequest = $request;
                $answeredPayload = $payload;

                continue;
            }
            $job = ExecutionJob::query()->where('run_id', $run->id)->where('state', ExecutionJobState::PLANNED->value)->orderBy('id')->first();
            self::assertInstanceOf(ExecutionJob::class, $job, json_encode($run->only(['state', 'phase', 'wait_reason'])));
            if ($job->step_type === ExecutionStepType::PUBLISH->value) {
                self::assertInstanceOf(HumanRequest::class, $answeredRequest);
                self::assertIsArray($answeredPayload);
                $versionBeforeReplay = $run->version;
                $this->postAnswer($approver, $project, $answeredRequest, $answeredPayload)->assertSessionHasErrors('chosen_effect');
                self::assertSame($versionBeforeReplay, $run->refresh()->version);
            }
            $rejectProbe = $misleadingPublishResponse && $job->step_type === ExecutionStepType::PUBLISH->value;
            if ($rejectProbe) {
                self::assertTrue($gateAnswered);
                $remoteBefore = $this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'show-ref'], $fixture['root']);
                $this->misleadPublishProbe($fixture['root'], $run);
            }
            $dispatch = fn () => $this->app->call([new ExecuteRunStep($job->id), 'handle']);
            $bindingBefore = $run->only(['version', 'phase', 'candidate_tree_sha', 'candidate_diff_hash']);
            $gatesBefore = RunGate::query()->where('run_id', $run->id)
                ->get(['gate_id', 'state', 'evidence_expected_run_version'])->toArray();
            $dispatch();
            AgentMailboxFixture::drain($job, $dispatch);
            if ($rejectProbe) {
                self::assertSame(ExecutionJobState::FAILED, $job->refresh()->state);
                self::assertSame('remote_probe_failed', $job->failure_code);
                self::assertSame(RunState::FAILED, $run->refresh()->state);
                self::assertNull($run->confirmed_branch_publication_oid);
                self::assertNull($run->pending_status_operation_id);
                self::assertFileExists($fixture['root'].'/publish-probe-seen');
                $dispatch();
                self::assertFileDoesNotExist($fixture['root'].'/push-attempted');
                self::assertSame($remoteBefore, $this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'show-ref'], $fixture['root']));

                return;
            }
            self::assertNotSame(ExecutionJobState::FAILED, $job->refresh()->state, $job->step_type.':'.$job->failure_code
                .' '.json_encode(['run_before' => $bindingBefore, 'gates_before' => $gatesBefore]));
        }
        self::assertFalse($misleadingPublishResponse, 'The run must reach the negative publish probe.');
        self::assertSame($implementation, $gateAnswered);
        self::assertSame(RunState::COMPLETED, $run->refresh()->state);
        self::assertSame($format, $project->refresh()->object_format);
        if ($implementation) {
            self::assertSame($format->zeroOid(), $run->branch_publication_expected_oid);
            self::assertSame($run->final_commit_oid, trim($this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'rev-parse', $run->run_branch], $fixture['root'])));
            self::assertSame($format->length(), strlen($run->final_commit_oid));
            self::assertSame($run->candidate_tree_sha, trim($this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'rev-parse', $run->run_branch.'^{tree}'], $fixture['root'])));
        } else {
            self::assertNull($run->run_branch);
            self::assertNull($run->final_commit_oid);
            self::assertSame(1, DB::table('run_artifacts')->where('run_id', $run->id)->where('kind', 'completion_report')->count());
        }
        $publishedTicket = $this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'show', 'refs/heads/main:'.$path], $fixture['root']);
        $completedStatus = $implementation ? 'review' : 'ready';
        self::assertStringContainsString('status: '.$completedStatus, $publishedTicket);
        $statusMutation = TicketMutation::query()->where('source_status', 'in_progress')->where('target_status', $completedStatus)->sole();
        $statusOperation = ControlOperation::query()->findOrFail($statusMutation->status_operation_id);
        self::assertSame(ControlOperationState::COMPLETED, $statusOperation->state);
        self::assertSame($statusMutation->prepared_commit_oid, $project->refresh()->control_oid);
        self::assertSame($statusMutation->prepared_commit_oid, trim($this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'rev-parse', 'refs/heads/main'], $fixture['root'])));
        if ($implementation) {
            self::assertStringContainsString('## Recorded Scope', $publishedTicket);
            self::assertSame(hash('sha256', $publishedTicket), $run->recorded_scope_sha256);
        }
        self::assertGreaterThanOrEqual($implementation ? 3 : 1, $adapter->turnCount);
        foreach (ReviewResult::query()->where('run_id', $run->id)->get() as $result) {
            self::assertSame($format->length(), strlen($result->checkpoint_tree_sha));
            self::assertSame(64, strlen($result->workspace_tree_hash));
            self::assertNotSame($result->checkpoint_tree_sha, $result->workspace_tree_hash);
        }
        $remoteBefore = $this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'show-ref'], $fixture['root']);
        foreach (ExecutionJob::query()->where('run_id', $run->id)->get() as $job) {
            $this->app->call([new ExecuteRunStep($job->id), 'handle']);
        }
        self::assertSame($remoteBefore, $this->managedFixtureGit(['--git-dir='.$fixture['remote'], 'show-ref'], $fixture['root']));
        if ($implementation) {
            $this->assertGitObjectGuardsObserved($guards);
        }
    }

    private function misleadPublishProbe(string $root, Run $run): void
    {
        $configuration = app(GitConfiguration::class);
        $wrapper = $root.'/git-publish-probe';
        $response = $run->checkpoint_commit_sha."\trefs/heads/foreign\n"
            .$run->checkpoint_commit_sha."\t".$run->run_branch."\n";
        $script = <<<'SH'
#!/bin/sh
set -eu
for argument do
    if [ "$argument" = "ls-remote" ]; then
        printf seen > __PROBE__
        printf '%s' __RESPONSE__
        exit 0
    fi
    if [ "$argument" = "push" ]; then
        printf attempted > __PUSH__
        exit 97
    fi
done
exec __GIT__ "$@"
SH;
        file_put_contents($wrapper, strtr($script, [
            '__PROBE__' => escapeshellarg($root.'/publish-probe-seen'),
            '__PUSH__' => escapeshellarg($root.'/push-attempted'),
            '__RESPONSE__' => escapeshellarg($response),
            '__GIT__' => escapeshellarg($configuration->gitBinary),
        ]));
        chmod($wrapper, 0555);
        $probeConfiguration = new GitConfiguration(
            $wrapper, $configuration->sshBinary, $configuration->executablePath,
            $configuration->sshWrapper, $configuration->executionHome, $configuration->xdgConfigHome,
            $configuration->globalConfig, $configuration->hooksPath, $configuration->allowedHosts,
            $configuration->allowedRemotePaths, $configuration->allowedRefPatterns,
            $configuration->pinnedHostKeyFingerprints,
        );
        $this->app->instance(HardenedGitRunner::class, new HardenedGitRunner(
            app(ControlProcessRunner::class), app(GitRemotePolicy::class), new HardenedGitEnvironment($probeConfiguration),
        ));
        $this->app->forgetInstance(PublishCompletionService::class);
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        return new ApprovalSelection(
            app(AgentProfileRegistry::class)->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            app(ReviewerSlotFactory::class)->fromArray([['id' => (string) Str::uuid(), 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'high', 'prompt_profile' => 'security']]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), app(AgentInputLimits::class)),
            $attentionUser?->id, 'automatic_after_gates',
            $this->workflowType, $this->workflowReviewSubject,
            $this->workflowType === RunType::REVIEW_ONLY ? ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES : null,
        );
    }

    private function executeControl(ControlOperation $operation): void
    {
        DB::table('jobs')->delete();
        app(ControlOperationExecutor::class)->execute($operation->id);
        self::assertSame(ControlOperationState::COMPLETED, $operation->refresh()->state, json_encode($operation->result()->first()?->getAttributes()));
    }

    /** @return array<string, int|string> */
    private function answerPayload(HumanRequest $request): array
    {
        return [
            'run_version' => $request->bound_run_version, 'ticket_contract' => $request->bound_ticket_contract,
            'checkpoint' => $request->bound_checkpoint, 'scope' => $request->bound_scope,
            'agent_slot' => $request->bound_agent_slot, 'requested_effect' => $request->bound_requested_effect,
            'chosen_effect' => 'authorize_gate_evidence',
        ];
    }

    /** @param array<string, int|string> $payload
     * @return TestResponse<Response>
     */
    private function postAnswer(User $actor, Project $project, HumanRequest $request, array $payload): TestResponse
    {
        $this->actingAs($actor);
        $session = $this->app->make('session')->driver();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        app(StepUpGuard::class)->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);
        $session->save();

        return $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.human-requests.answer', [$project, $request->id]), $payload);
    }
}
