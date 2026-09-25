<?php

return [

  /*
    |--------------------------------------------------------------------------
    | API Url
    |--------------------------------------------------------------------------
    |
    | This value is the url of the API, it will be used to verify if a given email might receive some rights
    |
    */
  'api_url' => env('APP_GESTSIS_API_URL', ''),

  /*
    |--------------------------------------------------------------------------
    | APP Url
    |--------------------------------------------------------------------------
    |
    | This value is the url of the APP, it will be used to generate the correct validation link URL
    |
    */
  'app_url' => env('APP_GESTSIS_APP_URL', ''),

  /*
    |--------------------------------------------------------------------------
    | Délai de grâce avant désactivation d'un compte sans rôle
    |--------------------------------------------------------------------------
    |
    | Nombre de jours entre le moment où un compte sans rôle (et sans sapeur
    | actif lié) est marqué à désactiver, et sa désactivation effective.
    |
    */
  'deactivation_grace_days' => (int) env('GESTSIS_DEACTIVATION_GRACE_DAYS', 30),

  /*
    |--------------------------------------------------------------------------
    | Dépôt GitHub de l'application mobile
    |--------------------------------------------------------------------------
    |
    | Utilisé pour déterminer la dernière version publiée (via les releases
    | GitHub) et proposer une mise à jour dans l'application mobile.
    |
    */
  'mobile_github_repo' => env('GESTSIS_MOBILE_GITHUB_REPO', 'GestSIS/GestSIS_Mobile'),

  /*
    |--------------------------------------------------------------------------
    | Enforcement de la politique 2FA
    |--------------------------------------------------------------------------
    |
    | La date d'échéance (2fa_policies.enforced_at) est partagée par tous les
    | environnements et pilotée par un admin via le dashboard. Ce toggle
    | permet de désactiver le blocage localement (dev/CI) sans jamais bloquer
    | un compte de démo, indépendamment de la date configurée en base.
    | L'activation volontaire du 2FA (opt-in) n'est jamais affectée par ce
    | toggle : elle fonctionne dans tous les environnements.
    |
    */
  'two_factor_enforcement_enabled' => filter_var(env('GESTSIS_TWO_FACTOR_ENFORCEMENT_ENABLED', true), FILTER_VALIDATE_BOOL),

  /*
    |--------------------------------------------------------------------------
    | WebAuthn / FIDO2 (2FA)
    |--------------------------------------------------------------------------
    |
    | `rp_id` doit correspondre exactement au hostname vu par le navigateur
    | (pas une IP) de l'app GestSIS_APP — pas celui de l'API. `allowed_origins`
    | doit inclure le schéma (http/https) et, si non standard, le port.
    |
    */
  'webauthn_rp_id' => env('GESTSIS_WEBAUTHN_RP_ID', 'localhost'),
  'webauthn_rp_name' => env('GESTSIS_WEBAUTHN_RP_NAME', 'GestSIS'),
  'webauthn_allowed_origins' => array_filter(explode(',', (string) env('GESTSIS_WEBAUTHN_ALLOWED_ORIGINS', 'http://localhost:8080'))),

];
