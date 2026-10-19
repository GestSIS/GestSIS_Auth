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
            $table->string('email_validate_url')->nullable();
        });

        DB::table('domaines')->where('code', 'sis')->update([
            'email_validate_url' => config('gestsis.api_url', '') . '/api/v2/email-validate',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domaines', function (Blueprint $table) {
            $table->dropColumn('email_validate_url');
        });
    }
};
