<?php

namespace Tests\Feature\Runs;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Runs\RetentionLimit;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checks\BuildsCheckFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * AC-09 and AC-13 of AI6-031: the trusted check-log size limit binds at the
 * persistence of a real check result — after the central redaction, with the
 * visible marker inside the budget — not only at the shown excerpt.
 */
final class CheckLogRetentionLimitTest extends TicketUiTestCase
{
    use BuildsCheckFixture;

    private const CLEARTEXT = 'super-secret-check-cleartext';

    public function test_the_check_log_size_limit_binds_at_persistence_after_the_redaction(): void
    {
        config(['ai6.retention.check_logs.max_bytes' => 96]);
        $this->app->forgetInstance(CheckRunner::class);
        $line = 'password='.self::CLEARTEXT.' '.str_repeat('ü', 200);
        $this->bindCheckRuntime(['probe-long' => $this->probeProfile(['ai6-check-long.php'])]);
        ['run' => $run, 'worktree' => $worktree] = $this->checkableRun('AI6-031-CHECKSIZE');
        self::assertNotFalse(file_put_contents(
            $worktree.'/ai6-check-long.php',
            "<?php\n\nfwrite(STDOUT, ".var_export($line, true)." . \"\\n\");\nexit(0);\n",
        ));

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-long');

        self::assertSame(CheckResultState::SUCCEEDED, $record->state);
        $stored = DB::table('check_results')->where('id', $record->id)->value('redacted_output');
        self::assertIsString($stored);
        self::assertLessThanOrEqual(96, strlen($stored), 'The persisted output stays within the trusted limit, marker included.');
        self::assertStringEndsWith(RetentionLimit::TRUNCATION_MARKER, $stored);
        self::assertStringStartsWith('password='.RedactionMatchType::SECRET->marker().' ', $stored, 'The redaction ran on the complete text before the cut.');
        self::assertSame(1, preg_match('//u', $stored), 'The cut never splits a multibyte character.');
        self::assertStringNotContainsString(self::CLEARTEXT, $stored);
        foreach (DB::table('check_results')->get() as $row) {
            self::assertStringNotContainsString(self::CLEARTEXT, json_encode($row, JSON_THROW_ON_ERROR));
        }
    }
}
