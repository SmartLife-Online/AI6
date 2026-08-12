<?php

namespace App\AI6\Tickets\Livewire;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Shared\Markdown\SafeMarkdownRenderer;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketViewModelFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Ticketdetail – AI6'])]
final class TicketDetail extends Component
{
    public Project $project;

    public TicketReadModel $ticketReadModel;

    // The route parameter stays a plain string and resolves only after the
    // project authorization decided, so a non-member receives the policy
    // denial instead of an existence probe.
    public function mount(Project $project, string $readModel): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
        $this->ticketReadModel = TicketReadModel::query()
            ->whereKey($readModel)
            ->where('project_id', $project->getKey())
            ->firstOrFail();
    }

    // Livewire updates bypass the route middleware of the initial GET request;
    // authorization and the project binding are re-decided on every hydration.
    public function hydrate(): void
    {
        Gate::authorize('view', $this->project);
        abort_unless($this->ticketReadModel->project_id === $this->project->getKey(), 404);
    }

    public function render(): View
    {
        $factory = app(TicketViewModelFactory::class);
        $viewModel = $factory->forReadModel($this->project, $this->ticketReadModel);
        $renderedBody = null;
        $unrenderableSource = null;

        if ($viewModel->document !== null) {
            $renderedBody = app(SafeMarkdownRenderer::class)->render(
                $viewModel->document->body,
                new RedactionContext(
                    (string) $this->project->getKey(),
                    null,
                    'ticket-ui:'.$viewModel->readModel->relative_path,
                ),
            );
        } elseif ($viewModel->readModel->document_state !== TicketDocumentState::UNPARSED) {
            $unrenderableSource = $viewModel->readModel->redacted_content;
        }

        return view('tickets.detail', [
            'project' => $this->project,
            'viewModel' => $viewModel,
            'badges' => $factory->dependencyBadgesFor($this->project, $viewModel),
            'renderedBody' => $renderedBody,
            'unrenderableSource' => $unrenderableSource,
            'refreshOperationId' => (string) Str::uuid(),
        ]);
    }
}
