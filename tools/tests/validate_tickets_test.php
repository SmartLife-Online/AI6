#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Abhängigkeitsfreie Regressionstests für tools/validate_tickets.php.
 *
 * Jeder Fall arbeitet auf einer eigenen temporären Kopie der Tickets. Der echte Arbeitsbaum wird
 * nur gelesen und nach dem Lauf zusätzlich per Inhalts-Hash auf unveränderten Zustand geprüft.
 */

$repositoryRoot = realpath(dirname(__DIR__, 2));
if ($repositoryRoot === false) {
    fwrite(STDERR, "Repository-Root konnte nicht bestimmt werden.\n");
    exit(1);
}

$workingTreeHash = fixture_source_hash($repositoryRoot);
$temporaryRoot = create_temporary_directory('smartlife-ticket-validator-');
$baseFixture = $temporaryRoot.'/base';
$passed = 0;

try {
    copy_fixture_source($repositoryRoot, $baseFixture);

    run_case('grüner Gesamtbestand und topologische Kerngarantien', $baseFixture, static function (string $fixture): void {
        $result = run_validator($fixture);
        assert_same(0, $result['exit'], $result['output']);
        $expectedTickets = count(glob($fixture.'/tickets/M*.md') ?: []);
        assert_contains('Tickets gefunden: '.$expectedTickets, $result['output']);
        assert_before($result['output'], 'M03', 'M05');
        assert_before($result['output'], 'M10', 'M11');
        assert_before($result['output'], 'M46', 'M49');
        assert_before($result['output'], 'M51', 'M21');
    });
    $passed++;

    run_case('Root-Argument als getrennte Argumente', $baseFixture, static function (string $fixture): void {
        $result = run_validator($fixture, true);
        assert_same(0, $result['exit'], $result['output']);
    });
    $passed++;

    run_case('unbekanntes Kommandozeilenargument', $baseFixture, static function (string $fixture): void {
        $result = run_validator($fixture, false, ['--falsch']);
        assert_true($result['exit'] !== 0, 'Unbekanntes Argument hätte abgelehnt werden müssen.');
        assert_contains("Unbekanntes Argument '--falsch'", $result['output']);
    });
    $passed++;

    run_case('unbekanntes Dependency-Ziel', $baseFixture, static function (string $fixture): void {
        set_dependencies($fixture, 'M01', ['M999']);
        assert_validator_fails($fixture, "unbekanntes Ticket 'M999'");
    });
    $passed++;

    run_case('doppelte Ticket-ID', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M02.md', '/^id:\s*M02$/m', 'id: M01');
        assert_validator_fails($fixture, "doppelte Ticket-ID 'M01'");
    });
    $passed++;

    run_case('Dateinamens-/ID-Abweichung', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^id:\s*M01$/m', 'id: M99');
        assert_validator_fails($fixture, "Dateiname erwartet id 'M01', YAML enthält 'M99'");
    });
    $passed++;

    run_case('ungültige YAML-ID', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^id:\s*M01$/m', 'id: ungueltig');
        assert_validator_fails($fixture, "YAML-Feld 'id' fehlt oder ist ungültig");
    });
    $passed++;

    run_case('fehlender YAML-Block', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^```yaml\R.*?^```\R/ms', '');
        assert_validator_fails($fixture, 'erwartet genau einen YAML-Metadatenblock, gefunden: 0');
    });
    $passed++;

    run_case('zweiter YAML-Block', $baseFixture, static function (string $fixture): void {
        append_file($fixture.'/tickets/M01.md', "\n```yaml\nid: M999\n```\n");
        assert_validator_fails($fixture, 'erwartet genau einen YAML-Metadatenblock, gefunden: 2');
    });
    $passed++;

    run_case('doppeltes YAML-Feld', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^status:\s*in_progress$/m', "status: in_progress\nstatus: todo");
        assert_validator_fails($fixture, "doppeltes YAML-Feld 'status'");
    });
    $passed++;

    run_case('fremdes YAML-Feld', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^status:\s*in_progress$/m', "status: in_progress\nspec_refs: []");
        assert_validator_fails($fixture, "unbekanntes YAML-Feld 'spec_refs'");
    });
    $passed++;

    run_case('fehlendes Pflichtfeld', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^titel:.*\R/m', '');
        assert_validator_fails($fixture, 'Pflichtfeld fehlt: titel');
    });
    $passed++;

    run_case('Listenfeld als Skalar', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^depends_on:\s*\[\]$/m', 'depends_on: M03');
        assert_validator_fails($fixture, "Feld 'depends_on' muss eine Liste sein");
    });
    $passed++;

    run_case('ungültiger Status', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^status:\s*in_progress$/m', 'status: fertig');
        assert_validator_fails($fixture, "ungültiger status 'fertig'");
    });
    $passed++;

    run_case('ungültige Priorität', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^prio:\s*P0$/m', 'prio: P9');
        assert_validator_fails($fixture, "ungültige prio 'P9'");
    });
    $passed++;

    run_case('ungültige Phase', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^phase:\s*"0"$/m', 'phase: "eins"');
        assert_validator_fails($fixture, "ungültige phase 'eins'");
    });
    $passed++;

    run_case('reserved erfordert P3', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^status:\s*in_progress$/m', 'status: reserved');
        assert_validator_fails($fixture, "status 'reserved' erfordert prio 'P3'");
    });
    $passed++;

    run_case('doppelte Dependency', $baseFixture, static function (string $fixture): void {
        set_dependencies($fixture, 'M11', ['M04', 'M10', 'M10']);
        assert_validator_fails($fixture, 'depends_on enthält Duplikate');
    });
    $passed++;

    run_case('Selbstabhängigkeit', $baseFixture, static function (string $fixture): void {
        set_dependencies($fixture, 'M01', ['M01']);
        assert_validator_fails($fixture, 'Ticket hängt von sich selbst ab');
    });
    $passed++;

    run_case('echter Zyklus mit geschlossenem Zykluspfad', $baseFixture, static function (string $fixture): void {
        set_dependencies($fixture, 'M03', ['M05']);
        assert_validator_fails($fixture, 'M03 -> M05 -> M03');
    });
    $passed++;

    run_case('Abhängigkeit aus späterer Phase', $baseFixture, static function (string $fixture): void {
        set_dependencies($fixture, 'M01', ['M22']);
        assert_validator_fails($fixture, 'Phase 0 darf nicht von M22 aus der späteren Phase 1.5 abhängen');
    });
    $passed++;

    $titleMutations = [
        'abweichender Kurztitel' => '**M31 — Abweichender Titel** · P1 · Phase 1.8',
        'abweichende Prio' => '**M31 — Veraltete jQuery-Registrierungsseiten stilllegen** · P2 · Phase 1.8',
        'abweichende Phase' => '**M31 — Veraltete jQuery-Registrierungsseiten stilllegen** · P1 · Phase 1.9',
        'Mittelpunkt im Kurztitel' => '**M31 — jQuery · Registrierung** · P1 · Phase 1.8',
    ];
    foreach ($titleMutations as $label => $titleLine) {
        run_case("Payload-Titelzeile: $label", $baseFixture, static function (string $fixture) use ($titleLine): void {
            replace_first_line($fixture.'/tickets/M31.md', $titleLine);
            assert_validator_fails($fixture, 'Titelzeile');
        });
        $passed++;
    }

    run_case('Inhalt vor Payload-Titelzeile', $baseFixture, static function (string $fixture): void {
        prepend_file($fixture.'/tickets/M01.md', "Vorspann\n");
        assert_validator_fails($fixture, 'muss die erste nichtleere Zeile sein');
    });
    $passed++;

    run_case('fehlender Pflichtabschnitt', $baseFixture, static function (string $fixture): void {
        replace_regex($fixture.'/tickets/M01.md', '/^## Ziel$/m', '## Absicht');
        assert_validator_fails($fixture, "Abschnitt '## Ziel' muss genau einmal vorhanden sein, gefunden: 0");
    });
    $passed++;

    run_case('doppelter Pflichtabschnitt', $baseFixture, static function (string $fixture): void {
        append_file($fixture.'/tickets/M01.md', "\n## Hinweise\n\nDoppelt.\n");
        assert_validator_fails($fixture, "Abschnitt '## Hinweise' muss genau einmal vorhanden sein, gefunden: 2");
    });
    $passed++;

    run_case('leere files-Liste', $baseFixture, static function (string $fixture): void {
        set_files($fixture, 'M01', []);
        assert_validator_fails($fixture, 'files-Liste darf nicht leer sein');
    });
    $passed++;

    run_case('doppelter files-Pfad', $baseFixture, static function (string $fixture): void {
        set_files($fixture, 'M01', ['backend/example.php', 'backend/example.php']);
        assert_validator_fails($fixture, 'files enthält Duplikate');
    });
    $passed++;

    $unsafePaths = [
        'Traversal' => '../ausserhalb.php',
        'absoluter Pfad' => '/etc/passwd',
        'URI' => 'https://example.test/datei.php',
        'Backslash' => 'backend\\datei.php',
        'leeres Segment' => 'backend//datei.php',
    ];
    foreach ($unsafePaths as $label => $path) {
        run_case("unsicherer files-Pfad: $label", $baseFixture, static function (string $fixture) use ($path): void {
            set_files($fixture, 'M01', [$path]);
            assert_validator_fails($fixture, 'files');
        });
        $passed++;
    }

    run_case('ungeordnete exakte Scope-Überlappung', $baseFixture, static function (string $fixture): void {
        set_files($fixture, 'M01', ['.github/workflows/security-tests.yml']);
        assert_validator_fails($fixture, 'M01 und M03: ungeordnete Scope-Überlappung');
    });
    $passed++;

    run_case('ungeordnete Verzeichnis-Scope-Überlappung', $baseFixture, static function (string $fixture): void {
        set_files($fixture, 'M01', ['tests/security/']);
        assert_validator_fails($fixture, "'tests/security/' und 'tests/security/bootstrap.php'");
    });
    $passed++;

    run_case('additive .gitignore-Ausnahme ohne Dependency', $baseFixture, static function (string $fixture): void {
        set_files($fixture, 'M01', ['.gitignore']);
        set_files($fixture, 'M03', ['.gitignore']);
        $result = run_validator($fixture);
        assert_same(0, $result['exit'], $result['output']);
    });
    $passed++;

    run_case('künftiges Ticket und neue numerische Phase werden dynamisch erkannt', $baseFixture, static function (string $fixture): void {
        $ticket = read_file($fixture.'/tickets/M55.md');
        $ticket = str_replace('M55', 'M999', $ticket);
        write_file($fixture.'/tickets/M999.md', $ticket);
        replace_regex($fixture.'/tickets/M999.md', '/Phase 2/', 'Phase 4.2');
        replace_regex($fixture.'/tickets/M999.md', '/^phase:\s*"2"$/m', 'phase: "4.2"');
        set_dependencies($fixture, 'M999', ['M55']);
        set_files($fixture, 'M999', ['future/M999.php']);

        $result = run_validator($fixture);
        assert_same(0, $result['exit'], $result['output']);
        assert_contains('Tickets gefunden: 56', $result['output']);
        assert_before($result['output'], 'M55', 'M999');
    });
    $passed++;

    run_case('ungültig benannte M-Datei wird entdeckt', $baseFixture, static function (string $fixture): void {
        copy_file($fixture.'/tickets/M01.md', $fixture.'/tickets/Mfalsch.md');
        assert_validator_fails($fixture, 'Dateiname muss M<ID>.md mit mindestens zwei Ziffern entsprechen');
    });
    $passed++;

    assert_same($workingTreeHash, fixture_source_hash($repositoryRoot), 'Der echte Arbeitsbaum wurde verändert.');
    echo "OK: $passed Validator-Regressionsfälle bestanden; der Arbeitsbaum blieb unverändert.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FEHLER: '.$exception->getMessage().PHP_EOL);
    exit(1);
} finally {
    remove_tree($temporaryRoot);
}

