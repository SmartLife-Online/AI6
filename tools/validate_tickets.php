<?php

declare(strict_types=1);

const AI6_TICKET_FIELDS = [
    'schema',
    'id',
    'title',
    'status',
    'depends_on',
    'kind',
    'milestone',
    'risk',
    'files',
    'spec_refs',
];

const AI6_TICKET_SECTIONS = [
    'Goal',
    'Context',
    'Tasks',
    'Acceptance Criteria',
    'Test Cases',
    'AC Coverage',
    'Initial Scope and Sensitive Paths',
    'Do Not Change',
    'Out of Scope',
    'Manual and External Gates',
    'Review Focus',
    'Notes',
];

const AI6_TICKET_STATUSES = ['todo', 'ready', 'in_progress', 'blocked', 'review', 'done', 'cancelled'];
const AI6_TICKET_KINDS = ['feature', 'chore', 'fix', 'spike'];
const AI6_TICKET_MILESTONES = ['M0', 'M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7'];
const AI6_TICKET_RISKS = ['low', 'medium', 'high'];

const AI6_NOTES_BOILERPLATE = [
    '- Definition of Done: `docs/AI6_IMPLEMENTATION_PLAN.md` §12.2.',
    '- Ticket status, approval and run metadata are owned by AI6. The implementation agent never changes them.',
    '- Necessary deviations are reported as `needs_human` or as a scope/contract request, never applied silently.',
];

/** @return never */
function ai6_validator_main(array $arguments): void
{
    $root = ai6_validator_root($arguments);
    $errors = [];
    $tickets = [];
    $ticketFiles = glob($root . '/tickets/AI6-*.md') ?: [];
    sort($ticketFiles, SORT_NATURAL);

    if ($ticketFiles === []) {
        $errors[] = 'Keine Ticketdateien unter tickets/AI6-*.md gefunden.';
    }

    $requirements = ai6_plan_requirement_ids($root, $errors);
    $blueprints = ai6_plan_blueprints($root, $errors);
    foreach ($ticketFiles as $path) {
        $relativePath = 'tickets/' . basename($path);
        $ticket = ai6_parse_ticket($path, $relativePath, $requirements, $blueprints, $errors);
        if ($ticket !== null) {
            $tickets[$ticket['id']] = $ticket;
        }
    }

    ai6_validate_ticket_set($tickets, $blueprints, $errors);
    $errors = array_values(array_unique($errors));
    sort($errors, SORT_STRING);

    echo "Ticket-Validator (ai6.ticket.v1)\n";
    echo 'Root: ' . $root . "\n";
    echo "Geprüft: tickets/AI6-*.md\n";
    echo 'Gefunden: ' . count($ticketFiles) . "\n";

    if ($errors !== []) {
        foreach ($errors as $error) {
            echo '  - FEHLER: ' . $error . "\n";
        }
        echo 'Ergebnis: ' . count($errors) . " Fehler.\n";
        exit(1);
    }

    $order = ai6_topological_order($tickets);
    echo 'Topologische Reihenfolge: ' . implode(' -> ', $order) . "\n";
    echo "Ergebnis: gültig.\n";
    exit(0);
}

/** @param list<string> $arguments */
function ai6_validator_root(array $arguments): string
{
    $root = dirname(__DIR__);
    foreach (array_slice($arguments, 1) as $index => $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
            continue;
        }
        if ($argument === '--root' && isset($arguments[$index + 2])) {
            $root = $arguments[$index + 2];
        }
    }

    $resolved = realpath($root);
    if ($resolved === false || !is_dir($resolved)) {
        fwrite(STDERR, "Repository-Wurzel nicht gefunden: $root\n");
        exit(2);
    }

    return rtrim($resolved, "/\\");
}

/** @param list<string> $errors @return array<string, true> */
function ai6_plan_requirement_ids(string $root, array &$errors): array
{
    $path = $root . '/docs/AI6_IMPLEMENTATION_PLAN.md';
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $errors[] = 'docs/AI6_IMPLEMENTATION_PLAN.md kann nicht gelesen werden.';

        return [];
    }

    if (preg_match('/^## 3\.[^\n]*\n(.*?)^## 4\./ms', $contents, $section) !== 1) {
        $errors[] = 'docs/AI6_IMPLEMENTATION_PLAN.md enthält keinen eindeutig abgrenzbaren §3-Anforderungskatalog.';

        return [];
    }

    preg_match_all('/\b([A-Z]+-\d{3})\b/', $section[1], $matches);

    return array_fill_keys(array_values(array_unique($matches[1])), true);
}

