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
            $table->boolean('self_registration_enabled')->default(false);
        });

        DB::table('domaines')->where('code', 'sis')->update(['self_registration_enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domaines', function (Blueprint $table) {
            $table->dropColumn('self_registration_enabled');
        });
    }
};
