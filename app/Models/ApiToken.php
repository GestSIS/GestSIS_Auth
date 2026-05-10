<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ApiToken extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'token',
        'expires_at',
        'last_used_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the API token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the permissions assigned to this token.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'api_token_permissions');
    }

    /**
     * Get the SIS allowed for this token.
     * If empty, the token is valid for all user's SIS.
     */
    public function allowedSis(): BelongsToMany
    {
        return $this->belongsToMany(Sis::class, 'api_token_sis');
    }

    /**
     * Scope to only include active (non-expired) tokens.
     */
    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    /**
     * Scope to filter tokens by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get valid permissions for this token.
     * Returns only permissions the user still has, grouped by SIS.
     * If the token has restricted SIS, only include those SIS.
     * 
     * @return array
     */
    public function getValidPermissions(): array
    {
        // Get token's assigned permissions
        $tokenPermissions = $this->permissions()->get();

        // Get user's current permissions (grouped by SIS)
        $userPermissions = User::getPermissions($this->user_id);

        // If token has restricted SIS, filter user permissions to only those SIS
        $allowedSisKeys = $this->allowedSis()->pluck('api_key')->toArray();
        if (!empty($allowedSisKeys)) {
            $userPermissions = collect($userPermissions)
                ->filter(fn($perms, $sisKey) => in_array($sisKey, $allowedSisKeys))
                ->toArray();
        }

        // Flatten user permissions for quick lookup
        $userPermissionKeys = collect($userPermissions)
            ->flatMap(fn($perms, $sisKey) => $perms)
            ->unique()
            ->toArray();

        // Filter and group token permissions by SIS
        return $tokenPermissions
            ->filter(fn($permission) => in_array($permission->api_key, $userPermissionKeys))
            ->reduce(function ($result, $permission) use ($userPermissions) {
                // Find which SIS this permission belongs to for this user
                foreach ($userPermissions as $sisKey => $perms) {
                    if (in_array($permission->api_key, $perms)) {
                        $result[$sisKey][] = $permission->api_key;
                        break;
                    }
                }
                return $result;
            }, []);
    }
}
