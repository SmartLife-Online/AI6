<?php

namespace App\AI6\HumanLoop;

use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Auth\Models\User;
use App\AI6\Git\CanonicalJson;
use App\AI6\HumanLoop\Jobs\SendHumanRequestNotification;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\ScopePathLimitExceeded;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The exclusive creation and resolution seam for human requests. */
final readonly class HumanRequestService
{
    public const CANCEL_EFFECT = 'cancel';

    public function __construct(
        private HumanRequestClassifier $classifier,
        private Redactor $redactor,
        private RunOrchestrator $orchestrator,
        private ProjectPolicy $policy,
        private HumanRequestRecipient $recipient,
        private CanonicalJson $canonicalJson,
        private ?RunLimitPolicy $limits = null,
        private ?RunArtifactStore $artifacts = null,
    ) {}

    public function open(
        Run $run,
        HumanRequestProposal $proposal,
        string $agentSlotId,
        string $boundStepKey,
        ?WaitReason $waitReason = null,
        ?ImportLimitResult $limit = null,
    ): HumanRequest {
        $waitReason ??= $proposal->kind === 'resource_limit' ? WaitReason::RESOURCE_LIMIT : WaitReason::HUMAN_QUESTION;
        $classification = $this->classifier->classify($proposal);
        $approval = TicketApproval::query()->whereKey($run->ticket_approval_id)->firstOrFail();

        try {
            $request = DB::transaction(function () use ($run, $proposal, $classification, $approval, $agentSlotId, $boundStepKey, $waitReason, $limit): HumanRequest {
                DB::table('runs')->where('id', $run->getKey())->lockForUpdate()->first();
                $fresh = Run::query()->findOrFail($run->getKey());
                $this->assertBoundAttentionUser($approval, $fresh->project_id);
                if ($fresh->state === RunState::RUNNING) {
                    $fresh = $this->orchestrator->transition(
                        $fresh,
                        $fresh->version,
                        RunState::WAITING,
                        $fresh->phase,
                        $waitReason,
                    );
                } elseif ($fresh->state !== RunState::WAITING || $fresh->wait_reason !== $waitReason) {
                    throw new HumanRequestRejected(
                        'run_not_waiting_for_human',
                        'The run is not in a wait that can accept this request.',
                    );
                }

                // HUM-004 binds an answer to the checkpoint. A run without one
                // could only carry an empty binding, which is no binding at all,
                // so the request is refused instead of opened unanswerable.
                $checkpoint = (string) $fresh->checkpoint_tree_sha;
                if ($checkpoint === '') {
                    throw new HumanRequestRejected('checkpoint_not_bound', 'The run has no bound checkpoint an answer could be bound to.');
                }

                $context = new RedactionContext((string) $fresh->project_id, $fresh->id, 'human-request');
                $request = HumanRequest::query()->create([
                    'id' => (string) Str::uuid(),
                    'run_id' => $fresh->id,
                    'project_id' => $fresh->project_id,
                    'kind' => $classification->kind,
                    'response_mode' => $classification->responseMode,
                    'title' => $this->redact($proposal->title, $context),
                    'message' => $this->redact($proposal->message, $context),
                    'why_needed' => $this->redact($proposal->whyNeeded, $context),
                    'options' => array_map(
                        fn (HumanRequestOption $option): array => [
                            'key' => $option->key,
                            'label' => $this->redact($option->label, $context),
                        ],
                        $proposal->options,
                    ),
                    'recommended_option' => $proposal->recommendedOption,
                    'affected_paths' => array_map(
                        fn (string $path): string => $this->redact($path, $context),
                        $proposal->affectedPaths,
                    ),
                    'criterion_refs' => $proposal->criterionRefs,
                    'allowed_effects' => $classification->allowedEffects,
                    'required_action' => $classification->requiredAction->value,
                    'bound_run_version' => $fresh->version,
                    // A confirmed contract amendment moves the run's own
                    // contract binding past the approval's; the request must
                    // bind the contract that is effective now (TC-07).
                    'bound_ticket_contract' => $fresh->ticket_contract_sha256 ?? $approval->ticket_contract_sha256,
                    'bound_checkpoint' => $checkpoint,
                    'bound_scope' => $fresh->scope_hash,
                    'bound_agent_slot' => $agentSlotId,
                    'bound_requested_effect' => $classification->requestedEffectBinding,
                    'bound_step_key' => $boundStepKey,
                    'delivery_status' => HumanRequestDeliveryStatus::QUEUED,
                    'delivery_attempts' => 0,
                    'delivery_revision' => 1,
                    'delivery_status_changed_at' => now(),
                    'resolution_state' => HumanRequestResolutionState::OPEN,
                    'attention_user_id' => $approval->attention_user_id,
                ]);

                $job = ExecutionJob::query()
                    ->where('run_id', $fresh->id)
                    ->where('idempotency_key', $boundStepKey)
                    ->first();
                if (! $job instanceof ExecutionJob || ! $this->orchestrator->parkBoundStep($job)) {
                    throw new HumanRequestRejected(
                        'bound_step_not_parkable',
                        'The bound step could not be parked for the wait.',
                    );
                }
                if ($limit instanceof ImportLimitResult && $this->artifacts instanceof RunArtifactStore) {
                    $this->artifacts->store(
                        $fresh,
                        RunArtifactKind::LIMIT_PENDING,
                        json_encode([
                            'limit' => $limit->limit->value,
                            'observed' => $limit->observed,
                            'maximum' => $limit->maximum,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        [
                            'limit' => $limit->limit->value,
                            'observed' => $limit->observed,
                            'maximum' => $limit->maximum,
                        ],
                        new RedactionContext((string) $fresh->project_id, $fresh->id, 'resource-limit'),
                    );
                }

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new HumanRequestRejected(
                'open_request_exists',
                'An open blocking human request already exists for this run.',
            );
        } catch (InvalidRedactionInputException) {
            throw new HumanRequestRejected('invalid_utf8', 'The proposal text is not valid UTF-8.');
        } catch (RunTransitionConflict $conflict) {
            throw new HumanRequestRejected($conflict->reason, $conflict->getMessage());
        }

        $this->dispatchNotification($request);

        return $request;
    }

    public function redispatchNotification(HumanRequest $request): HumanRequest
    {
        $request = DB::transaction(function () use ($request): HumanRequest {
            DB::table('human_requests')->where('id', $request->getKey())->lockForUpdate()->first();
            $fresh = HumanRequest::query()->findOrFail($request->getKey());
            if ($fresh->resolution_state !== HumanRequestResolutionState::OPEN) {
                throw new HumanRequestRejected('request_already_resolved', 'The human request is already resolved.');
            }

            $fresh->forceFill([
                'delivery_revision' => $fresh->delivery_revision + 1,
                'delivery_status' => HumanRequestDeliveryStatus::QUEUED,
                'delivery_attempts' => 0,
                'delivery_failure_key' => null,
                'delivery_status_changed_at' => now(),
            ])->save();

            return $fresh;
        });

        $this->dispatchNotification($request);

        return $request;
    }

    public function answer(
        HumanRequest $request,
        User $user,
        int $runVersion,
        string $ticketContract,
        string $checkpoint,
        string $scope,
        string $agentSlot,
        string $requestedEffect,
        string $chosenEffect,
    ): Intervention {
        try {
            return DB::transaction(function () use (
                $request,
                $user,
                $runVersion,
                $ticketContract,
                $checkpoint,
                $scope,
                $agentSlot,
                $requestedEffect,
                $chosenEffect,
            ): Intervention {
                DB::table('human_requests')->where('id', $request->getKey())->lockForUpdate()->first();
                $fresh = HumanRequest::query()->findOrFail($request->getKey());
                $run = Run::query()->findOrFail($fresh->run_id);
                $project = $run->project()->firstOrFail();

                if (! $this->policy->decide(ProjectAction::ANSWER_HUMAN_REQUEST, $user, $project)) {
                    throw new HumanRequestRejected('unauthorized', 'The user is not permitted to answer this human request.');
                }
                if ($fresh->resolution_state !== HumanRequestResolutionState::OPEN) {
                    throw new HumanRequestRejected('request_already_resolved', 'The human request is already resolved.');
                }

                $this->assertBinding('stale_run_version', (string) $runVersion, (string) $fresh->bound_run_version);
                $this->assertBinding('stale_ticket_contract', $ticketContract, $fresh->bound_ticket_contract);
                $this->assertBinding('stale_checkpoint', $checkpoint, $fresh->bound_checkpoint);
                $this->assertBinding('stale_scope', $scope, $fresh->bound_scope);
                $this->assertBinding('stale_agent_slot', $agentSlot, $fresh->bound_agent_slot);
                $this->assertBinding('stale_requested_effect', $requestedEffect, $fresh->bound_requested_effect);

                $allowed = $fresh->allowed_effects;
                if ($chosenEffect !== self::CANCEL_EFFECT && ! in_array($chosenEffect, $allowed, true)) {
                    throw new HumanRequestRejected('effect_not_offered', 'The chosen effect is not a classified answer for this request.');
                }

                $intervention = Intervention::query()->create([
                    'id' => (string) Str::uuid(),
                    'human_request_id' => $fresh->id,
                    'user_id' => $user->getKey(),
                    'chosen_effect' => $chosenEffect,
                    'chosen_option_key' => $chosenEffect === self::CANCEL_EFFECT ? null : $chosenEffect,
                ]);

                $fresh->forceFill([
                    'resolution_state' => $chosenEffect === self::CANCEL_EFFECT
                        ? HumanRequestResolutionState::CANCELLED
                        : HumanRequestResolutionState::ANSWERED,
                    'resolved_at' => now(),
                ])->save();

                if ($chosenEffect === self::CANCEL_EFFECT) {
                    $this->orchestrator->cancelWait($run, $fresh->bound_run_version, $run->wait_reason ?? WaitReason::HUMAN_QUESTION);
                } else {
                    $expectedVersion = $fresh->bound_run_version;
                    if ($run->wait_reason === WaitReason::RESOURCE_LIMIT && $chosenEffect === 'increase') {
                        $this->applyLimitIncrease($run);
                    }
                    if ($run->wait_reason === WaitReason::SCOPE_APPROVAL) {
                        // A scope decision binds the run's own version (effective
                        // scope + counter), so both the run instance and the
                        // expected version handed to the wait resumption below
                        // must be the ones the scope decision itself produced.
                        $run = $this->applyScopeDecision($run, $fresh, $chosenEffect);
                        $expectedVersion = $run->version;
                    }
                    $this->orchestrator->resumeWait(
                        $run,
                        $expectedVersion,
                        $fresh->bound_step_key,
                        $run->wait_reason ?? WaitReason::HUMAN_QUESTION,
                    );
                }

                return $intervention;
            });
        } catch (UniqueConstraintViolationException) {
            throw new HumanRequestRejected(
                'request_already_resolved',
                'The human request already has an intervention.',
            );
        } catch (RunTransitionConflict $conflict) {
            throw new HumanRequestRejected($conflict->reason, $conflict->getMessage());
        }
    }

    private function assertBoundAttentionUser(TicketApproval $approval, int $projectId): void
    {
        if ($this->recipient->resolve($approval->attention_user_id, $projectId) === null) {
            throw new HumanRequestRejected(
                'attention_user_unavailable',
                'A human request requires a bound active attention user who may answer it.',
            );
        }
    }

    private function dispatchNotification(HumanRequest $request): void
    {
        $requestId = $request->id;
        $revision = $request->delivery_revision;
        DB::afterCommit(static function () use ($requestId, $revision): void {
            SendHumanRequestNotification::dispatch($requestId, $revision);
        });
    }

    private function redact(string $text, RedactionContext $context): string
    {
        return $this->redactor->redact($text, $context)->text;
    }

    private function assertBinding(string $reason, string $provided, string $expected): void
    {
        if (! hash_equals($expected, $provided)) {
            throw new HumanRequestRejected($reason, 'The answer binding does not match the stored request binding.');
        }
    }

    private function applyScopeDecision(Run $run, HumanRequest $request, string $chosenEffect): Run
    {
        $path = $request->affected_paths[0] ?? null;
        if (! is_string($path) || $path === '') {
            throw new HumanRequestRejected('scope_path_missing', 'The scope approval request has no bound path.');
        }
        if (! $this->limits instanceof RunLimitPolicy) {
            throw new HumanRequestRejected('limit_policy_unavailable', 'The scope-limit resolver is unavailable.');
        }
        $maxAddedScopePaths = $this->limits->effective($run)['max_added_scope_paths'];
        try {
            return $this->orchestrator->applyScopeDecision(
                $run,
                $path,
                $chosenEffect === 'approve',
                $request->id,
                $maxAddedScopePaths,
                $this->canonicalJson,
                $chosenEffect === 'approve' ? 'human_approved' : 'human_rejected',
                // The decision runs compare-and-swap against the run version the
                // request was opened under; a run that moved in between (for
                // example through a contract amendment) refuses the answer as
                // stale_run_version instead of applying it (AC-14, HUM-004).
                (int) $request->bound_run_version,
            );
        } catch (ScopePathLimitExceeded) {
            throw new HumanRequestRejected(
                'scope_path_limit_exceeded',
                'The approval would exceed the approved max_added_scope_paths limit.',
            );
        }
    }

    private function applyLimitIncrease(Run $run): void
    {
        if (! $this->limits instanceof RunLimitPolicy || ! $this->artifacts instanceof RunArtifactStore) {
            throw new HumanRequestRejected('limit_policy_unavailable', 'The resource-limit resolver is unavailable.');
        }
        $pending = RunArtifact::query()
            ->where('run_id', $run->getKey())
            ->where('kind', RunArtifactKind::LIMIT_PENDING->value)
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->first();
        if ($pending === null) {
            throw new HumanRequestRejected('limit_binding_missing', 'The exceeded limit binding is missing.');
        }
        $observed = $pending->redacted_metadata['observed'] ?? null;
        $maximum = $pending->redacted_metadata['maximum'] ?? null;
        $limitName = $pending->redacted_metadata['limit'] ?? null;
        if (! is_int($observed) || ! is_int($maximum) || ! is_string($limitName)) {
            throw new HumanRequestRejected('limit_binding_missing', 'The exceeded limit binding is incomplete.');
        }
        $limit = ImportLimit::tryFrom($limitName);
        if (! $limit instanceof ImportLimit) {
            throw new HumanRequestRejected('limit_binding_missing', 'The exceeded limit is not an import limit.');
        }
        $result = new ImportLimitResult($limit, $observed, $maximum);
        $raised = $this->limits->raiseToObserved($run, $result);
        if ($raised === null) {
            throw new HumanRequestRejected('limit_above_server_maximum', 'The requested increase exceeds the immutable server maximum.');
        }
        $this->artifacts->store(
            $run,
            RunArtifactKind::LIMIT_GRANT,
            json_encode($raised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $raised,
            new RedactionContext((string) $run->project_id, $run->id, 'resource-limit'),
        );
    }
}
