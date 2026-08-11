<?php

namespace App\AI6\Tickets;

use Normalizer;

final class GenericV1TicketValidator
{
    public const ID_PATTERN = '/\A(?=.{2,32}\z)(?=.*\d)[A-Z][A-Z0-9-]*\z/D';

    /** @return list<TicketValidationError> */
    public function validate(TicketDocument $document, string $relativePath): array
    {
        $errors = [];
        $frontmatter = $document->frontmatter;
        $this->requiredString($frontmatter, 'schema', $errors);
        $this->requiredString($frontmatter, 'id', $errors);
        $this->requiredString($frontmatter, 'title', $errors);
        $this->requiredString($frontmatter, 'status', $errors);
        if (($frontmatter['schema'] ?? null) !== 'ai6.ticket.v1') {
            $errors[] = $this->error('schema_invalid', 'schema', 'Das Ticket verwendet nicht das Schema ai6.ticket.v1.');
        }

        $id = $frontmatter['id'] ?? null;
        if (is_string($id) && preg_match(self::ID_PATTERN, $id) !== 1) {
            $errors[] = $this->error('id_invalid', 'id', 'Die Ticket-ID entspricht nicht der Standard-ID-Regel.');
        }
        if (is_string($id) && basename($relativePath) !== $id.'.md') {
            $errors[] = $this->error('filename_id_mismatch', 'id', 'Dateiname und deklarierte Ticket-ID stimmen nicht überein.');
        }
        if (is_string($frontmatter['title'] ?? null) && trim($frontmatter['title']) === '') {
            $errors[] = $this->error('title_empty', 'title', 'Der Tickettitel darf nicht leer sein.');
        }
        if (is_string($frontmatter['status'] ?? null)
            && ! in_array($frontmatter['status'], ['todo', 'ready', 'in_progress', 'blocked', 'review', 'done', 'cancelled'], true)) {
            $errors[] = $this->error('status_invalid', 'status', 'Der Ticketstatus ist nicht zulässig.');
        }

        $dependsOn = $frontmatter['depends_on'] ?? null;
        if (! is_array($dependsOn) || ! array_is_list($dependsOn)) {
            $errors[] = $this->error('depends_on_invalid', 'depends_on', 'Abhängigkeiten müssen als Liste angegeben werden.');
        } else {
            foreach ($dependsOn as $dependency) {
                if (! is_string($dependency) || preg_match(self::ID_PATTERN, $dependency) !== 1) {
                    $errors[] = $this->error('dependency_id_invalid', 'depends_on', 'Mindestens eine Abhängigkeits-ID ist ungültig.');
                    break;
                }
            }
            if (count(array_unique(array_filter($dependsOn, 'is_string'))) !== count($dependsOn)) {
                $errors[] = $this->error('dependency_duplicate', 'depends_on', 'Eine Abhängigkeit ist mehrfach deklariert.');
            }
        }

        if (! isset($document->sections['Goal']) || trim($document->sections['Goal']) === '') {
            $errors[] = $this->error('goal_missing', 'Goal', 'Der Abschnitt Goal fehlt oder ist leer.');
        }
        foreach ($document->duplicateSections as $section) {
            $errors[] = $this->error('section_duplicate', $section, 'Ein Ticketabschnitt ist mehrfach vorhanden.');
        }

        $this->validateOptionalEnums($frontmatter, $errors);
        $this->validateListField($frontmatter, 'files', $errors, true);
        $this->validateListField($frontmatter, 'spec_refs', $errors, true);
        foreach ($document->files as $path) {
            if (! self::isCanonicalRepositoryPath($path)) {
                $errors[] = $this->error('file_path_invalid', 'files', 'Mindestens ein Scopepfad ist nicht kanonisch repositoryrelativ.');
            }
        }
        $this->validateEvidenceIds($document, $errors);

        return $this->uniqueErrors($errors);
    }

