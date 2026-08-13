<?php

namespace App\AI6\Prompts;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;

final readonly class PromptRenderer
{
    public function __construct(
        private PromptCatalog $catalog,
        private Redactor $redactor,
        private AgentInputLimits $limits,
        private CanonicalJson $canonicalJson,
    ) {}

    public function render(
        string $entryId,
        PromptVariables $variables,
        RedactionContext $context,
        ?string $reviewProfileId = null,
    ): string {
        $entry = $this->catalog->entry($entryId);
        $expected = $entry->requiredVariables;
        $actual = array_keys($variables->values);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new PromptRenderingException(PromptRenderingError::VARIABLES_INVALID);
        }

        $inputBytes = 0;
        foreach ($variables->values as $value) {
            $inputBytes += strlen($value);
            if ($inputBytes > $this->limits->maxPromptInputBytes) {
                throw new PromptRenderingException(PromptRenderingError::INPUT_BYTES_EXCEEDED);
            }
        }

        $redacted = [];
        foreach ($variables->values as $key => $value) {
            try {
                $result = $this->redactor->redact($value, $context);
            } catch (InvalidRedactionInputException) {
                throw new PromptRenderingException(PromptRenderingError::UTF8_INVALID);
            }
            $redacted['{{'.$key.'}}'] = $result->text;
        }

        $prompt = strtr($entry->template, $redacted);
        if ($reviewProfileId !== null) {
            $profile = $this->catalog->reviewProfile($reviewProfileId);
            $prompt .= "\n\nReviewprofil: ".$profile->displayName."\nFokus: ".$profile->focus;
        }
        if (strlen($prompt) > $this->limits->maxPromptInputBytes) {
            throw new PromptRenderingException(PromptRenderingError::INPUT_BYTES_EXCEEDED);
        }

        return $prompt;
    }

    /** @param list<PromptRenderRequest> $requests */
    public function snapshot(array $requests, RedactionContext $context): PromptSnapshot
    {
        if ($requests === []) {
            throw new PromptRenderingException(PromptRenderingError::VARIABLES_INVALID);
        }

        $selectedProfiles = [];
        $renderedPrompts = [];
        $typedInput = [];
        foreach ($requests as $request) {
            if (isset($renderedPrompts[$request->entryId])) {
                throw new PromptRenderingException(PromptRenderingError::DUPLICATE_ENTRY);
            }
            $entry = $this->catalog->entry($request->entryId);
            $profile = $request->reviewProfileId === null ? null : $this->catalog->reviewProfile($request->reviewProfileId);
            $rendered = $this->render($request->entryId, $request->variables, $context, $request->reviewProfileId);
            $selectedProfiles[$request->entryId] = $request->reviewProfileId;
            $renderedPrompts[$request->entryId] = $rendered;
            $typedInput[$request->entryId] = [
                'entry_version' => $entry->version,
                'review_profile_id' => $profile?->id,
                'review_profile_version' => $profile?->version,
                'variable_names' => array_keys($request->variables->values),
                'rendered_prompt' => $rendered,
            ];
        }
        ksort($selectedProfiles, SORT_STRING);
        ksort($renderedPrompts, SORT_STRING);
        ksort($typedInput, SORT_STRING);
        $hashInput = [
            'catalog_version' => $this->catalog->version,
            'prompts' => $typedInput,
        ];
        $hash = hash('sha256', "AI6-PROMPT-SNAPSHOT-V1\0".$this->canonicalJson->normalizeAndEncode($hashInput));

        return new PromptSnapshot($this->catalog->version, $selectedProfiles, $renderedPrompts, $hash);
    }
}
