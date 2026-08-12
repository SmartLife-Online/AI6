<?php

namespace App\AI6\Tickets;

use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelUsePolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class TicketViewModelFactory
{
    public function __construct(
        private TicketV1Parser $parser,
        private TicketReadModelFreshness $freshness,
        private TicketReadModelUsePolicy $usePolicy,
    ) {}

    /**
     * Everything the ticket list needs from one pass: the view models, their
     * dependency badges resolved against a single shared target index, and the
     * refresh operations that own no projection yet and would otherwise be
     * invisible (a queued first refresh or one that failed before persisting).
     *
     * @return array{viewModels: list<TicketViewModel>, badges: array<int, list<DependencyBadge>>, pendingOperations: list<ControlOperation>}
     */
    public function listFor(Project $project): array
    {
        $readModels = $this->readModels($project);
        $latestRefreshByPath = $this->latestRefreshOperationsByPath($project);
        $viewModels = [];
        $entries = [];
        $projectedPaths = [];

        foreach ($readModels as $readModel) {
            $viewModel = $this->viewModel(
                $project,
                $readModel,
                $latestRefreshByPath[$readModel->relative_path] ?? null,
            );
            $viewModels[] = $viewModel;
            $entries[] = [$viewModel->ticketId, $viewModel->ticketStatus, $readModel->relative_path];
            $projectedPaths[$readModel->relative_path] = true;
        }

        $index = $this->indexFromEntries($entries);
        $badges = [];

        foreach ($viewModels as $viewModel) {
            $badges[(int) $viewModel->readModel->getKey()] = $this->badgesFromIndex($viewModel, $index);
        }

        $pendingOperations = [];

        foreach ($latestRefreshByPath as $path => $operation) {
            if (! array_key_exists($path, $projectedPaths)
                && $operation->state !== ControlOperationState::COMPLETED) {
                $pendingOperations[$path] = $operation;
            }
        }

        ksort($pendingOperations, SORT_STRING);

        return [
            'viewModels' => $viewModels,
            'badges' => $badges,
            'pendingOperations' => array_values($pendingOperations),
        ];
    }

    public function forReadModel(Project $project, TicketReadModel $readModel): TicketViewModel
    {
        $latestRefreshByPath = $this->latestRefreshOperationsByPath($project, [$readModel->relative_path]);

        return $this->viewModel(
            $project,
            $readModel,
            $latestRefreshByPath[$readModel->relative_path] ?? null,
        );
    }

    /**
     * Badges for a single detail view. Only when the ticket declares
     * dependencies at all are the other projections read — and then only their
     * redacted content is parsed for identity, without freshness, policy or
     * operation lookups.
     *
     * @return list<DependencyBadge>
     */
    public function dependencyBadgesFor(Project $project, TicketViewModel $viewModel): array
    {
        if ($viewModel->dependsOn === []) {
            return [];
        }

        $entries = [];

        foreach ($this->readModels($project) as $readModel) {
            $document = $this->documentFor($readModel);
            $frontmatter = $document->frontmatter ?? [];
            $entries[] = [
                is_string($frontmatter['id'] ?? null) ? $frontmatter['id'] : null,
                is_string($frontmatter['status'] ?? null) ? $frontmatter['status'] : null,
                $readModel->relative_path,
            ];
        }

        return $this->badgesFromIndex($viewModel, $this->indexFromEntries($entries));
    }

    private function viewModel(
        Project $project,
        TicketReadModel $readModel,
        ?ControlOperation $latestRefresh,
    ): TicketViewModel {
        $freshness = $this->freshness->for($project, $readModel);
        $document = $this->documentFor($readModel);
        $frontmatter = $document->frontmatter ?? [];
        $dependsOn = is_array($frontmatter['depends_on'] ?? null)
            ? array_values(array_filter($frontmatter['depends_on'], is_string(...)))
            : [];
        $goal = $document->sections['Goal'] ?? null;

        return new TicketViewModel(
            readModel: $readModel,
            document: $document,
            ticketId: is_string($frontmatter['id'] ?? null) ? $frontmatter['id'] : null,
            title: is_string($frontmatter['title'] ?? null) ? $frontmatter['title'] : null,
            ticketStatus: is_string($frontmatter['status'] ?? null) ? $frontmatter['status'] : null,
            dependsOn: $dependsOn,
            goal: is_string($goal) ? $goal : null,
            isStale: $freshness['stale'],
            staleReasons: $freshness['reasons'],
            allowsEditor: $this->usePolicy->allowsEditor($readModel, ! $freshness['stale']),
            allowsApproval: $this->usePolicy->allowsApproval($readModel, ! $freshness['stale']),
            latestRefresh: $latestRefresh,
        );
    }

    private function documentFor(TicketReadModel $readModel): ?TicketDocument
    {
        // An unparsed projection stays a pure envelope: the UI must not derive
        // any content statement from it (GIT-009).
        if ($readModel->document_state === TicketDocumentState::UNPARSED) {
            return null;
        }

        try {
            return $this->parser->parse($readModel->redacted_content);
        } catch (TicketParseException) {
            return null;
        }
    }

    /** @return list<TicketReadModel> */
    private function readModels(Project $project): array
    {
        return TicketReadModel::query()
            ->where('project_id', $project->getKey())
            ->get()
            ->sortBy('relative_path', SORT_STRING)
            ->values()
            ->all();
    }

    /**
     * The latest refresh operation per candidate path, selected in SQL by
     * creation time with the identifier as deterministic tiebreaker; only the
     * envelope columns travel, never authorization snapshots or error bodies.
     * Within one timestamp a running attempt outranks a finished one so that
     * an active order never hides behind an already terminal row.
     *
     * @param  list<string>|null  $paths
     * @return array<string, ControlOperation>
     */
    private function latestRefreshOperationsByPath(Project $project, ?array $paths = null): array
    {
        $query = ControlOperation::query()
            ->where('project_id', $project->getKey())
            ->where('operation_type', ControlOperationType::TICKET_REFRESH)
            ->latest('created_at')
            ->latest('id');

        if ($paths !== null) {
            $query->whereIn(
                DB::raw("json_extract(operation_parameters_jcs, '$.relative_path')"),
                $paths,
            );
        }

        $operations = $query->get([
            'id',
            'project_id',
            'operation_type',
            'state',
            'phase',
            'created_at',
            DB::raw("json_extract(operation_parameters_jcs, '$.relative_path') as refresh_relative_path"),
        ]);
        $byPath = [];

        foreach ($operations as $operation) {
            $path = $operation->getAttribute('refresh_relative_path');

            if (! is_string($path)) {
                continue;
            }

            $current = $byPath[$path] ?? null;

            if ($current === null) {
                $byPath[$path] = $operation;

                continue;
            }

            $currentCreatedAt = $current->getAttribute('created_at');
            $operationCreatedAt = $operation->getAttribute('created_at');

            if ($current->state->terminal()
                && ! $operation->state->terminal()
                && $currentCreatedAt instanceof CarbonInterface
                && $operationCreatedAt instanceof CarbonInterface
                && $currentCreatedAt->equalTo($operationCreatedAt)) {
                $byPath[$path] = $operation;
            }
        }

        return $byPath;
    }

    /**
     * @param  list<array{string|null, string|null, string}>  $entries
     * @return array{ids: array<string, array{status: string|null, count: int}>, unreadable: array<string, true>}
     */
    private function indexFromEntries(array $entries): array
    {
        $ids = [];
        $unreadable = [];

        foreach ($entries as [$ticketId, $status, $path]) {
            if ($ticketId === null) {
                // The projection exists but carries no readable identity; the
                // canonical filename contract (TKT-002) still lets a declared
                // dependency point at it without inventing a status.
                $unreadable[pathinfo($path, PATHINFO_FILENAME)] = true;

                continue;
            }

            if (array_key_exists($ticketId, $ids)) {
                $ids[$ticketId]['count']++;
            } else {
                $ids[$ticketId] = ['status' => $status, 'count' => 1];
            }
        }

        return ['ids' => $ids, 'unreadable' => $unreadable];
    }

    /**
     * @param  array{ids: array<string, array{status: string|null, count: int}>, unreadable: array<string, true>}  $index
     * @return list<DependencyBadge>
     */
    private function badgesFromIndex(TicketViewModel $viewModel, array $index): array
    {
        return array_map(static function (string $dependency) use ($index): DependencyBadge {
            $target = $index['ids'][$dependency] ?? null;

            if ($target !== null) {
                if ($target['count'] > 1) {
                    return new DependencyBadge($dependency, DependencyBadge::AMBIGUOUS, null);
                }

                if ($target['status'] === null) {
                    return new DependencyBadge($dependency, DependencyBadge::UNKNOWN, null);
                }

                return new DependencyBadge(
                    $dependency,
                    $target['status'] === 'done' ? DependencyBadge::SATISFIED : DependencyBadge::OPEN,
                    $target['status'],
                );
            }

            if (array_key_exists($dependency, $index['unreadable'])) {
                return new DependencyBadge($dependency, DependencyBadge::UNREADABLE, null);
            }

            return new DependencyBadge($dependency, DependencyBadge::MISSING, null);
        }, $viewModel->dependsOn);
    }
}
