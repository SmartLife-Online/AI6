<?php

namespace App\AI6\HumanLoop;

use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Auth\Models\User;
use App\AI6\Git\CanonicalJson;
use App\AI6\HumanLoop\Jobs\SendHumanRequestNotification;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Reviews\FindingDispositionType;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\VerifierCandidate;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The exclusive creation and resolution seam for human requests. */
final readonly class HumanRequestService
{
    public const CANCEL_EFFECT = 'cancel';

    /**
     * switch_profile carries the same effect as switch_reviewer — a new slot
     * revision through switchBoundReviewer — and is authorized identically.
     *
     * @var list<string>
     */
    private const STEP_UP_EFFECTS = ['increase', 'additional_round', 'switch_reviewer', 'switch_profile', 'finding_disposition'];

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

    public function openLimitRequest(
        Run $run,
        ExecutionJob $job,
        ImportLimitResult $limit,
        WaitReason $reason,
    ): HumanRequest {
        $reviewLimit = $reason === WaitReason::REVIEW_LIMIT;
        $options = $reviewLimit
            ? [
                new HumanRequestOption('additional_round', 'Genau eine zusätzliche Runde'),
                new HumanRequestOption('switch_reviewer', 'Reviewer oder Modell wechseln'),
                new HumanRequestOption('finding_disposition', 'Finding-Disposition erfassen'),
            ]
            : [
                new HumanRequestOption('reduce', 'Verbrauch reduzieren'),
                new HumanRequestOption('increase', 'Limit bis zum Servermaximum erhöhen'),
            ];

        return $this->open(
            $run,
            new HumanRequestProposal(
                $reason->value,
                $reviewLimit ? 'Reviewgrenze erreicht' : 'Ressourcengrenze erreicht',
                'Der gebundene Schritt wurde vor seiner Wirkung angehalten.',
                'Die serverseitig freigegebene Grenze '.$limit->limit->value.' wurde erreicht.',
                'select',
                $options,
                $reviewLimit ? 'additional_round' : 'reduce',
                [],
                [],
            ),
            $reviewLimit ? $this->activeSlotForStep($run, $job) : 'system:'.$job->step_type,
            $job->idempotency_key,
            $reason,
            $limit,
        );
    }

    public function openFailureRequest(Run $run, ExecutionJob $job, WaitReason $reason, ?string $agentSlotId = null): HumanRequest
    {
        if (! in_array($reason, [WaitReason::PROVIDER_ERROR, WaitReason::INVALID_JSON], true)) {
            throw new HumanRequestRejected('failure_wait_invalid', 'The failure wait reason is not supported.');
        }
        $slotId = $agentSlotId ?? 'system:'.$job->step_type;
        $reviewSlot = $run->agents()->where('slot_id', $slotId)->whereIn('role', ['quality_review', 'finding_verification'])->exists();
        $options = $reason === WaitReason::PROVIDER_ERROR
            ? [new HumanRequestOption('retry', 'Idempotent erneut versuchen')]
            : [new HumanRequestOption('new_turn', 'Neuen gebundenen Turn starten')];
        if ($reviewSlot) {
            $options[] = new HumanRequestOption('switch_profile', 'Freigegebenes Profil wechseln');
        }

        return $this->open(
            $run,
            new HumanRequestProposal(
                $reason->value,
                $reason === WaitReason::PROVIDER_ERROR ? 'Providerfehler' : 'Ungültiges Agentenergebnis',
                'Der gebundene Schritt wurde ohne Teilwirkung angehalten.',
                'Der vorhandene Attemptvertrag ist ausgeschöpft.',
                'select',
                $options,
                $options[0]->key,
                [],
                [],
            ),
            $slotId,
            $job->idempotency_key,
            $reason,
        );
    }

    public function openGitConflictRequest(Run $run, string $agentSlotId, string $boundStepKey): HumanRequest
    {
        return $this->open(
            $run,
            new HumanRequestProposal(
                WaitReason::GIT_CONFLICT->value,
                'Status-Compare-and-Swap in Konflikt',
                'Der Run und seine Projektsperre bleiben bis zu einer neuen autorisierten Entscheidung bestehen.',
                'Die erwartete Control-OID hat sich vor der Statusveröffentlichung geändert.',
                'select',
                [new HumanRequestOption('refresh_expected_oid', 'OID aktualisieren und Entscheidung erneut autorisieren')],
                'refresh_expected_oid',
                [],
                [],
            ),
            $agentSlotId,
            $boundStepKey,
            WaitReason::GIT_CONFLICT,
        );
    }

    /**
     * Open the intervention request for a run parked behind git_base_changed.
     *
     * The registered resolver of this wait is the controlled abort through the
     * status-mutation saga; without an open request the panel could never offer
     * it and only a database intervention could end the run (AC-05, HUM-005).
     * The request binds the run's current non-terminal step.
     */
    public function openBaseDriftRequest(Run $run): HumanRequest
    {
        $job = ExecutionJob::query()->where('run_id', $run->getKey())->get()
            ->sortBy([['step_number', 'desc'], ['id', 'desc']])
            ->first(static fn (ExecutionJob $candidate): bool => ! in_array(
                $candidate->state,
                [ExecutionJobState::SUCCEEDED, ExecutionJobState::FAILED],
                true,
            ));
        if (! $job instanceof ExecutionJob) {
            throw new HumanRequestRejected('bound_step_not_parkable', 'The drifted run has no bound step the request could park.');
        }

        return $this->open(
            $run,
            new HumanRequestProposal(
                WaitReason::GIT_BASE_CHANGED->value,
                'Control-Basis hat sich geändert',
                'Der Run und seine Projektsperre bleiben bis zu einer autorisierten Abbruchentscheidung bestehen.',
                'Die Control-Basis des Runs entspricht nicht mehr dem gebundenen Stand.',
                'select',
                [new HumanRequestOption('controlled_abort', 'Kontrollierter Abbruch über die Statusmutations-Saga')],
                'controlled_abort',
                [],
                [],
            ),
            'system:'.$job->step_type,
            $job->idempotency_key,
            WaitReason::GIT_BASE_CHANGED,
        );
    }

    /** Open the report confirmation without inventing an execution-job producer. */
    public function openManualReportRequest(Run $run): HumanRequest
    {
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        try {
            $request = DB::transaction(function () use ($run, $approval): HumanRequest {
                DB::table('runs')->where('id', $run->id)->lockForUpdate()->first();
                $fresh = Run::query()->findOrFail($run->id);
                $this->assertBoundAttentionUser($approval, $fresh->project_id);
                if ($fresh->state === RunState::RUNNING) {
                    $fresh = $this->orchestrator->transition(
                        $fresh,
                        $fresh->version,
                        RunState::WAITING,
                        $fresh->phase,
                        WaitReason::MANUAL_REPORT,
                    );
                } elseif ($fresh->state !== RunState::WAITING || $fresh->wait_reason !== WaitReason::MANUAL_REPORT) {
                    throw new HumanRequestRejected('manual_report_not_waiting', 'The run cannot open a report confirmation request.');
                }
                if ($fresh->checkpoint_tree_sha === null || $fresh->checkpoint_diff_hash === null) {
                    throw new HumanRequestRejected('checkpoint_not_bound', 'The report confirmation requires a bound review checkpoint.');
                }
                $effect = hash('sha256', implode(':', [
                    $fresh->checkpoint_tree_sha,
                    $fresh->checkpoint_diff_hash,
                    $fresh->agent_profile_hash,
                    $fresh->evidence_epoch,
                ]));
                $context = new RedactionContext((string) $fresh->project_id, $fresh->id, 'manual-report');
                $request = HumanRequest::query()->create([
                    'id' => (string) Str::uuid(),
                    'run_id' => $fresh->id,
                    'project_id' => $fresh->project_id,
                    'kind' => WaitReason::MANUAL_REPORT->value,
                    'response_mode' => 'select',
                    'title' => $this->redact('Reviewbericht abschließen', $context),
                    'message' => $this->redact('Der gebundene Reviewstand ist vollständig und kann status-only abgeschlossen werden.', $context),
                    'why_needed' => $this->redact('Der freigegebene Abschlussmodus verlangt eine menschliche Bestätigung.', $context),
                    'options' => [['key' => 'confirm_report', 'label' => 'Gebundenen Bericht abschließen']],
                    'recommended_option' => 'confirm_report',
                    'affected_paths' => [],
                    'criterion_refs' => [],
                    'allowed_effects' => ['confirm_report'],
                    'required_action' => ProjectAction::ANSWER_HUMAN_REQUEST->value,
                    'bound_run_version' => $fresh->version,
                    'bound_ticket_contract' => $fresh->ticket_contract_sha256 ?? $approval->ticket_contract_sha256,
                    'bound_checkpoint' => $fresh->checkpoint_tree_sha,
                    'bound_scope' => $fresh->scope_hash,
                    'bound_agent_slot' => ReportOnlyHumanRequestBinding::AGENT_SLOT,
                    'bound_requested_effect' => $effect,
                    'bound_step_key' => ReportOnlyHumanRequestBinding::completionStepKey($fresh->id),
                    'delivery_status' => HumanRequestDeliveryStatus::QUEUED,
                    'delivery_attempts' => 0,
                    'delivery_revision' => 1,
                    'delivery_status_changed_at' => now(),
                    'resolution_state' => HumanRequestResolutionState::OPEN,
                    'attention_user_id' => $approval->attention_user_id,
                ]);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = HumanRequest::query()->where('run_id', $run->id)
                ->where('resolution_state', HumanRequestResolutionState::OPEN->value)->first();
            if (! $existing instanceof HumanRequest || $existing->kind !== WaitReason::MANUAL_REPORT->value) {
                throw new HumanRequestRejected('open_request_exists', 'An open blocking human request already exists for this run.');
            }

            return $existing;
        }
        $this->dispatchNotification($request);

        return $request;
    }

    /** Open a report-only CAS conflict decision without an execution-job producer. */
    public function openReportStatusConflictRequest(Run $run): HumanRequest
    {
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        try {
            $request = DB::transaction(function () use ($run, $approval): HumanRequest {
                DB::table('runs')->where('id', $run->id)->lockForUpdate()->first();
                $fresh = Run::query()->findOrFail($run->id);
                $this->assertBoundAttentionUser($approval, $fresh->project_id);
                if ($fresh->state !== RunState::WAITING || $fresh->wait_reason !== WaitReason::GIT_CONFLICT
                    || $fresh->pending_status_operation_id !== null
                    || $fresh->checkpoint_tree_sha === null || $fresh->checkpoint_diff_hash === null) {
                    throw new HumanRequestRejected(
                        'report_status_conflict_not_waiting',
                        'The run cannot open a report status conflict decision.',
                    );
                }
                $effect = hash('sha256', implode(':', [
                    $fresh->checkpoint_tree_sha,
                    $fresh->checkpoint_diff_hash,
                    $fresh->agent_profile_hash,
                    $fresh->evidence_epoch,
                ]));
                $context = new RedactionContext((string) $fresh->project_id, $fresh->id, 'report-status-conflict');
                $request = HumanRequest::query()->create([
                    'id' => (string) Str::uuid(),
                    'run_id' => $fresh->id,
                    'project_id' => $fresh->project_id,
                    'kind' => WaitReason::GIT_CONFLICT->value,
                    'response_mode' => 'select',
                    'title' => $this->redact('Statusabgleich in Konflikt', $context),
                    'message' => $this->redact('Der report-only Abschluss benötigt eine neue autorisierte Compare-and-Swap-Entscheidung.', $context),
                    'why_needed' => $this->redact('Die zuvor gebundene Control-OID ist nicht mehr aktuell.', $context),
                    'options' => [['key' => 'refresh_expected_oid', 'label' => 'OID aktualisieren und Abschluss erneut autorisieren']],
                    'recommended_option' => 'refresh_expected_oid',
                    'affected_paths' => [],
                    'criterion_refs' => [],
                    'allowed_effects' => ['refresh_expected_oid'],
                    'required_action' => ProjectAction::ANSWER_HUMAN_REQUEST->value,
                    'bound_run_version' => $fresh->version,
                    'bound_ticket_contract' => $fresh->ticket_contract_sha256 ?? $approval->ticket_contract_sha256,
                    'bound_checkpoint' => $fresh->checkpoint_tree_sha,
                    'bound_scope' => $fresh->scope_hash,
                    'bound_agent_slot' => ReportOnlyHumanRequestBinding::AGENT_SLOT,
                    'bound_requested_effect' => $effect,
                    'bound_step_key' => ReportOnlyHumanRequestBinding::statusConflictStepKey($fresh->id),
                    'delivery_status' => HumanRequestDeliveryStatus::QUEUED,
                    'delivery_attempts' => 0,
                    'delivery_revision' => 1,
                    'delivery_status_changed_at' => now(),
                    'resolution_state' => HumanRequestResolutionState::OPEN,
                    'attention_user_id' => $approval->attention_user_id,
                ]);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = HumanRequest::query()->where('run_id', $run->id)
                ->where('resolution_state', HumanRequestResolutionState::OPEN->value)->first();
            if (! $existing instanceof HumanRequest || $existing->kind !== WaitReason::GIT_CONFLICT->value) {
                throw new HumanRequestRejected('open_request_exists', 'An open blocking human request already exists for this run.');
            }

            return $existing;
        }
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
        ?InterventionAuthorization $authorization = null,
        ?string $findingId = null,
        ?string $findingDisposition = null,
        ?string $reason = null,
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
                $authorization,
                $findingId,
                $findingDisposition,
                $reason,
            ): Intervention {
                DB::table('human_requests')->where('id', $request->getKey())->lockForUpdate()->first();
                $fresh = HumanRequest::query()->findOrFail($request->getKey());
                $run = Run::query()->findOrFail($fresh->run_id);
                $project = $run->project()->firstOrFail();

                if (! $this->policy->decide(ProjectAction::ANSWER_HUMAN_REQUEST, $user, $project)) {
                    throw new HumanRequestRejected('unauthorized', 'The user is not permitted to answer this human request.');
                }
                $membership = ProjectMembership::query()->where('project_id', $project->getKey())
                    ->where('user_id', $user->getKey())->first();
                if (! $membership instanceof ProjectMembership) {
                    throw new HumanRequestRejected('unauthorized', 'The user has no project membership.');
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
                // controlled_abort and refresh_expected_oid are marker effects:
                // both waits resolve exclusively through the re-authorized,
                // status-operation-bound cancellation saga, never through a
                // plain answer that would resume the parked step (plan §7.2).
                if (in_array($chosenEffect, [self::CANCEL_EFFECT, 'controlled_abort', 'confirm_report', 'refresh_expected_oid'], true)) {
                    throw new HumanRequestRejected('legacy_cancel_forbidden', 'Cancellation must use the status-operation-bound cancellation saga.');
                }
                if (! in_array($chosenEffect, $allowed, true)) {
                    throw new HumanRequestRejected('effect_not_offered', 'The chosen effect is not a classified answer for this request.');
                }
                $requiresStepUp = self::requiresStepUp($chosenEffect);
                if ($requiresStepUp && ! $authorization instanceof InterventionAuthorization) {
                    throw new HumanRequestRejected('step_up_required', 'The selected intervention requires a fresh step-up proof.');
                }

                $expectedVersion = $fresh->bound_run_version;
                $auditReason = 'Gebundene Panelantwort.';
                if ($chosenEffect === 'finding_disposition') {
                    $type = is_string($findingDisposition) ? FindingDispositionType::tryFrom($findingDisposition) : null;
                    $finding = is_string($findingId)
                        ? Finding::query()->whereKey($findingId)->where('run_id', $run->id)->first()
                        : null;
                    if (! $finding instanceof Finding || ! $type instanceof FindingDispositionType
                        || ! $authorization instanceof InterventionAuthorization || trim((string) $reason) === '') {
                        throw new HumanRequestRejected('finding_disposition_invalid', 'The finding disposition input is incomplete.');
                    }
                    $auditReason = trim($this->redact(
                        (string) $reason,
                        new RedactionContext((string) $run->project_id, $run->id, 'finding-disposition'),
                    ));
                    $run = $this->orchestrator->recordHumanFindingDisposition(
                        $run,
                        $finding,
                        $expectedVersion,
                        $type,
                        $auditReason,
                        $user,
                        $authorization->proofHash,
                    );
                    $expectedVersion = $run->version;
                }

                $intervention = Intervention::query()->create([
                    'id' => (string) Str::uuid(),
                    'human_request_id' => $fresh->id,
                    'user_id' => $user->getKey(),
                    'actor_role' => $membership->role->value,
                    'step_up_verified' => $requiresStepUp,
                    'step_up_proof_hash' => $authorization?->proofHash,
                    'chosen_effect' => $chosenEffect,
                    'chosen_option_key' => $chosenEffect,
                    'expected_run_version' => $fresh->bound_run_version,
                    'wait_reason' => ($run->wait_reason ?? WaitReason::HUMAN_QUESTION)->value,
                    'bound_step_key' => $fresh->bound_step_key,
                    'reason' => $auditReason,
                    'idempotency_key' => hash('sha256', implode(':', [
                        $fresh->id, $chosenEffect, $fresh->bound_run_version,
                    ])),
                ]);

                $fresh->forceFill([
                    'resolution_state' => HumanRequestResolutionState::ANSWERED,
                    'resolved_at' => now(),
                ])->save();

                if (($run->wait_reason === WaitReason::RESOURCE_LIMIT && $chosenEffect === 'increase')
                    || ($run->wait_reason === WaitReason::REVIEW_LIMIT && $chosenEffect === 'additional_round')) {
                    $this->applyLimitIncrease($run, $chosenEffect);
                }
                if (in_array($chosenEffect, ['switch_reviewer', 'switch_profile'], true)) {
                    $this->switchBoundReviewer($run, $fresh->bound_agent_slot);
                } elseif ($chosenEffect === 'new_turn') {
                    $role = $run->agents()->where('slot_id', $fresh->bound_agent_slot)->value('role');
                    if ($role === 'quality_review') {
                        $this->orchestrator->discardReviewSession($run, $fresh->bound_agent_slot);
                    } elseif ($role === 'finding_verification') {
                        $this->orchestrator->discardVerifierSession($run, $fresh->bound_agent_slot);
                    } else {
                        $this->orchestrator->discardImplementationSessions($run);
                    }
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

    public static function requiresStepUp(string $effect): bool
    {
        return in_array($effect, self::STEP_UP_EFFECTS, true);
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

    private function applyLimitIncrease(Run $run, string $chosenEffect): void
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
        $raised = $chosenEffect === 'additional_round'
            ? $this->limits->raiseOne($run, $limit)
            : $this->limits->raiseToObserved($run, $result);
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

    private function activeSlotForStep(Run $run, ExecutionJob $job): string
    {
        $role = ExecutionStepType::tryFrom($job->step_type) === ExecutionStepType::VERIFY
            ? 'finding_verification'
            : 'quality_review';
        $slot = $run->agents()->where('role', $role)->where('is_active', true)
            ->orderBy('slot_revision')->value('slot_id');

        return is_string($slot) && $slot !== '' ? $slot : 'system:'.$job->step_type;
    }

    private function switchBoundReviewer(Run $run, string $slotId): void
    {
        $activeSlotId = DB::table('run_agents')->where('run_id', $run->id)->where('slot_id', $slotId)
            ->whereIn('role', ['quality_review', 'finding_verification'])
            ->where('is_active', true)->value('id');
        $slot = is_int($activeSlotId) ? RunAgent::query()->find($activeSlotId) : null;
        if (! $slot instanceof RunAgent) {
            throw new HumanRequestRejected('reviewer_slot_not_active', 'The bound reviewer slot is no longer active.');
        }
        $candidateKey = $slot->role === 'finding_verification' ? 'verifier_candidates' : 'reviewers';
        $sourceProviders = [];
        $implementationProfile = null;
        if ($slot->role === 'finding_verification') {
            $logicalSlot = $slot->approval_slot_id ?? $slot->slot_id;
            $slotIds = RunAgent::query()->where('run_id', $run->id)
                ->where('approval_slot_id', $logicalSlot)->pluck('slot_id');
            $verificationQuery = ReviewResult::query()->where('run_id', $run->id)->whereIn('slot_id', $slotIds)
                ->where(static function (Builder $query): void {
                    $query->whereNotNull('original_finding_id')->orWhereNotNull('original_duplicate_group');
                })->orderByDesc('attempt');
            $findingId = $verificationQuery->value('original_finding_id');
            $duplicateGroup = is_string($findingId) ? null : $verificationQuery->value('original_duplicate_group');
            $sourceProviders = is_string($findingId)
                ? Finding::query()->whereKey($findingId)->pluck('provider_profile')->filter(static fn (mixed $value): bool => is_string($value))->all()
                : (is_string($duplicateGroup)
                    ? Finding::query()->where('run_id', $run->id)->where('duplicate_group', $duplicateGroup)->pluck('provider_profile')->filter(static fn (mixed $value): bool => is_string($value))->unique()->values()->all()
                    : []);
            $implementationProfile = ($run->agent_profile_snapshot ?? [])['implementation']['profile_id'] ?? null;
        }
        foreach (($run->agent_profile_snapshot ?? [])[$candidateKey] ?? [] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $values = [
                $candidate['provider_profile'] ?? null,
                $candidate['model'] ?? null,
                $candidate['effort'] ?? null,
                $candidate['prompt_profile_id'] ?? ($slot->role === 'finding_verification' ? 'finding_verification' : null),
            ];
            if (! array_reduce($values, static fn (bool $valid, mixed $value): bool => $valid && is_string($value) && $value !== '', true)
                || ($values[0] === $slot->provider_profile && $values[1] === $slot->model
                    && $values[2] === $slot->effort && $values[3] === $slot->prompt_profile)
                || ($slot->role === 'finding_verification'
                    && ($values[0] === $slot->provider_profile || in_array($values[0], $sourceProviders, true)
                        || ($candidate['profile_id'] ?? null) === $implementationProfile))) {
                continue;
            }
            if ($slot->role === 'quality_review') {
                $this->orchestrator->reviseReviewSlot($run, $slotId, ...$values);
            } else {
                $profileId = $candidate['profile_id'] ?? null;
                $candidateId = $candidate['id'] ?? null;
                if (! is_string($profileId) || ! is_string($candidateId)) {
                    continue;
                }
                $this->orchestrator->reviseVerifierSlot($run, $slotId, new VerifierCandidate(
                    $candidateId,
                    $profileId,
                    $values[0],
                    $values[1],
                    $values[2],
                    ['selected_from_approval_snapshot', 'human_authorized_slot_revision'],
                ));
            }

            return;
        }

        throw new HumanRequestRejected('reviewer_alternative_missing', 'No alternative reviewer from the run-bound approval snapshot is available.');
    }
}
