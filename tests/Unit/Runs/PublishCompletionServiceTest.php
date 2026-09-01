<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\PublishCompletionService;
use App\AI6\Runs\RunTransitionConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishCompletionServiceTest extends TestCase
{
    #[DataProvider('validPushModes')]
    public function test_push_mode_resolution_accepts_only_the_two_bound_values(string $mode): void
    {
        self::assertSame($mode, PublishCompletionService::resolvePushMode($mode));
    }

    /** @return iterable<string, array{string}> */
    public static function validPushModes(): iterable
    {
        yield 'manual' => ['manual'];
        yield 'automatic after gates' => ['automatic_after_gates'];
    }

    #[DataProvider('invalidPushModes')]
    public function test_push_mode_resolution_rejects_every_other_source_value(string $mode): void
    {
        try {
            PublishCompletionService::resolvePushMode($mode);
            self::fail('An invalid publish mode was accepted.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('push_mode_invalid', $conflict->reason);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPushModes(): iterable
    {
        yield 'empty' => [''];
        yield 'truncated automatic' => ['automatic'];
        yield 'project supplied variant' => ['automatic_after_checks'];
        yield 'case variant' => ['MANUAL'];
    }
}
