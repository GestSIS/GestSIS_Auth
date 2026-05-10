<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('api_token_sis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->onDelete('cascade');
            $table->foreignId('sis_id')->constrained('sis')->onDelete('cascade');
            $table->timestamps();

            // Unique constraint: a token can't have the same SIS twice
            $table->unique(['api_token_id', 'sis_id']);

            // Indexes for performance
            $table->index('api_token_id');
            $table->index('sis_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_token_sis');
    }
};