/**
 * @param list<string> $errors
 * @return array<string, array{title: string, milestone: string, risk: string, kind: string, depends_on: list<string>, requirement_refs: list<string>, goal: string}>
 */
function ai6_plan_blueprints(string $root, array &$errors): array
{
    $path = $root . '/docs/AI6_IMPLEMENTATION_PLAN.md';
    $contents = @file_get_contents($path);
    if ($contents === false
        || preg_match('/^## 15\.[^\n]*\n(.*?)^## 16\./ms', $contents, $section) !== 1) {
        $errors[] = 'docs/AI6_IMPLEMENTATION_PLAN.md enthält keinen eindeutig abgrenzbaren §15-Blueprintkatalog.';

        return [];
    }

    preg_match_all('/^## 15\.\d+ (M[0-7])\b[^\n]*$/m', $section[1], $milestoneMatches, PREG_OFFSET_CAPTURE);
    preg_match_all(
        '/^### (AI6-\d{3}[A-Z]?) — ([^\n]+)\n(.*?)(?=^### AI6-|^## 15\.\d+|\z)/msu',
        $section[1],
        $matches,
        PREG_OFFSET_CAPTURE,
    );

    $blueprints = [];
    foreach ($matches[1] as $index => $idMatch) {
        $id = $idMatch[0];
        $offset = $matches[0][$index][1];
        $block = $matches[3][$index][0];
        $milestone = '';
        foreach ($milestoneMatches[1] as $milestoneIndex => $candidate) {
            if ($milestoneMatches[0][$milestoneIndex][1] > $offset) {
                break;
            }
            $milestone = $candidate[0];
        }
        if (isset($blueprints[$id])) {
            $errors[] = "docs/AI6_IMPLEMENTATION_PLAN.md: Blueprint `$id` ist doppelt vorhanden.";
            continue;
        }

        $risk = ai6_blueprint_scalar($block, 'Risiko');
        $kind = ai6_blueprint_scalar($block, 'Kind');
        $dependsRaw = ai6_blueprint_raw_value($block, 'Depends on');
        $requirementsRaw = ai6_blueprint_raw_value($block, 'Requirement-Refs');
        preg_match_all('/`(AI6-\d{3}[A-Z]?)`/', $dependsRaw, $dependsMatches);
        preg_match_all('/`([A-Z]+-\d{3})`/', $requirementsRaw, $requirementMatches);
        $dependsOn = trim($dependsRaw) === 'keine' ? [] : $dependsMatches[1];
        $goal = preg_match('/^\*\*Ziel\*\*\n\n([^\n]+)$/m', $block, $goalMatch) === 1
            ? $goalMatch[1]
            : '';

        if ($milestone === '' || $risk === '' || $kind === '' || $requirementsRaw === '' || $goal === '') {
            $errors[] = "docs/AI6_IMPLEMENTATION_PLAN.md: Blueprint `$id` ist nicht vollständig parsbar.";
        }
        $blueprints[$id] = [
            'title' => $matches[2][$index][0],
            'milestone' => $milestone,
            'risk' => $risk,
            'kind' => $kind,
            'depends_on' => $dependsOn,
            'requirement_refs' => $requirementMatches[1],
            'goal' => $goal,
        ];
    }
    if ($blueprints === []) {
        $errors[] = 'docs/AI6_IMPLEMENTATION_PLAN.md: keine Ticket-Blueprints in §15 gefunden.';
    }

    return $blueprints;
}

function ai6_blueprint_scalar(string $block, string $label): string
{
    $value = ai6_blueprint_raw_value($block, $label);

    return preg_match('/^`([^`]+)`$/', trim($value), $match) === 1 ? $match[1] : '';
}

function ai6_blueprint_raw_value(string $block, string $label): string
{
    return preg_match('/^- \*\*' . preg_quote($label, '/') . ':\*\* (.+)$/m', $block, $match) === 1
        ? trim($match[1])
        : '';
}

/**
 * @param array<string, true> $requirements
 * @param array<string, array{title: string, milestone: string, risk: string, kind: string, depends_on: list<string>, requirement_refs: list<string>, goal: string}> $blueprints
 * @param list<string> $errors
 * @return array{id: string, path: string, depends_on: list<string>}|null
 */
