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

    public function test_traversal_is_rejected_before_creating_a_staging_directory(): void
    {
        $paths = $this->paths();

        try {
            $paths->prepareAttempt('../escape', '123e4567-e89b-42d3-a456-426614174000', 1);
            self::fail('Traversal was accepted as a project identifier.');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'.control-staging');
        }
    }

    public function test_symlinked_managed_root_is_rejected(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This assertion requires POSIX directory-symlink semantics.');
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
                $link.DIRECTORY_SEPARATOR.'known_hosts',
                ['refs/heads/main'],
                300,
                8,
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

    public function test_case_variant_project_identifier_is_rejected_before_creating_a_colliding_path(): void
    {
        $this->expectException(RuntimeException::class);

        $this->paths()->prepareAttempt(
            str_repeat('A', 32),
            '123e4567-e89b-42d3-a456-426614174000',
            1,
        );
    }

    public function test_operation_cleanup_removes_every_attempt_including_pre_intent_residue(): void
    {
        $paths = $this->paths();
        $projectIdentifier = str_repeat('a', 32);
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        $first = $paths->prepareBundle($projectIdentifier, $operationId, 1);
        $second = $paths->prepareBundle($projectIdentifier, $operationId, 2);
        self::assertNotFalse(file_put_contents($first.DIRECTORY_SEPARATOR.'orphan', 'first'));
        self::assertNotFalse(file_put_contents($second.DIRECTORY_SEPARATOR.'orphan', 'second'));

        $paths->removeOwnedOperation($projectIdentifier, $operationId);

        self::assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'.control-staging'.DIRECTORY_SEPARATOR.$operationId);
        self::assertDirectoryExists($this->root.DIRECTORY_SEPARATOR.'deploy-keys');
    }

    public function test_managed_repository_and_attempt_ref_paths_are_canonical_and_attempt_bound(): void
    {
        $paths = $this->paths();
        $projectIdentifier = str_repeat('a', 32);
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        $staged = $paths->stagedRepository($projectIdentifier, $operationId, 7);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($this->root)).'/.control-staging/'.$operationId.'/7/repository',
            str_replace('\\', '/', $staged),
        );
        self::assertSame(
            'refs/ai6/attempts/'.$operationId.'/7/control',
            ManagedProjectPath::attemptRef($operationId, 7),
        );
        self::assertSame(
            str_replace('\\', '/', (string) realpath($this->root)).'/projects/'.$projectIdentifier.'/repository',
            str_replace('\\', '/', $paths->repositoryDirectory($projectIdentifier)),
        );
    }

    public function test_repository_lookup_does_not_create_project_directories(): void
    {
        $projectIdentifier = str_repeat('a', 32);
        $repository = $this->paths()->repositoryDirectory($projectIdentifier);

        self::assertSame(
            str_replace('\\', '/', (string) realpath($this->root)).'/projects/'.$projectIdentifier.'/repository',
            str_replace('\\', '/', $repository),
        );
        self::assertDirectoryDoesNotExist($this->root.DIRECTORY_SEPARATOR.'projects');
    }

    public function test_staged_repository_publish_preserves_the_previous_repository_as_owned_recovery_material(): void
    {
        $paths = $this->paths();
        $projectIdentifier = str_repeat('a', 32);
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        $active = $paths->repositoryDirectory($projectIdentifier);
        self::assertTrue(mkdir(dirname($active), 0700, true));
        self::assertTrue(mkdir($active, 0700));
        $this->createRepositoryMetadata($active);
        self::assertNotFalse(file_put_contents($active.'/old', 'old'));
        $staged = $paths->stagedRepository($projectIdentifier, $operationId, 1);
        self::assertTrue(mkdir($staged, 0700));
        $this->createRepositoryMetadata($staged);
        self::assertNotFalse(file_put_contents($staged.'/new', 'new'));

        $published = $paths->publishStagedRepository($projectIdentifier, $operationId, 1);
        self::assertSame(realpath($active), $published);
        self::assertFileExists($active.'/new');
        self::assertFileDoesNotExist($active.'/old');
        self::assertFileExists(dirname($staged).'/previous-repository/old');

        $paths->removeOwnedOperation($projectIdentifier, $operationId);
        self::assertFileExists($active.'/new');
        self::assertDirectoryDoesNotExist($this->root.'/.control-staging/'.$operationId);
    }

    public function test_interrupted_publish_with_backup_and_no_active_repository_resumes_idempotently(): void
    {
        $paths = $this->paths();
        $projectIdentifier = str_repeat('a', 32);
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        $active = $paths->repositoryDirectory($projectIdentifier);
        self::assertTrue(mkdir(dirname($active), 0700, true));
        self::assertTrue(mkdir($active, 0700));
        $this->createRepositoryMetadata($active);
        self::assertNotFalse(file_put_contents($active.'/old', 'old'));
        $staged = $paths->stagedRepository($projectIdentifier, $operationId, 1);
        self::assertTrue(mkdir($staged, 0700));
        $this->createRepositoryMetadata($staged);
        self::assertNotFalse(file_put_contents($staged.'/new', 'new'));
        $backup = dirname($staged).'/previous-repository';
        self::assertTrue(rename($active, $backup));

        $published = $paths->publishStagedRepository($projectIdentifier, $operationId, 1);
        self::assertSame(realpath($active), $published);
        self::assertFileExists($active.'/new');
        self::assertFileExists($backup.'/old');
    }

    public function test_repository_metadata_symlinks_are_rejected(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This assertion requires POSIX file-symlink semantics.');
        }

        $repository = $this->paths()->repositoryDirectory(str_repeat('a', 32));
        self::assertTrue(mkdir(dirname($repository), 0700, true));
        self::assertTrue(mkdir($repository, 0700));
        self::assertTrue(mkdir($repository.'/objects', 0700));
        self::assertTrue(mkdir($repository.'/refs', 0700));
        $outside = $this->root.'/outside-head';
        self::assertNotFalse(file_put_contents($outside, "ref: refs/heads/main\n"));
        if (! @symlink($outside, $repository.'/HEAD')) {
            self::markTestSkipped('This runtime cannot create a file symlink.');
        }

        $this->expectException(RuntimeException::class);
        $this->paths()->assertRepository($repository);
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
                $link.DIRECTORY_SEPARATOR.'known_hosts',
                ['refs/heads/main'],
                300,
                8,
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

    public function test_operation_cleanup_unlinks_a_nested_symlink_without_touching_its_target(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This assertion requires POSIX symlink semantics.');
        }

        $outside = $this->root.'-outside';
        self::assertTrue(mkdir($outside, 0700));
        self::assertNotFalse(file_put_contents($outside.'/keep', 'unchanged'));
        $projectIdentifier = str_repeat('a', 32);
        $operationId = '123e4567-e89b-42d3-a456-426614174000';
        $bundle = $this->paths()->prepareBundle($projectIdentifier, $operationId, 1);
        if (! @symlink($outside, $bundle.'/outside-link')) {
            self::markTestSkipped('This runtime cannot create a directory symlink.');
        }

        try {
            $this->paths()->removeOwnedOperation($projectIdentifier, $operationId);
            self::assertSame('unchanged', file_get_contents($outside.'/keep'));
            self::assertDirectoryDoesNotExist($this->root.'/.control-staging/'.$operationId);
        } finally {
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
            $this->root.DIRECTORY_SEPARATOR.'known_hosts',
            ['refs/heads/main'],
            300,
            8,
        ));
    }

    private function createRepositoryMetadata(string $repository): void
    {
        self::assertTrue(mkdir($repository.'/.git', 0700));
        self::assertTrue(mkdir($repository.'/.git/objects', 0700));
        self::assertTrue(mkdir($repository.'/.git/refs', 0700));
        self::assertNotFalse(file_put_contents($repository.'/.git/HEAD', "ref: refs/heads/main\n"));
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
