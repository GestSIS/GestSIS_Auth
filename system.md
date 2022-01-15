# Buts visés

- Donner des droits aux utilisateurs

# Questions
- Comment limiter le nombres d'utilisateurs du système afin que seul un nombre restreint de personnes puissent y avoir accès ?
- Possible de récupérer l'email de l'utilisateur et le comparer aux informations sapeurs, ce qui permetterais de lier un sapeur de manière implicite avec un user.
- Steps à faire :
    Lorsqu'un user valide son email, lui donner les droits de base d'un sapeur si son email correspond à un sapeur du SIS.

- Comment faire afin que seul les personnes requisent puissent créer un compte ?
    Lors de la demande de création d'un compte, l'utilisateur saisie son email.
    Si un SIS contient cet email alors il est autorisé à créer son compte -> requière une validation de l'email.
    Autrement il est refusé
- Utiliser un captcha pour éviter le spamming

- Quand est-il pour les comptes spéciaux de l'ECA ?
    Très peu nombreux, création manuelle par mes soins ? Ou alors valider les adresse email ...@eca-jura.ch ?

- Intersection des email avec données du SIS, utiliser hash function sur l'email.

- Que faire lors du changement d'email ?
    Changer email du sapeur afin que son compte soit toujours lié ?
    Garder une trace du sapeur lié ?

- Que faire quand un caissier n'est pas membre du SIS mais utilise GestSIS ?
    Possibilité de générer un code permettant de récupérer des droits et créer un compte ?
    Register avec un lien spécial contenant le token
    Prévoir une interface ou l'utilisateur peut avoir accès aux informations de son compte
    Et ainsi ajouter des tokens qu'on lui aurait passer.
    Nécessite mis-à-jour de la DB pour ajouter une table token reliant certains rôles

- Est-ce un bonne idée d'avoir des tokens ou alors on ne devrait pas simplement donner l'email de l'utilisateur ?
    Non, car l'utilisateur préfèreras peut-être utiliser une autre adresse email ou possèderas peut-être déjà un compte avec cette adresse email.

- Lors d'ajout de sapeur, envoie d'un email pour s'inscrire sur GestSIS ou pas ?
    Pour l'instant non. Mais pourquoi pas dans le future

TODO: Définir les routes pour créer un compte :
- Auth
    /register?token=khksfdhkjhsdkdsojre -> ajout check pour email + prendre en compte un potentiel token
    /login -> comme avant
    /generate-token [post] permet de générer un token -> prend en paramètre les rôles à attribuer, est une checkbox pour envoyer par email le token ou pas
    /token -> [post] Permet d'ajouter un token -> token invalidé une fois utilisé

- API
    /email-check -> requiere JWT avec rôle particulier représentant le serveur d'authentification
    Retourne la liste des SIS contenant cette email et sapeur est actif

- Saisie des infos :
    Message d'information: "Veuillez consulter votre adresse email afin de valider votre compte si votre adresse email est référencée dans le système."


Dévelopement process :
1. Schéma de la DB pour role, perms, user
2. Seeder pour créer quelques comptes
3. Ajout des droits dans les JWT
4. Ajout check des droits dans l'API
5. Modification de register pour ajouter le système de check de l'email
6. Modification du schéma pour token
7. Modification register pour prendre en compte un potentiel token
8. Ajout route pour ajouter un token
9. Interface graphique, détailler les différentes étapes

Register, 2 cas possible :
- Avec un token
    Donne accès directement à certains droits -> OK
- Sans token
    Aucun droit disponible -> OK
    Possible de créer un compte que pour un email existant

Lié avec un compte sapeur qu'une fois l'adresse email de vérifié -> OK
- Idem pour les droits, idéalement oui.

Lors de la validation de l'email, lié avec les emails identiques afin de lier aux sapeurs -> OK

A faire :
1. Reset mdp
2. Changer email
3. Utiliser token pour compte actif
4. Tester ce qui est en place.
5. Changer mdp
