<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Révocation "douce" des jetons API : le jeton reste listé (avec la date et
     * la raison) pour que l'utilisateur sache quelle intégration recréer, mais
     * il n'est plus échangeable contre un JWT.
     */
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('last_used_at');
            $table->string('revoked_reason', 64)->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropColumn(['revoked_at', 'revoked_reason']);
        });
    }
};
