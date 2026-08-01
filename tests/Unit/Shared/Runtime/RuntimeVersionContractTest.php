<?php

namespace Tests\Unit\Shared\Runtime;

use PHPUnit\Framework\TestCase;

final class RuntimeVersionContractTest extends TestCase
{
    public function test_pins_accept_only_the_approved_php_and_sqlite_minor_lines(): void
    {
        $dockerfile = $this->read('Dockerfile');
        $compose = $this->decodeCompose($this->read('docker-compose.yml'));

        self::assertSame([], $this->versionContractErrors($dockerfile, $compose));
        $phpVersion = $this->extract(
            '/^ARG PHP_IMAGE="php:(\d+\.\d+\.\d+)-apache-bookworm@sha256:[0-9a-f]{64}"$/m',
            $dockerfile,
        );
        $sqliteVersion = $this->extract('/^ARG SQLITE_VERSION="(\d+\.\d+\.\d+)"$/m', $dockerfile);
        self::assertNotNull($phpVersion);
        self::assertNotNull($sqliteVersion);

        $phpDrift = str_replace(
            'ARG PHP_IMAGE="php:'.$phpVersion.'-apache-bookworm',
            'ARG PHP_IMAGE="php:8.6.0-apache-bookworm',
            $dockerfile,
            $phpReplacementCount,
        );
        self::assertSame(1, $phpReplacementCount);
        self::assertNotSame([], $this->versionContractErrors($phpDrift, $compose));

        $sqliteDrift = str_replace(
            'ARG SQLITE_VERSION="'.$sqliteVersion.'"',
            'ARG SQLITE_VERSION="3.54.0"',
            $dockerfile,
            $sqliteReplacementCount,
        );
        self::assertSame(1, $sqliteReplacementCount);
        self::assertNotSame([], $this->versionContractErrors($sqliteDrift, $compose));

        $composeDrift = $compose;
        $composeDrift['services']['app']['image'] = 'ai6-runtime:php-8.6.0_sqlite-3.54.0';
        self::assertNotSame([], $this->versionContractErrors($dockerfile, $composeDrift));
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    private function versionContractErrors(string $dockerfile, array $compose): array
    {
        $errors = [];
        $phpVersion = $this->extract(
            '/^ARG PHP_IMAGE="php:(\d+\.\d+\.\d+)-apache-bookworm@sha256:[0-9a-f]{64}"$/m',
            $dockerfile,
        );
        $sqliteVersion = $this->extract('/^ARG SQLITE_VERSION="(\d+\.\d+\.\d+)"$/m', $dockerfile);

        if ($phpVersion === null || preg_match('/\A8\.5\.\d+\z/D', $phpVersion) !== 1) {
            $errors[] = 'Dockerfile PHP_IMAGE is outside the approved PHP 8.5 line.';
        }
        if ($sqliteVersion === null || preg_match('/\A3\.53\.\d+\z/D', $sqliteVersion) !== 1) {
            $errors[] = 'Dockerfile SQLITE_VERSION is outside the approved SQLite 3.53 line.';
        }

        $expectedImage = $phpVersion === null || $sqliteVersion === null
            ? null
            : 'ai6-runtime:php-'.$phpVersion.'_sqlite-'.$sqliteVersion;
        foreach (['init', 'app', 'worker', 'scheduler', 'agent', 'checker'] as $service) {
            $actualImage = $compose['services'][$service]['image'] ?? null;
            if (! is_string($actualImage) || $actualImage !== $expectedImage) {
                $errors[] = "$service image does not match the Dockerfile PHP and SQLite pins.";
            }
        }

        return $errors;
    }

    private function extract(string $pattern, string $contents): ?string
    {
        return preg_match($pattern, $contents, $matches) === 1 ? $matches[1] : null;
    }

    /** @return array<string, mixed> */
    private function decodeCompose(string $contents): array
    {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        self::assertNotFalse($contents);

        return $contents;
    }
}
