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
            $table->string('mail_from')->nullable();
            $table->string('mail_name')->nullable();
            $table->string('test_account_domain_suffix')->nullable();
        });

        DB::table('domaines')->where('code', 'sis')->update([
            'mail_from' => env('MAIL_FROM_SIS', 'support@gestsis.ch'),
            'mail_name' => 'GestSIS',
            'test_account_domain_suffix' => '@gestsis.ch',
        ]);

        DB::table('domaines')->where('code', 'eca')->update([
            'mail_from' => env('MAIL_FROM_ECA', 'support@gesteca.ch'),
            'mail_name' => 'GestECA',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domaines', function (Blueprint $table) {
            $table->dropColumn(['mail_from', 'mail_name', 'test_account_domain_suffix']);
        });
    }
};
