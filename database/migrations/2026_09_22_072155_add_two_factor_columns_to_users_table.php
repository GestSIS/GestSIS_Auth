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
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('two_factor_reminder_sent_at')->nullable();
            $table->boolean('two_factor_exempt')->default(false);
            $table->timestamp('two_factor_exempt_until')->nullable();
            $table->string('two_factor_exempt_reason')->nullable();
            $table->foreignId('two_factor_exempt_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('two_factor_exempt_by');
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_confirmed_at',
                'two_factor_reminder_sent_at',
                'two_factor_exempt',
                'two_factor_exempt_until',
                'two_factor_exempt_reason',
            ]);
        });
    }
};
