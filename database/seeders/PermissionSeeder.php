<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $elements = array(
            // Permissions de base
            array('id' => 1, 'tri' => 0, 'nom' => 'Sapeur lecture', 'description' => 'Affichage des informations sapeurs', 'api_key' => 'sapeur.lecture'),
            array('id' => 2, 'tri' => 1, 'nom' => 'Sapeur modification', 'description' => 'Modification des informations sapeurs', 'api_key' => 'sapeur.modification'),
            array('id' => 3, 'tri' => 40, 'nom' => 'Comptabilité', 'description' => 'Comptabilité, contrôle total', 'api_key' => 'comptabilite.tout'),
            array('id' => 4, 'tri' => 21, 'nom' => 'Intervention saisie', 'description' => 'Saisie des interventions', 'api_key' => 'intervention.modification'),
            array('id' => 5, 'tri' => 22, 'nom' => 'Intervention validation', 'description' => 'Validation des interventions après saisie', 'api_key' => 'intervention.validation'),
            array('id' => 6, 'tri' => 11, 'nom' => 'Exercice modification', 'description' => 'Saisie des exercices', 'api_key' => 'exercice.modification'),
            array('id' => 7, 'tri' => 12, 'nom' => 'Exercice saisie des présences', 'description' => 'Saisie des présences pour exercice', 'api_key' => 'exercice.presence'),
            array('id' => 8, 'tri' => 13, 'nom' => 'Exercice validation', 'description' => 'Validation des exercices', 'api_key' => 'exercice.validation'),
            array('id' => 9, 'tri' => 30, 'nom' => 'Organisation modification', 'description' => 'Modification des groupes', 'api_key' => 'organisation.modification'),
            array('id' => 10, 'tri' => 50, 'nom' => 'Contrôle médical', 'description' => 'Gestion des contrôles médicaux', 'api_key' => 'controle_medical.tout'),
            array('id' => 11, 'tri' => 60, 'nom' => 'Utilisateur', 'description' => 'Modification des droits des utilisateurs', 'api_key' => 'utilisateur.tout'),

            // Config
            array('id' => 12, 'tri' => 2, 'nom' => 'Config pour sapeur', 'description' => 'Configuration pour module sapeur', 'api_key' => 'sapeur.config'),

            // Supprimé
            // array('id' => 13, 'nom' => 'Config pour organisation', 'description' => 'Configuration pour groupes', 'api_key' => 'organisation.config'),

            array('id' => 14, 'tri' => 14, 'nom' => 'Config pour exercice', 'description' => 'Configuration pour module exercice', 'api_key' => 'exercice.config'),
            array('id' => 15, 'tri' => 23, 'nom' => 'Config pour intervention', 'description' => 'Configuration pour module intervention', 'api_key' => 'intervention.config'),
            array('id' => 16, 'tri' => 41, 'nom' => 'Config pour comptabilite', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'comptabilite.config'),
            array('id' => 17, 'tri' => 51, 'nom' => 'Config pour contrôle medical', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'controle_medical.config'),
            array('id' => 18, 'tri' => 61, 'nom' => 'Config pour utilisateur', 'description' => 'Configuration des différents rôles', 'api_key' => 'utilisateur.config'),
            array('id' => 19, 'tri' => 70, 'nom' => 'Config générale', 'description' => 'Configuration des informations du SIS', 'api_key' => 'sis.config'),

            // Admin
            array('id' => 20, 'tri' => 100, 'nom' => 'Admin', 'description' => 'Paramètres admin du système', 'api_key' => 'admin.tout'),

            // Rajouté
            array('id' => 21, 'tri' => 10, 'nom' => 'Exercice lecture', 'description' => 'Affichage des exercices', 'api_key' => 'exercice.lecture'),
            array('id' => 22, 'tri' => -10, 'nom' => 'Effectif', 'description' => 'Affichage de l\'effectif', 'api_key' => 'effectif.tout'),
            array('id' => 23, 'tri' => 20, 'nom' => 'Intervention lecture', 'description' => 'Affichage des interventions', 'api_key' => 'intervention.lecture'),

            // Bon choix ?
            // array('id' => 24, 'nom' => 'Intervention création', 'description' => 'Création des interventions sans visualisation', 'api_key' => 'intervention.creation'),

            array('id' => 24, 'tri' => 45, 'nom' => 'Mat. perso lecture', 'description' => 'Affichage du matériel personnel', 'api_key' => 'mat_perso.lecture'),
            array('id' => 25, 'tri' => 46, 'nom' => 'Mat. perso modification', 'description' => 'Modification du matériel personnel', 'api_key' => 'mat_perso.modification'),
            array('id' => 26, 'tri' => 47, 'nom' => 'Mat. perso config', 'description' => 'Configuration du matériel personnel', 'api_key' => 'mat_perso.config'),

            array('id' => 27, 'tri' => 35, 'nom' => 'SMS envoie', 'description' => 'Envoie de SMS', 'api_key' => 'sms.envoie'),
            array('id' => 28, 'tri' => 36, 'nom' => 'SMS config', 'description' => 'Configuration du des SMS', 'api_key' => 'sms.config'),

            array('id' => 29, 'tri' => 25, 'nom' => 'Cours lecture', 'description' => 'Affichage des cours', 'api_key' => 'cours.lecture'),
            array('id' => 30, 'tri' => 26, 'nom' => 'Cours modification', 'description' => 'Modification des cours', 'api_key' => 'cours.modification'),
            array('id' => 31, 'tri' => 27, 'nom' => 'Cours config', 'description' => 'Configuration des cours', 'api_key' => 'cours.config'),

            array('id' => 32, 'tri' => 31, 'nom' => 'Feuille heure personnelle', 'description' => 'Saisie de feuilles d\'heures personnelles', 'api_key' => 'feuille_heure.saisie_perso'),
            array('id' => 33, 'tri' => 32, 'nom' => 'Feuille heure commune', 'description' => 'Saisie de feuilles d\'heures communes', 'api_key' => 'feuille_heure.saisie_commune'),
            array('id' => 34, 'tri' => 33, 'nom' => 'Feuille heure validation', 'description' => 'Validation des feuilles d\'heures', 'api_key' => 'feuille_heure.validation'),
            array('id' => 35, 'tri' => 34, 'nom' => 'Feuille heure config', 'description' => 'Configuration des feuilles d\'heures', 'api_key' => 'feuille_heure.config'),
        );

        foreach ($elements as $element) {
            DB::table('permissions')->insert($element);
        }
    }
}
