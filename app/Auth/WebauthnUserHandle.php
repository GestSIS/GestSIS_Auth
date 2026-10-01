<?php

namespace App\Auth;

class WebauthnUserHandle
{
    /**
     * Le "user.id" WebAuthn doit être un identifiant opaque (la spec déconseille
     * d'exposer une info identifiante comme un id séquentiel de table) plutôt
     * que l'id brut de `users`. Dérivé de façon déterministe (pas besoin de
     * colonne dédiée ni de table de correspondance) : on connaît toujours le
     * user_id au moment où ce handle est nécessaire (jamais de lookup inverse).
     */
    public static function forUserId(int|string $userId): string
    {
        return hash('sha256', 'gestsis-webauthn-user:' . $userId, true);
    }
}
