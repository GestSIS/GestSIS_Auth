<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('domaines')->where('code', 'sis')->update([
            'active_identities_url' => config('gestsis.api_url', '') . '/api/v2/identites-actives',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('domaines')->where('code', 'sis')->update([
            'active_identities_url' => config('gestsis.api_url', '') . '/api/v2/sapeurs-emails',
        ]);
    }
};
