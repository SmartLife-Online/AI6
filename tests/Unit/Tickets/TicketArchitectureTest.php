<?php

namespace Tests\Unit\Tickets;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TicketArchitectureTest extends TestCase
{
    public function test_yaml_jcs_and_contract_hash_each_have_one_authorized_seam(): void
    {
        $root = dirname(__DIR__, 3);
        $php = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/AI6')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $php[str_replace('\\', '/', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }
        $yamlUsers = array_keys(array_filter($php, static fn ($content): bool => is_string($content)
            && str_contains($content, 'Symfony\\Component\\Yaml')));
        self::assertCount(1, $yamlUsers);
        self::assertStringEndsWith('/app/AI6/Shared/Yaml/RestrictedYaml.php', $yamlUsers[0]);

        $ticketHashes = array_keys(array_filter(
            $php,
            static fn ($content): bool => is_string($content)
                && str_contains($content, 'AI6-TICKET-CONTRACT-V1'),
        ));
        self::assertCount(1, $ticketHashes);
        self::assertStringEndsWith('/app/AI6/Tickets/TicketContractHasher.php', $ticketHashes[0]);
        self::assertStringContainsString('CanonicalJson', $php[$ticketHashes[0]]);
        self::assertStringNotContainsString('json_encode(', $php[$ticketHashes[0]]);

        $command = $php[str_replace('\\', '/', $root.'/app/AI6/Tickets/Console/ReprojectUnparsedTicketsCommand.php')];
        self::assertIsString($command);
        self::assertStringContainsString('ControlOperationRuntimeIdentity', $command);
        self::assertStringContainsString('EffectiveProjectConfiguration', $command);
        self::assertStringNotContainsString("config('ai6.runtime_role')", $command);
        self::assertStringNotContainsString('TicketValidationConfiguration', $command);
    }
}
