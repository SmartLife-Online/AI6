<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ManagedProjectPath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ControlOperationPathTest extends TestCase
{
    public function test_invalid_project_operation_and_attempt_identifiers_are_rejected_before_path_creation(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-control-path-'.bin2hex(random_bytes(6));
        mkdir($root, 0700);
        mkdir($root.DIRECTORY_SEPARATOR.'keys', 0700);
        $paths = new ManagedProjectPath(new ControlOperationConfiguration(
            $root,
            $root.DIRECTORY_SEPARATOR.'keys',
            '/usr/bin/ssh-keygen',
            '/wrapper',
            120,
            30,
            30,
            3,
            $root.DIRECTORY_SEPARATOR.'known_hosts',
            ['refs/heads/main'],
            300,
            8,
            'tickets',
        ));

        foreach ([
            ['../project', '123e4567-e89b-42d3-a456-426614174000', 1],
            [str_repeat('a', 32), '../operation', 1],
            [str_repeat('a', 32), '123e4567-e89b-42d3-a456-426614174000', 0],
        ] as [$project, $operation, $attempt]) {
            try {
                $paths->prepareAttempt($project, $operation, $attempt);
                self::fail('An invalid managed-path component was accepted.');
            } catch (RuntimeException) {
                self::assertDirectoryDoesNotExist($root.DIRECTORY_SEPARATOR.'.control-staging');
            }
        }

        rmdir($root.DIRECTORY_SEPARATOR.'keys');
        rmdir($root);
    }
}
