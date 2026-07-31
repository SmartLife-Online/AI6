<?php

declare(strict_types=1);

/**
 * Ticket-Prompt-Backend.
 *
 * Liefert drei Aktionen als JSON:
 *   ?action=list              → alle Tickets aus dem tickets/-Ordner
 *   ?action=prompt&id=AI6-001 → Master-Prompt mit eingesetztem Ticketinhalt
 *   ?action=status            → Status in Ticketdatei und vorhandenen Indizes synchronisieren (POST)
 *
 * Aufruf lokal z. B. aus dem Repository-Wurzelverzeichnis:
 *   php -S localhost:8000
 *   Browser: http://localhost:8000/ticket-prompt/
 */

const TICKET_ID_PATTERN = '/^AI6-\d{3}[A-Z]?$/';
const TICKET_PLACEHOLDER = '[TICKET HIER EINFÜGEN';
const TICKET_STATUSES = ['todo', 'in_progress', 'review', 'done', 'reserved'];
const STATUS_REQUEST_HEADER = 'status-update';

final class TicketPromptHttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}

final class TicketStatusConflict extends RuntimeException
{
}

final class TicketStatusPersistenceFailure extends RuntimeException
{
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    runApi(dirname(__DIR__));
}

function runApi(string $repoRoot): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $ticketsDir = $repoRoot . '/tickets';
    $masterPromptPath = $repoRoot . '/ai/prompts/implementierung_master_prompt.md';
    $action = (string) ($_GET['action'] ?? 'list');

    try {
        match ($action) {
            'list' => respond(['ok' => true, 'tickets' => listTickets(
                $ticketsDir,
                $repoRoot . '/tickets/README.md',
                $repoRoot . '/docs/04_TICKETS.md',
            )]),
            'prompt' => respond(buildPromptResponse($ticketsDir, $masterPromptPath, (string) ($_GET['id'] ?? ''))),
            'status' => respond(buildStatusResponse($repoRoot, readStatusRequest())),
            default => fail('Unbekannte Aktion.', 400),
        };
    } catch (TicketPromptHttpException $e) {
        fail($e->getMessage(), $e->httpStatus, $e->headers);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage(), 422);
    } catch (TicketStatusConflict $e) {
        fail($e->getMessage(), 409);
    } catch (TicketStatusPersistenceFailure $e) {
        fail($e->getMessage(), 500);
    } catch (Throwable) {
        fail('Interner Fehler. Es wurde kein bestätigter Statuswechsel vorgenommen.', 500);
    }
}

/**
 * Gibt eine JSON-Antwort aus und beendet die Verarbeitung.
 *
 * @param array<string, mixed> $payload
 */
function respond(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/** @param array<string, string> $headers */
function fail(string $message, int $status, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    respond(['ok' => false, 'error' => $message]);
}

/**
 * Liest alle AI6-Ticketdateien (AI6-*.md) und liefert ID + Anzeigetitel + Phase/Prio.
 *
 * @return list<array{id: string, title: string, meta: string, status: string, status_consistent: bool}>
 */
function listTickets(string $dir, ?string $readmePath = null, ?string $docsPath = null): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/AI6-*.md') ?: [];
    sort($files, SORT_NATURAL);
    $readmeExists = $readmePath !== null && is_file($readmePath);
    $docsExists = $docsPath !== null && is_file($docsPath);
    $readmeStatuses = indexStatusesForTicketList($readmePath);
    $docsStatuses = indexStatusesForTicketList($docsPath);

    $tickets = [];
    foreach ($files as $file) {
        $id = basename($file, '.md');
        $headline = firstNonEmptyLine($file);
        $content = file_get_contents($file);
        $status = $content === false ? '' : statusForTicketList($content, $id);
        $tickets[] = [
            'id' => $id,
            'title' => cleanTitle($headline),
            'meta' => extractMeta($headline),
            'status' => $status,
            'status_consistent' => $status !== ''
                && (!$readmeExists || ($readmeStatuses[$id] ?? null) === $status)
                && (!$docsExists || ($docsStatuses[$id] ?? null) === $status),
        ];
    }

    return $tickets;
}

