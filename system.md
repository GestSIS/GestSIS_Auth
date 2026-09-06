# Buts visés

- Donner des droits aux utilisateurs

# Implémenté

- **Limiter la création de compte** : à l'inscription (`POST /register`), si aucun token n'est fourni, l'email doit correspondre à un sapeur/civil actif d'un SIS. Vérifié via `GET /api/v2/email-validate` sur GestSIS_API (protégé par `JwtTokenValidatorAuth`), appelé depuis `ApiRegisterController`. En cas d'échec, même message d'erreur générique que pour un email déjà utilisé, pour ne pas révéler lequel des deux cas s'est produit.
- **Lien email ↔ sapeur** : la liaison se fait uniquement lors de la validation de l'email (`ApiConfirmerEmailController`), les droits de base du sapeur ne sont accordés qu'une fois l'email vérifié. Les emails identiques sont rapprochés à ce moment-là.
- **Comptes non-membres du SIS (ex. caissier externe)** : système de tokens d'inscription plutôt qu'un envoi d'email direct.
  - `POST /register-token` — génère un token (rôles à attribuer en paramètre), protégé par `jwtTokenRole:utilisateur.tout`.
  - `POST /use-token` — consomme un token et attribue les rôles ; le token est invalidé après usage.
  - `POST /register` accepte un champ `token` optionnel dans le body (et non `?token=` en query string comme envisagé initialement) : s'il est fourni et valide, le check email/SIS est sauté et les rôles du token sont attribués directement.
  - Stockage : tables `register_tokens` / `register_token_roles`, token haché avant stockage, avec durée de validité. Couvert par `tests/Unit/RegisterTokenTest.php`.
- **Schéma DB rôles/permissions/utilisateurs + seeders** : `permissions`, `roles`, `user_roles`, `permission_roles`, `sis`, `sapeurs`, plus `register_tokens`/`register_token_roles`. Seeders `PermissionSeeder`, `SisSeeder`, `RoleSeeder`, `UserSeeder`.
- **Comptes tablette/techniques** : a évolué vers un mécanisme différent de ce qui était envisagé ici.
  - Un système de tokens API scopés (permissions + SIS) existe désormais : tables `api_tokens`/`api_token_permissions`/`api_token_sis`, géré via `ApiTokenController` (large couverture de tests, `tests/Feature/ApiTokenTest.php`).
  - Commande `php artisan token:generate-long-term {sisKey} ...` : crée un utilisateur technique et émet un JWT longue durée scopé — répond au besoin "caissier sans compte" côté CLI plutôt qu'en self-service.
  - Comptes `@gestsis.ch` : changement de mot de passe refusé (`ApiMotDePasseController::changer`), connexion à GestSIS App bloquée (`PageLogin.vue`), à l'exception des comptes `admin@gestsis.ch`/`demo@gestsis.ch`. **⚠ Bug repéré pendant cette relecture** : la condition d'exemption dans `PageLogin.vue` compare `!email === "admin@gestsis.ch"` (donc toujours `false`), l'exemption ne se déclenche donc jamais actuellement — à corriger séparément.
- **Synchronisation email ↔ sapeur dans le temps** : commande planifiée `users:sync-sapeurs` (rapproche par email les sapeurs sans compte lié, en ne considérant que les comptes dont l'email est vérifié ; `User::getSapeurs` n'émet de toute façon le claim `sapeurs` que pour ces comptes). Si un sapeur est déjà lié à un *autre* utilisateur (signe d'un changement d'email non traité), le conflit est signalé à Sentry/Bugsink pour revue manuelle plutôt que d'être résolu automatiquement.
- **Désactivation automatique** : commande planifiée `users:process-deactivation`, révoque les droits/désactive le compte quand le sapeur lié devient inactif.
- **Tests** : couverture substantielle désormais en place (`RegisterTokenTest`, `ApiRegisterTest`, `ApiTokenTest`, `ApiLoginDisabledAccountTest`, `AdminUserRoleControllerTest`, `AdminSapeurControllerTest`, `SisControllerTest`, `ProcessAccountDeactivationTest`, `SyncSapeurUserMappingsTest`).

# Questions encore ouvertes

- **Captcha anti-spam** : jamais implémenté (aucune trace de recaptcha/turnstile/hcaptcha). À décider si le besoin se confirme.
- **Comptes spéciaux ECA** : pas traité spécifiquement comme envisagé (validation du domaine `@eca-jura.ch`). Probablement couvert de fait par les tokens d'inscription ou les tokens API techniques — à confirmer si un besoin réel se présente.
- **Changement d'email** : toujours aucune fonctionnalité self-service. Le cas est seulement détecté défensivement par `users:sync-sapeurs` (conflit signalé, pas de re-liaison automatique).

# A faire

1. Implémenter le changement d'email (self-service) et la re-liaison du sapeur qui en découle.
2. Corriger le bug d'exemption admin/demo dans `GestSIS_APP/src/pages/PageLogin.vue` (condition toujours fausse).
3. Décider si un captcha est nécessaire (spam constaté en pratique ou non).
4. Statuer sur le traitement des comptes ECA (domaine dédié vs tokens existants).
