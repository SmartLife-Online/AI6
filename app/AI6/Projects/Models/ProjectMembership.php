<?php

namespace App\AI6\Projects\Models;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\ProjectRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property ProjectRole $role
 */
final class ProjectMembership extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'project_id',
        'role',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
        ];
    }
}
