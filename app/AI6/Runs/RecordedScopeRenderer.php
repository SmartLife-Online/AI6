<?php

namespace App\AI6\Runs;

use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketSectionLocator;

/** Deterministic AI6-owned documentation of the scope that was effective for one run. */
final readonly class RecordedScopeRenderer
{
    public function __construct(
        private Redactor $redactor,
        private RunLimitPolicy $limits,
        private TicketSectionLocator $sections,
    ) {}

    public function write(Run $run, string $ticketContent): string
    {
        $initial = array_values(array_filter(
            ($run->scope_snapshot ?? [])['ticket_files'] ?? [],
            'is_string',
        ));
        sort($initial, SORT_STRING);
        $decisions = ScopeDecision::query()->where('run_id', $run->id)->orderBy('path')->get();
        $quarantined = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::QUARANTINED_PATH->value)->orderBy('sequence')->get();
        $lines = [
            '## Recorded Scope',
            '',
            'Dieser Abschnitt wurde deterministisch von AI6 geschrieben; er ist kein Bestandteil des Ticketvertrags.',
            '',
            '**Initialer Scope:**',
            '',
        ];
        foreach ($initial as $path) {
            $lines[] = '- '.$this->inlineCode($this->safe($run, $path, 'recorded-scope-initial'));
        }
        if ($initial === []) {
            $lines[] = '- None.';
        }
        $lines[] = '';
        $lines[] = '**Scope-Entscheidungen:**';
        $lines[] = '';
        foreach ($decisions as $decision) {
            $lines[] = sprintf(
                '- %s — %s — %s',
                $this->inlineCode($this->safe($run, $decision->path, 'recorded-scope-decision-path')),
                $this->safe($run, $decision->outcome, 'recorded-scope-decision-outcome'),
                $this->safe($run, $decision->reason, 'recorded-scope-decision-reason'),
            );
        }
        if ($decisions->isEmpty()) {
            $lines[] = '- None.';
        }
        $lines[] = '';
        $lines[] = '**Quarantänisierte Pfade:**';
        $lines[] = '';
        $seen = [];
        foreach ($quarantined as $artifact) {
            $path = $artifact->redacted_metadata['path'] ?? null;
            if (! is_string($path) || $path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $lines[] = '- '.$this->inlineCode($this->safe($run, $path, 'recorded-scope-quarantine'));
        }
        if ($seen === []) {
            $lines[] = '- None.';
        }
        $maximum = $this->limits->effective($run)['max_added_scope_paths'];
        $lines[] = '';
        $lines[] = sprintf('**Pfadlimit:** %d von %d zusätzlichen Pfaden verbraucht.', $run->added_scope_paths_count, $maximum);
        $section = implode("\n", $lines)."\n";

        $normalized = str_replace(["\r\n", "\r"], "\n", $ticketContent);
        $headings = $this->sections->levelTwoHeadings($normalized);
        $recorded = array_find_key(
            $headings,
            static fn (array $heading): bool => $heading['title'] === 'Recorded Scope',
        );
        if (is_int($recorded)) {
            $start = $headings[$recorded]['offset'];
            $end = $headings[$recorded + 1]['offset'] ?? strlen($normalized);
            $without = substr($normalized, 0, $start).substr($normalized, $end);
        } else {
            $without = $normalized;
        }
        $notes = array_find(
            $this->sections->levelTwoHeadings($without),
            static fn (array $heading): bool => $heading['title'] === 'Notes',
        );
        $notesOffset = is_array($notes) ? $notes['offset'] : false;

        return $notesOffset === false
            ? rtrim($without, "\n")."\n\n".$section
            : rtrim(substr($without, 0, $notesOffset), "\n")."\n\n".$section."\n".substr($without, $notesOffset);
    }

    private function safe(Run $run, string $value, string $purpose): string
    {
        $redacted = $this->redactor->redact(
            $value,
            new RedactionContext((string) $run->project_id, $run->id, $purpose),
        )->text;
        foreach (RedactionMatchType::cases() as $type) {
            $redacted = str_replace($type->marker(), '[redigiert]', $redacted);
        }

        return $redacted;
    }

    private function inlineCode(string $value): string
    {
        preg_match_all('/`+/', $value, $matches);
        $longest = $matches[0] === [] ? 0 : max(array_map('strlen', $matches[0]));
        $delimiter = str_repeat('`', $longest + 1);
        $padding = str_starts_with($value, '`') || str_ends_with($value, '`') ? ' ' : '';

        return $delimiter.$padding.$value.$padding.$delimiter;
    }
}
