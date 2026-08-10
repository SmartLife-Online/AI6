<?php

namespace App\AI6\Projects;

enum ProjectAction: string
{
    case APPEAR_IN_LIST = 'appear_in_list';
    case VIEW_DETAILS = 'view_details';
    case REFRESH_READ_MODEL = 'refresh_read_model';
}
