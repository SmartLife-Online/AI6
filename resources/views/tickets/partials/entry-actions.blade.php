{{-- The availability of both entries is decided exclusively by
     TicketReadModelUsePolicy; this template only prints the decision.
     Neither entry owns a mutation route in AI6-008: the edit flow lands
     with AI6-009 and the approval flow with AI6-012. --}}
<div class="ai6-entry-actions">
    @if ($viewModel->allowsEditor)
        <button type="button" data-ai6-entry="edit" class="ai6-entry">Bearbeiten</button>
    @endif
    @if ($viewModel->allowsApproval)
        <button type="button" data-ai6-entry="approval" class="ai6-entry">Approval vorbereiten</button>
    @endif
    @if (! $viewModel->allowsEditor && ! $viewModel->allowsApproval)
        <span class="ai6-muted">Keine Edit- oder Approval-Aktion für diese Projektion</span>
    @endif
</div>
