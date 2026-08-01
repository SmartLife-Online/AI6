<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_only_the_public_status(): void
    {
        $sessionDirectory = sys_get_temp_dir().'/ai6-health-sessions-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($sessionDirectory));
        $migrationsBefore = $this->migrationSnapshot();
        $this->assertNotSame([], $migrationsBefore);
        config([
            'session.driver' => 'file',
            'session.files' => $sessionDirectory,
        ]);

        try {
            $response = $this->get('/health');

            $response->assertStatus(200);
            $response->assertHeader('content-type', 'application/json');
            $response->assertHeaderMissing('set-cookie');
            $this->assertSame('{"status":"ok"}', $response->getContent());
            $response->assertExactJson(['status' => 'ok']);
            $this->assertSame(['.', '..'], scandir($sessionDirectory));
            $this->assertFileDoesNotExist(database_path('database.sqlite'));
            $this->assertSame($migrationsBefore, $this->migrationSnapshot());
        } finally {
            rmdir($sessionDirectory);
        }
    }

    /** @return array<string, string> */
    private function migrationSnapshot(): array
    {
        $snapshot = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $migration) {
            $digest = hash_file('sha256', $migration);
            $this->assertIsString($digest);
            $snapshot[basename($migration)] = $digest;
        }

        ksort($snapshot);

        return $snapshot;
    }
}
