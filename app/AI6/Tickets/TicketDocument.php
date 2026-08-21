<?php

namespace App\AI6\Tickets;

final readonly class TicketDocument
{
    /** @param array<string, mixed> $frontmatter
     * @param  array<string, string>  $sections
     * @param  list<string>  $duplicateSections
     * @param  list<string>  $acceptanceCriterionIds
     * @param  list<string>  $testCaseIds
     * @param  list<string>  $manualGateIds
     * @param  list<string>  $externalGateIds
     * @param  list<string>  $files
     * @param  list<string>  $specRefs
     */
    public function __construct(
        public array $frontmatter,
        public string $body,
        public array $sections,
        public array $duplicateSections,
        public array $acceptanceCriterionIds,
        public array $testCaseIds,
        public array $manualGateIds,
        public array $externalGateIds,
        public array $files,
        public array $specRefs,
    ) {}
}
