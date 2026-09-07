<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Closure;
use Throwable;

/** Database-free supervisor. Only this consumer authorizes the fixture containment path. */
final class AgentExecutionProcessor
{
    private static bool $executing = false;

    public function __construct(
        private readonly ExecutionMailboxFactory $mailboxes,
        private readonly ProviderRuntimeProfileRegistry $profiles,
        private readonly CredentialRevisionRegistry $revisions,
        private readonly ProcessPolicyRegistry $policies,
        private readonly Redactor $redactor,
    ) {}

    public static function executing(): bool
    {
        return self::$executing;
    }

    /** @param Closure(string): void $heartbeat */
    public function processNext(string $bootId, Closure $heartbeat): bool
    {
        $this->publishBoot($bootId);
        $mailbox = $this->mailboxes->forRole(ExecutionRole::AGENT);
        if (glob(self::inputRoot().'/requests/*.json') === []) {
            return false;
        }
        $claimed = self::withLifecycleLock(function () use ($mailbox, $bootId): ?array {
            $message = $mailbox->claimNext(MailboxMessageType::REQUEST, new RedactionContext('agent', null, 'turn-request'));
            if ($message === null) {
                return null;
            }
            $request = AgentExecutionRequest::fromJson($message->content);
            if ($message->slotId !== $request->string('slot_id') || $message->deliveryId !== $request->deliveryId()
                || $request->integer('deadline_at') <= time()) {
                throw new AgentExecutionException('agent_request_envelope_binding_invalid');
            }
            $home = self::home($request);
            self::writeDocument(self::outputRoot().'/claims/'.$request->string('execution_id').'.json', [
                'schema' => 'ai6.agent-claim.v1', 'execution_id' => $request->string('execution_id'),
                'agent_boot_id' => $bootId, 'recorded_at' => time(),
            ]);

            return [$message, $request, $home];
        });
        if ($claimed === null) {
            return false;
        }
        [$message, $request, $home] = $claimed;
        $contextPath = dirname($home->runtimeConfiguration).'/turn.json';
        $pulse = function () use ($request, $bootId, $heartbeat, $contextPath): void {
            self::withLifecycleLock(function () use ($request, $bootId, $heartbeat, $contextPath): void {
                if (! is_file($contextPath) || is_link($contextPath) || time() >= $request->integer('deadline_at')) {
                    throw new AgentExecutionException('agent_execution_terminal');
                }
                $this->assertBoot($bootId);
                $heartbeat($request->string('execution_id'));
                $this->publishBoot($bootId);
                self::writeDocument(self::outputRoot().'/heartbeats/'.$request->string('execution_id').'.json', [
                    'schema' => 'ai6.agent-heartbeat.v1', 'execution_id' => $request->string('execution_id'),
                    'agent_boot_id' => $bootId, 'recorded_at' => time(),
                ]);
            });
        };
        $answer = new AgentTurnResult('');
        $state = 'provider_error';
        $reason = 'agent_provider_error';
        try {
            $bytes = self::readBytes($contextPath, $this->policies->get(ProcessPolicyName::AGENT)->outputLimitBytes);
            $this->redactor->assertValidInput($bytes);
            if (! hash_equals($request->string('context_hash'), hash('sha256', $bytes))) {
                throw new AgentExecutionException('agent_context_hash_invalid');
            }
            $context = AgentResultContext::fromJson($bytes, $this->profiles, $request->string('context_hash'));
            if ($context->role->value !== $request->string('role') || $context->slotId !== $request->string('slot_id')
                || $context->attempt !== $request->integer('attempt')
                || $context->promptSnapshot->hash !== $request->string('prompt_hash')
                || $context->instructionSnapshot->hash !== $request->string('instruction_hash')
                || $context->instructionSnapshot->providerProfileAlias !== $request->string('provider_alias')
                || $context->runtimeProfile->id !== $request->string('runtime_profile_id')
                || $context->runtimeProfile->hash !== $request->string('runtime_profile_hash')
                || $this->revisions->revision($request->string('provider_alias')) !== $request->string('credential_revision')) {
                throw new AgentExecutionException('agent_context_binding_invalid');
            }
            $profile = app(RestrictedJsonDecoder::class)->decode(self::readBytes($home->runtimeConfiguration, 1048576), new RedactionContext('agent', null, 'runtime-profile'));
            $expectedProfile = app(RestrictedJsonDecoder::class)->decode(json_encode($context->runtimeProfile, JSON_THROW_ON_ERROR), new RedactionContext('agent', null, 'runtime-profile'));
            if ($profile != $expectedProfile) {
                throw new AgentExecutionException('agent_runtime_binding_invalid');
            }
            $adapter = app()->makeWith(AgentAdapter::class, ['providerAlias' => $request->string('provider_alias')]);
            $pulse();
            self::$executing = true;
            try {
                $answer = $adapter->turn($context, $home, $pulse, $context->unreachablePaths);
            } finally {
                self::$executing = false;
            }
            $this->redactor->assertValidInput($answer->bytes);
            if (strlen($answer->bytes) > $this->policies->get(ProcessPolicyName::AGENT)->outputLimitBytes) {
                throw new AgentExecutionException('agent_answer_output_limit');
            }
            $pulse();
            $state = 'ok';
            $reason = 'agent_completed';
        } catch (InvalidAgentResponse|InvalidRedactionInputException $exception) {
            $answer = new AgentTurnResult('', $answer->usage, $answer->usageSource);
            $state = 'invalid_json';
            $reason = $exception instanceof InvalidAgentResponse ? $exception->reason : 'agent_response_invalid_utf8';
        } catch (Throwable $exception) {
            report($exception);
            $answer = new AgentTurnResult('', $answer->usage, $answer->usageSource);
            $reason = $exception instanceof AgentExecutionException ? $exception->reason : 'agent_provider_error';
        }
        // A failed turn may have produced no heartbeat since its last pulse.
        // Refresh it once, best effort, so the completion timestamp below stays
        // within assertAlive()'s tolerance instead of reading as stale; a
        // genuinely terminal or boot-changed condition is still caught by the
        // publication lock's own checks right after.
        try {
            $pulse();
        } catch (Throwable) {
        }
        self::withLifecycleLock(function () use ($request, $home, $contextPath, $answer, $state, $reason, $bootId, $mailbox, $message): void {
            // A terminal worker cleanup revokes publication as well as heartbeat.
            if (! is_file($contextPath) || time() >= $request->integer('deadline_at')) {
                return;
            }
            $this->assertBoot($bootId);
            $answerPath = $home->resultDirectory.'/answer.txt';
            if (is_link($answerPath) || file_exists($answerPath)
                || file_put_contents($answerPath, $answer->bytes, LOCK_EX) !== strlen($answer->bytes)
                || ! chmod($answerPath, 0640)) {
                throw new AgentExecutionException('agent_answer_publication_failed');
            }
            $document = new AgentExecutionResultDocument($request, $bootId, $state, $reason, time(), hash('sha256', $answer->bytes), $answer);
            $mailbox->write(MailboxMessageType::RESULT, $message->slotId, $message->deliveryId, $document->toJson());
        });

        return true;
    }

