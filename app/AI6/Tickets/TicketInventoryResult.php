<?php

namespace App\AI6\Tickets;

use App\AI6\Git\TicketBlob;

final readonly class TicketInventoryResult
{
    /** @param array<string, TicketBlob> $blobs
     * @param  array<string, TicketProjection>  $projections
     * @param  list<TicketValidationError>  $projectErrors
     * @param  list<string>  $invalidUtf8Paths
     */
    public function __construct(
        public array $blobs,
        public array $projections,
        public array $projectErrors,
        public array $invalidUtf8Paths,
    ) {}

    public function projectionFor(string $relativePath): ?TicketProjection
    {
        $projection = $this->projections[$relativePath] ?? null;

        return $projection?->withErrors($this->projectErrors);
    }

    public function hasInvalidUtf8(string $relativePath): bool
    {
        return in_array($relativePath, $this->invalidUtf8Paths, true);
    }
}
