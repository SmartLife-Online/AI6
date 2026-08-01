<?php

declare(strict_types=1);

$validatorSource = dirname(__DIR__) . '/validate_tickets.php';
$tests = [
    'akzeptiert ein kanonisches AI6-Ticket' => static function (string $root): void {
        createTicketFixture($root);
        assertValidatorPasses($root);
    },
    'akzeptiert alle Workflowstatus' => static function (string $root): void {
        foreach (['todo', 'ready', 'in_progress', 'blocked', 'review', 'done', 'cancelled'] as $status) {
            createTicketFixture($root, status: $status);
            assertValidatorPasses($root);
        }
    },
    'akzeptiert einen explizit leeren Dateiscope' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, "files:\n  - \"app/Test.php\"", 'files: []');
        replaceInTicket($root, '- `app/Test.php` — new', 'None.');
        assertValidatorPasses($root);
    },
    'akzeptiert kanonische CommonMark-Code-Spans für Backticks im Pfad' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, '"app/Test.php"', '"app/T`est.php"');
        replaceInTicket($root, '`app/Test.php`', '``app/T`est.php``');
        assertValidatorPasses($root);
    },
    'verlangt die Flow-Sequenz für depends_on' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, 'depends_on: []', "depends_on:\n  - AI6-899");
        assertValidatorFails($root, '`depends_on` muss als Flow-Sequenz serialisiert sein');
    },
    'lehnt den entfernten Altstatus reserved ab' => static function (string $root): void {
        createTicketFixture($root, status: 'reserved');
        assertValidatorFails($root, '`status` muss einer dieser Werte sein');
    },
    'verlangt mindestens eine AI6-Ticketdatei' => static function (string $root): void {
        createBaseFixture($root);
        assertValidatorFails($root, 'Keine Ticketdateien unter tickets/AI6-*.md gefunden.');
    },
    'verlangt kanonische Frontmatter-Reihenfolge' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, "schema: ai6.ticket.v1\nid: AI6-900", "id: AI6-900\nschema: ai6.ticket.v1");
        assertValidatorFails($root, 'Frontmatter-Schlüssel müssen vollständig und in kanonischer Reihenfolge stehen');
    },
    'lehnt unbekannte Frontmatter-Schlüssel ab' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, "risk: low\nfiles:", "risk: low\npriority: P1\nfiles:");
        assertValidatorFails($root, 'unbekannter Frontmatter-Schlüssel `priority`');
    },
    'bindet ID an den Dateinamen' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, 'id: AI6-900', 'id: AI6-901');
        assertValidatorFails($root, 'stimmt nicht mit dem Dateinamen `AI6-900` überein');
    },
    'bindet Titel und Goal an den aktuellen Blueprint' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, 'title: "Validatortest"', 'title: "Abweichender Titel"');
        replaceInTicket($root, '# AI6-900 — Validatortest', '# AI6-900 — Abweichender Titel');
        replaceInTicket($root, 'Erster Zielabsatz.', 'Abweichendes Ziel.');
        assertValidatorFails($root, '`title` weicht vom aktuellen Blueprint `AI6-900` ab');
        assertValidatorFails($root, 'der erste Goal-Absatz weicht vom Blueprint-Ziel `AI6-900` ab');
    },
    'lehnt unbekannte Abhängigkeiten ab' => static function (string $root): void {
        createTicketFixture($root, ['AI6-899']);
        assertValidatorFails($root, 'unbekannte Abhängigkeit `AI6-899`');
    },
    'erkennt Abhängigkeitszyklen' => static function (string $root): void {
        createTicketFixture($root, ['AI6-901']);
        writeFixtureFile($root . '/tickets/AI6-901.md', validTicket('AI6-901', ['AI6-900'], 'Zweites Ticket'));
        assertValidatorFails($root, 'Abhängigkeitszyklus:');
    },
    'verlangt alle zwölf Abschnitte in Reihenfolge' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, "## Review Focus\n\n- Kanonische Serialisierung.\n\n", '');
        assertValidatorFails($root, 'die zwölf Pflichtabschnitte müssen genau einmal');
    },
    'verlangt genau zwei Goal-Absätze' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, "Erster Zielabsatz.\n\nZweiter Zielabsatz.", 'Nur ein Zielabsatz.');
        assertValidatorFails($root, '`Goal` muss genau zwei nichtleere Absätze enthalten');
    },
    'verlangt lückenlos nummerierte Aufgaben' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, '1. Den Validator prüfen.', '2. Den Validator prüfen.');
        assertValidatorFails($root, 'Aufgaben müssen ab 1 lückenlos nummeriert');
    },
    'lehnt unbekannte Coverage-Nachweise ab' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, '| AC-01 | TC-01 |', '| AC-01 | TC-99 |');
        assertValidatorFails($root, 'nicht deklarierten Nachweis `TC-99`');
    },
    'lehnt doppelte Evidence in einer Coverage-Zeile ab' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, '| AC-01 | TC-01 |', '| AC-01 | TC-01, TC-01 |');
        assertValidatorFails($root, 'enthält doppelte Evidence-IDs');
    },
    'verlangt Coverage für jede AC' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, "| AC-01 | TC-01 |\n", '');
        assertValidatorFails($root, 'jede AC-ID muss genau einmal');
    },
    'bindet files an den erwarteten Scope' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, '- `app/Test.php` — new', '- `app/Other.php` — new');
        assertValidatorFails($root, '`files` und `Expected initial scope` müssen dieselben Pfade');
    },
    'lehnt Pfadtraversal ab' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, '"app/Test.php"', '"../Test.php"');
        replaceInTicket($root, '`app/Test.php`', '`../Test.php`');
        assertValidatorFails($root, 'nichtkanonischer Repositorypfad');
    },
    'verlangt existierende Requirement-IDs' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, 'TKT-001', 'TKT-999');
        assertValidatorFails($root, 'unbekannte Requirement-ID `TKT-999`');
    },
    'verlangt die drei Notes-Boilerplatezeilen' => static function (string $root): void {
        createTicketFixture($root);
        replaceInTicket($root, 'Ticket status, approval and run metadata are owned by AI6.', 'Status wird extern gepflegt.');
        assertValidatorFails($root, 'Notes-Boilerplate Zeile 2 fehlt oder ist verändert');
    },
    'lehnt CRLF-Serialisierung ab' => static function (string $root): void {
        createTicketFixture($root);
        $path = $root . '/tickets/AI6-900.md';
        writeFixtureFile($path, str_replace("\n", "\r\n", readFixtureFile($path)));
        assertValidatorFails($root, 'nur LF-Zeilenenden sind erlaubt');
    },
    'verlangt genau einen abschließenden LF' => static function (string $root): void {
        createTicketFixture($root);
        $path = $root . '/tickets/AI6-900.md';
        writeFixtureFile($path, readFixtureFile($path) . "\n");
        assertValidatorFails($root, 'genau ein abschließender LF-Zeilenumbruch');
    },
];

