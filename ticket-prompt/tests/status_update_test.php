<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api.php';

$validatorSource = dirname(__DIR__, 2) . '/tools/validate_tickets.php';
$masterPromptSource = dirname(__DIR__, 2) . '/ai/prompts/implementierung_master_prompt.md';
$tests = [
    'hält Oberfläche und Folge-Prompts am AI6-Vertrag' => static function (string $root): void {
        unset($root);
        $html = readFixtureFile(dirname(__DIR__) . '/index.html');

        assertContainsText('Frontmatter ungültig', $html);
        assertContainsText('ticket.allowed_statuses', $html);
        assertContainsText('Ändere weder Ticketstatus noch `AGENTS.md`.', $html);
        assertNotContainsText('Inventardateien', $html);
        assertNotContainsText('Umsetzungshinweise für die Review-KI', $html);
        assertNotContainsText('setzte das Ticket auf "done"', $html);
    },
    'erzeugt einen AI6-konformen Implementierungs-Prompt' => static function (string $root): void {
        $template = readFixtureFile($root . '/ai/prompts/implementierung_master_prompt.md');
        $prompt = composePrompt($template, readFixtureFile($root . '/tickets/AI6-900.md'));

        assertContainsText('bestehenden **AI6-Repository**', $prompt);
        assertContainsText('# AI6-900 — Status-Fixture', $prompt);
        assertContainsText('Ändere weder die Ticketdatei noch Ticketstatus', $prompt);
        assertNotContainsText('Smartlife', $prompt);
        assertNotContainsText('[TICKET HIER EINFÜGEN', $prompt);
        assertNotContainsText('Setze den Ticketstatus', $prompt);
    },
    'liest Titel, Metadaten und Status aus dem Frontmatter' => static function (string $root): void {
        $tickets = listTickets($root . '/tickets');
        assertSameValue(1, count($tickets));
        assertSameValue('AI6-900', $tickets[0]['id']);
        assertSameValue('Status-Fixture', $tickets[0]['title']);
        assertSameValue('M0 · low · chore', $tickets[0]['meta']);
        assertSameValue('todo', $tickets[0]['status']);
        assertTrue($tickets[0]['status_consistent']);
        assertSameValue(['ready', 'blocked', 'cancelled'], $tickets[0]['allowed_statuses']);
    },
    'ändert nur den kanonischen Ticketstatus' => static function (string $root): void {
        $ticketBefore = readFixtureFile($root . '/tickets/AI6-900.md');
        $readmeBefore = readFixtureFile($root . '/tickets/README.md');

        $result = updateTicketStatus($root, 'AI6-900', 'ready');

        assertSameValue('todo', $result['previous_status']);
        assertSameValue(['ticket' => 'todo'], $result['previous_statuses']);
        assertSameValue('ready', $result['status']);
        assertTrue($result['changed']);
        assertTrue($result['validator_clean']);
        assertSameValue(0, $result['remaining_validator_errors']);
        assertSameValue(['todo', 'in_progress', 'blocked', 'cancelled'], $result['allowed_statuses']);
        assertSameValue(['tickets/AI6-900.md'], $result['updated_files']);
        assertSameValue($readmeBefore, readFixtureFile($root . '/tickets/README.md'));

        $ticketAfter = readFixtureFile($root . '/tickets/AI6-900.md');
        assertSameValue(
            editTicketYamlStatus($ticketBefore, 'AI6-900', 'ready')['contents'],
            $ticketAfter,
        );
        assertContainsText('Prosa mit `status: done` bleibt unverändert.', $ticketAfter);
    },
    'unterstützt den regulären Hauptpfad des Statusgraphen' => static function (string $root): void {
        foreach (['ready', 'in_progress', 'review', 'done'] as $status) {
            $result = updateTicketStatus($root, 'AI6-900', $status);
            assertSameValue($status, $result['status']);
            assertTrue($result['changed']);
        }
        assertContainsText("status: done\n", readFixtureFile($root . '/tickets/AI6-900.md'));
    },
    'unterstützt Blockierung, Wiederaufnahme und Verwerfung' => static function (string $root): void {
        foreach (['blocked', 'todo', 'cancelled'] as $status) {
            $result = updateTicketStatus($root, 'AI6-900', $status);
            assertSameValue($status, $result['status']);
        }
    },
    'lehnt Sprünge und Übergänge aus terminalen Zuständen ab' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        $before = readFixtureFile($path);
        assertThrows(
            static fn (): array => updateTicketStatus($root, 'AI6-900', 'done'),
            TicketStatusConflict::class,
        );
        assertSameValue($before, readFixtureFile($path));

        writeFixtureFile($path, str_replace('status: todo', 'status: done', $before));
        $terminal = readFixtureFile($path);
        assertThrows(
            static fn (): array => updateTicketStatus($root, 'AI6-900', 'todo'),
            TicketStatusConflict::class,
        );
        assertSameValue($terminal, readFixtureFile($path));
    },
    'behandelt einen identischen Status als No-op' => static function (string $root): void {
        $before = readFixtureFile($root . '/tickets/AI6-900.md');
        $result = updateTicketStatus($root, 'AI6-900', 'todo');

        assertTrue(!$result['changed']);
        assertSameValue(null, $result['validator_clean']);
        assertSameValue($before, readFixtureFile($root . '/tickets/AI6-900.md'));
    },
    'lehnt Altstatus und ungültige IDs ab' => static function (string $root): void {
        assertThrows(
            static fn (): array => updateTicketStatus($root, 'AI6-900', 'reserved'),
            InvalidArgumentException::class,
        );
        assertThrows(
            static fn (): array => updateTicketStatus($root, '../AI6-900', 'ready'),
            InvalidArgumentException::class,
        );
    },
    'verlangt genau ein Statusfeld im Frontmatter' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        $before = readFixtureFile($path);
        writeFixtureFile($path, str_replace("status: todo\n", "status: todo\nstatus: ready\n", $before));
        $malformed = readFixtureFile($path);

        assertThrows(
            static fn (): array => updateTicketStatus($root, 'AI6-900', 'ready'),
            TicketStatusConflict::class,
        );
        assertSameValue($malformed, readFixtureFile($path));
    },
    'verlangt den kanonischen Validator' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        $before = readFixtureFile($path);
        unlink($root . '/tools/validate_tickets.php');

        assertThrows(
            static fn (): array => updateTicketStatus($root, 'AI6-900', 'ready'),
            TicketStatusConflict::class,
        );
        assertSameValue($before, readFixtureFile($path));
    },
    'schreibt keinen vom Validator abgelehnten Kandidaten' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        $before = readFixtureFile($path);
        writeConditionalValidator($root);

        assertThrows(
            static fn (): array => updateTicketStatus($root, 'AI6-900', 'ready'),
            TicketStatusConflict::class,
        );
        assertSameValue($before, readFixtureFile($path));
    },
    'lehnt einen Statuswechsel bei vorhandenen Validatorfehlern ab' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        $contents = str_replace(
            'Ticket status, approval and run metadata are owned by AI6.',
            'Status wird extern gepflegt.',
            readFixtureFile($path),
        );
        writeFixtureFile($path, $contents);

        assertThrows(
            static fn (): array => updateTicketStatus($root, 'AI6-900', 'ready'),
            TicketStatusConflict::class,
        );
        assertSameValue($contents, readFixtureFile($path));
    },
    'rollt einen Schreibfehler vollständig zurück' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        $before = readFixtureFile($path);
        $writer = static function ($handle, string $contents, string $key): void {
            writeLockedContents($handle, $contents, $key);
            throw new RuntimeException('simulierter Schreibfehler');
        };

        assertThrows(
            static fn (): array => updateTicketStatus(
                $root,
                'AI6-900',
                'ready',
                writer: $writer,
                validator: static function (string $unusedRoot): void {},
            ),
            TicketStatusPersistenceFailure::class,
        );
        assertSameValue($before, readFixtureFile($path));
    },
    'rollt einen nachgelagerten Validatorfehler zurück' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        $before = readFixtureFile($path);

        assertThrows(
            static fn (): array => updateTicketStatus(
                $root,
                'AI6-900',
                'ready',
                validator: static function (string $unusedRoot): void {
                    throw new RuntimeException('simulierter Validatorfehler');
                },
            ),
            TicketStatusPersistenceFailure::class,
        );
        assertSameValue($before, readFixtureFile($path));
    },
    'markiert ungültiges Frontmatter in der Ticketliste' => static function (string $root): void {
        $path = $root . '/tickets/AI6-900.md';
        writeFixtureFile($path, str_replace('status: todo', 'status: reserved', readFixtureFile($path)));

        $ticket = listTickets($root . '/tickets')[0];
        assertSameValue('', $ticket['status']);
        assertTrue(!$ticket['status_consistent']);
        assertSameValue([], $ticket['allowed_statuses']);
    },
];

