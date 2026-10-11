<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\NativeSessionProjection;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NativeSessionProjectionTest extends TestCase
{
    private string $root;

    private string $source;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/ai6-native-session-'.bin2hex(random_bytes(8));
        $relative = '/execution-'.str_repeat('a', 32).'/slot-review-'.str_repeat('b', 16);
        $this->target = $this->root.'/inputs'.$relative.'/home/session-state';
        $this->source = $this->root.'/outputs'.$relative.'/result/copilot-invocation/result/copilot-session-state';
        mkdir($this->target, 0700, true);
        mkdir($this->source, 0700, true);
        mkdir(dirname(dirname($this->source)).'/artifacts', 0700);
        config(['ai6.execution_mailboxes.agent_root' => $this->root.'/inputs',
            'ai6.execution_mailboxes.agent_output_root' => $this->root.'/outputs']);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_native_history_is_disposable_and_the_original_output_is_preserved(): void
    {
        $session = $this->projection();
        self::assertMatchesRegularExpression('/\A[0-9]+:[0-9]+\z/', $session->assertBinding());
        file_put_contents($this->source.'/session.jsonl', 'private native history');
        $original = dirname(dirname(dirname($this->source)));
        file_put_contents($original.'/answer.txt', 'public answer');
        $session->cleanup();
        self::assertDirectoryDoesNotExist(dirname(dirname($this->source)));
        self::assertSame('public answer', file_get_contents($original.'/answer.txt'));
        self::assertSame(['.', '..'], scandir($this->target));
    }

    #[DataProvider('invalidBindings')]
    public function test_foreign_bindings_are_rejected_without_cleanup(string $field, string $value): void
    {
        $values = [$this->source, $this->target, str_repeat('c', 64), str_repeat('d', 64), 'quality_review'];
        $index = ['source' => 0, 'target' => 1, 'context' => 2, 'runtime' => 3, 'role' => 4][$field];
        $values[$index] = $value;
        $session = new NativeSessionProjection(...$values);
        $this->expectException(ProcessStartRejectedException::class);
        $session->assertBinding();
    }

    /** @return list<array{string, string}> */
    public static function invalidBindings(): array
    {
        return [['source', '/tmp/foreign'], ['target', '/tmp/foreign'], ['context', 'invalid'],
            ['runtime', 'invalid'], ['role', 'implementation'], ['role', 'finding_verification']];
    }

    public function test_existing_native_state_at_the_target_cannot_be_resumed(): void
    {
        file_put_contents($this->target.'/foreign.jsonl', 'foreign');
        $this->expectException(ProcessStartRejectedException::class);
        $this->projection()->assertBinding();
    }

    public function test_runtime_version_and_closed_permission_are_both_required(): void
    {
        foreach ([[1, 'ephemeral_review', false], [2, 'ephemeral_review', true], [2, 'anything', false], [3, 'ephemeral_review', false]] as [$version, $permission, $expected]) {
            $runtime = new ProviderRuntimeProfile('github-copilot-cli-v1', $version, [], ['native_session_state' => $permission], [], str_repeat('a', 64));
            self::assertSame($expected, NativeSessionProjection::enabled($runtime));
        }
    }

    public function test_partial_projection_creation_is_cleaned_without_requiring_a_valid_session_mount(): void
    {
        rmdir($this->source);
        file_put_contents(dirname($this->source).'/partial.tmp', 'partial setup');
        $this->projection()->cleanup();
        self::assertDirectoryDoesNotExist(dirname(dirname($this->source)));
        self::assertDirectoryExists(dirname(dirname(dirname($this->source))));
        self::assertDirectoryExists($this->target);
    }

    private function projection(): NativeSessionProjection
    {
        return new NativeSessionProjection($this->source, $this->target, str_repeat('c', 64), str_repeat('d', 64), 'quality_review');
    }
}
