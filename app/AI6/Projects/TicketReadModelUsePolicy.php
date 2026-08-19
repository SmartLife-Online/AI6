<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Tickets\TicketSourceBlockers;
use App\AI6\Tickets\TicketValidationProfile;

final readonly class TicketReadModelUsePolicy
{
    public function allowsEditor(
        TicketReadModel $readModel,
        bool $isFresh,
        TicketValidationProfile $requiredProfile,
    ): bool {
        return $readModel->editor_eligible
            && $isFresh
            && in_array($readModel->document_state, [TicketDocumentState::INVALID, TicketDocumentState::VALID], true)
            && $this->hasRequiredProfile($readModel, $requiredProfile)
            && $readModel->redaction_state === TicketReadModelRedactionState::CLEAR
            && array_diff($readModel->source_blockers, [TicketSourceBlockers::INVALID]) === [];
    }

    public function allowsApproval(
        TicketReadModel $readModel,
        bool $isFresh,
        TicketValidationProfile $requiredProfile,
    ): bool {
        return $readModel->approval_eligible
            && $isFresh
            && $readModel->document_state === TicketDocumentState::VALID
            && is_string($readModel->ticket_contract_sha256)
            && preg_match('/\A[0-9a-f]{64}\z/D', $readModel->ticket_contract_sha256) === 1
            && $this->hasRequiredProfile($readModel, $requiredProfile)
            && $readModel->redaction_state === TicketReadModelRedactionState::CLEAR
            && $readModel->source_blockers === [];
    }

    public function allowsRunStart(
        TicketReadModel $readModel,
        bool $isFresh,
        TicketValidationProfile $requiredProfile,
    ): bool {
        return $isFresh
            && $readModel->document_state === TicketDocumentState::VALID
            && is_string($readModel->ticket_contract_sha256)
            && preg_match('/\A[0-9a-f]{64}\z/D', $readModel->ticket_contract_sha256) === 1
            && $this->hasRequiredProfile($readModel, $requiredProfile)
            && $readModel->redaction_state === TicketReadModelRedactionState::CLEAR
            && $readModel->source_blockers === [];
    }

    /**
     * A contract amendment mutates the ticket of the active run in place, so it
     * needs the same safe, byte-clear source as a run start — the editor flag
     * is irrelevant because the run, not an editor session, owns the ticket.
     */
    public function allowsAmendment(
        TicketReadModel $readModel,
        bool $isFresh,
        TicketValidationProfile $requiredProfile,
    ): bool {
        return $this->allowsRunStart($readModel, $isFresh, $requiredProfile);
    }

    private function hasRequiredProfile(TicketReadModel $readModel, TicketValidationProfile $requiredProfile): bool
    {
        return is_string($readModel->validation_profile)
            && hash_equals($requiredProfile->value, $readModel->validation_profile);
    }
}
