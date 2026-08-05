<?php

namespace App\AI6\Projects;

final class ProjectIdentifierGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
