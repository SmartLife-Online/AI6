<?php

namespace App\AI6\Tickets\Console;

use App\AI6\Git\ControlOperationRuntimeIdentityFactory;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\RefreshPathPolicy;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Shared\Config\StrictEnumParser;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketInventory;
use App\AI6\Tickets\TicketSourceBlockers;
use App\AI6\Tickets\TicketValidationConfiguration;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReprojectUnparsedTicketsCommand extends Command
{
    protected $signature = 'ai6:tickets:reproject-unparsed';

    protected $description = 'Reproject legacy unparsed ticket read models under the configured server profile';

    public function __construct(
        private readonly Application $app,
        private readonly TicketValidationConfiguration $validation,
        private readonly ControlOperationRuntimeIdentityFactory $runtimeIdentityFactory,
        private readonly RefreshPathPolicy $refreshPaths,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->runtimeIdentityFactory->fromConfiguredValues()->runtimeRole !== 'worker') {
            $this->error('Die Reprojektion darf ausschließlich im Worker-Kontext laufen.');

            return self::FAILURE;
        }

        $conflicts = 0;
        $ids = TicketReadModel::query()
            ->where('document_state', TicketDocumentState::UNPARSED)
            ->whereNull('validation_profile')
            ->orderBy('id')
            ->pluck('id');
        foreach ($ids as $id) {
            $readModel = TicketReadModel::query()->find($id);
            if (! $readModel instanceof TicketReadModel) {
                $conflicts++;
                $this->warn('Konflikt: Eine Projektion wurde zwischenzeitlich entfernt.');

                continue;
            }
            try {
                if (! $this->reproject($readModel)) {
                    $conflicts++;
                    $this->warn('Konflikt: Eine Projektion wurde zwischenzeitlich geändert.');
                }
            } catch (Throwable $exception) {
                $conflicts++;
                report($exception);
                $this->warn('Konflikt: Eine Projektion konnte nicht sicher neu erstellt werden ('.$exception::class.').');
            }
        }

        $remaining = TicketReadModel::query()
            ->where('document_state', TicketDocumentState::UNPARSED)
            ->whereNull('validation_profile')
            ->count();
        if ($conflicts > 0 || $remaining > 0) {
            $this->error(sprintf('%d Konflikt(e); %d unparsed Read Model(s) verbleiben.', $conflicts, $remaining));

            return self::FAILURE;
        }

        $this->info('Alle unparsed Ticket-Read-Models sind profilgebunden reprojiziert.');

        return self::SUCCESS;
    }

    private function reproject(TicketReadModel $readModel): bool
    {
        $project = Project::query()->findOrFail($readModel->project_id);
        if ($project->project_identifier === null) {
            return false;
        }
        $managedPaths = $this->app->make(ManagedProjectPath::class);
        $repository = $managedPaths->assertRepository(
            $managedPaths->repositoryDirectory($project->project_identifier),
        );
        $context = new RedactionContext((string) $project->getKey(), null, 'ticket-read-model:'.$readModel->relative_path);
        $profile = $this->validation->profile;
        $inventory = $this->app->make(TicketInventory::class)->inspect(
            $repository,
            $readModel->control_commit,
            $this->refreshPaths->basePath(),
            $profile,
            $profile,
            $context,
        );
        $blob = $inventory->blobs[$readModel->relative_path] ?? null;
        $projection = $inventory->projectionFor($readModel->relative_path);
        if ($blob === null || $projection === null || ! hash_equals($readModel->blob_sha, $blob->blobSha)) {
            return false;
        }
        try {
            $redaction = $this->app->make(Redactor::class)->redact($blob->content, $context);
        } catch (InvalidRedactionInputException) {
            return false;
        }
        $matches = array_map(static fn ($match): array => $match->jsonSerialize(), $redaction->matches);
        $redactionState = $matches === [] ? TicketReadModelRedactionState::CLEAR : TicketReadModelRedactionState::CONTENT_REDACTED;
        $blockers = $projection->sourceBlockers;
        if ($redactionState === TicketReadModelRedactionState::CONTENT_REDACTED) {
            $blockers[] = TicketSourceBlockers::CONTENT_REDACTED;
        }
        $blockers = TicketSourceBlockers::canonicalize($blockers);
        $editorEligible = $redactionState === TicketReadModelRedactionState::CLEAR
            && array_diff($blockers, [TicketSourceBlockers::INVALID]) === [];
        $approvalEligible = $editorEligible && $projection->state === TicketDocumentState::VALID;
        $generation = $readModel->control_generation;

        return DB::transaction(function () use (
            $readModel, $project, $profile, $projection, $redaction, $redactionState,
            $matches, $blockers, $editorEligible, $approvalEligible, $generation,
        ): bool {
            $currentProject = Project::query()->findOrFail($project->getKey());
            $currentProfile = TicketValidationConfiguration::fromConfiguredValues(
                $this->app->make(StrictEnumParser::class),
                $this->app->make(StrictPositiveIntegerParser::class),
            )->profile;
            if ($currentProject->control_generation !== $generation
                || $currentProfile !== $profile) {
                return false;
            }

            return TicketReadModel::query()
                ->whereKey($readModel->getKey())
                ->where('project_id', $readModel->project_id)
                ->where('relative_path', $readModel->relative_path)
                ->where('control_commit', $readModel->control_commit)
                ->where('blob_sha', $readModel->blob_sha)
                ->where('control_generation', $generation)
                ->where('document_state', TicketDocumentState::UNPARSED->value)
                ->whereNull('validation_profile')
                ->update([
                    'validation_profile' => $profile->value,
                    'document_state' => $projection->state->value,
                    'ticket_contract_sha256' => $projection->contractHash,
                    'validation_errors' => json_encode(array_map(static fn ($error): array => $error->jsonSerialize(), $projection->errors), JSON_THROW_ON_ERROR),
                    'redacted_content' => $redaction->text,
                    'redaction_state' => $redactionState->value,
                    'redaction_matches' => json_encode($matches, JSON_THROW_ON_ERROR),
                    'source_blockers' => json_encode($blockers, JSON_THROW_ON_ERROR),
                    'approval_editor_eligible' => false,
                    'editor_eligible' => $editorEligible,
                    'approval_eligible' => $approvalEligible,
                    'generated_at' => Date::now(),
                    'updated_at' => Date::now(),
                ]) === 1;
        });
    }
}
