#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validiert den vollständigen Ticketbestand ohne externe Abhängigkeiten.
 *
 * Normaler Aufruf: php tools/validate_tickets.php
 * Testaufruf:      php tools/validate_tickets.php --root=/pfad/zum/fixture
 * Exit:            0 = alles konsistent, 1 = Fehler gefunden
 */

const REQUIRED_FIELDS = ['id', 'titel', 'phase', 'prio', 'status', 'depends_on', 'files'];
const LIST_FIELDS = ['depends_on', 'files'];
const ALLOWED_STATUS = ['todo', 'in_progress', 'review', 'done', 'reserved'];
const ALLOWED_PRIO = ['P0', 'P1', 'P2', 'P3'];
const REQUIRED_SECTIONS = [
    'Ziel',
    'Aufgaben',
    'Akzeptanzkriterien',
    'Testfälle',
    'Nicht ändern (Out of Scope)',
    'Hinweise',
    'Umsetzungshinweise für die Review-KI',
];

$argumentErrors = [];
$root = validator_root($argv, $argumentErrors);

if ($argumentErrors !== []) {
    print_errors($argumentErrors);
    exit(1);
}

$errors = [];
$ticketFiles = discover_ticket_files($root, $errors);
[$tickets, $ticketOrder] = parse_ticket_files($ticketFiles, $root, $errors);

validate_metadata_set($tickets, $errors);
validate_dependency_phases($tickets, $errors);
$topologicalOrder = topological_order($tickets, $ticketOrder, $errors);

if (count($topologicalOrder) === count($tickets)) {
    validate_scope_overlaps($tickets, $ticketOrder, $errors);
}

echo 'Geprüft: tickets/M*.md'.PHP_EOL;
echo 'Tickets gefunden: '.count($tickets).PHP_EOL;

if ($errors !== []) {
    print_errors($errors);
    echo PHP_EOL.'Ergebnis: FEHLGESCHLAGEN'.PHP_EOL;
    exit(1);
}

echo PHP_EOL.'Vorgeschlagene Bearbeitungsreihenfolge (topologisch):'.PHP_EOL;
echo '  '.implode(' -> ', $topologicalOrder).PHP_EOL;
echo PHP_EOL.'Ergebnis: OK - alle Prüfungen bestanden.'.PHP_EOL;
exit(0);

/** @param list<string> $errors */
function validator_root(array $arguments, array &$errors): string
{
    $root = dirname(__DIR__);

    for ($index = 1; $index < count($arguments); $index++) {
        $argument = (string) $arguments[$index];

        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
            continue;
        }

        if ($argument === '--root' && isset($arguments[$index + 1])) {
            $root = (string) $arguments[++$index];
            continue;
        }

        $errors[] = "Unbekanntes Argument '$argument'.";
    }

    $resolved = realpath($root);
    if ($resolved === false || !is_dir($resolved)) {
        $errors[] = "Validator-Root '$root' ist kein vorhandenes Verzeichnis.";

        return $root;
    }

    return rtrim($resolved, "/\\");
}

/** @return list<string> */
function discover_ticket_files(string $root, array &$errors): array
{
    $ticketDirectory = $root.'/tickets';
    if (!is_dir($ticketDirectory)) {
        $errors[] = 'tickets: erforderliches Verzeichnis fehlt.';

        return [];
    }

    $files = glob($ticketDirectory.'/M*.md');
    if ($files === false || $files === []) {
        $errors[] = 'tickets/M*.md: keine Ticketdateien gefunden.';

        return [];
    }

    usort($files, static function (string $left, string $right): int {
        $leftName = basename($left);
        $rightName = basename($right);
        preg_match('/^M(\d+)\.md$/', $leftName, $leftMatch);
        preg_match('/^M(\d+)\.md$/', $rightName, $rightMatch);

        if (isset($leftMatch[1], $rightMatch[1])) {
            return ((int) $leftMatch[1] <=> (int) $rightMatch[1])
                ?: strcmp($leftName, $rightName);
        }

        return strnatcasecmp($leftName, $rightName);
    });

    return array_values($files);
}

/**
 * @param list<string> $files
 * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
 */