function ai6_parse_ticket(
    string $path,
    string $relativePath,
    array $requirements,
    array $blueprints,
    array &$errors,
): ?array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $errors[] = "$relativePath: Datei kann nicht gelesen werden.";

        return null;
    }

    ai6_validate_serialization($contents, $relativePath, $errors);
    $normalized = str_replace(["\r\n", "\r"], "\n", ltrim($contents, "\xEF\xBB\xBF"));
    if (preg_match('/\A---\n(.*?)\n---\n\n(.*)\z/s', $normalized, $match) !== 1) {
        $errors[] = "$relativePath: genau ein Frontmatter-Block am Dateianfang mit anschließender Leerzeile erwartet.";

        return null;
    }

    $metadata = ai6_parse_frontmatter($match[1], $relativePath, $errors);
    if ($metadata === null) {
        return null;
    }

    $filenameId = basename($path, '.md');
    $id = is_string($metadata['id'] ?? null) ? $metadata['id'] : $filenameId;
    ai6_validate_metadata($metadata, $filenameId, $relativePath, $requirements, $errors);
    ai6_validate_body($match[2], $metadata, $relativePath, $errors);
    ai6_validate_blueprint_fidelity($match[2], $metadata, $blueprints, $relativePath, $errors);

    if (preg_match('/^AI6-\d{3}[A-Z]?$/', $id) !== 1) {
        return null;
    }

    return [
        'id' => $id,
        'path' => $relativePath,
        'depends_on' => is_array($metadata['depends_on'] ?? null) ? $metadata['depends_on'] : [],
    ];
}

/**
 * @param array<string, string|list<string>> $metadata
 * @param array<string, array{title: string, milestone: string, risk: string, kind: string, depends_on: list<string>, requirement_refs: list<string>, goal: string}> $blueprints
 * @param list<string> $errors
 */
function ai6_validate_blueprint_fidelity(
    string $body,
    array $metadata,
    array $blueprints,
    string $path,
    array &$errors,
): void {
    $id = is_string($metadata['id'] ?? null) ? $metadata['id'] : '';
    if (!isset($blueprints[$id])) {
        $errors[] = "$path: Ticket-ID `$id` besitzt keinen Blueprint im aktuellen Plan §15.";

        return;
    }

    $blueprint = $blueprints[$id];
    foreach (['title', 'milestone', 'risk', 'kind', 'depends_on'] as $key) {
        if (($metadata[$key] ?? null) !== $blueprint[$key]) {
            $errors[] = "$path: `$key` weicht vom aktuellen Blueprint `$id` ab.";
        }
    }

    $specRefs = is_array($metadata['spec_refs'] ?? null) ? $metadata['spec_refs'] : [];
    $requirementRefs = [];
    foreach ($specRefs as $reference) {
        if (preg_match('/ — ([A-Z]+-\d{3})$/u', $reference, $match) === 1) {
            $requirementRefs[] = $match[1];
        }
    }
    if ($requirementRefs !== $blueprint['requirement_refs']) {
        $errors[] = "$path: `spec_refs` weicht von den geordneten Requirement-Refs des Blueprints `$id` ab.";
    }

    [, $sections] = ai6_extract_sections($body);
    $goalParagraphs = isset($sections['Goal'])
        ? (preg_split('/\n{2,}/', trim($sections['Goal'])) ?: [])
        : [];
    if (($goalParagraphs[0] ?? null) !== $blueprint['goal']) {
        $errors[] = "$path: der erste Goal-Absatz weicht vom Blueprint-Ziel `$id` ab.";
    }
}

/** @param list<string> $errors */
function ai6_validate_serialization(string $contents, string $path, array &$errors): void
{
    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $errors[] = "$path: UTF-8-BOM ist nicht erlaubt.";
    }
    if (preg_match('//u', $contents) !== 1) {
        $errors[] = "$path: Inhalt ist kein gültiges UTF-8.";
    }
    if (str_contains($contents, "\r")) {
        $errors[] = "$path: nur LF-Zeilenenden sind erlaubt.";
    }
    if (!str_ends_with($contents, "\n") || str_ends_with($contents, "\n\n")) {
        $errors[] = "$path: genau ein abschließender LF-Zeilenumbruch ist erforderlich.";
    }
}

