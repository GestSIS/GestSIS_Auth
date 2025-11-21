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
            // Effectif : 0
            // Sapeurs : 100
            // Intervention : 200
            // Exercices & séances : 300
            // Fiche travail : 400
            // Cours : 500
            // Matériel personnel : 600
            // Organisation : 700
            // Absences : 750
            // Comptabilité : 800
            // Contrôles médicaux : 900
            // SMS : 1100
            // Utilisateur : 1000
            // Admin : 2000

            ['id' => 22, 'tri' => 50, 'nom' => 'Effectif', 'description' => 'Affichage de l\'effectif', 'api_key' => 'effectif.tout'],

            ['id' => 1, 'tri' => 100, 'nom' => 'Sapeur lecture', 'description' => 'Affichage des informations sapeurs', 'api_key' => 'sapeur.lecture'],
            ['id' => 2, 'tri' => 110, 'nom' => 'Sapeur modification', 'description' => 'Modification des informations sapeurs', 'api_key' => 'sapeur.modification'],
            ['id' => 12, 'tri' => 120, 'nom' => 'Config pour sapeur', 'description' => 'Configuration pour module sapeur', 'api_key' => 'sapeur.config'],

            ['id' => 23, 'tri' => 200, 'nom' => 'Intervention lecture', 'description' => 'Affichage des interventions', 'api_key' => 'intervention.lecture'],
            ['id' => 4, 'tri' => 210, 'nom' => 'Intervention saisie', 'description' => 'Saisie des interventions', 'api_key' => 'intervention.modification'],
            ['id' => 5, 'tri' => 220, 'nom' => 'Intervention validation', 'description' => 'Validation des interventions après saisie', 'api_key' => 'intervention.validation'],
            ['id' => 15, 'tri' => 230, 'nom' => 'Config pour intervention', 'description' => 'Configuration pour module intervention', 'api_key' => 'intervention.config'],

            ['id' => 6, 'tri' => 300, 'nom' => 'Exercice modification', 'description' => 'Saisie des exercices', 'api_key' => 'exercice.modification'],
            ['id' => 21, 'tri' => 310, 'nom' => 'Exercice lecture', 'description' => 'Affichage des exercices', 'api_key' => 'exercice.lecture'],
            ['id' => 7, 'tri' => 320, 'nom' => 'Exercice saisie des présences', 'description' => 'Saisie des présences pour exercice', 'api_key' => 'exercice.presence'],
            ['id' => 8, 'tri' => 330, 'nom' => 'Exercice validation', 'description' => 'Validation des exercices', 'api_key' => 'exercice.validation'],
            ['id' => 14, 'tri' => 340, 'nom' => 'Config pour exercice', 'description' => 'Configuration pour module exercice', 'api_key' => 'exercice.config'],

            ['id' => 32, 'tri' => 400, 'nom' => 'Fiche travail lecture', 'description' => 'Configuration des fiches de travail', 'api_key' => 'fiche_travail.lecture'],
            ['id' => 33, 'tri' => 410, 'nom' => 'Fiche travail personnelle', 'description' => 'Saisie de fiches de travail personnelles', 'api_key' => 'fiche_travail.saisie_perso'],
            ['id' => 34, 'tri' => 420, 'nom' => 'Fiche travail commune', 'description' => 'Saisie de fiches de travail communes', 'api_key' => 'fiche_travail.saisie_commune'],
            ['id' => 35, 'tri' => 430, 'nom' => 'Fiche travail validation', 'description' => 'Validation des fiches de travail', 'api_key' => 'fiche_travail.validation'],
            ['id' => 36, 'tri' => 440, 'nom' => 'Fiche travail config', 'description' => 'Configuration des fiches de travail', 'api_key' => 'fiche_travail.config'],

            ['id' => 29, 'tri' => 500, 'nom' => 'Cours lecture', 'description' => 'Affichage des cours', 'api_key' => 'cours.lecture'],
            ['id' => 30, 'tri' => 510, 'nom' => 'Cours modification', 'description' => 'Modification des cours', 'api_key' => 'cours.modification'],
            ['id' => 31, 'tri' => 520, 'nom' => 'Cours config', 'description' => 'Configuration des cours', 'api_key' => 'cours.config'],

            ['id' => 24, 'tri' => 600, 'nom' => 'Matériel lecture', 'description' => 'Affichage du matériel', 'api_key' => 'materiel.lecture'],
            ['id' => 25, 'tri' => 610, 'nom' => 'Matériel modification', 'description' => 'Modification du matériel', 'api_key' => 'materiel.modification'],
            ['id' => 26, 'tri' => 620, 'nom' => 'Matériel config', 'description' => 'Configuration du matériel', 'api_key' => 'materiel.config'],

            ['id' => 9, 'tri' => 700, 'nom' => 'Organisation modification', 'description' => 'Modification des groupes', 'api_key' => 'organisation.modification'],

            ['id' => 37, 'tri' => 750, 'nom' => 'Absences lecture', 'description' => 'Affichage des absences', 'api_key' => 'absence.lecture'],
            ['id' => 38, 'tri' => 760, 'nom' => 'Absences modification', 'description' => 'Modification des absences', 'api_key' => 'absence.modification'],
            ['id' => 39, 'tri' => 770, 'nom' => 'Absences config', 'description' => 'Configuration des absences', 'api_key' => 'absence.config'],

            ['id' => 3, 'tri' => 805, 'nom' => 'Comptabilité modification', 'description' => 'Comptabilité, modification', 'api_key' => 'comptabilite.modification'],
            ['id' => 40, 'tri' => 800, 'nom' => 'Comptabilité lecture', 'description' => 'Comptabilité, lecture', 'api_key' => 'comptabilite.lecture'],
            ['id' => 16, 'tri' => 810, 'nom' => 'Config pour comptabilite', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'comptabilite.config'],

            ['id' => 10, 'tri' => 900, 'nom' => 'Contrôle médical', 'description' => 'Gestion des contrôles médicaux', 'api_key' => 'controle_medical.tout'],
            ['id' => 17, 'tri' => 910, 'nom' => 'Config pour contrôle medical', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'controle_medical.config'],

            ['id' => 18, 'tri' => 1010, 'nom' => 'Config pour utilisateur', 'description' => 'Configuration des différents rôles', 'api_key' => 'utilisateur.config'],
            ['id' => 19, 'tri' => 1010, 'nom' => 'Config générale', 'description' => 'Configuration des informations du SIS', 'api_key' => 'sis.config'],
            ['id' => 11, 'tri' => 1000, 'nom' => 'Utilisateur', 'description' => 'Modification des droits des utilisateurs', 'api_key' => 'utilisateur.tout'],

            ['id' => 27, 'tri' => 1100, 'nom' => 'SMS envoie', 'description' => 'Envoie de SMS', 'api_key' => 'sms.envoie'],
            ['id' => 28, 'tri' => 1110, 'nom' => 'SMS config', 'description' => 'Configuration du des SMS', 'api_key' => 'sms.config'],

            ['id' => 41, 'tri' => 1200, 'nom' => 'RTA lecture', 'description' => 'Accès aux informations RTA', 'api_key' => 'rta.lecture'],
            ['id' => 42, 'tri' => 1210, 'nom' => 'RTA modification', 'description' => 'Envoie de modifications au RTA', 'api_key' => 'rta.modification'],
            ['id' => 43, 'tri' => 1230, 'nom' => 'RTA config', 'description' => 'Configuration du RTA', 'api_key' => 'rta.config'],

            ['id' => 20, 'tri' => 2000, 'nom' => 'Admin', 'description' => 'Paramètres admin du système', 'api_key' => 'admin.tout'],
        );

        foreach ($elements as $element) {
            DB::table('permissions')->insert($element);
        }
    }
}
