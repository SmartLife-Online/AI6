<?php

namespace App\AI6\Shared\Process;

use App\AI6\Shared\Redaction\RedactionContext;
use InvalidArgumentException;

final readonly class ProcessRequest
{
    /**
     * @param  list<string>  $command
     * @param  list<string>  $environmentAllowlist
     * @param  array<string, string>  $environment
     */
    public function __construct(
        public array $command,
        public string $workingDirectory,
        public array $environmentAllowlist,
        public array $environment,
        public RedactionContext $redactionContext,
        public ?int $timeoutSeconds = null,
        public ?int $outputLimitBytes = null,
        public ProcessPolicyName $policy = ProcessPolicyName::CONTROL,
        public ?ProcessLimits $approvedLimits = null,
        public ?string $resultDirectory = null,
        public ?string $artifactDirectory = null,
    ) {
        if ($this->command === []) {
            throw new InvalidArgumentException('A control process requires a non-empty argument list.');
        }

        foreach ($this->command as $argument) {
            if ($argument === '' || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Every control process argument must be a non-empty NUL-free string.');
            }
        }

        if (! is_dir($this->workingDirectory)) {
            throw new InvalidArgumentException('The control process working directory must already exist.');
        }

        $allowed = [];
        foreach ($this->environmentAllowlist as $name) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
                throw new InvalidArgumentException('The process environment allowlist contains an invalid name.');
            }

            $allowed[$name] = true;
        }

        if (count($allowed) !== count($this->environmentAllowlist)) {
            throw new InvalidArgumentException('The process environment allowlist must not contain duplicates.');
        }

        foreach ($this->environment as $name => $value) {
            if (! isset($allowed[$name]) || str_contains($value, "\0")) {
                throw new InvalidArgumentException('A process environment value is not allowlisted or contains NUL.');
            }
        }

        if ($this->timeoutSeconds !== null && $this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('A requested process timeout must be positive.');
        }

        if ($this->outputLimitBytes !== null && $this->outputLimitBytes < 1) {
            throw new InvalidArgumentException('A requested process output limit must be positive.');
        }

        if ($this->resultDirectory !== null && ! is_dir($this->resultDirectory)) {
            throw new InvalidArgumentException('A process result directory must already exist.');
        }
        if ($this->artifactDirectory !== null && ! is_dir($this->artifactDirectory)) {
            throw new InvalidArgumentException('A process artifact directory must already exist.');
        }
    }
}
