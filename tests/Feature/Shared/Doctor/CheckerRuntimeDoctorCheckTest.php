<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Shared\Doctor\CheckerRuntimeDoctorCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CheckerRuntimeDoctorCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ai6-checker-doctor-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/input', 0700, true);
        mkdir($this->root.'/output/attestations', 0700, true);
        config([
            'ai6.execution_mailboxes.checker_root' => $this->root.'/input',
            'ai6.execution_mailboxes.checker_output_root' => $this->root.'/output',
            'ai6.checks.runtime.attestation_max_age_seconds' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_missing_and_stale_attestations_fail_with_named_details(): void
    {
        $check = new CheckerRuntimeDoctorCheck;
        self::assertSame(['Fehler' => 'checker_attestation_missing'], $check->run()->details);

        $this->writeAttestation(time() - 16);
        self::assertSame(['Fehler' => 'checker_attestation_stale_or_invalid'], $check->run()->details);
    }

    public function test_a_fresh_complete_attestation_passes(): void
    {
        $this->writeAttestation(time());
        $result = (new CheckerRuntimeDoctorCheck)->run();
        self::assertTrue($result->passed);
        self::assertSame('frisch und vollständig', $result->details['Status']);
    }

    public function test_an_unavailable_checker_root_is_named(): void
    {
        config(['ai6.execution_mailboxes.checker_root' => $this->root.'/missing']);

        self::assertSame(
            ['Fehler' => 'checker_root_unavailable'],
            (new CheckerRuntimeDoctorCheck)->run()->details,
        );
    }

    public function test_an_unavailable_profile_program_is_named_without_stopping_attestation_publication(): void
    {
        $this->writeAttestation(time(), ['profile_programs' => ['php-targeted' => false]]);

        self::assertSame(
            ['Fehler' => 'checker_attestation_profile_program_unavailable'],
            (new CheckerRuntimeDoctorCheck)->run()->details,
        );
    }

    #[DataProvider('runtimePromises')]
    public function test_each_false_runtime_promise_has_its_own_detail(string $promise): void
    {
        $this->writeAttestation(time(), [$promise => false]);

        self::assertSame(
            ['Fehler' => 'checker_attestation_'.$promise],
            (new CheckerRuntimeDoctorCheck)->run()->details,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function runtimePromises(): iterable
    {
        foreach (['input_read_only', 'output_separate', 'workspace_private', 'container_read_only', 'network_isolated', 'namespace_tooling', 'profiles_executable'] as $promise) {
            yield $promise => [$promise];
        }
    }

    /** @param array<string, mixed> $overrides */
    private function writeAttestation(int $recordedAt, array $overrides = []): void
    {
        $document = [
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => str_repeat('b', 32),
            'recorded_at' => $recordedAt, 'role' => 'checker', 'input_read_only' => true,
            'output_separate' => true, 'workspace_private' => true, 'container_read_only' => true,
            'network_isolated' => true, 'namespace_tooling' => true, 'profiles_executable' => true,
            'profile_programs' => ['php-targeted' => true],
        ];
        file_put_contents($this->root.'/output/attestations/checker.json', json_encode(array_replace($document, $overrides), JSON_THROW_ON_ERROR)."\n");
    }
}
