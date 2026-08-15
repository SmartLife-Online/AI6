{{-- Availability remains owned exclusively by TicketReadModelUsePolicy. --}}
<div class="ai6-entry-actions">
    @can('editTicket', $project)
        @if ($viewModel->allowsEditor)
            <a href="{{ route('projects.tickets.edit', [$viewModel->readModel->project_id, $viewModel->readModel]) }}" data-ai6-entry="edit" class="ai6-entry">Bearbeiten</a>
        @endif
    @endcan
    @can('approveTicket', $project)
        @if ($viewModel->allowsApproval && $viewModel->ticketStatus === 'todo')
            <a href="{{ route('projects.tickets.approval', [$project, $viewModel->readModel]) }}" data-ai6-entry="approval" class="ai6-entry">Approval vorbereiten</a>
        @endif
    @endcan
    @if (! $viewModel->allowsEditor && ! $viewModel->allowsApproval)
        <span class="ai6-muted">Keine Edit- oder Approval-Aktion für diese Projektion</span>
    @endif
</div>
