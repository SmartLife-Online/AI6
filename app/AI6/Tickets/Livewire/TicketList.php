<?php

namespace App\AI6\Tickets\Livewire;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Tickets\TicketViewModel;
use App\AI6\Tickets\TicketViewModelFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Ticketübersicht – AI6'])]
final class TicketList extends Component
{
    public Project $project;

    public string $status = '';

    public string $state = '';

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
    }

    // Livewire updates bypass the route middleware of the initial GET request;
    // the project authorization is therefore re-decided on every hydration.
    public function hydrate(): void
    {
        Gate::authorize('view', $this->project);
    }

    public function render(): View
    {
        $list = app(TicketViewModelFactory::class)->listFor($this->project);
        $rows = [];
        $refreshOperationIds = [];

        foreach ($list['viewModels'] as $viewModel) {
            if (! $this->matchesFilters($viewModel)) {
                continue;
            }

            $rows[] = [
                'viewModel' => $viewModel,
                'badges' => $list['badges'][(int) $viewModel->readModel->getKey()] ?? [],
            ];
            $refreshOperationIds[$viewModel->readModel->relative_path] = (string) Str::uuid();
        }

        return view('tickets.list', [
            'project' => $this->project,
            'rows' => $rows,
            'totalCount' => count($list['viewModels']),
            'pendingOperations' => $list['pendingOperations'],
            'statusOptions' => $this->statusOptions($list['viewModels']),
            'stateOptions' => $this->stateOptions(),
            'refreshOperationIds' => $refreshOperationIds,
        ]);
    }

    private function matchesFilters(TicketViewModel $viewModel): bool
    {
        if ($this->status !== '' && $viewModel->ticketStatus !== $this->status) {
            return false;
        }

        if ($this->state === '') {
            return true;
        }

        if ($this->state === TicketReadModelRedactionState::CONTENT_REDACTED->value) {
            return $viewModel->readModel->redaction_state === TicketReadModelRedactionState::CONTENT_REDACTED;
        }

        return $viewModel->readModel->document_state->value === $this->state;
    }

    /**
     * @param  list<TicketViewModel>  $viewModels
     * @return list<string>
     */
    private function statusOptions(array $viewModels): array
    {
        $statuses = [];

        foreach ($viewModels as $viewModel) {
            if ($viewModel->ticketStatus !== null) {
                $statuses[$viewModel->ticketStatus] = true;
            }
        }

        $options = array_keys($statuses);
        sort($options, SORT_STRING);

        return $options;
    }

    /**
     * The filter options derive from the real enums so that a new document or
     * redaction state never silently drops out of the filter.
     *
     * @return list<string>
     */
    private function stateOptions(): array
    {
        $options = array_column(TicketDocumentState::cases(), 'value');
        $options[] = TicketReadModelRedactionState::CONTENT_REDACTED->value;

        return $options;
    }
}