/** @param list<string> $errors @return array<string, string|list<string>>|null */
function ai6_parse_frontmatter(string $block, string $path, array &$errors): ?array
{
    $lines = explode("\n", $block);
    $metadata = [];
    $keys = [];

    for ($index = 0; $index < count($lines); $index++) {
        $line = $lines[$index];
        if (preg_match('/^([a-z_]+):(.*)$/', $line, $match) !== 1) {
            $errors[] = "$path: ungültige Frontmatter-Zeile " . ($index + 2) . '. Supportet wird nur das kanonische Ticketformat.';
            continue;
        }

        $key = $match[1];
        $rawValue = ltrim($match[2], ' ');
        $keys[] = $key;
        if (array_key_exists($key, $metadata)) {
            $errors[] = "$path: doppelter Frontmatter-Schlüssel `$key`.";
            continue;
        }
        if (!in_array($key, AI6_TICKET_FIELDS, true)) {
            $errors[] = "$path: unbekannter Frontmatter-Schlüssel `$key`.";
        }

        if (in_array($key, ['depends_on', 'files', 'spec_refs'], true)) {
            if ($rawValue === '[]') {
                $metadata[$key] = [];
                continue;
            }
            if ($rawValue !== '') {
                $metadata[$key] = ai6_parse_flow_list($rawValue, $path, $key, $errors);
                continue;
            }

            $items = [];
            while (isset($lines[$index + 1]) && str_starts_with($lines[$index + 1], '  - ')) {
                $index++;
                $items[] = ai6_parse_list_value(substr($lines[$index], 4), $path, $key, $errors);
            }
            if ($key === 'depends_on') {
                $errors[] = "$path: `depends_on` muss als Flow-Sequenz serialisiert sein; leer exakt als `[]`.";
            } elseif ($items === []) {
                $errors[] = "$path: eine leere `$key`-Liste muss exakt als `$key: []` serialisiert sein.";
            }
            $metadata[$key] = $items;
            continue;
        }

        $metadata[$key] = ai6_parse_scalar($rawValue, $path, $key, $errors);
    }

    if ($keys !== AI6_TICKET_FIELDS) {
        $errors[] = "$path: Frontmatter-Schlüssel müssen vollständig und in kanonischer Reihenfolge stehen: "
            . implode(', ', AI6_TICKET_FIELDS) . '.';
    }

    return $metadata;
}

/** @param list<string> $errors */
function ai6_parse_scalar(string $value, string $path, string $key, array &$errors): string
{
    if ($value === '') {
        $errors[] = "$path: `$key` darf nicht leer sein.";

        return '';
    }
    if ($key === 'title') {
        return ai6_decode_quoted_string($value, $path, $key, $errors);
    }
    if (str_starts_with($value, '"') || str_contains($value, '#') || preg_match('/[&*!{}>|]/', $value) === 1) {
        $errors[] = "$path: `$key` verwendet keine kanonische skalare Serialisierung.";
    }

    return $value;
}

/** @param list<string> $errors @return list<string> */
function ai6_parse_flow_list(string $value, string $path, string $key, array &$errors): array
{
    if ($key !== 'depends_on' || preg_match('/^\[(.*)\]$/', $value, $match) !== 1) {
        $errors[] = "$path: `$key` muss als kanonische Liste serialisiert sein.";

        return [];
    }
    if (trim($match[1]) === '') {
        return [];
    }

    $items = array_map('trim', explode(',', $match[1]));
    foreach ($items as $item) {
        if (preg_match('/^AI6-\d{3}[A-Z]?$/', $item) !== 1) {
            $errors[] = "$path: ungültige Abhängigkeit `$item`.";
        }
    }
    if ($value !== '[' . implode(', ', $items) . ']') {
        $errors[] = "$path: `depends_on` ist nicht kanonisch serialisiert.";
    }

    return $items;
}

/** @param list<string> $errors */
function ai6_parse_list_value(string $value, string $path, string $key, array &$errors): string
{
    if (in_array($key, ['files', 'spec_refs'], true)) {
        return ai6_decode_quoted_string($value, $path, $key, $errors);
    }
    if (preg_match('/^AI6-\d{3}[A-Z]?$/', $value) !== 1) {
        $errors[] = "$path: ungültiger Listeneintrag in `$key`: `$value`.";
    }

    return $value;
}

/** @param list<string> $errors */
function ai6_decode_quoted_string(string $value, string $path, string $key, array &$errors): string
{
    if (!str_starts_with($value, '"') || !str_ends_with($value, '"')) {
        $errors[] = "$path: Werte in `$key` müssen doppelt JSON-kompatibel quotiert sein.";

        return trim($value, '"');
    }

    try {
        $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $errors[] = "$path: ungültige Zeichenkette in `$key`.";

        return '';
    }
    if (!is_string($decoded)) {
        $errors[] = "$path: `$key` enthält keine Zeichenkette.";

        return '';
    }

    return $decoded;
}

/**
 * @param array<string, string|list<string>> $metadata
 * @param array<string, true> $requirements
 * @param list<string> $errors
 */