/** Erste nicht-leere Zeile einer Datei (für Titel/Meta). */
function firstNonEmptyLine(string $file): string
{
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return '';
    }

    $result = '';
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            $result = $line;
            break;
        }
    }
    fclose($handle);

    return $result;
}

/** Entfernt Markdown-Auszeichnung und den Meta-Teil hinter dem ersten "·". */
function cleanTitle(string $headline): string
{
    $title = str_replace('*', '', $headline);
    $title = explode('·', $title)[0];

    return trim($title);
}

/** Liefert den Meta-Teil (z. B. "P0 · Phase 0") aus der Kopfzeile, falls vorhanden. */
function extractMeta(string $headline): string
{
    $plain = trim(str_replace('*', '', $headline));
    $pos = mb_strpos($plain, '·');
    if ($pos === false) {
        return '';
    }

    return trim(mb_substr($plain, $pos + mb_strlen('·')));
}

/**
 * Baut die Antwort für ?action=prompt.
 *
 * @return array<string, mixed>
 */
function buildPromptResponse(string $ticketsDir, string $masterPromptPath, string $id): array
{
    $ticket = readTicket($ticketsDir, $id);
    if ($ticket === null) {
        fail('Ticket nicht gefunden: ' . $id, 404);
    }

    if (!is_file($masterPromptPath)) {
        fail('Master-Prompt-Vorlage nicht gefunden (ai/prompts/implementierung_master_prompt.md).', 500);
    }

    $template = file_get_contents($masterPromptPath);
    if ($template === false) {
        fail('Master-Prompt-Vorlage nicht lesbar.', 500);
    }

    return [
        'ok' => true,
        'id' => $id,
        'prompt' => composePrompt($template, $ticket),
    ];
}

/** Liest den Ticketinhalt; schützt gegen Path-Traversal über ein striktes ID-Muster. */
function readTicket(string $dir, string $id): ?string
{
    if (preg_match(TICKET_ID_PATTERN, $id) !== 1) {
        return null;
    }

    $path = $dir . '/' . $id . '.md';
    if (!is_file($path)) {
        return null;
    }

    $content = file_get_contents($path);

    return $content === false ? null : $content;
}

/**
 * Ersetzt die Platzhalter-Zeile im Master-Prompt durch den Ticketinhalt.
 * Fehlt der Platzhalter, wird das Ticket sauber abgetrennt angehängt.
 */
function composePrompt(string $template, string $ticket): string
{
    $ticketBlock = rtrim($ticket) . "\n";
    $lines = preg_split('/\R/u', $template) ?: [];

    $out = [];
    $replaced = false;
    foreach ($lines as $line) {
        if (!$replaced && mb_strpos($line, TICKET_PLACEHOLDER) !== false) {
            $out[] = $ticketBlock;
            $replaced = true;
            continue;
        }
        $out[] = $line;
    }

    $result = implode("\n", $out);

    if (!$replaced) {
        $result = rtrim($result) . "\n\n---\n\n" . $ticketBlock;
    }

    return $result;
}

/**
 * Liest den absichtlich nicht als einfaches Formular sendbaren JSON-Request.
 * Der Custom-Header erzwingt bei fremden Browser-Origin einen CORS-Preflight.
 *
 * @return array{id: string, status: string}
 */
