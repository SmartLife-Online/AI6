<?php

namespace App\AI6\Shared\Process;

use App\AI6\Checks\CheckerExecutionProcessor;
use App\AI6\Checks\CheckerRuntimeAttestation;
use App\AI6\Checks\CheckerRuntimeConfiguration;
use App\AI6\Checks\CheckExecutionContractException;
use App\AI6\Checks\CheckProfileConfigurationException;
use Illuminate\Console\Command;
use Throwable;

final class ExecutionMailboxCommand extends Command
{
    protected $signature = 'ai6:execution-mailbox {role : agent or checker} {--once}';

    protected $description = 'Run the isolated role-specific execution mailbox loop';

    public function handle(): int
    {
        $role = ExecutionRole::tryFrom((string) $this->argument('role'));
        if ($role === null || config('ai6.runtime_role') !== $role->value) {
            $this->components->error('The execution mailbox role binding is invalid.');

            return self::INVALID;
        }

        $root = config('ai6.execution_mailboxes.'.$role->value.'_root');
        $heartbeatDirectory = getenv('AI6_HEARTBEAT_DIRECTORY');
        $interval = getenv('AI6_HEARTBEAT_INTERVAL');
        if (! is_string($root) || ! is_dir($root) || is_link($root)
            || ! is_string($heartbeatDirectory) || $heartbeatDirectory !== '/run/ai6/heartbeat/'.$role->value
            || ! is_dir($heartbeatDirectory) || ! is_string($interval) || preg_match('/\A[1-9][0-9]*\z/D', $interval) !== 1) {
            $this->components->error('The execution mailbox runtime boundary is unavailable.');

            return self::FAILURE;
        }

        $bootId = trim((string) file_get_contents($heartbeatDirectory.'/boot-id'));
        if (preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1) {
            $this->components->error('The execution mailbox boot binding is invalid.');

            return self::FAILURE;
        }

        do {
            $requests = glob($root.'/requests/*.json', GLOB_NOSORT);
            $this->heartbeat($heartbeatDirectory, $role, $bootId, is_array($requests) ? count($requests) : 0);
            if ($role === ExecutionRole::CHECKER) {
                try {
                    app(CheckerRuntimeAttestation::class)->publish($bootId);
                    app(CheckerExecutionProcessor::class)->processNext($bootId, function (string $_executionId) use ($heartbeatDirectory, $role, $bootId): void {
                        $this->heartbeat($heartbeatDirectory, $role, $bootId, 0);
                        app(CheckerRuntimeAttestation::class)->publish($bootId);
                    });
                } catch (CheckExecutionContractException|CheckProfileConfigurationException $exception) {
                    report($exception);
                    $this->components->error('Checkerauftrag abgewiesen: '.$exception->reason.'.');
                } catch (MailboxRejectedException $exception) {
                    report($exception);
                    $this->components->error('Checkerumschlag abgewiesen: '.$exception->reason->value.'.');
                } catch (Throwable $exception) {
                    report($exception);
                    $this->components->error('A checker request was rejected or failed.');
                }
            }
            if ($this->option('once')) {
                break;
            }
            sleep($role === ExecutionRole::CHECKER
                ? app(CheckerRuntimeConfiguration::class)->pollIntervalSeconds
                : (int) $interval);
        } while (true);

        return self::SUCCESS;
    }

    private function heartbeat(string $directory, ExecutionRole $role, string $bootId, int $pending): void
    {
        $document = json_encode([
            'role' => $role->value,
            'boot_id' => $bootId,
            'recorded_at' => time(),
            'mailbox_pending' => $pending,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $directory.'/heartbeat.json.tmp';
        if (file_put_contents($temporary, $document, LOCK_EX) !== strlen($document) || ! rename($temporary, $directory.'/heartbeat.json')) {
            throw new \RuntimeException('The execution mailbox heartbeat could not be published atomically.');
        }
    }
}
