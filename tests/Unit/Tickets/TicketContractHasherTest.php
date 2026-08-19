<?php

namespace Tests\Unit\Tickets;

use App\AI6\Tickets\TicketContractHasher;
use App\AI6\Tickets\TicketV1Parser;
use JsonException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class TicketContractHasherTest extends TestCase
{
    /** @throws JsonException */
    public function test_shared_golden_vectors_and_equivalence_classes_are_byte_exact(): void
    {
        $vectors = json_decode($this->fixture('golden-vectors.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($vectors as $vector) {
            $content = $this->fixture($vector['fixture']);
            self::assertSame($vector['sha256'], $this->hash($content));
        }

        $base = $this->fixture('generic-v1.md');
        $status = str_replace('status: todo', 'status: ready', $base);
        $canonicalYaml = str_replace('depends_on: []', "depends_on:\n  []", $base);
        $crlf = str_replace("\n", "\r\n", $canonicalYaml);
        self::assertSame($this->hash($base), $this->hash($status));
        self::assertSame($this->hash($base), $this->hash($crlf."\r\n\r\n"));
        self::assertSame($this->hash($base), $this->hash(rtrim($base, "\n")));
        self::assertNotSame($this->gitBlobOid($base), $this->gitBlobOid($status));
        self::assertNotSame($this->hash($base), $this->hash(str_replace('Ticketformat prüfen', 'Ticketformat genau prüfen', $base)));
        self::assertNotSame($this->hash($base), $this->hash(str_replace('## Goal', '## Goal  ', $base)));

        $ordered = str_replace('depends_on: []', 'depends_on: [AI6-001, AI6-002]', $base);
        $reordered = str_replace('depends_on: []', 'depends_on: [AI6-002, AI6-001]', $base);
        self::assertNotSame($this->hash($ordered), $this->hash($reordered));
    }

    /**
     * TKT-012: the AI6-owned `## Recorded Scope` section is documentation, not
     * contract. Writing it and writing it again leave `ticket_contract_sha256`
     * identical, while every other body change still moves it.
     */
    public function test_the_ai6_owned_recorded_scope_section_never_changes_the_contract_hash(): void
    {
        $base = $this->fixture('generic-v1.md');
        $withSection = $this->fixture('generic-v1-recorded-scope.md');
        self::assertStringContainsString('## Recorded Scope', $withSection);
        self::assertSame($this->hash($base), $this->hash($withSection));

        // Fortschreibung: a rewritten section keeps the same contract hash.
        $rewritten = str_replace('**Limitverbrauch:** 1/12', '**Limitverbrauch:** 4/12

### Runde 2

- `tests/NewTest.php` — auto_allow', $withSection);
        self::assertNotSame($withSection, $rewritten);
        self::assertSame($this->hash($base), $this->hash($rewritten));

        // The section ends at the next `##` heading; content after it is
        // contract again and still moves the hash.
        $withTrailingSection = $withSection.'
## Notes

Hinweis.
';
        self::assertNotSame($this->hash($base), $this->hash($withTrailingSection));
        self::assertSame(
            $this->hash($base.'
## Notes

Hinweis.
'),
            $this->hash($withTrailingSection),
        );

        // Only this one section is excluded; ordinary body prose still counts.
        self::assertNotSame(
            $this->hash($withSection),
            $this->hash(str_replace('Ticketformat prüfen', 'Ticketformat genau prüfen', $withSection)),
        );
        // The Git blob keeps binding the exact bytes.
        self::assertNotSame($this->gitBlobOid($base), $this->gitBlobOid($withSection));
    }

    public function test_unicode_nfc_and_jcs_special_characters_are_canonicalized(): void
    {
        $base = str_replace(
            'title: "Legacy-Migration vorbereiten"',
            'title: "Café \\"/\\" vorbereiten"',
            $this->fixture('generic-v1.md'),
        );
        $decomposed = str_replace('Café', "Cafe\u{0301}", $base);
        self::assertSame($this->hash($base), $this->hash($decomposed));
    }

    private function hash(string $content): string
    {
        $document = $this->app->make(TicketV1Parser::class)->parse($content);

        return $this->app->make(TicketContractHasher::class)->hash($document);
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(base_path('tests/Fixtures/Tickets/'.$name));
        self::assertIsString($content);

        return $content;
    }

    private function gitBlobOid(string $content): string
    {
        $process = new Process(['git', 'hash-object', '--stdin'], base_path());
        $process->setInput($content);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return trim($process->getOutput());
    }
}
