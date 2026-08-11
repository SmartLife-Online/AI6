<?php

namespace App\AI6\Tickets;

use App\AI6\Git\ControlOperationTerminalConflict;
use App\AI6\Git\GitTreeEntry;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;

final readonly class TicketInventory
{
    public function __construct(
        private HardenedGitRunner $git,
        private TicketReadModelProjector $projector,
        private TicketDependencyGraph $graph,
        private Redactor $redactor,
        private TicketValidationConfiguration $configuration,
    ) {}

    public function inspect(
        string $repository,
        string $controlCommit,
        string $basePath,
        TicketValidationProfile $profile,
        TicketValidationProfile $requiredProfile,
        RedactionContext $context,
    ): TicketInventoryResult {
        $entries = $this->git->listDirectTreeEntries($repository, $controlCommit, $basePath, $context);
        $projectErrors = $this->caseFoldErrors($entries);
        $candidates = array_values(array_filter($entries, function (GitTreeEntry $entry): bool {
            $stem = str_ends_with($entry->name, '.md') ? substr($entry->name, 0, -3) : '';

            return $entry->isRegularBlob() && preg_match(GenericV1TicketValidator::ID_PATTERN, $stem) === 1;
        }));
        if (count($candidates) > $this->configuration->maxCandidates) {
            throw new ControlOperationTerminalConflict(
                'refresh_ticket_candidate_limit_exceeded',
                'Der Ticketbestand überschreitet die serverseitige Kandidatengrenze.',
            );
        }
        $blobs = [];
        $projections = [];
        $declared = [];
        $dependencies = [];
        $invalidUtf8Paths = [];

        foreach ($candidates as $entry) {
            $path = $basePath.'/'.$entry->name;
            $blob = $this->git->readRegularBlob($repository, $controlCommit, $path, $context);
            $blobs[$path] = $blob;
            try {
                $this->redactor->assertValidInput($blob->content);
            } catch (InvalidRedactionInputException) {
                $invalidUtf8Paths[] = $path;

                continue;
            }
            $projection = $this->projector->project($blob->content, $path, $profile, $requiredProfile);
            $projections[$path] = $projection;
            foreach ($projection->errors as $error) {
                if ($error->code === 'filename_id_mismatch') {
                    $projectErrors[] = new TicketValidationError($error->code, $path, $error->message);
                }
            }
            $id = $projection->document?->frontmatter['id'] ?? null;
            if (! is_string($id) || preg_match(GenericV1TicketValidator::ID_PATTERN, $id) !== 1) {
                continue;
            }
            if (isset($declared[$id])) {
                $paths = [$declared[$id], $path];
                sort($paths);
                $projectErrors[] = new TicketValidationError(
                    'declared_id_duplicate', implode(', ', $paths), 'Mehrere Kandidaten deklarieren dieselbe Ticket-ID.',
                );
            }
            $declared[$id] = $path;
            $items = $projection->document?->frontmatter['depends_on'] ?? [];
            $dependencies[$id] = is_array($items) && array_is_list($items)
                ? array_values(array_filter($items, 'is_string')) : [];
        }
        $projectErrors = [...$projectErrors, ...$this->graph->validate($dependencies)];

        return new TicketInventoryResult(
            $blobs,
            $projections,
            $this->unique($projectErrors),
            $invalidUtf8Paths,
        );
    }

    /** @param list<GitTreeEntry> $entries
     * @return list<TicketValidationError>
     */
    private function caseFoldErrors(array $entries): array
    {
        $names = [];
        $errors = [];
        foreach ($entries as $entry) {
            if (! $entry->isRegularBlob()
                || preg_match('/\A(?=.{2,32}\.md\z)(?=.*\d)[A-Za-z][A-Za-z0-9-]*\.md\z/D', $entry->name) !== 1) {
                continue;
            }
            $names[strtolower($entry->name)][] = $entry->name;
        }
        foreach ($names as $variants) {
            if (count(array_unique($variants)) > 1) {
                sort($variants);

                $errors[] = new TicketValidationError(
                    'candidate_case_fold_collision', implode(', ', $variants), 'Ticketkandidaten kollidieren bei case-insensitivem Vergleich.',
                );
            }
        }

        return $errors;
    }

    /** @param list<TicketValidationError> $errors
     * @return list<TicketValidationError>
     */
    private function unique(array $errors): array
    {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error->code."\0".$error->field] = $error;
        }

        return array_values($unique);
    }
}