function ai6_validate_metadata(array $metadata, string $filenameId, string $path, array $requirements, array &$errors): void
{
    ai6_expect_value($metadata, 'schema', 'ai6.ticket.v1', $path, $errors);
    $id = $metadata['id'] ?? '';
    if (!is_string($id) || preg_match('/^AI6-\d{3}[A-Z]?$/', $id) !== 1) {
        $errors[] = "$path: `id` muss dem Muster AI6-NNN oder AI6-NNNA entsprechen.";
    } elseif ($id !== $filenameId) {
        $errors[] = "$path: `id` `$id` stimmt nicht mit dem Dateinamen `$filenameId` überein.";
    }

    $title = $metadata['title'] ?? '';
    if (!is_string($title) || trim($title) === '' || str_contains($title, "\n")) {
        $errors[] = "$path: `title` muss eine nichtleere einzeilige Zeichenkette sein.";
    }

    ai6_expect_enum($metadata, 'status', AI6_TICKET_STATUSES, $path, $errors);
    ai6_expect_enum($metadata, 'kind', AI6_TICKET_KINDS, $path, $errors);
    ai6_expect_enum($metadata, 'milestone', AI6_TICKET_MILESTONES, $path, $errors);
    ai6_expect_enum($metadata, 'risk', AI6_TICKET_RISKS, $path, $errors);

    foreach (['depends_on', 'files', 'spec_refs'] as $key) {
        if (!isset($metadata[$key]) || !is_array($metadata[$key])) {
            $errors[] = "$path: `$key` muss eine Liste sein.";
        }
    }

    $files = is_array($metadata['files'] ?? null) ? $metadata['files'] : [];
    ai6_validate_unique_values($files, $path, 'files', $errors);
    foreach ($files as $file) {
        ai6_validate_repo_path($file, $path, $errors);
    }

    $specRefs = is_array($metadata['spec_refs'] ?? null) ? $metadata['spec_refs'] : [];
    if ($specRefs === []) {
        $errors[] = "$path: `spec_refs` darf nicht leer sein.";
    }
    ai6_validate_unique_values($specRefs, $path, 'spec_refs', $errors);
    foreach ($specRefs as $reference) {
        if (preg_match('/^docs\/AI6_IMPLEMENTATION_PLAN\.md — ([A-Z]{2,}-\d{3})$/u', $reference, $match) !== 1) {
            $errors[] = "$path: ungültige Spezifikationsreferenz `$reference`.";
            continue;
        }
        if (!isset($requirements[$match[1]])) {
            $errors[] = "$path: unbekannte Requirement-ID `{$match[1]}` in `spec_refs`.";
        }
    }
}

/** @param array<string, string|list<string>> $metadata @param list<string> $errors */
function ai6_expect_value(array $metadata, string $key, string $expected, string $path, array &$errors): void
{
    if (($metadata[$key] ?? null) !== $expected) {
        $errors[] = "$path: `$key` muss exakt `$expected` sein.";
    }
}

/** @param array<string, string|list<string>> $metadata @param list<string> $allowed @param list<string> $errors */
function ai6_expect_enum(array $metadata, string $key, array $allowed, string $path, array &$errors): void
{
    $value = $metadata[$key] ?? null;
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        $errors[] = "$path: `$key` muss einer dieser Werte sein: " . implode(', ', $allowed) . '.';
    }
}

/** @param list<string> $values @param list<string> $errors */
function ai6_validate_unique_values(array $values, string $path, string $key, array &$errors): void
{
    if (count($values) !== count(array_unique($values))) {
        $errors[] = "$path: `$key` enthält doppelte Einträge.";
    }
}

/** @param list<string> $errors */
function ai6_validate_repo_path(string $value, string $ticketPath, array &$errors): void
{
    $trimmed = rtrim($value, '/');
    $invalid = $value === ''
        || str_contains($value, '\\')
        || str_starts_with($value, '/')
        || str_starts_with($value, '//')
        || preg_match('/^[A-Za-z]:/', $value) === 1
        || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $value) === 1
        || preg_match('/[\x00-\x1F\x7F*?\[\]{}!]/', $value) === 1
        || str_contains($value, '//')
        || $trimmed === '';
    $segments = $trimmed === '' ? [] : explode('/', $trimmed);
    if ($invalid || in_array('.', $segments, true) || in_array('..', $segments, true) || in_array('', $segments, true)) {
        $errors[] = "$ticketPath: nichtkanonischer Repositorypfad in `files`: `$value`.";
    }
    if (class_exists(Normalizer::class) && Normalizer::normalize($value, Normalizer::FORM_C) !== $value) {
        $errors[] = "$ticketPath: Repositorypfad ist nicht in Unicode-Normalform NFC: `$value`.";
    }
}

