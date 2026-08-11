<?php

namespace Tests\Unit\Tickets;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\StrictEnumParser;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Tickets\Ai6DetailV1TicketValidator;
use App\AI6\Tickets\GenericV1TicketValidator;
use App\AI6\Tickets\LegacyTicketReader;
use App\AI6\Tickets\TicketParseException;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketV1Parser;
use App\AI6\Tickets\TicketValidationConfiguration;
use App\AI6\Tickets\TicketValidationProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TicketParserAndValidationTest extends TestCase
{
    public function test_v1_parser_extracts_generic_and_detail_contract_fields_only(): void
    {
        $parser = $this->app->make(TicketV1Parser::class);
        $generic = $parser->parse($this->fixture('generic-v1.md'));
        $detail = $parser->parse($this->fixture('ai6-detail-v1.md'));

        self::assertSame('Das generische Ticketformat prüfen.', $generic->sections['Goal']);
        self::assertSame(['app/AI6/Tickets/'], $detail->files);
        self::assertSame(['docs/AI6_IMPLEMENTATION_PLAN.md — TKT-002'], $detail->specRefs);
        self::assertSame(['AC-01'], $detail->acceptanceCriterionIds);
        self::assertSame(['TC-01'], $detail->testCaseIds);
        self::assertStringContainsString('Plan §5.2', $detail->sections['Goal']);
        self::assertCount(1, $detail->specRefs);

        self::assertSame([], $this->app->make(GenericV1TicketValidator::class)->validate($generic, 'tickets/M169.md'));
        self::assertSame([], $this->app->make(Ai6DetailV1TicketValidator::class)->validate($detail, 'tickets/AI6-099.md'));
    }

    public function test_legacy_reader_preserves_every_field_and_projector_never_treats_it_as_v1(): void
    {
        $content = $this->fixture('legacy-m169.md');
        $legacy = $this->app->make(LegacyTicketReader::class)->read($content);
        self::assertSame('M169', $legacy->fields['id']);
        self::assertSame(['migration', 'historisch'], $legacy->fields['tags']);
        self::assertSame(['note' => 'vollständig erhalten'], $legacy->fields['metadata']);

        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $content, 'tickets/M169.md', TicketValidationProfile::GENERIC_V1,
        );
        self::assertSame('invalid', $projection->state->value);
        self::assertNull($projection->contractHash);
        self::assertSame('legacy_format', $projection->errors[0]->code);
    }

    #[DataProvider('forbiddenYamlProvider')]
    public function test_restricted_yaml_rejects_forbidden_features(string $yaml, string $code): void
    {
        $content = "---\n".$yaml."\n---\n\n## Goal\n\nZiel.\n";
        try {
            $this->app->make(TicketV1Parser::class)->parse($content);
            self::fail('Forbidden YAML was accepted.');
        } catch (TicketParseException $exception) {
            self::assertSame($code, $exception->errors[0]->code);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenYamlProvider(): iterable
    {
        $base = "schema: ai6.ticket.v1\nid: M169\ntitle: \"Titel\"\nstatus: todo\ndepends_on: []";
        yield 'anchor' => [$base."\nvalue: &copy x", 'yaml_anchor_forbidden'];
        yield 'alias' => [$base."\nvalue: *copy", 'yaml_alias_forbidden'];
        yield 'merge key' => [$base."\n<<: {}", 'yaml_merge_key_forbidden'];
        yield 'tag' => [$base."\nvalue: !custom x", 'yaml_tag_forbidden'];
        yield 'duplicate' => [$base."\nid: M170", 'yaml_parse_error'];
        yield 'unknown' => [$base."\nunknown: x", 'yaml_unknown_key'];
    }

    #[DataProvider('invalidFieldProvider')]
    public function test_field_and_path_validation_is_deterministic(string $search, string $replacement, string $code): void
    {
        $content = str_replace($search, $replacement, $this->fixture('generic-v1.md'));
        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $content, 'tickets/M169.md', TicketValidationProfile::GENERIC_V1,
        );
        self::assertSame('invalid', $projection->state->value);
        self::assertContains($code, array_column(array_map(fn ($error) => $error->jsonSerialize(), $projection->errors), 'code'));
        self::assertNull($projection->contractHash);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidFieldProvider(): iterable
    {
        yield 'lowercase id' => ['id: M169', 'id: m169', 'id_invalid'];
        yield 'overlong id' => ['id: M169', 'id: AI6-123456789012345678901234567890', 'id_invalid'];
        yield 'id without digit' => ['id: M169', 'id: MABC', 'id_invalid'];
        yield 'status' => ['status: todo', 'status: unknown', 'status_invalid'];
        yield 'dependency form' => ['depends_on: []', 'depends_on: M168', 'depends_on_invalid'];
        yield 'filename mismatch' => ['id: M169', 'id: M170', 'filename_id_mismatch'];
        yield 'acceptance criterion id' => ['## Goal', "- [ ] **AC-1** Ungültig.\n\n## Goal", 'ac_id_invalid'];
        yield 'test case id' => ['## Goal', "- **TC-1** Ungültig.\n\n## Goal", 'tc_id_invalid'];
    }

    #[DataProvider('invalidPathProvider')]
    public function test_files_paths_cannot_escape_or_use_noncanonical_syntax(string $path): void
    {
        $content = str_replace('depends_on: []', "depends_on: []\nfiles:\n  - \"".addcslashes($path, '\\"').'"', $this->fixture('generic-v1.md'));
        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $content, 'tickets/M169.md', TicketValidationProfile::GENERIC_V1,
        );
        self::assertContains('file_path_invalid', array_map(fn ($error) => $error->code, $projection->errors));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPathProvider(): iterable
    {
        foreach (['/absolute', '../escape', 'a//b', 'a/../b', 'a\\b', "a\x01b", 'src/*.php', 'src/!generated', 'C:/drive'] as $path) {
            yield $path => [$path];
        }
    }

    public function test_profiles_are_separate_and_repository_content_cannot_select_one(): void
    {
        $document = $this->app->make(TicketV1Parser::class)->parse($this->fixture('generic-v1.md'));
        self::assertSame([], $this->app->make(GenericV1TicketValidator::class)->validate($document, 'tickets/M169.md'));
        self::assertNotSame([], $this->app->make(Ai6DetailV1TicketValidator::class)->validate($document, 'tickets/M169.md'));
        self::assertSame('generic_v1', config('ai6.tickets.validation_profile'));
    }

    public function test_generic_spec_refs_are_structural_while_ai6_detail_refs_are_plan_canonical(): void
    {
        $genericContent = str_replace(
            'depends_on: []',
            "depends_on: []\nspec_refs:\n  - \"specs/product.md — REQ-42\"",
            $this->fixture('generic-v1.md'),
        );
        $generic = $this->app->make(TicketReadModelProjector::class)->project(
            $genericContent,
            'tickets/M169.md',
            TicketValidationProfile::GENERIC_V1,
        );
        self::assertSame('valid', $generic->state->value);

        $detailContent = str_replace(
            'docs/AI6_IMPLEMENTATION_PLAN.md — TKT-002',
            'specs/product.md — REQ-42',
            $this->fixture('ai6-detail-v1.md'),
        );
        $detail = $this->app->make(TicketReadModelProjector::class)->project(
            $detailContent,
            'tickets/AI6-099.md',
            TicketValidationProfile::AI6_DETAIL_V1,
        );
        self::assertContains('spec_ref_invalid', array_map(static fn ($error): string => $error->code, $detail->errors));
    }

    public function test_ticket_candidate_limit_is_positive_bounded_server_configuration(): void
    {
        $enum = new StrictEnumParser;
        $integer = new StrictPositiveIntegerParser;
        $configuration = TicketValidationConfiguration::fromConfiguredValues($enum, $integer);
        self::assertSame(100, $configuration->maxCandidates);

        config(['ai6.tickets.max_candidates' => '0']);
        $this->expectException(ConfigurationException::class);
        TicketValidationConfiguration::fromConfiguredValues($enum, $integer);
    }

    public function test_ai6_007_scope_and_candidate_limit_operations_contract_stay_synchronized(): void
    {
        $ticket = file_get_contents(base_path('tickets/AI6-007.md'));
        self::assertIsString($ticket);
        $document = $this->app->make(TicketV1Parser::class)->parse($ticket);
        self::assertSame(1, preg_match(
            '/\*\*Expected initial scope:\*\*\R\R(?<scope>.+?)\R\R\*\*Sensitive paths:\*\*/s',
            $ticket,
            $scopeBlock,
        ));
        self::assertSame(1, preg_match_all(
            '/^- `(?<path>[^`]+)` — (?:new|existing)$/m',
            $scopeBlock['scope'],
            $scopePaths,
        ) > 0 ? 1 : 0);
        self::assertSame($document->files, $scopePaths['path']);
        self::assertStringContainsString('Eine projektbezogene Kandidatengrenze existiert derzeit nicht', $ticket);
        self::assertStringNotContainsString('projektweise Profil- und Kandidatengrenzwahl', $ticket);

        $readme = file_get_contents(base_path('README.md'));
        self::assertIsString($readme);
        self::assertStringContainsString('sperrt damit jeden Einzelpfad-Refresh dieses Projekts', $readme);
        self::assertStringContainsString('Eine projektbezogene Kandidatengrenze existiert derzeit nicht', $readme);
        self::assertStringContainsString('gilt ausschließlich die instanzweite Grenze `AI6_TICKET_MAX_CANDIDATES`', $readme);
        self::assertStringNotContainsString('AI6-010 übernimmt neben `tickets_path` und Profilwahl ausdrücklich auch die projektbezogene Kandidatengrenzwahl', $readme);
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(base_path('tests/Fixtures/Tickets/'.$name));
        self::assertIsString($content);

        return $content;
    }
}
