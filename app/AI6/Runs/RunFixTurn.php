<?php

namespace App\AI6\Runs;

use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Reviews\FixContextPackage;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;
use Throwable;

/** Thin fix handler: context binding plus the existing implementation turn. */
final readonly class RunFixTurn
{
    public function __construct(
        private RunImplementation $implementation,
        private RunOrchestrator $orchestrator,
        private FixContextPackage $contexts,
        private PromptRenderer $prompts,
        private PromptCatalog $catalog,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        try {
            $package = $this->contexts->forRun($run);
            if ($package['finding_ids'] === []) {
                if ($this->orchestrator->applyPreparedStepEffect($run, ExecutionStepType::FIX, $job->step_number)) {
                    $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Fixturn ohne wirksamen Blocker abgeschlossen.');
                }

                return;
            }
            $binding = ($run->prompt_snapshot ?? [])['fix_prompt_binding'] ?? null;
            $entry = $this->catalog->entry('fix');
            if (! is_array($binding) || ($binding['entry_version'] ?? null) !== $entry->version
                || ! is_string($binding['template_sha256'] ?? null)
                || ! hash_equals($binding['template_sha256'], hash('sha256', $entry->template))) {
                throw new ImplementationImportException('fix_prompt_binding_mismatch', 'The fix catalog or template binding changed.');
            }
            $context = new RedactionContext((string) $run->project_id, $run->id, 'fix-prompt');
            $prompt = $this->prompts->snapshot([
                new PromptRenderRequest('fix', new PromptVariables(['context' => $package['json']])),
            ], $context);
            $this->implementation->executeFix($job, $run, $owner, $prompt, $package['hash'], $package['finding_ids']);
        } catch (Throwable $exception) {
            $code = match (true) {
                $exception instanceof ImplementationImportException => $exception->reason,
                $exception instanceof RunTransitionConflict => $exception->reason,
                default => 'fix_prompt_binding_invalid',
            };
            $this->orchestrator->discardImplementationSessions($run);
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Fixturn abgebrochen: '.$code.'.', $code);
            $this->orchestrator->failRun($run->id);
        }
    }
}
