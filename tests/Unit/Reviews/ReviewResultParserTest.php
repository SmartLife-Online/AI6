<?php

namespace Tests\Unit\Reviews;

use App\AI6\Agents\AgentFinding;
use App\AI6\Agents\AgentResult;
use App\AI6\Agents\AgentResultStatus;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewResultParseException;
use App\AI6\Reviews\ReviewResultParser;
use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\TestCase;

final class ReviewResultParserTest extends TestCase
{
    public function test_unknown_control_values_fail_with_a_named_reason_before_persistence(): void
    {
        foreach ([
            ['unknown', 'open', 'contract', 'finding_severity_unknown'],
            ['must_fix', 'unknown', 'contract', 'finding_disposition_unknown'],
            ['must_fix', 'open', 'unknown', 'finding_category_unknown'],
        ] as [$severity, $disposition, $category, $reason]) {
            $result = new AgentResult(
                'ai6.quality-review.v1',
                AgentResultStatus::FINDINGS_TO_FIX,
                'Zusammenfassung',
                null,
                [new AgentFinding('F-1', $severity, $disposition, $category, 'app/A.php', 1, 'Titel', 'Evidenz', 'Erwartung', ['AC-01'])],
                [],
                null,
            );
            try {
                $this->app->make(ReviewResultParser::class)->persist(
                    new ReviewResult,
                    new Run,
                    $result,
                    new RedactionContext('project', 'run', 'review-result'),
                );
                self::fail('An unknown review control value was accepted.');
            } catch (ReviewResultParseException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
    }
}