$failures = [];
foreach ($tests as $name => $test) {
    $root = createTemporaryFixture();
    try {
        createStatusFixture($root, $validatorSource, $masterPromptSource);
        $test($root);
        echo "PASS: $name\n";
    } catch (Throwable $throwable) {
        $failures[] = "$name: {$throwable->getMessage()}";
        echo "FAIL: $name\n";
    } finally {
        removeFixture($root);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "\n" . count($tests) . " Status-Regressionstests erfolgreich.\n";

function createStatusFixture(string $root, string $validatorSource, string $masterPromptSource): void
{
    writeFixtureFile(
        $root . '/docs/AI6_IMPLEMENTATION_PLAN.md',
        implode("\n", [
            '# Testplan',
            '',
            '## 3. Normativer Anforderungskatalog',
            '',
            '- **TKT-001** Testanforderung.',
            '',
            '## 4. Zielarchitektur',
            '',
            '## 15. Ticket-Blueprints',
            '',
            '## 15.1 M0 – Test',
            '',
            '### AI6-900 — Status-Fixture',
            '',
            '- **Initialstatus des späteren Detailtickets:** `todo`',
            '- **Risiko:** `low`',
            '- **Kind:** `chore`',
            '- **Depends on:** keine',
            '- **Requirement-Refs:** `TKT-001`',
            '- **Erwartete Module:** `Shared`',
            '',
            '**Ziel**',
            '',
            'Der Status lässt sich sicher ändern.',
            '',
            '**Deliverables**',
            '',
            '- Fixture.',
            '',
            '## 16. Requirement-Traceability',
            '',
        ]),
    );
    writeFixtureFile($root . '/tickets/AI6-900.md', validStatusTicket());
    writeFixtureFile(
        $root . '/tickets/README.md',
        "# Backlogansicht\n\nDiese Datei ist ausdrücklich keine Statusquelle.\n",
    );
    if (!copy($validatorSource, $root . '/tools/validate_tickets.php')) {
        throw new RuntimeException('Validator konnte nicht in das Fixture kopiert werden.');
    }
    writeFixtureFile(
        $root . '/ai/prompts/implementierung_master_prompt.md',
        readFixtureFile($masterPromptSource),
    );
}

function validStatusTicket(): string
{
    return implode("\n", [
        '---',
        'schema: ai6.ticket.v1',
        'id: AI6-900',
        'title: "Status-Fixture"',
        'status: todo',
        'depends_on: []',
        'kind: chore',
        'milestone: M0',
        'risk: low',
        'files:',
        '  - "ticket-prompt/api.php"',
        'spec_refs:',
        '  - "docs/AI6_IMPLEMENTATION_PLAN.md — TKT-001"',
        '---',
        '',
        '# AI6-900 — Status-Fixture',
        '',
        '## Goal',
        '',
        'Der Status lässt sich sicher ändern.',
        '',
        'Alle anderen Inhalte bleiben bytegenau erhalten.',
        '',
        '## Context',
        '',
        'Prosa mit `status: done` bleibt unverändert.',
        '',
        '## Tasks',
        '',
        '1. Den Statuswechsel prüfen.',
        '',
        '## Acceptance Criteria',
        '',
        '- [ ] **AC-01** Der Statuswechsel verändert nur das Frontmatter-Statusfeld.',
        '',
        '## Test Cases',
        '',
        '- **TC-01** Das Fixture vergleicht die Inhalte bytegenau.',
        '',
        '## AC Coverage',
        '',
        '| AC | Evidence |',
        '|---|---|',
        '| AC-01 | TC-01 |',
        '',
        '## Initial Scope and Sensitive Paths',
        '',
        '**Expected initial scope:**',
        '',
        '- `ticket-prompt/api.php` — existing',
        '',
        '**Sensitive paths:**',
        '',
        'None.',
        '',
        '## Do Not Change',
        '',
        'None.',
        '',
        '## Out of Scope',
        '',
        '- Produktive Ticketdateien.',
        '',
        '## Manual and External Gates',
        '',
        'None.',
        '',
        '## Review Focus',
        '',
        '- Atomarer Statuswechsel.',
        '',
        '## Notes',
        '',
        '- Definition of Done: `docs/AI6_IMPLEMENTATION_PLAN.md` §12.2.',
        '- Ticket status, approval and run metadata are owned by AI6. The implementation agent never changes them.',
        '- Necessary deviations are reported as `needs_human` or as a scope/contract request, never applied silently.',
        '',
    ]);
}

function writeConditionalValidator(string $root): void
{
    writeFixtureFile(
        $root . '/tools/validate_tickets.php',
        <<<'PHP'
<?php
$root = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, 7);
    }
}
$contents = file_get_contents($root . '/tickets/AI6-900.md');
if (is_string($contents) && str_contains($contents, "status: ready\n")) {
    echo "  - FEHLER: ready nicht erlaubt\n";
    exit(1);
}
echo "Ergebnis: gültig.\n";
PHP
        . "\n",
    );
}

