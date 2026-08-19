<?php

namespace Tests\Feature\Checks;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-07: the same sensitive string is masked identically in a check output, in a
 * ticket read model and through the central redactor the provider output uses,
 * and the removed cleartext exists neither in the database nor in the output.
 */
final class CheckRedactionTest extends TicketUiTestCase
{
    use BuildsCheckFixture;

    private const CLEARTEXT = 'super-secret-check-cleartext';

    public function test_a_check_output_is_masked_exactly_like_a_read_model_and_provider_output(): void
    {
        $line = 'password='.self::CLEARTEXT;
        $this->bindCheckRuntime(['probe-masked' => $this->probeProfile(['ai6-check-masked.php'])]);
        ['run' => $run, 'worktree' => $worktree] = $this->checkableRun('AI6-021-REDACT');
        self::assertNotFalse(file_put_contents(
            $worktree.'/ai6-check-masked.php',
            "<?php\n\nfwrite(STDOUT, ".var_export($line, true)." . \"\\n\");\nexit(0);\n",
        ));

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-masked');
        self::assertSame(CheckResultState::SUCCEEDED, $record->state);

        $redactor = $this->app->make(Redactor::class);
        $expected = 'password='.RedactionMatchType::SECRET->marker();

        // The identical bytes under the read model, provider output and check
        // contexts produce the identical mask, because all three cross this one
        // boundary. Only the per-context fingerprints differ.
        $masked = [];
        foreach (['ticket-read-model', 'implementation-turn', 'check:before_review'] as $identifier) {
            $result = $redactor->redact($line, new RedactionContext((string) $run->project_id, $run->id, $identifier));
            self::assertSame($expected, $result->text, $identifier);
            $masked[] = $result->text;
        }
        self::assertCount(1, array_unique($masked));

        // The check output the runner persisted is exactly that same mask.
        self::assertSame($expected, $record->redacted_output);

        // Nothing anywhere in the persisted check result carries the cleartext.
        self::assertStringNotContainsString(self::CLEARTEXT, $record->redacted_output);
        foreach (DB::table('check_results')->get() as $row) {
            self::assertStringNotContainsString(self::CLEARTEXT, json_encode($row, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * AC-08 structural half: that the check module owns no secret or redaction
     * pattern list is already enforced repository-wide by
     * Tests\Unit\Shared\Redaction\RedactionArchitectureTest, which scans `app/`
     * — `app/AI6/Checks/` included — and allowlists only the redaction module
     * itself. Re-listing those patterns here would itself be the violation that
     * test forbids, so this file proves only the behavioural half above.
     */
    public function test_the_check_module_is_not_exempt_from_the_central_redaction_architecture(): void
    {
        $allowlist = (string) file_get_contents(base_path('tests/Unit/Shared/Redaction/RedactionArchitectureTest.php'));

        self::assertStringNotContainsString('/app/AI6/Checks/', $allowlist);
        self::assertDirectoryExists(app_path('AI6/Checks'));
    }
}
