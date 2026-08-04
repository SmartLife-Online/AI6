<?php

namespace App\AI6\Auth\Models;

use App\AI6\Auth\EmailNormalizer;
use App\AI6\Projects\Models\ProjectMembership;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property bool $is_global_admin
 */
final class User extends Authenticatable
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'is_global_admin',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function setEmailAttribute(string $email): void
    {
        $this->attributes['email'] = (new EmailNormalizer)->normalize($email);
    }

    /** @return HasMany<ProjectMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /** @return HasMany<PasskeyCredential, $this> */
    public function passkeyCredentials(): HasMany
    {
        return $this->hasMany(PasskeyCredential::class);
    }

    /** @return HasOne<TotpCredential, $this> */
    public function totpCredential(): HasOne
    {
        return $this->hasOne(TotpCredential::class);
    }

    /** @return HasMany<RecoveryCode, $this> */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_global_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
