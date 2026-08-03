<?php

namespace App\AI6\Auth;

use RuntimeException;

final class CannotRemoveLastAdministrator extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der letzte aktive globale Administrator kann nicht entfernt werden.');
    }
}
