<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Redaction\RedactionKeyringFactory;
use App\AI6\Shared\Security\SecurityMeasure;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class DoctorCommandTest extends TestCase
{
    private string $checkerRuntime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkerRuntime = sys_get_temp_dir().'/ai6-doctor-'.bin2hex(random_bytes(6));
        mkdir($this->checkerRuntime.'/input', 0700, true);
        mkdir($this->checkerRuntime.'/output/attestations', 0700, true);
        config([
            'ai6.execution_mailboxes.checker_root' => $this->checkerRuntime.'/input',
            'ai6.execution_mailboxes.checker_output_root' => $this->checkerRuntime.'/output',
        ]);
        $this->writeAttestation();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->checkerRuntime, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->checkerRuntime);
        parent::tearDown();
    }

    public function test_strict_doctor_reports_complete_policy_and_keyring_without_secret_values(): void
    {
        $secret = str_repeat('private-key-material-', 2);
        config([
            'ai6.redaction.active_key_id' => 'doctor-v3',
            'ai6.redaction.keys' => [
                'doctor-v3' => [
                    'version' => 3,
                    'key' => 'base64:'.base64_encode($secret),
                ],
            ],
        ]);

        $exitCode = Artisan::call('ai6:doctor');
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('SecurityPolicy: OK', $output);
        self::assertStringContainsString('Profil: strict', $output);
        self::assertMatchesRegularExpression('/Policyhash: [0-9a-f]{64}/', $output);

        foreach (SecurityMeasure::cases() as $measure) {
            self::assertStringContainsString('Maßnahme '.$measure->value.': aktiv', $output);
        }

        self::assertStringContainsString('Reduktionsbestätigung: nicht bestätigt', $output);
        self::assertStringContainsString('Redaction-Schlüsselring: OK', $output);
        self::assertStringContainsString('Aktive Key-ID: doctor-v3', $output);
        self::assertStringContainsString('Schlüsselquelle: expliziter Schlüsselring', $output);
        self::assertStringNotContainsString($secret, $output);
        self::assertStringNotContainsString(base64_encode($secret), $output);
    }

    public function test_doctor_marks_the_application_key_fallback_as_local_and_not_rotation_stable(): void
    {
        config([
            'ai6.redaction.environment' => 'local',
            'ai6.redaction.active_key_id' => 'app-key-v1',
            'ai6.redaction.keys' => '',
            'ai6.redaction.app_key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);

        $exitCode = Artisan::call('ai6:doctor');
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString(
            'Schlüsselquelle: APP_KEY-Fallback (nur lokal; nicht rotationsstabil)',
            $output,
        );
    }

    public function test_doctor_rejects_the_application_key_fallback_in_production(): void
    {
        $privateAppKey = 'base64:'.base64_encode(str_repeat('p', 32));
        config([
            'ai6.redaction.environment' => 'production',
            'ai6.redaction.active_key_id' => 'app-key-v1',
            'ai6.redaction.keys' => '',
            'ai6.redaction.app_key' => $privateAppKey,
        ]);

        $exitCode = Artisan::call('ai6:doctor');
        $output = Artisan::output();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Redaction-Schlüsselring: FEHLER', $output);
        self::assertStringNotContainsString('APP_KEY-Fallback', $output);
        self::assertStringNotContainsString($privateAppKey, $output);
    }

    public function test_doctor_returns_failure_for_invalid_keyring_without_echoing_raw_configuration(): void
    {
        $privateValue = 'private-invalid-keyring-value';
        config([
            'ai6.redaction.active_key_id' => 'missing-v1',
            'ai6.redaction.keys' => $privateValue,
        ]);

        $exitCode = Artisan::call('ai6:doctor');
        $output = Artisan::output();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Redaction-Schlüsselring: FEHLER', $output);
        self::assertStringContainsString('Zustand: ungültig oder unvollständig', $output);
        self::assertStringNotContainsString($privateValue, $output);
    }

    public function test_keyring_configuration_errors_do_not_capture_raw_keys_in_exception_frames(): void
    {
        $privateKey = 'private-short-key-material';
        config([
            'ai6.redaction.active_key_id' => 'configured-v1',
            'ai6.redaction.keys' => [
                'configured-v1' => [
                    'version' => 1,
                    'key' => 'base64:'.base64_encode($privateKey),
                ],
            ],
        ]);
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            try {
                app(RedactionKeyringFactory::class)->fromConfiguredValues();
                self::fail('Expected ConfigurationException.');
            } catch (ConfigurationException $exception) {
                $encodedKey = base64_encode($privateKey);
                $containsPrivateKey = function (mixed $value) use (&$containsPrivateKey, $privateKey, $encodedKey): bool {
                    if (is_string($value)) {
                        return str_contains($value, $privateKey) || str_contains($value, $encodedKey);
                    }

                    if (is_array($value)) {
                        foreach ($value as $entry) {
                            if ($containsPrivateKey($entry)) {
                                return true;
                            }
                        }
                    }

                    return false;
                };

                self::assertFalse($containsPrivateKey($exception->getTrace()));
                self::assertStringNotContainsString($privateKey, $exception->getMessage());
            }
        } finally {
            ini_set('zend.exception_ignore_args', is_string($previous) ? $previous : '1');
        }
    }

    private function writeAttestation(): void
    {
        $document = [
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => str_repeat('a', 32),
            'recorded_at' => time(), 'role' => 'checker', 'input_read_only' => true,
            'output_separate' => true, 'workspace_private' => true, 'container_read_only' => true,
            'network_isolated' => true, 'namespace_tooling' => true, 'profiles_executable' => true,
            'profile_programs' => ['php-targeted' => true],
        ];
        file_put_contents($this->checkerRuntime.'/output/attestations/checker.json', json_encode($document, JSON_THROW_ON_ERROR)."\n");
    }
}
