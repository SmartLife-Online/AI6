<?php

namespace Tests\Unit\Shared\Process;

use App\AI6\Shared\Process\ExecutionMailbox;
use App\AI6\Shared\Process\ExecutionResultPublisher;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Process\MailboxRejectedException;
use App\AI6\Shared\Process\MailboxRejection;
use App\AI6\Shared\Process\ProcessLimit;
use App\AI6\Shared\Process\ProcessLimitResult;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\TestCase;

final class ExecutionMailboxTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ai6-mailbox-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function test_an_atomic_envelope_is_verified_and_consumed_exactly_once(): void
    {
        $mailbox = $this->mailbox(ExecutionRole::AGENT);
        $path = $mailbox->write(MailboxMessageType::RESULT, 'slot-1', 'delivery-1', '{"ok":true}');
        self::assertFileExists($path);
        self::assertStringNotContainsString('.tmp', basename($path));

        $message = $mailbox->read(MailboxMessageType::RESULT, 'slot-1', 'delivery-1', $this->context());
        self::assertSame('{"ok":true}', $message->content);
        self::assertSame(hash('sha256', $message->content), $message->contentHash);

        try {
            $mailbox->read(MailboxMessageType::RESULT, 'slot-1', 'delivery-1', $this->context());
            self::fail('A replay must be rejected.');
        } catch (MailboxRejectedException $exception) {
            self::assertSame(MailboxRejection::REPLAY, $exception->reason);
        }
    }

    public function test_a_failed_write_removes_its_staging_file_and_preserves_the_failure(): void
    {
        $temporary = $this->root.'/.failed-write.tmp';
        file_put_contents($temporary, 'partial envelope');
        $original = new \RuntimeException('The original write failure.');
        $cleanup = new \ReflectionMethod(ExecutionMailbox::class, 'cleanupFailedWrite');

        try {
            $cleanup->invoke($this->mailbox(ExecutionRole::AGENT), $temporary, $original);
            self::fail('A write failure must be rethrown after cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertSame($original, $exception);
        }

        self::assertFileDoesNotExist($temporary);
    }

    public function test_tampered_foreign_and_oversized_envelopes_are_named_rejections(): void
    {
        $mailbox = $this->mailbox(ExecutionRole::AGENT);
        $path = $mailbox->write(MailboxMessageType::REQUEST, 'slot-1', 'tampered', 'payload');
        $document = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $document['content_sha256'] = str_repeat('0', 64);
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR)."\n");
        $this->assertRejection($mailbox, MailboxRejection::HASH_MISMATCH, 'slot-1', 'tampered');

        $foreign = $mailbox->write(MailboxMessageType::REQUEST, 'slot-1', 'foreign', 'payload');
        $document = json_decode((string) file_get_contents($foreign), true, 8, JSON_THROW_ON_ERROR);
        $document['role'] = 'checker';
        file_put_contents($foreign, json_encode($document, JSON_THROW_ON_ERROR)."\n");
        $this->assertRejection($mailbox, MailboxRejection::FOREIGN_ROLE, 'slot-1', 'foreign');

        $small = new ExecutionMailbox(ExecutionRole::AGENT, $this->root, 1, 32, $this->redactor());
        $this->expectException(MailboxRejectedException::class);
        $small->write(MailboxMessageType::REQUEST, 'slot-1', 'large', str_repeat('x', 64));
    }

    public function test_mailbox_modes_ignore_a_restrictive_umask_and_allow_group_handoff(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX mailbox mode evidence requires POSIX.');
        }
        mkdir($this->root.'/input', 0700);
        mkdir($this->root.'/output', 0700);
        $mailbox = new ExecutionMailbox(ExecutionRole::AGENT, $this->root.'/input', 1, 4096, $this->redactor(), $this->root.'/output');

        $previous = umask(0077);
        try {
            $request = $mailbox->write(MailboxMessageType::REQUEST, 'slot-1', 'request-mode', 'request');
            $result = $mailbox->write(MailboxMessageType::RESULT, 'slot-1', 'result-mode', 'result');
        } finally {
            umask($previous);
        }

        self::assertSame('750', substr(sprintf('%o', fileperms(dirname($request))), -3));
        self::assertSame('1730', substr(sprintf('%o', fileperms(dirname($result))), -4));
        self::assertSame('640', substr(sprintf('%o', fileperms($request)), -3));
        self::assertSame('640', substr(sprintf('%o', fileperms($result)), -3));
    }

    public function test_incomplete_size_mismatched_and_foreign_slot_envelopes_are_named_rejections(): void
    {
        $mailbox = $this->mailbox(ExecutionRole::CHECKER);
        $this->assertRejection($mailbox, MailboxRejection::INCOMPLETE, 'slot-1', 'missing');

        $size = $mailbox->write(MailboxMessageType::REQUEST, 'slot-1', 'size', 'payload');
        $document = json_decode((string) file_get_contents($size), true, 8, JSON_THROW_ON_ERROR);
        $document['size']++;
        file_put_contents($size, json_encode($document, JSON_THROW_ON_ERROR)."\n");
        $this->assertRejection($mailbox, MailboxRejection::SIZE_MISMATCH, 'slot-1', 'size');

        $foreign = $mailbox->write(MailboxMessageType::REQUEST, 'slot-1', 'slot', 'payload');
        $document = json_decode((string) file_get_contents($foreign), true, 8, JSON_THROW_ON_ERROR);
        $document['slot_id'] = 'slot-2';
        file_put_contents($foreign, json_encode($document, JSON_THROW_ON_ERROR)."\n");
        $this->assertRejection($mailbox, MailboxRejection::FOREIGN_SLOT, 'slot-1', 'slot');
    }

    public function test_a_limit_result_is_never_published_as_a_partial_result(): void
    {
        $mailbox = $this->mailbox(ExecutionRole::AGENT);
        $result = new ProcessResult(
            ProcessOutcome::RESOURCE_LIMIT_EXCEEDED,
            1,
            'partial',
            '',
            0.1,
            new ProcessLimitResult(ProcessLimit::FILE_COUNT, 2, 1),
        );

        self::assertNull((new ExecutionResultPublisher)->publish($mailbox, 'slot-1', 'limited', $result));
        self::assertFileDoesNotExist($this->root.'/results/limited.json');
    }

    public function test_request_and_result_may_share_a_delivery_identifier_without_false_replay(): void
    {
        $mailbox = $this->mailbox(ExecutionRole::AGENT);
        $mailbox->write(MailboxMessageType::REQUEST, 'slot-1', 'same-delivery', 'request');
        self::assertSame('request', $mailbox->read(MailboxMessageType::REQUEST, 'slot-1', 'same-delivery', $this->context())->content);
        $mailbox->write(MailboxMessageType::RESULT, 'slot-1', 'same-delivery', 'result');
        self::assertSame('result', $mailbox->read(MailboxMessageType::RESULT, 'slot-1', 'same-delivery', $this->context())->content);
    }

    public function test_returned_content_has_its_own_redacted_size_and_hash_binding(): void
    {
        $mailbox = $this->mailbox(ExecutionRole::AGENT);
        $raw = 'token=ghp_1234567890';
        $mailbox->write(MailboxMessageType::RESULT, 'slot-1', 'redacted', $raw);
        $message = $mailbox->read(MailboxMessageType::RESULT, 'slot-1', 'redacted', $this->context());

        self::assertNotSame($raw, $message->content);
        self::assertSame(strlen($message->content), $message->size);
        self::assertSame(hash('sha256', $message->content), $message->contentHash);
        self::assertSame(strlen($raw), $message->envelopeSize);
        self::assertSame(hash('sha256', $raw), $message->envelopeContentHash);
    }

    public function test_agent_and_checker_mailboxes_use_mutually_separate_roots(): void
    {
        foreach (['agent-input', 'agent-output', 'checker-input', 'checker-output'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700);
        }
        $agent = new ExecutionMailbox(ExecutionRole::AGENT, $this->root.'/agent-input', 1, 4096, $this->redactor(), $this->root.'/agent-output');
        $checker = new ExecutionMailbox(ExecutionRole::CHECKER, $this->root.'/checker-input', 1, 4096, $this->redactor(), $this->root.'/checker-output');
        $agent->write(MailboxMessageType::REQUEST, 'slot-1', 'agent-only', 'payload');
        $checker->write(MailboxMessageType::REQUEST, 'slot-2', 'checker-only', 'payload');

        $this->assertRejection($checker, MailboxRejection::INCOMPLETE, 'slot-1', 'agent-only');
        $this->assertRejection($agent, MailboxRejection::INCOMPLETE, 'slot-2', 'checker-only');
    }

    private function assertRejection(ExecutionMailbox $mailbox, MailboxRejection $reason, string $slot, string $delivery): void
    {
        try {
            $mailbox->read(MailboxMessageType::REQUEST, $slot, $delivery, $this->context());
            self::fail('The invalid envelope must be rejected.');
        } catch (MailboxRejectedException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    private function mailbox(ExecutionRole $role): ExecutionMailbox
    {
        return new ExecutionMailbox($role, $this->root, 1, 4096, $this->redactor());
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('project-1', 'run-1', 'mailbox-test');
    }

    private function redactor(): Redactor
    {
        return new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator(new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('k', 32)]])),
        );
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
