<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api.php';

$tests = [
    'isolierte Drei-Dateien-Änderung erhält allen Fremdinhalt' => 'testExactThreeFileUpdate',
    'abweichender Ausgangsstatus wird vereinheitlicht' => 'testRepairsExistingDrift',
    'bereits im Ticket stehender Zielstatus repariert die Indizes' => 'testRepairsDriftToTicketStatus',
    'Ticketliste kennzeichnet Statusabweichungen' => 'testListReportsStatusDrift',
    'ohne Inventardateien wird nur die Ticketdatei geändert' => 'testUpdatesTicketWithoutIndexes',
    'optionale Inventardateien funktionieren mit vorhandenem Validator' => 'testOptionalIndexesWithValidator',
    'ohne Doku-Inventar werden nur Ticket und README geändert' => 'testUpdatesTicketAndReadmeOnly',
    'ohne README werden nur Ticket und Doku-Inventar geändert' => 'testUpdatesTicketAndDocsOnly',
    'doppelte Inventarzeile verändert keine Datei' => 'testRejectsDuplicateIndexRow',
    'Schreibfehler stellt alle Originale wieder her' => 'testRollsBackWriteFailure',
    'Validatorfehler stellt alle Originale wieder her' => 'testRollsBackValidatorFailure',
    'ungültige Eingaben verändern keine Datei' => 'testRejectsInvalidInput',
    'kanonische Inventarzeile wird minimal ersetzt' => 'testCanonicalIndexEditIsMinimal',
    'vollständiges Repository-Fixture akzeptiert keine neuen Validatorfehler' => 'testFullFixtureWithExistingValidatorErrors',
];

$failures = [];
foreach ($tests as $label => $test) {
    try {
        $test();
        echo "OK: $label" . PHP_EOL;
    } catch (Throwable $e) {
        $failures[] = "$label: {$e->getMessage()}";
        echo "FEHLER: $label" . PHP_EOL;
    }
}

if ($failures !== []) {
    echo PHP_EOL . implode(PHP_EOL, $failures) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Alle Statuswechsel-Tests bestanden.' . PHP_EOL;
exit(0);

function testExactThreeFileUpdate(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        $result = updateTicketStatus($root, 'M999', 'review', validator: static function (): void {
        });

        assertSameValue('todo', $result['previous_status']);
        assertSameValue('review', $result['status']);
        assertTrue($result['changed']);

        $expectedTicket = str_replace("status: todo\r\n", "status: review\r\n", $originals['ticket']);
        $expectedReadme = str_replace('| M999 | Testticket | 1.7 | P1 | todo | — |', '| M999 | Testticket | 1.7 | P1 | review | — |', $originals['readme']);
        $expectedDocs = str_replace('| M999 | todo | Testticket | P1 | 1.7 | — |', '| M999 | review | Testticket | P1 | 1.7 | — |', $originals['docs']);

        assertSameValue($expectedTicket, readFixtureFile($root . '/tickets/M999.md'));
        assertSameValue($expectedReadme, readFixtureFile($root . '/tickets/README.md'));
        assertSameValue($expectedDocs, readFixtureFile($root . '/docs/04_TICKETS.md'));
        assertContainsText('Prosa status: done bleibt unverändert.', readFixtureFile($root . '/tickets/M999.md'));
    });
}

function testRepairsExistingDrift(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        writeFixtureFile(
            $root . '/docs/04_TICKETS.md',
            str_replace('| M999 | todo |', '| M999 | done |', $originals['docs']),
        );
        $drifted = snapshotFixture($root);
        $result = updateTicketStatus($root, 'M999', 'review', validator: static function (): void {
        });

        assertTrue($result['changed']);
        assertSameValue(
            ['ticket' => 'todo', 'readme' => 'todo', 'docs' => 'done'],
            $result['previous_statuses'],
        );
        assertSameValue(
            editTicketYamlStatus($drifted['ticket'], 'M999', 'review')['contents'],
            readFixtureFile($root . '/tickets/M999.md'),
        );
        assertSameValue(
            editIndexStatus($drifted['readme'], 'M999', 'review', 'tickets/README.md')['contents'],
            readFixtureFile($root . '/tickets/README.md'),
        );
        assertSameValue(
            editIndexStatus($drifted['docs'], 'M999', 'review', 'docs/04_TICKETS.md')['contents'],
            readFixtureFile($root . '/docs/04_TICKETS.md'),
        );
    });
}

