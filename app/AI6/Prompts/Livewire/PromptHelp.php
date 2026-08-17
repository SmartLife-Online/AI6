<?php

namespace App\AI6\Prompts\Livewire;

use App\AI6\Prompts\ManualFindingListException;
use App\AI6\Prompts\ManualFindingListExtractor;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptEntry;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Prompt-Hilfe – AI6'])]
final class PromptHelp extends Component
{
    public const OWN_ENTRY_ID = 'manual_own_review_fix';

    public const FOREIGN_ENTRY_ID = 'manual_foreign_fix_review';

    public const DYNAMIC_ENTRY_ID = 'manual_finding_list_fix';

    public string $reviewAnswer = '';

    public string $dynamicPreview = '';

    public bool $dynamicCopyEnabled = false;

    public bool $nothingToFix = false;

    public bool $dynamicRejected = false;

    public bool $redacted = false;

    public function processReviewAnswer(): void
    {
        $raw = $this->reviewAnswer;
        $this->reviewAnswer = '';
        $this->dynamicPreview = '';
        $this->dynamicCopyEnabled = false;
        $this->nothingToFix = false;
        $this->dynamicRejected = false;
        $this->redacted = false;

        try {
            $extraction = app(ManualFindingListExtractor::class)->extract($raw, $this->context());
        } catch (ManualFindingListException) {
            $this->dynamicRejected = true;

            return;
        }

        if ($extraction->nothingToFix) {
            $this->nothingToFix = true;
            $this->redacted = $extraction->redacted;

            return;
        }

        try {
            $this->dynamicPreview = app(PromptRenderer::class)->render(
                self::DYNAMIC_ENTRY_ID,
                new PromptVariables(['finding_list' => $extraction->list]),
                $this->context(),
            );
        } catch (PromptRenderingException) {
            $this->dynamicRejected = true;

            return;
        }

        $this->dynamicCopyEnabled = true;
        $this->redacted = $extraction->redacted;
    }

    public function render(): View
    {
        $catalog = app(PromptCatalog::class);
        $renderer = app(PromptRenderer::class);
        $context = $this->context();
        $ownEntry = $this->namedEntry($catalog, self::OWN_ENTRY_ID);
        $foreignEntry = $this->namedEntry($catalog, self::FOREIGN_ENTRY_ID);
        $dynamicEntry = $this->namedEntry($catalog, self::DYNAMIC_ENTRY_ID);

        return view('prompts.help', [
            'catalogVersion' => $catalog->version,
            'ownEntry' => $ownEntry,
            'foreignEntry' => $foreignEntry,
            'dynamicEntry' => $dynamicEntry,
            'ownPrompt' => $renderer->render(self::OWN_ENTRY_ID, new PromptVariables([]), $context),
            'foreignPrompt' => $renderer->render(self::FOREIGN_ENTRY_ID, new PromptVariables([]), $context),
        ]);
    }

    private function namedEntry(PromptCatalog $catalog, string $id): PromptEntry
    {
        $entry = $catalog->entry($id);
        if ($entry->displayName === '') {
            throw new InvalidArgumentException('A manual prompt entry requires a non-empty display name.');
        }

        return $entry;
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('manual-prompt-help', null, 'prompt-help');
    }
}