function parse_ticket_files(array $files, string $root, array &$errors): array
{
    $tickets = [];
    $order = [];

    foreach ($files as $file) {
        $relative = relative_path($root, $file);
        $filename = basename($file);

        if (!preg_match('/^(M\d{2,})\.md$/', $filename, $filenameMatch)) {
            $errors[] = "$relative: Dateiname muss M<ID>.md mit mindestens zwei Ziffern entsprechen.";
            continue;
        }

        $expectedId = $filenameMatch[1];
        $contents = file_get_contents($file);
        if ($contents === false) {
            $errors[] = "$relative: Datei konnte nicht gelesen werden.";
            continue;
        }

        $blocks = yaml_blocks($contents);
        if (count($blocks) !== 1) {
            $errors[] = "$relative: erwartet genau einen YAML-Metadatenblock, gefunden: ".count($blocks).'.';
            continue;
        }

        $meta = parse_yaml_block($blocks[0], $relative, $errors);
        $id = $meta['id'] ?? null;

        validate_payload_title($contents, $meta, $relative, $errors);
        validate_required_sections($contents, $relative, $errors);

        if (!is_string($id) || !preg_match('/^M\d{2,}$/', $id)) {
            $errors[] = "$relative: YAML-Feld 'id' fehlt oder ist ungültig.";
            continue;
        }

        if ($id !== $expectedId) {
            $errors[] = "$relative: Dateiname erwartet id '$expectedId', YAML enthält '$id'.";
        }

        if (isset($tickets[$id])) {
            $errors[] = "$relative: doppelte Ticket-ID '$id'.";
            continue;
        }

        $meta['_source'] = $relative;
        $tickets[$id] = $meta;
        $order[] = $id;
    }

    return [$tickets, $order];
}

/** @return list<string> */
function yaml_blocks(string $contents): array
{
    preg_match_all('/^```ya?ml[ \t]*\r?\n(.*?)^```[ \t]*\r?$/ms', $contents, $matches);

    return array_values($matches[1] ?? []);
}

/** @return array<string, mixed> */
function parse_yaml_block(string $block, string $source, array &$errors): array
{
    $result = [];
    $currentKey = null;
    $lines = preg_split('/\R/', $block) ?: [];

    foreach ($lines as $lineNumber => $rawLine) {
        if (trim($rawLine) === '' || str_starts_with(ltrim($rawLine), '#')) {
            continue;
        }

        if (preg_match('/^([a-z_]+):\s*(.*)$/i', $rawLine, $fieldMatch)) {
            $key = $fieldMatch[1];
            if (array_key_exists($key, $result)) {
                $errors[] = "$source: doppeltes YAML-Feld '$key'.";
            }

            $value = trim(strip_inline_comment($fieldMatch[2]));
            $currentKey = $key;

            if ($value === '') {
                $result[$key] = [];
            } elseif (preg_match('/^\[(.*)\]$/s', $value, $listMatch)) {
                $result[$key] = parse_inline_list($listMatch[1]);
            } else {
                $result[$key] = strip_quotes($value);
            }
            continue;
        }

        if (preg_match('/^\s{2}-\s+(.*)$/', $rawLine, $listItemMatch) && $currentKey !== null) {
            if (!isset($result[$currentKey]) || !is_array($result[$currentKey])) {
                $errors[] = "$source: Block-Liste für '$currentKey' folgt auf einen Skalar.";
                $result[$currentKey] = [];
            }

            $value = trim(strip_inline_comment($listItemMatch[1]));
            $result[$currentKey][] = strip_quotes($value);
            continue;
        }

        $line = $lineNumber + 1;
        $errors[] = "$source: nicht unterstützte YAML-Syntax in Blockzeile $line.";
    }

    return $result;
}

function strip_inline_comment(string $value): string
{
    $quote = null;
    $length = strlen($value);

    for ($index = 0; $index < $length; $index++) {
        $character = $value[$index];
        if (($character === '"' || $character === "'") && ($index === 0 || $value[$index - 1] !== '\\')) {
            if ($quote === null) {
                $quote = $character;
            } elseif ($quote === $character) {
                $quote = null;
            }
            continue;
        }

        if ($character === '#' && $quote === null && ($index === 0 || ctype_space($value[$index - 1]))) {
            return rtrim(substr($value, 0, $index));
        }
    }

    return $value;
}

/** @return list<string> */
function parse_inline_list(string $contents): array
{
    if (trim($contents) === '') {
        return [];
    }

    $items = array_map('trim', explode(',', $contents));

    return array_values(array_map('strip_quotes', $items));
}

function strip_quotes(string $value): string
{
    $value = trim($value);
    if (strlen($value) < 2) {
        return $value;
    }

    $first = $value[0];
    $last = substr($value, -1);
    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        return substr($value, 1, -1);
    }

    return $value;
}

