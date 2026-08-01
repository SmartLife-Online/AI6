<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_only_the_public_status(): void
    {
        $sessionDirectory = sys_get_temp_dir().'/ai6-health-sessions-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($sessionDirectory));
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
            $this->assertDirectoryDoesNotExist(database_path('migrations'));
        } finally {
            rmdir($sessionDirectory);
        }
    }
}
