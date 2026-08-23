<?php

namespace Tests\Unit\Runs;

use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\FindingOriginalDisposition;
use App\AI6\Reviews\FindingSeverity;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Runs\Models\Run;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * TC-06: every binding the ticket names invalidates a disposition on its own, and the
 * superseded entry stays readable. The drift is applied to the run and reviewer source
 * the decision reads, never to the immutable finding or disposition rows.
 */
final class FindingDispositionBindingTest extends TestCase
{
    /** @var list<string> */
    private const RUN_BINDINGS = [
        'ticket_contract_sha256',
        'config_hash',
        'scope_hash',
        'prompt_hash',
        'instruction_hash',
        'runtime_profile_hash',
        'agent_profile_hash',
        'security_policy_hash',
        'checkpoint_tree_sha',
        'checkpoint_diff_hash',
    ];

    /** @var list<string> */
    private const REVIEWER_BINDINGS = [
        'slot_id',
        'provider_profile',
        'model',
        'effort',
        'prompt_profile',
        'round_number',
    ];

    public function test_a_matching_disposition_suppresses_the_blockade(): void
    {
        $state = new EffectiveFindingState;
        $finding = $this->finding($state);

        self::assertFalse($state->blocks($finding, $this->boundRun()));
        self::assertNotNull($state->currentDisposition($finding, $this->boundRun()));
    }

    public function test_every_run_binding_invalidates_the_disposition_on_its_own(): void
    {
        $state = new EffectiveFindingState;

        foreach (self::RUN_BINDINGS as $field) {
            $finding = $this->finding($state);
            $drifted = $this->boundRun();
            $drifted->setAttribute($field, str_repeat('f', 64));

            self::assertTrue(
                $state->blocks($finding, $drifted),
                $field.' did not invalidate the disposition.',
            );
            self::assertNull($state->currentDisposition($finding, $drifted));
            // The superseded decision is invalidated, never deleted.
            self::assertCount(1, $finding->getRelation('dispositions'));
            self::assertSame('Begründung.', $finding->getRelation('dispositions')->first()?->reason);
        }
    }

    public function test_every_reviewer_source_binding_invalidates_the_disposition_on_its_own(): void
    {
        $state = new EffectiveFindingState;

        foreach (self::REVIEWER_BINDINGS as $field) {
            $finding = $this->finding($state);
            $finding->setAttribute($field, $field === 'round_number' ? 7 : 'drifted-'.$field);

            self::assertTrue(
                $state->blocks($finding, $this->boundRun()),
                $field.' did not invalidate the disposition.',
            );
            self::assertNull($state->currentDisposition($finding, $this->boundRun()));
        }
    }

    public function test_the_binding_set_is_exactly_the_declared_contract(): void
    {
        $state = new EffectiveFindingState;
        $finding = $this->finding($state);
        $disposition = $finding->getRelation('dispositions')->first();
        self::assertInstanceOf(FindingDisposition::class, $disposition);

        // A binding that the disposition row does not carry cannot be compared, so a
        // silently dropped field would make the disposition permanently valid.
        foreach ([...self::RUN_BINDINGS, 'reviewer_binding_hash'] as $field) {
            $column = $field === 'checkpoint_diff_hash' ? 'diff_hash' : $field;
            self::assertIsString(
                $disposition->getAttribute($column),
                $column.' is not bound on the disposition.',
            );
        }
    }

    private function boundRun(): Run
    {
        $run = new Run;
        $run->forceFill([
            'id' => 'run-1',
            'ticket_contract_sha256' => str_repeat('1', 64),
            'config_hash' => str_repeat('2', 64),
            'scope_hash' => str_repeat('3', 64),
            'prompt_hash' => str_repeat('4', 64),
            'instruction_hash' => str_repeat('5', 64),
            'runtime_profile_hash' => str_repeat('6', 64),
            'agent_profile_hash' => str_repeat('7', 64),
            'security_policy_hash' => str_repeat('8', 64),
            'checkpoint_tree_sha' => str_repeat('9', 64),
            'checkpoint_diff_hash' => str_repeat('a', 64),
        ]);

        return $run;
    }

    private function finding(EffectiveFindingState $state): Finding
    {
        $finding = new Finding;
        $finding->forceFill([
            'id' => 'finding-1',
            'severity' => FindingSeverity::HIGH,
            'original_disposition' => FindingOriginalDisposition::MUST_FIX,
            'slot_id' => 'slot-1',
            'provider_profile' => 'fake',
            'model' => 'fake-model',
            'effort' => 'high',
            'prompt_profile' => 'security',
            'round_number' => 1,
        ]);

        $run = $this->boundRun();
        $disposition = new FindingDisposition;
        $disposition->forceFill([
            'id' => 'disposition-1',
            'finding_id' => 'finding-1',
            'reason' => 'Begründung.',
            'expected_run_version' => 1,
            'ticket_contract_sha256' => $run->ticket_contract_sha256,
            'config_hash' => $run->config_hash,
            'scope_hash' => $run->scope_hash,
            'prompt_hash' => $run->prompt_hash,
            'instruction_hash' => $run->instruction_hash,
            'runtime_profile_hash' => $run->runtime_profile_hash,
            'agent_profile_hash' => $run->agent_profile_hash,
            'security_policy_hash' => $run->security_policy_hash,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'reviewer_binding_hash' => $state->reviewerBindingHash($finding),
        ]);
        $finding->setRelation('dispositions', new Collection([$disposition]));

        return $finding;
    }
}
