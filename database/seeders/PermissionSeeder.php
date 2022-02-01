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
            array('id' => 1, 'nom' => 'Sapeur lecture', 'description' => 'Affichage des informations sapeurs', 'api_key' => 'sapeur.lecture'),
            array('id' => 2, 'nom' => 'Sapeur modification', 'description' => 'Modification des informations sapeurs', 'api_key' => 'sapeur.modification'),
            array('id' => 3, 'nom' => 'Comptabilité', 'description' => 'Comptabilité, contrôle total', 'api_key' => 'comptabilite.tout'),
            array('id' => 4, 'nom' => 'Intervention saisie', 'description' => 'Saisie des interventions', 'api_key' => 'intervention.modification'),
            array('id' => 5, 'nom' => 'Intervention validation', 'description' => 'Validation des interventions après saisie', 'api_key' => 'intervention.validation'),
            array('id' => 6, 'nom' => 'Exercice modification', 'description' => 'Saisie des exercices', 'api_key' => 'exercice.modification'),
            array('id' => 7, 'nom' => 'Exercice saisie des présences', 'description' => 'Saisie des présences pour exercice', 'api_key' => 'exercice.presence'),
            array('id' => 8, 'nom' => 'Exercice validation', 'description' => 'Validation des exercices', 'api_key' => 'exercice.validation'),
            array('id' => 9, 'nom' => 'Organisation modification', 'description' => 'Modification des groupes', 'api_key' => 'organisation.modification'),
            array('id' => 10, 'nom' => 'Contrôle médical', 'description' => 'Gestion des contrôles médicaux', 'api_key' => 'controle_medical.tout'),
            array('id' => 11, 'nom' => 'Utilisateur', 'description' => 'Modification des droits des utilisateurs', 'api_key' => 'utilisateur.tout'),

            // Config
            array('id' => 12, 'nom' => 'Config pour sapeur', 'description' => 'Configuration pour module sapeur', 'api_key' => 'sapeur.config'),
            array('id' => 13, 'nom' => 'Config pour organisation', 'description' => 'Configuration pour groupes', 'api_key' => 'organisation.config'),
            array('id' => 14, 'nom' => 'Config pour exercice', 'description' => 'Configuration pour module exercice', 'api_key' => 'exercice.config'),
            array('id' => 15, 'nom' => 'Config pour intervention', 'description' => 'Configuration pour module intervention', 'api_key' => 'intervention.config'),
            array('id' => 16, 'nom' => 'Config pour comptabilite', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'comptabilite.config'),
            array('id' => 17, 'nom' => 'Config pour contrôle medical', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'controle_medical.config'),
            array('id' => 18, 'nom' => 'Config pour utilisateur', 'description' => 'Configuration des différents rôles', 'api_key' => 'utilisateur.config'),
            array('id' => 19, 'nom' => 'Config générale', 'description' => 'Configuration des informations du SIS', 'api_key' => 'sis.config'),

            // Admin
            array('id' => 20, 'nom' => 'Admin', 'description' => 'Paramètres admin du système ', 'api_key' => 'admin.tout'),

            // Rajouté
            array('id' => 21, 'nom' => 'Exercice lecture', 'description' => 'Affichage des exercices ', 'api_key' => 'exercice.lecture'),
        );

        foreach ($elements as $element) {
            DB::table('permissions')->insert($element);
        }
    }
}