/** @param array<string, mixed> $meta */
function validate_payload_title(string $contents, array $meta, string $source, array &$errors): void
{
    $lines = preg_split('/\R/', $contents) ?: [];
    $firstNonEmpty = '';

    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $firstNonEmpty = trim($line);
            break;
        }
    }

    if (!str_starts_with($firstNonEmpty, '**M')) {
        if (preg_match('/^\*\*M\d+/m', $contents)) {
            $errors[] = "$source: die kanonische Payload-Titelzeile muss die erste nichtleere Zeile sein.";
        } else {
            $errors[] = "$source: kanonische Payload-Titelzeile fehlt.";
        }

        return;
    }

    $phasePattern = '(?:0|[1-9]\d*)(?:\.\d+)?';
    $pattern = '/^\*\*(M\d{2,}) — (.+)\*\* · (P[0-3]) · Phase ('.$phasePattern.')$/u';
    if (!preg_match($pattern, $firstNonEmpty, $match)) {
        $errors[] = "$source: ungültige kanonische Payload-Titelzeile.";

        return;
    }

    $title = trim($match[2]);
    if ($title === '' || str_contains($title, ' · ') || str_contains($title, '**')) {
        $errors[] = "$source: Kurztitel der Payload-Titelzeile ist leer oder enthält ein Format-Trennzeichen.";
    }

    $expected = [
        'id' => $match[1],
        'titel' => $title,
        'prio' => $match[3],
        'phase' => $match[4],
    ];

    foreach ($expected as $field => $titleValue) {
        $yamlValue = $meta[$field] ?? null;
        if (!is_string($yamlValue) || $yamlValue !== $titleValue) {
            $errors[] = "$source: Titelzeile und YAML-Feld '$field' weichen voneinander ab.";
        }
    }
}

function validate_required_sections(string $contents, string $source, array &$errors): void
{
    $previousPosition = -1;

    foreach (REQUIRED_SECTIONS as $section) {
        $count = preg_match_all('/^## '.preg_quote($section, '/').'[ \t]*$/m', $contents, $matches, PREG_OFFSET_CAPTURE);
        if ($count !== 1) {
            $errors[] = "$source: Abschnitt '## $section' muss genau einmal vorhanden sein, gefunden: $count.";
            continue;
        }

        $position = $matches[0][0][1];
        if ($position < $previousPosition) {
            $errors[] = "$source: Abschnitt '## $section' steht nicht in der Reihenfolge der Ticketvorlage.";
        }
        $previousPosition = $position;
    }
}

/** @param array<string, array<string, mixed>> $tickets */
function validate_metadata_set(array $tickets, array &$errors): void
{
    foreach ($tickets as $id => $meta) {
        $source = ticket_source($id, $meta);

        foreach (REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $meta)) {
                $errors[] = "$source: Pflichtfeld fehlt: $field.";
            }
        }

        foreach ($meta as $field => $_value) {
            if ($field !== '_source' && !in_array($field, REQUIRED_FIELDS, true)) {
                $errors[] = "$source: unbekanntes YAML-Feld '$field'.";
            }
        }

        foreach (['id', 'titel', 'phase', 'prio', 'status'] as $field) {
            if (array_key_exists($field, $meta) && !is_string($meta[$field])) {
                $errors[] = "$source: Feld '$field' muss ein Skalar sein.";
            }
        }

        foreach (LIST_FIELDS as $field) {
            if (array_key_exists($field, $meta) && !is_array($meta[$field])) {
                $errors[] = "$source: Feld '$field' muss eine Liste sein.";
            }
        }

        $title = $meta['titel'] ?? null;
        if (is_string($title) && trim($title) === '') {
            $errors[] = "$source: Feld 'titel' darf nicht leer sein.";
        }

        $status = $meta['status'] ?? null;
        if (is_string($status) && !in_array($status, ALLOWED_STATUS, true)) {
            $errors[] = "$source: ungültiger status '$status'.";
        }

        $priority = $meta['prio'] ?? null;
        if (is_string($priority) && !in_array($priority, ALLOWED_PRIO, true)) {
            $errors[] = "$source: ungültige prio '$priority'.";
        }

        if ($status === 'reserved' && $priority !== 'P3') {
            $errors[] = "$source: status 'reserved' erfordert prio 'P3'.";
        }

        $phase = $meta['phase'] ?? null;
        if (is_string($phase) && !preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $phase)) {
            $errors[] = "$source: ungültige phase '$phase'.";
        }

        validate_dependencies($id, $meta, $tickets, $source, $errors);
        validate_files($meta, $source, $errors);
    }
}

/**
 * @param array<string, mixed> $meta
 * @param array<string, array<string, mixed>> $tickets
 */
