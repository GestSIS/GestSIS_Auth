<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domaines', function (Blueprint $table) {
            $table->string('active_identities_url')->nullable();
        });

        DB::table('domaines')->where('code', 'sis')->update([
            'active_identities_url' => config('gestsis.api_url', '') . '/api/v2/sapeurs-emails',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domaines', function (Blueprint $table) {
            $table->dropColumn('active_identities_url');
        });
    }
};