function testRepairsDriftToTicketStatus(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        writeFixtureFile(
            $root . '/docs/04_TICKETS.md',
            str_replace('| M999 | todo |', '| M999 | done |', $originals['docs']),
        );

        $result = updateTicketStatus($root, 'M999', 'todo', validator: static function (): void {
        });

        assertTrue($result['changed']);
        assertSameValue($originals, snapshotFixture($root));
    });
}

function testListReportsStatusDrift(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        $consistent = listedFixtureTicket($root);
        assertTrue($consistent['status_consistent']);

        writeFixtureFile(
            $root . '/docs/04_TICKETS.md',
            str_replace('| M999 | todo |', '| M999 | done |', $originals['docs']),
        );
        $drifted = listedFixtureTicket($root);
        assertSameValue('todo', $drifted['status']);
        assertTrue(!$drifted['status_consistent']);
    });
}

function testUpdatesTicketWithoutIndexes(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        deleteFixtureFile($root . '/tickets/README.md');
        deleteFixtureFile($root . '/docs/04_TICKETS.md');

        $result = updateTicketStatus($root, 'M999', 'review');

        assertTrue($result['changed']);
        assertSameValue(['ticket' => 'todo'], $result['previous_statuses']);
        assertSameValue(['tickets/M999.md'], $result['updated_files']);
        assertSameValue(null, $result['validator_clean']);
        assertSameValue(
            editTicketYamlStatus($originals['ticket'], 'M999', 'review')['contents'],
            readFixtureFile($root . '/tickets/M999.md'),
        );
        assertTrue(!file_exists($root . '/tickets/README.md'));
        assertTrue(!file_exists($root . '/docs/04_TICKETS.md'));
        assertTrue(listedFixtureTicket($root)['status_consistent']);
    });
}

function testOptionalIndexesWithValidator(): void
{
    withSmallFixture(function (string $root): void {
        deleteFixtureFile($root . '/tickets/README.md');
        deleteFixtureFile($root . '/docs/04_TICKETS.md');
        writeFixtureFile($root . '/tools/validate_tickets.php', "<?php\n\nexit(0);\n");

        $result = updateTicketStatus($root, 'M999', 'review');

        assertTrue($result['changed']);
        assertSameValue(true, $result['validator_clean']);
        assertSameValue(['tickets/M999.md'], $result['updated_files']);
        assertTrue(!file_exists($root . '/tickets/README.md'));
        assertTrue(!file_exists($root . '/docs/04_TICKETS.md'));
    });
}

function testUpdatesTicketAndReadmeOnly(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        deleteFixtureFile($root . '/docs/04_TICKETS.md');

        $result = updateTicketStatus($root, 'M999', 'review');

        assertSameValue(['ticket' => 'todo', 'readme' => 'todo'], $result['previous_statuses']);
        assertSameValue(['tickets/M999.md', 'tickets/README.md'], $result['updated_files']);
        assertSameValue(
            editTicketYamlStatus($originals['ticket'], 'M999', 'review')['contents'],
            readFixtureFile($root . '/tickets/M999.md'),
        );
        assertSameValue(
            editIndexStatus($originals['readme'], 'M999', 'review', 'tickets/README.md')['contents'],
            readFixtureFile($root . '/tickets/README.md'),
        );
        assertTrue(!file_exists($root . '/docs/04_TICKETS.md'));
        assertTrue(listedFixtureTicket($root)['status_consistent']);
    });
}

function testUpdatesTicketAndDocsOnly(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        deleteFixtureFile($root . '/tickets/README.md');

        $result = updateTicketStatus($root, 'M999', 'review');

        assertSameValue(['ticket' => 'todo', 'docs' => 'todo'], $result['previous_statuses']);
        assertSameValue(['tickets/M999.md', 'docs/04_TICKETS.md'], $result['updated_files']);
        assertSameValue(
            editTicketYamlStatus($originals['ticket'], 'M999', 'review')['contents'],
            readFixtureFile($root . '/tickets/M999.md'),
        );
        assertSameValue(
            editIndexStatus($originals['docs'], 'M999', 'review', 'docs/04_TICKETS.md')['contents'],
            readFixtureFile($root . '/docs/04_TICKETS.md'),
        );
        assertTrue(!file_exists($root . '/tickets/README.md'));
        assertTrue(listedFixtureTicket($root)['status_consistent']);
    });
}

function testRejectsDuplicateIndexRow(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        $duplicate = '| M999 | Testticket | 1.7 | P1 | todo | — |' . "\r\n";
        writeFixtureFile($root . '/tickets/README.md', $originals['readme'] . $duplicate);
        $duplicated = snapshotFixture($root);

        assertThrows(
            static fn () => updateTicketStatus($root, 'M999', 'review', validator: static function (): void {
            }),
            TicketStatusConflict::class,
        );
        assertFixtureSnapshot($root, $duplicated);
    });
}

