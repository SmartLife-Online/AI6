{{-- Availability remains owned exclusively by TicketReadModelUsePolicy. --}}
<div class="ai6-entry-actions">
    @can('editTicket', $project)
        @if ($viewModel->allowsEditor)
            <a href="{{ route('projects.tickets.edit', [$viewModel->readModel->project_id, $viewModel->readModel]) }}" data-ai6-entry="edit" class="ai6-entry">Bearbeiten</a>
        @endif
    @endcan
    @if ($viewModel->allowsApproval)
        <button type="button" data-ai6-entry="approval" class="ai6-entry">Approval vorbereiten</button>
    @endif
    @if (! $viewModel->allowsEditor && ! $viewModel->allowsApproval)
        <span class="ai6-muted">Keine Edit- oder Approval-Aktion für diese Projektion</span>
    @endif
</div>
