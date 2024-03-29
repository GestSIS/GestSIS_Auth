<?php

namespace App\Models;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'validate_email_token'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'validate_email_token'
    ];

    /**
     * Get the attributes that should be cast.
     * 
     * @return array
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'admin' => 'boolean',
        ];
    }

    public static function getPermissions($userId)
    {
        $user = User::find($userId);
        if ($user && $user->admin) {
            $sisListe = Sis::all();
            $groupedPermissions = array();
            foreach ($sisListe as $element) {
                $groupedPermissions[$element->api_key][] = "admin";
            }
            return $groupedPermissions;
        } else {
            // Load permissions
            $permissions = DB::table('permissions')
                ->join('permission_roles', 'permissions.id', '=', 'permission_roles.permission_id')
                ->join('roles', 'roles.id', '=', 'permission_roles.role_id')
                ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
                ->join('sis', 'sis.id', '=', 'roles.sis_id')
                ->where('user_roles.user_id', '=', $userId)
                ->select('permissions.api_key as perm_key', 'sis.api_key as sis_key')
                ->distinct()
                ->get();

            $groupedPermissions = array();
            foreach ($permissions as $element) {
                $groupedPermissions[$element->sis_key][] = $element->perm_key;
            }
            return $groupedPermissions;
        }
    }

    public static function getSapeurs($userId)
    {
        $sapeurs = DB::table('sapeurs')
            ->join('sis', 'sis.id', '=', 'sapeurs.sis_id')
            ->where('sapeurs.user_id', '=', $userId)
            ->select('sis.api_key as sis_key', 'sapeurs.sapeur_id as sapeur_id')
            ->distinct()
            ->get();

        $indexedSapeurs = array();
        foreach ($sapeurs as $element) {
            $indexedSapeurs[$element->sis_key] = $element->sapeur_id;
        }
        return $indexedSapeurs;
    }

    public static function getMobile($userId)
    {
        $mobiles = DB::table('roles')
            ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('sis', 'sis.id', '=', 'roles.sis_id')
            ->where('user_roles.user_id', '=', $userId)
            ->select('sis.api_key as sis_key', 'sis.mobile as mobile')
            ->distinct()
            ->get();

        $groupedMobile = array();
        foreach ($mobiles as $element) {
            if ($element->mobile) {
                $groupedMobile[$element->sis_key][] = $element->mobile;
            }
        }
        return array_keys($groupedMobile);
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function getActiveRefreshToken()
    {
        return $this->refreshTokens()->where('expire', '>', Carbon::now())->first();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function sapeur()
    {
        return $this->hasMany(Sapeur::class);
    }

    public function PasswordResetTokens()
    {
        return $this->hasMany(PasswordResetToken::class, 'user_id');
    }
}
