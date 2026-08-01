<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        // Keep tests deterministic by preventing a local root .env from being loaded.
        $app->useEnvironmentPath(__DIR__);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
