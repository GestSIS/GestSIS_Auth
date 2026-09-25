<?php

namespace App\Http\Middleware;

/**
 * Variante de JwtTokenValidatorRole pour les rares routes d'Auth qu'une
 * intégration peut légitimement appeler avec un jeton d'API (lecture de ses
 * permissions, gestion des rôles/utilisateurs d'un SIS).
 */
class JwtTokenValidatorRoleOrApiToken extends JwtTokenValidatorRole
{
    protected bool $acceptsApiTokens = true;
}
