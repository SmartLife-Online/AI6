<?php

namespace Tests\Unit\Runs;

use App\AI6\Tickets\TicketContractHasher;
use App\AI6\Tickets\TicketV1Parser;
use JsonException;
use Tests\TestCase;

final class ApprovalContractHashTest extends TestCase
{
    /** @throws JsonException */
    public function test_approval_contract_hash_uses_the_parser_golden_vectors_and_ignores_status_only_changes(): void
    {
        $vectorsJson = file_get_contents(base_path('tests/Fixtures/Tickets/golden-vectors.json'));
        self::assertIsString($vectorsJson);
        $vectors = json_decode($vectorsJson, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($vectors);

        foreach ($vectors as $vector) {
            self::assertIsArray($vector);
            $content = file_get_contents(base_path('tests/Fixtures/Tickets/'.$vector['fixture']));
            self::assertIsString($content);
            $ready = str_replace('status: todo', 'status: ready', $content);

            self::assertSame($vector['sha256'], $this->hash($content));
            self::assertSame($vector['sha256'], $this->hash($ready));
        }
    }

    private function hash(string $content): string
    {
        $document = $this->app->make(TicketV1Parser::class)->parse($content);

        return $this->app->make(TicketContractHasher::class)->hash($document);
    }
}
