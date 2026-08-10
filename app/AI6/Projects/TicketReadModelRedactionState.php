<?php

namespace App\AI6\Projects;

enum TicketReadModelRedactionState: string
{
    case CLEAR = 'clear';
    case CONTENT_REDACTED = 'content_redacted';
}
