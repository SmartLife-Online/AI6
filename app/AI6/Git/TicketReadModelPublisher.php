<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketProjection;
use App\AI6\Tickets\TicketSourceBlockers;
use DateTimeInterface;

final readonly class TicketReadModelPublisher
{
    public function __construct(private Redactor $redactor) {}

    public function publish(
        Project $project,
        ControlOperation $operation,
        TicketReadModelRefreshResult $result,
        TicketProjection $projection,
        RedactionContext $context,
        DateTimeInterface $generatedAt,
    ): TicketReadModel {
        try {
            $redaction = $this->redactor->redact($result->content, $context);
        } catch (InvalidRedactionInputException) {
            throw new ControlOperationTerminalConflict(
                'refresh_blob_not_utf8',
                'Der gebundene Git-Blob enthält kein gültiges UTF-8.',
            );
        }
        $matches = array_map(
            static fn ($match): array => $match->jsonSerialize(),
            $redaction->matches,
        );
        $redactionState = $matches === []
            ? TicketReadModelRedactionState::CLEAR
            : TicketReadModelRedactionState::CONTENT_REDACTED;
        $blockers = $projection->sourceBlockers;
        if ($redactionState === TicketReadModelRedactionState::CONTENT_REDACTED) {
            $blockers[] = TicketSourceBlockers::CONTENT_REDACTED;
        }
        $blockers = TicketSourceBlockers::canonicalize($blockers);
        $editorEligible = $redactionState === TicketReadModelRedactionState::CLEAR
            && array_diff($blockers, [TicketSourceBlockers::INVALID]) === [];
        $approvalEligible = $editorEligible && $projection->state === TicketDocumentState::VALID;

        return TicketReadModel::query()->updateOrCreate(
            [
                'project_id' => $project->getKey(),
                'relative_path' => $result->relativePath,
            ],
            [
                'control_operation_id' => $operation->id,
                'control_commit' => $result->controlCommit,
                'blob_sha' => $result->blobSha,
                'control_generation' => $project->control_generation,
                'validation_profile' => $projection->profile->value,
                'document_state' => $projection->state,
                'ticket_contract_sha256' => $projection->contractHash,
                'validation_errors' => array_map(
                    static fn ($error): array => $error->jsonSerialize(),
                    $projection->errors,
                ),
                'redacted_content' => $redaction->text,
                'redaction_state' => $redactionState,
                'redaction_matches' => $matches,
                'source_blockers' => $blockers,
                'approval_editor_eligible' => false,
                'editor_eligible' => $editorEligible,
                'approval_eligible' => $approvalEligible,
                'generated_at' => $generatedAt,
            ],
        );
    }
}
