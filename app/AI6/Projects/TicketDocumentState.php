<?php

namespace App\AI6\Projects;

enum TicketDocumentState: string
{
    case UNPARSED = 'unparsed';
    case INVALID = 'invalid';
    case VALID = 'valid';
}
