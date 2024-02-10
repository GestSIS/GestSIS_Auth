<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use Illuminate\Console\Command;

class DBCustomSeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:seed-custom';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        printf("Seed Permissions\n");
        $permissions =  [
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

            ['id' => 24, 'tri' => 600, 'nom' => 'Mat. perso lecture', 'description' => 'Affichage du matériel personnel', 'api_key' => 'mat_perso.lecture'],
            ['id' => 25, 'tri' => 610, 'nom' => 'Mat. perso modification', 'description' => 'Modification du matériel personnel', 'api_key' => 'mat_perso.modification'],
            ['id' => 26, 'tri' => 620, 'nom' => 'Mat. perso config', 'description' => 'Configuration du matériel personnel', 'api_key' => 'mat_perso.config'],

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

            ['id' => 20, 'tri' => 2000, 'nom' => 'Admin', 'description' => 'Paramètres admin du système', 'api_key' => 'admin.tout'],
        ];

        Permission::insert([
            ['id' => 40, 'tri' => 800, 'nom' => 'Comptabilité lecture', 'description' => 'Comptabilité, lecture', 'api_key' => 'comptabilite.lecture'],
        ]);
        foreach ($permissions as $p) {
            Permission::where('id', '=', $p['id'])->update(['tri' => $p['tri'], 'api_key' => $p['api_key'], 'description' => $p['description'], 'nom' => $p['nom']]);
        }

        // $roleNamedAdmin = Role::where('nom', 'LIKE', 'Admi%')->get(['id'])->toArray();
        $roleWithComptabiliteTout = PermissionRole::where('permission_id', '=', 3)->get(['role_id'])->toArray();
        PermissionRole::insert(array_map(fn ($r) => ['role_id' => $r['role_id'], 'permission_id' => 40], $roleWithComptabiliteTout));

        printf("Migrating done\n");
        return 0;
    }
}
