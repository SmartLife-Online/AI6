<?php

namespace App\AI6\Projects;

use App\AI6\Agents\AgentRole;
use App\AI6\Agents\ModelProfileAllowlist;
use App\AI6\Checks\CheckProfileAllowlist;
use App\AI6\Shared\Yaml\RestrictedYaml;
use App\AI6\Shared\Yaml\RestrictedYamlException;
use App\AI6\Tickets\DependencySatisfiedStatusAllowlist;
use App\AI6\Tickets\TicketValidationProfile;
use Normalizer;

final readonly class ProjectConfigurationParser
{
    private const TOP_LEVEL_KEYS = [
        'version', 'tickets_path', 'ticket_validation_profile', 'push_mode', 'auto_start_next',
        'dependency_satisfied_statuses', 'defaults', 'limits', 'scope', 'checks',
    ];

    private const LIMIT_KEYS = [
        'max_fix_rounds', 'max_review_rounds', 'max_verification_rounds', 'max_agent_invocations',
        'max_added_scope_paths', 'max_changed_files', 'max_changed_bytes', 'max_artifacts',
        'max_artifact_bytes', 'max_total_artifact_bytes', 'max_provider_output_bytes', 'max_run_minutes',
    ];

    public function __construct(
        private RestrictedYaml $yaml,
        private CheckProfileAllowlist $checkProfiles,
        private ModelProfileAllowlist $modelProfiles,
        private DependencySatisfiedStatusAllowlist $dependencyStatuses,
    ) {}

    public function parse(string $yaml): ProjectConfigurationParseResult
    {
        try {
            $mapping = $this->yaml->parseMapping($yaml, self::TOP_LEVEL_KEYS);
        } catch (RestrictedYamlException $exception) {
            return new ProjectConfigurationParseResult(null, array_map(
                static fn ($error): ProjectConfigurationError => new ProjectConfigurationError(
                    $error->code,
                    'config',
                    $error->message,
                ),
                $exception->errors,
            ));
        }

        return $this->validate($mapping);
    }

    /** @param array<string, mixed> $mapping */
    public function validate(array $mapping): ProjectConfigurationParseResult
    {
        $errors = [];
        if (! $this->hasExactKeys($mapping, self::TOP_LEVEL_KEYS)) {
            $errors[] = $this->error('config_keys_invalid', 'config', 'Die Projektkonfiguration ist unvollständig oder nicht kanonisch geordnet.');
        }

        $version = $this->integer($mapping['version'] ?? null, 'version', $errors, exact: 1);
        $ticketsPath = $this->path($mapping['tickets_path'] ?? null, 'tickets_path', $errors, glob: false);
        $validationProfile = $this->enum(
            $mapping['ticket_validation_profile'] ?? null,
            'ticket_validation_profile',
            array_column(TicketValidationProfile::cases(), 'value'),
            $errors,
        );
        $pushMode = $this->enum($mapping['push_mode'] ?? null, 'push_mode', ['manual', 'automatic_after_gates'], $errors);
        $autoStart = $this->boolean($mapping['auto_start_next'] ?? null, 'auto_start_next', $errors);
        $dependencyStatuses = $this->stringList(
            $mapping['dependency_satisfied_statuses'] ?? null,
            'dependency_satisfied_statuses',
            $errors,
            $this->dependencyStatuses->allows(...),
            'dependency_status_unknown',
        );
        $defaults = $this->defaults($mapping['defaults'] ?? null, $errors);
        $limits = $this->limits($mapping['limits'] ?? null, $errors);
        $scope = $this->scope($mapping['scope'] ?? null, $errors);
        $checks = $this->checks($mapping['checks'] ?? null, $errors);

        if ($errors !== []) {
            return new ProjectConfigurationParseResult(null, $errors);
        }

        return new ProjectConfigurationParseResult(new ProjectConfiguration([
            'version' => $version,
            'tickets_path' => $ticketsPath,
            'ticket_validation_profile' => $validationProfile,
            'push_mode' => $pushMode,
            'auto_start_next' => $autoStart,
            'dependency_satisfied_statuses' => $dependencyStatuses,
            'defaults' => $defaults,
            'limits' => $limits,
            'scope' => $scope,
            'checks' => $checks,
        ]), []);
    }

    /** @param list<ProjectConfigurationError> $errors
     * @return array<string, mixed>
     */
    private function defaults(mixed $value, array &$errors): array
    {
        if (! $this->mappingHasExactKeys($value, ['implementation_profile', 'implementation_effort', 'reviewers'])) {
            $errors[] = $this->error('defaults_invalid', 'defaults', 'Der Defaults-Block ist unvollständig oder enthält unbekannte Schlüssel.');

            return [];
        }
        $profile = $this->string($value['implementation_profile'], 'defaults.implementation_profile', $errors);
        if ($profile !== null && ! $this->modelProfiles->allows($profile)) {
            $errors[] = $this->error('model_profile_unknown', 'defaults.implementation_profile', 'Das referenzierte Modellprofil ist serverseitig nicht bekannt.');
        }
        $effort = $this->enum($value['implementation_effort'], 'defaults.implementation_effort', $this->modelProfiles->efforts(), $errors);
        if ($profile !== null && $effort !== null && ! $this->modelProfiles->supportsRoleEffort($profile, AgentRole::IMPLEMENTATION, $effort)) {
            $errors[] = $this->error('model_profile_combination_invalid', 'defaults', 'Die Kombination aus Modellprofil, Rolle und Effort ist serverseitig nicht zulässig.');
        }
        $reviewers = [];
        if (! is_array($value['reviewers']) || ! array_is_list($value['reviewers']) || $value['reviewers'] === []) {
            $errors[] = $this->error('reviewers_invalid', 'defaults.reviewers', 'Mindestens ein Reviewerprofil ist erforderlich.');
        } else {
            foreach ($value['reviewers'] as $index => $reviewer) {
                $field = 'defaults.reviewers.'.$index;
                if (! $this->mappingHasExactKeys($reviewer, ['profile', 'effort'])) {
                    $errors[] = $this->error('reviewer_invalid', $field, 'Ein Reviewereintrag ist unvollständig oder enthält unbekannte Schlüssel.');

                    continue;
                }
                $reviewerProfile = $this->string($reviewer['profile'], $field.'.profile', $errors);
                if ($reviewerProfile !== null && ! $this->modelProfiles->allows($reviewerProfile)) {
                    $errors[] = $this->error('model_profile_unknown', $field.'.profile', 'Das referenzierte Modellprofil ist serverseitig nicht bekannt.');
                }
                $reviewerEffort = $this->enum($reviewer['effort'], $field.'.effort', $this->modelProfiles->efforts(), $errors);
                if ($reviewerProfile !== null && $reviewerEffort !== null && ! $this->modelProfiles->supportsRoleEffort($reviewerProfile, AgentRole::QUALITY_REVIEW, $reviewerEffort)) {
                    $errors[] = $this->error('model_profile_combination_invalid', $field, 'Die Kombination aus Modellprofil, Rolle und Effort ist serverseitig nicht zulässig.');
                }
                if ($reviewerProfile !== null && $reviewerEffort !== null) {
                    $reviewers[] = ['profile' => $reviewerProfile, 'effort' => $reviewerEffort];
                }
            }
        }

        return ['implementation_profile' => $profile, 'implementation_effort' => $effort, 'reviewers' => $reviewers];
    }

    /** @param list<ProjectConfigurationError> $errors
     * @return array<string, int>
     */
    private function limits(mixed $value, array &$errors): array
    {
        if (! $this->mappingHasExactKeys($value, self::LIMIT_KEYS)) {
            $errors[] = $this->error('limits_invalid', 'limits', 'Der Limits-Block ist unvollständig oder enthält unbekannte Schlüssel.');

            return [];
        }
        $maxima = config('ai6.project_config.server_maxima');
        $normalized = [];
        foreach (self::LIMIT_KEYS as $key) {
            $maximum = is_array($maxima) && is_int($maxima[$key] ?? null) ? $maxima[$key] : 0;
            $parsed = $this->integer($value[$key], 'limits.'.$key, $errors, maximum: $maximum);
            if ($parsed !== null) {
                $normalized[$key] = $parsed;
            }
        }

        return $normalized;
    }

    /** @param list<ProjectConfigurationError> $errors
     * @return array{auto_allow: list<string>, require_approval: list<string>}
     */
    private function scope(mixed $value, array &$errors): array
    {
        if (! $this->mappingHasExactKeys($value, ['auto_allow', 'require_approval'])) {
            $errors[] = $this->error('scope_invalid', 'scope', 'Der Scope-Block ist unvollständig oder enthält unbekannte Schlüssel.');

            return ['auto_allow' => [], 'require_approval' => []];
        }

        return [
            'auto_allow' => $this->pathList($value['auto_allow'], 'scope.auto_allow', $errors),
            'require_approval' => $this->pathList($value['require_approval'], 'scope.require_approval', $errors),
        ];
    }

    /** @param list<ProjectConfigurationError> $errors
     * @return array{before_review: list<string>, final: list<string>}
     */
    private function checks(mixed $value, array &$errors): array
    {
        if (! $this->mappingHasExactKeys($value, ['before_review', 'final'])) {
            $errors[] = $this->error('checks_invalid', 'checks', 'Der Checks-Block ist unvollständig oder enthält unbekannte Schlüssel.');

            return ['before_review' => [], 'final' => []];
        }
        $validate = fn (string $profile): bool => $this->checkProfiles->allows($profile);

        return [
            'before_review' => $this->stringList($value['before_review'], 'checks.before_review', $errors, $validate, 'check_profile_unknown'),
            'final' => $this->stringList($value['final'], 'checks.final', $errors, $validate, 'check_profile_unknown'),
        ];
    }

    /** @param list<ProjectConfigurationError> $errors
     * @return list<string>
     */
    private function pathList(mixed $value, string $field, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = $this->error('scope_glob_list_invalid', $field, 'Scope-Globs müssen als Liste angegeben werden.');

            return [];
        }
        $paths = [];
        foreach ($value as $index => $candidate) {
            $path = $this->path($candidate, $field.'.'.$index, $errors, glob: true);
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @param list<ProjectConfigurationError> $errors */
    private function path(mixed $value, string $field, array &$errors, bool $glob): ?string
    {
        $value = $this->string($value, $field, $errors);
        if ($value === null) {
            return null;
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        $invalid = ! is_string($normalized)
            || $normalized === ''
            || strlen($normalized) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            || str_contains($normalized, '\\')
            || str_starts_with($normalized, '/')
            || str_ends_with($normalized, '/')
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/D', $normalized) === 1;
        if (! $invalid) {
            foreach (explode('/', $normalized) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    $invalid = true;
                    break;
                }
                if ($glob) {
                    if ($segment !== '**' && preg_match('/\A[A-Za-z0-9._*?-]+\z/D', $segment) !== 1) {
                        $invalid = true;
                        break;
                    }
                } elseif (preg_match('/[*?\[\]{}!]/', $segment) === 1) {
                    $invalid = true;
                    break;
                }
            }
        }
        if ($invalid) {
            $errors[] = $this->error($glob ? 'scope_glob_invalid' : 'tickets_path_invalid', $field, $glob
                ? 'Der Scope-Glob ist nicht kanonisch oder verlässt das Repository.'
                : 'Der Ticketpfad ist kein kanonischer repositoryrelativer Verzeichnispfad.');

            return null;
        }

        return $normalized;
    }

    /** @param list<ProjectConfigurationError> $errors */
    private function integer(mixed $value, string $field, array &$errors, ?int $exact = null, ?int $maximum = null): ?int
    {
        if (! is_int($value) || $value < 1 || ($exact !== null && $value !== $exact) || ($maximum !== null && ($maximum < 1 || $value > $maximum))) {
            $errors[] = $this->error('positive_integer_invalid', $field, 'Der Wert muss eine positive Ganzzahl innerhalb des Servermaximums sein.');

            return null;
        }

        return $value;
    }

    /** @param list<ProjectConfigurationError> $errors */
    private function boolean(mixed $value, string $field, array &$errors): ?bool
    {
        if (! is_bool($value)) {
            $errors[] = $this->error('boolean_invalid', $field, 'Der Wert muss ein boolescher YAML-Wert sein.');

            return null;
        }

        return $value;
    }

    /** @param list<ProjectConfigurationError> $errors */
    private function string(mixed $value, string $field, array &$errors): ?string
    {
        if (! is_string($value) || $value === '' || preg_match('//u', $value) !== 1) {
            $errors[] = $this->error('string_invalid', $field, 'Der Wert muss eine nicht leere UTF-8-Zeichenkette sein.');

            return null;
        }

        return $value;
    }

    /** @param list<string> $allowed
     * @param  list<ProjectConfigurationError>  $errors
     */
    private function enum(mixed $value, string $field, array $allowed, array &$errors): ?string
    {
        $value = $this->string($value, $field, $errors);
        if ($value !== null && ! in_array($value, $allowed, true)) {
            $errors[] = $this->error('enum_value_unknown', $field, 'Der referenzierte Wert ist serverseitig nicht erlaubt.');

            return null;
        }

        return $value;
    }

    /** @param list<ProjectConfigurationError> $errors
     * @param  callable(string): bool  $allows
     * @return list<string>
     */
    private function stringList(mixed $value, string $field, array &$errors, callable $allows, string $unknownCode): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $errors[] = $this->error('string_list_invalid', $field, 'Der Wert muss eine nicht leere Liste sein.');

            return [];
        }
        $result = [];
        foreach ($value as $index => $item) {
            $item = $this->string($item, $field.'.'.$index, $errors);
            if ($item === null) {
                continue;
            }
            if (! $allows($item)) {
                $errors[] = $this->error($unknownCode, $field.'.'.$index, 'Die Referenz ist serverseitig nicht bekannt.');

                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @param list<string> $keys */
    private function mappingHasExactKeys(mixed $value, array $keys): bool
    {
        return is_array($value) && ! array_is_list($value) && $this->hasExactKeys($value, $keys);
    }

    /** @param array<string, mixed> $value
     * @param  list<string>  $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys)
            && array_diff(array_keys($value), $keys) === [];
    }

    private function error(string $code, string $field, string $message): ProjectConfigurationError
    {
        return new ProjectConfigurationError($code, $field, $message);
    }
}