/** @param array<string, string|list<string>> $metadata @param list<string> $errors */
function ai6_validate_body(string $body, array $metadata, string $path, array &$errors): void
{
    [$intro, $sections, $sectionNames] = ai6_extract_sections($body);
    $id = is_string($metadata['id'] ?? null) ? $metadata['id'] : '';
    $title = is_string($metadata['title'] ?? null) ? $metadata['title'] : '';
    if (trim($intro) !== "# $id — $title") {
        $errors[] = "$path: H1 muss exakt `# $id — $title` entsprechen.";
    }
    if ($sectionNames !== AI6_TICKET_SECTIONS) {
        $errors[] = "$path: die zwölf Pflichtabschnitte müssen genau einmal und in kanonischer Reihenfolge vorkommen.";

        return;
    }

    $goalParagraphs = preg_split('/\n{2,}/', trim($sections['Goal'])) ?: [];
    if (count(array_filter($goalParagraphs, static fn (string $value): bool => trim($value) !== '')) !== 2) {
        $errors[] = "$path: `Goal` muss genau zwei nichtleere Absätze enthalten.";
    }
    if (trim($sections['Context']) === '') {
        $errors[] = "$path: `Context` darf nicht leer sein.";
    }

    ai6_validate_tasks($sections['Tasks'], $path, $errors);
    $acceptanceIds = ai6_validate_id_list($sections['Acceptance Criteria'], '/^- \[ \] \*\*(AC-\d{2})\*\* \S.*$/u', 'Akzeptanzkriterium', $path, $errors);
    $testIds = ai6_validate_id_list($sections['Test Cases'], '/^- \*\*(TC-\d{2})\*\* \S.*$/u', 'Testfall', $path, $errors);
    $gateIds = ai6_validate_gates($sections['Manual and External Gates'], $path, $errors);
    ai6_validate_coverage($sections['AC Coverage'], $acceptanceIds, array_merge($testIds, $gateIds), $path, $errors);
    ai6_validate_scope($sections['Initial Scope and Sensitive Paths'], $metadata, $path, $errors);

    ai6_validate_optional_bullets($sections['Do Not Change'], 'Do Not Change', $path, $errors);
    ai6_validate_required_bullets($sections['Out of Scope'], 'Out of Scope', $path, $errors);
    ai6_validate_required_bullets($sections['Review Focus'], 'Review Focus', $path, $errors);
    ai6_validate_notes($sections['Notes'], $path, $errors);
}

/** @return array{string, array<string, string>, list<string>} */
function ai6_extract_sections(string $body): array
{
    preg_match_all('/^## ([^\n]+)\n/m', $body, $matches, PREG_OFFSET_CAPTURE);
    $sections = [];
    $names = [];
    $introEnd = isset($matches[0][0]) ? $matches[0][0][1] : strlen($body);

    foreach ($matches[1] as $index => $heading) {
        $name = $heading[0];
        $names[] = $name;
        $contentStart = $matches[0][$index][1] + strlen($matches[0][$index][0]);
        $contentEnd = isset($matches[0][$index + 1]) ? $matches[0][$index + 1][1] : strlen($body);
        $sections[$name] = trim(substr($body, $contentStart, $contentEnd - $contentStart));
    }

    return [substr($body, 0, $introEnd), $sections, $names];
}

/** @param list<string> $errors */
function ai6_validate_tasks(string $contents, string $path, array &$errors): void
{
    $lines = array_values(array_filter(explode("\n", trim($contents)), static fn (string $line): bool => trim($line) !== ''));
    if ($lines === []) {
        $errors[] = "$path: `Tasks` darf nicht leer sein.";

        return;
    }
    foreach ($lines as $index => $line) {
        $number = $index + 1;
        if (preg_match('/^' . $number . '\. \S.*$/u', $line) !== 1) {
            $errors[] = "$path: Aufgaben müssen ab 1 lückenlos nummeriert und einzeilig sein (erwartet: `$number.`).";
            break;
        }
    }
}

/** @param list<string> $errors @return list<string> */
function ai6_validate_id_list(string $contents, string $pattern, string $label, string $path, array &$errors): array
{
    $lines = array_values(array_filter(explode("\n", trim($contents)), static fn (string $line): bool => trim($line) !== ''));
    $ids = [];
    foreach ($lines as $line) {
        if (preg_match($pattern, $line, $match) !== 1) {
            $errors[] = "$path: ungültige $label-Zeile `$line`.";
            continue;
        }
        $ids[] = $match[1];
    }
    if ($ids === []) {
        $errors[] = "$path: mindestens ein $label ist erforderlich.";
    }
    if (count($ids) !== count(array_unique($ids))) {
        $errors[] = "$path: doppelte IDs im Abschnitt für $label.";
    }

    return $ids;
}

/** @param list<string> $errors @return list<string> */
function ai6_validate_gates(string $contents, string $path, array &$errors): array
{
    if (trim($contents) === 'None.') {
        return [];
    }

    return ai6_validate_id_list(
        $contents,
        '/^- \*\*((?:MG|EXT)-\d{2})\*\* \S.*$/u',
        'Gate',
        $path,
        $errors,
    );
}

