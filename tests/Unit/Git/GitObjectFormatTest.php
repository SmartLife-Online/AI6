<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlRemoteRefUnresolved;
use App\AI6\Git\GitObjectFormat;
use App\AI6\Git\GitRemoteRefResponse;
use App\AI6\Git\ProjectGitOidRule;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitObjectFormatTest extends TestCase
{
    /** @return iterable<string, array{GitObjectFormat, int, string}> */
    public static function formats(): iterable
    {
        yield 'sha1' => [GitObjectFormat::SHA1, 40, 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391'];
        yield 'sha256' => [GitObjectFormat::SHA256, 64, '473a0f4c3be8a93681a267e3b1e9a7dcda1185436fe141f7749120a303721813'];
    }

    #[DataProvider('formats')]
    public function test_exact_nonzero_identity_and_contextual_zero(GitObjectFormat $format, int $length, string $emptyBlob): void
    {
        self::assertSame($length, $format->length());
        self::assertSame($emptyBlob, $format->objectId('blob', ''));
        self::assertSame($format, GitObjectFormat::fromOid($emptyBlob));
        self::assertTrue($format->validOid($emptyBlob));
        self::assertFalse($format->validOid($format->zeroOid()));
        self::assertTrue($format->validOid($format->zeroOid(), allowZero: true));
        foreach ([null, '', substr($emptyBlob, 1), $emptyBlob.'a', strtoupper($emptyBlob), str_repeat('g', $length), $emptyBlob."\n", str_repeat('a', $length === 40 ? 64 : 40)] as $invalid) {
            self::assertFalse($format->validOid($invalid));
        }
        self::assertSame(['sha1', 'sha256'], array_column(GitObjectFormat::cases(), 'value'));
        self::assertNull(GitObjectFormat::tryFromOid($format->zeroOid()));
    }

    public function test_object_type_is_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GitObjectFormat::SHA1->objectId('unknown', 'content');
    }

    #[DataProvider('formats')]
    public function test_http_rule_uses_only_the_bound_project_format(GitObjectFormat $format, int $length, string $oid): void
    {
        $translator = $this->createStub(Translator::class);
        foreach ([$oid, null, [], 123, '', $format->zeroOid(), strtoupper($oid), $oid."\n", str_repeat('a', $length === 40 ? 64 : 40)] as $value) {
            $errors = [];
            (new ProjectGitOidRule($format))->validate('oid', $value, function (string $message) use (&$errors, $translator): PotentiallyTranslatedString {
                $errors[] = $message;

                return new PotentiallyTranslatedString($message, $translator);
            });
            self::assertCount($value === $oid ? 0 : 1, $errors);
        }
        $errors = [];
        (new ProjectGitOidRule(null))->validate('oid', $oid, function (string $message) use (&$errors, $translator): PotentiallyTranslatedString {
            $errors[] = $message;

            return new PotentiallyTranslatedString($message, $translator);
        });
        self::assertCount(1, $errors);
    }

    #[DataProvider('formats')]
    public function test_remote_ref_parser_consumes_the_whole_single_requested_line(GitObjectFormat $format, int $length, string $oid): void
    {
        $ref = 'refs/heads/main';
        self::assertSame($oid, GitRemoteRefResponse::oid($oid."\t".$ref."\n", $ref));
        self::assertSame($oid, GitRemoteRefResponse::oid($oid."\t".$ref."\r\n", $ref));
        foreach ([
            '', $oid."\t".$ref, $oid."\trefs/heads/other\n", $oid."\trefs/heads/prefix/refs/heads/main\n",
            $oid."\t".$ref."\n".$oid."\t".$ref."\n", $oid."\t".$ref."\nextra", 'extra'.$oid."\t".$ref."\n",
            $format->zeroOid()."\t".$ref."\n", strtoupper($oid)."\t".$ref."\n", substr($oid, 1)."\t".$ref."\n",
        ] as $output) {
            try {
                GitRemoteRefResponse::oid($output, $ref);
                self::fail('A malformed remote response was accepted.');
            } catch (ControlRemoteRefUnresolved) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
