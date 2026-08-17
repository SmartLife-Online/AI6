<?php

namespace App\AI6\Shared\Process;

enum MailboxRejection: string
{
    case INCOMPLETE = 'incomplete';
    case TOO_LARGE = 'too_large';
    case INVALID_VERSION = 'invalid_version';
    case FOREIGN_ROLE = 'foreign_role';
    case FOREIGN_SLOT = 'foreign_slot';
    case SIZE_MISMATCH = 'size_mismatch';
    case HASH_MISMATCH = 'hash_mismatch';
    case REPLAY = 'replay';
    case INVALID_ENCODING = 'invalid_encoding';
}
