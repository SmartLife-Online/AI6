<?php

namespace App\AI6\Tickets;

final readonly class Ai6DetailV1TicketValidator
{
    private const SECTIONS = [
        'Goal', 'Context', 'Tasks', 'Acceptance Criteria', 'Test Cases', 'AC Coverage',
        'Initial Scope and Sensitive Paths', 'Do Not Change', 'Out of Scope',
        'Manual and External Gates', 'Review Focus', 'Notes',
    ];

    public function __construct(private GenericV1TicketValidator $generic) {}

    /** @return list<TicketValidationError> */
    public function validate(TicketDocument $document, string $relativePath): array
    {
        $errors = $this->generic->validate($document, $relativePath);
        foreach (TicketV1Parser::KNOWN_KEYS as $key) {
            if (! array_key_exists($key, $document->frontmatter)) {
                $errors[] = new TicketValidationError('detail_field_required', $key, 'Das AI6-Detailprofil verlangt dieses Frontmatter-Feld.');
            }
        }
        foreach (self::SECTIONS as $section) {
            if (! array_key_exists($section, $document->sections) || trim($document->sections[$section]) === '') {
                $errors[] = new TicketValidationError('detail_section_required', $section, 'Das AI6-Detailprofil verlangt diesen Abschnitt.');
            }
        }
        if ($document->acceptanceCriterionIds === []) {
            $errors[] = new TicketValidationError('detail_ac_required', 'Acceptance Criteria', 'Das AI6-Detailprofil verlangt mindestens ein Akzeptanzkriterium.');
        }
        if ($document->testCaseIds === []) {
            $errors[] = new TicketValidationError('detail_tc_required', 'Test Cases', 'Das AI6-Detailprofil verlangt mindestens einen Testfall.');
        }
        $specRefs = $document->frontmatter['spec_refs'] ?? null;
        if (! is_array($specRefs) || ! array_is_list($specRefs) || $specRefs === []) {
            $errors[] = new TicketValidationError('spec_ref_invalid', 'spec_refs', 'Das AI6-Detailprofil verlangt mindestens eine kanonische Planreferenz.');
        } else {
            foreach ($specRefs as $specRef) {
                if (! is_string($specRef)
                    || preg_match('/\Adocs\/AI6_IMPLEMENTATION_PLAN\.md — [A-Z]+-\d{3}\z/D', $specRef) !== 1) {
                    $errors[] = new TicketValidationError('spec_ref_invalid', 'spec_refs', 'Mindestens eine AI6-Planreferenz ist nicht kanonisch.');
                    break;
                }
            }
        }

        return $errors;
    }
}
