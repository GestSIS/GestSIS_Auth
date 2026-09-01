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

];