$failures = [];
foreach ($tests as $name => $test) {
    $root = createTemporaryDirectory();
    try {
        copyValidator($validatorSource, $root);
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

echo "\n" . count($tests) . " Validator-Regressionstests erfolgreich.\n";

function createBaseFixture(string $root): void
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
            '### AI6-900 — Validatortest',
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
            'Erster Zielabsatz.',
            '',
            '**Deliverables**',
            '',
            '- Fixture.',
            '',
            '### AI6-901 — Zweites Ticket',
            '',
            '- **Initialstatus des späteren Detailtickets:** `todo`',
            '- **Risiko:** `low`',
            '- **Kind:** `chore`',
            '- **Depends on:** `AI6-900`',
            '- **Requirement-Refs:** `TKT-001`',
            '- **Erwartete Module:** `Shared`',
            '',
            '**Ziel**',
            '',
            'Erster Zielabsatz.',
            '',
            '**Deliverables**',
            '',
            '- Fixture.',
            '',
            '## 16. Requirement-Traceability',
            '',
        ]),
    );
}

/** @param list<string> $dependencies */
function createTicketFixture(string $root, array $dependencies = [], string $status = 'todo'): void
{
    createBaseFixture($root);
    writeFixtureFile($root . '/tickets/AI6-900.md', validTicket('AI6-900', $dependencies, 'Validatortest', $status));
}