function readStatusRequest(): array
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        throw new TicketPromptHttpException('Statusänderungen sind nur per POST erlaubt.', 405, ['Allow' => 'POST']);
    }

    if ((string) ($_SERVER['HTTP_X_TICKET_PROMPT_REQUEST'] ?? '') !== STATUS_REQUEST_HEADER) {
        throw new TicketPromptHttpException('Sicherheitsheader für die Statusänderung fehlt.', 403);
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new TicketPromptHttpException('Statusänderungen erwarten application/json.', 415);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 2048) {
        throw new TicketPromptHttpException('Ungültige Request-Größe.', 400);
    }

    try {
        $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new TicketPromptHttpException('Ungültiges JSON.', 400);
    }

    if (!is_array($payload) || !is_string($payload['id'] ?? null) || !is_string($payload['status'] ?? null)) {
        throw new TicketPromptHttpException('Ticket-ID und Status müssen als Strings angegeben werden.', 422);
    }

    return ['id' => $payload['id'], 'status' => $payload['status']];
}

/**
 * @param array{id: string, status: string} $payload
 * @return array<string, mixed>
 */
function buildStatusResponse(string $repoRoot, array $payload): array
{
    $result = updateTicketStatus($repoRoot, $payload['id'], $payload['status']);

    return ['ok' => true] + $result;
}

/**
 * Ändert den Workflowstatus in der Ticketdatei und allen vorhandenen Inventardateien. Fundstellen werden vorab
 * eindeutig geprüft; Schreib- oder Validatorfehler stellen die Originalinhalte wieder her.
 *
 * @param (callable(resource, string, string): void)|null $writer Nur für Fehlerfalltests.
 * @param (callable(string): void)|null $validator Nur für isolierte Fixture-Tests.
 * @return array{
 *   id: string,
 *   previous_status: string,
 *   previous_statuses: array<string, string>,
 *   status: string,
 *   changed: bool,
 *   validator_clean: bool|null,
 *   remaining_validator_errors: int,
 *   updated_files: list<string>
 * }
 */