function validate_dependencies(string $id, array $meta, array $tickets, string $source, array &$errors): void
{
    $dependencies = is_array($meta['depends_on'] ?? null) ? $meta['depends_on'] : [];
    if (count($dependencies) !== count(array_unique($dependencies))) {
        $errors[] = "$source: depends_on enthält Duplikate.";
    }

    foreach ($dependencies as $dependency) {
        if (!is_string($dependency) || !preg_match('/^M\d{2,}$/', $dependency)) {
            $errors[] = "$source: ungültige Dependency-ID.";
        } elseif ($dependency === $id) {
            $errors[] = "$source: Ticket hängt von sich selbst ab.";
        } elseif (!isset($tickets[$dependency])) {
            $errors[] = "$source: depends_on verweist auf unbekanntes Ticket '$dependency'.";
        }
    }
}

/** @param array<string, mixed> $meta */
function validate_files(array $meta, string $source, array &$errors): void
{
    $files = is_array($meta['files'] ?? null) ? $meta['files'] : [];
    if ($files === []) {
        $errors[] = "$source: files-Liste darf nicht leer sein.";

        return;
    }

    if (count($files) !== count(array_unique($files))) {
        $errors[] = "$source: files enthält Duplikate.";
    }

    foreach ($files as $file) {
        validate_file_reference($file, $source, $errors);
    }
}

function validate_file_reference(mixed $file, string $source, array &$errors): void
{
    if (!is_string($file) || $file === '') {
        $errors[] = "$source: files enthält keinen gültigen Pfad-String.";

        return;
    }

    if (str_contains($file, "\0") || str_contains($file, '\\')) {
        $errors[] = "$source: unsicherer files-Pfad '$file'.";

        return;
    }

    if (str_starts_with($file, '/') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $file)) {
        $errors[] = "$source: absoluter oder URI-files-Pfad ist unzulässig: '$file'.";

        return;
    }

    $pathWithoutTrailingSlash = rtrim($file, '/');
    if ($pathWithoutTrailingSlash === '' || $pathWithoutTrailingSlash !== trim($pathWithoutTrailingSlash)) {
        $errors[] = "$source: ungültiger files-Pfad '$file'.";

        return;
    }

    foreach (explode('/', $pathWithoutTrailingSlash) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            $errors[] = "$source: Traversal-/Normalisierungssegment in files ist unzulässig: '$file'.";

            return;
        }
    }
}

/** @param array<string, array<string, mixed>> $tickets */
function validate_dependency_phases(array $tickets, array &$errors): void
{
    foreach ($tickets as $id => $meta) {
        $phase = $meta['phase'] ?? null;
        if (!is_string($phase) || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $phase)) {
            continue;
        }

        $dependencies = is_array($meta['depends_on'] ?? null) ? $meta['depends_on'] : [];
        foreach ($dependencies as $dependency) {
            $dependencyPhase = $tickets[$dependency]['phase'] ?? null;
            if (!is_string($dependencyPhase)
                || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $dependencyPhase)) {
                continue;
            }

            if (version_compare($dependencyPhase, $phase, '>')) {
                $source = ticket_source($id, $meta);
                $errors[] = "$source: Phase $phase darf nicht von $dependency aus der späteren Phase "
                    .$dependencyPhase.' abhängen.';
            }
        }
    }
}

/**
 * @param array<string, array<string, mixed>> $tickets
 * @param list<string> $order
 * @return list<string>
 */
function topological_order(array $tickets, array $order, array &$errors): array
{
    $sorted = [];
    $done = [];

    do {
        $progress = false;
        foreach ($order as $id) {
            if (isset($done[$id]) || !isset($tickets[$id])) {
                continue;
            }

            $ready = true;
            $dependencies = is_array($tickets[$id]['depends_on'] ?? null) ? $tickets[$id]['depends_on'] : [];
            foreach ($dependencies as $dependency) {
                if (isset($tickets[$dependency]) && !isset($done[$dependency])) {
                    $ready = false;
                    break;
                }
            }

            if ($ready) {
                $sorted[] = $id;
                $done[$id] = true;
                $progress = true;
            }
        }
    } while ($progress);

    if (count($sorted) < count($tickets)) {
        $cycle = dependency_cycle($tickets, $order);
        if ($cycle !== []) {
            $errors[] = 'Gesamtgraph: Abhängigkeitszyklus: '.implode(' -> ', $cycle).'.';
        } else {
            $unresolved = array_values(array_diff(array_keys($tickets), $sorted));
            $errors[] = 'Gesamtgraph: unauflösbare Abhängigkeiten bei '.implode(', ', $unresolved).'.';
        }
    }

    return $sorted;
}

/**
 * @param array<string, array<string, mixed>> $tickets
 * @param list<string> $order
 * @return list<string>
 */
