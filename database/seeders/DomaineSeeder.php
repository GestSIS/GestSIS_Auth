<?php

namespace Database\Seeders;

use App\Models\Domaine;
use Illuminate\Database\Seeder;

class DomaineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // self_registration_enabled, active_identities_url, mail_from/mail_name
        // et test_account_domain_suffix sont fixés ici en plus de leurs
        // migrations respectives : sur `migrate:fresh --seed`, toutes les
        // migrations tournent avant tous les seeders, donc le backfill de la
        // migration ne voit aucune ligne `domaines` à mettre à jour (voir le
        // même piège déjà rencontré pour `permissions.domaine_id`).
        Domaine::updateOrCreate(['code' => 'sis'], [
            'nom' => 'GestSIS',
            'self_registration_enabled' => true,
            'active_identities_url' => config('gestsis.api_url', '') . '/api/v2/identites-actives',
            'email_validate_url' => config('gestsis.api_url', '') . '/api/v2/email-validate',
            'app_url' => config('gestsis.app_url', ''),
            'mail_from' => env('MAIL_FROM_SIS', 'support@gestsis.ch'),
            'mail_name' => 'GestSIS',
            'test_account_domain_suffix' => '@gestsis.ch',
        ]);
        Domaine::updateOrCreate(['code' => 'eca'], [
            'nom' => 'GestECA',
            'mail_from' => env('MAIL_FROM_ECA', 'support@gesteca.ch'),
            'mail_name' => 'GestECA',
        ]);
    }
}