exit(0);

function run_case(string $label, string $baseFixture, callable $test): void
{
    static $number = 0;
    $number++;
    $fixture = dirname($baseFixture).'/case-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    copy_tree($baseFixture, $fixture);

    try {
        $test($fixture);
        echo "PASS: $label\n";
    } finally {
        remove_tree($fixture);
    }
}

/** @return array{exit: int, output: string} */
function run_validator(string $fixture, bool $separateRootArgument = false, array $extraArguments = []): array
{
    $rootArguments = $separateRootArgument ? ['--root', $fixture] : ['--root='.$fixture];
    $command = array_merge([PHP_BINARY, $fixture.'/tools/validate_tickets.php'], $rootArguments, $extraArguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Validator-Prozess konnte nicht gestartet werden.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit' => $exit,
        'output' => (string) $stdout.(string) $stderr,
    ];
}

function assert_validator_fails(string $fixture, string $messageFragment): void
{
    $result = run_validator($fixture);
    assert_true($result['exit'] !== 0, 'Validator hätte fehlschlagen müssen. Ausgabe: '.$result['output']);
    assert_contains($messageFragment, $result['output']);
}

function copy_fixture_source(string $sourceRoot, string $fixtureRoot): void
{
    create_directory($fixtureRoot.'/tickets');
    create_directory($fixtureRoot.'/tools');

    foreach (glob($sourceRoot.'/tickets/M*.md') ?: [] as $file) {
        copy_file($file, $fixtureRoot.'/tickets/'.basename($file));
    }
    copy_file($sourceRoot.'/tools/validate_tickets.php', $fixtureRoot.'/tools/validate_tickets.php');
}

