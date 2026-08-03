<?php

namespace App\AI6\Shared\Redaction;

use RuntimeException;

final readonly class Redactor
{
    public function __construct(
        private RedactionPolicy $policy,
        private RedactionFingerprintGenerator $fingerprints,
    ) {}

    /** @throws InvalidRedactionInputException */
    public function redact(string $text, RedactionContext $context): RedactionResult
    {
        if (preg_match('//u', $text) !== 1) {
            throw new InvalidRedactionInputException('Redaction input must be valid UTF-8.');
        }

        $candidates = [];

        foreach ($this->policy->rules() as $rule) {
            $count = preg_match_all($rule->pattern, $text, $matches, PREG_OFFSET_CAPTURE);

            if ($count === false) {
                throw new RuntimeException(sprintf('Redaction rule %s could not be evaluated.', $rule->name));
            }

            foreach ($matches[0] as $match) {
                $value = $match[0];
                $start = $match[1];

                if ($value === '' || $this->isKnownMarker($value)) {
                    continue;
                }

                $candidate = [
                    'start' => $start,
                    'length' => strlen($value),
                    'value' => $value,
                    'rule' => $rule,
                ];

                $candidates[] = $candidate;
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            return $left['start'] <=> $right['start']
                ?: $right['length'] <=> $left['length']
                ?: $left['rule']->priority <=> $right['rule']->priority;
        });

        $selected = [];
        $coveredUntil = -1;
        foreach ($candidates as $candidate) {
            if ($candidate['start'] < $coveredUntil) {
                continue;
            }

            $selected[] = $candidate;
            $coveredUntil = $candidate['start'] + $candidate['length'];
        }

        $output = '';
        $cursor = 0;
        $redactions = [];
        foreach ($selected as $candidate) {
            /** @var RedactionRule $rule */
            $rule = $candidate['rule'];
            $output .= substr($text, $cursor, $candidate['start'] - $cursor).$rule->marker();
            $fingerprint = $this->fingerprints->generate($rule->type, $context, $candidate['value']);
            $redactions[] = new RedactionMatch(
                $rule->type,
                $context->identifier,
                $candidate['start'],
                $candidate['length'],
                $rule->marker(),
                $fingerprint->version,
                $fingerprint->keyId,
                $fingerprint->value,
            );
            $cursor = $candidate['start'] + $candidate['length'];
        }

        $output .= substr($text, $cursor);

        return new RedactionResult($output, $redactions);
    }

    private function isKnownMarker(string $value): bool
    {
        foreach (RedactionMatchType::cases() as $type) {
            if ($value === $type->marker()) {
                return true;
            }
        }

        return false;
    }
}