function updateTicketStatus(
    string $repoRoot,
    string $id,
    string $newStatus,
    ?callable $writer = null,
    ?callable $validator = null,
): array {
    assertValidTicketStatusInput($id, $newStatus);

    $root = realpath($repoRoot);
    if ($root === false || !is_dir($root)) {
        throw new TicketStatusConflict('Repository-Wurzel nicht gefunden.');
    }

    $paths = statusFilePaths($root, $id);
    $handles = [];
    $originals = [];
    $updates = [];
    $commitStarted = false;
    $validationResult = ['clean' => null, 'remaining_errors' => 0];

    try {
        foreach ($paths as $key => $path) {
            $safePath = assertSafeStatusFile($root, $path, $key);
            $handle = fopen($safePath, 'r+b');
            if ($handle === false) {
                throw new TicketStatusConflict("Statusdatei ist nicht schreibbar: $key.");
            }
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new TicketStatusConflict("Statusdatei konnte nicht gesperrt werden: $key.");
            }
            $handles[$key] = $handle;
            $originals[$key] = readLockedContents($handle, $key);
        }

        $edits = ['ticket' => editTicketYamlStatus($originals['ticket'], $id, $newStatus)];
        if (isset($originals['readme'])) {
            $edits['readme'] = editIndexStatus($originals['readme'], $id, $newStatus, 'tickets/README.md');
        }
        if (isset($originals['docs'])) {
            $edits['docs'] = editIndexStatus($originals['docs'], $id, $newStatus, 'docs/04_TICKETS.md');
        }

        $previousStatuses = [];
        foreach ($edits as $key => $edit) {
            $previousStatuses[$key] = $edit['previous_status'];
        }
        $uniquePreviousStatuses = array_values(array_unique($previousStatuses));
        $previousStatus = $previousStatuses['ticket'];
        $updatedFiles = statusFileLabels($id, array_keys($paths));
        if (count($uniquePreviousStatuses) === 1 && $previousStatus === $newStatus) {
            return [
                'id' => $id,
                'previous_status' => $previousStatus,
                'previous_statuses' => $previousStatuses,
                'status' => $newStatus,
                'changed' => false,
                'validator_clean' => null,
                'remaining_validator_errors' => 0,
                'updated_files' => $updatedFiles,
            ];
        }

        foreach ($edits as $key => $edit) {
            $updates[$key] = $edit['contents'];
        }

        $persist = $writer ?? static function ($handle, string $contents, string $key): void {
            writeLockedContents($handle, $contents, $key);
        };

        if ($validator === null && is_file($root . '/tools/validate_tickets.php')) {
            $validationResult = validateTicketStatusCandidate($root, $id, $originals, $updates);
        }

        $commitStarted = true;
        foreach ($updates as $key => $contents) {
            $persist($handles[$key], $contents, $key);
        }

        foreach ($updates as $key => $contents) {
            if (readLockedContents($handles[$key], $key) !== $contents) {
                throw new TicketStatusPersistenceFailure("Nachprüfung der Statusdatei fehlgeschlagen: $key.");
            }
        }

        if ($validator !== null) {
            $validator($root);
            $validationResult = ['clean' => true, 'remaining_errors' => 0];
        }

        return [
            'id' => $id,
            'previous_status' => $previousStatus,
            'previous_statuses' => $previousStatuses,
            'status' => $newStatus,
            'changed' => true,
            'validator_clean' => $validationResult['clean'],
            'remaining_validator_errors' => $validationResult['remaining_errors'],
            'updated_files' => $updatedFiles,
        ];
    } catch (Throwable $e) {
        if ($commitStarted) {
            $rollbackErrors = rollbackStatusFiles($handles, $originals);
            if ($rollbackErrors !== []) {
                throw new TicketStatusPersistenceFailure(
                    'Statusänderung fehlgeschlagen und konnte nicht vollständig zurückgerollt werden. '
                    . 'Bitte die betroffenen Statusdateien manuell prüfen: ' . implode(', ', $rollbackErrors),
                    0,
                    $e,
                );
            }
        }

        if ($e instanceof InvalidArgumentException || $e instanceof TicketStatusConflict) {
            throw $e;
        }
        if ($e instanceof TicketStatusPersistenceFailure) {
            throw $e;
        }

        throw new TicketStatusPersistenceFailure(
            'Statusänderung fehlgeschlagen; alle betroffenen Dateien wurden auf ihren Ausgangsstand zurückgesetzt.',
            0,
            $e,
        );
    } finally {
        foreach ($handles as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

function assertValidTicketStatusInput(string $id, string $status): void
{
    if (preg_match(TICKET_ID_PATTERN, $id) !== 1) {
        throw new InvalidArgumentException('Ungültige Ticket-ID.');
    }
    if (!in_array($status, TICKET_STATUSES, true)) {
        throw new InvalidArgumentException('Ungültiger Ticketstatus.');
    }
}

/** @return array<string, string> */
function statusFilePaths(string $root, string $id): array
{
    $paths = ['ticket' => $root . '/tickets/' . $id . '.md'];
    if (is_file($root . '/tickets/README.md')) {
        $paths['readme'] = $root . '/tickets/README.md';
    }
    if (is_file($root . '/docs/04_TICKETS.md')) {
        $paths['docs'] = $root . '/docs/04_TICKETS.md';
    }

    return $paths;
}

/** @param list<string> $keys @return list<string> */
function statusFileLabels(string $id, array $keys): array
{
    $labels = [
        'ticket' => 'tickets/' . $id . '.md',
        'readme' => 'tickets/README.md',
        'docs' => 'docs/04_TICKETS.md',
    ];

    return array_values(array_map(
        static fn (string $key): string => $labels[$key] ?? $key,
        $keys,
    ));
}

function assertSafeStatusFile(string $root, string $path, string $key = 'unbekannt'): string
{
    if (is_link($path) || !is_file($path)) {
        throw new TicketStatusConflict("Statusdatei fehlt oder ist ein Symlink: $key.");
    }

    $realPath = realpath($path);
    $normalizedRoot = strtolower(str_replace('\\', '/', rtrim($root, "/\\"))) . '/';
    $normalizedPath = $realPath === false ? '' : strtolower(str_replace('\\', '/', $realPath));
    if ($realPath === false || !str_starts_with($normalizedPath, $normalizedRoot)) {
        throw new TicketStatusConflict('Statusdatei liegt außerhalb des Repositorys.');
    }

    return $realPath;
}

/** @param resource $handle */
function readLockedContents($handle, string $key): string
{
    if (fseek($handle, 0) !== 0) {
        throw new TicketStatusPersistenceFailure("Statusdatei konnte nicht positioniert werden: $key.");
    }
    $contents = stream_get_contents($handle);
    if ($contents === false) {
        throw new TicketStatusPersistenceFailure("Statusdatei konnte nicht gelesen werden: $key.");
    }

    return $contents;
}

/** @param resource $handle */
function writeLockedContents($handle, string $contents, string $key): void
{
    if (fseek($handle, 0) !== 0 || !ftruncate($handle, 0)) {
        throw new TicketStatusPersistenceFailure("Statusdatei konnte nicht vorbereitet werden: $key.");
    }

    $written = 0;
    $length = strlen($contents);
    while ($written < $length) {
        $result = fwrite($handle, substr($contents, $written));
        if ($result === false || $result === 0) {
            throw new TicketStatusPersistenceFailure("Statusdatei konnte nicht vollständig geschrieben werden: $key.");
        }
        $written += $result;
    }

    if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
        throw new TicketStatusPersistenceFailure("Statusdatei konnte nicht sicher gespeichert werden: $key.");
    }
}

/**
 * @param array<string, resource> $handles
 * @param array<string, string> $originals
 * @return list<string>
 */
function rollbackStatusFiles(array $handles, array $originals): array
{
    $errors = [];
    foreach ($originals as $key => $contents) {
        if (!isset($handles[$key])) {
            continue;
        }
        try {
            writeLockedContents($handles[$key], $contents, $key);
            if (readLockedContents($handles[$key], $key) !== $contents) {
                $errors[] = $key;
            }
        } catch (Throwable) {
            $errors[] = $key;
        }
    }

    return array_values(array_unique($errors));
}

/** @return array{previous_status: string, contents: string} */
function editTicketYamlStatus(string $contents, string $id, string $newStatus): array
{
    $blockCount = preg_match_all(
        '/^```yaml[ \t]*\r?\n(.*?)^```[ \t]*\r?$/msu',
        $contents,
        $blocks,
        PREG_OFFSET_CAPTURE,
    );
    if ($blockCount !== 1) {
        throw new TicketStatusConflict("$id: genau ein YAML-Metadatenblock erwartet, gefunden: " . (int) $blockCount . '.');
    }

    $block = $blocks[1][0][0];
    $blockOffset = $blocks[1][0][1];
    $idCount = preg_match_all('/^id:[ \t]*(AI6-\d{3}[A-Z]?)[ \t]*\r?$/mu', $block, $idMatches);
    if ($idCount !== 1 || $idMatches[1][0] !== $id) {
        throw new TicketStatusConflict("$id: YAML-ID fehlt oder stimmt nicht mit dem Dateinamen überein.");
    }

    $statusCount = preg_match_all(
        '/^(status:[ \t]*)([a-z_]+)([ \t]*)\r?$/mu',
        $block,
        $statusMatches,
        PREG_OFFSET_CAPTURE,
    );
    if ($statusCount !== 1) {
        throw new TicketStatusConflict("$id: genau ein YAML-Statusfeld erwartet, gefunden: " . (int) $statusCount . '.');
    }

    $previousStatus = $statusMatches[2][0][0];
    assertExistingStatusAllowed($previousStatus, "$id YAML");
    $offset = $blockOffset + $statusMatches[2][0][1];
    $updated = substr_replace($contents, $newStatus, $offset, strlen($previousStatus));

    return ['previous_status' => $previousStatus, 'contents' => $updated];
}

/** @return array{previous_status: string, contents: string} */
function editIndexStatus(string $contents, string $id, string $newStatus, string $label): array
{
    $lines = linesWithOffsets($contents);
    $statusColumn = null;
    $matches = [];

    foreach ($lines as $lineEntry) {
        $line = rtrim($lineEntry['line'], "\r\n");
        $cells = markdownTableCells($line);
        if ($cells === null) {
            $statusColumn = null;
            continue;
        }

        $firstValue = $cells[0]['value'] ?? '';
        if (strcasecmp($firstValue, 'ID') === 0) {
            $statusColumn = null;
            foreach ($cells as $index => $cell) {
                if (strcasecmp($cell['value'], 'Status') === 0) {
                    $statusColumn = $index;
                    break;
                }
            }
            continue;
        }

        if ($statusColumn === null || $firstValue !== $id || !isset($cells[$statusColumn])) {
            continue;
        }

        $statusCell = $cells[$statusColumn];
        assertExistingStatusAllowed($statusCell['value'], "$label $id");
        $matches[] = [
            'previous_status' => $statusCell['value'],
            'offset' => $lineEntry['offset'] + $statusCell['value_offset'],
            'length' => strlen($statusCell['value']),
        ];
    }

    if (count($matches) !== 1) {
        throw new TicketStatusConflict("$label: genau eine Inventarzeile für $id erwartet, gefunden: " . count($matches) . '.');
    }

    $match = $matches[0];
    $updated = substr_replace($contents, $newStatus, $match['offset'], $match['length']);

    return ['previous_status' => $match['previous_status'], 'contents' => $updated];
}

function assertExistingStatusAllowed(string $status, string $label): void
{
    if (!in_array($status, TICKET_STATUSES, true)) {
        throw new TicketStatusConflict("$label enthält einen unbekannten Status '$status'.");
    }
}

/** @return list<array{line: string, offset: int}> */
function linesWithOffsets(string $contents): array
{
    preg_match_all('/[^\r\n]*(?:\r\n|\n|\r|$)/', $contents, $matches, PREG_OFFSET_CAPTURE);
    $lines = [];
    foreach ($matches[0] as [$line, $offset]) {
        if ($line === '') {
            continue;
        }
        $lines[] = ['line' => $line, 'offset' => $offset];
    }

    return $lines;
}

/**
 * @return list<array{value: string, value_offset: int}>|null
 */
function markdownTableCells(string $line): ?array
{
    $leftTrimmed = ltrim($line, " \t");
    $trimmed = rtrim($leftTrimmed, " \t");
    if (!str_starts_with($trimmed, '|') || !str_ends_with($trimmed, '|')) {
        return null;
    }

    $lineStart = strlen($line) - strlen($leftTrimmed);
    $bars = [];
    for ($position = 0; $position < strlen($trimmed); $position++) {
        if ($trimmed[$position] === '|') {
            $bars[] = $position;
        }
    }
    if (count($bars) < 2) {
        return null;
    }

    $cells = [];
    for ($index = 0; $index < count($bars) - 1; $index++) {
        $rawStart = $bars[$index] + 1;
        $rawLength = $bars[$index + 1] - $rawStart;
        $raw = substr($trimmed, $rawStart, $rawLength);
        $leading = strlen($raw) - strlen(ltrim($raw, " \t"));
        $value = trim($raw, " \t");
        $cells[] = [
            'value' => $value,
            'value_offset' => $lineStart + $rawStart + $leading,
        ];
    }

    return $cells;
}

function statusForTicketList(string $contents, string $id): string
{
    try {
        return editTicketYamlStatus($contents, $id, 'todo')['previous_status'];
    } catch (Throwable) {
        return '';
    }
}

/** @return array<string, string> */
function indexStatusesForTicketList(?string $path): array
{
    if ($path === null || !is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $statusColumn = null;
    $collected = [];
    foreach (linesWithOffsets($contents) as $lineEntry) {
        $line = rtrim($lineEntry['line'], "\r\n");
        $cells = markdownTableCells($line);
        if ($cells === null) {
            $statusColumn = null;
            continue;
        }

        $firstValue = $cells[0]['value'] ?? '';
        if (strcasecmp($firstValue, 'ID') === 0) {
            $statusColumn = null;
            foreach ($cells as $index => $cell) {
                if (strcasecmp($cell['value'], 'Status') === 0) {
                    $statusColumn = $index;
                    break;
                }
            }
            continue;
        }

        if ($statusColumn === null
            || preg_match(TICKET_ID_PATTERN, $firstValue) !== 1
            || !isset($cells[$statusColumn])) {
            continue;
        }

        $status = $cells[$statusColumn]['value'];
        if (in_array($status, TICKET_STATUSES, true)) {
            $collected[$firstValue][] = $status;
        }
    }

    $statuses = [];
    foreach ($collected as $id => $values) {
        $unique = array_values(array_unique($values));
        if (count($unique) === 1 && count($values) === 1) {
            $statuses[$id] = $unique[0];
        }
    }

    return $statuses;
}

function runCanonicalTicketValidator(string $repoRoot): void
{
    $result = canonicalTicketValidatorResult($repoRoot);
    if ($result['exit_code'] === 0) {
        return;
    }

    throw new TicketStatusConflict(validatorRejectionMessage($result['output']));
}

/** @return array{exit_code: int, output: list<string>, errors: list<string>} */
function canonicalTicketValidatorResult(string $repoRoot): array
{
    $validatorPath = $repoRoot . '/tools/validate_tickets.php';
    if (!is_file($validatorPath) || !function_exists('exec')) {
        throw new TicketStatusConflict('Kanonischer Ticket-Validator ist nicht verfügbar.');
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validatorPath)
        . ' --root=' . escapeshellarg($repoRoot) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
        'errors' => validatorErrors($output),
    ];
}

/** @param list<string> $output @return list<string> */
function validatorErrors(array $output): array
{
    $errors = [];
    foreach ($output as $line) {
        if (preg_match('/^\s*-\s*FEHLER:\s*(.+?)\s*$/u', $line, $match) === 1) {
            $errors[] = $match[1];
        }
    }

    $errors = array_values(array_unique($errors));
    sort($errors, SORT_STRING);

    return $errors;
}

/** @param list<string> $output */
function validatorRejectionMessage(array $output): string
{
    $details = trim(implode("\n", array_slice($output, 0, 16)));

    return 'Statuswechsel vom Ticket-Validator abgelehnt.'
        . ($details === '' ? '' : "\n" . $details);
}

/**
 * @param array<string, string> $originals
 * @param array<string, string> $updates
 * @return array{clean: bool, remaining_errors: int}
 */
function validateTicketStatusCandidate(string $repoRoot, string $id, array $originals, array $updates): array
{
    $fixture = createStatusValidationFixture();

    try {
        $relativePaths = [
            'ticket' => 'tickets/' . $id . '.md',
            'readme' => 'tickets/README.md',
            'docs' => 'docs/04_TICKETS.md',
        ];
        $excludedPaths = [];
        $candidateFiles = [];
        foreach (array_keys($updates) as $key) {
            if (!isset($relativePaths[$key], $originals[$key])) {
                throw new TicketStatusConflict("Unbekannte Statusdatei im Validierungslauf: $key.");
            }
            $excludedPaths[] = $relativePaths[$key];
            $candidateFiles[$key] = $fixture . '/' . $relativePaths[$key];
        }

        copyMarkdownFilesForValidation($repoRoot, $fixture, $excludedPaths);
        copyStatusValidationFile(
            $repoRoot . '/tools/validate_tickets.php',
            $fixture . '/tools/validate_tickets.php',
        );

        foreach ($candidateFiles as $key => $path) {
            writeStatusValidationFile($path, $originals[$key]);
        }
        $baseline = canonicalTicketValidatorResult($fixture);

        foreach ($candidateFiles as $key => $path) {
            writeStatusValidationFile($path, $updates[$key]);
        }
        $candidate = canonicalTicketValidatorResult($fixture);

        if ($candidate['exit_code'] === 0) {
            return ['clean' => true, 'remaining_errors' => 0];
        }

        $newErrors = array_values(array_diff($candidate['errors'], $baseline['errors']));
        if ($baseline['exit_code'] === 0 || $candidate['errors'] === [] || $newErrors !== []) {
            throw new TicketStatusConflict(validatorRejectionMessage($candidate['output']));
        }

        return ['clean' => false, 'remaining_errors' => count($candidate['errors'])];
    } finally {
        removeStatusValidationFixture($fixture);
    }
}

/** @param list<string> $excludedRelativePaths */
function copyMarkdownFilesForValidation(string $sourceRoot, string $targetRoot, array $excludedRelativePaths): void
{
    $excluded = array_fill_keys($excludedRelativePaths, true);
    $directory = new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $item): bool {
            if ($item->isDir()) {
                return !in_array($item->getFilename(), ['.git', 'vendor', 'node_modules'], true);
            }

            return strtolower($item->getExtension()) === 'md';
        },
    );
    $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        if (isset($excluded[$relative])) {
            continue;
        }
        copyStatusValidationFile($item->getPathname(), $targetRoot . '/' . $relative);
    }
}