function copy_tree(string $source, string $target): void
{
    create_directory($target);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destination = $target.'/'.$relative;
        if ($item->isDir()) {
            create_directory($destination);
        } else {
            copy_file($item->getPathname(), $destination);
        }
    }
}

function copy_file(string $source, string $target): void
{
    create_directory(dirname($target));
    if (!copy($source, $target)) {
        throw new RuntimeException("Kopieren fehlgeschlagen: $source");
    }
}

function create_temporary_directory(string $prefix): string
{
    $path = rtrim(sys_get_temp_dir(), "/\\").'/'.$prefix.bin2hex(random_bytes(8));
    create_directory($path);

    return $path;
}

function create_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Verzeichnis konnte nicht angelegt werden: $path");
    }
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function set_dependencies(string $fixture, string $id, array $dependencies): void
{
    $value = '['.implode(', ', $dependencies).']';
    replace_regex($fixture.'/tickets/'.$id.'.md', '/^depends_on:\s*\[[^\]]*\]$/m', 'depends_on: '.$value);
}

function set_files(string $fixture, string $id, array $files): void
{
    $replacement = 'files:';
    if ($files === []) {
        $replacement .= ' []'."\n";
    } else {
        $replacement .= "\n";
        foreach ($files as $file) {
            $replacement .= '  - '.$file."\n";
        }
    }

    replace_regex(
        $fixture.'/tickets/'.$id.'.md',
        '/^files:\s*(?:\[\])?\R(?:  -[^\r\n]*\R)*/m',
        $replacement,
    );
}

