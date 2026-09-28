<?php

namespace App\AI6\Shared\Process;

interface ProcessRuntimeProbe
{
    public function apparmorConfined(ExecutionRole $role): bool;

    /** @return array<string, bool> */
    public function checkerRuntimePromises(): array;

    /** @return list<string> */
    public function mountOptions(string $path): array;
}
