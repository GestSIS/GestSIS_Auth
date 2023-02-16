<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('register_tokens', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('token')->unique();
            $table->string('description')->default("");
            $table->date('validite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('register_tokens');
    }
};
