<?php

namespace App\AI6\Shared\Process;

interface ProcessRuntimeProbe
{
    /** @return array<string, bool> */
    public function checkerRuntimePromises(): array;

    /** @return list<string> */
    public function mountOptions(string $path): array;
}