function replace_regex(string $file, string $pattern, string $replacement): void
{
    $contents = read_file($file);
    $updated = preg_replace($pattern, $replacement, $contents, 1, $count);
    if ($updated === null || $count !== 1) {
        throw new RuntimeException("Regex-Ersetzung in $file erwartete genau einen Treffer, gefunden: $count.");
    }
    write_file($file, $updated);
}

function replace_first_line(string $file, string $replacement): void
{
    replace_regex($file, '/\A[^\r\n]*/', $replacement);
}

function prepend_file(string $file, string $prefix): void
{
    write_file($file, $prefix.read_file($file));
}

function append_file(string $file, string $suffix): void
{
    write_file($file, read_file($file).$suffix);
}

function read_file(string $file): string
{
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException("Datei konnte nicht gelesen werden: $file");
    }

    return $contents;
}

function write_file(string $file, string $contents): void
{
    if (file_put_contents($file, $contents) === false) {
        throw new RuntimeException("Datei konnte nicht geschrieben werden: $file");
    }
}

function fixture_source_hash(string $root): string
{
    $files = array_merge(
        glob($root.'/tickets/M*.md') ?: [],
        [$root.'/tools/validate_tickets.php', $root.'/tools/tests/validate_tickets_test.php'],
    );
    sort($files);
    $context = hash_init('sha256');

    foreach ($files as $file) {
        hash_update($context, str_replace('\\', '/', substr($file, strlen($root)))."\0");
        hash_update_file($context, $file);
    }

    return hash_final($context);
}

function assert_contains(string $needle, string $haystack): void
{
    assert_true(str_contains($haystack, $needle), "Erwarteter Text fehlt: $needle\nAusgabe:\n$haystack");
}

function assert_before(string $haystack, string $first, string $second): void
{
    $firstPosition = strpos($haystack, $first);
    $secondPosition = strpos($haystack, $second);
    assert_true(
        $firstPosition !== false && $secondPosition !== false && $firstPosition < $secondPosition,
        "Erwartete Reihenfolge fehlt: $first vor $second.",
    );
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Werte sind nicht identisch.');
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
