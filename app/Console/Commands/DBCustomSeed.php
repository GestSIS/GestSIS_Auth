<?php

namespace App\Console\Commands;

use App\Permission;
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
        Permission::insert(array('id' => 22, 'nom' => 'Effectif', 'description' => 'Affichage de l\'effectif ', 'api_key' => 'effectif.tout'));
        Permission::insert(array('id' => 23, 'nom' => 'Intervention lecture', 'description' => 'Affichage des interventions ', 'api_key' => 'intervention.lecture'));
        Permission::where('id', '=', 13)->delete(); // Drop organisation.config
        printf("Migrating done\n");
        return 0;
    }
}
