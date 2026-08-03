<?php

namespace Tests\Unit\Shared\Redaction;

use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRule;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function test_default_rules_replace_secret_token_credential_and_path_matches_with_stable_markers(): void
    {
        $text = 'password=hunter2 Bearer abcdefghijk sk-abcdefgh ';
        $text .= 'https://alice:s3cret@example.test ';
        $text .= 'C:\\Users\\Michael\\private\\notes.txt /home/michael/private/notes.txt';
        $result = $this->redactor()->redact($text, $this->context());

        self::assertSame(
            'password=[REDACTED:SECRET] Bearer [REDACTED:TOKEN] [REDACTED:TOKEN] '.
            'https://[REDACTED:CREDENTIAL]@example.test '.
            '[REDACTED:PATH] [REDACTED:PATH]',
            $result->text,
        );
        self::assertSame([
            'secret',
            'token',
            'token',
            'credential',
            'sensitive_path',
            'sensitive_path',
        ], array_map(static fn ($match): string => $match->type->value, $result->matches));
        self::assertSame(['log'], array_values(array_unique(array_map(
            static fn ($match): string => $match->field,
            $result->matches,
        ))));
    }

    public function test_text_without_matches_is_unchanged(): void
    {
        $result = $this->redactor()->redact('Öffentlicher Text ohne vertrauliche Werte.', $this->context());

        self::assertSame('Öffentlicher Text ohne vertrauliche Werte.', $result->text);
        self::assertSame([], $result->matches);
    }

    public function test_invalid_utf8_is_rejected_before_any_redaction_output_is_returned(): void
    {
        $invalidInput = "password=private-value\xC3\x28";

        try {
            $this->redactor()->redact($invalidInput, $this->context());
            self::fail('Invalid UTF-8 must fail closed.');
        } catch (InvalidRedactionInputException $exception) {
            self::assertSame('Redaction input must be valid UTF-8.', $exception->getMessage());
            self::assertStringNotContainsString($invalidInput, $exception->getMessage());
        }
    }

    public function test_structured_quoted_and_named_values_are_redacted_atomically(): void
    {
        $text = '{"password":"dummy-secret-value","access_token":"dummy token value"} ';
        $text .= "password='dummy secret suffix' auth_token=dummy-token-value";
        $result = $this->redactor()->redact($text, $this->context());

        self::assertSame(
            '{"password":"[REDACTED:SECRET]","access_token":"[REDACTED:TOKEN]"} '.
            "password='[REDACTED:SECRET]' auth_token=[REDACTED:TOKEN]",
            $result->text,
        );
        self::assertSame(
            ['secret', 'token', 'secret', 'token'],
            array_map(static fn ($match): string => $match->type->value, $result->matches),
        );
        self::assertStringNotContainsString('dummy', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_untrusted_marker_prefixes_cannot_suppress_redaction(): void
    {
        $text = 'password=[REDACTED:SECRET]dummy-hidden-value ';
        $text .= 'Bearer [REDACTED:TOKEN]dummy-hidden-value ';
        $text .= 'https://[REDACTED:CREDENTIAL]dummy@example.test';
        $first = $this->redactor()->redact($text, $this->context());
        $second = $this->redactor()->redact($first->text, $this->context());

        self::assertSame(
            'password=[REDACTED:SECRET] Bearer [REDACTED:TOKEN] '.
            'https://[REDACTED:CREDENTIAL]@example.test',
            $first->text,
        );
        self::assertCount(3, $first->matches);
        self::assertSame($first->text, $second->text);
        self::assertSame([], $second->matches);
    }

    public function test_sensitive_paths_with_spaces_and_unicode_are_fully_redacted(): void
    {
        $text = 'C:\\Users\\Míchael\\Private Folder\\secret.txt, ';
        $text .= '/home/míchäel/Private Folder/secret.txt';
        $result = $this->redactor()->redact($text, $this->context());

        self::assertSame('[REDACTED:PATH], [REDACTED:PATH]', $result->text);
        self::assertCount(2, $result->matches);
    }

    public function test_unquoted_paths_end_before_prose_and_do_not_hide_a_following_secret_match(): void
    {
        $text = 'ERROR in C:\\Users\\Michael\\app.php line 41 and ';
        $text .= '/home/michael/app.php line 42 while running tests; ';
        $text .= '/home/michael/x.txt password=hunter2';
        $result = $this->redactor()->redact($text, $this->context());

        self::assertSame(
            'ERROR in [REDACTED:PATH] line 41 and [REDACTED:PATH] line 42 while running tests; '.
            '[REDACTED:PATH] password=[REDACTED:SECRET]',
            $result->text,
        );
        self::assertSame(
            ['sensitive_path', 'sensitive_path', 'sensitive_path', 'secret'],
            array_map(static fn ($match): string => $match->type->value, $result->matches),
        );
    }

    public function test_second_application_is_byte_identical_and_has_no_additional_match(): void
    {
        $first = $this->redactor()->redact('password=private-value', $this->context());
        $second = $this->redactor()->redact($first->text, $this->context());

        self::assertSame($first->text, $second->text);
        self::assertSame([], $second->matches);
    }

    public function test_result_and_serialized_log_shape_never_contain_plaintext_or_unkeyed_digest(): void
    {
        $secret = 'private-value-for-result';
        $result = $this->redactor()->redact('password='.$secret, $this->context());
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($secret, $serialized);
        self::assertStringNotContainsString(hash('sha256', $secret), $serialized);
        self::assertSame('[REDACTED:SECRET]', $result->matches[0]->marker);
        self::assertSame(64, strlen($result->matches[0]->fingerprint));
    }

    public function test_overlap_resolution_is_registration_order_independent_and_prefers_longest_then_priority(): void
    {
        $short = new RedactionRule('short', RedactionMatchType::SECRET, '~abc~', 20);
        $long = new RedactionRule('long', RedactionMatchType::TOKEN, '~abcdef~', 30);
        $priorityWinner = new RedactionRule('priority-winner', RedactionMatchType::CREDENTIAL, '~xyz~', 10);
        $priorityLoser = new RedactionRule('priority-loser', RedactionMatchType::SECRET, '~xyz~', 40);
        $first = $this->redactor([$short, $long, $priorityLoser, $priorityWinner]);
        $second = $this->redactor([$priorityWinner, $long, $short, $priorityLoser]);

        $firstResult = $first->redact('abcdef xyz', $this->context());
        $secondResult = $second->redact('abcdef xyz', $this->context());

        self::assertSame('[REDACTED:TOKEN] [REDACTED:CREDENTIAL]', $firstResult->text);
        self::assertSame($firstResult->text, $secondResult->text);
        self::assertSame(
            array_map(static fn ($match): array => $match->jsonSerialize(), $firstResult->matches),
            array_map(static fn ($match): array => $match->jsonSerialize(), $secondResult->matches),
        );
    }

    /** @param list<RedactionRule>|null $rules */
    private function redactor(?array $rules = null): Redactor
    {
        $keyring = new RedactionKeyring('test-v1', [
            'test-v1' => ['version' => 1, 'key' => str_repeat("\x02", 32)],
        ]);

        return new Redactor(
            new RedactionPolicy($rules ?? RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator($keyring),
        );
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('project-a', 'run-a', 'log');
    }
}
