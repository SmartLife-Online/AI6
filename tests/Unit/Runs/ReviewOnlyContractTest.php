<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\RunType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewOnlyContractTest extends TestCase
{
    #[DataProvider('narrowingProvider')]
    public function test_server_rules_only_narrow_the_completion_mode(
        ReviewOnlyCompletionMode $approved,
        ReviewOnlyCompletionMode $maximum,
        ReviewOnlyCompletionMode $expected,
    ): void {
        self::assertSame($expected, $approved->narrowedTo($maximum));
    }

    /** @return iterable<string, array{ReviewOnlyCompletionMode, ReviewOnlyCompletionMode, ReviewOnlyCompletionMode}> */
    public static function narrowingProvider(): iterable
    {
        yield 'automatic remains automatic when allowed' => [ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES, ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES, ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES];
        yield 'automatic narrows to manual' => [ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES, ReviewOnlyCompletionMode::MANUAL, ReviewOnlyCompletionMode::MANUAL];
        yield 'manual stays manual' => [ReviewOnlyCompletionMode::MANUAL, ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES, ReviewOnlyCompletionMode::MANUAL];
    }

    public function test_manual_approval_cannot_be_broadened_to_automatic(): void
    {
        $this->expectException(RunTransitionConflict::class);
        ReviewOnlyCompletionMode::AUTOMATIC_AFTER_GATES->assertNotBroadenedFrom(ReviewOnlyCompletionMode::MANUAL);
    }

    public function test_run_type_is_closed(): void
    {
        self::assertSame(['implementation', 'review_only'], array_map(
            static fn (RunType $type): string => $type->value,
            RunType::cases(),
        ));
    }
}