    public static function isCanonicalRepositoryPath(string $path): bool
    {
        $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
        if (! is_string($normalized) || ! hash_equals($path, $normalized) || $path === ''
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_contains($path, '\\') || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:/', $path) === 1 || str_starts_with($path, '//')
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/', $path) === 1
            || preg_match('/[*?\[\]{}!]/', $path) === 1) {
            return false;
        }
        $trimmed = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        if ($trimmed === '') {
            return false;
        }
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $values
     * @param  list<TicketValidationError>  $errors
     */
    private function requiredString(array $values, string $key, array &$errors): void
    {
        if (! array_key_exists($key, $values) || ! is_string($values[$key])) {
            $errors[] = $this->error($key.'_required', $key, 'Ein Pflichtfeld fehlt oder besitzt den falschen Typ.');
        }
    }

    /** @param array<string, mixed> $values
     * @param  list<TicketValidationError>  $errors
     */
    private function validateOptionalEnums(array $values, array &$errors): void
    {
        foreach ([
            'kind' => ['feature', 'chore', 'fix', 'spike'],
            'milestone' => ['M0', 'M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7'],
            'risk' => ['low', 'medium', 'high'],
        ] as $key => $allowed) {
            if (array_key_exists($key, $values) && (! is_string($values[$key]) || ! in_array($values[$key], $allowed, true))) {
                $errors[] = $this->error($key.'_invalid', $key, 'Ein optionales Enumfeld besitzt einen unzulässigen Wert.');
            }
        }
    }

    /** @param array<string, mixed> $values
     * @param  list<TicketValidationError>  $errors
     */
    private function validateListField(array $values, string $key, array &$errors, bool $allowEmpty): void
    {
        if (! array_key_exists($key, $values)) {
            return;
        }
        $items = $values[$key];
        if (! is_array($items) || ! array_is_list($items) || (! $allowEmpty && $items === [])) {
            $errors[] = $this->error($key.'_invalid', $key, 'Das Feld muss eine gültige Liste sein.');

            return;
        }
        foreach ($items as $item) {
            if (! is_string($item) || trim($item) === '') {
                $errors[] = $this->error($key.'_item_invalid', $key, 'Listeneinträge müssen nicht leere Zeichenketten sein.');
                break;
            }
        }
    }

    /** @param list<TicketValidationError> $errors */
    private function validateEvidenceIds(TicketDocument $document, array &$errors): void
    {
        if (count(array_unique($document->acceptanceCriterionIds)) !== count($document->acceptanceCriterionIds)) {
            $errors[] = $this->error('ac_id_duplicate', 'Acceptance Criteria', 'Eine AC-ID ist mehrfach vorhanden.');
        }
        if (count(array_unique($document->testCaseIds)) !== count($document->testCaseIds)) {
            $errors[] = $this->error('tc_id_duplicate', 'Test Cases', 'Eine TC-ID ist mehrfach vorhanden.');
        }
        if (preg_match('/(?m)^- \[ \] \*\*AC-(?!\d{2}\*\*)/', $document->body) === 1) {
            $errors[] = $this->error('ac_id_invalid', 'Acceptance Criteria', 'Mindestens eine AC-ID besitzt nicht das Format AC-xx.');
        }
        if (preg_match('/(?m)^- \*\*TC-(?!\d{2}\*\*)/', $document->body) === 1) {
            $errors[] = $this->error('tc_id_invalid', 'Test Cases', 'Mindestens eine TC-ID besitzt nicht das Format TC-xx.');
        }
    }

    private function error(string $code, string $field, string $message): TicketValidationError
    {
        return new TicketValidationError($code, $field, $message);
    }

    /** @param list<TicketValidationError> $errors
     * @return list<TicketValidationError>
     */
    private function uniqueErrors(array $errors): array
    {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error->code."\0".$error->field] = $error;
        }

        return array_values($unique);
    }
}