/** @param list<string> $dependencies */
function validTicket(string $id, array $dependencies = [], string $title = 'Validatortest', string $status = 'todo'): string
{
    $dependsOn = $dependencies === [] ? '[]' : '[' . implode(', ', $dependencies) . ']';

    return implode("\n", [
        '---',
        'schema: ai6.ticket.v1',
        "id: $id",
        'title: ' . json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        "status: $status",
        "depends_on: $dependsOn",
        'kind: chore',
        'milestone: M0',
        'risk: low',
        'files:',
        '  - "app/Test.php"',
        'spec_refs:',
        '  - "docs/AI6_IMPLEMENTATION_PLAN.md — TKT-001"',
        '---',
        '',
        "# $id — $title",
        '',
        '## Goal',
        '',
        'Erster Zielabsatz.',
        '',
        'Zweiter Zielabsatz.',
        '',
        '## Context',
        '',
        'Das Fixture bildet das kanonische Ticketformat ab.',
        '',
        '## Tasks',
        '',
        '1. Den Validator prüfen.',
        '',
        '## Acceptance Criteria',
        '',
        '- [ ] **AC-01** Das Ticket wird als gültig erkannt.',
        '',
        '## Test Cases',
        '',
        '- **TC-01** Der Validator endet mit Exitcode 0.',
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
        '- `app/Test.php` — new',
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
        '- Produktivcode außerhalb des Fixtures.',
        '',
        '## Manual and External Gates',
        '',
        'None.',
        '',
        '## Review Focus',
        '',
        '- Kanonische Serialisierung.',
        '',
        '## Notes',
        '',
        '- Definition of Done: `docs/AI6_IMPLEMENTATION_PLAN.md` §12.2.',
        '- Ticket status, approval and run metadata are owned by AI6. The implementation agent never changes them.',
        '- Necessary deviations are reported as `needs_human` or as a scope/contract request, never applied silently.',
        '',
    ]);
}

function replaceInTicket(string $root, string $search, string $replace): void
{
    $path = $root . '/tickets/AI6-900.md';
    $contents = readFixtureFile($path);
    $updated = str_replace($search, $replace, $contents, $count);
    if ($count === 0) {
        throw new RuntimeException("Fixture-Fundstelle nicht gefunden: $search");
    }
    writeFixtureFile($path, $updated);
}

function assertValidatorPasses(string $root): void
{
    $result = runValidator($root);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException("Validator sollte erfolgreich sein:\n" . implode("\n", $result['output']));
    }
}

function assertValidatorFails(string $root, string $fragment): void
{
    $result = runValidator($root);
    if ($result['exit_code'] === 0 || !str_contains(implode("\n", $result['output']), $fragment)) {
        throw new RuntimeException("Erwarteter Fehler fehlt: $fragment\n" . implode("\n", $result['output']));
    }
}

/** @return array{exit_code: int, output: list<string>} */
function runValidator(string $root): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/validate_tickets.php')
        . ' --root=' . escapeshellarg($root) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);

    return ['exit_code' => $exitCode, 'output' => $output];
}

function copyValidator(string $source, string $root): void
{
    $target = $root . '/tools/validate_tickets.php';
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
        throw new RuntimeException('Tool-Verzeichnis konnte nicht erstellt werden.');
    }
    if (!copy($source, $target)) {
        throw new RuntimeException('Validator konnte nicht kopiert werden.');
    }
}

function createTemporaryDirectory(): string
{
    $root = rtrim(sys_get_temp_dir(), "/\\") . '/ai6_validator_' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700, true)) {
        throw new RuntimeException('Temporäres Verzeichnis konnte nicht erstellt werden.');
    }

    return $root;
}

function writeFixtureFile(string $path, string $contents): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
        throw new RuntimeException("Verzeichnis konnte nicht erstellt werden: $path");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Datei konnte nicht geschrieben werden: $path");
    }
}

function readFixtureFile(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Datei konnte nicht gelesen werden: $path");
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