function testRollsBackWriteFailure(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        $writer = static function ($handle, string $contents, string $key): void {
            if ($key === 'readme') {
                fseek($handle, 0);
                ftruncate($handle, 0);
                fwrite($handle, 'absichtlich unvollständig');
                fflush($handle);
                throw new RuntimeException('simulierter Schreibfehler');
            }
            writeLockedContents($handle, $contents, $key);
        };

        assertThrows(
            static fn () => updateTicketStatus(
                $root,
                'M999',
                'review',
                writer: $writer,
                validator: static function (): void {
                },
            ),
            TicketStatusPersistenceFailure::class,
        );
        assertFixtureSnapshot($root, $originals);
    });
}

function testRollsBackValidatorFailure(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        assertThrows(
            static fn () => updateTicketStatus(
                $root,
                'M999',
                'review',
                validator: static function (): void {
                    throw new TicketStatusConflict('simulierte Validatorablehnung');
                },
            ),
            TicketStatusConflict::class,
        );
        assertFixtureSnapshot($root, $originals);
    });
}

function testRejectsInvalidInput(): void
{
    withSmallFixture(function (string $root, array $originals): void {
        assertThrows(
            static fn () => updateTicketStatus($root, '../M999', 'review'),
            InvalidArgumentException::class,
        );
        assertThrows(
            static fn () => updateTicketStatus($root, 'M999', 'erledigt'),
            InvalidArgumentException::class,
        );
        assertFixtureSnapshot($root, $originals);
    });
}

function testCanonicalIndexEditIsMinimal(): void
{
    $root = dirname(__DIR__, 2);
    $readme = readFixtureFile($root . '/tickets/README.md');
    $ticketStatus = statusForTicketList(readFixtureFile($root . '/tickets/M156.md'), 'M156');
    $targetStatus = $ticketStatus === 'review' ? 'in_progress' : 'review';
    $oldRow = '| M156 | Lokale Docker-Testumgebung für den Sales-Chatbot | 1.7 | P1 | '
        . $ticketStatus . ' | M25, M78, M110, M112 |';
    $newRow = '| M156 | Lokale Docker-Testumgebung für den Sales-Chatbot | 1.7 | P1 | '
        . $targetStatus . ' | M25, M78, M110, M112 |';
    assertSameValue(1, substr_count($readme, $oldRow));
    assertSameValue(
        str_replace($oldRow, $newRow, $readme),
        editIndexStatus($readme, 'M156', $targetStatus, 'tickets/README.md')['contents'],
    );
}

function testFullFixtureWithExistingValidatorErrors(): void
{
    $sourceRoot = dirname(__DIR__, 2);
    $sourceHashes = sourceStatusHashes($sourceRoot, 'M100');
    $fixture = createTemporaryDirectory('ticket_status_full_');

    try {
        copyValidatorFixture($sourceRoot, $fixture);
        synchronizeFixtureStatusesWithTickets($fixture);
        $m140Status = statusForTicketList(readFixtureFile($fixture . '/tickets/M140.md'), 'M140');
        $m140DriftStatus = $m140Status === 'todo' ? 'in_progress' : 'todo';
        writeFixtureFile(
            $fixture . '/tickets/README.md',
            editIndexStatus(
                readFixtureFile($fixture . '/tickets/README.md'),
                'M140',
                $m140DriftStatus,
                'tickets/README.md',
            )['contents'],
        );
        writeFixtureFile(
            $fixture . '/docs/04_TICKETS.md',
            editIndexStatus(
                readFixtureFile($fixture . '/docs/04_TICKETS.md'),
                'M140',
                $m140DriftStatus,
                'docs/04_TICKETS.md',
            )['contents'],
        );
        $before = snapshotFixture($fixture, 'M100');
        $currentStatus = statusForTicketList($before['ticket'], 'M100');
        $targetStatus = $currentStatus === 'in_progress' ? 'review' : 'in_progress';
        $driftStatus = $currentStatus === 'todo' || $targetStatus === 'todo' ? 'done' : 'todo';

        $baselineResult = canonicalTicketValidatorResult($fixture);
        assertTrue($baselineResult['exit_code'] !== 0);
        assertSameValue(2, count($baselineResult['errors']));
        writeFixtureFile(
            $fixture . '/docs/04_TICKETS.md',
            editIndexStatus($before['docs'], 'M100', $driftStatus, 'docs/04_TICKETS.md')['contents'],
        );
        $drifted = snapshotFixture($fixture, 'M100');

        $result = updateTicketStatus($fixture, 'M100', $targetStatus);
        assertSameValue($currentStatus, $result['previous_status']);
        assertSameValue(
            ['ticket' => $currentStatus, 'readme' => $currentStatus, 'docs' => $driftStatus],
            $result['previous_statuses'],
        );
        assertSameValue($targetStatus, $result['status']);
        assertTrue($result['changed']);
        assertSameValue(false, $result['validator_clean']);
        assertSameValue(2, $result['remaining_validator_errors']);

        assertSameValue(
            editTicketYamlStatus($drifted['ticket'], 'M100', $targetStatus)['contents'],
            readFixtureFile($fixture . '/tickets/M100.md'),
        );
        assertSameValue(
            editIndexStatus($drifted['readme'], 'M100', $targetStatus, 'tickets/README.md')['contents'],
            readFixtureFile($fixture . '/tickets/README.md'),
        );
        assertSameValue(
            editIndexStatus($drifted['docs'], 'M100', $targetStatus, 'docs/04_TICKETS.md')['contents'],
            readFixtureFile($fixture . '/docs/04_TICKETS.md'),
        );
        $remainingResult = canonicalTicketValidatorResult($fixture);
        assertTrue($remainingResult['exit_code'] !== 0);
        assertSameValue(2, count($remainingResult['errors']));

        $restored = updateTicketStatus($fixture, 'M100', $currentStatus);
        assertTrue($restored['changed']);
        assertFixtureSnapshot($fixture, $before, 'M100');
        assertSameValue($sourceHashes, sourceStatusHashes($sourceRoot, 'M100'));
    } finally {
        removeTemporaryTree($fixture);
    }
}

