<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use Notifiable;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'validate_email_token',
        'validate_email_expire'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'validate_email_token',
        'validate_email_expire',
        ...self::ADMIN_ONLY_ATTRIBUTES,
    ];

    /**
     * Réglages de sécurité réservés au tableau de bord admin (exemptions 2FA,
     * rappels, durée maximale des sessions) : masqués par défaut — un
     * responsable SIS listant ses utilisateurs n'a pas à voir quels comptes
     * sont exemptés ni pourquoi. Rendus visibles explicitement par les
     * endpoints admin (makeVisible).
     */
    public const ADMIN_ONLY_ATTRIBUTES = [
        'two_factor_reminder_sent_at',
        'two_factor_exempt',
        'two_factor_exempt_until',
        'two_factor_exempt_reason',
        'two_factor_exempt_by',
        'session_max_days',
        'session_max_days_set_by',
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
            'validate_email_expire' => 'datetime',
            'password' => 'hashed',
            'admin' => 'boolean',
            'pending_deactivation_at' => 'datetime',
            'disabled_at' => 'datetime',
            'session_max_days' => 'integer',
            'two_factor_reminder_sent_at' => 'datetime',
            'two_factor_exempt' => 'boolean',
            'two_factor_exempt_until' => 'datetime',
        ];
    }

    public static function getPermissions(int|string $userId): array
    {
        $user = User::find($userId);
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

        $groupedPermissions = [];
        foreach ($permissions as $element) {
            $groupedPermissions[$element->sis_key][] = $element->perm_key;
        }
        return $groupedPermissions;
    }

    /**
     * Recharge l'utilisateur depuis la DB et retourne null s'il n'existe plus
     * ou a été désactivé — pour ne pas se fier uniquement au JWT, qui reste
     * valide jusqu'à expiration naturelle même après désactivation du compte.
     */
    public static function findActive(int|string $userId): ?self
    {
        $user = self::find($userId);

        return ($user === null || $user->disabled_at !== null) ? null : $user;
    }

    /**
     * Summary of getSapeurs
     * @param int|string $userId
     * @return array<int, int>
     */
    public static function getSapeurs(int|string $userId): array
    {
        // Le claim `sapeurs` n'est émis que pour un compte dont l'email est vérifié :
        // un lien créé (ou hérité) avant vérification ne doit donner aucun accès.
        $sapeurs = DB::table('sapeurs')
            ->join('sis', 'sis.id', '=', 'sapeurs.sis_id')
            ->join('users', 'users.id', '=', 'sapeurs.user_id')
            ->where('sapeurs.user_id', '=', $userId)
            ->whereNotNull('users.email_verified_at')
            ->whereNull('sapeurs.deactivated_at')
            ->select('sis.api_key as sis_key', 'sapeurs.sapeur_id as sapeur_id')
            ->distinct()
            ->get();

        $indexedSapeurs = [];
        foreach ($sapeurs as $element) {
            $indexedSapeurs[$element->sis_key] = $element->sapeur_id;
        }
        return $indexedSapeurs;
    }

    public static function getMobile(int|string $userId)
    {
        $mobiles = DB::table('roles')
            ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('sis', 'sis.id', '=', 'roles.sis_id')
            ->where('user_roles.user_id', '=', $userId)
            ->select('sis.api_key as sis_key', 'sis.mobile as mobile')
            ->distinct()
            ->get();

        $groupedMobile = [];
        foreach ($mobiles as $element) {
            if ($element->mobile) {
                $groupedMobile[$element->sis_key][] = $element->mobile;
            }
        }
        return array_keys($groupedMobile);
    }

    /**
     * Sessions (appareils connectés) du compte.
     *
     * @return HasMany<AuthSession,$this>
     */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /**
     * Refresh tokens d'avant les sessions signées (transition, voir LegacyRefreshToken).
     *
     * @return HasMany<LegacyRefreshToken,$this>
     */
    public function legacyRefreshTokens(): HasMany
    {
        return $this->hasMany(LegacyRefreshToken::class);
    }

    /**
     * Déconnecte tous les appareils du compte, sauf éventuellement la session
     * courante. Les access tokens déjà émis expirent d'eux-mêmes (60 min au plus).
     *
     * @return int nombre de sessions révoquées
     */
    public function revokeAllSessions(?string $exceptSessionId = null): int
    {
        $this->legacyRefreshTokens()->delete();

        return $this->authSessions()
            ->when($exceptSessionId !== null, fn ($query) => $query->whereKeyNot($exceptSessionId))
            ->delete();
    }

    /**
     * @return HasMany<TwoFactorRecoveryCode,$this>
     */
    public function twoFactorRecoveryCodes(): HasMany
    {
        return $this->hasMany(TwoFactorRecoveryCode::class);
    }

    /**
     * Durée maximale d'une session de ce compte, depuis le login : au-delà,
     * le refresh est refusé et il faut se reconnecter (2FA compris).
     */
    public function sessionMaxDays(): int
    {
        return $this->session_max_days ?? AuthSession::DEFAULT_MAX_DAYS;
    }

    /**
     * Méthodes 2FA du compte (TOTP, clés WebAuthn), confirmées ou en cours
     * d'enrôlement. La logique 2FA passe par App\Auth\TwoFactorManager.
     *
     * @return HasMany<TwoFactorMethod,$this>
     */
    public function twoFactorMethods(): HasMany
    {
        return $this->hasMany(TwoFactorMethod::class);
    }

    /**
     * Le compte a du 2FA : au moins une méthode confirmée. Seule définition,
     * utilisée partout (login, refresh, rappels, statistiques, admin).
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFactorMethods()->confirmed()->exists();
    }

    /**
     * Équivalent requête de hasTwoFactorEnabled().
     *
     * @param Builder<User> $query
     */
    public function scopeWithTwoFactorEnabled(Builder $query): void
    {
        $query->whereHas('twoFactorMethods', fn (Builder $methods) => $methods->whereNotNull('confirmed_at'));
    }

    /**
     * @param Builder<User> $query
     */
    public function scopeWithoutTwoFactorEnabled(Builder $query): void
    {
        $query->whereDoesntHave('twoFactorMethods', fn (Builder $methods) => $methods->whereNotNull('confirmed_at'));
    }

    /**
     * Exemption 2FA valide à l'instant présent (accordée par un admin,
     * potentiellement bornée dans le temps via two_factor_exempt_until).
     */
    public function isTwoFactorExempt(): bool
    {
        if (!$this->two_factor_exempt) {
            return false;
        }

        return $this->two_factor_exempt_until === null || now()->lt($this->two_factor_exempt_until);
    }

    /**
     * @return HasMany<UserRole,$this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function sapeur(): HasMany
    {
        return $this->hasMany(Sapeur::class);
    }

    public function PasswordResetTokens(): HasMany
    {
        return $this->hasMany(PasswordResetToken::class, 'user_id');
    }
}
