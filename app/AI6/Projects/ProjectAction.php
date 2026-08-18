<?php

namespace App\AI6\Projects;

enum ProjectAction: string
{
    case APPEAR_IN_LIST = 'appear_in_list';
    case VIEW_DETAILS = 'view_details';
    case REFRESH_READ_MODEL = 'refresh_read_model';
    case EDIT_TICKET = 'edit_ticket';
    case CHANGE_TICKET_STATUS = 'change_ticket_status';
    case REFRESH_CONFIGURATION = 'refresh_configuration';
    case APPROVE_CONFIGURATION = 'approve_configuration';
    case APPROVE_TICKET = 'approve_ticket';
    case START_RUN = 'start_run';
    case VIEW_RUN = 'view_run';
    case ANSWER_HUMAN_REQUEST = 'answer_human_request';
}
