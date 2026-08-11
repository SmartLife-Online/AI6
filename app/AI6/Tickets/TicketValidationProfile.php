<?php

namespace App\AI6\Tickets;

enum TicketValidationProfile: string
{
    case GENERIC_V1 = 'generic_v1';
    case AI6_DETAIL_V1 = 'ai6_detail_v1';
}
