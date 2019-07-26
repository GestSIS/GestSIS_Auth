# GestSIS_Auth

## Getting started

Create two files in the folder `storage\keys`:
- auth-private.key
- auth.public.key

Those files are your public and private rsa256 keys for jwt.

You can easily generate those from this website:
- [http://travistidwell.com/jsencrypt/demo/](http://travistidwell.com/jsencrypt/demo/) 

## How to integrate this auth system into your api

### Prérequis

Pour installer les prérequis:

`composer require firebase/php-jwt`

### Copier la clé publique

Copier le fichier `storage\keys\auth-public.key` au même endroit dans votre installation.

### Ajout du drivers

Ajouter le code suivant dans le fichier `config/disks`:

`
'keys' => [
    'driver' => 'local',
    'root' => storage_path('keys')
]
`

Celà permet d'accéder à la clé précédemment créé à travers le drivers keys.
 
### Copier le fichier `TokenTools`

Copier le fichier `TokenTools` dans un dossier Auth. Ce fichier utilise le drivers `keys` pour chargé la clé publique.
 