/** @param list<string> $acceptanceIds @param list<string> $evidenceIds @param list<string> $errors */
function ai6_validate_coverage(string $contents, array $acceptanceIds, array $evidenceIds, string $path, array &$errors): void
{
    $lines = explode("\n", trim($contents));
    if (($lines[0] ?? '') !== '| AC | Evidence |' || ($lines[1] ?? '') !== '|---|---|') {
        $errors[] = "$path: `AC Coverage` benötigt den exakten Tabellenkopf `| AC | Evidence |`.";
    }

    $covered = [];
    $usedEvidence = [];
    foreach (array_slice($lines, 2) as $line) {
        if (preg_match('/^\| (AC-\d{2}) \| ((?:(?:TC|MG|EXT)-\d{2})(?:, (?:(?:TC|MG|EXT)-\d{2}))*) \|$/', $line, $match) !== 1) {
            $errors[] = "$path: ungültige AC-Coverage-Zeile `$line`.";
            continue;
        }
        $covered[] = $match[1];
        foreach (explode(', ', $match[2]) as $evidence) {
            $usedEvidence[] = $evidence;
            if (!in_array($evidence, $evidenceIds, true)) {
                $errors[] = "$path: Coverage referenziert nicht deklarierten Nachweis `$evidence`.";
            }
        }
        $rowEvidence = explode(', ', $match[2]);
        if (count($rowEvidence) !== count(array_unique($rowEvidence))) {
            $errors[] = "$path: Coverage-Zeile für `{$match[1]}` enthält doppelte Evidence-IDs.";
        }
    }
    if ($covered !== $acceptanceIds) {
        $errors[] = "$path: jede AC-ID muss genau einmal und in Deklarationsreihenfolge in `AC Coverage` stehen.";
    }
    foreach ($evidenceIds as $evidence) {
        if (!in_array($evidence, $usedEvidence, true)) {
            $errors[] = "$path: deklarierter Nachweis `$evidence` ist keiner AC zugeordnet.";
        }
    }
}

/** @param array<string, string|list<string>> $metadata @param list<string> $errors */
function ai6_validate_scope(string $contents, array $metadata, string $path, array &$errors): void
{
    if (preg_match('/\A\*\*Expected initial scope:\*\*\n\n(.*?)\n\n\*\*Sensitive paths:\*\*\n\n(.+)\z/s', trim($contents), $match) !== 1) {
        $errors[] = "$path: Scope-Abschnitt benötigt die exakten Labels `Expected initial scope` und `Sensitive paths`.";

        return;
    }

    $files = is_array($metadata['files'] ?? null) ? $metadata['files'] : [];
    $scopePaths = [];
    $scopeContents = trim($match[1]);
    if ($files === []) {
        if ($scopeContents !== 'None.') {
            $errors[] = "$path: bei `files: []` muss `Expected initial scope` exakt `None.` enthalten.";
        }
    } elseif ($scopeContents === 'None.') {
        $errors[] = "$path: `Expected initial scope` darf bei nichtleerer `files`-Liste nicht `None.` sein.";
    } else {
        foreach (explode("\n", $scopeContents) as $index => $line) {
            if (preg_match('/^- (.+) — (?:new|existing)$/u', $line, $scopeMatch) !== 1) {
                $errors[] = "$path: ungültiger Scope-Eintrag `$line`.";
                continue;
            }
            $file = $files[$index] ?? null;
            if (!is_string($file) || $scopeMatch[1] !== ai6_markdown_code_span($file)) {
                $errors[] = "$path: Scope-Pfad in Zeile " . ($index + 1)
                    . ' ist nicht die kanonische CommonMark-Darstellung des zugehörigen `files`-Pfads.';
                continue;
            }
            $scopePaths[] = $file;
        }
    }
    if ($scopePaths !== $files) {
        $errors[] = "$path: `files` und `Expected initial scope` müssen dieselben Pfade in derselben Reihenfolge enthalten.";
    }
    ai6_validate_optional_bullets($match[2], 'Sensitive paths', $path, $errors);
}

function ai6_markdown_code_span(string $value): string
{
    preg_match_all('/`+/', $value, $matches);
    $longestRun = 0;
    foreach ($matches[0] as $run) {
        $longestRun = max($longestRun, strlen($run));
    }
    $delimiter = str_repeat('`', $longestRun + 1);
    $needsPadding = str_starts_with($value, '`')
        || str_ends_with($value, '`')
        || str_starts_with($value, ' ')
        || str_ends_with($value, ' ');
    $padding = $needsPadding ? ' ' : '';

    return $delimiter . $padding . $value . $padding . $delimiter;
}

