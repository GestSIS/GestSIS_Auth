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
            array('id' => 22, 'tri' => 50, 'nom' => 'Effectif', 'description' => 'Affichage de l\'effectif', 'api_key' => 'effectif.tout'),

            array('id' => 1, 'tri' => 100, 'nom' => 'Sapeur lecture', 'description' => 'Affichage des informations sapeurs', 'api_key' => 'sapeur.lecture'),
            array('id' => 2, 'tri' => 110, 'nom' => 'Sapeur modification', 'description' => 'Modification des informations sapeurs', 'api_key' => 'sapeur.modification'),
            array('id' => 12, 'tri' => 120, 'nom' => 'Config pour sapeur', 'description' => 'Configuration pour module sapeur', 'api_key' => 'sapeur.config'),

            array('id' => 23, 'tri' => 200, 'nom' => 'Intervention lecture', 'description' => 'Affichage des interventions', 'api_key' => 'intervention.lecture'),
            array('id' => 4, 'tri' => 210, 'nom' => 'Intervention saisie', 'description' => 'Saisie des interventions', 'api_key' => 'intervention.modification'),
            array('id' => 5, 'tri' => 220, 'nom' => 'Intervention validation', 'description' => 'Validation des interventions après saisie', 'api_key' => 'intervention.validation'),
            array('id' => 15, 'tri' => 230, 'nom' => 'Config pour intervention', 'description' => 'Configuration pour module intervention', 'api_key' => 'intervention.config'),

            array('id' => 6, 'tri' => 300, 'nom' => 'Exercice modification', 'description' => 'Saisie des exercices', 'api_key' => 'exercice.modification'),
            array('id' => 21, 'tri' => 310, 'nom' => 'Exercice lecture', 'description' => 'Affichage des exercices', 'api_key' => 'exercice.lecture'),
            array('id' => 7, 'tri' => 320, 'nom' => 'Exercice saisie des présences', 'description' => 'Saisie des présences pour exercice', 'api_key' => 'exercice.presence'),
            array('id' => 8, 'tri' => 330, 'nom' => 'Exercice validation', 'description' => 'Validation des exercices', 'api_key' => 'exercice.validation'),
            array('id' => 14, 'tri' => 340, 'nom' => 'Config pour exercice', 'description' => 'Configuration pour module exercice', 'api_key' => 'exercice.config'),

            array('id' => 32, 'tri' => 400, 'nom' => 'Fiche travail lecture', 'description' => 'Configuration des fiches de travail', 'api_key' => 'fiche_travail.lecture'),
            array('id' => 33, 'tri' => 410, 'nom' => 'Fiche travail personnelle', 'description' => 'Saisie de fiches de travail personnelles', 'api_key' => 'fiche_travail.saisie_perso'),
            array('id' => 34, 'tri' => 420, 'nom' => 'Fiche travail commune', 'description' => 'Saisie de fiches de travail communes', 'api_key' => 'fiche_travail.saisie_commune'),
            array('id' => 35, 'tri' => 430, 'nom' => 'Fiche travail validation', 'description' => 'Validation des fiches de travail', 'api_key' => 'fiche_travail.validation'),
            array('id' => 36, 'tri' => 440, 'nom' => 'Fiche travail config', 'description' => 'Configuration des fiches de travail', 'api_key' => 'fiche_travail.config'),

            array('id' => 29, 'tri' => 500, 'nom' => 'Cours lecture', 'description' => 'Affichage des cours', 'api_key' => 'cours.lecture'),
            array('id' => 30, 'tri' => 510, 'nom' => 'Cours modification', 'description' => 'Modification des cours', 'api_key' => 'cours.modification'),
            array('id' => 31, 'tri' => 520, 'nom' => 'Cours config', 'description' => 'Configuration des cours', 'api_key' => 'cours.config'),

            array('id' => 24, 'tri' => 600, 'nom' => 'Mat. perso lecture', 'description' => 'Affichage du matériel personnel', 'api_key' => 'mat_perso.lecture'),
            array('id' => 25, 'tri' => 610, 'nom' => 'Mat. perso modification', 'description' => 'Modification du matériel personnel', 'api_key' => 'mat_perso.modification'),
            array('id' => 26, 'tri' => 620, 'nom' => 'Mat. perso config', 'description' => 'Configuration du matériel personnel', 'api_key' => 'mat_perso.config'),

            array('id' => 9, 'tri' => 700, 'nom' => 'Organisation modification', 'description' => 'Modification des groupes', 'api_key' => 'organisation.modification'),

            array('id' => 37, 'tri' => 750, 'nom' => 'Absences lecture', 'description' => 'Affichage des absences', 'api_key' => 'absence.lecture'),
            array('id' => 38, 'tri' => 760, 'nom' => 'Absences modification', 'description' => 'Modification des absences', 'api_key' => 'absence.modification'),
            array('id' => 39, 'tri' => 770, 'nom' => 'Absences config', 'description' => 'Configuration des absences', 'api_key' => 'absence.config'),

            array('id' => 3, 'tri' => 800, 'nom' => 'Comptabilité', 'description' => 'Comptabilité, contrôle total', 'api_key' => 'comptabilite.tout'),
            array('id' => 16, 'tri' => 810, 'nom' => 'Config pour comptabilite', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'comptabilite.config'),

            array('id' => 10, 'tri' => 900, 'nom' => 'Contrôle médical', 'description' => 'Gestion des contrôles médicaux', 'api_key' => 'controle_medical.tout'),
            array('id' => 17, 'tri' => 910, 'nom' => 'Config pour contrôle medical', 'description' => 'Configuration pour module contrôles médicaux', 'api_key' => 'controle_medical.config'),

            array('id' => 27, 'tri' => 950, 'nom' => 'SMS envoie', 'description' => 'Envoie de SMS', 'api_key' => 'sms.envoie'),
            array('id' => 28, 'tri' => 960, 'nom' => 'SMS config', 'description' => 'Configuration du des SMS', 'api_key' => 'sms.config'),

            array('id' => 11, 'tri' => 1000, 'nom' => 'Utilisateur', 'description' => 'Modification des droits des utilisateurs', 'api_key' => 'utilisateur.tout'),
            array('id' => 18, 'tri' => 1010, 'nom' => 'Config pour utilisateur', 'description' => 'Configuration des différents rôles', 'api_key' => 'utilisateur.config'),

            array('id' => 19, 'tri' => 1020, 'nom' => 'Config générale', 'description' => 'Configuration des informations du SIS', 'api_key' => 'sis.config'),

            array('id' => 20, 'tri' => 2000, 'nom' => 'Admin', 'description' => 'Paramètres admin du système', 'api_key' => 'admin.tout'),
        ];

        Permission::insert([
            array('id' => 37, 'tri' => 750, 'nom' => 'Absences lecture', 'description' => 'Affichage des absences', 'api_key' => 'absence.lecture'),
            array('id' => 38, 'tri' => 760, 'nom' => 'Absences modification', 'description' => 'Modification des absences', 'api_key' => 'absence.modification'),
            array('id' => 39, 'tri' => 770, 'nom' => 'Absences config', 'description' => 'Configuration des absences', 'api_key' => 'absence.config'),
        ]);
        foreach ($permissions as $p) {
            Permission::where('id', '=', $p['id'])->update(['tri' => $p['tri'], 'api_key' => $p['api_key'], 'description' => $p['description'], 'nom' => $p['nom']]);
        }
        // Permission::insert($permissions);

        $roleNamedAdmin = Role::where('nom', 'LIKE', 'Admi%')->get(['id'])->toArray();
        // $roleWithSapeurModifIds = PermissionRole::where('permission_id', '=', 2)->get(['role_id'])->toArray();
        // $roleWithSapeurConfigIds = PermissionRole::where('permission_id', '=', 12)->get(['role_id'])->toArray();
        PermissionRole::insert(array_map(fn ($r) => ['role_id' => $r['id'], 'permission_id' => 37], $roleNamedAdmin));
        PermissionRole::insert(array_map(fn ($r) => ['role_id' => $r['id'], 'permission_id' => 38], $roleNamedAdmin));
        PermissionRole::insert(array_map(fn ($r) => ['role_id' => $r['id'], 'permission_id' => 39], $roleNamedAdmin));

        printf("Migrating done\n");
        return 0;
    }
}
