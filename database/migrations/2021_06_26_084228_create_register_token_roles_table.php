<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRegisterTokenRolesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('register_token_roles', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            
            $table->unsignedBigInteger('register_token_id');
            $table->foreign('register_token_id')->references('id')->on('register_tokens')->onDelete('cascade');
            
            $table->unsignedBigInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');

            $table->unique(['register_token_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('register_token_roles');
    }
}