function dependency_cycle(array $tickets, array $order): array
{
    $state = [];
    $stack = [];

    $visit = function (string $id) use (&$visit, &$state, &$stack, $tickets): array {
        $state[$id] = 1;
        $stack[] = $id;
        $dependencies = is_array($tickets[$id]['depends_on'] ?? null) ? $tickets[$id]['depends_on'] : [];

        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || !isset($tickets[$dependency])) {
                continue;
            }

            if (($state[$dependency] ?? 0) === 0) {
                $cycle = $visit($dependency);
                if ($cycle !== []) {
                    return $cycle;
                }
            } elseif ($state[$dependency] === 1) {
                $start = array_search($dependency, $stack, true);
                if ($start !== false) {
                    $cycle = array_slice($stack, $start);
                    $cycle[] = $dependency;

                    return $cycle;
                }
            }
        }

        array_pop($stack);
        $state[$id] = 2;

        return [];
    };

    foreach ($order as $id) {
        if (isset($tickets[$id]) && ($state[$id] ?? 0) === 0) {
            $cycle = $visit($id);
            if ($cycle !== []) {
                return $cycle;
            }
        }
    }

    return [];
}

/**
 * @param array<string, array<string, mixed>> $tickets
 * @param list<string> $order
 */
function validate_scope_overlaps(array $tickets, array $order, array &$errors): void
{
    $count = count($order);

    for ($leftIndex = 0; $leftIndex < $count; $leftIndex++) {
        $leftId = $order[$leftIndex];
        $leftFiles = valid_file_references($tickets[$leftId]);

        for ($rightIndex = $leftIndex + 1; $rightIndex < $count; $rightIndex++) {
            $rightId = $order[$rightIndex];
            if (ticket_depends_on($leftId, $rightId, $tickets)
                || ticket_depends_on($rightId, $leftId, $tickets)) {
                continue;
            }

            $rightFiles = valid_file_references($tickets[$rightId]);
            foreach ($leftFiles as $leftFile) {
                foreach ($rightFiles as $rightFile) {
                    if ($leftFile === '.gitignore' && $rightFile === '.gitignore') {
                        continue;
                    }

                    if (scopes_overlap($leftFile, $rightFile)) {
                        $errors[] = "$leftId und $rightId: ungeordnete Scope-Überlappung zwischen "
                            ."'$leftFile' und '$rightFile'; direkte oder transitive depends_on-Ordnung fehlt.";
                    }
                }
            }
        }
    }
}

/** @param array<string, mixed> $ticket @return list<string> */
function valid_file_references(array $ticket): array
{
    $files = is_array($ticket['files'] ?? null) ? $ticket['files'] : [];

    return array_values(array_filter(
        $files,
        static fn (mixed $file): bool => is_string($file)
            && $file !== ''
            && !str_contains($file, "\0")
            && !str_contains($file, '\\')
            && !str_starts_with($file, '/')
            && !preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $file),
    ));
}

function scopes_overlap(string $left, string $right): bool
{
    return $left === $right
        || (str_ends_with($left, '/') && str_starts_with($right, $left))
        || (str_ends_with($right, '/') && str_starts_with($left, $right));
}

/** @param array<string, array<string, mixed>> $tickets */
function ticket_depends_on(string $ticketId, string $targetId, array $tickets, array &$visited = []): bool
{
    if (isset($visited[$ticketId])) {
        return false;
    }
    $visited[$ticketId] = true;

    $dependencies = is_array($tickets[$ticketId]['depends_on'] ?? null)
        ? $tickets[$ticketId]['depends_on']
        : [];

    foreach ($dependencies as $dependency) {
        if (!is_string($dependency) || !isset($tickets[$dependency])) {
            continue;
        }

        if ($dependency === $targetId || ticket_depends_on($dependency, $targetId, $tickets, $visited)) {
            return true;
        }
    }

    return false;
}

/** @param array<string, mixed> $meta */
function ticket_source(string $id, array $meta): string
{
    return is_string($meta['_source'] ?? null) ? $meta['_source'] : "Ticket-YAML $id";
}

/** @param list<string> $errors */
function print_errors(array $errors): void
{
    echo PHP_EOL.'Fehler ('.count($errors).'):'.PHP_EOL;
    foreach ($errors as $error) {
        echo '  - FEHLER: '.$error.PHP_EOL;
    }
}

function relative_path(string $root, string $path): string
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/').'/';
    $normalizedPath = str_replace('\\', '/', $path);

    if (str_starts_with(
        DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath,
        DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot,
    )) {
        return substr($normalizedPath, strlen($normalizedRoot));
    }

    return $normalizedPath;
}
