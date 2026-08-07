<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ManagedProjectPath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ManagedProjectPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-path-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.DIRECTORY_SEPARATOR.'deploy-keys', 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function test_attempt_and_active_paths_remain_below_the_managed_root(): void
    {
        $paths = $this->paths();
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        $bundle = $paths->prepareBundle(str_repeat('a', 32), $operationId, 1);

        self::assertStringStartsWith(realpath($this->root), $bundle);
        self::assertSame(
            realpath($this->root.DIRECTORY_SEPARATOR.'deploy-keys').DIRECTORY_SEPARATOR.str_repeat('a', 32),
            $paths->activeDirectory(str_repeat('a', 32)),
        );
    }

    public function test_traversal_and_symlinked_managed_roots_are_rejected(): void
    {
        $paths = $this->paths();
        try {
            $paths->prepareAttempt('../escape', '123e4567-e89b-42d3-a456-426614174000', 1);
            self::fail('Traversal was accepted as a project identifier.');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'.control-staging');
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $link = $this->root.'-link';
        if (! @symlink($this->root, $link)) {
            self::markTestSkipped('This Windows runtime cannot create a directory symlink.');
        }

        try {
            $unsafe = new ManagedProjectPath(new ControlOperationConfiguration(
                $link,
                $link.DIRECTORY_SEPARATOR.'deploy-keys',
                '/usr/bin/ssh-keygen',
                '/wrapper',
                120,
                30,
                30,
                3,
            ));
            $this->expectException(RuntimeException::class);
            $unsafe->prepareAttempt(str_repeat('a', 32), '123e4567-e89b-42d3-a456-426614174000', 1);
        } finally {
            @unlink($link);
        }
    }

    public function test_case_variant_operation_identifier_is_rejected_before_creating_a_colliding_path(): void
    {
        $this->expectException(RuntimeException::class);

        $this->paths()->prepareAttempt(
            str_repeat('a', 32),
            '123E4567-E89B-42D3-A456-426614174000',
            1,
        );
    }

    public function test_cleanup_rejects_a_symlinked_managed_root(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This assertion requires POSIX symlink semantics.');
        }

        $link = $this->root.'-link';
        if (! @symlink($this->root, $link)) {
            self::markTestSkipped('This runtime cannot create a directory symlink.');
        }

        try {
            $unsafe = new ManagedProjectPath(new ControlOperationConfiguration(
                $link,
                $link.DIRECTORY_SEPARATOR.'deploy-keys',
                '/usr/bin/ssh-keygen',
                '/wrapper',
                120,
                30,
                30,
                3,
            ));
            $this->expectException(RuntimeException::class);
            $unsafe->removeOwnedAttempt(str_repeat('a', 32), '123e4567-e89b-42d3-a456-426614174000', 1);
        } finally {
            @unlink($link);
        }
    }

    public function test_cleanup_rejects_a_symlinked_operation_parent_without_touching_its_target(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This assertion requires POSIX symlink semantics.');
        }

        $outside = $this->root.'-outside';
        self::assertTrue(mkdir($outside, 0700));
        self::assertNotFalse(file_put_contents($outside.'/keep', 'unchanged'));
        $staging = $this->root.'/.control-staging';
        self::assertTrue(mkdir($staging, 0700));
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        if (! @symlink($outside, $staging.'/'.$operationId)) {
            self::markTestSkipped('This runtime cannot create a directory symlink.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->paths()->removeOwnedAttempt(str_repeat('a', 32), $operationId, 1);
        } finally {
            self::assertSame('unchanged', file_get_contents($outside.'/keep'));
            @unlink($staging.'/'.$operationId);
            @unlink($outside.'/keep');
            @rmdir($outside);
        }
    }

    private function paths(): ManagedProjectPath
    {
        return new ManagedProjectPath(new ControlOperationConfiguration(
            $this->root,
            $this->root.DIRECTORY_SEPARATOR.'deploy-keys',
            '/usr/bin/ssh-keygen',
            '/wrapper',
            120,
            30,
            30,
            3,
        ));
    }

    private function remove(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.DIRECTORY_SEPARATOR.$entry);
            }
        }
        @rmdir($path);
    }
}
