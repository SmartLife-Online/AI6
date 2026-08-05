<?php

namespace Tests\Unit\Projects;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ProjectRegistrationArchitectureTest extends TestCase
{
    public function test_registration_code_has_no_process_network_or_repository_read_path(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [
            ...$this->phpFiles($root.'/app/AI6/Projects'),
            $root.'/app/AI6/Git/GitConfigurationFactory.php',
            $root.'/app/AI6/Git/GitRemotePolicy.php',
            $root.'/app/AI6/Git/HostKeyFingerprint.php',
            $root.'/routes/web.php',
            ...$this->phpFiles($root.'/resources/views/projects'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:exec|shell_exec|system|passthru|proc_open|popen|fsockopen|stream_socket_client|curl_exec)\s*\(/',
                $source,
                $file,
            );
            self::assertStringNotContainsString('App\\AI6\\Shared\\Process', $source, $file);
            self::assertStringNotContainsString('Symfony\\Component\\Process', $source, $file);
            self::assertStringNotContainsString('Illuminate\\Support\\Facades\\Process', $source, $file);
            self::assertStringNotContainsString('Illuminate\\Support\\Facades\\Http', $source, $file);
            self::assertStringNotContainsString('GuzzleHttp', $source, $file);
        }

        $controller = file_get_contents($root.'/app/AI6/Projects/Http/ProjectController.php');
        self::assertIsString($controller);
        self::assertStringNotContainsString('App\\AI6\\Git', $controller);
        self::assertStringNotContainsString('repository_path', $controller);
        self::assertStringNotContainsString('managed_path', $controller);
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