/** @param callable(string, array{ticket: string, readme: string, docs: string}): void $callback */
function withSmallFixture(callable $callback): void
{
    $root = createTemporaryDirectory('ticket_status_small_');
    try {
        createFixtureDirectory($root . '/tickets');
        createFixtureDirectory($root . '/docs');

        $ticket = implode("\r\n", [
            '**M999 — Testticket** · P1 · Phase 1.7',
            '',
            '```yaml',
            'id: M999',
            'titel: "Testticket"',
            'phase: "1.7"',
            'prio: P1',
            'status: todo',
            'depends_on: []',
            'files: []',
            'spec_refs: []',
            '```',
            '',
            'Prosa status: done bleibt unverändert.',
            '',
        ]);
        $readme = implode("\r\n", [
            '# Index',
            '',
            '| ID | Titel | Phase | Prio | Status | depends_on |',
            '|---|---|---|---|---|---|',
            '| M999 | Testticket | 1.7 | P1 | todo | — |',
            '',
        ]);
        // Abweichende Spaltenreihenfolge belegt, dass nicht hart auf Spalte 5 geschrieben wird.
        $docs = implode("\r\n", [
            '# Kanon',
            '',
            '| ID | Status | Titel | Prio | Phase | depends_on |',
            '|---|---|---|---|---|---|',
            '| M999 | todo | Testticket | P1 | 1.7 | — |',
            '',
        ]);

        writeFixtureFile($root . '/tickets/M999.md', $ticket);
        writeFixtureFile($root . '/tickets/README.md', $readme);
        writeFixtureFile($root . '/docs/04_TICKETS.md', $docs);
        $callback($root, ['ticket' => $ticket, 'readme' => $readme, 'docs' => $docs]);
    } finally {
        removeTemporaryTree($root);
    }
}

/** @return array{ticket: string, readme: string, docs: string} */
function snapshotFixture(string $root, string $id = 'M999'): array
{
    return [
        'ticket' => readFixtureFile($root . '/tickets/' . $id . '.md'),
        'readme' => readFixtureFile($root . '/tickets/README.md'),
        'docs' => readFixtureFile($root . '/docs/04_TICKETS.md'),
    ];
}

/** @return array{id: string, title: string, meta: string, status: string, status_consistent: bool} */
function listedFixtureTicket(string $root): array
{
    $tickets = listTickets(
        $root . '/tickets',
        $root . '/tickets/README.md',
        $root . '/docs/04_TICKETS.md',
    );
    foreach ($tickets as $ticket) {
        if ($ticket['id'] === 'M999') {
            return $ticket;
        }
    }

    throw new RuntimeException('M999 fehlt in der Ticketliste.');
}

/** @param array{ticket: string, readme: string, docs: string} $expected */
function assertFixtureSnapshot(string $root, array $expected, string $id = 'M999'): void
{
    assertSameValue($expected, snapshotFixture($root, $id));
}

