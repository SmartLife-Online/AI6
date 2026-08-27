<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ReviewSubjectException;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\Models\TicketApprovalPreview;
use App\AI6\Tickets\TicketMutationConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class TicketApprovalController
{
    public const STEP_UP_ACTION = 'ticket.approve';

    public function store(Request $request, Project $project, string $readModel, AgentProfileRegistry $profiles, ReviewerSlotFactory $reviewers, AgentInputLimits $inputLimits, CanonicalJson $canonicalJson, QueueTicketMutation $mutations, StepUpGuard $stepUp, ApprovalSelectionFactory $selectionFactory): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $model = TicketReadModel::query()->whereKey($readModel)->where('project_id', $project->getKey())->firstOrFail();
        $limitNames = array_keys((array) config('ai6.project_config.server_maxima', []));
        $rules = [
            'operation_id' => ['required', 'uuid'],
            'preview_id' => ['required', 'uuid'],
            'expected_control_oid' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'expected_blob' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'base_content' => ['required', 'string', 'max:2097152'],
            'reason' => ['required', 'string', 'max:2000'],
            'implementation_profile' => ['required', 'string'],
            'implementation_model' => ['required', 'string'],
            'implementation_effort' => ['required', 'string'],
            'reviewers' => ['required', 'array', 'min:1'],
            'reviewers.*.id' => ['required', 'uuid'],
            'reviewers.*.profile' => ['required', 'string'],
            'reviewers.*.model' => ['required', 'string'],
            'reviewers.*.effort' => ['required', 'string'],
            'reviewers.*.prompt_profile' => ['required', 'string'],
            'attention_user_id' => ['nullable', 'integer'],
            'push_mode' => ['required', Rule::in(['manual', 'automatic_after_gates'])],
            'run_type' => ['sometimes', Rule::in(['implementation', 'review_only'])],
            'review_subject_kind' => ['required_if:run_type,review_only', Rule::in(array_column(ReviewSubjectKind::cases(), 'value'))],
            'review_base_oid' => ['required_if:run_type,review_only', 'regex:/\A[0-9a-f]{64}\z/D'],
            'review_source_oid' => ['required_if:run_type,review_only', 'regex:/\A[0-9a-f]{64}\z/D'],
            'review_source_ref' => ['required_if:review_subject_kind,managed_branch', 'nullable', 'string', 'max:1024'],
            'review_source_run_id' => ['required_if:review_subject_kind,validated_patch,checkpoint', 'nullable', 'uuid'],
            'review_tree_oid' => ['required_if:review_subject_kind,validated_patch,checkpoint', 'nullable', 'regex:/\A[0-9a-f]{64}\z/D'],
            'review_diff_hash' => ['required_if:review_subject_kind,validated_patch,checkpoint', 'nullable', 'regex:/\A[0-9a-f]{64}\z/D'],
            'completion_mode' => ['required_if:run_type,review_only', 'nullable', Rule::in(['manual', 'automatic_after_gates'])],
            'confirm_snapshot' => ['required', 'accepted'],
            'expected_approval_snapshot_hash' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
        ];
        foreach ($limitNames as $name) {
            $rules['limits.'.$name] = ['required', 'integer', 'min:1'];
        }
        $validated = $request->validate($rules);
        try {
            $implementation = $profiles->resolve($validated['implementation_profile'], AgentRole::IMPLEMENTATION, $validated['implementation_model'], $validated['implementation_effort']);
            $reviewerSlots = $reviewers->fromArray($validated['reviewers']);
            $limits = ApprovalLimits::fromConfiguredValues($validated['limits'], $inputLimits);
            $attentionUserId = isset($validated['attention_user_id']) ? (int) $validated['attention_user_id'] : null;
            if ($attentionUserId !== null && ! ProjectMembership::query()->where('project_id', $project->getKey())->where('user_id', $attentionUserId)->whereHas('user', fn ($query) => $query->where('is_active', true))->exists()) {
                throw new \InvalidArgumentException('Der Attention-User ist kein aktives Projektmitglied.');
            }
            $runType = RunType::tryFrom((string) ($validated['run_type'] ?? 'implementation')) ?? RunType::IMPLEMENTATION;
            $subjectReference = null;
            $completionMode = null;
            if ($runType === RunType::REVIEW_ONLY) {
                $binding = $selectionFactory->reviewOnlyBinding($model, [
                    'kind' => (string) $validated['review_subject_kind'],
                    'base_oid' => (string) $validated['review_base_oid'],
                    'source_oid' => (string) $validated['review_source_oid'],
                    'ref' => $validated['review_source_ref'] ?? null,
                    'source_run_id' => $validated['review_source_run_id'] ?? null,
                    'tree_oid' => $validated['review_tree_oid'] ?? null,
                    'diff_hash' => $validated['review_diff_hash'] ?? null,
                    'completion_mode' => (string) $validated['completion_mode'],
                ]);
                $subjectReference = $binding['reference'];
                $completionMode = $binding['completion_mode'];
            }
            $selection = new ApprovalSelection($implementation, $reviewerSlots, $limits, $attentionUserId, $validated['push_mode'], $runType, $subjectReference, $completionMode);
            $previewRecord = TicketApprovalPreview::query()
                ->whereKey($validated['preview_id'])
                ->where('project_id', $project->getKey())
                ->where('ticket_read_model_id', $model->getKey())
                ->where('requested_by', $actor->getKey())
                ->where('state', 'ready')
                ->first();
            if (! $previewRecord instanceof TicketApprovalPreview
                || ! is_array($previewRecord->approval_snapshot)
                || ! is_string($previewRecord->approval_snapshot_hash)
                || ! hash_equals($validated['expected_approval_snapshot_hash'], $previewRecord->approval_snapshot_hash)
                || ! hash_equals($previewRecord->reviewed_ticket_blob_sha, $validated['expected_blob'])
                || ! hash_equals($previewRecord->reviewed_control_sha, $validated['expected_control_oid'])
                || ! hash_equals($canonicalJson->normalizeAndEncode($selection->jsonSerialize()), $canonicalJson->normalizeAndEncode($previewRecord->selection_snapshot))) {
                throw new \InvalidArgumentException('Der gemeinsam bestätigte Approval-Snapshot ist nicht mehr aktuell.');
            }
            $preview = ApprovalSnapshot::fromArray($previewRecord->approval_snapshot);
            if (! hash_equals($previewRecord->approval_snapshot_hash, $preview->aggregateHash)) {
                throw new \InvalidArgumentException('Der gemeinsam bestätigte Approval-Snapshot ist nicht mehr aktuell.');
            }
            $stepUp->consumeFresh($request, $actor, self::STEP_UP_ACTION);
            $operation = $mutations->approve($actor, $project, $model, $validated['operation_id'], $validated['expected_control_oid'], $validated['expected_blob'], $validated['base_content'], $validated['reason'], true, $selection, $preview, $previewRecord->id);
        } catch (AgentProfileSelectionException $exception) {
            throw ValidationException::withMessages([
                'approval' => ['Die Agentenprofilauswahl wurde abgelehnt: '.$exception->reason->value.'.'],
            ]);
        } catch (PromptRenderingException $exception) {
            throw ValidationException::withMessages([
                'approval' => ['Das Review-Promptprofil wurde abgelehnt: '.$exception->reason->value.'.'],
            ]);
        } catch (ReviewSubjectException $exception) {
            throw ValidationException::withMessages([
                'approval' => ['Der Reviewgegenstand wurde abgelehnt: '.$exception->reason.'.'],
            ]);
        } catch (TicketMutationConflict|ControlOperationConflict|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['approval' => [$exception->getMessage()]]);
        }

        return redirect()->route('projects.approvals.show', [$project, $operation->id]);
    }
}
