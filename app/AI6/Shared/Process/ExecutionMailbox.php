<?php

namespace App\AI6\Shared\Process;

use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use JsonException;

final readonly class ExecutionMailbox
{
    private string $outputRoot;

    public function __construct(
        public ExecutionRole $role,
        private string $root,
        private int $version,
        private int $maxEnvelopeBytes,
        private Redactor $redactor,
        ?string $outputRoot = null,
    ) {
        $this->outputRoot = $outputRoot ?? $root;
        if ($maxEnvelopeBytes < 1 || $version < 1 || ! is_dir($root) || is_link($root)
            || ! is_dir($this->outputRoot) || is_link($this->outputRoot)) {
            throw new \InvalidArgumentException('The execution mailbox configuration is invalid.');
        }
    }

    public function write(MailboxMessageType $type, string $slotId, string $deliveryId, string $content): string
    {
        $this->assertIdentifier($slotId);
        $this->assertIdentifier($deliveryId);
        $directory = $this->directory($type);
        $this->ensureDirectory($directory, $type === MailboxMessageType::REQUEST ? 0750 : 01730);

        $document = [
            'version' => $this->version,
            'role' => $this->role->value,
            'type' => $type->value,
            'slot_id' => $slotId,
            'delivery_id' => $deliveryId,
            'size' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'content_base64' => base64_encode($content),
        ];
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (strlen($bytes) > $this->maxEnvelopeBytes) {
            throw new MailboxRejectedException(MailboxRejection::TOO_LARGE);
        }

        $target = $directory.'/'.$deliveryId.'.json';
        if (file_exists($target)) {
            throw new MailboxRejectedException(MailboxRejection::REPLAY);
        }
        $temporary = $directory.'/.'.$deliveryId.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('The execution envelope staging file could not be created.');
        }

        try {
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                    throw new \RuntimeException('The execution envelope could not be written completely.');
                }
            } finally {
                fclose($handle);
            }
        } catch (\Throwable $writeFailure) {
            $this->cleanupFailedWrite($temporary, $writeFailure);
        }
        if (! chmod($temporary, 0640)) {
            unlink($temporary);
            throw new \RuntimeException('The execution envelope permissions could not be applied.');
        }

        if (! link($temporary, $target)) {
            unlink($temporary);
            throw new \RuntimeException('The execution envelope could not be published atomically.');
        }
        unlink($temporary);

        return $target;
    }

    public function read(MailboxMessageType $type, string $slotId, string $deliveryId, RedactionContext $context): MailboxMessage
    {
        $this->assertIdentifier($slotId);
        $this->assertIdentifier($deliveryId);
        $path = $this->directory($type).'/'.$deliveryId.'.json';
        if (! is_file($path) || is_link($path)) {
            throw new MailboxRejectedException(MailboxRejection::INCOMPLETE);
        }

        $length = filesize($path);
        if (! is_int($length) || $length < 1) {
            throw new MailboxRejectedException(MailboxRejection::INCOMPLETE);
        }
        if ($length > $this->maxEnvelopeBytes) {
            throw new MailboxRejectedException(MailboxRejection::TOO_LARGE);
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) !== $length) {
            throw new MailboxRejectedException(MailboxRejection::INCOMPLETE);
        }

        try {
            $safeBytes = $this->redactor->redact($bytes, $context)->text;
            $document = json_decode($safeBytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (InvalidRedactionInputException|JsonException) {
            throw new MailboxRejectedException(MailboxRejection::INVALID_ENCODING);
        }

        $keys = ['version', 'role', 'type', 'slot_id', 'delivery_id', 'size', 'content_sha256', 'content_base64'];
        if (! is_array($document) || array_keys($document) !== $keys) {
            throw new MailboxRejectedException(MailboxRejection::INCOMPLETE);
        }
        if ($document['version'] !== $this->version || $document['type'] !== $type->value) {
            throw new MailboxRejectedException(MailboxRejection::INVALID_VERSION);
        }
        if ($document['role'] !== $this->role->value) {
            throw new MailboxRejectedException(MailboxRejection::FOREIGN_ROLE);
        }
        if ($document['slot_id'] !== $slotId || $document['delivery_id'] !== $deliveryId) {
            throw new MailboxRejectedException(MailboxRejection::FOREIGN_SLOT);
        }

        $content = is_string($document['content_base64']) ? base64_decode($document['content_base64'], true) : false;
        if (! is_string($content)) {
            throw new MailboxRejectedException(MailboxRejection::INVALID_ENCODING);
        }
        if (! is_int($document['size']) || strlen($content) !== $document['size']) {
            throw new MailboxRejectedException(MailboxRejection::SIZE_MISMATCH);
        }
        if (! is_string($document['content_sha256']) || ! hash_equals($document['content_sha256'], hash('sha256', $content))) {
            throw new MailboxRejectedException(MailboxRejection::HASH_MISMATCH);
        }

        try {
            $content = $this->redactor->redact($content, $context)->text;
        } catch (InvalidRedactionInputException) {
            throw new MailboxRejectedException(MailboxRejection::INVALID_ENCODING);
        }

        $this->consumeOnce($type, $deliveryId);

        return new MailboxMessage(
            $this->version,
            $this->role,
            $type,
            $slotId,
            $deliveryId,
            strlen($content),
            hash('sha256', $content),
            $content,
            $document['size'],
            $document['content_sha256'],
        );
    }

    private function consumeOnce(MailboxMessageType $type, string $deliveryId): void
    {
        $directory = $this->outputRoot.'/consumed';
        $this->ensureDirectory($directory, 01730);
        $marker = $directory.'/'.hash('sha256', $type->value."\0".$deliveryId);
        if (file_exists($marker)) {
            throw new MailboxRejectedException(MailboxRejection::REPLAY);
        }
        $handle = @fopen($marker, 'x+b');
        if ($handle === false) {
            throw new MailboxRejectedException(MailboxRejection::REPLAY);
        }
        fclose($handle);
    }

    private function directory(MailboxMessageType $type): string
    {
        $root = $type === MailboxMessageType::REQUEST ? $this->root : $this->outputRoot;

        return $root.'/'.$type->value.'s';
    }

    private function ensureDirectory(string $directory, int $mode): void
    {
        $created = false;
        if (! is_dir($directory)) {
            $created = mkdir($directory, $mode, true);
        }
        if (! is_dir($directory)) {
            throw new \RuntimeException('The execution mailbox directory could not be created.');
        }
        if ($created && ! chmod($directory, $mode)) {
            throw new \RuntimeException('The execution mailbox directory permissions could not be applied.');
        }
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $identifier) !== 1) {
            throw new \InvalidArgumentException('A mailbox identifier is invalid.');
        }
    }

    private function cleanupFailedWrite(string $temporary, \Throwable $writeFailure): never
    {
        if (is_file($temporary) && ! $this->tryFilesystemOperation(static fn (): bool => unlink($temporary))) {
            throw new \RuntimeException('The execution envelope write failed and its staging file could not be removed.', previous: $writeFailure);
        }

        throw $writeFailure;
    }

    /** @param callable(): bool $operation */
    private function tryFilesystemOperation(callable $operation): bool
    {
        try {
            return $operation();
        } catch (\Throwable) {
            return false;
        }
    }
}