    /** The worker creates this stable inode; the agent opens it read-only. */
    public static function initializeLifecycleLock(): void
    {
        $path = self::inputRoot().'/.agent-lifecycle.lock';
        if (is_link($path)) {
            throw new AgentExecutionException('agent_lifecycle_lock_invalid');
        }
        if (! is_file($path)) {
            $stream = @fopen($path, 'x+b');
            if (is_resource($stream)) {
                fclose($stream);
                if (! chmod($path, 0640)) {
                    throw new AgentExecutionException('agent_lifecycle_lock_invalid');
                }
            } elseif (! is_file($path)) {
                throw new AgentExecutionException('agent_lifecycle_lock_unavailable');
            }
        }
    }

    /**
     * Serialize claim/publication with worker revocation, never the provider turn.
     * The stable inode is mailbox infrastructure and is not removed per turn.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function withLifecycleLock(Closure $operation): mixed
    {
        $path = self::inputRoot().'/.agent-lifecycle.lock';
        if (! is_file($path) || is_link($path)) {
            throw new AgentExecutionException('agent_lifecycle_lock_unavailable');
        }
        $stream = @fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new AgentExecutionException('agent_lifecycle_lock_unavailable');
        }
        try {
            if (! flock($stream, LOCK_EX)) {
                throw new AgentExecutionException('agent_lifecycle_lock_unavailable');
            }
            try {
                clearstatcache();

                return $operation();
            } finally {
                flock($stream, LOCK_UN);
            }
        } finally {
            fclose($stream);
        }
    }

    private function assertBoot(string $bootId): void
    {
        $boot = app(RestrictedJsonDecoder::class)->decode(self::readBytes(self::outputRoot().'/attestations/agent.json', 4096),
            new RedactionContext('agent', null, 'current-boot'));
        if (($boot['agent_boot_id'] ?? null) !== $bootId) {
            throw new AgentExecutionException('agent_execution_boot_changed');
        }
    }

    public function publishBoot(string $bootId): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1) {
            throw new AgentExecutionException('agent_boot_invalid');
        }
        $interval = getenv('AI6_HEARTBEAT_INTERVAL');
        $age = getenv('AI6_HEARTBEAT_MAX_AGE');
        $interval = is_string($interval) && ctype_digit($interval) ? (int) $interval : 5;
        $age = is_string($age) && ctype_digit($age) ? (int) $age : 15;
        if ($interval < 1 || $age < $interval || $age > 3600) {
            throw new AgentExecutionException('agent_heartbeat_configuration_invalid');
        }
        self::writeDocument(self::outputRoot().'/attestations/agent.json', [
            'schema' => 'ai6.agent-boot.v1', 'agent_boot_id' => $bootId, 'recorded_at' => time(),
            'heartbeat_interval' => $interval,
            'heartbeat_max_age' => $age,
        ]);
    }

    public static function inputRoot(): string
    {
        return self::root('agent_root');
    }

    public static function outputRoot(): string
    {
        return self::root('agent_output_root');
    }

    private static function root(string $key): string
    {
        $path = config('ai6.execution_mailboxes.'.$key);
        if (! is_string($path) || ! is_dir($path) || is_link($path)) {
            throw new AgentExecutionException('agent_root_unavailable');
        }

        return rtrim(str_replace('\\', '/', (string) realpath($path)), '/');
    }

    public static function home(AgentExecutionRequest $request): ExecutionHome
    {
        $root = self::inputRoot().'/'.$request->string('home');
        $output = self::outputRoot().'/'.$request->string('home');
        foreach ([$root, dirname($root), $output, dirname($output)] as $path) {
            if (! is_dir($path) || is_link($path)) {
                throw new AgentExecutionException('agent_home_unavailable');
            }
        }
        $workspace = ($request->string('role') === AgentRole::IMPLEMENTATION->value ? $output : $root).'/workspace';

        return new ExecutionHome($root, $output, $workspace, $root.'/home', $root.'/instructions',
            $root.'/runtime/profile.json', $root.'/home/auth', $output.'/result', $output.'/artifacts', $output.'/instruction-patch');
    }

    public static function readBytes(string $path, int $maximum = 1048576): string
    {
        for ($ancestor = dirname($path); dirname($ancestor) !== $ancestor; $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new AgentExecutionException('agent_document_parent_invalid');
            }
        }
        if (! is_file($path) || is_link($path) || filesize($path) > $maximum) {
            throw new AgentExecutionException('agent_document_unavailable');
        }
        $bytes = file_get_contents($path, length: $maximum + 1);

        return is_string($bytes) && strlen($bytes) <= $maximum ? $bytes : throw new AgentExecutionException('agent_document_unavailable');
    }

    /** @param array<string, mixed> $document */
    public static function writeDocument(string $path, array $document): void
    {
        $directory = dirname($path);
        for ($ancestor = $directory; dirname($ancestor) !== $ancestor; $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new AgentExecutionException('agent_document_directory_invalid');
            }
        }
        if ((! is_dir($directory) && ! mkdir($directory, 01730, true) && ! is_dir($directory)) || is_link($directory) || is_link($path)) {
            throw new AgentExecutionException('agent_document_directory_invalid');
        }
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($temporary, 0640) || ! rename($temporary, $path)) {
                throw new AgentExecutionException('agent_document_publication_failed');
            }
        } finally {
            if (is_file($temporary) && ! unlink($temporary)) {
                throw new AgentExecutionException('agent_document_cleanup_failed');
            }
        }
    }
}