function createStatusValidationFixture(): string
{
    $base = rtrim(sys_get_temp_dir(), "/\\");
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $path = $base . '/ticket_prompt_status_' . bin2hex(random_bytes(8));
        if (mkdir($path, 0700)) {
            return $path;
        }
    }

    throw new TicketStatusPersistenceFailure('Temporäres Validator-Fixture konnte nicht erstellt werden.');
}

function copyStatusValidationFile(string $source, string $target): void
{
    if (!is_file($source) || is_link($source)) {
        throw new TicketStatusConflict('Validator-Quelldatei fehlt oder ist ein Symlink.');
    }
    ensureStatusValidationDirectory(dirname($target));
    if (!copy($source, $target)) {
        throw new TicketStatusPersistenceFailure('Validator-Fixture konnte nicht vollständig kopiert werden.');
    }
}

function writeStatusValidationFile(string $path, string $contents): void
{
    ensureStatusValidationDirectory(dirname($path));
    if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
        throw new TicketStatusPersistenceFailure('Validator-Kandidat konnte nicht geschrieben werden.');
    }
}

function ensureStatusValidationDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new TicketStatusPersistenceFailure('Validator-Fixture-Verzeichnis konnte nicht erstellt werden.');
    }
}

function removeStatusValidationFixture(string $path): void
{
    $realPath = realpath($path);
    $tempRoot = realpath(sys_get_temp_dir());
    if ($realPath === false || $tempRoot === false) {
        return;
    }

    $normalizedPath = strtolower(str_replace('\\', '/', $realPath));
    $normalizedTemp = strtolower(str_replace('\\', '/', rtrim($tempRoot, "/\\"))) . '/';
    if (!str_starts_with($normalizedPath, $normalizedTemp)
        || !str_starts_with(basename($realPath), 'ticket_prompt_status_')) {
        throw new TicketStatusPersistenceFailure('Unsicheres Validator-Cleanup-Ziel wurde abgelehnt.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            if (!rmdir($item->getPathname())) {
                throw new TicketStatusPersistenceFailure('Validator-Fixture konnte nicht aufgeräumt werden.');
            }
        } elseif (!unlink($item->getPathname())) {
            throw new TicketStatusPersistenceFailure('Validator-Fixture konnte nicht aufgeräumt werden.');
        }
    }
    if (!rmdir($realPath)) {
        throw new TicketStatusPersistenceFailure('Validator-Fixture konnte nicht aufgeräumt werden.');
    }
}
