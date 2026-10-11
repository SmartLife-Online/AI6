<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\NativeSessionProjection;
use App\AI6\Shared\Process\AgentProcessScope;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Process\RunningControlProcess;
use App\AI6\Shared\Redaction\RedactionContext;

/** Fixed test payload executed only by the existing read-only agent launcher. */
final class NativeSessionScopeProbe
{
    /** @return array<string, bool|string|null> */
    public static function run(): array
    {
        $input = config('ai6.fixture.native_session_scope_probe');
        if (! is_array($input) || ! is_array($input['home'] ?? null) || ! is_bool($input['unconfirmed'] ?? null)
            || ! is_array($input['home']['nativeSessionProjection'] ?? null)) {
            throw new \RuntimeException('The fixed session probe input is invalid.');
        }
        $values = $input['home'];
        $session = new NativeSessionProjection(...$values['nativeSessionProjection']);
        $values['nativeSessionProjection'] = $session;
        $home = new ExecutionHome(...$values);
        if ($input['unconfirmed']) {
            config(['ai6.process.process_group_kill_binary' => '/usr/bin/true']);
            app()->forgetInstance(ProcessConfiguration::class);
            app()->forgetInstance(ControlProcessRunner::class);
        }
        $released = null;
        $running = null;
        $rejected = false;
        $message = '';
        $scope = new AgentProcessScope([$home->root], [dirname($session->source), dirname(dirname($session->source)).'/artifacts'],
            nativeSessionProjection: $session, onRelease: static function (bool $quiescent) use (&$released): void {
                $released = $quiescent;
            });
        $runner = app(ControlProcessRunner::class);
        try {
            $runner->withinAgentScope($scope, static function () use ($runner, $home, $session, &$running): void {
                $running = $runner->start(new ProcessRequest([PHP_BINARY, '-r', 'echo "scope-payload-started\n"; flush(); sleep(10);'], $home->workspace,
                    [], [], new RedactionContext('test', null, 'native-session'), policy: ProcessPolicyName::AGENT,
                    resultDirectory: dirname($session->source), artifactDirectory: dirname(dirname($session->source)).'/artifacts'));
                if (! $running->waitForOutputPrefix('__AI6_PROCESS_STARTED_V1__:'.$running->processId."\nscope-payload-started\n", 5)) {
                    throw new \RuntimeException('The fixed scope payload did not start.');
                }
            });
        } catch (ProcessStartRejectedException $exception) {
            $rejected = true;
            $message = $exception->getMessage();
        }

        return self::result($running, $released, $rejected, $message);
    }

    /** @return array<string, bool|string|null> */
    private static function result(?RunningControlProcess $running, ?bool $released, bool $rejected, string $message): array
    {
        return ['released' => $released, 'rejected' => $rejected, 'message' => $message,
            'running' => $running?->running(), 'quiescent' => $running?->quiescent()];
    }
}
