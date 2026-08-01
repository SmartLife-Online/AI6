<?php

declare(strict_types=1);

const DEFAULT_PLAN_PATH = __DIR__.'/../docs/AI6_IMPLEMENTATION_PLAN.md';
const DEFAULT_OUTPUT_PATH = __DIR__.'/../docs/AI6_TICKET_MANIFEST.yaml';

[$check, $planPath, $outputPath] = parseArguments(array_slice(commandLineArguments(), 1));
$manifest = generateManifest($planPath);

if ($check) {
    $existing = @file_get_contents($outputPath);

    if ($existing === false || $existing !== $manifest) {
        fwrite(STDERR, "Ticket manifest drift detected. Run php scripts/generate-ticket-manifest.php.\n");
        exit(1);
    }

    fwrite(STDOUT, "Ticket manifest is current.\n");
    exit(0);
}

if (file_put_contents($outputPath, $manifest, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write ticket manifest: {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, "Wrote {$outputPath}.\n");

/** @return list<string> */
function commandLineArguments(): array
{
    $arguments = $_SERVER['argv'] ?? [];

    if (! is_array($arguments)) {
        return [];
    }

    return array_values(array_filter($arguments, is_string(...)));
}

/**
 * @param  list<string>  $arguments
 * @return array{bool, string, string}
 */
function parseArguments(array $arguments): array
{
    $check = false;
    $planPath = DEFAULT_PLAN_PATH;
    $outputPath = DEFAULT_OUTPUT_PATH;

    foreach ($arguments as $argument) {
        if ($argument === '--check') {
            $check = true;
        } elseif (str_starts_with($argument, '--plan=')) {
            $planPath = substr($argument, strlen('--plan='));
        } elseif (str_starts_with($argument, '--output=')) {
            $outputPath = substr($argument, strlen('--output='));
        } else {
            fwrite(STDERR, "Unknown argument: {$argument}\n");
            exit(2);
        }
    }

    return [$check, $planPath, $outputPath];
}

function generateManifest(string $planPath): string
{
    $plan = @file_get_contents($planPath);

    if ($plan === false) {
        throw new RuntimeException("Unable to read implementation plan: {$planPath}");
    }

    $plan = str_replace(["\r\n", "\r"], "\n", $plan);

    if (preg_match('/^\*\*Revision (V\d+\.\d+\.\d+):\*\*/m', $plan, $revisionMatch) !== 1) {
        throw new RuntimeException('Implementation plan revision not found.');
    }

    $sectionStart = strpos($plan, "## 15. Ticket-Blueprints\n");
    $sectionEnd = strpos($plan, "\n## 16. ", $sectionStart === false ? 0 : $sectionStart);

    if ($sectionStart === false || $sectionEnd === false) {
        throw new RuntimeException('Ticket blueprint section boundaries not found.');
    }

    $lines = explode("\n", substr($plan, $sectionStart, $sectionEnd - $sectionStart));
    $milestone = null;
    $blueprints = [];

    for ($index = 0, $count = count($lines); $index < $count; $index++) {
        if (preg_match('/^## 15\.\d+ (M[0-7]) /u', $lines[$index], $milestoneMatch) === 1) {
            $milestone = $milestoneMatch[1];

            continue;
        }

        if (preg_match('/^### (AI6-\d+[A-Z]?) — (.+)$/u', $lines[$index], $headingMatch) !== 1) {
            continue;
        }

        if ($milestone === null) {
            throw new RuntimeException("Blueprint {$headingMatch[1]} has no milestone.");
        }

        $metadata = [];
        for ($offset = 1; $offset <= 10 && count($metadata) < 5; $offset++) {
            $line = $lines[$index + $offset] ?? '';
            if (preg_match('/^- \*\*(.+):\*\* (.+)$/u', $line, $metadataMatch) === 1) {
                $metadata[$metadataMatch[1]] = $metadataMatch[2];
            }
        }

        if (count($metadata) !== 5) {
            throw new RuntimeException("Incomplete metadata for blueprint {$headingMatch[1]}.");
        }

        $blueprints[] = [
            'id' => $headingMatch[1],
            'title' => $headingMatch[2],
            'milestone' => $milestone,
            'initial_status' => unwrapCode($metadata['Initialstatus des späteren Detailtickets'] ?? ''),
            'risk' => unwrapCode($metadata['Risiko'] ?? ''),
            'kind' => unwrapCode($metadata['Kind'] ?? ''),
            'depends_on' => parseCodeList($metadata['Depends on'] ?? ''),
            'requirement_refs' => parseCodeList($metadata['Requirement-Refs'] ?? ''),
        ];
    }

    if (count($blueprints) !== 44) {
        throw new RuntimeException('Expected 44 ticket blueprints, found '.count($blueprints).'.');
    }

    $output = [
        'schema: ai6.ticket-manifest.v1',
        'source: docs/AI6_IMPLEMENTATION_PLAN.md',
        'plan_revision: '.yamlString($revisionMatch[1]),
        'blueprint_count: '.count($blueprints),
        'blueprints:',
    ];

    foreach ($blueprints as $blueprint) {
        $output[] = '  - id: '.yamlString($blueprint['id']);
        $output[] = '    title: '.yamlString($blueprint['title']);
        $output[] = '    milestone: '.yamlString($blueprint['milestone']);
        $output[] = '    initial_status: '.yamlString($blueprint['initial_status']);
        $output[] = '    risk: '.yamlString($blueprint['risk']);
        $output[] = '    kind: '.yamlString($blueprint['kind']);
        $output[] = '    depends_on: '.yamlInlineList($blueprint['depends_on']);
        $output[] = '    requirement_refs: '.yamlInlineList($blueprint['requirement_refs']);
    }

    return implode("\n", $output)."\n";
}

function unwrapCode(string $value): string
{
    if (preg_match('/^`([^`]+)`$/u', $value, $match) !== 1) {
        throw new RuntimeException("Expected one code value, got: {$value}");
    }

    return $match[1];
}

/** @return list<string> */
function parseCodeList(string $value): array
{
    if ($value === 'keine') {
        return [];
    }

    if (preg_match_all('/`([^`]+)`/u', $value, $matches) === 0) {
        throw new RuntimeException("Expected a code value list, got: {$value}");
    }

    return $matches[1];
}

function yamlString(string $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** @param list<string> $values */
function yamlInlineList(array $values): string
{
    return '['.implode(', ', array_map(yamlString(...), $values)).']';
}
