<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\TicketReadModel;

final class TicketReadModelUsePolicy
{
    public function allowsApprovalOrEditor(TicketReadModel $readModel): bool
    {
        return $readModel->approval_editor_eligible
            && $readModel->document_state === TicketDocumentState::VALID
            && $readModel->redaction_state === TicketReadModelRedactionState::CLEAR
            && $readModel->source_blockers === [];
    }
}
