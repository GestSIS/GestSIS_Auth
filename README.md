# GestSIS_Auth

Serveur d'authentification pour la nouvelle API GestSIS

## Installation

Installation des dépendances :

```sh
composer install
cp .env.example .env
```

Modifier le fichier `.env` afin de configurer la base de données. Attention, la création de la base de données n'est pas réalisée par ce script. La base de données pour ce serveur (GestSIS_Auth) ne doit pas
être la même que celle du serveur API (GestSIS_API).

Puis :
```sh
php artisan migrate --step
php artisan db:seed
```

### Génération des clés

Créer 2 fichiers dans le dossier `storage\keys`:

-   auth-private.key
-   auth-public.key

Ces fichiers sont vos clés publique et privée RSA 256 pour jwt.

Celles-ci peuvent être facilement générées sur le site suivant.

-   [http://travistidwell.com/jsencrypt/demo/](http://travistidwell.com/jsencrypt/demo/)

### Démarrage du serveur de développement

```sh
php artisan serve -port=8001
```

### Développement dans une machine virtuelle
Si le serveur de développement est lancé dans une machine virtuelle, mais que l'accès se fait depuis l'hôte, il est nécessaire d'ajouter `--host=XXX` à la commande ci-dessus.

## Intégration du système d'authentification dans une nouvelle API

Il n'est pas nécessaire de suivre les indications de cette section pour démarrer GestSIS_Auth.

### Prérequis

Pour installer les prérequis:

`composer require firebase/php-jwt`

### Copier la clé publique

Copier le fichier `storage\keys\auth-public.key` au même endroit dans votre installation.

### Ajout du drivers

Ajouter le code suivant qui se trouve dans le fichier `config/filesystems.php` dans la clé `disks`:

`'keys' => [ 'driver' => 'local', 'root' => storage_path('keys') ]`

Celà permet d'accéder à la clé précédemment créé à travers le drivers keys.

Vous devriez ainsi obtenir quelque chose comme :

```php
    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        //...

        'keys' => [
            'driver' => 'local',
            'root' => storage_path('keys')
        ],
    ],
```

### Copier le fichier `TokenTools`

Copier le fichier `app\Auth\TokenTools.php` dans un nouveau dossier `app\Auth` dans votre projet. Ce fichier utilise le drivers `keys` pour chargé la clé publique.
