<?php

namespace Tests\Unit\Reviews;

use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\ExactFindingGroup;
use App\AI6\Reviews\FindingCategory;
use App\AI6\Reviews\FindingOriginalDisposition;
use App\AI6\Reviews\FindingSeverity;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Runs\Models\Run;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class FindingStateTest extends TestCase
{
    public function test_one_must_fix_blocks_without_any_majority_and_advisory_values_do_not(): void
    {
        $state = new EffectiveFindingState;
        $run = new Run;

        // Plan §8.1: only an open must_fix or human_required blocks. The severity is
        // descriptive metadata and never decides the blockade on its own.
        self::assertTrue($state->blocks($this->finding(FindingSeverity::CRITICAL, FindingOriginalDisposition::MUST_FIX), $run));
        self::assertTrue($state->blocks($this->finding(FindingSeverity::LOW, FindingOriginalDisposition::MUST_FIX), $run));
        self::assertTrue($state->blocks($this->finding(FindingSeverity::MEDIUM, FindingOriginalDisposition::HUMAN_REQUIRED), $run));
        self::assertFalse($state->blocks($this->finding(FindingSeverity::HIGH, FindingOriginalDisposition::OPEN), $run));
        self::assertFalse($state->blocks($this->finding(FindingSeverity::CRITICAL, FindingOriginalDisposition::SUGGESTION), $run));
        self::assertFalse($state->blocks($this->finding(FindingSeverity::HIGH, FindingOriginalDisposition::FOLLOW_UP), $run));
    }

    public function test_only_byte_exact_normalized_findings_share_a_group(): void
    {
        $arguments = [
            FindingSeverity::MUST_FIX,
            FindingOriginalDisposition::OPEN,
            FindingCategory::CONTRACT,
            'app/Example.php',
            7,
            'Titel',
            'Evidenz',
            'Erwartung',
            ['AC-01', 'AC-02'],
        ];
        $first = ExactFindingGroup::key(...$arguments);
        $second = ExactFindingGroup::key(...$arguments);
        $similar = ExactFindingGroup::key(...array_replace($arguments, [5 => 'Titel.']));
        $reordered = ExactFindingGroup::key(...array_replace($arguments, [8 => ['AC-02', 'AC-01']]));

        self::assertSame($first, $second);
        self::assertNotSame($first, $similar);
        self::assertSame($first, $reordered);
    }

    public function test_a_disposition_for_another_finding_cannot_suppress_a_blockade(): void
    {
        $state = new EffectiveFindingState;
        $finding = $this->finding(FindingSeverity::MUST_FIX, FindingOriginalDisposition::MUST_FIX);
        $finding->id = 'finding-a';
        $foreign = new FindingDisposition;
        $foreign->finding_id = 'finding-b';

        self::assertTrue($state->blocks($finding, new Run, $foreign));
    }

    private function finding(FindingSeverity $severity, FindingOriginalDisposition $disposition): Finding
    {
        $finding = new Finding;
        $finding->forceFill([
            'severity' => $severity,
            'original_disposition' => $disposition,
        ]);
        $finding->setRelation('dispositions', new Collection);

        return $finding;
    }
}
