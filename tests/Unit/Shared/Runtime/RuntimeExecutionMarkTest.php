<?php

namespace Tests\Unit\Shared\Runtime;

use App\AI6\Shared\Runtime\RuntimeExecutionMark;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RuntimeExecutionMarkTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/ai6-execution-mark-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_hostile_and_long_keys_stay_inside_the_directory_with_constant_names(): void
    {
        $mark = new RuntimeExecutionMark($this->directory);
        $keys = [
            '../outside',
            '..\\outside',
            "nul\0byte",
            "control\x01value",
            'CON',
            'con',
            'CaseSensitive',
            'casesensitive',
            str_repeat('sehr-lang-', 1024),
        ];

        foreach ($keys as $key) {
            self::assertTrue($mark->claim($key));
        }

        $files = glob($this->directory.'/*');
        self::assertIsArray($files);
        self::assertCount(count($keys), $files);

        foreach ($files as $file) {
            self::assertSame(realpath($this->directory), realpath(dirname($file)));
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', basename($file));
        }
    }

    public function test_nfc_and_nfd_claim_the_same_mark_while_distinct_keys_do_not_collide(): void
    {
        $mark = new RuntimeExecutionMark($this->directory);
        $nfc = "Caf\u{00E9}";
        $nfd = "Cafe\u{0301}";

        self::assertSame($mark->digest($nfc), $mark->digest($nfd));
        self::assertTrue($mark->claim($nfc));
        self::assertFalse($mark->claim($nfd));

        $digests = [];
        for ($index = 0; $index < 256; $index++) {
            $digests[] = $mark->digest('key-'.$index);
        }

        self::assertCount(256, array_unique($digests));
    }

    public function test_concurrent_claims_report_new_exactly_once(): void
    {
        $autoload = dirname(__DIR__, 4).'/vendor/autoload.php';
        $code = <<<'PHP'
require $argv[1];
$mark = new App\AI6\Shared\Runtime\RuntimeExecutionMark($argv[2]);
$key = base64_decode($argv[3], true);
if (! is_string($key)) {
    exit(2);
}
echo $mark->claim($key) ? 'new' : 'existing';
PHP;
        $encodedKey = base64_encode('../parallel/'.str_repeat('x', 2048));
        $processes = [];

        for ($index = 0; $index < 8; $index++) {
            $process = new Process([PHP_BINARY, '-r', $code, $autoload, $this->directory, $encodedKey]);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }

        self::assertCount(1, array_filter($results, static fn (string $result): bool => $result === 'new'));
        self::assertCount(7, array_filter($results, static fn (string $result): bool => $result === 'existing'));
        self::assertCount(1, glob($this->directory.'/*') ?: []);
    }
}
