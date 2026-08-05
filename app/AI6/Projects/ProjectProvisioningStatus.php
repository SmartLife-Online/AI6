<?php

namespace App\AI6\Projects;

enum ProjectProvisioningStatus: string
{
    case NOT_PROVISIONED = 'not_provisioned';
    case PROVISIONING = 'provisioning';
    case PROVISIONED = 'provisioned';
    case PROVISIONING_FAILED = 'provisioning_failed';

    public function exposesPublicDeployKey(): bool
    {
        return $this === self::PROVISIONED;
    }
}