/** @param callable(): mixed $callback */
function assertThrows(callable $callback, string $expectedClass): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException('Falscher Exception-Typ: ' . $throwable::class . ', erwartet: ' . $expectedClass);
    }
    throw new RuntimeException("Erwartete Exception fehlt: $expectedClass");
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Werte unterscheiden sich. Erwartet: ' . var_export($expected, true)
            . '; tatsächlich: ' . var_export($actual, true),
        );
    }
}

function assertTrue(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Bedingung ist nicht erfüllt.');
    }
}

function assertContainsText(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("Erwarteter Text fehlt: $needle");
    }
}

function assertNotContainsText(string $needle, string $haystack): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException("Unerwarteter Text vorhanden: $needle");
    }
}

function createTemporaryFixture(): string
{
    $root = rtrim(sys_get_temp_dir(), "/\\") . '/ticket_prompt_test_' . bin2hex(random_bytes(8));
    foreach (['tickets', 'docs', 'tools'] as $directory) {
        if (!mkdir($root . '/' . $directory, 0700, true)) {
            throw new RuntimeException('Fixture-Verzeichnis konnte nicht erstellt werden.');
        }
    }

    return $root;
}

function writeFixtureFile(string $path, string $contents): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
        throw new RuntimeException("Verzeichnis konnte nicht erstellt werden: $path");
    }
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException("Fixture-Datei konnte nicht geschrieben werden: $path");
    }
}

function readFixtureFile(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Fixture-Datei konnte nicht gelesen werden: $path");
    }

    return $contents;
}

function removeFixture(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($root);
}