/** @return array{ticket: string, readme: string, docs: string} */
function sourceStatusHashes(string $root, string $id): array
{
    $paths = statusFilePaths(realpath($root) ?: $root, $id);

    return [
        'ticket' => hash_file('sha256', $paths['ticket']) ?: '',
        'readme' => hash_file('sha256', $paths['readme']) ?: '',
        'docs' => hash_file('sha256', $paths['docs']) ?: '',
    ];
}

function copyValidatorFixture(string $source, string $target): void
{
    foreach (['docs', 'reviews'] as $directory) {
        createFixtureDirectory($target . '/' . $directory);
        foreach (glob($source . '/' . $directory . '/*.md') ?: [] as $file) {
            copyFixtureFile($file, $target . '/' . $directory . '/' . basename($file));
        }
    }

    createFixtureDirectory($target . '/tickets');
    foreach (glob($source . '/tickets/M*.md') ?: [] as $file) {
        copyFixtureFile($file, $target . '/tickets/' . basename($file));
    }
    copyFixtureFile($source . '/tickets/README.md', $target . '/tickets/README.md');
    copyFixtureFile($source . '/tools/validate_tickets.php', $target . '/tools/validate_tickets.php');
    foreach (['g3b-baseline.md', 'g3b-abnahme.md'] as $file) {
        copyFixtureFile(
            $source . '/laravel/resources/rag/' . $file,
            $target . '/laravel/resources/rag/' . $file,
        );
    }
}

function synchronizeFixtureStatusesWithTickets(string $root): void
{
    $readme = readFixtureFile($root . '/tickets/README.md');
    $docs = readFixtureFile($root . '/docs/04_TICKETS.md');

    foreach (glob($root . '/tickets/M*.md') ?: [] as $ticketPath) {
        $id = basename($ticketPath, '.md');
        $status = statusForTicketList(readFixtureFile($ticketPath), $id);
        if ($status === '') {
            throw new RuntimeException("Fixture-Ticketstatus konnte nicht gelesen werden: $id");
        }
        $readme = editIndexStatus($readme, $id, $status, 'tickets/README.md')['contents'];
        $docs = editIndexStatus($docs, $id, $status, 'docs/04_TICKETS.md')['contents'];
    }

    writeFixtureFile($root . '/tickets/README.md', $readme);
    writeFixtureFile($root . '/docs/04_TICKETS.md', $docs);
}

function copyFixtureFile(string $source, string $target): void
{
    createFixtureDirectory(dirname($target));
    if (!copy($source, $target)) {
        throw new RuntimeException("Fixture-Datei konnte nicht kopiert werden: $source");
    }
}

function createTemporaryDirectory(string $prefix): string
{
    $path = rtrim(sys_get_temp_dir(), "/\\") . '/' . $prefix . bin2hex(random_bytes(8));
    createFixtureDirectory($path);

    return $path;
}

function createFixtureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Fixture-Verzeichnis konnte nicht erstellt werden: $path");
    }
}

function removeTemporaryTree(string $path): void
{
    $realPath = realpath($path);
    $tempRoot = realpath(sys_get_temp_dir());
    if ($realPath === false || $tempRoot === false) {
        return;
    }

    $normalizedPath = strtolower(str_replace('\\', '/', $realPath));
    $normalizedTemp = strtolower(str_replace('\\', '/', rtrim($tempRoot, "/\\"))) . '/';
    if (!str_starts_with($normalizedPath, $normalizedTemp) || !str_contains(basename($realPath), 'ticket_status_')) {
        throw new RuntimeException('Unsicheres temporäres Löschziel abgelehnt.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($realPath);
}

function readFixtureFile(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Fixture-Datei konnte nicht gelesen werden: $path");
    }

    return $contents;
}

function writeFixtureFile(string $path, string $contents): void
{
    createFixtureDirectory(dirname($path));
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Fixture-Datei konnte nicht geschrieben werden: $path");
    }
}

function deleteFixtureFile(string $path): void
{
    if (!is_file($path) || !unlink($path)) {
        throw new RuntimeException("Fixture-Datei konnte nicht entfernt werden: $path");
    }
}

function assertThrows(callable $callback, string $expectedClass): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException('Unerwartete Exceptionklasse ' . $e::class . ", erwartet: $expectedClass", 0, $e);
    }

    throw new RuntimeException("Erwartete Exception wurde nicht geworfen: $expectedClass");
}

function assertContainsText(string $needle, string $haystack): void
{
    assertTrue(str_contains($haystack, $needle), "Erwarteter Text fehlt: $needle");
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Werte sind nicht identisch.');
    }
}

function assertTrue(bool $condition, string $message = 'Bedingung ist nicht erfüllt.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
