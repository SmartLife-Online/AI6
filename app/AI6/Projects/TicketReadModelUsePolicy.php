<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Tickets\TicketSourceBlockers;
use App\AI6\Tickets\TicketValidationConfiguration;
use App\AI6\Tickets\TicketValidationProfile;

final readonly class TicketReadModelUsePolicy
{
    public function __construct(private TicketValidationConfiguration $configuration) {}

    public function allowsEditor(
        TicketReadModel $readModel,
        bool $isFresh,
        ?TicketValidationProfile $requiredProfile = null,
    ): bool {
        $requiredProfile ??= $this->configuration->profile;

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
        ?TicketValidationProfile $requiredProfile = null,
    ): bool {
        $requiredProfile ??= $this->configuration->profile;

        return $readModel->approval_eligible
            && $isFresh
            && $readModel->document_state === TicketDocumentState::VALID
            && is_string($readModel->ticket_contract_sha256)
            && preg_match('/\A[0-9a-f]{64}\z/D', $readModel->ticket_contract_sha256) === 1
            && $this->hasRequiredProfile($readModel, $requiredProfile)
            && $readModel->redaction_state === TicketReadModelRedactionState::CLEAR
            && $readModel->source_blockers === [];
    }

    private function hasRequiredProfile(TicketReadModel $readModel, TicketValidationProfile $requiredProfile): bool
    {
        return is_string($readModel->validation_profile)
            && hash_equals($requiredProfile->value, $readModel->validation_profile);
    }
}
