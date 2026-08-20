<?php

namespace Tests\Unit\Checks;

use App\AI6\Checks\CheckExecutionContractException;
use App\AI6\Checks\CheckExecutionRequest;
use App\AI6\Checks\CheckExecutionResultDocument;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckExecutionDocumentTest extends TestCase
{
    public function test_request_and_result_round_trip_with_closed_bindings(): void
    {
        $request = new CheckExecutionRequest('execution-1', 'run-1', CheckPhase::BEFORE_REVIEW, 'php-targeted', str_repeat('a', 64), str_repeat('b', 64), 2_000_000_000);
        self::assertEquals($request, CheckExecutionRequest::fromJson($request->toJson()));

        $result = new CheckExecutionResultDocument(
            'execution-1', 'run-1', CheckPhase::BEFORE_REVIEW, 'php-targeted', str_repeat('c', 32),
            2_000_000_000, 1_999_999_999, CheckResultState::SUCCEEDED, null, 0, 12, 'ok', str_repeat('a', 64),
            str_repeat('d', 64), str_repeat('b', 64), str_repeat('b', 64),
            ['side_effects' => false, 'network' => false, 'mutates' => false],
        );
        self::assertEquals($result, CheckExecutionResultDocument::fromJson($result->toJson()));
    }

    public function test_a_timeout_may_complete_just_after_its_bound_deadline(): void
    {
        $result = new CheckExecutionResultDocument(
            'execution-timeout', 'run-1', CheckPhase::BEFORE_REVIEW, 'php-targeted', str_repeat('c', 32),
            100, 101, CheckResultState::TIMED_OUT, 'process_timeout', null, 1000, '', str_repeat('a', 64),
            str_repeat('a', 64), str_repeat('b', 64), str_repeat('b', 64),
            ['side_effects' => false, 'network' => false, 'mutates' => false],
        );

        self::assertSame(CheckResultState::TIMED_OUT, CheckExecutionResultDocument::fromJson($result->toJson())->state);
    }

    #[DataProvider('forbiddenRequestFields')]
    public function test_execution_definitions_and_unknown_fields_are_rejected(string $field, mixed $value): void
    {
        $document = json_decode((new CheckExecutionRequest('execution-1', 'run-1', CheckPhase::BEFORE_REVIEW, 'php-targeted', str_repeat('a', 64), str_repeat('b', 64), 2_000_000_000))->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $document[$field] = $value;

        $this->expectException(CheckExecutionContractException::class);
        CheckExecutionRequest::fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function forbiddenRequestFields(): iterable
    {
        yield 'program' => ['program', '/usr/bin/php'];
        yield 'arguments' => ['arguments', ['artisan', 'test']];
        yield 'working directory' => ['working_directory', 'tree'];
        yield 'metadata' => ['metadata', ['network' => false]];
        yield 'unknown' => ['extra', true];
    }
}