/** @param list<string> $errors */
function ai6_validate_optional_bullets(string $contents, string $section, string $path, array &$errors): void
{
    if (trim($contents) === 'None.') {
        return;
    }
    ai6_validate_required_bullets($contents, $section, $path, $errors);
}

/** @param list<string> $errors */
function ai6_validate_required_bullets(string $contents, string $section, string $path, array &$errors): void
{
    $lines = array_values(array_filter(explode("\n", trim($contents)), static fn (string $line): bool => trim($line) !== ''));
    if ($lines === [] || trim($contents) === 'None.') {
        $errors[] = "$path: `$section` benötigt mindestens einen Eintrag.";

        return;
    }
    foreach ($lines as $line) {
        if (preg_match('/^- \S.*$/u', $line) !== 1) {
            $errors[] = "$path: ungültige Zeile in `$section`: `$line`.";
        }
    }
}

/** @param list<string> $errors */
function ai6_validate_notes(string $contents, string $path, array &$errors): void
{
    $lines = explode("\n", trim($contents));
    foreach (AI6_NOTES_BOILERPLATE as $index => $expected) {
        if (($lines[$index] ?? null) !== $expected) {
            $line = $index + 1;
            $errors[] = "$path: Notes-Boilerplate Zeile $line fehlt oder ist verändert.";
        }
    }
    foreach (array_slice($lines, count(AI6_NOTES_BOILERPLATE)) as $line) {
        if (preg_match('/^- \S.*$/u', $line) !== 1) {
            $errors[] = "$path: zusätzliche Notes-Hinweise müssen Listeneinträge sein: `$line`.";
        }
    }
}

/**
 * @param array<string, array{id: string, path: string, depends_on: list<string>}> $tickets
 * @param array<string, array{title: string, milestone: string, risk: string, kind: string, depends_on: list<string>, requirement_refs: list<string>, goal: string}> $blueprints
 * @param list<string> $errors
 */
function ai6_validate_ticket_set(array $tickets, array $blueprints, array &$errors): void
{
    $seen = [];
    foreach ($tickets as $id => $ticket) {
        if (isset($seen[$id])) {
            $errors[] = "Ticket-ID `$id` ist mehrfach vorhanden.";
        }
        $seen[$id] = true;
        if (count($ticket['depends_on']) !== count(array_unique($ticket['depends_on']))) {
            $errors[] = "{$ticket['path']}: `depends_on` enthält doppelte Einträge.";
        }
        foreach ($ticket['depends_on'] as $dependency) {
            if ($dependency === $id) {
                $errors[] = "{$ticket['path']}: ein Ticket darf nicht von sich selbst abhängen.";
            } elseif (!isset($blueprints[$dependency])) {
                $errors[] = "{$ticket['path']}: unbekannte Abhängigkeit `$dependency`.";
            }
        }
    }

    $visiting = [];
    $visited = [];
    foreach (array_keys($tickets) as $id) {
        ai6_visit_dependency($id, $tickets, $visiting, $visited, $errors, []);
    }
}

/**
 * @param array<string, array{id: string, path: string, depends_on: list<string>}> $tickets
 * @param array<string, true> $visiting
 * @param array<string, true> $visited
 * @param list<string> $errors
 * @param list<string> $stack
 */
function ai6_visit_dependency(string $id, array $tickets, array &$visiting, array &$visited, array &$errors, array $stack): void
{
    if (isset($visited[$id]) || !isset($tickets[$id])) {
        return;
    }
    if (isset($visiting[$id])) {
        $stack[] = $id;
        $errors[] = 'Abhängigkeitszyklus: ' . implode(' -> ', $stack) . '.';

        return;
    }

    $visiting[$id] = true;
    $stack[] = $id;
    foreach ($tickets[$id]['depends_on'] as $dependency) {
        ai6_visit_dependency($dependency, $tickets, $visiting, $visited, $errors, $stack);
    }
    unset($visiting[$id]);
    $visited[$id] = true;
}

/** @param array<string, array{id: string, path: string, depends_on: list<string>}> $tickets @return list<string> */
function ai6_topological_order(array $tickets): array
{
    $order = [];
    $visited = [];
    $visit = function (string $id) use (&$visit, &$order, &$visited, $tickets): void {
        if (isset($visited[$id]) || !isset($tickets[$id])) {
            return;
        }
        $visited[$id] = true;
        foreach ($tickets[$id]['depends_on'] as $dependency) {
            $visit($dependency);
        }
        $order[] = $id;
    };
    foreach (array_keys($tickets) as $id) {
        $visit($id);
    }

    return $order;
}

ai6_validator_main($argv);
