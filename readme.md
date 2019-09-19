# GestSIS_Auth

## Getting started

Create two files in the folder `storage\keys`:

-   auth-private.key
-   auth-public.key

Those files are your public and private rsa256 keys for jwt.

You can easily generate those from this website:

-   [http://travistidwell.com/jsencrypt/demo/](http://travistidwell.com/jsencrypt/demo/)

## To run

```sh
php artisan serve -port=8001
```

## How to integrate this auth system into your new api

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
