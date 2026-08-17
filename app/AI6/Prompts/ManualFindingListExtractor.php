<?php

namespace App\AI6\Prompts;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;

final readonly class ManualFindingListExtractor
{
    public const MAX_REVIEW_ANSWER_BYTES = 262144;

    public const MARKER_LINE = '### Fix-Liste';

    public const NOTHING_TO_FIX = 'Nichts zu fixen.';

    public function __construct(
        private Redactor $redactor,
        private StrictPositiveIntegerParser $integerParser,
    ) {}

    public function extract(string $raw, RedactionContext $context): ManualFindingListExtraction
    {
        try {
            $redacted = $this->redactor->redact($raw, $context);
        } catch (InvalidRedactionInputException) {
            throw new ManualFindingListException(ManualFindingListError::UTF8_INVALID);
        }

        if (strlen($raw) > $this->maximumBytes()) {
            throw new ManualFindingListException(ManualFindingListError::INPUT_BYTES_EXCEEDED);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $redacted->text);
        $lines = explode("\n", $normalized);
        $markerIndexes = [];
        foreach ($lines as $index => $line) {
            if ($line === self::MARKER_LINE) {
                $markerIndexes[] = $index;
            }
        }

        if ($markerIndexes === []) {
            throw new ManualFindingListException(ManualFindingListError::MARKER_MISSING);
        }

        if (count($markerIndexes) > 1) {
            throw new ManualFindingListException(ManualFindingListError::MARKER_DUPLICATE);
        }

        $contentStart = $markerIndexes[0] + 1;
        for ($index = $contentStart, $count = count($lines); $index < $count; $index++) {
            if (preg_match('/\A#{1,6}[ \t]/', $lines[$index]) === 1) {
                throw new ManualFindingListException(ManualFindingListError::MARKER_NOT_TERMINAL);
            }
        }

        $list = trim(implode("\n", array_slice($lines, $contentStart)), "\n");
        if ($list === '') {
            throw new ManualFindingListException(ManualFindingListError::LIST_EMPTY);
        }

        if ($list === self::NOTHING_TO_FIX) {
            return new ManualFindingListExtraction(true, '', $this->containsRedactionMarker($list));
        }

        return new ManualFindingListExtraction(false, $list, $this->containsRedactionMarker($list));
    }

    private function containsRedactionMarker(string $list): bool
    {
        foreach (RedactionMatchType::cases() as $type) {
            if (str_contains($list, $type->marker())) {
                return true;
            }
        }

        return false;
    }

    public function maximumBytes(): int
    {
        $parsed = $this->integerParser->parse(
            'ai6.manual_prompt_help.max_review_answer_bytes',
            config('ai6.manual_prompt_help.max_review_answer_bytes'),
            self::MAX_REVIEW_ANSWER_BYTES,
        );
        if ($parsed instanceof ConfigurationViolation) {
            throw new ConfigurationException($parsed->message);
        }

        return $parsed;
    }
}
