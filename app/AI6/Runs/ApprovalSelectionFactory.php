<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Git\ReviewSubject;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\Git\ReviewSubjectReference;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\VerifierCandidatePoolFactory;
use App\AI6\Tickets\TicketV1Parser;

final readonly class ApprovalSelectionFactory
{
    public function __construct(
        private AgentProfileRegistry $profiles,
        private ReviewerSlotFactory $reviewers,
        private AgentInputLimits $inputLimits,
        private ReviewSubjectReference $reviewSubjects,
        private TicketV1Parser $tickets,
        private VerifierCandidatePoolFactory $verifiers,
    ) {}

    public function completionModeForRisk(ReviewOnlyCompletionMode $requested, string $risk): ReviewOnlyCompletionMode
    {
        return $requested->narrowedTo($risk === 'high'
            ? ReviewOnlyCompletionMode::MANUAL
            : ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES);
    }

    /**
     * Bind the reviewed source and the completion mode of a review-only approval.
     *
     * This is the one place that maps a submitted source to its canonical
     * reference: the HTTP action and the Livewire preview both call it, so the
     * base rule, the per-kind field set and the risk narrowing cannot drift
     * apart between the snapshot a human confirms and the one that is stored.
     *
     * @param  array{kind: string, base_oid: string, source_oid: string, ref: string|null, source_run_id: string|null, tree_oid: string|null, diff_hash: string|null, completion_mode: string}  $input
     * @return array{reference: string, completion_mode: ReviewOnlyCompletionMode}
     */
    public function reviewOnlyBinding(TicketReadModel $readModel, array $input): array
    {
        if (! hash_equals((string) $readModel->control_commit, $input['base_oid'])) {
            throw new \InvalidArgumentException('Die Reviewbasis stimmt nicht mit dem geprüften Control-Stand überein.');
        }
        $kind = ReviewSubjectKind::tryFrom($input['kind']);
        if (! $kind instanceof ReviewSubjectKind) {
            throw new \InvalidArgumentException('Die Reviewquellart ist ungültig.');
        }
        $stored = in_array($kind, [ReviewSubjectKind::VALIDATED_PATCH, ReviewSubjectKind::CHECKPOINT], true);
        $risk = $this->tickets->parse((string) $readModel->redacted_content)->frontmatter['risk'] ?? null;

        return [
            'reference' => $this->reviewSubjects->encode(new ReviewSubject(
                $kind,
                $input['base_oid'],
                $input['source_oid'],
                $kind === ReviewSubjectKind::MANAGED_BRANCH ? $input['ref'] : null,
                $stored ? $input['source_run_id'] : null,
                $stored ? $input['tree_oid'] : null,
                $stored ? $input['diff_hash'] : null,
            )),
            // An unreadable or absent risk narrows to the strictest mode.
            'completion_mode' => $this->completionModeForRisk(
                ReviewOnlyCompletionMode::tryFrom($input['completion_mode']) ?? ReviewOnlyCompletionMode::MANUAL,
                is_string($risk) ? $risk : 'high',
            ),
        ];
    }

    /** @param array<string, mixed> $value */
    public function fromArray(array $value): ApprovalSelection
    {
        $implementation = $value['implementation'] ?? null;
        $reviewers = $value['reviewers'] ?? null;
        $limits = $value['limits'] ?? null;
        if (! is_array($implementation) || ! is_array($reviewers) || ! is_array($limits)) {
            throw new \InvalidArgumentException('Die Approval-Auswahl ist unvollständig.');
        }
        $reviewerInputs = array_map(static fn (array $slot): array => [
            'id' => $slot['id'] ?? null,
            'profile' => $slot['profile_id'] ?? null,
            'model' => $slot['model'] ?? null,
            'effort' => $slot['effort'] ?? null,
            'prompt_profile' => $slot['prompt_profile_id'] ?? null,
        ], $reviewers);
        $verifierValues = $value['verifier_candidates'] ?? null;
        $verifiers = is_array($verifierValues) && array_is_list($verifierValues)
            ? $this->verifiers->fromArray($verifierValues)
            : $this->verifiers->all();

        return new ApprovalSelection(
            $this->profiles->resolve(
                (string) ($implementation['profile_id'] ?? ''),
                AgentRole::IMPLEMENTATION,
                (string) ($implementation['model'] ?? ''),
                (string) ($implementation['effort'] ?? ''),
            ),
            $this->reviewers->fromArray($reviewerInputs),
            ApprovalLimits::fromConfiguredValues($limits, $this->inputLimits),
            is_int($value['attention_user_id'] ?? null) ? $value['attention_user_id'] : null,
            is_string($value['push_mode'] ?? null) ? $value['push_mode'] : '',
            RunType::tryFrom(is_string($value['run_type'] ?? null) ? $value['run_type'] : '') ?? RunType::IMPLEMENTATION,
            is_string($value['review_subject_reference'] ?? null) ? $value['review_subject_reference'] : null,
            ReviewOnlyCompletionMode::tryFrom(is_string($value['completion_mode'] ?? null) ? $value['completion_mode'] : ''),
            $verifiers,
        );
    }
}
