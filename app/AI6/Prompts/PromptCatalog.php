<?php

namespace App\AI6\Prompts;

use InvalidArgumentException;

final readonly class PromptCatalog
{
    public const VERSION = '1';

    /** @var array<string, PromptEntry> */
    private array $entries;

    /** @var array<string, ReviewPromptProfile> */
    private array $reviewProfiles;

    /**
     * @param  list<PromptEntry>  $entries
     * @param  list<ReviewPromptProfile>  $reviewProfiles
     */
    public function __construct(
        public string $version,
        array $entries,
        array $reviewProfiles,
    ) {
        if ($version === '') {
            throw new InvalidArgumentException('The prompt catalog version must not be empty.');
        }
        $this->entries = self::indexEntries($entries);
        $this->reviewProfiles = self::indexReviewProfiles($reviewProfiles);
    }

    public static function defaults(): self
    {
        return new self(self::VERSION, [
            new PromptEntry('implementation', '1', "Implementiere den freigegebenen Auftrag.\n\nKontext:\n{{context}}", ['context']),
            new PromptEntry('quality_review', '1', "Prüfe den unveränderlichen Implementierungsstand.\n\nKontext:\n{{context}}", ['context']),
            new PromptEntry('fix', '1', "Behebe ausschließlich die freigegebenen Findings.\n\nKontext:\n{{context}}", ['context']),
            new PromptEntry('finding_verification', '1', "Verifiziere das gebundene Finding unabhängig.\n\nKontext:\n{{context}}", ['context']),
            new PromptEntry('security_review', '1', "Prüfe den gebundenen Stand auf Sicherheitsrisiken.\n\nKontext:\n{{context}}", ['context']),
            new PromptEntry('human_response', '1', "Setze die autorisierte menschliche Antwort im gebundenen Kontext um.\n\nKontext:\n{{context}}", ['context']),
        ], [
            new ReviewPromptProfile('ticket_ac_fidelity', '1', 'Ticket- und AC-Treue', 'Prüfe jede Anforderung und jedes Akzeptanzkriterium gegen den tatsächlichen Stand.'),
            new ReviewPromptProfile('functional_correctness', '1', 'Funktionale Korrektheit', 'Prüfe fachliches Verhalten, Randfälle und Fehlerpfade.'),
            new ReviewPromptProfile('security', '1', 'Security', 'Prüfe Vertrauensgrenzen, Autorisierung, Datenabfluss und fail-closed Verhalten.'),
            new ReviewPromptProfile('database_migrations', '1', 'Datenbank und Migrationen', 'Prüfe Schema, Constraints, Datenintegrität und Rollbackfähigkeit.'),
            new ReviewPromptProfile('concurrency', '1', 'Concurrency', 'Prüfe atomare Übergänge, Sperren, Wiederholung und Race Conditions.'),
            new ReviewPromptProfile('performance', '1', 'Performance', 'Prüfe Ressourcenverbrauch, Abfrageverhalten und skalierende Eingaben.'),
            new ReviewPromptProfile('tests', '1', 'Tests', 'Prüfe Aussagekraft, Negativfälle und AC-Abdeckung der Tests.'),
            new ReviewPromptProfile('architecture', '1', 'Architektur', 'Prüfe Modulgrenzen, zentrale Nähte und unerwünschte Abstraktionen.'),
            new ReviewPromptProfile('api_contracts', '1', 'API-Verträge', 'Prüfe öffentliche Signaturen, Serialisierung und Kompatibilität.'),
        ]);
    }

    /** @return list<PromptEntry> */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    /** @return list<ReviewPromptProfile> */
    public function reviewProfiles(): array
    {
        return array_values($this->reviewProfiles);
    }

    public function entry(string $id): PromptEntry
    {
        return $this->entries[$id] ?? throw new PromptRenderingException(PromptRenderingError::ENTRY_UNKNOWN);
    }

    public function reviewProfile(string $id): ReviewPromptProfile
    {
        return $this->reviewProfiles[$id] ?? throw new PromptRenderingException(PromptRenderingError::REVIEW_PROFILE_UNKNOWN);
    }

    public function withEntry(PromptEntry $entry, string $catalogVersion): self
    {
        $this->assertIncreasedVersion($catalogVersion);
        $entries = $this->entries;
        $entries[$entry->id] = $entry;

        return new self($catalogVersion, array_values($entries), array_values($this->reviewProfiles));
    }

    public function withReviewProfile(ReviewPromptProfile $profile, string $catalogVersion): self
    {
        $this->assertIncreasedVersion($catalogVersion);
        $profiles = $this->reviewProfiles;
        $profiles[$profile->id] = $profile;

        return new self($catalogVersion, array_values($this->entries), array_values($profiles));
    }

    private function assertIncreasedVersion(string $catalogVersion): void
    {
        if ($catalogVersion === '' || version_compare($catalogVersion, $this->version, '<=')) {
            throw new PromptRenderingException(PromptRenderingError::CATALOG_VERSION_NOT_INCREASED);
        }
    }

    /** @param list<PromptEntry> $entries
     * @return array<string, PromptEntry>
     */
    private static function indexEntries(array $entries): array
    {
        if ($entries === []) {
            throw new InvalidArgumentException('The prompt catalog must contain entries.');
        }

        $indexed = [];
        foreach ($entries as $entry) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $entry->id) !== 1 || isset($indexed[$entry->id]) || $entry->version === '') {
                throw new InvalidArgumentException('The prompt catalog contains an invalid entry.');
            }
            $required = $entry->requiredVariables;
            foreach ($entry->requiredVariables as $variable) {
                if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $variable) !== 1) {
                    throw new InvalidArgumentException('The prompt catalog entry contains an invalid variable.');
                }
            }
            sort($required, SORT_STRING);
            preg_match_all('/\{\{([a-z][a-z0-9_]{0,63})\}\}/D', $entry->template, $matches);
            $actual = $matches[1];
            sort($actual, SORT_STRING);
            $remainder = preg_replace('/\{\{[a-z][a-z0-9_]{0,63}\}\}/D', '', $entry->template);
            if ($actual !== $required || ! is_string($remainder) || str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
                throw new InvalidArgumentException('The prompt template variables must match the declared variables exactly.');
            }
            $indexed[$entry->id] = $entry;
        }
        ksort($indexed, SORT_STRING);

        return $indexed;
    }

    /** @param list<ReviewPromptProfile> $profiles
     * @return array<string, ReviewPromptProfile>
     */
    private static function indexReviewProfiles(array $profiles): array
    {
        if ($profiles === []) {
            throw new InvalidArgumentException('The prompt catalog must contain review profiles.');
        }

        $indexed = [];
        foreach ($profiles as $profile) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $profile->id) !== 1
                || isset($indexed[$profile->id])
                || $profile->version === ''
                || $profile->displayName === ''
                || $profile->focus === '') {
                throw new InvalidArgumentException('The prompt catalog contains an invalid review profile.');
            }
            $indexed[$profile->id] = $profile;
        }
        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
