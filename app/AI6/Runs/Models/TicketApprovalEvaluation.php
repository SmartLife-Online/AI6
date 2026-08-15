<?php

namespace App\AI6\Runs\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $ticket_approval_id
 * @property string $state
 * @property bool|null $eligible
 * @property list<string>|null $reasons
 */
final class TicketApprovalEvaluation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'eligible' => 'boolean',
            'reasons' => 'array',
        ];
    }
}
